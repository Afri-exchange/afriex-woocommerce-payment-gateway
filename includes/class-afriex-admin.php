<?php
/**
 * The Afriex admin surface: the order box, the order action and the notices
 * that are not tied to one settings page.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan 4.2 and 4.3.
 *
 * Three things live here.
 *
 * 1. **The Afriex box on the order edit screen.** Everything the plugin knows
 *    about one order's payment, for both methods: which method, which
 *    environment and key, the virtual account or every Checkout reference with
 *    its session id and expiry, the channel used, the settled transaction, the
 *    last Afriex status and the review flag. It is read-only. Nothing on this
 *    screen changes an order's money except the order action below.
 * 2. **Check Afriex payment now.** A registered `woocommerce_order_actions`
 *    entry, so WooCommerce's own order-edit nonce and capability are what
 *    guard it (plan 13): the plugin adds no second form and no second URL that
 *    could be reached without them. It runs the same throttled, locked check
 *    the sweep and the order-received page run.
 * 3. **The notices that belong to no single screen.** The 401/403, the missing
 *    webhook key and the environment switch are the base gateway class's, and
 *    the review flag is the settlement engine's, because each of those is
 *    written where it is detected. What is left is the two this class owns:
 *    an unsupported currency learned from a 422, and the store offering bank
 *    transfer twice over.
 *
 * Registered from the plugin bootstrap on `is_admin()`, never from a gateway:
 * an order taken before a method was switched off still has to be readable
 * and checkable (plan 4.2).
 */
class Afriex_Admin {

	/**
	 * The order action's id. It becomes
	 * `woocommerce_order_action_afriex_check_payment`.
	 */
	public const ACTION = 'afriex_check_payment';

	/**
	 * The meta box's id.
	 */
	private const BOX_ID = 'afriex-order-details';

	/**
	 * Currencies Afriex has refused, as `{currency: {code, time}}` (plan 4.2).
	 */
	public const CURRENCY_NOTICE_OPTION = 'afriex_wc_currency_notice';

	/**
	 * Notice keys a store manager has dismissed.
	 */
	public const DISMISSED_OPTION = 'afriex_wc_dismissed_notices';

	/**
	 * The key of the "shoppers see bank transfer twice" notice.
	 */
	private const NOTICE_DUPLICATE_BANK_TRANSFER = 'duplicate_bank_transfer';

	/**
	 * Where the result of one Check-now press waits for the redirect that
	 * follows it. Per user, and short-lived: it is a sentence about a button
	 * press, not a record of anything.
	 */
	private const RESULT_TRANSIENT = 'afriex_wc_check_result_';

	/**
	 * Screens the configuration notices appear on.
	 *
	 * Enough to be seen by somebody setting the store up, few enough not to
	 * follow them around the admin. The urgent notices — a rejected key, an
	 * environment switched under live orders — are the base class's and show
	 * everywhere, because their consequence is that payments stop.
	 */
	private const NOTICE_SCREENS = array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' );

	/**
	 * Hook the order screen and the notices. Called from the plugin bootstrap.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_box' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( __CLASS__, 'check_payment_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_notice_dismissal' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The Afriex box (plan 4.3).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The order edit screen's id, whichever order storage is in use.
	 */
	private static function order_screen_id(): string {
		if ( Afriex_Order_Meta::orders_use_hpos() && function_exists( 'wc_get_page_screen_id' ) ) {
			return (string) wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * Add the box to an Afriex order, and to nothing else.
	 *
	 * @param string                $post_type     Screen id (HPOS) or post type.
	 * @param WP_Post|WC_Order|null $post_or_order The order being edited.
	 */
	public static function add_order_box( $post_type, $post_or_order = null ): void {
		$screen = self::order_screen_id();

		if ( $post_type !== $screen ) {
			return;
		}

		$order = self::resolve_order( $post_or_order );

		if ( null === $order ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'Afriex', 'afriex-gateway-for-woocommerce' ),
			array( __CLASS__, 'render_order_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * An Afriex order from whatever the order screen hands over, or null.
	 *
	 * @param mixed $post_or_order WP_Post, WC_Order or an id.
	 */
	private static function resolve_order( $post_or_order ): ?WC_Order {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		return in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ? $order : null;
	}

	/**
	 * Print the box.
	 *
	 * @param mixed $post_or_order WP_Post or WC_Order, as WordPress passes it.
	 */
	public static function render_order_box( $post_or_order ): void {
		$order = self::resolve_order( $post_or_order );

		if ( null === $order ) {
			return;
		}

		$review = Afriex_Order_Meta::get_review_flag( $order );

		if ( '' !== $review ) {
			printf(
				'<p class="afriex-order-box__review"><strong>%s</strong><br /><span>%s</span></p>',
				esc_html__( 'This order needs review.', 'afriex-gateway-for-woocommerce' ),
				esc_html( self::review_reason_label( $review ) )
			);
		}

		self::render_rows( self::shared_rows( $order ) );

		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID === $order->get_payment_method() ) {
			self::render_checkout_sessions( $order );
		} else {
			self::render_rows( self::bank_transfer_rows( $order ), __( 'Bank account', 'afriex-gateway-for-woocommerce' ) );
		}
	}

	/**
	 * One definition list of label => value pairs, already unescaped.
	 *
	 * @param array<string, string> $rows    Rows.
	 * @param string                $heading Optional heading above them.
	 */
	private static function render_rows( array $rows, string $heading = '' ): void {
		if ( empty( $rows ) ) {
			return;
		}

		if ( '' !== $heading ) {
			echo '<h4 class="afriex-order-box__heading">' . esc_html( $heading ) . '</h4>';
		}

		echo '<ul class="afriex-order-box__list">';

		foreach ( $rows as $label => $value ) {
			printf(
				'<li><strong>%1$s:</strong> <span>%2$s</span></li>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</ul>';
	}

	/**
	 * What both methods record.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private static function shared_rows( WC_Order $order ): array {
		$rows = array();

		$rows[ __( 'Method', 'afriex-gateway-for-woocommerce' ) ] = AFRIEX_WC_CHECKOUT_GATEWAY_ID === $order->get_payment_method()
			? __( 'Afriex Checkout', 'afriex-gateway-for-woocommerce' )
			: __( 'Afriex Bank Transfer', 'afriex-gateway-for-woocommerce' );

		$environment = Afriex_Order_Meta::get_environment( $order );

		if ( '' !== $environment ) {
			$rows[ __( 'Environment', 'afriex-gateway-for-woocommerce' ) ] = $environment;
		}

		$fingerprint = (string) $order->get_meta( Afriex_Order_Meta::KEY_KEY_FINGERPRINT );

		if ( '' !== $fingerprint ) {
			// Not the end of the key: a fingerprint, the first twelve
			// characters of sha256(key). Saying "ends in" here sent admins to
			// compare it with the settings page's "Saved (ends in …)", which
			// really is the last four characters of the key, and conclude the
			// wrong key was installed. Both places now say fingerprint and
			// print the same value.
			$rows[ __( 'API key', 'afriex-gateway-for-woocommerce' ) ] = sprintf(
				/* translators: %s: a short hash identifying which API key was used */
				__( 'key fingerprint %s', 'afriex-gateway-for-woocommerce' ),
				$fingerprint
			);
		}

		$channel = (string) $order->get_meta( Afriex_Order_Meta::KEY_CHANNEL );

		if ( '' !== $channel ) {
			$rows[ __( 'Channel used', 'afriex-gateway-for-woocommerce' ) ] = Afriex_Checkout_Gateway::channel_label( $channel );
		}

		$settled = Afriex_Order_Meta::get_settled_transaction_id( $order );

		if ( '' !== $settled ) {
			$rows[ __( 'Settled transaction', 'afriex-gateway-for-woocommerce' ) ] = $settled;
		}

		$last_status = Afriex_Order_Meta::get_last_status( $order );

		if ( '' !== $last_status ) {
			$rows[ __( 'Last Afriex status', 'afriex-gateway-for-woocommerce' ) ] = $last_status;
		}

		$rows = array_merge( $rows, self::deadline_rows( $order ) );

		return $rows;
	}

	/**
	 * When this order stops waiting for its payment (F32, F33).
	 *
	 * The stored deadline is the sweep's, and the sweep writes it the first
	 * time it meets the order — which can be five minutes after the shopper
	 * was told to transfer the money. Until then the order screen fell silent
	 * about the one thing the shopper had been given a date for. So a Bank
	 * Transfer order with no stored deadline yet is shown the same deadline
	 * the shopper's own page is showing: the account's expiry when Afriex gave
	 * one, otherwise the store's payment window. It is marked as the expected
	 * one rather than presented as a decision already taken.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private static function deadline_rows( WC_Order $order ): array {
		$label    = __( 'Payment window closes', 'afriex-gateway-for-woocommerce' );
		$deadline = Afriex_Order_Meta::get_deadline( $order );

		if ( $deadline > 0 ) {
			return array( $label => self::format_time( $deadline ) );
		}

		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID === $order->get_payment_method() ) {
			return array();
		}

		$expected = Afriex_Gateway::transfer_deadline( $order );

		if ( $expected <= 0 ) {
			return array();
		}

		return array(
			$label => sprintf(
				/* translators: %s: date and time the payment window closes */
				__( '%s (expected; the scheduled check has not recorded it yet)', 'afriex-gateway-for-woocommerce' ),
				self::format_time( $expected )
			),
		);
	}

	/**
	 * The virtual account, for a Bank Transfer order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private static function bank_transfer_rows( WC_Order $order ): array {
		$rows = array();

		$account_id = Afriex_Order_Meta::get_payment_method_id( $order );

		if ( '' !== $account_id ) {
			$rows[ __( 'Afriex account id', 'afriex-gateway-for-woocommerce' ) ] = $account_id;
		}

		foreach ( Afriex_Order_Meta::get_payment_instructions( $order ) as $label => $value ) {
			$rows[ $label ] = $value;
		}

		$customer_id = Afriex_Order_Meta::get_customer_id( $order );

		if ( '' !== $customer_id ) {
			$rows[ __( 'Afriex customer id', 'afriex-gateway-for-woocommerce' ) ] = $customer_id;
		}

		if ( 'pool' === Afriex_Order_Meta::get_collection_method( $order ) ) {
			$rows[ __( 'Collection', 'afriex-gateway-for-woocommerce' ) ] = __( 'Shared (1.0 pool account)', 'afriex-gateway-for-woocommerce' );
		}

		$expires = Afriex_Order_Meta::get_expires_at( $order );

		if ( '' !== $account_id ) {
			// Said either way, because "no row" and "Afriex gave no expiry"
			// mean different things and only one of them is a fallback to the
			// store's own payment window (F33).
			$rows[ __( 'Account expires', 'afriex-gateway-for-woocommerce' ) ] = $expires > 0
				? self::format_time( $expires )
				: __( 'Afriex gave no expiry; the store payment window applies', 'afriex-gateway-for-woocommerce' );
		}

		// Every account this order has been given and replaced (F20). A
		// transfer into one of them still binds to this order and is flagged
		// rather than applied quietly, and the first question anybody asks
		// about such a flag is "which account was that?".
		$previous = Afriex_Order_Meta::get_previous_payment_method_ids( $order );

		if ( ! empty( $previous ) ) {
			$rows[ __( 'Replaced accounts', 'afriex-gateway-for-woocommerce' ) ] = implode( ', ', $previous );
		}

		return $rows;
	}

	/**
	 * Every Checkout session this order has ever asked for (plan 4.3).
	 *
	 * All of them, not just the latest: a shopper who pressed Pay twice has
	 * two, and a payment can arrive on either. The current one is marked, so
	 * the list reads as a history rather than a puzzle.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_checkout_sessions( WC_Order $order ): void {
		$rows = Afriex_Order_Meta::get_checkout_reference_rows( $order );

		if ( empty( $rows ) ) {
			echo '<p class="afriex-order-box__empty">' . esc_html__( 'No Afriex Checkout session has been created for this order.', 'afriex-gateway-for-woocommerce' ) . '</p>';
			return;
		}

		$current = Afriex_Order_Meta::get_current_checkout_reference( $order );

		echo '<h4 class="afriex-order-box__heading">' . esc_html__( 'Checkout sessions', 'afriex-gateway-for-woocommerce' ) . '</h4>';
		echo '<ul class="afriex-order-box__sessions">';

		foreach ( $rows as $reference => $row ) {
			$detail = array();

			if ( isset( $row['amount_minor'] ) && null !== $row['amount_minor'] ) {
				$detail[ __( 'Amount', 'afriex-gateway-for-woocommerce' ) ] = Afriex_Money::to_major_string( (int) $row['amount_minor'], (string) $row['currency'] ) . ' ' . (string) $row['currency'];
			}

			$detail[ __( 'Session id', 'afriex-gateway-for-woocommerce' ) ] = ! empty( $row['session_id'] )
				? (string) $row['session_id']
				: __( 'not reported yet', 'afriex-gateway-for-woocommerce' );

			$expires = Afriex_Checkout_Gateway::session_deadline( $row );

			$detail[ __( 'Expires', 'afriex-gateway-for-woocommerce' ) ] = empty( $row['expires_at'] )
				? sprintf(
					/* translators: %s: local date and time */
					__( '%s (assumed)', 'afriex-gateway-for-woocommerce' ),
					self::format_time( $expires )
				)
				: self::format_time( $expires );

			if ( ! empty( $row['channels'] ) && is_array( $row['channels'] ) ) {
				$detail[ __( 'Channels offered', 'afriex-gateway-for-woocommerce' ) ] = implode(
					', ',
					array_map( array( 'Afriex_Checkout_Gateway', 'channel_label' ), $row['channels'] )
				);
			}

			if ( ! empty( $row['created'] ) ) {
				$detail[ __( 'Created', 'afriex-gateway-for-woocommerce' ) ] = self::format_time( (int) $row['created'] );
			}

			printf(
				'<li class="afriex-order-box__session"><code>%1$s</code>%2$s',
				esc_html( (string) $reference ),
				$reference === $current
					? ' <em>' . esc_html__( '(current)', 'afriex-gateway-for-woocommerce' ) . '</em>'
					: ''
			);

			self::render_rows( $detail );

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * A stored UNIX time in the site's own timezone and format.
	 *
	 * @param int $timestamp UNIX time.
	 */
	private static function format_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * A sentence for one review reason code.
	 *
	 * Unknown codes are shown as they are rather than hidden: a reason this
	 * version does not know is still the reason somebody has to look.
	 *
	 * @param string $reason Reason code.
	 */
	private static function review_reason_label( string $reason ): string {
		$labels = array(
			'amount_mismatch'         => __( 'The amount Afriex reported does not match this order.', 'afriex-gateway-for-woocommerce' ),
			'currency_mismatch'       => __( 'Afriex reported this payment in a different currency from the order.', 'afriex-gateway-for-woocommerce' ),
			'order_total_mismatch'    => __( 'The payment matches what Afriex was asked to collect, but this order\'s total has changed since then.', 'afriex-gateway-for-woocommerce' ),
			'no_expectation'          => __( 'This plugin has no record of what this order was meant to be paid, so a payment cannot be checked against it.', 'afriex-gateway-for-woocommerce' ),
			'credentials_unavailable' => __( 'This order was taken with an Afriex API key the store no longer has, so its payments cannot be checked here. Restore that key, or check the order in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
			'amount_unreadable'       => __( 'Afriex reported an amount this plugin could not read.', 'afriex-gateway-for-woocommerce' ),
			'second_payment'          => __( 'A second payment arrived for an order that was already paid.', 'afriex-gateway-for-woocommerce' ),
			'paid_after_close'        => __( 'A payment arrived after this order was closed. Refund it, or reinstate the order by hand.', 'afriex-gateway-for-woocommerce' ),
			'ineligible_status'       => __( 'Afriex confirmed a payment while this order was in a status the plugin will not complete from.', 'afriex-gateway-for-woocommerce' ),
			'legacy_pool'             => __( 'This order was taken with the 1.0 shared account, so a deposit cannot be matched to it automatically.', 'afriex-gateway-for-woocommerce' ),
			'unresolved_at_deadline'  => __( 'The payment window closed while Afriex was reporting a payment this plugin could not apply.', 'afriex-gateway-for-woocommerce' ),
			'unreadable_at_deadline'  => __( 'The payment window closed, but Afriex\'s transaction list could not be read far enough back to be sure whether a payment arrived. The order has not been cancelled; check it in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' ),
			'account_ambiguous'       => __( 'The Afriex bank account this order was given is recorded against more than one order, so a deposit into it cannot be matched to one of them. Decide by hand which order it paid for.', 'afriex-gateway-for-woocommerce' ),
			'superseded_account'      => __( 'A payment arrived in an Afriex bank account that had already been replaced for this order, so it was not applied automatically.', 'afriex-gateway-for-woocommerce' ),
			'customer_mismatch'       => __( 'Afriex reported a payment that names a different Afriex customer from the one this order was set up with.', 'afriex-gateway-for-woocommerce' ),
		);

		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		// A post-payment status is flagged under its own name, lower-cased:
		// refunded, disputed, dispute_lost and so on.
		if ( Afriex_Status_Mapper::is_post_payment( strtoupper( $reason ) ) ) {
			return sprintf(
				/* translators: %s: an Afriex status such as REFUNDED or DISPUTE_LOST */
				__( 'Afriex reported %s for the payment that settled this order.', 'afriex-gateway-for-woocommerce' ),
				strtoupper( $reason )
			);
		}

		return $reason;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Check Afriex payment now (plan 4.3).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Offer the action on Afriex orders.
	 *
	 * @param array|mixed $actions Actions WooCommerce already offers.
	 * @return array|mixed
	 */
	public static function add_order_action( $actions ) {
		global $theorder;

		if ( ! is_array( $actions ) || ! $theorder instanceof WC_Order ) {
			return $actions;
		}

		if ( ! in_array( $theorder->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = __( 'Check Afriex payment now', 'afriex-gateway-for-woocommerce' );

		return $actions;
	}

	/**
	 * Ask Afriex what happened to this order, from the order screen.
	 *
	 * WooCommerce runs this from its own order-save handler, which has
	 * already checked the `update-order_{id}` nonce and `edit_shop_order`.
	 * The capability is checked again here because this method's safety
	 * should not depend on being reachable only one way.
	 *
	 * @param mixed $order The order WooCommerce hands over.
	 */
	public static function check_payment_now( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_order is WooCommerce's own meta capability, mapped in wc_shop_order_map_meta_cap().
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return;
		}

		// Both methods go through the sweep's own check, which is what plan
		// 4.3 asks for: "the same throttled, locked check as the sweep". A
		// Checkout order sent to Afriex_Checkout_Return::maybe_check() would
		// be asked about its CURRENT reference only, so a merchant pressing
		// the button on a retried order was told nothing had been confirmed
		// while a SUCCESS sat on an earlier one. check_now() polls every
		// reference, under the same throttle and the same lock.
		$message = self::sweep_result_message(
			Afriex_Scheduler::check_now( $order->get_id() ),
			$order->get_payment_method()
		);

		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $message, MINUTE_IN_SECONDS );

		Afriex_Logger::info(
			'Check Afriex payment now was used',
			array(
				'order_id' => $order->get_id(),
				'user_id'  => get_current_user_id(),
			)
		);
	}

	/**
	 * What to tell the admin after the check.
	 *
	 * @param array  $result         An outcome from Afriex_Scheduler::check_now().
	 * @param string $payment_method The order's payment method id.
	 */
	private static function sweep_result_message( array $result, string $payment_method ): string {
		$outcome = isset( $result['outcome'] ) ? (string) $result['outcome'] : '';
		$reason  = isset( $result['reason'] ) ? (string) $result['reason'] : '';

		if ( 'throttled' === $outcome || 'locked' === $outcome ) {
			return __( 'This order was checked with Afriex a moment ago, so it was not asked again. Try in fifteen seconds.', 'afriex-gateway-for-woocommerce' );
		}

		if ( 'error' === $outcome ) {
			return __( 'Afriex could not be reached. Nothing about this order was changed.', 'afriex-gateway-for-woocommerce' );
		}

		if ( 'skipped' === $outcome ) {
			return __( 'There is nothing to ask Afriex about this order.', 'afriex-gateway-for-woocommerce' );
		}

		// A Checkout order's check can settle the payment there and then, so
		// the two outcomes that mean "the order changed" are worth saying
		// plainly rather than sending the merchant to the notes.
		if ( Afriex_Settlement::OUTCOME_SETTLED === $outcome ) {
			return __( 'Afriex confirms this payment, and the order has been updated.', 'afriex-gateway-for-woocommerce' );
		}

		if ( Afriex_Settlement::OUTCOME_FLAGGED === $outcome ) {
			return __( 'Afriex reported something about this order that needs a person to look at it. Its notes say what.', 'afriex-gateway-for-woocommerce' );
		}

		// Past the payment window the check is the sweep's own expiry work
		// (plan 4.3, plan 9), so it can also end an order or hold it open. Both
		// are changes the person who pressed the button should be told about
		// rather than left to find in the notes.
		if ( Afriex_Settlement::OUTCOME_CANCELLED === $outcome ) {
			return __( 'The payment window for this order had closed and Afriex reported no successful payment, so the order has been cancelled.', 'afriex-gateway-for-woocommerce' );
		}

		if ( Afriex_Settlement::OUTCOME_EXTENDED === $outcome ) {
			// "Held a little longer" is true of a payment in flight. It is the
			// opposite of the truth when the order was held because Afriex's
			// transaction list could not be read to the end: nothing is in
			// flight, the answer simply could not be obtained.
			if ( 'listing_truncated' === $reason ) {
				return __( 'Afriex\'s list of transactions could not be read far enough back to be sure whether a payment arrived, so this order has been left as it is rather than cancelled. It will be tried again, and handed to you for review if it keeps happening. Check the order in your Afriex dashboard.', 'afriex-gateway-for-woocommerce' );
			}

			return __( 'The payment window for this order had closed, but Afriex still has a payment in flight, so the order is being held a little longer.', 'afriex-gateway-for-woocommerce' );
		}

		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID === $payment_method ) {
			return __( 'Afriex was asked about every payment attempt on this order. Anything it reported is in the order notes.', 'afriex-gateway-for-woocommerce' );
		}

		return __( 'Afriex was asked about this order. Anything it reported is in the order notes. Bank transfers are completed from Afriex\'s own notification, not from this check.', 'afriex-gateway-for-woocommerce' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Notices (plan 4.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Remember that Afriex refused a currency outright (plan 4.2).
	 *
	 * Called from the Checkout gateway when a session create comes back 422
	 * with something other than the generic VALIDATION_ERROR: that is Afriex
	 * saying it cannot collect this currency with any of the requested
	 * channels, which no retry will fix and which a store manager will
	 * otherwise only learn about from lost orders.
	 *
	 * @param string $currency ISO 4217 code the session asked for.
	 * @param string $code     The Afriex error code.
	 */
	public static function record_unsupported_currency( string $currency, string $code ): void {
		$currency = strtoupper( trim( $currency ) );

		if ( '' === $currency ) {
			return;
		}

		$notice = get_option( self::CURRENCY_NOTICE_OPTION, array() );
		$notice = is_array( $notice ) ? $notice : array();

		if ( isset( $notice[ $currency ] ) ) {
			// Once is the notice; the log has the rest.
			return;
		}

		$notice[ $currency ] = array(
			'code' => $code,
			'time' => time(),
		);

		update_option( self::CURRENCY_NOTICE_OPTION, $notice, false );
	}

	/**
	 * A session that succeeded proves the currency works after all.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function clear_unsupported_currency( string $currency ): void {
		$currency = strtoupper( trim( $currency ) );
		$notice   = get_option( self::CURRENCY_NOTICE_OPTION, array() );

		if ( ! is_array( $notice ) || ! isset( $notice[ $currency ] ) ) {
			return;
		}

		unset( $notice[ $currency ] );

		if ( empty( $notice ) ) {
			delete_option( self::CURRENCY_NOTICE_OPTION );
		} else {
			update_option( self::CURRENCY_NOTICE_OPTION, $notice, false );
		}
	}

	/**
	 * Print this class's notices.
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// The result of a Check-now press belongs on the order screen the
		// press came from, which is deliberately not one of the screens the
		// configuration notices appear on, so it is printed first and
		// without that test.
		self::render_check_result_notice();

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( $screen->id, self::NOTICE_SCREENS, true ) ) {
			return;
		}

		self::render_unsupported_currency_notice();
		self::render_duplicate_bank_transfer_notice();
	}

	/**
	 * The one-line result of the last Check-now press.
	 */
	private static function render_check_result_notice(): void {
		$key     = self::RESULT_TRANSIENT . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-info is-dismissible afriex-check-result"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Afriex refused the store's currency (plan 4.2).
	 */
	private static function render_unsupported_currency_notice(): void {
		$notice = get_option( self::CURRENCY_NOTICE_OPTION, array() );

		if ( ! is_array( $notice ) || empty( $notice ) ) {
			return;
		}

		$currencies = array_keys( $notice );

		printf(
			'<div class="notice notice-warning afriex-currency-notice"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated currency codes */
					_n(
						'Afriex refused a Checkout payment in %s because it cannot collect that currency with the payment options this store allows. Shoppers paying in it cannot complete an order until the allowed payment options or the store currency change.',
						'Afriex refused Checkout payments in these currencies because it cannot collect them with the payment options this store allows: %s. Shoppers paying in them cannot complete an order until the allowed payment options or the store currency change.',
						count( $currencies ),
						'afriex-gateway-for-woocommerce'
					),
					implode( ', ', $currencies )
				)
			),
			esc_url( self::dismiss_url( 'currency' ) ),
			esc_html__( 'Dismiss this notice', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Both methods are on and both end up showing a bank transfer.
	 *
	 * For NGN, Afriex documents one deposit rail: the virtual account
	 * (plan 2). So a store with both methods enabled offers its shopper the
	 * same bank transfer twice over, once on the store's own order-received
	 * page and once on Afriex's hosted page. That is a real choice a merchant
	 * might make, so this is a notice and not a rule; it exists because
	 * nothing else on the settings screens would ever say it.
	 */
	private static function render_duplicate_bank_transfer_notice(): void {
		if ( self::is_dismissed( self::NOTICE_DUPLICATE_BANK_TRANSFER ) ) {
			return;
		}

		if ( ! self::both_methods_offer_bank_transfer() ) {
			return;
		}

		printf(
			'<div class="notice notice-info afriex-duplicate-notice"><p>%1$s</p><p><a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: store currency code */
					__( 'Both Afriex payment methods are enabled, and Afriex collects %s by bank transfer, so shoppers are offered a bank transfer twice: Afriex Bank Transfer shows the account details on your order-received page, and Afriex Checkout shows them on Afriex\'s own page. Enable one, or remove Bank transfer from the Afriex Checkout payment options, unless you mean to offer both.', 'afriex-gateway-for-woocommerce' ),
					get_woocommerce_currency()
				)
			),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_CHECKOUT_GATEWAY_ID ) ),
			esc_html__( 'Afriex Checkout settings', 'afriex-gateway-for-woocommerce' ),
			esc_url( self::dismiss_url( self::NOTICE_DUPLICATE_BANK_TRANSFER ) ),
			esc_html__( 'Dismiss this notice', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Whether both methods are enabled and Checkout would show a bank
	 * transfer as well.
	 *
	 * The channels Afriex has actually offered for the currency are the
	 * evidence when the store has any; before the first session there are
	 * none, so the admin's own selection is all there is to go on.
	 */
	public static function both_methods_offer_bank_transfer(): bool {
		foreach ( Afriex_Payment_Gateway::GATEWAY_IDS as $id ) {
			$settings = get_option( 'woocommerce_' . $id . '_settings', array() );

			if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
				return false;
			}
		}

		$settings = get_option( 'woocommerce_' . AFRIEX_WC_CHECKOUT_GATEWAY_ID . '_settings', array() );
		$allowed  = Afriex_Checkout_Gateway::sanitize_channels(
			is_array( $settings ) && isset( $settings['channels'] ) ? $settings['channels'] : Afriex_Checkout_Gateway::CHANNELS
		);

		if ( empty( $allowed ) ) {
			$allowed = Afriex_Checkout_Gateway::CHANNELS;
		}

		$known = Afriex_Checkout_Gateway::known_channels( get_woocommerce_currency() );
		$offer = empty( $known ) ? $allowed : array_intersect( $allowed, $known );

		return in_array( 'VIRTUAL_BANK_ACCOUNT', $offer, true );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dismissal.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The nonce-protected link that dismisses one notice.
	 *
	 * @param string $key Notice key.
	 */
	private static function dismiss_url( string $key ): string {
		return wp_nonce_url(
			add_query_arg( 'afriex_wc_dismiss', $key ),
			'afriex_wc_dismiss_' . $key
		);
	}

	/**
	 * Whether a store manager has put this notice away.
	 *
	 * @param string $key Notice key.
	 */
	private static function is_dismissed( string $key ): bool {
		$dismissed = get_option( self::DISMISSED_OPTION, array() );

		return is_array( $dismissed ) && in_array( $key, $dismissed, true );
	}

	/**
	 * Act on a dismissal link.
	 */
	public static function handle_notice_dismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked below, before anything changes.
		$key = isset( $_GET['afriex_wc_dismiss'] ) ? sanitize_key( wp_unslash( $_GET['afriex_wc_dismiss'] ) ) : '';

		if ( ! in_array( $key, array( 'currency', self::NOTICE_DUPLICATE_BANK_TRANSFER ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'afriex_wc_dismiss_' . $key );

		if ( 'currency' === $key ) {
			// The record *is* the notice: a currency that is refused again
			// raises it again, which is what a store manager needs.
			delete_option( self::CURRENCY_NOTICE_OPTION );
		} else {
			$dismissed   = get_option( self::DISMISSED_OPTION, array() );
			$dismissed   = is_array( $dismissed ) ? $dismissed : array();
			$dismissed[] = $key;

			update_option( self::DISMISSED_OPTION, array_values( array_unique( $dismissed ) ), false );
		}

		wp_safe_redirect( remove_query_arg( array( 'afriex_wc_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
