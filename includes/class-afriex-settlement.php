<?php
/**
 * The settlement engine: the one place an Afriex transaction changes an order.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan sections 6.2 and 6.3, for both payment methods.
 *
 * Every path that can settle a payment — the webhook, the return handler, the
 * status endpoint, the sweep, the order action and process_payment() — comes
 * through settle(). They differ only in how long they will wait for the order
 * lock and in what they do with the answer. That is deliberate: the sweep
 * exists precisely for the cases the webhook missed, and if the two settled on
 * different rules the backstop would be running rules nobody tested.
 *
 * The decisions themselves are in Afriex_Settlement_Decision, which has no
 * WordPress in it and is covered by the decision-table tests. This class does
 * the rest: the lock, the cache flush and load, the binding queries, the
 * ledger claim, the notes, the emails and payment_complete().
 *
 * Three rules run through all of it.
 *
 * 1. Nothing is applied that has not been bound to this order by evidence
 *    Afriex gave us: a reference we minted, or an account we were issued
 *    (F2, F3, F26, F28). An unmatched transaction is never applied.
 * 2. An order that is already settled never changes status again, whatever
 *    arrives afterwards and whatever the merchant has done to it since (F87).
 * 3. A payment only completes an order that was waiting for one. A cancelled
 *    or refunded order is reported, not revived (F14).
 */
class Afriex_Settlement {
	/*
	 * Who is asking. The lock timeout depends on it: a webhook can afford to
	 * wait because Afriex allows 30 s and retries anyway, while a page a
	 * shopper is waiting on cannot (plan 6.1).
	 */
	public const SOURCE_WEBHOOK      = 'webhook';
	public const SOURCE_RETURN       = 'return';
	public const SOURCE_STATUS       = 'status';
	public const SOURCE_SWEEP        = 'sweep';
	public const SOURCE_CHECKOUT     = 'process_payment';
	public const SOURCE_ORDER_ACTION = 'order_action';

	/*
	 * What happened. Callers map these to HTTP codes; see http_status().
	 */
	public const OUTCOME_SETTLED      = 'settled';
	public const OUTCOME_NOTED        = 'noted';
	public const OUTCOME_ORDER_FAILED = 'order_failed';
	public const OUTCOME_FLAGGED      = 'flagged';
	public const OUTCOME_IGNORED      = 'ignored';
	public const OUTCOME_LOCKED       = 'locked';
	public const OUTCOME_ERROR        = 'failed';
	public const OUTCOME_CANCELLED    = 'cancelled';
	public const OUTCOME_EXTENDED     = 'extended';

	/**
	 * Orders waiting for an admin to look at them, for the notice.
	 */
	public const REVIEW_NOTICE_OPTION = 'afriex_wc_review_notice';

	/**
	 * Order statuses a Bank Transfer deposit is normally looked for in. Used
	 * to pick between orders that somehow share an account, never to refuse
	 * an order outright: a payment that lands on a cancelled order still has
	 * to be reported (F14).
	 */
	private const OPEN_STATUSES = array( 'on-hold', 'pending', 'failed' );

	/**
	 * How many orders the notice names before it stops listing them.
	 */
	private const NOTICE_MAX_ORDERS = 20;

	/**
	 * Default extension when the deadline passes while Afriex still has the
	 * payment in flight. Filterable through
	 * afriex_wc_deadline_extension.
	 */
	private const DEADLINE_EXTENSION = 1800;

	/**
	 * How many Afriex Checkout references one collection will list.
	 *
	 * One GET per reference, and a shopper can mint a new one after every
	 * failed attempt on the hosted page, so the work has to have a ceiling.
	 * The newest ones are listed first (see checkout_references_to_list()),
	 * which is where a payment actually is.
	 */
	private const MAX_REFERENCE_LISTINGS = 8;

	/**
	 * Review reasons that a payment applied in full demonstrably answers.
	 *
	 * These all mean "a payment could not be applied to this order": the total
	 * had moved, the money did not match, the expectation was missing or
	 * unreadable, the key was gone, or the window closed on an unapplied
	 * payment. Once the order is paid by a transaction the decision table
	 * accepted, the question they raise is over.
	 *
	 * Deliberately NOT here: `second_payment`, `paid_after_close`,
	 * `ineligible_status`, `legacy_pool`, `account_ambiguous`,
	 * `superseded_account`, `customer_mismatch` and the post-payment statuses.
	 * Each of those asks a person something that completing the order does not
	 * answer — most obviously `superseded_account`, which exists to say that
	 * the shopper was shown two accounts and may yet pay into the other one.
	 */
	private const RESOLVED_BY_PAYMENT = array(
		'order_total_mismatch',
		'amount_mismatch',
		'currency_mismatch',
		'no_expectation',
		'amount_unreadable',
		'credentials_unavailable',
		'unresolved_at_deadline',
		'unreadable_at_deadline',
	);

	/**
	 * The subset of RESOLVED_BY_PAYMENT raised about one refused transaction.
	 *
	 * These flags mean "Afriex took this money and the plugin would not apply
	 * it". Only that same transaction later completing the order answers them
	 * (the order total was corrected, then the refused payment accepted). A
	 * DIFFERENT transaction completing the order does not: the refused money
	 * is still in the merchant's Afriex account, and clearing the flag would
	 * drop it from the store-wide review notice.
	 */
	private const RESOLVED_ONLY_BY_SAME_TRANSACTION = array(
		'order_total_mismatch',
		'amount_mismatch',
		'currency_mismatch',
		'no_expectation',
		'amount_unreadable',
	);

	/**
	 * How long the order lock survives a request that dies holding it.
	 *
	 * The ledger's own default is 30 s, which is shorter than the work this
	 * class does inside the lock: the expire routine can make three Afriex
	 * calls, each with a 10 s timeout and one retry that sleeps up to 3 s.
	 * A TTL shorter than the critical section is not mutual exclusion at
	 * all — a second process takes the row over while the first is still
	 * inside it. Callers that talk to Afriex under the lock also give the
	 * client a time budget, so the hold is bounded from both ends.
	 */
	public const LOCK_TTL = 120;

	/*
	 * ---------------------------------------------------------------------
	 * Locking.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The ledger lock name for one order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function lock_key( int $order_id ): string {
		return 'order:' . $order_id;
	}

	/**
	 * How long this caller waits for the order lock (plan 6.1).
	 *
	 * A page a shopper is looking at waits not at all and reports what it
	 * already knows; the sweep waits not at all and comes back in five
	 * minutes. Only the webhook and process_payment() wait, and only for 3 s,
	 * well inside Afriex's 30 s and the shopper's patience.
	 *
	 * @param string $source One of the SOURCE_* constants.
	 */
	public static function lock_timeout( string $source ): float {
		if ( self::SOURCE_WEBHOOK === $source || self::SOURCE_CHECKOUT === $source ) {
			return 3.0;
		}

		return 0.0;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Finding the order, without loading it (plan 6.1, F8).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Which order a transaction belongs to, decided from ids alone.
	 *
	 * Deliberately returns an id and not an order: the caller takes the lock
	 * first and loads the order afterwards, so what it reads is the state
	 * inside the lock rather than a copy from before it (F8).
	 *
	 * @param array $transaction Transaction payload from Afriex.
	 * @return array order_id (0 when none), reason, candidates.
	 */
	public static function resolve_order_id( array $transaction ): array {
		$reference = Afriex_Settlement_Decision::reference( $transaction );
		$parsed    = Afriex_Order_Meta::parse_reference( $reference );

		// Afriex Checkout: the reference is ours and says which order it is,
		// but the claim is only believed when the lookup agrees (plan 6.2).
		if ( $parsed > 0 ) {
			$ids = Afriex_Order_Meta::find_order_ids_by_checkout_reference( $reference );

			if ( empty( $ids ) ) {
				// This reference parses, so this store minted it: either an
				// order that held it has been deleted outright, or somebody
				// wrote a reference of their own. Neither is the ordinary
				// "money for another merchant" case, and the caller logs that
				// at info, which a default install never writes. Said here so
				// the merchant has at least one line to find.
				Afriex_Logger::warning(
					'A payment carries a reference this store minted, but no order holds it',
					array( 'reference' => $reference )
				);

				return self::resolution( 0, 'reference_unknown', array() );
			}

			if ( count( $ids ) > 1 ) {
				return self::resolution( 0, 'reference_ambiguous', $ids );
			}

			if ( $ids[0] !== $parsed ) {
				return self::resolution( 0, 'reference_order_mismatch', $ids );
			}

			return self::resolution( $ids[0], 'reference', $ids );
		}

		// Bank Transfer: the virtual account the money arrived in. Afriex has
		// not said whether an inbound deposit carries it as sourceId or as
		// destinationId (plan 16, question 7), so both are tried.
		$account_ids = array();

		foreach ( array( 'destinationId', 'sourceId' ) as $field ) {
			if ( isset( $transaction[ $field ] ) && is_scalar( $transaction[ $field ] ) && '' !== (string) $transaction[ $field ] ) {
				$account_ids[] = (string) $transaction[ $field ];
			}
		}

		$account_ids = array_values( array_unique( $account_ids ) );

		if ( empty( $account_ids ) ) {
			return self::resolution( 0, 'no_reference_or_account', array() );
		}

		// Four passes, narrowest first, and the first one that names exactly one
		// order wins:
		//
		// 1. an order still waiting for money whose CURRENT account this is;
		// 2. any order at all whose current account this is;
		// 3. an order still waiting for money that was GIVEN this account and
		// has since been given another (F20);
		// 4. any order at all that was given it.
		//
		// The last two are what stop a transfer into a replaced account from
		// looking like money for a different merchant. The shopper was shown
		// that account: by the time the plugin decides it is stale, the money
		// may already have left their bank, and an unresolvable deposit is
		// logged and dropped. Resolved, it reaches settle(), which binds it,
		// flags the order and tells the merchant the customer may have paid
		// into either account. The current holder is still preferred at every
		// step, so an id that is current for one order and superseded on
		// another can only ever resolve to the order it is current for.
		$passes = array(
			array(
				'method'   => 'find_order_ids_by_payment_method_id',
				'statuses' => self::OPEN_STATUSES,
			),
			array(
				'method'   => 'find_order_ids_by_payment_method_id',
				'statuses' => array(),
			),
			array(
				'method'   => 'find_order_ids_by_previous_payment_method_id',
				'statuses' => self::OPEN_STATUSES,
			),
			array(
				'method'   => 'find_order_ids_by_previous_payment_method_id',
				'statuses' => array(),
			),
		);

		foreach ( $passes as $pass ) {
			$found = array();

			foreach ( $account_ids as $account_id ) {
				foreach ( call_user_func( array( 'Afriex_Order_Meta', $pass['method'] ), $account_id, $pass['statuses'] ) as $id ) {
					$found[ $id ] = $id;
				}
			}

			$found = array_values( $found );

			if ( 1 === count( $found ) ) {
				return self::resolution(
					$found[0],
					'find_order_ids_by_payment_method_id' === $pass['method'] ? 'payment_method' : 'superseded_payment_method',
					$found
				);
			}

			if ( count( $found ) > 1 ) {
				// More than one candidate is refused, never guessed (F30).
				return self::resolution( 0, 'account_ambiguous', $found );
			}
		}

		return self::resolution( 0, 'no_match', array() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settling.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Apply one Afriex transaction to one order: plan 6.3, steps 1 to 11.
	 *
	 * @param int    $order_id    Order the caller resolved.
	 * @param array  $transaction Transaction as fetched from Afriex. A webhook
	 *                            payload is a trigger, not evidence: callers
	 *                            re-fetch before calling this (F27).
	 * @param string $source      One of the SOURCE_* constants.
	 * @return array See outcome().
	 */
	public static function settle( int $order_id, array $transaction, string $source = self::SOURCE_WEBHOOK ): array {
		$context = array(
			'order_id'       => $order_id,
			'transaction_id' => Afriex_Settlement_Decision::transaction_id( $transaction ),
			'status'         => Afriex_Settlement_Decision::status( $transaction ),
			'source'         => $source,
		);

		if ( $order_id <= 0 ) {
			return self::outcome( self::OUTCOME_IGNORED, 'no_order', $context );
		}

		// Step 1: the lock. Everything below runs inside it.
		$lock = self::lock_key( $order_id );

		if ( ! Afriex_Ledger::lock( $lock, self::lock_timeout( $source ), self::LOCK_TTL ) ) {
			Afriex_Logger::info( 'Settlement could not take the order lock', $context );

			// Not an error: whoever holds the lock is doing this work now. The
			// webhook answers 500 so Afriex retries, and the sweep comes back.
			return self::outcome( self::OUTCOME_LOCKED, 'lock_timeout', $context, true );
		}

		try {
			return self::settle_locked( $order_id, $transaction, $source, $context );
		} finally {
			// Step 11.
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * Steps 2 to 10, with the order lock held.
	 *
	 * @param int    $order_id    Order id.
	 * @param array  $transaction Transaction payload.
	 * @param string $source      Caller.
	 * @param array  $context     Log context.
	 */
	private static function settle_locked( int $order_id, array $transaction, string $source, array $context ): array {
		// The order is loaded only after the lock, and only after its caches
		// are dropped, so this is the order as it is now (F8).
		self::flush_order_cache( $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::outcome( self::OUTCOME_IGNORED, 'order_missing', $context );
		}

		$txn_id = Afriex_Settlement_Decision::transaction_id( $transaction );
		$status = Afriex_Settlement_Decision::status( $transaction );

		// Step 2: ownership.
		if ( ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return self::outcome( self::OUTCOME_IGNORED, 'not_afriex', $context );
		}

		$already     = self::is_already_settled( $order, $txn_id );
		$settled_txn = self::settled_transaction_id( $order, $txn_id );

		// Step 3: binding.
		$binding = self::verify_binding( $order, $transaction );

		if ( ! $binding['ok'] ) {
			return self::refuse_binding( $order, $binding, $context );
		}

		$decision = Afriex_Settlement_Decision::decide(
			self::decision_input( $order, $transaction, $binding, $already, $settled_txn )
		);

		$context['decision'] = $decision['decision'];
		$context['reason']   = $decision['reason'];

		if ( is_array( $decision['money'] ) ) {
			// Both pairs and the fee, always, whichever one matched: until
			// Afriex answers question 1 of plan 16 this is the only record of
			// what it actually sent (plan 6.3 step 7).
			Afriex_Logger::info(
				'Afriex amounts',
				array_merge(
					$context,
					array(
						'expected_minor' => $decision['money']['expected_minor'],
						'expected_curr'  => $decision['money']['expected_currency'],
						'order_total'    => $order->get_total() . ' ' . $order->get_currency(),
						'destination'    => $binding['amounts']['destination_amount'] . ' ' . $binding['amounts']['destination_currency'],
						'source'         => $binding['amounts']['source_amount'] . ' ' . $binding['amounts']['source_currency'],
						'fee'            => $binding['amounts']['fee'] . ' ' . $binding['amounts']['fee_currency'],
						'matched'        => $decision['money']['matched'],
					)
				)
			);
		}

		self::record_seen( $order, $status, $txn_id, $binding['is_current'] );

		// Step 8: the ledger claim, before the eligibility check, so that a
		// transaction arriving for a closed order still cannot be spent on a
		// second order afterwards (F23).
		if ( $decision['claim'] ) {
			$claim = self::claim_transaction( $order_id, $txn_id );

			if ( ! $claim['ok'] ) {
				return self::refuse_claim( $order, $claim, $context );
			}
		}

		return self::apply( $order, $transaction, $decision, $binding, $context );
	}

	/**
	 * Everything the decision table needs to judge one transaction.
	 *
	 * Pulled out of settle_locked() so that the note-only Bank Transfer sweep
	 * can ask the same question without answering it (plan 9). Two ways of
	 * deciding what a payment means would be one too many, and a "what would
	 * have happened" note that disagreed with what does happen when the switch
	 * is turned on would be worse than no note at all.
	 *
	 * Pure: it reads the order and the payload and writes nothing.
	 *
	 * @param WC_Order $order       Order, loaded under the lock.
	 * @param array    $transaction Transaction payload.
	 * @param array    $binding     verify_binding() result.
	 * @param bool     $already     Whether the order is already settled.
	 * @param string   $settled_txn The transaction that settled it, or ''.
	 */
	private static function decision_input( WC_Order $order, array $transaction, array $binding, bool $already, string $settled_txn ): array {
		return array(
			'payment_method'       => $order->get_payment_method(),
			'afriex_methods'       => Afriex_Payment_Gateway::GATEWAY_IDS,
			'order_status'         => $order->get_status(),
			'already_settled'      => $already,
			'settled_txn'          => $settled_txn,
			'failed_by_plugin'     => (string) $order->get_meta( Afriex_Order_Meta::KEY_FAILED_BY_PLUGIN ),
			'txn_id'               => Afriex_Settlement_Decision::transaction_id( $transaction ),
			'txn_status'           => Afriex_Settlement_Decision::status( $transaction ),
			'txn_type'             => Afriex_Settlement_Decision::type( $transaction ),
			'is_current_attempt'   => $binding['is_current'],
			'expected_minor'       => $binding['expected_minor'],
			'expected_currency'    => $binding['expected_currency'],
			// What the order costs now, read under the lock with its caches
			// already dropped, so the guard in step 7 judges the live total and
			// not a copy from before the lock (F8).
			'order_total_minor'    => Afriex_Money::to_minor( $order->get_total(), $order->get_currency() ),
			'order_currency'       => strtoupper( (string) $order->get_currency() ),
			'destination_amount'   => $binding['amounts']['destination_amount'],
			'destination_currency' => $binding['amounts']['destination_currency'],
			'source_amount'        => $binding['amounts']['source_amount'],
			'source_currency'      => $binding['amounts']['source_currency'],
		);
	}

	/**
	 * What settle() would do with this transaction, without doing any of it.
	 *
	 * The note-only Bank Transfer sweep uses it to record exactly what it would
	 * have done had settlement from the listing been switched on (plan 9). It
	 * runs the real binding rules and the real decision table, so the note says
	 * what would really happen and not what this file guesses would happen.
	 *
	 * Pure: no notes, no flags, no ledger claims, no order saves.
	 *
	 * @param WC_Order $order       Order, loaded under the caller's lock.
	 * @param array    $transaction Transaction as Afriex reported it.
	 * @return array bound, decision (a DECISION constant or ''), reason,
	 *               binding (verify_binding() result or null), money.
	 */
	public static function dry_run( WC_Order $order, array $transaction ): array {
		$result = array(
			'bound'    => false,
			'decision' => '',
			'reason'   => 'not_afriex',
			'binding'  => null,
			'money'    => null,
		);

		if ( ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return $result;
		}

		$binding           = self::verify_binding( $order, $transaction );
		$result['binding'] = $binding;

		if ( ! $binding['ok'] ) {
			$result['reason'] = (string) $binding['reason'];

			return $result;
		}

		$txn_id  = Afriex_Settlement_Decision::transaction_id( $transaction );
		$already = self::is_already_settled( $order, $txn_id );

		$decision = Afriex_Settlement_Decision::decide(
			self::decision_input( $order, $transaction, $binding, $already, self::settled_transaction_id( $order, $txn_id ) )
		);

		$result['bound']    = true;
		$result['decision'] = (string) $decision['decision'];
		$result['reason']   = (string) $decision['reason'];
		$result['money']    = $decision['money'];

		return $result;
	}

	/**
	 * Carry out a decision. One arm per decision code, and no decisions here.
	 *
	 * @param WC_Order $order       Order, loaded under the lock.
	 * @param array    $transaction Transaction payload.
	 * @param array    $decision    Afriex_Settlement_Decision::decide() result.
	 * @param array    $binding     verify_binding() result.
	 * @param array    $context     Log context.
	 */
	private static function apply( WC_Order $order, array $transaction, array $decision, array $binding, array $context ): array {
		$txn_id = (string) $context['transaction_id'];
		$status = (string) $decision['status'];

		self::close_failed_session( $order, $transaction, $status );

		// F20: money that arrived in an account this order has replaced. It
		// is this order's money and it is applied on its merits below, but it
		// is never applied quietly: the shopper was shown two accounts, and
		// whoever runs the store has to know they may pay into the other one
		// as well.
		if ( '' !== (string) $binding['superseded'] && Afriex_Status_Mapper::is_success( $status ) ) {
			self::report_superseded_account( $order, (string) $binding['superseded'], $txn_id, $context );
		}

		switch ( $decision['decision'] ) {
			case Afriex_Settlement_Decision::COMPLETE:
				return self::complete( $order, $transaction, $decision, $context );

			case Afriex_Settlement_Decision::NOT_DEPOSIT:
				// Bound to this order, but moving money the other way. Worth
				// saying so on the order; never worth acting on (F3).
				Afriex_Order_Meta::note_once(
					$order,
					'not_deposit:' . $txn_id,
					sprintf(
						/* translators: 1: Afriex transaction type, 2: transaction id */
						__( 'Afriex reported a %1$s transaction (%2$s) against this order. It was recorded only; payments are confirmed from deposits.', 'afriex-gateway-for-woocommerce' ),
						Afriex_Settlement_Decision::type( $transaction ),
						$txn_id
					)
				);

				return self::outcome( self::OUTCOME_IGNORED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::SECOND_PAYMENT:
				Afriex_Order_Meta::flag_for_review( $order, 'second_payment' );

				$noted = Afriex_Order_Meta::note_once(
					$order,
					'second_payment:' . $txn_id,
					sprintf(
						/* translators: 1: new transaction id, 2: transaction that paid the order */
						__( 'Afriex reported a second successful payment (%1$s) for this order, which was already paid by %2$s. The order was not changed. Check whether the customer paid twice and refund the extra payment if so.', 'afriex-gateway-for-woocommerce' ),
						$txn_id,
						Afriex_Order_Meta::get_settled_transaction_id( $order )
					)
				);

				if ( $noted ) {
					self::email_admin(
						$order,
						__( 'Afriex: a second payment for an order that was already paid', 'afriex-gateway-for-woocommerce' ),
						sprintf(
							/* translators: 1: order number, 2: transaction id */
							__( 'Order %1$s was already paid, and Afriex has reported another successful payment (%2$s) for it. Nothing was changed automatically. Check whether the customer paid twice.', 'afriex-gateway-for-woocommerce' ),
							$order->get_order_number(),
							$txn_id
						)
					);
					self::queue_review_notice( $order, 'second_payment' );
				}

				return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::POST_PAYMENT_REVIEW:
				Afriex_Order_Meta::flag_for_review( $order, strtolower( $status ) );

				$noted = Afriex_Order_Meta::note_once(
					$order,
					'post_payment:' . $txn_id . ':' . $status,
					sprintf(
						/* translators: 1: Afriex status, 2: transaction id */
						__( 'Afriex reported %1$s for the payment that settled this order (%2$s). The order status was not changed. Check your Afriex dashboard and decide what to do about the order.', 'afriex-gateway-for-woocommerce' ),
						$status,
						$txn_id
					)
				);

				if ( $noted ) {
					self::email_admin(
						$order,
						sprintf(
							/* translators: %s: Afriex status */
							__( 'Afriex: %s on a paid order', 'afriex-gateway-for-woocommerce' ),
							$status
						),
						sprintf(
							/* translators: 1: Afriex status, 2: order number, 3: transaction id */
							__( 'Afriex reported %1$s for order %2$s (transaction %3$s). The order status was not changed automatically.', 'afriex-gateway-for-woocommerce' ),
							$status,
							$order->get_order_number(),
							$txn_id
						)
					);
					self::queue_review_notice( $order, strtolower( $status ) );
				}

				return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::POST_PAYMENT_NOTE:
				Afriex_Order_Meta::note_once(
					$order,
					'post_payment:' . $txn_id . ':' . $status,
					sprintf(
						/* translators: 1: Afriex status, 2: transaction id */
						__( 'Afriex reported %1$s for the payment that settled this order (%2$s).', 'afriex-gateway-for-woocommerce' ),
						$status,
						$txn_id
					)
				);

				return self::outcome( self::OUTCOME_NOTED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::UNSETTLED_POST_PAYMENT:
				Afriex_Order_Meta::flag_for_review( $order, strtolower( $status ) );
				Afriex_Order_Meta::note_once(
					$order,
					'post_payment:' . $txn_id . ':' . $status,
					sprintf(
						/* translators: 1: Afriex status, 2: transaction id */
						__( 'Afriex reported %1$s for transaction %2$s, which never paid for this order. Nothing was changed. Check your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
						$status,
						$txn_id
					)
				);

				return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE:
				Afriex_Order_Meta::note_once(
					$order,
					'already_settled:' . $txn_id . ':' . $status,
					sprintf(
						/* translators: 1: Afriex status, 2: transaction id */
						__( 'Afriex reported %1$s for transaction %2$s after this order was already paid. No status change was made.', 'afriex-gateway-for-woocommerce' ),
						$status,
						$txn_id
					)
				);

				return self::outcome( self::OUTCOME_NOTED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::FAIL_ORDER:
				return self::fail_order( $order, $transaction, $decision, $context );

			case Afriex_Settlement_Decision::STALE_FAILURE:
				// One decision, two quite different reasons, and one sentence
				// for both used to assert the first. An order the webhook has
				// already failed is no longer failable, so every later sight
				// of the SAME transaction — the return handler, the status
				// poll, the sweep — lands here with reason `order_status:*`.
				// Saying then that the transaction "is not this order's
				// current payment attempt" contradicts the order's own meta.
				if ( 'not_current_attempt' === $decision['reason'] ) {
					Afriex_Order_Meta::note_once(
						$order,
						'stale_failure:' . $txn_id . ':' . $status,
						sprintf(
							/* translators: 1: Afriex status, 2: transaction id */
							__( 'Afriex reported %1$s for transaction %2$s, which is not this order\'s current payment attempt. The order was left as it is.', 'afriex-gateway-for-woocommerce' ),
							$status,
							$txn_id
						)
					);

					return self::outcome( self::OUTCOME_NOTED, $decision['reason'], $context );
				}

				// The order has already left the statuses a payment may fail.
				// When it is already in the state Afriex is reporting, the
				// note would say nothing the order does not already show, so
				// none is added.
				if ( ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
					Afriex_Order_Meta::note_once(
						$order,
						'stale_failure:' . $txn_id . ':' . $status,
						sprintf(
							/* translators: 1: Afriex status, 2: transaction id, 3: order status */
							__( 'Afriex reported %1$s for transaction %2$s. This order is already %3$s, so it was left as it is.', 'afriex-gateway-for-woocommerce' ),
							$status,
							$txn_id,
							wc_get_order_status_name( $order->get_status() )
						)
					);
				}

				return self::outcome( self::OUTCOME_NOTED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::NOTE_STATUS:
				Afriex_Order_Meta::note_once(
					$order,
					'status:' . $txn_id . ':' . $status,
					'unrecognised' === $decision['reason']
						? sprintf(
							/* translators: 1: Afriex status, 2: transaction id */
							__( 'Afriex reported a status this plugin does not recognise: %1$s (transaction %2$s). The order was left as it is, and the payment will be checked again.', 'afriex-gateway-for-woocommerce' ),
							$status,
							$txn_id
						)
						: sprintf(
							/* translators: 1: Afriex status, 2: transaction id */
							__( 'Afriex payment status: %1$s (transaction %2$s).', 'afriex-gateway-for-woocommerce' ),
							$status,
							$txn_id
						)
				);

				return self::outcome( self::OUTCOME_NOTED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::AMOUNT_MISMATCH:
				return self::amount_mismatch( $order, $decision, $binding, $context );

			case Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH:
				return self::order_total_mismatch( $order, $decision, $binding, $context );

			case Afriex_Settlement_Decision::NOT_REVIVED:
				Afriex_Order_Meta::flag_for_review( $order, 'paid_after_close' );

				$noted = Afriex_Order_Meta::note_once(
					$order,
					'paid_after_close:' . $txn_id,
					sprintf(
						/* translators: 1: transaction id, 2: order status */
						__( 'Afriex confirmed payment %1$s after this order was %2$s. The order was not reopened. Review it, then either refund the payment or reinstate the order.', 'afriex-gateway-for-woocommerce' ),
						$txn_id,
						$order->get_status()
					)
				);

				if ( $noted ) {
					self::email_admin(
						$order,
						__( 'Afriex: payment received after an order was closed', 'afriex-gateway-for-woocommerce' ),
						sprintf(
							/* translators: 1: order number, 2: order status, 3: transaction id */
							__( 'Order %1$s is %2$s, and Afriex has confirmed payment %3$s for it. The order was not reopened. Review it, then either refund the payment or reinstate the order.', 'afriex-gateway-for-woocommerce' ),
							$order->get_order_number(),
							$order->get_status(),
							$txn_id
						)
					);
					self::queue_review_notice( $order, 'paid_after_close' );
				}

				return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::INELIGIBLE:
				Afriex_Order_Meta::flag_for_review( $order, 'ineligible_status' );
				Afriex_Order_Meta::note_once(
					$order,
					'ineligible:' . $txn_id,
					sprintf(
						/* translators: 1: transaction id, 2: order status */
						__( 'Afriex confirmed payment %1$s, but this order is %2$s and cannot be completed automatically. Review it by hand.', 'afriex-gateway-for-woocommerce' ),
						$txn_id,
						$order->get_status()
					)
				);

				return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );

			case Afriex_Settlement_Decision::NOT_AFRIEX:
			default:
				return self::outcome( self::OUTCOME_IGNORED, $decision['reason'], $context );
		}
	}

	/**
	 * Step 10: write the evidence, complete the payment, then check it took.
	 *
	 * An in-progress marker is written before payment_complete() so that a
	 * failure halfway leaves a record of which transaction was being applied,
	 * rather than an order that looks untouched (F87). The durable
	 * _afriex_settled_transaction_id is written only once WooCommerce agrees
	 * the order is paid and carries this transaction id, because every path
	 * that asks "is this order already settled?" reads that key: writing it
	 * on the way in would turn a failed completion into an order that can
	 * never be completed and never be cancelled, with a note claiming it was
	 * already paid.
	 *
	 * The ledger row is kept on failure either way, so the retry is against
	 * this order and only this order.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 * @param array    $decision    Decision.
	 * @param array    $context     Log context.
	 */
	private static function complete( WC_Order $order, array $transaction, array $decision, array $context ): array {
		$txn_id  = (string) $context['transaction_id'];
		$channel = Afriex_Settlement_Decision::channel( $transaction );

		$order->update_meta_data( Afriex_Order_Meta::KEY_SETTLING_TRANSACTION_ID, $txn_id );

		if ( '' !== $channel ) {
			$order->update_meta_data( Afriex_Order_Meta::KEY_CHANNEL, $channel );
		}

		// The order is being paid, so the plugin's own failure marker no
		// longer describes it.
		$order->delete_meta_data( Afriex_Order_Meta::KEY_FAILED_BY_PLUGIN );
		$order->save();

		$completed = $order->payment_complete( $txn_id );

		if ( true !== $completed || ! $order->is_paid() || (string) $order->get_transaction_id() !== $txn_id ) {
			// The settling marker stays, as the record of what was being
			// applied, and the settled marker is never written — so the next
			// delivery or sweep genuinely retries this order rather than
			// reading it as paid.
			Afriex_Logger::error(
				'payment_complete() did not settle the order',
				array_merge(
					$context,
					array(
						'returned'    => $completed,
						'is_paid'     => $order->is_paid(),
						'order_txn'   => (string) $order->get_transaction_id(),
						'order_state' => $order->get_status(),
					)
				)
			);

			return self::outcome( self::OUTCOME_ERROR, 'payment_complete_failed', $context, true );
		}

		$order->update_meta_data( Afriex_Order_Meta::KEY_SETTLED_TRANSACTION_ID, $txn_id );
		$order->delete_meta_data( Afriex_Order_Meta::KEY_SETTLING_TRANSACTION_ID );
		$order->save();

		Afriex_Order_Meta::note_once(
			$order,
			'settled:' . $txn_id,
			'' === $channel
				? sprintf(
					/* translators: %s: Afriex transaction id */
					__( 'Afriex payment confirmed (%s).', 'afriex-gateway-for-woocommerce' ),
					$txn_id
				)
				: sprintf(
					/* translators: 1: Afriex channel, 2: transaction id */
					__( 'Afriex payment confirmed by %1$s (%2$s).', 'afriex-gateway-for-woocommerce' ),
					$channel,
					$txn_id
				)
		);

		// Whatever a person was being asked to look at, the order is now paid
		// in full by a transaction this plugin accepted, so the question is
		// settled with it. The flag used to have no counterpart at all: an
		// order flagged `order_total_mismatch`, corrected and then paid by the
		// very transaction that had been refused kept saying "This order needs
		// review" for ever, stayed in the store-wide notice, and — because a
		// review flag sets may_cancel = false — could never be cancelled
		// either. The reason is kept on the order rather than erased, and only
		// the reasons a payment actually answers are cleared.
		$flag       = Afriex_Order_Meta::get_review_flag( $order );
		$resolvable = in_array( $flag, self::RESOLVED_BY_PAYMENT, true );

		if ( $resolvable && in_array( $flag, self::RESOLVED_ONLY_BY_SAME_TRANSACTION, true ) ) {
			// A flag with no recorded transaction predates this rule; keep it,
			// since nothing proves the refused money was the money now applied.
			$resolvable = Afriex_Order_Meta::get_review_flag_transaction( $order ) === $txn_id;
		}

		$resolved = $resolvable ? Afriex_Order_Meta::clear_review_flag( $order ) : '';

		if ( '' !== $resolved ) {
			self::drop_from_review_notice( $order->get_id() );

			Afriex_Order_Meta::note_once(
				$order,
				'review_cleared:' . $txn_id,
				sprintf(
					/* translators: 1: the review reason that was cleared, 2: Afriex transaction id */
					__( 'This order was marked for review (%1$s). Afriex payment %2$s has now been accepted in full, so the mark has been cleared.', 'afriex-gateway-for-woocommerce' ),
					$resolved,
					$txn_id
				)
			);
		}

		Afriex_Logger::info( 'Order settled', array_merge( $context, array( 'channel' => $channel ) ) );

		return self::outcome( self::OUTCOME_SETTLED, 'ok', $context );
	}

	/**
	 * Step 6: mark the order failed because this attempt will not be paid.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 * @param array    $decision    Decision.
	 * @param array    $context     Log context.
	 */
	private static function fail_order( WC_Order $order, array $transaction, array $decision, array $context ): array {
		$txn_id = (string) $context['transaction_id'];
		$reason = Afriex_Settlement_Decision::failure_reason( $transaction );

		// Recorded before the status change: only an order the plugin failed
		// may later be completed by a payment (plan 6.3 step 9).
		$order->update_meta_data( Afriex_Order_Meta::KEY_FAILED_BY_PLUGIN, '' === $txn_id ? '1' : $txn_id );
		$order->save();

		$note = sprintf(
			/* translators: 1: Afriex status, 2: transaction id */
			__( 'Afriex reported this payment as %1$s (transaction %2$s).', 'afriex-gateway-for-woocommerce' ),
			$decision['status'],
			$txn_id
		);

		if ( '' !== $reason ) {
			$note .= ' ' . sprintf(
				/* translators: %s: Afriex failure reason */
				__( 'Reason given: %s', 'afriex-gateway-for-woocommerce' ),
				$reason
			);
		}

		$order->update_status( 'failed', $note );

		// update_status() records its argument as a PRIVATE note, so the
		// reason above is for the merchant alone. Plan 5.2 asks for the
		// shopper to be told too, and the return handler cannot be relied on
		// for it: it only reaches its own copy of this when the page beats
		// the webhook, which on the ordinary path it does not. The key is the
		// one the return handler already uses, so whichever arrives first
		// writes the note and the other adds nothing.
		self::note_failure_reason( $order, $transaction );

		Afriex_Logger::info( 'Order failed by Afriex status', $context );

		return self::outcome( self::OUTCOME_ORDER_FAILED, $decision['reason'], $context );
	}

	/**
	 * Record that a Checkout session is spent, so it is never reused.
	 *
	 * Afriex's hosted page, once a payment on it has terminally failed,
	 * "offers to return to the merchant or retry with a new session"
	 * (plan 3): it will not take a second payment. Whatever is left of the
	 * session's lifetime, the next Place-order has to mint a new reference
	 * rather than hand the shopper back a page they cannot pay on.
	 *
	 * Written here rather than in fail_order() so that it also covers the
	 * failures that leave the order alone — a stale reference's failure, or a
	 * failure on an order a person has already moved.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 * @param string   $status      Afriex status.
	 */
	private static function close_failed_session( WC_Order $order, array $transaction, string $status ): void {
		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		if ( ! Afriex_Status_Mapper::is_terminal_failure( $status ) ) {
			return;
		}

		$reference = Afriex_Settlement_Decision::reference( $transaction );

		if ( '' === $reference ) {
			return;
		}

		Afriex_Order_Meta::update_checkout_reference( $order, $reference, array( 'closed' => true ) );
	}

	/**
	 * Tell the shopper, once, why Afriex could not take the payment.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 */
	public static function note_failure_reason( WC_Order $order, array $transaction ): void {
		$reason = Afriex_Settlement_Decision::failure_reason( $transaction );

		if ( '' === $reason ) {
			return;
		}

		Afriex_Order_Meta::note_once(
			$order,
			'checkout_failure_reason:' . Afriex_Settlement_Decision::transaction_id( $transaction ),
			sprintf(
				/* translators: %s: the reason Afriex gave for the failed payment */
				__( 'Afriex could not complete this payment: %s', 'afriex-gateway-for-woocommerce' ),
				$reason
			),
			true
		);
	}

	/**
	 * Say that a payment arrived in an account this order has replaced (F20).
	 *
	 * The payment is not stopped here. Whether it completes the order is the
	 * decision table's business, and it decides on the same evidence as any
	 * other deposit: the expectation recorded beside the account and the
	 * order's own total. What this adds is the one thing the decision table
	 * cannot know — that the shopper has been shown two accounts for this
	 * order and may yet pay into the other one too.
	 *
	 * @param WC_Order $order      Order, loaded under the lock.
	 * @param string   $account_id The superseded virtual account.
	 * @param string   $txn_id     Transaction id.
	 * @param array    $context    Log context.
	 */
	private static function report_superseded_account( WC_Order $order, string $account_id, string $txn_id, array $context ): void {
		Afriex_Order_Meta::flag_for_review( $order, 'superseded_account' );

		$noted = Afriex_Order_Meta::note_once(
			$order,
			'superseded_account:' . $txn_id,
			sprintf(
				/* translators: 1: transaction id, 2: the Afriex account the money arrived in, 3: the account this order now uses */
				__( 'Afriex payment %1$s arrived in bank account %2$s, which this order has replaced with %3$s. It has been matched to this order. Check whether the customer has also transferred to the current account.', 'afriex-gateway-for-woocommerce' ),
				$txn_id,
				$account_id,
				Afriex_Order_Meta::get_payment_method_id( $order )
			)
		);

		if ( $noted ) {
			self::email_admin(
				$order,
				__( 'Afriex: a payment into a replaced bank account', 'afriex-gateway-for-woocommerce' ),
				sprintf(
					/* translators: 1: order number, 2: transaction id, 3: the Afriex account the money arrived in */
					__( 'Order %1$s received Afriex payment %2$s in bank account %3$s, which the order had already replaced. Check whether the customer has also paid into the current account.', 'afriex-gateway-for-woocommerce' ),
					$order->get_order_number(),
					$txn_id,
					$account_id
				)
			);
			self::queue_review_notice( $order, 'superseded_account' );
		}

		Afriex_Logger::warning(
			'A payment arrived in an Afriex account this order has replaced',
			array_merge( $context, array( 'superseded' => $account_id ) )
		);
	}

	/**
	 * Step 7 refused: the money is not what this order asked for.
	 *
	 * Partial deposits are reported, never summed: two half payments are two
	 * problems for a person, not one paid order (F31).
	 *
	 * The review flag carries the money check's own reason, so a payment in
	 * the wrong currency is flagged `currency_mismatch` and a short one
	 * `amount_mismatch`. The note reads the same either way, but the flag is
	 * what an admin filters and what the order screen explains, and the two
	 * are not the same problem.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $decision Decision, carrying the money check.
	 * @param array    $binding  Binding result, carrying the raw amounts.
	 * @param array    $context  Log context.
	 */
	private static function amount_mismatch( WC_Order $order, array $decision, array $binding, array $context ): array {
		$txn_id   = (string) $context['transaction_id'];
		$money    = is_array( $decision['money'] ) ? $decision['money'] : array();
		$expected = isset( $money['expected_minor'] ) ? $money['expected_minor'] : null;
		$currency = isset( $money['expected_currency'] ) ? (string) $money['expected_currency'] : '';
		$flag     = self::money_flag( isset( $money['reason'] ) ? (string) $money['reason'] : '' );

		$expected_text = ( null === $expected || '' === $currency )
			? __( 'unknown', 'afriex-gateway-for-woocommerce' )
			: Afriex_Order_Meta::format_amount( (float) Afriex_Money::to_major_string( (int) $expected, $currency ), $currency );

		Afriex_Order_Meta::flag_for_review( $order, $flag, $txn_id );
		Afriex_Order_Meta::note_once(
			$order,
			$flag . ':' . $txn_id,
			sprintf(
				/* translators: 1: expected amount, 2: destination amount and currency, 3: source amount and currency, 4: transaction id */
				__( 'Afriex payment does not match this order. Expected %1$s; Afriex reported %2$s received and %3$s sent (transaction %4$s). The order was not completed. Check it in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
				$expected_text,
				trim( $binding['amounts']['destination_amount'] . ' ' . $binding['amounts']['destination_currency'] ),
				trim( $binding['amounts']['source_amount'] . ' ' . $binding['amounts']['source_currency'] ),
				$txn_id
			)
		);

		Afriex_Logger::warning( 'Afriex amount mismatch', array_merge( $context, array( 'money' => $money ) ) );

		self::queue_review_notice( $order, $flag );

		return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );
	}

	/**
	 * The review flag for one money-check reason.
	 *
	 * Unknown reasons fall back to amount_mismatch: a flag nobody recognises
	 * would still have to be looked at, but it should say something an admin
	 * can act on.
	 *
	 * @param string $reason The money check's reason code.
	 */
	private static function money_flag( string $reason ): string {
		$flags = array( 'currency_mismatch', 'amount_unreadable', 'no_expectation', 'amount_mismatch' );

		return in_array( $reason, $flags, true ) ? $reason : 'amount_mismatch';
	}

	/**
	 * Step 7 refused by the amount guard: the money matches the session, but
	 * the session no longer matches the order.
	 *
	 * This is not a shopper doing anything wrong. The shopper paid exactly
	 * what the page in front of them asked for. What changed is the order,
	 * after that page was issued, so only a person can say whether to take
	 * the money, refund it, or ask for the difference.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $decision Decision, carrying the money check and the guard.
	 * @param array    $binding  Binding result, carrying the raw amounts.
	 * @param array    $context  Log context.
	 */
	private static function order_total_mismatch( WC_Order $order, array $decision, array $binding, array $context ): array {
		$txn_id   = (string) $context['transaction_id'];
		$money    = is_array( $decision['money'] ) ? $decision['money'] : array();
		$expected = isset( $money['expected_minor'] ) ? $money['expected_minor'] : null;
		$currency = isset( $money['expected_currency'] ) ? (string) $money['expected_currency'] : '';

		$session_text = ( null === $expected || '' === $currency )
			? __( 'unknown', 'afriex-gateway-for-woocommerce' )
			: Afriex_Order_Meta::format_amount( (float) Afriex_Money::to_major_string( (int) $expected, $currency ), $currency );

		// The pair money() matched is the one Afriex actually settled on, and
		// it is quoted as Afriex sent it rather than reformatted, so the note
		// can be compared with the Afriex dashboard line by line.
		$paid_text = 'source' === (string) ( $money['matched'] ?? '' )
			? trim( $binding['amounts']['source_amount'] . ' ' . $binding['amounts']['source_currency'] )
			: trim( $binding['amounts']['destination_amount'] . ' ' . $binding['amounts']['destination_currency'] );

		$paid_text  = '' === $paid_text ? $session_text : $paid_text;
		$total_text = Afriex_Order_Meta::format_amount( (float) $order->get_total(), $order->get_currency() );

		Afriex_Order_Meta::flag_for_review( $order, 'order_total_mismatch', $txn_id );

		$noted = Afriex_Order_Meta::note_once(
			$order,
			'order_total_mismatch:' . $txn_id,
			sprintf(
				/* translators: 1: amount Afriex was asked to collect, 2: amount Afriex confirmed, 3: the order's current total, 4: transaction id */
				__( 'Afriex confirmed a payment of %2$s against a request to collect %1$s, but this order now totals %3$s (transaction %4$s). The order was not completed, because the payment no longer matches what the order costs. Review it, then either adjust the order or refund the payment.', 'afriex-gateway-for-woocommerce' ),
				$session_text,
				$paid_text,
				$total_text,
				$txn_id
			)
		);

		if ( $noted ) {
			self::email_admin(
				$order,
				__( 'Afriex: a payment does not match the order total', 'afriex-gateway-for-woocommerce' ),
				sprintf(
					/* translators: 1: order number, 2: amount the session was created for, 3: the order's current total */
					__( 'Order %1$s was paid on an Afriex payment page created for %2$s, but the order now totals %3$s. The order was not completed and is flagged for review.', 'afriex-gateway-for-woocommerce' ),
					$order->get_order_number(),
					$session_text,
					$total_text
				)
			);
		}

		Afriex_Logger::warning(
			'Afriex payment does not match the order total',
			array_merge( $context, array( 'money' => $money ) )
		);

		self::queue_review_notice( $order, 'order_total_mismatch' );

		return self::outcome( self::OUTCOME_FLAGGED, $decision['reason'], $context );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Binding (plan 6.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Does this transaction belong to this order, and what did that attempt
	 * ask to be paid?
	 *
	 * Plan 6.2's "the order must be on-hold, pending or plugin-failed" is
	 * applied when candidates are resolved, not here. Here it would stop a
	 * payment that lands on a cancelled or already-paid order from ever being
	 * reported, which is exactly the case F14 and F87 exist for.
	 *
	 * @param WC_Order $order       Order, loaded under the lock.
	 * @param array    $transaction Transaction payload.
	 * @return array ok, reason, message, flag, is_current, expected_minor,
	 *               expected_currency, amounts.
	 */
	private static function verify_binding( WC_Order $order, array $transaction ): array {
		$binding = 'afriex_checkout' === $order->get_payment_method()
			? self::bind_checkout( $order, $transaction )
			: self::bind_bank_transfer( $order, $transaction );

		return array_merge(
			array(
				'ok'                => false,
				'reason'            => 'unbound',
				'message'           => __( 'it could not be matched to this order', 'afriex-gateway-for-woocommerce' ),
				'flag'              => false,
				'is_current'        => false,
				'superseded'        => '',
				'expected_minor'    => null,
				'expected_currency' => '',
				'amounts'           => Afriex_Settlement_Decision::amounts( $transaction ),
			),
			$binding
		);
	}

	/**
	 * Afriex Checkout: bound by the reference this store minted.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 */
	private static function bind_checkout( WC_Order $order, array $transaction ): array {
		$reference = Afriex_Settlement_Decision::reference( $transaction );
		$parsed    = Afriex_Order_Meta::parse_reference( $reference );

		if ( 0 === $parsed ) {
			return array(
				'reason' => 'reference_unparsable',
				'flag'   => false,
			);
		}

		if ( $parsed !== $order->get_id() ) {
			return array(
				'reason' => 'reference_order_mismatch',
				'flag'   => false,
			);
		}

		$row = Afriex_Order_Meta::get_checkout_reference( $order, $reference );

		if ( null === $row ) {
			// The id in the reference names this order, but this order never
			// issued that reference. Someone wrote a reference, or a session
			// was created behind our back.
			return array(
				'reason'  => 'reference_not_issued',
				'flag'    => true,
				'message' => __( 'its payment reference was never issued for this order', 'afriex-gateway-for-woocommerce' ),
			);
		}

		// The reference must belong to this order and to no other (plan 6.2).
		$ids = Afriex_Order_Meta::find_order_ids_by_checkout_reference( $reference );

		if ( array( $order->get_id() ) !== $ids ) {
			return array(
				'reason'  => 'reference_lookup_disagrees',
				'flag'    => true,
				'message' => __( 'its payment reference does not belong to this order alone', 'afriex-gateway-for-woocommerce' ),
			);
		}

		return array(
			'ok'                => true,
			'reason'            => 'reference',
			'is_current'        => Afriex_Order_Meta::get_current_checkout_reference( $order ) === $reference,
			'expected_minor'    => isset( $row['amount_minor'] ) ? $row['amount_minor'] : null,
			'expected_currency' => (string) ( $row['currency'] ?? '' ),
		);
	}

	/**
	 * Bank Transfer: bound by the virtual account the deposit arrived in.
	 *
	 * The order's current account is tried first, and then every account this
	 * order has been given and replaced (F20). A deposit into a superseded
	 * account is still this order's money — the shopper was shown that
	 * account — so it binds; what it is not is evidence about the attempt now
	 * in flight, so `is_current` is false and a stale FAILED on an old
	 * account can no longer fail an order that has just been re-issued.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $transaction Transaction payload.
	 */
	private static function bind_bank_transfer( WC_Order $order, array $transaction ): array {
		$account_id = Afriex_Order_Meta::get_payment_method_id( $order );
		$previous   = Afriex_Order_Meta::get_previous_payment_method_ids( $order );

		if ( '' === $account_id && empty( $previous ) ) {
			return array(
				'reason' => 'no_account_on_order',
				'flag'   => false,
			);
		}

		$source_id      = isset( $transaction['sourceId'] ) && is_scalar( $transaction['sourceId'] ) ? (string) $transaction['sourceId'] : '';
		$destination_id = isset( $transaction['destinationId'] ) && is_scalar( $transaction['destinationId'] ) ? (string) $transaction['destinationId'] : '';

		$matched    = '';
		$is_current = false;

		if ( '' !== $account_id && ( $source_id === $account_id || $destination_id === $account_id ) ) {
			$matched    = $account_id;
			$is_current = true;
		} else {
			foreach ( $previous as $old_id ) {
				if ( $source_id === $old_id || $destination_id === $old_id ) {
					$matched = $old_id;
					break;
				}
			}
		}

		if ( '' === $matched ) {
			return array(
				'reason' => 'account_mismatch',
				'flag'   => false,
			);
		}

		$txn_customer   = isset( $transaction['customerId'] ) && is_scalar( $transaction['customerId'] ) ? (string) $transaction['customerId'] : '';
		$order_customer = Afriex_Order_Meta::get_customer_id( $order );

		// When both sides name a customer they have to be the same one.
		if ( '' !== $txn_customer && '' !== $order_customer && ! hash_equals( $order_customer, $txn_customer ) ) {
			return array(
				'reason'  => 'customer_mismatch',
				'flag'    => true,
				'message' => __( 'it names a different Afriex customer', 'afriex-gateway-for-woocommerce' ),
			);
		}

		if ( 'pool' === Afriex_Order_Meta::get_collection_method( $order ) ) {
			return self::bind_legacy_pool( $order, $transaction, $txn_customer, $order_customer );
		}

		// A dedicated account belongs to one order. If two orders claim it,
		// something is wrong that guessing would make worse (F30).
		$ids = Afriex_Order_Meta::find_order_ids_by_any_payment_method_id( $matched );

		if ( count( $ids ) > 1 ) {
			return array(
				'reason'  => 'account_ambiguous',
				'flag'    => true,
				'message' => __( 'its Afriex account is recorded against more than one order', 'afriex-gateway-for-woocommerce' ),
			);
		}

		$expected = Afriex_Order_Meta::get_expected( $order );

		// An order placed before the 1.1 backfill carries no expectation. The
		// order total is what the customer was told to pay, so it stands in;
		// a missing expectation must never read as a mismatch.
		if ( null === $expected['minor'] || '' === $expected['currency'] ) {
			$expected = array(
				'minor'    => Afriex_Money::to_minor( $order->get_total(), $order->get_currency() ),
				'currency' => strtoupper( $order->get_currency() ),
			);
		}

		return array(
			'ok'                => true,
			'reason'            => $is_current ? 'payment_method' : 'superseded_payment_method',
			'is_current'        => $is_current,
			'superseded'        => $is_current ? '' : $matched,
			'expected_minor'    => $expected['minor'],
			'expected_currency' => $expected['currency'],
		);
	}

	/**
	 * A 1.0 order that shares a pool account with every other 1.0 order.
	 *
	 * The account id proves nothing here, so it settles only on an exact
	 * account, customer and reference match, and is otherwise left for a
	 * person (plan 6.2, section 12).
	 *
	 * @param WC_Order $order          Order.
	 * @param array    $transaction    Transaction payload.
	 * @param string   $txn_customer   Customer id on the transaction.
	 * @param string   $order_customer Customer id on the order.
	 */
	private static function bind_legacy_pool( WC_Order $order, array $transaction, string $txn_customer, string $order_customer ): array {
		$reference       = Afriex_Settlement_Decision::reference( $transaction );
		$order_reference = Afriex_Order_Meta::get_reference( $order );

		$exact = '' !== $txn_customer
			&& '' !== $order_customer
			&& hash_equals( $order_customer, $txn_customer )
			&& '' !== $reference
			&& '' !== $order_reference
			&& hash_equals( $order_reference, $reference );

		if ( ! $exact ) {
			return array(
				'reason'  => 'legacy_pool_inexact',
				'flag'    => true,
				'message' => __( 'it arrived in a shared (pool) account and does not match this order\'s customer and reference exactly', 'afriex-gateway-for-woocommerce' ),
			);
		}

		$expected = Afriex_Order_Meta::get_expected( $order );

		if ( null === $expected['minor'] || '' === $expected['currency'] ) {
			$expected = array(
				'minor'    => Afriex_Money::to_minor( $order->get_total(), $order->get_currency() ),
				'currency' => strtoupper( $order->get_currency() ),
			);
		}

		return array(
			'ok'                => true,
			'reason'            => 'legacy_pool_exact',
			'is_current'        => true,
			'expected_minor'    => $expected['minor'],
			'expected_currency' => $expected['currency'],
		);
	}

	/**
	 * A transaction that does not belong here, said once and then dropped.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $binding Binding result.
	 * @param array    $context Log context.
	 */
	private static function refuse_binding( WC_Order $order, array $binding, array $context ): array {
		$context['reason'] = $binding['reason'];

		if ( $binding['flag'] ) {
			Afriex_Order_Meta::flag_for_review( $order, $binding['reason'] );
			Afriex_Order_Meta::note_once(
				$order,
				'unbound:' . $binding['reason'] . ':' . $context['transaction_id'],
				sprintf(
					/* translators: 1: Afriex transaction id, 2: reason the transaction was refused */
					__( 'Afriex transaction %1$s was not applied to this order because %2$s. Check it in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
					$context['transaction_id'],
					$binding['message']
				)
			);
			self::queue_review_notice( $order, $binding['reason'] );

			Afriex_Logger::warning( 'Settlement refused: binding', $context );

			return self::outcome( self::OUTCOME_FLAGGED, $binding['reason'], $context );
		}

		// Ordinary and not an error: one Afriex account sees transactions that
		// have nothing to do with any one order.
		Afriex_Logger::debug( 'Settlement skipped: not bound to this order', $context );

		return self::outcome( self::OUTCOME_IGNORED, $binding['reason'], $context );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The ledger claim (plan 6.3 step 8).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Claim one transaction for one order, for good.
	 *
	 * The claim is what stops the same deposit paying for two orders, and it
	 * is a row in the plugin table, so it is correct with a persistent object
	 * cache and under concurrency (F16, F23). A claim this order already
	 * holds is a success, so a retry of the same work is idempotent.
	 *
	 * @param int    $order_id Order id.
	 * @param string $txn_id   Afriex transaction id.
	 * @return array ok, reason, holder_order_id.
	 */
	private static function claim_transaction( int $order_id, string $txn_id ): array {
		if ( '' === trim( $txn_id ) ) {
			return array(
				'ok'       => false,
				'reason'   => 'empty_transaction_id',
				'other_id' => 0,
			);
		}

		if ( Afriex_Ledger::claim( Afriex_Ledger::KIND_SETTLED, $txn_id, $order_id, 'settled' ) ) {
			return array(
				'ok'       => true,
				'reason'   => 'claimed',
				'other_id' => 0,
			);
		}

		$holder = Afriex_Ledger::holder( Afriex_Ledger::KIND_SETTLED, $txn_id );

		if ( is_array( $holder ) && (int) $holder['order_id'] === $order_id ) {
			return array(
				'ok'       => true,
				'reason'   => 'already_ours',
				'other_id' => 0,
			);
		}

		return array(
			'ok'       => false,
			'reason'   => 'claimed_by_other_order',
			'other_id' => is_array( $holder ) ? (int) $holder['order_id'] : 0,
		);
	}

	/**
	 * The transaction is spoken for, or has no id. Either way, stop.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $claim   claim_transaction() result.
	 * @param array    $context Log context.
	 */
	private static function refuse_claim( WC_Order $order, array $claim, array $context ): array {
		$context['reason'] = $claim['reason'];

		Afriex_Order_Meta::flag_for_review( $order, $claim['reason'] );

		if ( 'empty_transaction_id' === $claim['reason'] ) {
			Afriex_Order_Meta::note_once(
				$order,
				'no_transaction_id:' . $context['status'],
				__( 'Afriex confirmed a payment for this order but did not give a transaction id, so it was not applied. Check the order in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' )
			);
		} else {
			Afriex_Order_Meta::note_once(
				$order,
				'claimed_elsewhere:' . $context['transaction_id'],
				sprintf(
					/* translators: 1: Afriex transaction id, 2: order id that already used it */
					__( 'Afriex transaction %1$s was already used for order #%2$d, so it was not applied to this order.', 'afriex-gateway-for-woocommerce' ),
					$context['transaction_id'],
					(int) $claim['other_id']
				)
			);
		}

		self::queue_review_notice( $order, $claim['reason'] );

		Afriex_Logger::warning( 'Settlement refused: transaction claim', array_merge( $context, array( 'holder' => $claim['other_id'] ) ) );

		return self::outcome( self::OUTCOME_FLAGGED, $claim['reason'], $context );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Expiry (plan 6.3, the expire routine).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The order's payment window has closed: find out what really happened.
	 *
	 * Cancelling is the last thing this does and the rarest. A SUCCESS is
	 * settled first, because a deposit that arrived while nobody was looking
	 * must not be thrown away by a timer, and a transaction still in flight
	 * moves the deadline instead of ending the order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $source   One of the SOURCE_* constants.
	 * @param float  $budget   Seconds this routine may spend talking to
	 *                         Afriex, 0 for the client's own defaults. A
	 *                         caller working to a deadline of its own — the
	 *                         webhook has 20 s for the whole delivery — passes
	 *                         what is left of it, because the client is built
	 *                         in here and a budget set outside cannot reach it.
	 * @return array See outcome().
	 */
	public static function expire( int $order_id, string $source = self::SOURCE_SWEEP, float $budget = 0.0 ): array {
		$context = array(
			'order_id'       => $order_id,
			'transaction_id' => '',
			'status'         => '',
			'source'         => $source,
		);

		if ( $order_id <= 0 ) {
			return self::outcome( self::OUTCOME_IGNORED, 'no_order', $context );
		}

		$lock = self::lock_key( $order_id );

		if ( ! Afriex_Ledger::lock( $lock, self::lock_timeout( $source ), self::LOCK_TTL ) ) {
			return self::outcome( self::OUTCOME_LOCKED, 'lock_timeout', $context, true );
		}

		try {
			return self::expire_locked( $order_id, $source, $context, $budget );
		} finally {
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * The expire routine with the lock held.
	 *
	 * @param int    $order_id Order id.
	 * @param string $source   Caller.
	 * @param array  $context  Log context.
	 * @param float  $budget   Seconds available for Afriex calls, 0 for none.
	 */
	private static function expire_locked( int $order_id, string $source, array $context, float $budget = 0.0 ): array {
		self::flush_order_cache( $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::outcome( self::OUTCOME_IGNORED, 'order_missing', $context );
		}

		if ( ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return self::outcome( self::OUTCOME_IGNORED, 'not_afriex', $context );
		}

		if ( self::is_already_settled( $order, '' ) ) {
			return self::outcome( self::OUTCOME_IGNORED, 'already_settled', $context );
		}

		if ( ! in_array( $order->get_status(), Afriex_Settlement_Decision::ELIGIBLE_STATUSES, true ) ) {
			return self::outcome( self::OUTCOME_IGNORED, 'order_status:' . $order->get_status(), $context );
		}

		// An order a person has been asked to look at is not ended by a
		// timer. The legacy pool orders the migration set aside are the
		// clearest case: their account id is a shared pool, so its
		// transactions say nothing about this one order (plan 12.3). The
		// routine still runs — a SUCCESS that binds exactly is still applied
		// — but the cancel at the end is off the table.
		$review_reason = Afriex_Order_Meta::get_review_flag( $order );
		$is_pool       = 'pool' === (string) $order->get_meta( Afriex_Order_Meta::KEY_COLLECTION_METHOD );
		$may_cancel    = '' === $review_reason && ! $is_pool;

		$client = self::client_for_order( $order );

		if ( ! $client->is_configured() ) {
			return self::outcome( self::OUTCOME_IGNORED, 'not_configured', $context );
		}

		if ( $budget > 0.0 ) {
			$client->set_time_budget( $budget );
		}

		$info = array();
		$rows = self::collect_transactions( $order, $client, $info );

		if ( is_wp_error( $rows ) ) {
			Afriex_Logger::info( 'Expiry check could not reach Afriex', array_merge( $context, array( 'error' => $rows->get_error_message() ) ) );

			// The HTTP status travels with the outcome so that the sweep can
			// tell "Afriex is rate-limiting us, stop the whole run" from "this
			// one order could not be checked" (plan 6.4).
			$context['api_status']      = self::error_status( $rows );
			$context['api_retry_after'] = self::error_retry_after( $rows );

			return self::outcome( self::OUTCOME_ERROR, 'api_error', $context, true );
		}

		// Any success settles, and settles first.
		$settled_any = false;

		foreach ( $rows as $row ) {
			if ( ! Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $row ) ) ) {
				continue;
			}

			$result      = self::settle( $order_id, $row, $source );
			$settled_any = true;

			if ( self::OUTCOME_SETTLED === $result['outcome'] || self::OUTCOME_FLAGGED === $result['outcome'] ) {
				return $result;
			}
		}

		if ( $settled_any ) {
			// settle() reloaded the order and saved through its own copy, so
			// the one in hand is stale. Anything written from here on — a
			// deduplicated note, a deadline, a status change — would silently
			// overwrite what it just recorded.
			self::flush_order_cache( $order_id );

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return self::outcome( self::OUTCOME_IGNORED, 'order_missing', $context );
			}

			if ( self::is_already_settled( $order, '' ) ) {
				return self::outcome( self::OUTCOME_IGNORED, 'already_settled', $context );
			}
		}

		// Anything still moving buys the order more time, once per transaction.
		// Post-payment statuses (REFUNDED, DISPUTED and the rest) are not
		// "still moving": is_terminal() excludes them deliberately, but an
		// order holding a refunded deposit must not have its deadline pushed
		// forward every five minutes for ever. They are counted below instead.
		foreach ( $rows as $row ) {
			$status = Afriex_Settlement_Decision::status( $row );

			if ( Afriex_Status_Mapper::is_terminal( $status ) || Afriex_Status_Mapper::is_post_payment( $status ) ) {
				continue;
			}

			/**
			 * How much longer an order waits when Afriex still has its payment
			 * in flight at the deadline.
			 *
			 * @param int      $seconds Extension in seconds.
			 * @param WC_Order $order   Order.
			 */
			$extension = (int) apply_filters( 'afriex_wc_deadline_extension', self::DEADLINE_EXTENSION, $order );

			Afriex_Order_Meta::extend_deadline( $order, time() + max( 60, $extension ) );
			Afriex_Order_Meta::note_once(
				$order,
				'deadline_extended:' . Afriex_Settlement_Decision::transaction_id( $row ),
				sprintf(
					/* translators: %s: Afriex status */
					__( 'The payment window for this order has passed, but Afriex still reports the payment as %s. The order is being held a little longer.', 'afriex-gateway-for-woocommerce' ),
					$status
				)
			);

			$context['status'] = $status;

			return self::outcome( self::OUTCOME_EXTENDED, 'in_flight', $context );
		}

		// Cancelling needs positive evidence that every transaction Afriex
		// holds for this order terminally failed. A SUCCESS that settle()
		// declined to apply, and a post-payment status on an unsettled order,
		// are both "somebody has to look at this" — never "no payment
		// arrived" (plan 6.3, the expire routine).
		$unresolved = array();

		foreach ( $rows as $row ) {
			$status = Afriex_Settlement_Decision::status( $row );

			if ( ! Afriex_Status_Mapper::is_terminal_failure( $status ) ) {
				$unresolved[] = Afriex_Settlement_Decision::transaction_id( $row ) . ' (' . $status . ')';
			}
		}

		if ( ! empty( $unresolved ) ) {
			Afriex_Order_Meta::flag_for_review( $order, 'unresolved_at_deadline' );
			Afriex_Order_Meta::note_once(
				$order,
				'unresolved_at_deadline:' . md5( implode( ',', $unresolved ) ),
				sprintf(
					/* translators: %s: comma-separated Afriex transaction ids and statuses */
					__( 'The Afriex payment window for this order closed, but Afriex reports a payment that this plugin could not apply: %s. The order has been left as it is for review.', 'afriex-gateway-for-woocommerce' ),
					implode( ', ', $unresolved )
				)
			);
			self::queue_review_notice( $order, 'unresolved_at_deadline' );
			self::back_off_deadline( $order );

			Afriex_Logger::warning(
				'Order left for review at its Afriex deadline',
				array_merge( $context, array( 'unresolved' => $unresolved ) )
			);

			return self::outcome( self::OUTCOME_FLAGGED, 'unresolved_at_deadline', $context );
		}

		// A listing that hit the page ceiling, or an account Afriex no longer
		// knows, is not an answer about this order. Cancelling on it would
		// end an order somebody has paid for.
		if ( ! empty( $info['truncated'] ) ) {
			$truncations = Afriex_Order_Meta::count_truncated_check( $order );

			self::back_off_deadline( $order );

			// Extending on a listing nobody can read is right once. Doing it
			// for ever is not: the condition feeds itself (fromDate comes from
			// the order's own creation date, so the longer the order is stuck
			// the wider its window and the more certainly the next listing
			// truncates too), and the merchant is left with an order that
			// never ends, never releases its stock and says nothing about why.
			// Past the limit the order is handed to a person, out loud.
			if ( $truncations >= Afriex_Bank_Transfer_Sweep::TRUNCATION_LIMIT ) {
				Afriex_Order_Meta::flag_for_review( $order, 'unreadable_at_deadline' );
				Afriex_Order_Meta::note_once(
					$order,
					'unreadable_at_deadline:' . $truncations,
					__( 'The Afriex payment window for this order closed, but this plugin could not read Afriex\'s transaction list far enough back to be sure whether a payment arrived, several times in a row. The order has NOT been cancelled — that would risk ending an order somebody has paid for — and has been left for review. Check it in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' )
				);
				self::queue_review_notice( $order, 'unreadable_at_deadline' );

				Afriex_Logger::warning(
					'Expiry gave up on a listing it could never read to the end',
					array_merge( $context, array( 'truncated_checks' => $truncations ) )
				);

				return self::outcome( self::OUTCOME_FLAGGED, 'unreadable_at_deadline', $context );
			}

			Afriex_Logger::warning( 'Expiry could not read the whole transaction list; not cancelling', $context );

			return self::outcome( self::OUTCOME_EXTENDED, 'listing_truncated', $context );
		}

		Afriex_Order_Meta::clear_truncated_checks( $order );

		// This is what the Bank Transfer re-fetch is for, along with telling a
		// 404 from a 5xx above. Afriex can still be holding the virtual
		// account open after the store's own window has closed, which means a
		// transfer the shopper has already started can still land in it after
		// this cancellation. The order still ends — the payment window is the
		// merchant's decision, and plan 5.6 already lets the account's expiry
		// set the deadline when Afriex gives one — but the order says so, so
		// a late deposit is not a mystery.
		$account_expiry = isset( $info['account_expires_at'] ) ? (int) $info['account_expires_at'] : 0;

		if ( $account_expiry > time() ) {
			Afriex_Order_Meta::note_once(
				$order,
				'account_still_open:' . $account_expiry,
				sprintf(
					/* translators: %s: date and time the Afriex account closes */
					__( 'Note: Afriex still has this order\'s bank account open until %s, so a transfer already under way could still arrive after this order closes. Any such payment is reported on this order and refunded or applied by hand.', 'afriex-gateway-for-woocommerce' ),
					wp_date( 'Y-m-d H:i', $account_expiry )
				)
			);

			$context['account_expires_at'] = $account_expiry;
		}

		if ( '' !== (string) $info['account_status'] ) {
			$context['account_status'] = (string) $info['account_status'];
		}

		if ( ! $may_cancel ) {
			// A flagged or legacy-pool order ends only by hand (plan 12.3).
			Afriex_Order_Meta::note_once(
				$order,
				'expiry_needs_review:' . ( '' !== $review_reason ? $review_reason : 'pool' ),
				__( 'The Afriex payment window for this order closed. This order is marked for review, so it has not been cancelled automatically. Check it by hand.', 'afriex-gateway-for-woocommerce' )
			);
			self::queue_review_notice( $order, '' !== $review_reason ? $review_reason : 'legacy_pool' );
			self::back_off_deadline( $order );

			return self::outcome( self::OUTCOME_IGNORED, '' !== $review_reason ? 'needs_review' : 'pool_account', $context );
		}

		// Everything Afriex has for this order failed, or there is nothing at
		// all. Only now does the order end.
		$order->update_status(
			'cancelled',
			empty( $rows )
				? __( 'The Afriex payment window for this order closed and no payment arrived.', 'afriex-gateway-for-woocommerce' )
				: __( 'The Afriex payment window for this order closed, and every payment attempt Afriex reported had failed.', 'afriex-gateway-for-woocommerce' )
		);

		Afriex_Logger::info( 'Order cancelled at its Afriex deadline', array_merge( $context, array( 'transactions' => count( $rows ) ) ) );

		return self::outcome( self::OUTCOME_CANCELLED, empty( $rows ) ? 'no_transactions' : 'all_failed', $context );
	}

	/**
	 * Push an order's deadline out so a routine that refused to decide does
	 * not run again on the next sweep.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function back_off_deadline( WC_Order $order ): void {
		/** This filter is documented in includes/class-afriex-settlement.php */
		$extension = (int) apply_filters( 'afriex_wc_deadline_extension', self::DEADLINE_EXTENSION, $order );

		Afriex_Order_Meta::extend_deadline( $order, time() + max( 60, $extension ) );
	}

	/**
	 * Every Afriex transaction that could belong to this order.
	 *
	 * Rows are matched to the order locally as well as filtered at the API,
	 * because a filter Afriex quietly ignores would otherwise return the whole
	 * account's history, and the expire routine would read someone else's
	 * pending transfer as a reason to hold this order open. Only filters
	 * Afriex publishes are sent (plan 9, F2): type, channel, currency and
	 * fromDate. The account id is matched here, never at the API.
	 *
	 * Public because the Afriex Checkout poll needs exactly the same set of
	 * rows as the expire routine. Two ways of deciding which transactions
	 * belong to an order would be one too many.
	 *
	 * The listing can also come back short of the truth — Afriex may ignore
	 * the account filter, and the page ceiling is finite — so $info reports
	 * whether what was fetched is a complete answer. The expire routine
	 * refuses to cancel an order on a truncated listing: absence of evidence
	 * is not evidence that no payment arrived.
	 *
	 * @param WC_Order          $order  Order.
	 * @param Afriex_Api_Client $client Client for the order's environment.
	 * @param array|null        $info   Out: truncated, account_expires_at,
	 *                                  account_status, account_missing.
	 * @return array|WP_Error
	 */
	public static function collect_transactions( WC_Order $order, Afriex_Api_Client $client, &$info = null ) {
		$rows = array();
		$info = array(
			'truncated'          => false,
			'account_status'     => '',
			'account_expires_at' => 0,
			'account_missing'    => false,
			'error'              => null,
		);

		if ( 'afriex_checkout' === $order->get_payment_method() ) {
			$references = self::checkout_references_to_list( $order, $info );

			foreach ( $references as $reference ) {
				$result = $client->list_transactions( array( 'reference' => $reference ) );

				if ( is_wp_error( $result ) ) {
					// Whatever has already been collected is still evidence,
					// and throwing it away here was deterministic rather than
					// unlucky: the caller gives the whole poll one time budget,
					// so once it is spent EVERY remaining reference answers
					// afriex_time_budget_exceeded at the same point on every
					// run. A SUCCESS found on the first reference was being
					// discarded because the fourth timed out, so the order
					// could neither settle nor be cancelled.
					//
					// So the rows in hand are kept and the failure travels
					// beside them. 'truncated' means the caller must not
					// cancel on this answer — it is incomplete — while any
					// SUCCESS among the rows still settles.
					$info['error']     = $result;
					$info['truncated'] = true;

					Afriex_Logger::info(
						'A Checkout reference listing failed part-way through',
						array(
							'order_id'  => $order->get_id(),
							'reference' => $reference,
							'collected' => count( $rows ),
							'error'     => $result->get_error_message(),
						)
					);

					break;
				}

				if ( ! empty( $result['truncated'] ) ) {
					$info['truncated'] = true;
				}

				foreach ( $result['data'] as $row ) {
					if ( is_array( $row ) && Afriex_Settlement_Decision::reference( $row ) === $reference ) {
						$rows[] = $row;
					}
				}
			}

			// Nothing was gathered at all, so there is nothing to weigh
			// against the failure: report it as the failure it is, exactly as
			// before.
			if ( empty( $rows ) && $info['error'] instanceof WP_Error ) {
				return $info['error'];
			}

			return $rows;
		}

		// Bank Transfer. The account is re-fetched first, as the plan says,
		// and what it says about its own expiry is used: an account Afriex
		// still holds open past the store's window keeps the order open too.
		$account_id = Afriex_Order_Meta::get_payment_method_id( $order );

		if ( '' === $account_id ) {
			return $rows;
		}

		$account = $client->get_payment_method( $account_id );

		if ( is_wp_error( $account ) ) {
			// A 4xx is an answer — Afriex no longer knows this account — and
			// twelve more sweeps would get the same one, so the routine
			// carries on without it rather than re-polling the order for
			// ever. Only a 5xx, a 429 or a transport failure is "try again".
			if ( ! self::is_permanent_error( $account ) ) {
				return $account;
			}

			$info['account_missing'] = true;

			Afriex_Logger::info(
				'Afriex no longer knows this order\'s virtual account',
				array(
					'order_id' => $order->get_id(),
					'status'   => self::error_status( $account ),
				)
			);
		} elseif ( is_array( $account ) ) {
			$info['account_status']     = isset( $account['status'] ) && is_scalar( $account['status'] )
				? strtolower( (string) $account['status'] )
				: '';
			$info['account_expires_at'] = self::account_expiry( $account );
		}

		$transaction_id = Afriex_Order_Meta::get_transaction_id( $order );

		if ( '' !== $transaction_id ) {
			$transaction = $client->get_transaction( $transaction_id );

			if ( is_wp_error( $transaction ) ) {
				// Same split: a 404 for a transaction id this order recorded
				// is an answer, not a reason to keep the order in limbo.
				if ( ! self::is_permanent_error( $transaction ) ) {
					return $transaction;
				}

				Afriex_Logger::info(
					'Afriex no longer knows this order\'s recorded transaction',
					array(
						'order_id'       => $order->get_id(),
						'transaction_id' => $transaction_id,
						'status'         => self::error_status( $transaction ),
					)
				);
			} else {
				$rows[] = $transaction;
			}
		}

		// The deposits. The sweep lists once per run with Afriex's documented
		// filters and fans the rows out to orders in memory (plan 9), so when
		// a run is in progress this order's share of that listing is already
		// in hand. Off a sweep — a PAYMENT_METHOD.UPDATED webhook, the order
		// action — the same strict listing is made for this order alone.
		$listed = Afriex_Bank_Transfer_Sweep::rows_for_order( $order->get_id() );

		if ( null !== $listed && Afriex_Bank_Transfer_Sweep::was_truncated( $order->get_id() ) ) {
			// The run's listing is bounded at MAX_PAGES because it is shared
			// across up to twenty-five orders. A truncated answer is not an
			// answer about this order, and this is the caller that decides
			// whether the order may be cancelled: taking the run's word for
			// it meant that a store whose deposit history had outgrown the
			// page ceiling could never end a Bank Transfer order again, and
			// the condition fed itself. So ask once more for this order
			// alone, where the ceiling is ORDER_MAX_PAGES. Only an order at
			// its payment window's close reaches this, so the extra listing
			// is bounded by the batch size and happens only when the shared
			// listing really was cut short.
			$listed = null;
		}

		if ( null === $listed ) {
			$truncated = false;
			$listed    = Afriex_Bank_Transfer_Sweep::list_for_order( $order, $client, $truncated );

			if ( is_wp_error( $listed ) ) {
				return $listed;
			}

			$info['truncated'] = $info['truncated'] || $truncated;
		}

		foreach ( $listed as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Which of a Checkout order's references one run will list, newest first.
	 *
	 * Afriex_Order_Meta::add_checkout_reference() appends a row per payment
	 * attempt and nothing trims them, and a shopper can mint a fresh one after
	 * every
	 * terminally failed attempt on the hosted page. Listing all of them, one
	 * call each, has no ceiling — and because the rows come back oldest first,
	 * the reference most likely to carry the payment was the one a spent time
	 * budget cut off first.
	 *
	 * So: newest first, every reference Afriex has not finished with, and
	 * enough of the closed ones after them to reach the cap. Anything left out
	 * makes the answer incomplete, which is recorded as 'truncated' so the
	 * caller will not cancel the order on it.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $info  Out: truncated, by reference.
	 * @return string[] References, newest first.
	 */
	private static function checkout_references_to_list( WC_Order $order, array &$info ): array {
		$all = array_keys( Afriex_Order_Meta::get_checkout_reference_rows( $order ) );

		if ( count( $all ) <= self::MAX_REFERENCE_LISTINGS ) {
			return array_reverse( $all );
		}

		$rows   = Afriex_Order_Meta::get_checkout_reference_rows( $order );
		$open   = array();
		$closed = array();

		foreach ( array_reverse( $all ) as $reference ) {
			if ( empty( $rows[ $reference ]['closed'] ) ) {
				$open[] = $reference;
			} else {
				$closed[] = $reference;
			}
		}

		// Open sessions come first, so the slice drops closed ones before any
		// open one. A closed session failed terminally and its hosted page can
		// no longer take money, so leaving it out loses no evidence. Only an
		// OPEN session left unlisted makes the answer incomplete; reporting
		// truncation for closed ones used to stop the expire routine from ever
		// cancelling an order that had been retried more than a few times.
		if ( count( $open ) > self::MAX_REFERENCE_LISTINGS ) {
			$info['truncated'] = true;
		}

		return array_slice( array_merge( $open, $closed ), 0, self::MAX_REFERENCE_LISTINGS );
	}

	/**
	 * When a fetched virtual account stops accepting money, as a UNIX time.
	 *
	 * @param array $account Payment method as Afriex returned it.
	 */
	private static function account_expiry( array $account ): int {
		// One reading of an Afriex account's expiry, shared with the gateway
		// that stores it at checkout, so the sweep and the order screen can
		// never disagree about when an account closes (F32).
		return Afriex_Order_Meta::account_expiry( $account );
	}

	/**
	 * Whether an API error is an answer rather than a bad moment.
	 *
	 * The same split the webhook handler makes (plan 7): a 4xx other than 429
	 * will not change however many times it is asked, so a caller that
	 * retries on it would retry for ever.
	 *
	 * @param WP_Error $error Client error.
	 */
	private static function is_permanent_error( WP_Error $error ): bool {
		$status = self::error_status( $error );

		return $status >= 400 && $status < 500 && 429 !== $status;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether this order has already been paid, by this transaction or any
	 * other (plan 6.3 step 5).
	 *
	 * The settled marker alone is not taken as proof. It is only ever written
	 * after WooCommerce has agreed the order is paid, but an order can be
	 * moved back afterwards, and a store that has lost its date_paid should
	 * be retried rather than frozen: "settled" has to mean the order really
	 * is, or was, paid. F87 still holds, because WooCommerce keeps
	 * date_paid when a merchant puts a paid order back on hold.
	 *
	 * @param WC_Order $order  Order, loaded under the lock.
	 * @param string   $txn_id Transaction being applied, or ''.
	 */
	private static function is_already_settled( WC_Order $order, string $txn_id ): bool {
		if ( null !== $order->get_date_paid() ) {
			return true;
		}

		if ( '' !== Afriex_Order_Meta::get_settled_transaction_id( $order ) && $order->is_paid() ) {
			return true;
		}

		if ( '' === $txn_id ) {
			return false;
		}

		// The ledger is the last word: a completion that crashed after the
		// claim but before the meta was saved still counts as settled here,
		// and is retried against this order rather than started again.
		$holder = Afriex_Ledger::holder( Afriex_Ledger::KIND_SETTLED, $txn_id );

		return is_array( $holder ) && (int) $holder['order_id'] === $order->get_id() && $order->is_paid();
	}

	/**
	 * Which transaction paid this order, as far as anything here can tell.
	 *
	 * Normally that is the durable _afriex_settled_transaction_id. There is a
	 * window in which it is not written yet and the order is already paid:
	 * complete() calls payment_complete() — which sets the paid date, changes
	 * the status and sends WooCommerce's emails — and writes the marker only
	 * afterwards, because a marker written before a completion that failed
	 * would leave an order that could never be completed and never cancelled.
	 *
	 * A delivery that lands in that window used to find a paid order with no
	 * settled transaction on it and conclude that some *other* payment had
	 * paid it: the order was flagged for review and the merchant was emailed
	 * about a second payment that never happened. That is exactly the case
	 * plan 15 asks about — a webhook, a return and a sweep racing on one
	 * transaction — and it is a false alarm about the customer's money, which
	 * is the worst kind.
	 *
	 * So when the marker is missing, the two records written *before*
	 * payment_complete() are read instead: the ledger's settled:{txn} claim,
	 * which is durable and names the order it was claimed for, and the
	 * settling marker, which says what this order was in the middle of
	 * applying. Neither can name a second payment, because a second payment
	 * carries a different transaction id and claims its own ledger row.
	 *
	 * @param WC_Order $order  Order, loaded under the lock.
	 * @param string   $txn_id The transaction being applied.
	 */
	private static function settled_transaction_id( WC_Order $order, string $txn_id ): string {
		$settled = Afriex_Order_Meta::get_settled_transaction_id( $order );

		if ( '' !== $settled || '' === $txn_id ) {
			return $settled;
		}

		$holder = Afriex_Ledger::holder( Afriex_Ledger::KIND_SETTLED, $txn_id );

		if ( is_array( $holder ) && (int) $holder['order_id'] === $order->get_id() ) {
			return $txn_id;
		}

		if ( Afriex_Order_Meta::get_settling_transaction_id( $order ) === $txn_id ) {
			return $txn_id;
		}

		return '';
	}

	/**
	 * Record what Afriex last said about this order.
	 *
	 * The transaction id is recorded only for the order's current attempt, so
	 * a stale delivery for an abandoned session does not make the order point
	 * at a payment it is not waiting for.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $status     Afriex status.
	 * @param string   $txn_id     Transaction id.
	 * @param bool     $is_current Whether this is the current attempt.
	 */
	private static function record_seen( WC_Order $order, string $status, string $txn_id, bool $is_current ): void {
		$changed = false;

		if ( '' !== $status && (string) $order->get_meta( Afriex_Order_Meta::KEY_LAST_STATUS ) !== $status ) {
			$order->update_meta_data( Afriex_Order_Meta::KEY_LAST_STATUS, $status );
			$changed = true;
		}

		if ( $is_current && '' !== $txn_id && (string) $order->get_meta( Afriex_Order_Meta::KEY_TRANSACTION_ID ) !== $txn_id ) {
			$order->update_meta_data( Afriex_Order_Meta::KEY_TRANSACTION_ID, $txn_id );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Drop every cached copy of an order before loading it under the lock.
	 *
	 * Without this, the process takes the lock and then reads an order from
	 * the object cache as it was before whoever held the lock changed it,
	 * which is how two processes both decide an order is unpaid (F8).
	 *
	 * Public because the sweep takes the order lock itself and has to follow
	 * the same rule before it loads the order it will write through.
	 *
	 * @param int $order_id Order id.
	 */
	public static function flush_order_cache( int $order_id ): void {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				$cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );

				if ( is_object( $cache ) && method_exists( $cache, 'remove' ) ) {
					$cache->remove( $order_id );
				}
			} catch ( Throwable $e ) {
				Afriex_Logger::debug( 'Could not clear the WooCommerce order cache', array( 'order_id' => $order_id ) );
			}
		}

		// Legacy posts storage keeps the order in the post and post-meta
		// caches, which clean_post_cache() covers and which do not cover HPOS.
		clean_post_cache( $order_id );

		// HPOS keeps the order object under 'orders' or, with the datastore
		// cache feature on, under the request-level group 'order_objects'
		// (OrderCache::get_object_type()); the OrderCache call above handles
		// whichever is in use. Its *meta* is a separate, persistent group,
		// 'orders_meta' (OrdersTableDataStoreMeta::get_cache_group()), and
		// nothing else evicts it — which would leave the order reloaded under
		// the lock carrying _afriex_settled_transaction_id from before the
		// lock was taken, the exact F8 race this method exists to close.
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'orders_meta' );

		// The one that actually matters, and the one this method was missing.
		//
		// WC_Data caches an order's raw meta under
		// generate_meta_cache_key( $id, 'orders' ), which is
		// get_cache_prefix( 'orders' ) . get_cache_prefix( 'object_{id}' ) .
		// 'object_meta_{id}' — not the bare order id. Deleting the bare id
		// therefore cleared nothing, and a request that had read the order
		// before taking the lock got its own cached meta back from the read
		// inside the lock. On a double submit that is the whole of F38: the
		// second request could not see the account the first had just written,
		// so it opened a second Afriex account, and, because its WC_Order had
		// been built with no _afriex_* meta at all, saving it INSERTED a
		// second set of rows instead of updating the first. The order then
		// carried two accounts, get_meta() returned the first, and the shopper
		// had been shown the second: a transfer into it could never be matched.
		//
		// Invalidating the per-object group is what WooCommerce itself does
		// (wc-order-item-functions.php, class-wc-post-data.php). It bumps one
		// prefix, so it costs a single cache write, touches no other order,
		// and works with a persistent object cache, where the new prefix is
		// visible to every process rather than only to this one.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'object_' . $order_id );
		}
	}

	/**
	 * The API client for the credentials an order was created under (F50).
	 *
	 * Built from the shared account settings, never from a gateway instance,
	 * so settlement works whether or not the method is still enabled.
	 *
	 * An order records both halves of its credentials: the environment, and a
	 * fingerprint of the key it was created with. Keeping the environment is
	 * not enough. The store holds exactly one API key, so a rotation, or an
	 * environment switch with the matching new key, replaces the only key
	 * that could read this order's payments. Authenticating the look-up with
	 * the replacement asks Afriex about an order under a business the key may
	 * not cover, and the answer — most likely a 404 or a 403 — would be read
	 * as "no such payment".
	 *
	 * So when the order names a key the store no longer has, this returns a
	 * client with no key at all. Every caller already checks is_configured()
	 * or handles the WP_Error the client returns, so the look-up is skipped
	 * rather than made wrongly, and the order is flagged and noted once so a
	 * person can see why it stopped being checked.
	 *
	 * $report is how a caller that does NOT hold the order's lock asks the same
	 * question without writing to the order. The webhook resolves and loads an
	 * order before it takes any lock, and the flag-and-note below ends in
	 * $order->save(): saving from outside the lock can overwrite what a
	 * concurrent lock holder has just written, and losing a
	 * _afriex_last_note_key entry that way shows up later as a duplicated
	 * order note. Those callers pass false; the per-order paths, which do hold
	 * the lock, leave it alone and the note is written there.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $report Whether to flag and note an order whose key is
	 *                         gone. Only a caller holding the order's lock
	 *                         may.
	 */
	public static function client_for_order( WC_Order $order, bool $report = true ): Afriex_Api_Client {
		$environment = self::order_environment( $order );

		if ( self::order_key_is_available( $order ) ) {
			return new Afriex_Api_Client( $environment, Afriex_Account_Settings::api_key() );
		}

		if ( $report ) {
			self::report_credentials_unavailable(
				$order,
				$environment,
				(string) $order->get_meta( Afriex_Order_Meta::KEY_KEY_FINGERPRINT ),
				Afriex_Account_Settings::key_fingerprint()
			);
		} else {
			Afriex_Logger::warning(
				'Afriex look-up skipped: this order was taken with an API key the store no longer has',
				array(
					'order_id'    => $order->get_id(),
					'environment' => $environment,
					'order_key'   => (string) $order->get_meta( Afriex_Order_Meta::KEY_KEY_FINGERPRINT ),
					'store_key'   => Afriex_Account_Settings::key_fingerprint(),
				)
			);
		}

		return new Afriex_Api_Client( $environment, '' );
	}

	/**
	 * The Afriex environment an order belongs to, normalised.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_environment( WC_Order $order ): string {
		$environment = Afriex_Order_Meta::get_environment( $order );

		return '' === $environment
			? Afriex_Account_Settings::environment()
			: Afriex_Api_Client::normalise_environment( $environment );
	}

	/**
	 * Whether the store still holds the API key this order was taken with.
	 *
	 * The read-only half of client_for_order(). The run-level Bank Transfer
	 * listing groups orders before it holds any order lock, and it must not be
	 * the thing that writes the "credentials unavailable" note: that belongs to
	 * the per-order path, under the lock. So the sweep asks this, and simply
	 * leaves an order whose key is gone out of the listing.
	 *
	 * No fingerprint on the order (a 1.0 order, or one migrated into 1.1) and
	 * no key at all in the settings are both "nothing to compare", not "the
	 * wrong key": the current key is the only one there is.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_key_is_available( WC_Order $order ): bool {
		$order_fingerprint = (string) $order->get_meta( Afriex_Order_Meta::KEY_KEY_FINGERPRINT );
		$store_fingerprint = Afriex_Account_Settings::key_fingerprint();

		return '' === $order_fingerprint
			|| '' === $store_fingerprint
			|| hash_equals( $order_fingerprint, $store_fingerprint );
	}

	/**
	 * Say once, in the log and on the order, that an order's own API key is
	 * gone.
	 *
	 * @param WC_Order $order             Order.
	 * @param string   $environment       The order's environment.
	 * @param string   $order_fingerprint Fingerprint the order was created with.
	 * @param string   $store_fingerprint Fingerprint of the key the store holds.
	 */
	private static function report_credentials_unavailable( WC_Order $order, string $environment, string $order_fingerprint, string $store_fingerprint ): void {
		Afriex_Logger::warning(
			'Afriex look-up skipped: this order was taken with an API key the store no longer has',
			array(
				'order_id'       => $order->get_id(),
				'environment'    => $environment,
				'order_key'      => $order_fingerprint,
				'store_key'      => $store_fingerprint,
				'payment_method' => $order->get_payment_method(),
			)
		);

		Afriex_Order_Meta::flag_for_review( $order, 'credentials_unavailable' );

		$noted = Afriex_Order_Meta::note_once(
			$order,
			'credentials_unavailable:' . $order_fingerprint,
			sprintf(
				/* translators: 1: environment, 2: the API key fingerprint recorded on the order */
				__( 'This order was taken with the Afriex %1$s API key whose fingerprint is %2$s, which is not the key this store now holds. (The fingerprint is a hash of the key, not part of it; the same value is shown beside the API key field in the Afriex settings.) Its payments are no longer being checked with Afriex, because asking with a different key would give an answer about a different account. Restore that key, or check this order in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
				$environment,
				$order_fingerprint
			)
		);

		if ( $noted ) {
			self::queue_review_notice( $order, 'credentials_unavailable' );
		}
	}

	/**
	 * Tell the store's admin address that an order needs a person.
	 *
	 * Called only where a note was actually added, so the deduplication that
	 * protects the order notes protects the inbox too.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $subject Subject, without the site name.
	 * @param string   $message Body.
	 */
	private static function email_admin( WC_Order $order, string $subject, string $message ): void {
		$to = get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$body = $message . "\r\n\r\n" . sprintf(
			/* translators: %s: order edit URL */
			__( 'Order: %s', 'afriex-gateway-for-woocommerce' ),
			$order->get_edit_order_url()
		);

		wp_mail(
			$to,
			sprintf(
				'[%1$s] %2$s',
				wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				$subject
			),
			$body
		);
	}

	/**
	 * Remember an order for the admin notice.
	 *
	 * Public so that a caller which refuses a transaction before settle() is
	 * reached — the webhook's order resolution, which can end in "this
	 * account belongs to more than one order" — can put the orders in front
	 * of a person the same way every other refusal does.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Short reason code.
	 */
	public static function queue_review_notice( WC_Order $order, string $reason ): void {
		$notice = get_option( self::REVIEW_NOTICE_OPTION, array() );
		$notice = is_array( $notice ) ? $notice : array();

		$notice[ (string) $order->get_id() ] = $reason;

		if ( count( $notice ) > self::NOTICE_MAX_ORDERS ) {
			$notice = array_slice( $notice, -self::NOTICE_MAX_ORDERS, null, true );
		}

		update_option( self::REVIEW_NOTICE_OPTION, $notice, false );
	}

	/**
	 * Take one order back out of the admin notice.
	 *
	 * The notice's own "dismiss" link deletes the whole option for every order
	 * at once, which is the wrong granularity for an order that has genuinely
	 * resolved: this removes exactly the one and leaves the rest standing.
	 *
	 * @param int $order_id Order id.
	 */
	public static function drop_from_review_notice( int $order_id ): void {
		$notice = get_option( self::REVIEW_NOTICE_OPTION, array() );

		if ( ! is_array( $notice ) || ! isset( $notice[ (string) $order_id ] ) ) {
			return;
		}

		unset( $notice[ (string) $order_id ] );

		if ( empty( $notice ) ) {
			delete_option( self::REVIEW_NOTICE_OPTION );
			return;
		}

		update_option( self::REVIEW_NOTICE_OPTION, $notice, false );
	}

	/**
	 * Hook the review notice and its dismissal.
	 */
	public static function register_admin_notices(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_notice_dismissal' ) );
	}

	/**
	 * Tell a store manager that a payment needs looking at.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = get_option( self::REVIEW_NOTICE_OPTION, array() );

		if ( ! is_array( $notice ) || empty( $notice ) ) {
			return;
		}

		$links = array();

		foreach ( array_keys( $notice ) as $order_id ) {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$links[] = sprintf(
				'<a href="%1$s">#%2$s</a>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
		}

		if ( empty( $links ) ) {
			delete_option( self::REVIEW_NOTICE_OPTION );
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'afriex_wc_dismiss', 'review' ), 'afriex_wc_dismiss_review' );

		printf(
			'<div class="notice notice-warning afriex-review-notice"><p>%1$s %2$s</p><p><a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Afriex reported something about these orders that was not applied automatically. Check each one in your Afriex dashboard:', 'afriex-gateway-for-woocommerce' ),
			wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Dismiss the review notice from its nonce-protected link.
	 */
	public static function handle_notice_dismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked below, before anything changes.
		if ( ! isset( $_GET['afriex_wc_dismiss'] ) || 'review' !== sanitize_key( wp_unslash( $_GET['afriex_wc_dismiss'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'afriex_wc_dismiss_review' );

		delete_option( self::REVIEW_NOTICE_OPTION );

		wp_safe_redirect( remove_query_arg( array( 'afriex_wc_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * The HTTP status a webhook should answer with for an outcome (plan 7).
	 *
	 * Only a lost lock and a genuine failure ask Afriex to try again. A
	 * refusal is a decision, not a failure, and repeating it would waste
	 * eleven more deliveries reaching the same answer.
	 *
	 * @param array $outcome An outcome from settle() or expire().
	 */
	public static function http_status( array $outcome ): int {
		return ! empty( $outcome['retry'] ) ? 500 : 200;
	}

	/**
	 * Shape one outcome.
	 *
	 * @param string $outcome One of the OUTCOME_* constants.
	 * @param string $reason  Short reason code.
	 * @param array  $context order_id, transaction_id, status, source.
	 * @param bool   $retry   Whether the caller should ask to be called again.
	 */
	private static function outcome( string $outcome, string $reason, array $context, bool $retry = false ): array {
		return array(
			'outcome'         => $outcome,
			'reason'          => $reason,
			'retry'           => $retry,
			'order_id'        => (int) ( $context['order_id'] ?? 0 ),
			'transaction_id'  => (string) ( $context['transaction_id'] ?? '' ),
			'status'          => (string) ( $context['status'] ?? '' ),
			'source'          => (string) ( $context['source'] ?? '' ),
			// 0 unless an Afriex call failed. The sweep reads these to decide
			// whether to stop the whole run (plan 6.4).
			'api_status'      => (int) ( $context['api_status'] ?? 0 ),
			'api_retry_after' => (int) ( $context['api_retry_after'] ?? 0 ),
		);
	}

	/**
	 * The HTTP status an Afriex client error carried, or 0.
	 *
	 * @param WP_Error $error Client error.
	 */
	public static function error_status( WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * The Retry-After an Afriex client error carried, in seconds, or 0.
	 *
	 * @param WP_Error $error Client error.
	 */
	public static function error_retry_after( WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['retry_after'] ) ? max( 0, (int) $data['retry_after'] ) : 0;
	}

	/**
	 * Shape one order resolution.
	 *
	 * @param int    $order_id   Resolved order id, or 0.
	 * @param string $reason     How it was resolved, or why it was not.
	 * @param int[]  $candidates Ids considered.
	 */
	private static function resolution( int $order_id, string $reason, array $candidates ): array {
		return array(
			'order_id'   => $order_id,
			'reason'     => $reason,
			'candidates' => $candidates,
		);
	}
}
