<?php
/**
 * The Afriex transaction status enum, and what each status means here.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Afriex's published transaction statuses, sorted into the four classes the
 * settlement engine reasons about (plan section 3).
 *
 * The lists are the enum from Afriex's Models reference, nothing more. Two
 * statuses that version 1.0 handled are deliberately absent: COMPLETED and
 * CANCELED (one L). Neither is in Afriex's enum, so 1.0's handling of them was
 * handling of a status that never arrives, while the status that does arrive,
 * CANCELLED, fell through to "unrecognised".
 *
 * An unrecognised status is treated as NON-TERMINAL, not as a failure and not
 * as a success. Afriex can add a status at any time, and the only safe reading
 * of a word this plugin has never seen is "the payment has not finished yet":
 * it leaves the order where it is, adds a note, and lets the sweep look again.
 */
class Afriex_Status_Mapper {

	/**
	 * The payment succeeded and the order may complete.
	 */
	public const CLASS_SUCCESS = 'success';

	/**
	 * The payment will not happen. Afriex never moves away from these.
	 */
	public const CLASS_TERMINAL_FAILURE = 'terminal_failure';

	/**
	 * Still in flight, or unrecognised. Note it and look again later.
	 */
	public const CLASS_NON_TERMINAL = 'non_terminal';

	/**
	 * Something happened to money that has already moved: a refund or a
	 * dispute. Never changes the order status automatically (F35).
	 */
	public const CLASS_POST_PAYMENT = 'post_payment';

	/**
	 * The one status that settles an order.
	 */
	public const SUCCESS = 'SUCCESS';

	/**
	 * Terminal failures.
	 */
	public const TERMINAL_FAILURES = array( 'FAILED', 'REJECTED', 'CANCELLED' );

	/**
	 * In-flight statuses. UNKNOWN is Afriex's own "we cannot say yet", so it
	 * belongs here rather than with the failures.
	 */
	public const NON_TERMINAL = array(
		'PENDING',
		'PROCESSING',
		'RETRY',
		'SCHEDULED',
		'UNKNOWN',
		'IN_REVIEW',
		'CUSTOMER_ACTION_REQUIRED',
		'CHECKER_APPROVAL_REQUIRED',
		'ENGINEERING_REVIEW_REQUIRED',
		'RFI_REQUESTED',
	);

	/**
	 * Statuses a transaction reaches only after it has been paid.
	 */
	public const POST_PAYMENT = array(
		'REFUNDED',
		'DISPUTED',
		'DISPUTE_EVIDENCE_SUBMITTED',
		'DISPUTE_WON',
		'DISPUTE_LOST',
		'DISPUTE_RESOLVED',
	);

	/**
	 * The post-payment statuses that mean the store may be about to lose the
	 * money: a person has to look, and is emailed (plan 6.3 step 5).
	 */
	public const POST_PAYMENT_REVIEW = array(
		'REFUNDED',
		'DISPUTED',
		'DISPUTE_EVIDENCE_SUBMITTED',
		'DISPUTE_LOST',
	);

	/**
	 * The post-payment statuses that are good news, or at least neutral: a
	 * note is enough.
	 */
	public const POST_PAYMENT_RESOLVED = array(
		'DISPUTE_WON',
		'DISPUTE_RESOLVED',
	);

	/*
	 * ---------------------------------------------------------------------
	 * Classification.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Upper-case, trimmed form of a status as Afriex writes it.
	 *
	 * @param string $status Status from a webhook or an API response.
	 */
	public static function normalise( string $status ): string {
		return strtoupper( trim( $status ) );
	}

	/**
	 * Which of the four classes a status belongs to.
	 *
	 * @param string $status Afriex transaction status.
	 * @return string One of the CLASS_* constants.
	 */
	public static function classify( string $status ): string {
		$status = self::normalise( $status );

		if ( self::SUCCESS === $status ) {
			return self::CLASS_SUCCESS;
		}

		if ( in_array( $status, self::TERMINAL_FAILURES, true ) ) {
			return self::CLASS_TERMINAL_FAILURE;
		}

		if ( in_array( $status, self::POST_PAYMENT, true ) ) {
			return self::CLASS_POST_PAYMENT;
		}

		// Both the documented in-flight statuses and anything unrecognised.
		return self::CLASS_NON_TERMINAL;
	}

	/**
	 * Whether this is a status Afriex has published.
	 *
	 * Used to tell "in flight" from "we have never heard of this", which read
	 * the same to the settlement engine but not to a person reading the note.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_known( string $status ): bool {
		$status = self::normalise( $status );

		return self::SUCCESS === $status
			|| in_array( $status, self::TERMINAL_FAILURES, true )
			|| in_array( $status, self::NON_TERMINAL, true )
			|| in_array( $status, self::POST_PAYMENT, true );
	}

	/**
	 * Whether the payment succeeded.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_success( string $status ): bool {
		return self::CLASS_SUCCESS === self::classify( $status );
	}

	/**
	 * Whether the payment will never arrive.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_terminal_failure( string $status ): bool {
		return self::CLASS_TERMINAL_FAILURE === self::classify( $status );
	}

	/**
	 * Whether the payment is still in flight, as far as anyone here knows.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_non_terminal( string $status ): bool {
		return self::CLASS_NON_TERMINAL === self::classify( $status );
	}

	/**
	 * Whether this describes money that has already moved.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_post_payment( string $status ): bool {
		return self::CLASS_POST_PAYMENT === self::classify( $status );
	}

	/**
	 * A post-payment status that needs a person and an email.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function needs_review( string $status ): bool {
		return in_array( self::normalise( $status ), self::POST_PAYMENT_REVIEW, true );
	}

	/**
	 * A dispute that ended in the store's favour, or simply ended.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_dispute_resolution( string $status ): bool {
		return in_array( self::normalise( $status ), self::POST_PAYMENT_RESOLVED, true );
	}

	/**
	 * Whether Afriex has finished deciding this payment, one way or the other.
	 *
	 * Post-payment statuses are not terminal in this sense: they describe what
	 * happened after a payment that had already succeeded, and the expire
	 * routine must not read one as "this payment failed" and cancel an order.
	 *
	 * @param string $status Afriex transaction status.
	 */
	public static function is_terminal( string $status ): bool {
		$class = self::classify( $status );

		return self::CLASS_SUCCESS === $class || self::CLASS_TERMINAL_FAILURE === $class;
	}

	/**
	 * Every status this class knows, for tests and for admin copy.
	 *
	 * @return string[]
	 */
	public static function all_statuses(): array {
		return array_merge(
			array( self::SUCCESS ),
			self::TERMINAL_FAILURES,
			self::NON_TERMINAL,
			self::POST_PAYMENT
		);
	}
}
