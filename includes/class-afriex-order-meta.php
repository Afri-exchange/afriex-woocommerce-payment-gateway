<?php
/**
 * HPOS-safe order meta read/write helpers.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single place in the plugin that touches order meta.
 *
 * High-Performance Order Storage moves orders out of wp_posts/wp_postmeta into
 * dedicated tables. get_post_meta()/update_post_meta() on an order id is not
 * HPOS compatible, is flagged in the WooCommerce admin, and blocks merchants
 * from enabling HPOS at all. Everything here goes through the CRUD API instead,
 * so that discipline only has to hold in one file.
 */
class Afriex_Order_Meta {

	public const KEY_PAYMENT_METHOD_ID = '_afriex_payment_method_id';
	public const KEY_CUSTOMER_ID       = '_afriex_customer_id';
	public const KEY_ACCOUNT_NUMBER    = '_afriex_account_number';
	public const KEY_ACCOUNT_NAME      = '_afriex_account_name';
	public const KEY_BANK_NAME         = '_afriex_bank_name';
	public const KEY_REFERENCE         = '_afriex_reference';
	public const KEY_TRANSACTION_ID    = '_afriex_transaction_id';
	public const KEY_LAST_STATUS       = '_afriex_last_status';
	public const KEY_EXPIRES_AT        = '_afriex_expires_at';
	public const KEY_COLLECTION_METHOD = '_afriex_collection_method';

	/**
	 * Every virtual account this order has been given and then replaced.
	 *
	 * One row per superseded id, so a look-up is an exact meta match like
	 * every other one in this file. A shopper who was shown one account, went
	 * away and came back to a re-issued one can still transfer into the first:
	 * by the time the plugin decides the account is stale, the money may
	 * already have left. Without this key that deposit matches no order at all
	 * and is dropped as another merchant's money (F20).
	 */
	public const KEY_PREVIOUS_PAYMENT_METHOD_ID = '_afriex_previous_payment_method_id';

	// 1.1 keys (plan section 11).
	public const KEY_ENVIRONMENT           = '_afriex_environment';
	public const KEY_KEY_FINGERPRINT       = '_afriex_key_fingerprint';
	public const KEY_EXPECTED_AMOUNT_MINOR = '_afriex_expected_amount_minor';
	public const KEY_EXPECTED_CURRENCY     = '_afriex_expected_currency';

	/**
	 * One row per Checkout session, holding that session's JSON record:
	 * ref, amount_minor, currency, environment, created, session_id,
	 * expires_at. Expectations are stored per reference, so a payment made on
	 * an older session is judged against what that session asked for (F29).
	 */
	public const KEY_CHECKOUT_REFERENCE = '_afriex_checkout_reference';

	/**
	 * One row per Checkout session holding the bare reference string.
	 *
	 * The reference row above is JSON, which no meta query can match exactly,
	 * so the plain value is written alongside it purely to be looked up
	 * (plan 6.2). The two keys are always written and removed together.
	 */
	public const KEY_CHECKOUT_REF_INDEX = '_afriex_checkout_ref_index';

	public const KEY_CHECKOUT_CURRENT_REFERENCE = '_afriex_checkout_current_reference';
	public const KEY_CHECKOUT_URL               = '_afriex_checkout_url';

	/**
	 * The transaction that actually paid for this order, and the channel it
	 * came in on.
	 *
	 * KEY_SETTLED_TRANSACTION_ID means "this order is paid, by this
	 * transaction", and nothing else may write it: every path that decides an
	 * order is already settled reads it, so a value written on the way into
	 * payment_complete() would make a failed completion permanent.
	 */
	public const KEY_SETTLED_TRANSACTION_ID = '_afriex_settled_transaction_id';
	public const KEY_CHANNEL                = '_afriex_channel';

	/**
	 * The transaction a settlement was part-way through applying.
	 *
	 * Written before payment_complete() and removed after it, so a crash or a
	 * refusal in between leaves evidence of which payment was being applied
	 * (F87) without any path reading the order as paid. An order carrying
	 * this and not the settled key was interrupted mid-settlement and is
	 * retried, against the same order and the same transaction.
	 */
	public const KEY_SETTLING_TRANSACTION_ID = '_afriex_settling_transaction_id';

	/**
	 * Set when the plugin, not a person, moved the order to failed. Only such
	 * an order may later be completed by a payment, and only the current
	 * reference or account may fail an order in the first place (F19, F20).
	 */
	public const KEY_FAILED_BY_PLUGIN = '_afriex_failed_by_plugin';

	/**
	 * Scheduler cursor: the UNIX time of the next upstream check (F15).
	 */
	public const KEY_NEXT_CHECK = '_afriex_next_check';

	/**
	 * How many times the sweep has checked this order.
	 *
	 * Plan 6.4 asks for a backoff of 5 minutes, then 15, then an hour, then
	 * six, and so on. A timestamp alone cannot say which step the order is
	 * on, so the step is counted here beside it. Not in plan 11's table, for
	 * the same reason _afriex_deadline is not: the plan names the cursor
	 * without naming everywhere it has to be kept.
	 */
	public const KEY_CHECK_COUNT = '_afriex_check_count';

	/**
	 * Note keys already used on this order, most recent first, as JSON.
	 *
	 * Plan section 11 names this key for note deduplication. It holds a short
	 * list rather than a single key because a payment that alternates between
	 * two statuses would otherwise re-note each one forever (F21, F36).
	 */
	public const KEY_LAST_NOTE_KEY = '_afriex_last_note_key';

	/**
	 * When this order stops waiting for a payment, as a UNIX time. The expire
	 * routine extends it when Afriex still has a transaction in flight
	 * (plan 6.3), which needs somewhere to record the new deadline.
	 */
	public const KEY_DEADLINE = '_afriex_deadline';

	/**
	 * When the sweep stops watching a *closed* Afriex Checkout order for a
	 * late payment, as a UNIX time (plan 5.6).
	 *
	 * A cancelled or failed Checkout order keeps a session that Afriex may
	 * still report a SUCCESS on: a mobile-money approval that arrived after
	 * the window closed, or a webhook that was never delivered. The plan
	 * keeps those orders in the sweep for the payment window plus 48 hours,
	 * so that such a payment is flagged rather than lost.
	 *
	 * It is written the first time the sweep meets the closed order, and it
	 * is what the due-order query filters on afterwards: once it is in the
	 * past the order stops matching, so no separate "finished" flag is
	 * needed and nothing has to be cleaned up.
	 */
	public const KEY_WATCH_UNTIL = '_afriex_watch_until';

	/**
	 * Set when a person must look at the order before anything else happens
	 * to it automatically. The value is a short reason code, such as
	 * 'legacy_pool'; any non-empty value means "needs review".
	 */
	public const KEY_NEEDS_REVIEW = '_afriex_needs_review';

	/**
	 * The review reason an order was flagged with and has since resolved.
	 *
	 * KEY_NEEDS_REVIEW is cleared when the thing it was raised about is
	 * demonstrably over — the order is paid by the very transaction that was
	 * refused, most of all — and the reason is kept here so the history is not
	 * lost. Nothing reads this to decide anything; it is for the order screen
	 * and for whoever asks later why the order was set aside.
	 */
	public const KEY_RESOLVED_REVIEW = '_afriex_resolved_review';

	/**
	 * How many times in a row this order's expiry has been postponed because
	 * the transaction listing could not be read to the end.
	 *
	 * A truncated listing is not evidence that no payment arrived, so the
	 * expire routine will not cancel on one. This counts the refusals so that
	 * an order cannot be extended silently for ever (see
	 * Afriex_Bank_Transfer_Sweep::TRUNCATION_LIMIT).
	 */
	public const KEY_TRUNCATED_CHECKS = '_afriex_truncated_checks';

	/**
	 * A Checkout merchant reference: wc_{order id}_{16 lower-case hex}.
	 *
	 * Anchored at both ends, with no leading zero on the id, so exactly one
	 * string can ever parse to a given order. A reference is 64 bits of
	 * randomness, and the id in it is only a hint: the lookup still has to
	 * agree (plan 6.2).
	 */
	public const REFERENCE_PATTERN = '/^wc_([1-9][0-9]{0,17})_([0-9a-f]{16})$/';

	/**
	 * How many note keys are remembered per order.
	 */
	private const NOTE_KEY_HISTORY = 25;

	/**
	 * How soon the sweep looks at an order that has just minted a session.
	 *
	 * A retry is a fresh payment attempt, so it restarts the backoff ladder at
	 * its first rung rather than inheriting the step the abandoned session had
	 * climbed to. The sweep is the plan's backstop for a lost webhook
	 * (plan 5.6), and a backstop that waits a day is not one.
	 */
	private const NEW_ATTEMPT_CHECK_DELAY = 300;

	/**
	 * Persist Afriex payment details against the order.
	 *
	 * @param WC_Order $order             Order being paid.
	 * @param array    $account_data      Payment method payload from Afriex.
	 * @param string   $customer_id       Afriex customer id.
	 * @param string   $collection_method Either 'dedicated' or 'pool'.
	 */
	public static function store_payment_details(
		WC_Order $order,
		array $account_data,
		string $customer_id,
		string $collection_method = 'dedicated'
	): void {
		$order->update_meta_data( self::KEY_PAYMENT_METHOD_ID, self::extract_payment_method_id( $account_data ) );
		$order->update_meta_data( self::KEY_CUSTOMER_ID, $customer_id );
		$order->update_meta_data( self::KEY_ACCOUNT_NUMBER, (string) ( $account_data['accountNumber'] ?? '' ) );
		$order->update_meta_data( self::KEY_ACCOUNT_NAME, (string) ( $account_data['accountName'] ?? '' ) );
		$order->update_meta_data( self::KEY_BANK_NAME, (string) ( $account_data['institution']['institutionName'] ?? '' ) );
		$order->update_meta_data( self::KEY_REFERENCE, (string) ( $account_data['reference'] ?? $order->get_order_number() ) );
		$order->update_meta_data( self::KEY_LAST_STATUS, 'PENDING' );
		$order->update_meta_data( self::KEY_COLLECTION_METHOD, $collection_method );

		// F32: Afriex's virtual-account schema documents expiresInMinutes, not
		// expiresAt, so 1.0 never wrote this key at all and no dedicated
		// account ever expired. account_expiry() reads either shape and
		// answers with an absolute UNIX time.
		$expires_at = self::account_expiry( $account_data );

		if ( $expires_at > 0 ) {
			$order->update_meta_data( self::KEY_EXPIRES_AT, (string) $expires_at );
		} else {
			// Afriex said nothing about when this account closes, so the
			// store's payment window is the whole answer (F33). An expiry
			// left over from an account this one replaces would be a lie
			// about this one.
			$order->delete_meta_data( self::KEY_EXPIRES_AT );
		}

		$order->save();
	}

	/**
	 * When a virtual account stops accepting money, as a UNIX time.
	 *
	 * Afriex documents `expiresInMinutes` on the create response and has been
	 * seen to use `expiresAt` elsewhere, so both are read and the absolute
	 * field wins. 0 means "Afriex did not say", which is not the same as
	 * "never": the caller falls back to the store's payment window (F32, F33).
	 *
	 * @param array $account Payment method payload from Afriex.
	 * @param int   $now     Reference time, for tests. 0 for the current time.
	 */
	public static function account_expiry( array $account, int $now = 0 ): int {
		foreach ( array( 'expiresAt', 'expiryDate', 'expires_at' ) as $field ) {
			if ( ! isset( $account[ $field ] ) || ! is_scalar( $account[ $field ] ) ) {
				continue;
			}

			$timestamp = self::to_timestamp( (string) $account[ $field ] );

			if ( $timestamp > 0 ) {
				return $timestamp;
			}
		}

		if ( ! isset( $account['expiresInMinutes'] ) || ! is_numeric( $account['expiresInMinutes'] ) ) {
			return 0;
		}

		$minutes = (int) $account['expiresInMinutes'];

		if ( $minutes <= 0 ) {
			// A window of zero minutes is not a window; treat it as no answer
			// rather than as an account that expired before it was returned.
			return 0;
		}

		$created = isset( $account['createdAt'] ) && is_scalar( $account['createdAt'] )
			? self::to_timestamp( (string) $account['createdAt'] )
			: 0;

		if ( $created <= 0 ) {
			$created = $now > 0 ? $now : time();
		}

		return $created + $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * A date Afriex sent, as a UNIX time, or 0.
	 *
	 * A bare number is seconds unless it is far too large to be seconds, in
	 * which case it is milliseconds: the audit asks for values above 1e11 to
	 * be read that way (F32), and 1e11 seconds is the year 5138.
	 *
	 * @param string $raw Date string or numeric timestamp.
	 */
	public static function to_timestamp( string $raw ): int {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return 0;
		}

		if ( is_numeric( $raw ) ) {
			$value = (float) $raw;

			if ( $value >= 1e11 ) {
				$value /= 1000;
			}

			return $value > 0 ? (int) $value : 0;
		}

		$parsed = strtotime( $raw );

		return false === $parsed ? 0 : (int) $parsed;
	}

	/**
	 * Afriex returns the payment method id under more than one key depending on
	 * whether the account was created or fetched. Accept either, so the webhook
	 * lookup does not depend on which endpoint produced the account.
	 *
	 * @param array $account_data Payment method payload.
	 */
	public static function extract_payment_method_id( array $account_data ): string {
		foreach ( array( 'paymentMethodId', 'id', '_id' ) as $key ) {
			if ( ! empty( $account_data[ $key ] ) && is_scalar( $account_data[ $key ] ) ) {
				return (string) $account_data[ $key ];
			}
		}

		return '';
	}

	/**
	 * The Afriex payment method (virtual account) id.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_payment_method_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_PAYMENT_METHOD_ID );
	}

	/**
	 * The Afriex customer id the account was issued for.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_customer_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_CUSTOMER_ID );
	}

	/**
	 * The payment reference shown to the customer.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_reference( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_REFERENCE );
	}

	/**
	 * The Afriex transaction last seen for this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_transaction_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_TRANSACTION_ID );
	}

	/**
	 * The last Afriex status recorded against this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_last_status( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_LAST_STATUS );
	}

	/**
	 * How the account was collected: 'dedicated', or 'pool' for a 1.0 order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_collection_method( WC_Order $order ): string {
		$method = (string) $order->get_meta( self::KEY_COLLECTION_METHOD );

		return '' !== $method ? $method : 'dedicated';
	}

	/**
	 * Expiry timestamp of a dynamic virtual account, or 0 when open-ended.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_expires_at( WC_Order $order ): int {
		return self::to_timestamp( (string) $order->get_meta( self::KEY_EXPIRES_AT ) );
	}

	/**
	 * Retire this order's current virtual account before a new one is stored.
	 *
	 * Two things happen together because they are the same event (F20):
	 *
	 * - the id being replaced is remembered, so a transfer already on its way
	 *   into it still finds this order instead of looking like money for
	 *   another merchant;
	 * - everything the old account left behind is cleared. The recorded
	 *   transaction id, the expiry and the sweep deadline all described the
	 *   account being replaced. Left in place, the next sweep re-fetches the
	 *   old account's FAILED transaction and fails an order that has just
	 *   been given a fresh account to pay into, which is exactly what the
	 *   audit reproduced on order #93.
	 *
	 * The caller saves: store_payment_details() runs immediately afterwards.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $previous_id The virtual account id being replaced.
	 */
	public static function supersede_payment_method( WC_Order $order, string $previous_id ): void {
		$previous_id = trim( $previous_id );

		if ( '' !== $previous_id && ! in_array( $previous_id, self::get_previous_payment_method_ids( $order ), true ) ) {
			$order->add_meta_data( self::KEY_PREVIOUS_PAYMENT_METHOD_ID, $previous_id );
		}

		$order->delete_meta_data( self::KEY_TRANSACTION_ID );
		$order->delete_meta_data( self::KEY_EXPIRES_AT );
		$order->delete_meta_data( self::KEY_DEADLINE );
		$order->update_meta_data( self::KEY_LAST_STATUS, 'PENDING' );

		// A fresh attempt restarts the backoff ladder, for the same reason a
		// new Checkout session does: the sweep is the backstop for a lost
		// webhook, and a backstop that waits six hours is not one.
		$order->update_meta_data( self::KEY_NEXT_CHECK, time() + self::NEW_ATTEMPT_CHECK_DELAY );
		$order->update_meta_data( self::KEY_CHECK_COUNT, 0 );
	}

	/**
	 * Every virtual account id this order has had and replaced, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public static function get_previous_payment_method_ids( WC_Order $order ): array {
		$ids = array();

		foreach ( $order->get_meta( self::KEY_PREVIOUS_PAYMENT_METHOD_ID, false ) as $meta ) {
			$value = is_object( $meta ) ? $meta->value : $meta;

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$ids[] = trim( (string) $value );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The account details shown to the customer, as label => value pairs.
	 *
	 * Nothing at all when the order has no account number (F89): the rows are
	 * introduced by "transfer the exact order total to the account below", and
	 * an amount with no account to send it to is worse than silence. The
	 * account number is the one row that cannot be missing from an account a
	 * person can pay into, so it is the test.
	 *
	 * Returned raw: callers escape at the point of output.
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $deadline When the transfer has to arrive by, or 0 to
	 *                           leave the row out (F32, F33).
	 * @return array<string, string>
	 */
	public static function get_payment_instructions( WC_Order $order, int $deadline = 0 ): array {
		$rows = array();

		$account_number = (string) $order->get_meta( self::KEY_ACCOUNT_NUMBER );

		if ( '' === trim( $account_number ) ) {
			return $rows;
		}

		$bank_name = (string) $order->get_meta( self::KEY_BANK_NAME );
		if ( '' !== $bank_name ) {
			$rows[ __( 'Bank', 'afriex-gateway-for-woocommerce' ) ] = $bank_name;
		}

		$account_name = (string) $order->get_meta( self::KEY_ACCOUNT_NAME );
		if ( '' !== $account_name ) {
			$rows[ __( 'Account name', 'afriex-gateway-for-woocommerce' ) ] = $account_name;
		}

		$rows[ __( 'Account number', 'afriex-gateway-for-woocommerce' ) ] = $account_number;

		// A dedicated account is scoped to this order, so no reference is needed.
		// A pool account is shared, and the reference is the only thing tying the
		// deposit back to this order — so it is always shown for pool.
		if ( 'pool' === self::get_collection_method( $order ) ) {
			$reference = self::get_reference( $order );
			if ( '' !== $reference ) {
				$rows[ __( 'Payment reference', 'afriex-gateway-for-woocommerce' ) ] = $reference;
			}
		}

		// The figure the shopper is asked to transfer has to be the figure
		// settlement will accept, and settlement compares a deposit against
		// what the virtual account was ISSUED for — frozen at issue time, in
		// minor units — not against the live order total. WooCommerce lets an
		// admin edit an on-hold order's total, and when they do, printing the
		// new total here invites a transfer that can only ever come back as
		// AMOUNT_MISMATCH. So the expectation wins, and the live total stands
		// in only for an order that has none (a 1.0 order before the
		// migration backfilled it).
		$expected = self::get_expected( $order );
		$currency = (string) $order->get_currency();
		$amount   = (float) $order->get_total();

		if ( null !== $expected['minor'] && '' !== $expected['currency'] ) {
			$currency = (string) $expected['currency'];
			$amount   = (float) Afriex_Money::to_major_string( (int) $expected['minor'], $currency );
		}

		$rows[ __( 'Amount', 'afriex-gateway-for-woocommerce' ) ] = self::format_amount( $amount, $currency );

		// The shopper is being asked to go to their bank and make a transfer,
		// and the account will not be there for ever. Telling them when it
		// closes is the whole point of capturing the expiry (F32, F33).
		if ( $deadline > 0 ) {
			$rows[ __( 'Pay by', 'afriex-gateway-for-woocommerce' ) ] = self::format_deadline( $deadline );
		}

		return $rows;
	}

	/**
	 * A deadline in the store's own timezone and date format.
	 *
	 * @param int $timestamp UNIX time.
	 */
	public static function format_deadline( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * A formatted amount as plain text, safe to hand to esc_html().
	 *
	 * Note that wc_price() returns markup containing entities such as &nbsp; in the
	 * currency separator. Stripping the tags alone leaves those entities in
	 * place, and escaping the result would then render a literal "&nbsp;" to
	 * the customer. Decoding after stripping is what makes the string genuinely
	 * plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	public static function format_amount( float $amount, string $currency ): string {
		$formatted = wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );

		return html_entity_decode( $formatted, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/*
	 * The 1.0 single-order look-ups — find_order_by_payment_method_id(),
	 * find_order_by_reference() and find_order_by_meta() — are gone. They
	 * took the most recently dated match and returned it, with no check that
	 * only one order carried the value, that the order was still waiting for
	 * money, or that the account belonged to it. That is the binding hazard
	 * F30 exists for, and it is why a deposit is now resolved only through
	 * find_order_ids_by_payment_method_id() /
	 * find_order_ids_by_checkout_reference(), which return every match and
	 * leave Afriex_Settlement::resolve_order_id() to refuse rather than
	 * guess. They were unreferenced once Afriex_Reconciler was deleted;
	 * keeping an obvious-looking shortcut next to the safe one only invites
	 * the next caller to reintroduce the bug.
	 */

	/**
	 * Run wc_get_orders() with a meta_query that works on both order storages.
	 *
	 * HPOS applies a 'meta_query' query var. The legacy posts storage does
	 * not: WC_Data_Store_WP::get_wp_query_args() drops the key, so the query
	 * matches every order the other vars match (WooCommerce 9.2+ also raises
	 * a "doing it wrong" notice; older versions say nothing). On the posts
	 * storage the meta query is therefore passed under a private query var
	 * instead, which apply_cpt_meta_query() adds back through WooCommerce's
	 * documented filter for custom query vars.
	 *
	 * @param array $query_vars wc_get_orders() arguments, without meta_query.
	 * @param array $meta_query A WP_Meta_Query-style array.
	 * @return array Whatever wc_get_orders() returns for these arguments.
	 */
	public static function query_orders( array $query_vars, array $meta_query ): array {
		if ( self::orders_use_hpos() ) {
			$query_vars['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} else {
			if ( ! has_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'apply_cpt_meta_query' ) ) ) {
				add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'apply_cpt_meta_query' ), 10, 2 );
			}

			$query_vars['afriex_meta_query'] = $meta_query;
		}

		$result = wc_get_orders( $query_vars );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Whether orders are read from the HPOS tables.
	 */
	public static function orders_use_hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Add the private afriex_meta_query var to a posts-storage order query.
	 *
	 * @param array $wp_query_args WP_Query arguments built by WooCommerce.
	 * @param array $query_vars    The wc_get_orders() query vars.
	 * @return array
	 */
	public static function apply_cpt_meta_query( $wp_query_args, $query_vars ) {
		if ( ! is_array( $wp_query_args ) || empty( $query_vars['afriex_meta_query'] ) || ! is_array( $query_vars['afriex_meta_query'] ) ) {
			return $wp_query_args;
		}

		$existing = isset( $wp_query_args['meta_query'] ) && is_array( $wp_query_args['meta_query'] ) ? $wp_query_args['meta_query'] : array();

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The caller asked for this meta query.
		$wp_query_args['meta_query'] = empty( $existing )
			? $query_vars['afriex_meta_query']
			: array(
				'relation' => 'AND',
				$existing,
				$query_vars['afriex_meta_query'],
			);

		return $wp_query_args;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Checkout references (plan 5.1 step 4 and 6.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Mint a merchant reference for a Checkout session.
	 *
	 * 64 bits from the CSPRNG. The order id is in the string only so that the
	 * webhook can resolve an order without a database round trip; it is never
	 * trusted on its own, because anyone who sees one reference can write
	 * another with a different id in it.
	 *
	 * @param int $order_id Order the session is for.
	 */
	public static function mint_reference( int $order_id ): string {
		return 'wc_' . $order_id . '_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * The order id inside a reference, or 0 when the string is not one of ours.
	 *
	 * @param string $reference Candidate merchant reference.
	 */
	public static function parse_reference( string $reference ): int {
		if ( 1 !== preg_match( self::REFERENCE_PATTERN, trim( $reference ), $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	/**
	 * Record a newly minted reference and make it the current one.
	 *
	 * The expectation travels with the reference rather than with the order,
	 * so a payment that arrives on an old session is judged against the amount
	 * that session actually asked for, even after the cart changed (F29).
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $reference    Reference minted for this session.
	 * @param int      $amount_minor Amount the session asks for, in minor units.
	 * @param string   $currency     ISO 4217 code.
	 * @param string   $environment  Afriex environment the session was created in.
	 * @return array The stored row.
	 */
	public static function add_checkout_reference( WC_Order $order, string $reference, int $amount_minor, string $currency, string $environment ): array {
		$row = array(
			'ref'          => $reference,
			'amount_minor' => $amount_minor,
			'currency'     => strtoupper( trim( $currency ) ),
			'environment'  => $environment,
			'created'      => time(),
			'session_id'   => null,
			'expires_at'   => null,
			// The hosted page belongs to the session, not to the order: it is
			// written here empty and filled in only once Afriex has answered
			// with one, so a reference whose create failed can never be given
			// the previous session's page (plan 5.1 step 2).
			'url'          => '',
			// Set when Afriex has finished with this session. A terminal
			// failure spends the hosted page, so it must not be handed back to
			// the shopper for the rest of its lifetime (plan 3).
			'closed'       => false,
		);

		$order->add_meta_data( self::KEY_CHECKOUT_REFERENCE, wp_json_encode( $row ), false );
		$order->add_meta_data( self::KEY_CHECKOUT_REF_INDEX, $reference, false );
		$order->update_meta_data( self::KEY_CHECKOUT_CURRENT_REFERENCE, $reference );

		// The order-level copy describes whichever session was last created
		// successfully, and this attempt has no page of its own yet. Clearing
		// it here means nothing can mistake the old one for this one, whatever
		// happens to the create that follows.
		$order->delete_meta_data( self::KEY_CHECKOUT_URL );

		// A new session is a new payment attempt, so the sweep's backoff
		// starts again: the ladder the abandoned session climbed would
		// otherwise leave this one unwatched for up to a day (plan 5.6).
		$order->update_meta_data( self::KEY_NEXT_CHECK, time() + self::NEW_ATTEMPT_CHECK_DELAY );
		$order->update_meta_data( self::KEY_CHECK_COUNT, 0 );

		$order->save();

		return $row;
	}

	/**
	 * Every Checkout reference on an order, newest last, keyed by reference.
	 *
	 * A row that will not decode is skipped rather than guessed at: settlement
	 * compares amounts against these, and half a row is worse than none.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, array>
	 */
	public static function get_checkout_reference_rows( WC_Order $order ): array {
		$rows = array();

		foreach ( self::reference_meta( $order ) as $meta ) {
			$row = json_decode( (string) $meta->value, true );

			if ( ! is_array( $row ) || empty( $row['ref'] ) || ! is_string( $row['ref'] ) ) {
				continue;
			}

			$row['meta_id']      = (int) $meta->id;
			$row['amount_minor'] = isset( $row['amount_minor'] ) && is_numeric( $row['amount_minor'] ) ? (int) $row['amount_minor'] : null;
			$row['currency']     = strtoupper( (string) ( $row['currency'] ?? '' ) );

			// A row written by an earlier build has neither key. Both default
			// to "no session to reuse", which is the safe answer: the worst it
			// costs is one extra session.
			$row['url']    = isset( $row['url'] ) && is_string( $row['url'] ) ? $row['url'] : '';
			$row['closed'] = ! empty( $row['closed'] );

			$rows[ $row['ref'] ] = $row;
		}

		return $rows;
	}

	/**
	 * One reference's row, or null when the order never had that reference.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Reference.
	 */
	public static function get_checkout_reference( WC_Order $order, string $reference ): ?array {
		$rows = self::get_checkout_reference_rows( $order );

		return isset( $rows[ $reference ] ) ? $rows[ $reference ] : null;
	}

	/**
	 * Merge changes into one reference's row, e.g. the session id and expiry
	 * that CHECKOUT_SESSION.CREATED brings.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Reference.
	 * @param array    $changes   Keys to overwrite.
	 * @return bool False when the order has no such reference.
	 */
	public static function update_checkout_reference( WC_Order $order, string $reference, array $changes ): bool {
		$row = self::get_checkout_reference( $order, $reference );

		if ( null === $row ) {
			return false;
		}

		$meta_id = (int) $row['meta_id'];
		unset( $row['meta_id'] );

		$row = array_merge( $row, $changes );

		// The reference itself is the row's identity and is never rewritten.
		$row['ref'] = $reference;

		$order->update_meta_data( self::KEY_CHECKOUT_REFERENCE, wp_json_encode( $row ), $meta_id );
		$order->save();

		return true;
	}

	/**
	 * The reference of the latest session, which is the only one allowed to
	 * fail the order (F19).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_current_checkout_reference( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_CHECKOUT_CURRENT_REFERENCE );
	}

	/**
	 * The raw meta rows holding Checkout reference JSON.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function reference_meta( WC_Order $order ): array {
		$meta = $order->get_meta( self::KEY_CHECKOUT_REFERENCE, false );

		return is_array( $meta ) ? $meta : array();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Exact look-ups. Every one returns ids, never orders: the caller takes
	 * the order lock before loading, so that what it then reads is not a
	 * copy from before the lock (plan 6.1).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Orders carrying this exact Checkout reference.
	 *
	 * @param string $reference Merchant reference.
	 * @param int    $limit     Rows to fetch; more than one means ambiguity.
	 * @return int[]
	 */
	public static function find_order_ids_by_checkout_reference( string $reference, int $limit = 5 ): array {
		if ( '' === trim( $reference ) ) {
			return array();
		}

		// The trash is searched too. `status => any` leaves it out on both
		// storages, so a merchant who trashed an unpaid order with a live
		// session would make every payment on it unresolvable — and an
		// unresolvable payment looks exactly like money belonging to another
		// store, which is logged and dropped. Found, it can at least be
		// flagged for a person (plan 6.2).
		return self::find_order_ids_by_meta( self::KEY_CHECKOUT_REF_INDEX, $reference, self::all_statuses_with_trash(), $limit );
	}

	/**
	 * Orders issued this exact Afriex payment method (virtual account) id.
	 *
	 * @param string   $payment_method_id Afriex payment method id.
	 * @param string[] $statuses          Order statuses to limit to, or empty for any.
	 * @param int      $limit             Rows to fetch.
	 * @return int[]
	 */
	public static function find_order_ids_by_payment_method_id( string $payment_method_id, array $statuses = array(), int $limit = 5 ): array {
		if ( '' === trim( $payment_method_id ) ) {
			return array();
		}

		return self::find_order_ids_by_meta( self::KEY_PAYMENT_METHOD_ID, $payment_method_id, $statuses, $limit );
	}

	/**
	 * Orders that held this virtual account id and have since replaced it.
	 *
	 * Deliberately separate from the look-up above rather than folded into
	 * it: PAYMENT_METHOD.UPDATED must only ever reach the order an account is
	 * *currently* the account for, because an expiry on a superseded account
	 * says nothing about the order that has moved on to a new one.
	 *
	 * @param string   $payment_method_id Afriex payment method id.
	 * @param string[] $statuses          Order statuses to limit to, or empty for any.
	 * @param int      $limit             Rows to fetch.
	 * @return int[]
	 */
	public static function find_order_ids_by_previous_payment_method_id( string $payment_method_id, array $statuses = array(), int $limit = 5 ): array {
		if ( '' === trim( $payment_method_id ) ) {
			return array();
		}

		return self::find_order_ids_by_meta( self::KEY_PREVIOUS_PAYMENT_METHOD_ID, $payment_method_id, $statuses, $limit );
	}

	/**
	 * Orders for which this virtual account id is current or superseded.
	 *
	 * The current holder wins: an id is only ever superseded on one order, so
	 * two answers mean two orders genuinely claim the same account, which is
	 * refused rather than guessed at (F30).
	 *
	 * @param string   $payment_method_id Afriex payment method id.
	 * @param string[] $statuses          Order statuses to limit to, or empty for any.
	 * @param int      $limit             Rows to fetch.
	 * @return int[]
	 */
	public static function find_order_ids_by_any_payment_method_id( string $payment_method_id, array $statuses = array(), int $limit = 5 ): array {
		$ids = self::find_order_ids_by_payment_method_id( $payment_method_id, $statuses, $limit );

		if ( ! empty( $ids ) ) {
			return $ids;
		}

		return self::find_order_ids_by_previous_payment_method_id( $payment_method_id, $statuses, $limit );
	}

	/**
	 * Every order status a look-up may match, the trash included.
	 *
	 * @return string[]
	 */
	private static function all_statuses_with_trash(): array {
		$statuses = array_keys( wc_get_order_statuses() );

		// wc_get_order_statuses() lists only the registered shop-order
		// statuses, and `trash` is a WordPress post status rather than one of
		// them, so it is added here by hand.
		$statuses[] = 'trash';

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Ids of orders whose meta key holds exactly this value.
	 *
	 * @param string   $meta_key   Meta key.
	 * @param string   $meta_value Exact value.
	 * @param string[] $statuses   Order statuses, or empty for any.
	 * @param int      $limit      Rows to fetch.
	 * @return int[]
	 */
	private static function find_order_ids_by_meta( string $meta_key, string $meta_value, array $statuses = array(), int $limit = 5 ): array {
		$ids = self::query_orders(
			array(
				'limit'   => max( 1, $limit ),
				'return'  => 'ids',
				'status'  => empty( $statuses ) ? 'any' : $statuses,
				'orderby' => 'ID',
				'order'   => 'ASC',
			),
			array(
				array(
					'key'     => $meta_key,
					'value'   => $meta_value,
					'compare' => '=',
				),
			)
		);

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settlement bookkeeping.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The Afriex environment this order was created under, falling back to the
	 * store's current one for a 1.0 order that recorded none (F50).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_environment( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_ENVIRONMENT );
	}

	/**
	 * What this order is expected to be paid, in minor units.
	 *
	 * For Checkout the expectation belongs to a reference and is read from its
	 * row instead. A Bank Transfer order placed before the 1.1 backfill has
	 * none, and the caller falls back to the order total rather than reading a
	 * missing expectation as a mismatch.
	 *
	 * @param WC_Order $order Order.
	 * @return array minor (int|null) and currency (string).
	 */
	public static function get_expected( WC_Order $order ): array {
		$minor    = $order->get_meta( self::KEY_EXPECTED_AMOUNT_MINOR );
		$currency = (string) $order->get_meta( self::KEY_EXPECTED_CURRENCY );

		return array(
			'minor'    => ( '' === $minor || null === $minor || ! is_numeric( $minor ) ) ? null : (int) $minor,
			'currency' => '' === $currency ? '' : strtoupper( $currency ),
		);
	}

	/**
	 * The transaction that paid for this order, or '' when none has.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_settled_transaction_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_SETTLED_TRANSACTION_ID );
	}

	/**
	 * The transaction a settlement was interrupted part-way through applying,
	 * or '' when none was.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_settling_transaction_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_SETTLING_TRANSACTION_ID );
	}

	/**
	 * Mark the order as needing a person to look at it.
	 *
	 * The first reason wins: an order flagged as a legacy pool order and then
	 * seen to be overpaid is still, first of all, a legacy pool order, and
	 * overwriting the reason would lose why it was set aside.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Short reason code.
	 */
	public static function flag_for_review( WC_Order $order, string $reason ): void {
		if ( '' !== (string) $order->get_meta( self::KEY_NEEDS_REVIEW ) ) {
			return;
		}

		$order->update_meta_data( self::KEY_NEEDS_REVIEW, $reason );
		$order->save();
	}

	/**
	 * Why this order needs review, or '' when it does not.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_review_flag( WC_Order $order ): string {
		return (string) $order->get_meta( self::KEY_NEEDS_REVIEW );
	}

	/**
	 * Take the review flag off an order whose reason has been resolved.
	 *
	 * The flag used to be write-once with no counterpart, so an order that was
	 * flagged, corrected and then paid in full by the very transaction that
	 * had been refused stayed flagged for ever: its order box kept saying
	 * "This order needs review", it stayed in the store-wide review notice,
	 * and — because a review flag sets may_cancel = false — it could never be
	 * cancelled either if the shopper walked away.
	 *
	 * The reason is not thrown away: it moves to KEY_RESOLVED_REVIEW, so the
	 * order still records that it was set aside once and why.
	 *
	 * @param WC_Order $order Order.
	 * @return string The reason that was cleared, or '' when there was none.
	 */
	public static function clear_review_flag( WC_Order $order ): string {
		$reason = (string) $order->get_meta( self::KEY_NEEDS_REVIEW );

		if ( '' === $reason ) {
			return '';
		}

		$order->delete_meta_data( self::KEY_NEEDS_REVIEW );
		$order->update_meta_data( self::KEY_RESOLVED_REVIEW, $reason );
		$order->save();

		return $reason;
	}

	/**
	 * Count one expiry refused because the listing was truncated, and say how
	 * many in a row that now is.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function count_truncated_check( WC_Order $order ): int {
		$count = (int) $order->get_meta( self::KEY_TRUNCATED_CHECKS ) + 1;

		$order->update_meta_data( self::KEY_TRUNCATED_CHECKS, $count );
		$order->save();

		return $count;
	}

	/**
	 * Forget the run of truncated listings: this one was read to the end.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function clear_truncated_checks( WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::KEY_TRUNCATED_CHECKS ) ) {
			return;
		}

		$order->delete_meta_data( self::KEY_TRUNCATED_CHECKS );
		$order->save();
	}

	/**
	 * Add an order note at most once per note key.
	 *
	 * Afriex retries a delivery up to twelve times, the sweep looks again every
	 * five minutes, and the return handler and the status endpoint both check
	 * as well. Without this, one PROCESSING transaction would paper an order
	 * with identical notes (F21, F36).
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $note_key      Stable key for this note, e.g. "status:PENDING".
	 * @param string   $note          Note text, already translated.
	 * @param bool     $customer_note Whether the customer is emailed the note.
	 * @return bool False when the note had been added before.
	 */
	public static function note_once( WC_Order $order, string $note_key, string $note, bool $customer_note = false ): bool {
		$note_key = trim( $note_key );

		if ( '' === $note_key ) {
			$order->add_order_note( $note, $customer_note ? 1 : 0 );
			return true;
		}

		$seen = self::note_keys( $order );

		if ( in_array( $note_key, $seen, true ) ) {
			return false;
		}

		$order->add_order_note( $note, $customer_note ? 1 : 0 );

		array_unshift( $seen, $note_key );
		$order->update_meta_data( self::KEY_LAST_NOTE_KEY, wp_json_encode( array_slice( $seen, 0, self::NOTE_KEY_HISTORY ) ) );
		$order->save();

		return true;
	}

	/**
	 * Note keys already used on this order.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public static function note_keys( WC_Order $order ): array {
		$raw = (string) $order->get_meta( self::KEY_LAST_NOTE_KEY );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		// 1.1 development builds wrote a single key here rather than a list.
		if ( ! is_array( $decoded ) ) {
			return array( $raw );
		}

		return array_values( array_filter( array_map( 'strval', $decoded ), 'strlen' ) );
	}

	/**
	 * When this order stops waiting for a payment, as a UNIX time, or 0.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_deadline( WC_Order $order ): int {
		$raw = $order->get_meta( self::KEY_DEADLINE );

		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	/**
	 * Move the deadline, never backwards.
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $deadline UNIX time.
	 * @return bool Whether the deadline moved.
	 */
	public static function extend_deadline( WC_Order $order, int $deadline ): bool {
		if ( $deadline <= self::get_deadline( $order ) ) {
			return false;
		}

		$order->update_meta_data( self::KEY_DEADLINE, $deadline );
		$order->save();

		return true;
	}

	/**
	 * When the sweep stops watching a closed Checkout order, or 0 when it has
	 * not been worked out yet.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_watch_until( WC_Order $order ): int {
		$raw = $order->get_meta( self::KEY_WATCH_UNTIL );

		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	/**
	 * Record when the sweep stops watching a closed Checkout order.
	 *
	 * Written once and then left alone: it is the answer to "how long does
	 * this store wait for a late payment", not a cursor, and re-deriving it
	 * from a deadline the expire routine may have moved would keep pushing
	 * the end of the watch further away.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $until UNIX time.
	 */
	public static function set_watch_until( WC_Order $order, int $until ): void {
		$order->update_meta_data( self::KEY_WATCH_UNTIL, $until );
		$order->save();
	}

	/**
	 * Record when the sweep should next look at this order (F15).
	 *
	 * @param WC_Order $order Order.
	 * @param int      $when  UNIX time.
	 * @param int|null $count The backoff step this order has reached, or null
	 *                        to leave the count alone.
	 */
	public static function set_next_check( WC_Order $order, int $when, ?int $count = null ): void {
		$order->update_meta_data( self::KEY_NEXT_CHECK, $when );

		if ( null !== $count ) {
			$order->update_meta_data( self::KEY_CHECK_COUNT, max( 0, $count ) );
		}

		$order->save();
	}

	/**
	 * When the sweep should next look at this order, or 0 for "now".
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_next_check( WC_Order $order ): int {
		$raw = $order->get_meta( self::KEY_NEXT_CHECK );

		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	/**
	 * How many times the sweep has already checked this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_check_count( WC_Order $order ): int {
		$raw = $order->get_meta( self::KEY_CHECK_COUNT );

		return is_numeric( $raw ) ? max( 0, (int) $raw ) : 0;
	}
}
