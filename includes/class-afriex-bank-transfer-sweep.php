<?php
/**
 * The Bank Transfer half of the sweep: one strict listing per run.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists Afriex's virtual-account deposits once per sweep run and fans the rows
 * out to the orders that are waiting for them (plan 9, F2, F15, F28, F30).
 *
 * Why a run and not an order. Until this class, a Bank Transfer check asked
 * Afriex about one order at a time with `destinationId={account}`, a filter
 * Afriex has never documented (plan 16, question 8). A filter the API quietly
 * ignores turns each of those calls into "the whole account's history", so
 * twenty-five due orders meant twenty-five full listings per run — and the one
 * thing that made them safe was that every row was filtered again locally
 * afterwards. Plan 9 asks for the other shape: one listing per run, built
 * only from filters Afriex publishes, fanned out to orders in memory.
 *
 *   type=DEPOSIT & channel=VIRTUAL_BANK_ACCOUNT & currency={store} &
 *   fromDate={the oldest open order}, page from 0, limit 100, bounded.
 *
 * What it will not do:
 * - **It never applies a row it cannot place.** A deposit whose sourceId and
 *   destinationId name no account this run is waiting for belongs to somebody
 *   else — another merchant, another plugin, a payout — and is counted and
 *   dropped (F2, F26, F28).
 * - **It refuses ambiguity.** One account id that names two orders is not a
 *   payment for the older, or the newer, or the larger; it is a mistake
 *   somewhere, and guessing would turn it into a mistake about money. Both
 *   orders are flagged for a person and neither is settled (F30).
 * - **It does not settle before the deadline, yet.** SETTLE_FROM_LISTING below
 *   is the one switch, and it is off. Plan 9 keeps the *pre-deadline* Bank
 *   Transfer check note-only until Afriex answers questions 7 to 9 of plan 16:
 *   which field carries the virtual account's id on an inbound deposit,
 *   whether GET /transaction ignores unknown filters, and whether
 *   virtual-account amounts are in major units. Until then the run records, on
 *   the order, exactly what it would have done — the real binding rules and the
 *   real decision table, through Afriex_Settlement::dry_run() — and changes
 *   nothing.
 *
 *   Read the scope of that switch literally, because it is narrower than it
 *   sounds. Plan 9 says "note-only … except for expiry", and the expire routine
 *   is that exception: at the payment window's close
 *   Afriex_Settlement::expire_locked() takes these same rows — through
 *   collect_transactions(), which reads rows_for_order() — and settles any
 *   SUCCESS among them, for both methods, whatever this switch says. Turning
 *   the switch off delays settlement-from-listing by one payment window; it
 *   does not prevent it. What the switch governs is whether a listed deposit
 *   completes an order the moment the sweep sees it, or only at the point where
 *   the alternative is cancelling an order that the same listing says was paid.
 */
class Afriex_Bank_Transfer_Sweep {

	/**
	 * THE SWITCH (plan 9).
	 *
	 * When false, the sweep's own check reports what Afriex says about a Bank
	 * Transfer order and settles nothing. Payments are confirmed from the
	 * webhook, and at the payment window's close by the expire routine.
	 *
	 * When true, a deposit that the listing places on exactly one order, and
	 * that the decision table accepts, completes that order as soon as the
	 * sweep sees it.
	 *
	 * IT GOVERNS THE PRE-DEADLINE CHECK ONLY. The expire routine settles from
	 * the same listing regardless (see the class docblock), because at that
	 * point the alternative is cancelling an order a listed SUCCESS says was
	 * paid. "Off" therefore means "not before the payment window closes", not
	 * "never".
	 *
	 * Turning it on is this one line, once Afriex has answered questions 7, 8
	 * and 9 of plan section 16. The filter below exists so that a test — or a
	 * store that has its own answers — can do it without editing the plugin.
	 */
	public const SETTLE_FROM_LISTING = false;

	/**
	 * The documented filters. Both are re-checked on every row, because a
	 * filter the API ignores must not be able to widen what gets applied.
	 */
	public const FILTER_TYPE    = 'DEPOSIT';
	public const FILTER_CHANNEL = 'VIRTUAL_BANK_ACCOUNT';

	/**
	 * Pages of 100 one run may read per currency (plan 6.4's "bounded per
	 * run"). Five is 500 deposits since the oldest open order was placed,
	 * which is a great deal of bank transfers for one five-minute window; past
	 * it the answer is marked truncated, and a truncated answer never cancels
	 * an order and never settles one.
	 */
	public const MAX_PAGES = 5;

	/**
	 * Pages of 100 a listing made for ONE order may read.
	 *
	 * The run-level ceiling is a fan-out budget: five pages cover twenty-five
	 * orders at once, so it is cheap per order. A single-order listing has no
	 * such sharing, and it is the listing the expire routine depends on to
	 * decide whether an order may be cancelled — a truncated answer is not an
	 * answer, so a store whose deposit history has outgrown MAX_PAGES could
	 * never cancel a Bank Transfer order again. Four times the pages buys a
	 * store four times the history before that happens, at the cost of up to
	 * twenty GETs for the one order that needs it.
	 *
	 * This is a mitigation, not a cure. The cure is plan 16 questions 7 to 9:
	 * a filter Afriex honours that narrows the listing to one virtual account.
	 * Until those are answered, TRUNCATION_LIMIT below makes sure a store that
	 * does outgrow even this is told rather than left with orders that quietly
	 * never end.
	 */
	public const ORDER_MAX_PAGES = 20;

	/**
	 * How many times running an order's expiry may be postponed because the
	 * listing was truncated before the order is handed to a person instead.
	 *
	 * A truncated listing is not evidence that no payment arrived, so the
	 * expire routine refuses to cancel on one — correctly. But the condition
	 * is self-sustaining (fromDate comes from the order's own creation date,
	 * so the longer an order is stuck the wider its window and the more
	 * certainly it truncates), and silently extending for ever leaves the
	 * merchant with an order that never ends, never releases its stock and
	 * says nothing about why.
	 */
	public const TRUNCATION_LIMIT = 3;

	/**
	 * Seconds of Afriex time the whole run's listing may spend, across every
	 * environment-and-currency group. prime() takes a deadline from this once
	 * and gives each group only what is left, so N groups cost the run one
	 * budget rather than N of them.
	 */
	public const LIST_BUDGET = 60.0;

	/**
	 * Slack on fromDate, for clock differences between the two sides.
	 */
	private const FROM_DATE_SLACK = DAY_IN_SECONDS;

	/**
	 * Whether prime() has run for this request.
	 *
	 * @var bool
	 */
	private static $primed = false;

	/**
	 * True for every order this run's listing covers, keyed by order id,
	 * including the ones it found nothing for. Anything not here falls back to
	 * a listing of its own, which is how check_now() and the webhook work.
	 *
	 * @var array<int,bool>
	 */
	private static $covered = array();

	/**
	 * Rows placed on each order, keyed by order id.
	 *
	 * @var array<int,array>
	 */
	private static $rows = array();

	/**
	 * The account ids that named more than one order, keyed by order id (F30).
	 *
	 * @var array<int,string[]>
	 */
	private static $ambiguous = array();

	/**
	 * Whether each order's currency listing hit the page ceiling, by order id.
	 *
	 * @var array<int,bool>
	 */
	private static $truncated = array();

	/**
	 * Whether a deposit the listing places on exactly one order may complete
	 * that order.
	 */
	public static function settles_from_listing(): bool {
		/**
		 * Whether the scheduled check may settle a Bank Transfer order from
		 * Afriex's transaction listing.
		 *
		 * Off until Afriex answers questions 7 to 9 of plan section 16. See the
		 * class docblock: a wrong answer completes an order nobody paid for.
		 *
		 * @param bool $settle Whether settling from the listing is allowed.
		 */
		return (bool) apply_filters( 'woocommerce_afriex_settle_bank_transfer_from_sweep', self::SETTLE_FROM_LISTING );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The once-per-run listing.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * List Afriex's deposits once for the whole run and place them on orders.
	 *
	 * Called before the run takes any order lock, so it writes nothing to any
	 * order: it reads the account ids, the currency and the created date to
	 * build its map, and everything that follows from a row happens later,
	 * under that order's own lock.
	 *
	 * @param int[] $order_ids The run's due orders, of both methods.
	 * @param float $budget    Seconds of Afriex time the listing may spend.
	 * @return array orders, list_calls, rows, matched, unmatched, ambiguous,
	 *               foreign, truncated, api_status, api_retry_after.
	 */
	public static function prime( array $order_ids, float $budget = self::LIST_BUDGET ): array {
		self::clear();
		self::$primed = true;

		$report = array(
			'orders'          => 0,
			'list_calls'      => 0,
			'rows'            => 0,
			'matched'         => 0,
			'unmatched'       => 0,
			'ambiguous'       => 0,
			'foreign'         => 0,
			'truncated'       => false,
			'api_status'      => 0,
			'api_retry_after' => 0,
		);

		$groups = self::group_orders( $order_ids, $report );

		if ( empty( $groups ) ) {
			return $report;
		}

		// One deadline for the whole listing, not one budget per group. A
		// multi-currency store, or one that switched environment and kept its
		// key, has several groups, and giving each of them the full budget
		// would let the run's listing alone spend N x LIST_BUDGET — enough,
		// with the per-order budgets that follow, to outlive the sweep lock's
		// TTL and have the next run start on top of this one.
		$deadline = microtime( true ) + max( 1.0, $budget );

		foreach ( $groups as $group ) {
			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0.0 ) {
				// Out of time. These orders are not covered, so each falls
				// back to its own listing or waits for the next run; nothing
				// is decided from a listing that was never made.
				self::uncover( $group['orders'] );
				$report['truncated'] = true;
				continue;
			}

			$client = new Afriex_Api_Client( $group['environment'], Afriex_Account_Settings::api_key() );

			if ( ! $client->is_configured() ) {
				// Nothing to ask with. The per-order path says so on the order,
				// under its lock, rather than this one saying it here.
				self::uncover( $group['orders'] );
				continue;
			}

			$client->set_time_budget( $remaining );

			++$report['list_calls'];

			$listed = $client->list_transactions(
				array(
					'type'     => self::FILTER_TYPE,
					'channel'  => self::FILTER_CHANNEL,
					'currency' => $group['currency'],
					'fromDate' => gmdate( 'Y-m-d', max( 0, $group['from'] - self::FROM_DATE_SLACK ) ),
				),
				self::MAX_PAGES
			);

			if ( is_wp_error( $listed ) ) {
				Afriex_Logger::info(
					'The Bank Transfer sweep could not list deposits',
					array(
						'environment' => $group['environment'],
						'currency'    => $group['currency'],
						'error'       => $listed->get_error_message(),
					)
				);

				// Not an answer about any of these orders, so none of them is
				// covered: each falls back to its own listing, or is skipped.
				self::uncover( $group['orders'] );

				$report['api_status']      = Afriex_Settlement::error_status( $listed );
				$report['api_retry_after'] = Afriex_Settlement::error_retry_after( $listed );

				// A 429 or a 503 is the whole store being asked to slow down.
				// The run stops on it, so there is no point listing the other
				// currencies first.
				if ( in_array( $report['api_status'], array( 429, 503 ), true ) ) {
					return $report;
				}

				continue;
			}

			self::place_rows( $listed, $group, $report );
		}

		if ( $report['list_calls'] > 0 ) {
			Afriex_Logger::info( 'Bank Transfer sweep listing', $report );
		}

		return $report;
	}

	/**
	 * Sort the run's Bank Transfer orders into one listing per environment and
	 * currency, and build the account-id map each listing is fanned out with.
	 *
	 * @param int[] $order_ids The run's due orders, of both methods.
	 * @param array $report    Running totals, by reference.
	 * @return array Keyed by "{environment}|{currency}".
	 */
	private static function group_orders( array $order_ids, array &$report ): array {
		$groups = array();

		foreach ( $order_ids as $order_id ) {
			$order_id = (int) $order_id;
			$order    = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order || 'afriex' !== $order->get_payment_method() ) {
				continue;
			}

			// An order taken with a key the store no longer holds is left out
			// altogether: asking with a different key would give an answer
			// about a different Afriex account (step C0). The per-order path
			// records that on the order, under its lock.
			if ( ! Afriex_Settlement::order_key_is_available( $order ) ) {
				continue;
			}

			$currency = strtoupper( (string) $order->get_currency() );

			if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				continue;
			}

			$accounts = self::account_ids( $order );

			++$report['orders'];
			self::$covered[ $order_id ] = true;

			if ( empty( $accounts ) ) {
				// No virtual account was ever issued, so no deposit can name
				// this order. Covered, with nothing on it: a listing would tell
				// it nothing it does not already know.
				continue;
			}

			$environment = Afriex_Settlement::order_environment( $order );
			$key         = $environment . '|' . $currency;
			$created     = $order->get_date_created();
			$start       = $created instanceof WC_DateTime ? $created->getTimestamp() : time();

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'environment' => $environment,
					'currency'    => $currency,
					'from'        => $start,
					'accounts'    => array(),
					'orders'      => array(),
				);
			}

			$groups[ $key ]['from']     = min( $groups[ $key ]['from'], $start );
			$groups[ $key ]['orders'][] = $order_id;

			foreach ( $accounts as $account_id ) {
				$groups[ $key ]['accounts'][ $account_id ][ $order_id ] = $order_id;
			}
		}

		return $groups;
	}

	/**
	 * Every Afriex account id a deposit for this order could name: the one it
	 * holds now, and every one it has been given and had replaced (F20).
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private static function account_ids( WC_Order $order ): array {
		$ids     = array();
		$current = Afriex_Order_Meta::get_payment_method_id( $order );

		if ( '' !== $current ) {
			$ids[ $current ] = $current;
		}

		foreach ( Afriex_Order_Meta::get_previous_payment_method_ids( $order ) as $old ) {
			if ( '' !== (string) $old ) {
				$ids[ (string) $old ] = (string) $old;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Fan one listing out to the orders it belongs to.
	 *
	 * @param array $listed list_transactions() result.
	 * @param array $group  One environment-and-currency group.
	 * @param array $report Running totals, by reference.
	 */
	private static function place_rows( array $listed, array $group, array &$report ): void {
		$truncated = ! empty( $listed['truncated'] );

		if ( $truncated ) {
			$report['truncated'] = true;

			foreach ( $group['orders'] as $order_id ) {
				self::$truncated[ $order_id ] = true;
			}
		}

		foreach ( (array) $listed['data'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			++$report['rows'];

			if ( ! self::row_is_a_virtual_account_deposit( $row ) ) {
				// The API answered with something the filters excluded, which
				// means it ignored them. Dropping it here is the whole reason
				// the rows are filtered again locally.
				++$report['foreign'];
				continue;
			}

			$candidates = self::candidates( $row, $group['accounts'] );

			if ( empty( $candidates ) ) {
				// Somebody else's money. Never applied (F2, F26, F28).
				++$report['unmatched'];
				continue;
			}

			if ( count( $candidates ) > 1 ) {
				// One account, two orders. Refused, and both orders are told
				// (F30). The flagging happens under each order's own lock.
				++$report['ambiguous'];

				foreach ( $candidates as $order_id ) {
					foreach ( self::row_account_ids( $row ) as $account_id ) {
						if ( isset( $group['accounts'][ $account_id ] ) ) {
							self::$ambiguous[ $order_id ][ $account_id ] = $account_id;
						}
					}
				}

				continue;
			}

			$order_id = (int) reset( $candidates );

			self::$rows[ $order_id ][] = $row;
			++$report['matched'];
		}
	}

	/**
	 * Which of this run's orders a row could belong to.
	 *
	 * Afriex has not said whether an inbound deposit carries the virtual
	 * account as sourceId or as destinationId (plan 16, question 7), so both
	 * are read — exactly as resolve_order_id() does for a webhook.
	 *
	 * @param array $row      Transaction payload.
	 * @param array $accounts account id => array of order ids.
	 * @return int[]
	 */
	private static function candidates( array $row, array $accounts ): array {
		$found = array();

		foreach ( self::row_account_ids( $row ) as $account_id ) {
			if ( ! isset( $accounts[ $account_id ] ) ) {
				continue;
			}

			foreach ( $accounts[ $account_id ] as $order_id ) {
				$found[ (int) $order_id ] = (int) $order_id;
			}
		}

		return array_values( $found );
	}

	/**
	 * The account ids a transaction names.
	 *
	 * @param array $row Transaction payload.
	 * @return string[]
	 */
	private static function row_account_ids( array $row ): array {
		$ids = array();

		foreach ( array( 'destinationId', 'sourceId' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== (string) $row[ $field ] ) {
				$ids[ (string) $row[ $field ] ] = (string) $row[ $field ];
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a listed row really is a deposit on a virtual account.
	 *
	 * The filters were sent; this is the check that they were honoured. An
	 * absent field passes, because Afriex's own payloads do not always carry
	 * every one and settle() applies its own direction rule (plan 6.3 step 4)
	 * whatever this says. A field that is present and says something else is
	 * the API telling us it ignored the filter.
	 *
	 * The currency filter is deliberately not re-checked here: a deposit has
	 * two currencies and Afriex has not said which one the filter reads. The
	 * money check in the decision table compares both pairs against what the
	 * order was asked to pay, which is the comparison that actually matters.
	 *
	 * @param array $row Transaction payload.
	 */
	private static function row_is_a_virtual_account_deposit( array $row ): bool {
		$type = Afriex_Settlement_Decision::type( $row );

		if ( '' !== $type && self::FILTER_TYPE !== $type ) {
			return false;
		}

		$channel = Afriex_Settlement_Decision::channel( $row );

		return '' === $channel || self::FILTER_CHANNEL === $channel;
	}

	/**
	 * Take a set of orders back out of this run's listing.
	 *
	 * @param int[] $order_ids Orders.
	 */
	private static function uncover( array $order_ids ): void {
		foreach ( $order_ids as $order_id ) {
			unset( self::$covered[ (int) $order_id ], self::$rows[ (int) $order_id ], self::$truncated[ (int) $order_id ] );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading the run's listing.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a run-level listing has been made in this request.
	 */
	public static function is_primed(): bool {
		return self::$primed;
	}

	/**
	 * The rows this run placed on one order, or null when the run's listing
	 * does not cover it and the caller should list for itself.
	 *
	 * An order whose accounts turned out to be ambiguous is deliberately
	 * reported as NOT covered. place_rows() withholds an ambiguous row from
	 * every candidate — it must never be applied to one of them — but the
	 * caller that matters most here is
	 * Afriex_Settlement::collect_transactions(), which reads this as the whole
	 * truth about an order. Handing it an empty array would tell the expire
	 * routine that Afriex holds no transaction at all for an order that has in
	 * fact been paid, and it would cancel it with "no payment arrived". Saying
	 * "not covered" instead sends that caller to list_for_order(), where the
	 * row comes back and settle() refuses it out loud on `account_ambiguous`
	 * (F30).
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function rows_for_order( int $order_id ) {
		if ( empty( self::$covered[ $order_id ] ) ) {
			return null;
		}

		if ( ! empty( self::$ambiguous[ $order_id ] ) ) {
			return null;
		}

		return isset( self::$rows[ $order_id ] ) ? self::$rows[ $order_id ] : array();
	}

	/**
	 * The account ids that named this order and at least one other (F30).
	 *
	 * @param int $order_id Order id.
	 * @return string[]
	 */
	public static function ambiguous_accounts( int $order_id ): array {
		return isset( self::$ambiguous[ $order_id ] ) ? array_values( self::$ambiguous[ $order_id ] ) : array();
	}

	/**
	 * Whether the listing this order's rows came from hit the page ceiling.
	 *
	 * @param int $order_id Order id.
	 */
	public static function was_truncated( int $order_id ): bool {
		return ! empty( self::$truncated[ $order_id ] );
	}

	/**
	 * Forget the run's listing. Called in the run's finally block, so a long
	 * lived process (WP-CLI, a queue runner) never reads one run's rows in the
	 * next run.
	 */
	public static function clear(): void {
		self::$primed    = false;
		self::$covered   = array();
		self::$rows      = array();
		self::$ambiguous = array();
		self::$truncated = array();
	}

	/*
	 * ---------------------------------------------------------------------
	 * One order on its own: check_now(), and anything the run left out.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The same strict listing for a single order.
	 *
	 * Used by "Check Afriex payment now" and by any order the run's listing
	 * could not cover. It sends the same documented filters and does the same
	 * local matching; it simply has one order's accounts in its map instead of
	 * twenty-five.
	 *
	 * @param WC_Order          $order     Order, loaded under its lock.
	 * @param Afriex_Api_Client $client    Client for the order's environment.
	 * @param bool              $truncated Out: whether the listing was cut short.
	 * @return array|WP_Error Rows that belong to this order.
	 */
	public static function list_for_order( WC_Order $order, Afriex_Api_Client $client, &$truncated = false ) {
		$truncated = false;
		$accounts  = self::account_ids( $order );

		if ( empty( $accounts ) ) {
			return array();
		}

		$currency = strtoupper( (string) $order->get_currency() );

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return array();
		}

		$created = $order->get_date_created();
		$start   = $created instanceof WC_DateTime ? $created->getTimestamp() : time();

		$listed = $client->list_transactions(
			array(
				'type'     => self::FILTER_TYPE,
				'channel'  => self::FILTER_CHANNEL,
				'currency' => $currency,
				'fromDate' => gmdate( 'Y-m-d', max( 0, $start - self::FROM_DATE_SLACK ) ),
			),
			self::ORDER_MAX_PAGES
		);

		if ( is_wp_error( $listed ) ) {
			return $listed;
		}

		$truncated = ! empty( $listed['truncated'] );

		$map = array();

		foreach ( $accounts as $account_id ) {
			$map[ $account_id ] = array( $order->get_id() );
		}

		$rows = array();

		foreach ( (array) $listed['data'] as $row ) {
			if ( ! is_array( $row ) || ! self::row_is_a_virtual_account_deposit( $row ) ) {
				continue;
			}

			if ( ! empty( self::candidates( $row, $map ) ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Acting on the rows, or recording what acting would have done.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Apply this order's rows, or record what applying them would have done.
	 *
	 * @param WC_Order $order Order, loaded under its lock.
	 * @param array    $rows  Rows the listing placed on it.
	 * @return string The sweep outcome for this order.
	 */
	public static function apply_rows( WC_Order $order, array $rows ): string {
		if ( empty( $rows ) ) {
			return 'noted';
		}

		if ( ! self::settles_from_listing() ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					self::record_what_would_happen( $order, $row );
				}
			}

			return 'noted';
		}

		$order_id = $order->get_id();
		$outcome  = 'noted';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $row ) ) ) {
				// Nothing to settle. Say what it is, once, exactly as the
				// note-only path would.
				self::note_status( $order, $row );
				continue;
			}

			$result = Afriex_Settlement::settle( $order_id, $row, Afriex_Settlement::SOURCE_SWEEP );

			// Settled, or refused and flagged: either way the order now has its
			// answer and the remaining rows are history.
			if ( in_array( $result['outcome'], array( Afriex_Settlement::OUTCOME_SETTLED, Afriex_Settlement::OUTCOME_FLAGGED ), true ) ) {
				return (string) $result['outcome'];
			}

			$outcome = (string) $result['outcome'];

			// settle() reloaded the order under the lock and saved through its
			// own copy, so the one in hand is stale. A note written from it
			// below would silently discard whatever settle() just recorded —
			// notes, the note-dedup history, the last status. Every other
			// caller reloads here; so does this one.
			Afriex_Settlement::flush_order_cache( $order_id );

			$reloaded = wc_get_order( $order_id );

			if ( ! $reloaded instanceof WC_Order ) {
				return $outcome;
			}

			$order = $reloaded;
		}

		return $outcome;
	}

	/**
	 * Write on the order exactly what settling from the listing would have
	 * done with this deposit, and change nothing.
	 *
	 * The decision comes from Afriex_Settlement::dry_run(), which runs the real
	 * binding rules and the real decision table. The note is deduplicated on
	 * the transaction, its status and the decision, so an unchanged deposit is
	 * mentioned once however many times the sweep sees it (F21, F36) — and a
	 * deposit whose decision changes (the order total was edited, say) is
	 * mentioned again, because that is news.
	 *
	 * @param WC_Order $order Order, loaded under its lock.
	 * @param array    $row   Transaction payload.
	 */
	private static function record_what_would_happen( WC_Order $order, array $row ): void {
		$txn_id = Afriex_Settlement_Decision::transaction_id( $row );
		$status = Afriex_Settlement_Decision::status( $row );

		if ( '' === $txn_id ) {
			return;
		}

		if ( ! Afriex_Status_Mapper::is_success( $status ) ) {
			self::note_status( $order, $row );
			return;
		}

		$dry     = Afriex_Settlement::dry_run( $order, $row );
		$amounts = Afriex_Settlement_Decision::amounts( $row );

		Afriex_Logger::info(
			'Bank Transfer sweep: a deposit it would not apply',
			array(
				'order_id'       => $order->get_id(),
				'transaction_id' => $txn_id,
				'status'         => $status,
				'bound'          => $dry['bound'],
				'decision'       => $dry['decision'],
				'reason'         => $dry['reason'],
				'destination'    => (string) $amounts['destination_amount'] . ' ' . $amounts['destination_currency'],
				'source'         => (string) $amounts['source_amount'] . ' ' . $amounts['source_currency'],
			)
		);

		$money = trim( (string) $amounts['destination_amount'] . ' ' . $amounts['destination_currency'] );

		if ( '' === $money ) {
			$money = trim( (string) $amounts['source_amount'] . ' ' . $amounts['source_currency'] );
		}

		Afriex_Order_Meta::note_once(
			$order,
			'sweep_would:' . $txn_id . ':' . $status . ':' . ( '' !== $dry['decision'] ? $dry['decision'] : $dry['reason'] ),
			sprintf(
				/* translators: 1: Afriex transaction id, 2: amount and currency, 3: what would have happened */
				__( 'The scheduled Afriex check found a successful deposit (%1$s, %2$s) against this order\'s bank account. Bank transfers are not confirmed from this check, so nothing was changed: %3$s The payment is confirmed by Afriex\'s webhook, or when the payment window closes.', 'afriex-gateway-for-woocommerce' ),
				$txn_id,
				'' !== $money ? $money : __( 'amount not reported', 'afriex-gateway-for-woocommerce' ),
				self::would_have( $dry )
			)
		);
	}

	/**
	 * One sentence saying what the decision table would have done.
	 *
	 * @param array $dry Afriex_Settlement::dry_run() result.
	 */
	private static function would_have( array $dry ): string {
		if ( ! $dry['bound'] ) {
			/* translators: %s: internal reason code */
			return sprintf( __( 'it would not have been applied to this order (%s).', 'afriex-gateway-for-woocommerce' ), $dry['reason'] );
		}

		switch ( $dry['decision'] ) {
			case Afriex_Settlement_Decision::COMPLETE:
				return __( 'it would have marked this order as paid.', 'afriex-gateway-for-woocommerce' );

			case Afriex_Settlement_Decision::AMOUNT_MISMATCH:
				return __( 'it would not have marked this order as paid, because the amount or currency does not match what the account was issued for; the order would have been marked for review.', 'afriex-gateway-for-woocommerce' );

			case Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH:
				return __( 'it would not have marked this order as paid, because the amount paid no longer matches the order total; the order would have been marked for review.', 'afriex-gateway-for-woocommerce' );

			case Afriex_Settlement_Decision::SECOND_PAYMENT:
				return __( 'it would have reported a second payment on an order that is already paid.', 'afriex-gateway-for-woocommerce' );

			case Afriex_Settlement_Decision::NOT_REVIVED:
				return __( 'it would have reported a payment arriving after this order closed, without reopening it.', 'afriex-gateway-for-woocommerce' );

			case Afriex_Settlement_Decision::NOT_DEPOSIT:
				return __( 'it would have recorded the transaction only, because it does not move money into this store.', 'afriex-gateway-for-woocommerce' );

			default:
				/* translators: %s: internal decision code */
				return sprintf( __( 'it would have left the order as it is (%s).', 'afriex-gateway-for-woocommerce' ), '' !== $dry['decision'] ? $dry['decision'] : $dry['reason'] );
		}
	}

	/**
	 * Say what Afriex reports about a transaction that has not succeeded.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $row   Transaction payload.
	 */
	private static function note_status( WC_Order $order, array $row ): void {
		$txn_id = Afriex_Settlement_Decision::transaction_id( $row );
		$status = Afriex_Settlement_Decision::status( $row );

		if ( '' === $txn_id ) {
			return;
		}

		Afriex_Order_Meta::note_once(
			$order,
			'sweep_seen:' . $txn_id . ':' . $status,
			sprintf(
				/* translators: 1: Afriex transaction id, 2: Afriex status */
				__( 'Afriex reports transfer %1$s as %2$s.', 'afriex-gateway-for-woocommerce' ),
				$txn_id,
				$status
			)
		);
	}

	/**
	 * Tell an order that one of its Afriex accounts names another order too.
	 *
	 * Nothing is settled from a deposit into it, by this run or any other, and
	 * a person is asked to look. This is the run-level half of F30; the same
	 * refusal exists in resolve_order_id() for a webhook and in
	 * bind_bank_transfer() at settlement time.
	 *
	 * @param WC_Order $order    Order, loaded under its lock.
	 * @param string[] $accounts The account ids that named more than one order.
	 */
	public static function report_ambiguous( WC_Order $order, array $accounts ): void {
		if ( empty( $accounts ) ) {
			return;
		}

		sort( $accounts );

		Afriex_Order_Meta::flag_for_review( $order, 'account_ambiguous' );

		$noted = Afriex_Order_Meta::note_once(
			$order,
			'sweep_account_ambiguous:' . md5( implode( ',', $accounts ) ),
			sprintf(
				/* translators: %s: comma-separated Afriex account ids */
				__( 'The scheduled Afriex check found a payment into an Afriex account that is recorded against more than one order (%s). No payment has been applied to this order, because applying it to the wrong one would be worse than applying it to none. Check the Afriex dashboard and this order by hand.', 'afriex-gateway-for-woocommerce' ),
				implode( ', ', $accounts )
			)
		);

		if ( $noted ) {
			Afriex_Settlement::queue_review_notice( $order, 'account_ambiguous' );
		}

		Afriex_Logger::warning(
			'An Afriex virtual account is recorded against more than one order',
			array(
				'order_id' => $order->get_id(),
				'accounts' => $accounts,
			)
		);
	}
}
