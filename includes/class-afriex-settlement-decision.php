<?php
/**
 * The settlement decision table, with no WordPress in it.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What should happen to an order, given a transaction: decided from plain
 * arrays, so the rules can be tested exhaustively.
 *
 * Afriex_Settlement does the parts that need WordPress — the lock, the order
 * lookup, the notes, the ledger claim, payment_complete() — and asks this
 * class what to do. Nothing here reads or writes anything: no order, no
 * option, no database, not even a translation, because __() would drag in
 * WordPress and with it the reason this file exists.
 *
 * The steps below are plan 6.3 in its own order. The order matters: the
 * already-settled branch (step 5) is what stops a replayed webhook from
 * walking a paid order backwards, and the ledger claim (step 8) sits between
 * the money check and the eligibility check, which is why decide() reports
 * whether a claim is owed rather than assuming one has been taken.
 */
class Afriex_Settlement_Decision {

	/**
	 * The order is not an Afriex order at all (step 2).
	 */
	public const NOT_AFRIEX = 'not_afriex';

	/**
	 * The transaction moves money the wrong way (step 4, F3).
	 */
	public const NOT_DEPOSIT = 'not_deposit';

	/**
	 * A second, different transaction succeeded against an order that is
	 * already paid: the shopper has probably paid twice (step 5, F18).
	 */
	public const SECOND_PAYMENT = 'second_payment';

	/**
	 * A refund or a dispute on a paid order (step 5, F35).
	 */
	public const POST_PAYMENT_REVIEW = 'post_payment_review';

	/**
	 * A dispute that ended: a note is enough (step 5).
	 */
	public const POST_PAYMENT_NOTE = 'post_payment_note';

	/**
	 * Anything else arriving for an order that is already settled. Never a
	 * status change, whatever the merchant has done to the order since (F87).
	 */
	public const ALREADY_SETTLED_NOTE = 'already_settled_note';

	/**
	 * Afriex says this payment will not happen, and it is this order's current
	 * payment attempt (step 6).
	 */
	public const FAIL_ORDER = 'fail_order';

	/**
	 * A failure from an attempt this order has moved on from (F19, F20).
	 */
	public const STALE_FAILURE = 'stale_failure';

	/**
	 * Still in flight, or a status nobody here recognises (step 6).
	 */
	public const NOTE_STATUS = 'note_status';

	/**
	 * A refund or dispute on a transaction that never settled this order.
	 */
	public const UNSETTLED_POST_PAYMENT = 'unsettled_post_payment';

	/**
	 * The money does not match what was asked for (step 7, F31).
	 */
	public const AMOUNT_MISMATCH = 'amount_mismatch';

	/**
	 * The payment matches the session it was made on, but that session is no
	 * longer what the order costs (step 7, the amount guard).
	 *
	 * Expectations are stored per reference so that a late payment on an
	 * older session still settles (F29). That is right while the price has
	 * not moved. When it has — an admin edited the total, or block checkout
	 * recalculated a draft order from a basket changed in another tab — a
	 * payment on the older, cheaper session would otherwise complete an order
	 * that now costs more, in full and with nothing said.
	 */
	public const ORDER_TOTAL_MISMATCH = 'order_total_mismatch';

	/**
	 * Complete the order (steps 9 and 10).
	 */
	public const COMPLETE = 'complete';

	/**
	 * Paid after cancellation: flagged, never revived (step 9, F14).
	 */
	public const NOT_REVIVED = 'not_revived';

	/**
	 * A successful payment against an order in a status that cannot take it.
	 */
	public const INELIGIBLE = 'ineligible';

	/**
	 * Order statuses a successful payment may complete (step 9). A failed
	 * order joins them only when the plugin is the one that failed it.
	 */
	public const ELIGIBLE_STATUSES = array( 'pending', 'on-hold' );

	/**
	 * Statuses that mean a person decided this order was over. A payment that
	 * arrives afterwards is reported, never applied.
	 */
	public const CLOSED_STATUSES = array( 'cancelled', 'refunded' );

	/**
	 * Order statuses a terminal failure may move to failed (step 6).
	 */
	public const FAILABLE_STATUSES = array( 'pending', 'on-hold' );

	/**
	 * Decide what to do with one transaction against one order.
	 *
	 * @param array $input {
	 *     Everything the decision depends on, already read from the order.
	 *
	 *     @type string   $payment_method       The order's payment method id.
	 *     @type string[] $afriex_methods       Payment method ids that are ours.
	 *     @type string   $order_status         Order status, no "wc-" prefix.
	 *     @type bool     $already_settled      Paid, or carrying a settled
	 *                                          transaction id, or holding this
	 *                                          transaction's ledger row.
	 *     @type string   $settled_txn          The transaction that settled it.
	 *     @type string   $failed_by_plugin     Non-empty when the plugin failed it.
	 *     @type string   $txn_id               Transaction id.
	 *     @type string   $txn_status           Afriex transaction status.
	 *     @type string   $txn_type             Transaction type, '' when absent.
	 *     @type bool     $is_current_attempt   Whether the transaction belongs to
	 *                                          the order's current reference or
	 *                                          account.
	 *     @type int|null $expected_minor       Expected amount in minor units.
	 *     @type string   $expected_currency    Expected ISO 4217 code.
	 *     @type int|null $order_total_minor    What the order costs now, in
	 *                                          minor units, or null when the
	 *                                          total cannot be read.
	 *     @type string   $order_currency       The order's current currency.
	 *     @type mixed    $destination_amount   destinationAmount.
	 *     @type string   $destination_currency destinationCurrency.
	 *     @type mixed    $source_amount        sourceAmount.
	 *     @type string   $source_currency      sourceCurrency.
	 * }
	 * @return array {
	 *     @type string $decision One of the constants above.
	 *     @type bool   $claim    Whether the settled:{txn} ledger row must be
	 *                            claimed before acting (plan 6.3 step 8).
	 *     @type string $reason   A short code for the log and the note.
	 *     @type string $status   The normalised Afriex status.
	 *     @type array  $money    The money check, when one was made.
	 * }
	 */
	public static function decide( array $input ): array {
		$input  = self::defaults( $input );
		$status = Afriex_Status_Mapper::normalise( $input['txn_status'] );

		// Step 2: ownership.
		if ( ! in_array( $input['payment_method'], $input['afriex_methods'], true ) ) {
			return self::result( self::NOT_AFRIEX, false, 'payment_method:' . $input['payment_method'], $status );
		}

		// Step 4: direction. An absent type is allowed, because Afriex has not
		// said what type a checkout deposit carries (plan 16, question 2);
		// anything present and not DEPOSIT is refused outright.
		if ( '' !== $input['txn_type'] && 'DEPOSIT' !== strtoupper( $input['txn_type'] ) ) {
			return self::result( self::NOT_DEPOSIT, false, 'type:' . $input['txn_type'], $status );
		}

		// Step 5: already settled. No status change from here on, ever.
		if ( $input['already_settled'] ) {
			return self::decide_settled( $input, $status );
		}

		// Step 6: status.
		$class = Afriex_Status_Mapper::classify( $status );

		if ( Afriex_Status_Mapper::CLASS_TERMINAL_FAILURE === $class ) {
			$failable = in_array( $input['order_status'], self::FAILABLE_STATUSES, true );

			if ( $failable && $input['is_current_attempt'] ) {
				return self::result( self::FAIL_ORDER, false, 'status:' . $status, $status );
			}

			return self::result(
				self::STALE_FAILURE,
				false,
				$failable ? 'not_current_attempt' : 'order_status:' . $input['order_status'],
				$status
			);
		}

		if ( Afriex_Status_Mapper::CLASS_POST_PAYMENT === $class ) {
			// A refund or dispute on a transaction that never paid for this
			// order. Nothing to undo, but money has moved somewhere and the
			// order is still unpaid, so a person is told.
			return self::result( self::UNSETTLED_POST_PAYMENT, false, 'status:' . $status, $status );
		}

		if ( Afriex_Status_Mapper::CLASS_SUCCESS !== $class ) {
			return self::result(
				self::NOTE_STATUS,
				false,
				Afriex_Status_Mapper::is_known( $status ) ? 'in_flight' : 'unrecognised',
				$status
			);
		}

		// Step 7: money.
		$money = self::money( $input );

		if ( ! $money['ok'] ) {
			return self::result( self::AMOUNT_MISMATCH, false, $money['reason'], $status, $money );
		}

		// Step 7, second half: the session's expectation has to still be what
		// the order costs. Without this a payment made on an older, cheaper
		// session completes an order whose total has risen since.
		$guard = self::order_total( $input );

		if ( ! $guard['ok'] ) {
			return self::result( self::ORDER_TOTAL_MISMATCH, false, $guard['reason'], $status, array_merge( $money, $guard ) );
		}

		// Steps 8 and 9: claim the transaction, then check the order can take it.
		if ( in_array( $input['order_status'], self::CLOSED_STATUSES, true ) ) {
			return self::result( self::NOT_REVIVED, true, 'order_status:' . $input['order_status'], $status, $money );
		}

		$eligible = in_array( $input['order_status'], self::ELIGIBLE_STATUSES, true )
			|| ( 'failed' === $input['order_status'] && '' !== $input['failed_by_plugin'] );

		if ( ! $eligible ) {
			return self::result( self::INELIGIBLE, true, 'order_status:' . $input['order_status'], $status, $money );
		}

		return self::result( self::COMPLETE, true, 'ok', $status, $money );
	}

	/**
	 * Step 5 in full: what a transaction means for an order that is settled.
	 *
	 * @param array  $input  Normalised input.
	 * @param string $status Normalised Afriex status.
	 */
	private static function decide_settled( array $input, string $status ): array {
		$same_transaction = '' !== $input['settled_txn'] && $input['settled_txn'] === $input['txn_id'];

		// A different transaction succeeded against an order that is already
		// paid: two payments for one order, which only a person can unpick.
		if ( ! $same_transaction && Afriex_Status_Mapper::is_success( $status ) ) {
			return self::result( self::SECOND_PAYMENT, false, 'settled_txn:' . $input['settled_txn'], $status );
		}

		if ( Afriex_Status_Mapper::needs_review( $status ) ) {
			return self::result( self::POST_PAYMENT_REVIEW, false, 'status:' . $status, $status );
		}

		if ( Afriex_Status_Mapper::is_dispute_resolution( $status ) ) {
			return self::result( self::POST_PAYMENT_NOTE, false, 'status:' . $status, $status );
		}

		return self::result(
			self::ALREADY_SETTLED_NOTE,
			false,
			$same_transaction ? 'same_transaction' : 'other_transaction',
			$status
		);
	}

	/**
	 * Does the money match? Step 7.
	 *
	 * Either pair may match: Afriex has not said which of sourceAmount and
	 * destinationAmount equals the session amount for a checkout deposit, so
	 * until it does (plan 16, question 1) a match on either is accepted and
	 * both are reported. Comparison is between integers in minor units, never
	 * between floats.
	 *
	 * @param array $input Input as described on decide().
	 * @return array ok, matched, reason, and both amounts in minor units.
	 */
	public static function money( array $input ): array {
		$input    = self::defaults( $input );
		$expected = $input['expected_minor'];
		$currency = strtoupper( trim( $input['expected_currency'] ) );

		$destination_currency = strtoupper( trim( (string) $input['destination_currency'] ) );
		$source_currency      = strtoupper( trim( (string) $input['source_currency'] ) );

		$result = array(
			'ok'                   => false,
			'matched'              => '',
			'reason'               => '',
			'expected_minor'       => $expected,
			'expected_currency'    => $currency,
			'destination_minor'    => '' === $destination_currency ? null : Afriex_Money::to_minor( $input['destination_amount'], $destination_currency ),
			'destination_currency' => $destination_currency,
			'source_minor'         => '' === $source_currency ? null : Afriex_Money::to_minor( $input['source_amount'], $source_currency ),
			'source_currency'      => $source_currency,
		);

		// No expectation at all. The caller falls back to the order total
		// before calling, so reaching here means even that was unreadable.
		if ( null === $expected || '' === $currency ) {
			$result['reason'] = 'no_expectation';
			return $result;
		}

		if ( Afriex_Money::matches( $input['destination_amount'], $destination_currency, $expected, $currency ) ) {
			$result['ok']      = true;
			$result['matched'] = 'destination';
			return $result;
		}

		if ( Afriex_Money::matches( $input['source_amount'], $source_currency, $expected, $currency ) ) {
			$result['ok']      = true;
			$result['matched'] = 'source';
			return $result;
		}

		if ( $currency !== $destination_currency && $currency !== $source_currency ) {
			$result['reason'] = 'currency_mismatch';
			return $result;
		}

		if ( null === $result['destination_minor'] && null === $result['source_minor'] ) {
			$result['reason'] = 'amount_unreadable';
			return $result;
		}

		$result['reason'] = 'amount_mismatch';

		return $result;
	}

	/**
	 * The amount guard: does the session still match what the order costs?
	 *
	 * The money() check above answers "did the shopper pay what this session
	 * asked for?". It has to be asked per session, because a late payment on an
	 * older session is a real payment and must still settle (F29). It is not
	 * the whole question. A session is only evidence that the order is paid
	 * while the price it was issued for is still the price: an order placed
	 * at 50.00 and later edited to 90.00 is not paid by a 50.00 session,
	 * however well that session's own arithmetic adds up.
	 *
	 * So a payment completes an order only when the session's expected amount
	 * and currency also equal the order's current total and currency. Both
	 * directions matter: a total raised after the session was issued would
	 * otherwise be settled short, and a total lowered would be settled over.
	 *
	 * A total that cannot be read is not a mismatch. It is judged nowhere
	 * else either, and treating it as one would strand every payment on a
	 * store whose currency this plugin cannot express in minor units.
	 *
	 * @param array $input Input as described on decide().
	 * @return array ok, reason, and the order's total and currency.
	 */
	public static function order_total( array $input ): array {
		$input    = self::defaults( $input );
		$expected = $input['expected_minor'];
		$currency = strtoupper( trim( $input['expected_currency'] ) );

		$order_minor    = $input['order_total_minor'];
		$order_currency = strtoupper( trim( $input['order_currency'] ) );

		$result = array(
			'ok'                => true,
			'reason'            => '',
			'order_total_minor' => $order_minor,
			'order_currency'    => $order_currency,
		);

		if ( null === $order_minor || '' === $order_currency || null === $expected || '' === $currency ) {
			$result['reason'] = 'order_total_unknown';
			return $result;
		}

		if ( $currency !== $order_currency ) {
			$result['ok']     = false;
			$result['reason'] = 'order_currency_mismatch';
			return $result;
		}

		if ( (int) $expected !== (int) $order_minor ) {
			$result['ok']     = false;
			$result['reason'] = 'order_total_mismatch';
		}

		return $result;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading an Afriex transaction. Field names differ between the webhook
	 * payload and the API response, and settlement must not care which one it
	 * was handed.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The transaction id.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function transaction_id( array $transaction ): string {
		foreach ( array( 'transactionId', 'id', '_id' ) as $key ) {
			if ( isset( $transaction[ $key ] ) && is_scalar( $transaction[ $key ] ) && '' !== (string) $transaction[ $key ] ) {
				return (string) $transaction[ $key ];
			}
		}

		return '';
	}

	/**
	 * The merchant reference, wherever this payload carries it.
	 *
	 * Transactions carry it as merchantReference and again as meta.reference;
	 * 1.0's pool payloads had a bare reference. All three are read, the
	 * documented ones first.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function reference( array $transaction ): string {
		if ( isset( $transaction['merchantReference'] ) && is_scalar( $transaction['merchantReference'] ) ) {
			$reference = trim( (string) $transaction['merchantReference'] );

			if ( '' !== $reference ) {
				return $reference;
			}
		}

		if ( isset( $transaction['meta']['reference'] ) && is_scalar( $transaction['meta']['reference'] ) ) {
			$reference = trim( (string) $transaction['meta']['reference'] );

			if ( '' !== $reference ) {
				return $reference;
			}
		}

		if ( isset( $transaction['reference'] ) && is_scalar( $transaction['reference'] ) ) {
			return trim( (string) $transaction['reference'] );
		}

		return '';
	}

	/**
	 * The Afriex status, upper-cased, or 'UNKNOWN' when the payload has none.
	 *
	 * A missing status is Afriex's own word for "no answer yet", which is
	 * non-terminal, so a payload without one changes nothing.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function status( array $transaction ): string {
		$status = isset( $transaction['status'] ) && is_scalar( $transaction['status'] )
			? Afriex_Status_Mapper::normalise( (string) $transaction['status'] )
			: '';

		return '' === $status ? 'UNKNOWN' : $status;
	}

	/**
	 * The transaction type, or '' when the payload has none.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function type( array $transaction ): string {
		return isset( $transaction['type'] ) && is_scalar( $transaction['type'] )
			? strtoupper( trim( (string) $transaction['type'] ) )
			: '';
	}

	/**
	 * The channel the money came in on, e.g. CARD, or ''.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function channel( array $transaction ): string {
		foreach ( array( 'channel', 'paymentChannel' ) as $key ) {
			if ( isset( $transaction[ $key ] ) && is_scalar( $transaction[ $key ] ) && '' !== (string) $transaction[ $key ] ) {
				return strtoupper( trim( (string) $transaction[ $key ] ) );
			}
		}

		return '';
	}

	/**
	 * Both amount pairs and the fee, as they arrived.
	 *
	 * The destinationAmount and destinationCurrency pair falls back to amount
	 * and currency, which is what a 1.0-shaped payload carries.
	 *
	 * @param array $transaction Transaction payload.
	 * @return array
	 */
	public static function amounts( array $transaction ): array {
		return array(
			'destination_amount'   => self::scalar( $transaction, array( 'destinationAmount', 'amount' ) ),
			'destination_currency' => (string) self::scalar( $transaction, array( 'destinationCurrency', 'currency' ) ),
			'source_amount'        => self::scalar( $transaction, array( 'sourceAmount' ) ),
			'source_currency'      => (string) self::scalar( $transaction, array( 'sourceCurrency' ) ),
			'fee'                  => self::scalar( $transaction, array( 'fee' ) ),
			'fee_currency'         => (string) self::scalar( $transaction, array( 'feeCurrency', 'sourceCurrency' ) ),
		);
	}

	/**
	 * Afriex's reason for a failure, when it gave one.
	 *
	 * @param array $transaction Transaction payload.
	 */
	public static function failure_reason( array $transaction ): string {
		if ( isset( $transaction['meta']['failureReason']['message'] ) && is_scalar( $transaction['meta']['failureReason']['message'] ) ) {
			return trim( (string) $transaction['meta']['failureReason']['message'] );
		}

		return '';
	}

	/**
	 * First present scalar among a list of keys, or '' .
	 *
	 * @param array    $transaction Payload.
	 * @param string[] $keys        Keys in preference order.
	 * @return mixed
	 */
	private static function scalar( array $transaction, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $transaction[ $key ] ) && is_scalar( $transaction[ $key ] ) && '' !== (string) $transaction[ $key ] ) {
				return $transaction[ $key ];
			}
		}

		return '';
	}

	/**
	 * Fill in everything decide() reads, so a caller cannot change the answer
	 * by leaving a key out.
	 *
	 * @param array $input Raw input.
	 */
	private static function defaults( array $input ): array {
		return array_merge(
			array(
				'payment_method'       => '',
				'afriex_methods'       => array( 'afriex', 'afriex_checkout' ),
				'order_status'         => '',
				'already_settled'      => false,
				'settled_txn'          => '',
				'failed_by_plugin'     => '',
				'txn_id'               => '',
				'txn_status'           => '',
				'txn_type'             => '',
				'is_current_attempt'   => false,
				'expected_minor'       => null,
				'expected_currency'    => '',
				'order_total_minor'    => null,
				'order_currency'       => '',
				'destination_amount'   => '',
				'destination_currency' => '',
				'source_amount'        => '',
				'source_currency'      => '',
			),
			$input
		);
	}

	/**
	 * Shape one decision.
	 *
	 * @param string     $decision Decision code.
	 * @param bool       $claim    Whether the ledger claim is owed first.
	 * @param string     $reason   Short reason code.
	 * @param string     $status   Normalised Afriex status.
	 * @param array|null $money    Money check, when one was made.
	 */
	private static function result( string $decision, bool $claim, string $reason, string $status, ?array $money = null ): array {
		return array(
			'decision' => $decision,
			'claim'    => $claim,
			'reason'   => $reason,
			'status'   => $status,
			'money'    => $money,
		);
	}
}
