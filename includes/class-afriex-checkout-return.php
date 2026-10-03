<?php
/**
 * Afriex Checkout: coming back from the hosted page.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The return handler, the status endpoint and the confirming panel (plan 5.2,
 * 5.3, 5.4, 13).
 *
 * Three rules shape this class.
 *
 * 1. **The redirect proves nothing.** Afriex documents no return parameters,
 *    and anybody can type a URL, so coming back from the hosted page only
 *    makes the plugin *ask Afriex* what happened. Every status change still
 *    goes through Afriex_Settlement, on a transaction fetched from Afriex.
 * 2. **One throttled check serves every page.** The return handler, the status
 *    endpoint and (later) the order action all call maybe_check(), which takes
 *    an atomic per-order marker and a site-wide budget from the ledger before
 *    it calls Afriex. Fifty parallel polls therefore cost at most a handful of
 *    upstream calls, and a shopper hammering reload costs nothing at all.
 * 3. **Nothing here belongs to a gateway.** It is registered from the plugin
 *    bootstrap, so an order placed before the merchant switched Afriex Checkout
 *    off still confirms itself when the shopper comes back (plan 4.2).
 */
class Afriex_Checkout_Return {

	/**
	 * The wc-api endpoint Afriex redirects the shopper to.
	 *
	 * WC_API lower-cases the query value before firing
	 * woocommerce_api_{endpoint}, so this constant must stay lower-case.
	 */
	public const RETURN_ENDPOINT = 'afriex_checkout_return';

	/**
	 * The status endpoint (plan 5.3).
	 */
	public const REST_NAMESPACE = 'afriex/v1';
	public const REST_ROUTE     = '/order-status';

	/**
	 * The only three words the status endpoint ever says.
	 */
	public const STATUS_PAID    = 'paid';
	public const STATUS_PENDING = 'pending';
	public const STATUS_FAILED  = 'failed';

	/**
	 * At most one upstream check per order per this many seconds (plan 5.4).
	 */
	public const THROTTLE_SECONDS = 15;

	/**
	 * The site-wide cap: 30 upstream checks a minute, across every order.
	 */
	public const GLOBAL_LIMIT  = 30;
	public const GLOBAL_WINDOW = 60;

	/**
	 * One HTTP call, 8 s, never retried: a shopper is watching (plan 5.2).
	 */
	/**
	 * How many failed-signature lines a minute the return endpoint may write.
	 *
	 * Enough that a store whose return URL base is misconfigured shows up at
	 * once, few enough that nobody can grow the log file at will.
	 */
	private const BAD_SIGNATURE_LOG_BURST = 5;

	private const CHECK_TIMEOUT = 8.0;

	/**
	 * The whole check's budget, so a slow Afriex cannot hold the page open
	 * for much longer than the one call it is allowed.
	 */
	private const CHECK_BUDGET = 12.0;

	/**
	 * The panel polls every 5 s for 2 minutes (plan 5.2).
	 */
	private const POLL_INTERVAL_MS = 5000;
	private const POLL_WINDOW_MS   = 120000;

	/**
	 * The panel's script handle and the element it writes into.
	 */
	private const SCRIPT_HANDLE = 'afriex-checkout-return';
	private const PANEL_ID      = 'afriex-checkout-status';

	/**
	 * The block whose output the panel is appended to in a block theme's
	 * Order Confirmation template.
	 */
	private const STATUS_BLOCK = 'woocommerce/order-confirmation-status';

	/**
	 * Orders already resolved for the status endpoint, by id *and* key.
	 *
	 * The permission callback and the route callback both need the order and
	 * there is no way to hand one to the other, so the look-up is memoised
	 * for the request. The key is part of the memo's own key because that
	 * look-up is an authorisation: remembering it by order id alone hands the
	 * order to any later look-up in the same request, whatever key it came
	 * with. One HTTP request carries one key today, so nothing does that —
	 * but an authorisation cached without its credential is a mistake waiting
	 * for the first caller that batches two.
	 *
	 * @var array<string, int>
	 */
	private static $status_orders = array();

	/**
	 * Whether the panel has already been printed on this page.
	 *
	 * A block theme's Order Confirmation template runs both paths: the status
	 * block, which this class filters, and the additional-information block,
	 * which fires woocommerce_thankyou_{method}. The panel is wanted once.
	 *
	 * @var bool
	 */
	private static $panel_printed = false;

	/**
	 * Hook everything up. Called from the plugin bootstrap, not a gateway.
	 */
	public static function register(): void {
		add_action( 'woocommerce_api_' . self::RETURN_ENDPOINT, array( __CLASS__, 'handle_return' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_thankyou_' . AFRIEX_WC_CHECKOUT_GATEWAY_ID, array( __CLASS__, 'render_thankyou_panel' ) );
		add_filter( 'render_block', array( __CLASS__, 'append_panel_to_status_block' ), 10, 2 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The return from Afriex (plan 5.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Afriex sent the shopper back: check with Afriex, then show the order.
	 *
	 * WC_API fires this from parse_request, long before template_redirect, so
	 * the check finishes before anything renders — including
	 * wc_clear_cart_after_payment() — and the order-received page, classic or
	 * block, is drawn from the status this call just established.
	 */
	public static function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Afriex cannot carry a WordPress nonce; the HMAC below is the check, and nothing here acts on the query beyond looking an order up.
		$reference = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		$signature = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = self::verified_order( $reference, $signature );

		if ( null === $order ) {
			// A forged, stale or truncated URL is not an error to explain: it
			// is a request that proves nothing, so it learns nothing. No
			// order is loaded, no check is made and no ledger row is taken.
			self::redirect( self::shop_home_url() );

			return;
		}

		self::maybe_check( $order, Afriex_Settlement::SOURCE_RETURN );

		self::redirect( $order->get_checkout_order_received_url() );
	}

	/**
	 * The order a signed return URL names, or null when it names none.
	 *
	 * The signature is checked before anything is read from the database, and
	 * with hash_equals(), so neither a forged reference nor the time this
	 * function takes tells an attacker anything (plan 13).
	 *
	 * @param string $reference Reference from the URL.
	 * @param string $signature HMAC from the URL.
	 */
	private static function verified_order( string $reference, string $signature ): ?WC_Order {
		if ( '' === $reference || '' === $signature ) {
			return null;
		}

		if ( ! hash_equals( Afriex_Checkout_Gateway::return_signature( $reference ), $signature ) ) {
			// This endpoint is public and unauthenticated, and `warning` is
			// deliberately never gated on the merchant's logging preference,
			// so an unthrottled line here lets anyone who can reach the site
			// fill a log file the merchant cannot switch off. A handful a
			// minute still makes a genuine misconfiguration obvious.
			if ( Afriex_Ledger::throttle( 'return:badsig', MINUTE_IN_SECONDS, self::BAD_SIGNATURE_LOG_BURST ) ) {
				Afriex_Logger::warning(
					'An Afriex Checkout return URL failed its signature check',
					array( 'reference' => $reference )
				);
			}

			return null;
		}

		// The id inside the reference is a hint, never the answer: the order
		// is the one that actually holds this reference, and the two must
		// agree (plan 6.2).
		$expected_id = Afriex_Order_Meta::parse_reference( $reference );

		if ( $expected_id <= 0 ) {
			return null;
		}

		$found = Afriex_Order_Meta::find_order_ids_by_checkout_reference( $reference, 2 );

		if ( 1 !== count( $found ) || $found[0] !== $expected_id ) {
			Afriex_Logger::warning(
				'An Afriex Checkout return URL matched no single order',
				array(
					'reference' => $reference,
					'matches'   => count( $found ),
				)
			);

			return null;
		}

		$order = wc_get_order( $expected_id );

		if ( ! $order instanceof WC_Order || AFRIEX_WC_CHECKOUT_GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Send the shopper on and stop. Same host only.
	 *
	 * @param string $url Where to go.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Where a return that proves nothing goes.
	 */
	private static function shop_home_url(): string {
		$shop = wc_get_page_permalink( 'shop' );

		return is_string( $shop ) && '' !== $shop ? $shop : home_url( '/' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The one throttled upstream check (plan 5.4).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Ask Afriex what happened to this order, at most once every 15 seconds.
	 *
	 * Shared by the return handler, the status endpoint and the order action,
	 * so all three cost the same and none of them can be used to make a store
	 * hammer Afriex. When the check is skipped — because it is throttled,
	 * because the site's budget is spent, or because another process holds the
	 * order lock — the local status is reported as it stands, which is exactly
	 * what plan 6.1 asks a page to do.
	 *
	 * @param WC_Order $order  Order to check.
	 * @param string   $source One of Afriex_Settlement's SOURCE_* constants.
	 * @return string paid, pending or failed.
	 */
	public static function maybe_check( WC_Order $order, string $source = Afriex_Settlement::SOURCE_STATUS ): string {
		$status = self::local_status( $order );

		// A paid order has nothing left to learn from a listing, and a
		// refund or a dispute is the webhook's business, not a page's.
		if ( self::STATUS_PAID === $status ) {
			return $status;
		}

		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID !== $order->get_payment_method() ) {
			return $status;
		}

		$reference = Afriex_Order_Meta::get_current_checkout_reference( $order );

		if ( '' === $reference ) {
			return $status;
		}

		// The per-order marker first: it is the one that stops a reload loop,
		// and losing it costs nothing because whoever won is doing this work.
		if ( ! Afriex_Ledger::throttle( 'check:order:' . $order->get_id(), self::THROTTLE_SECONDS ) ) {
			return $status;
		}

		if ( ! Afriex_Ledger::global_rate( 'checks', self::GLOBAL_LIMIT, self::GLOBAL_WINDOW ) ) {
			Afriex_Logger::info(
				'An Afriex Checkout status check was skipped: the site-wide check budget is spent',
				array( 'order_id' => $order->get_id() )
			);

			return $status;
		}

		// Timeout 0: a page never waits for a lock (plan 6.1). Whoever holds
		// it — a webhook, the sweep — is doing this work anyway.
		$lock = Afriex_Settlement::lock_key( $order->get_id() );

		if ( ! Afriex_Ledger::lock( $lock, 0.0, Afriex_Settlement::LOCK_TTL ) ) {
			return $status;
		}

		try {
			return self::check_locked( $order, $reference, $source );
		} finally {
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * List the current reference's transactions and settle what they say.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference The order's current reference.
	 * @param string   $source    Settlement source.
	 * @return string The local status afterwards.
	 */
	private static function check_locked( WC_Order $order, string $reference, string $source ): string {
		$client = Afriex_Settlement::client_for_order( $order );

		if ( ! $client->is_configured() ) {
			return self::local_status( $order );
		}

		$client->set_time_budget( self::CHECK_BUDGET );

		$result = $client->list_transactions(
			array( 'reference' => $reference ),
			Afriex_Api_Client::FOREGROUND_MAX_PAGES,
			array(
				'timeout' => self::CHECK_TIMEOUT,
				'retry'   => false,
			)
		);

		$client->set_time_budget( null );

		if ( is_wp_error( $result ) ) {
			// Nothing is concluded from a failed look-up: the webhook and the
			// sweep are both still coming.
			Afriex_Logger::info(
				'An Afriex Checkout status check could not reach Afriex',
				array(
					'order_id'  => $order->get_id(),
					'reference' => $reference,
					'status'    => Afriex_Settlement::error_status( $result ),
				)
			);

			return self::local_status( $order );
		}

		$transaction = self::pick_transaction( $result['data'], $reference );

		if ( null !== $transaction ) {
			$outcome = Afriex_Settlement::settle( $order->get_id(), $transaction, $source );

			if ( Afriex_Settlement::OUTCOME_ORDER_FAILED === $outcome['outcome'] ) {
				// The page itself says nothing about why: WooCommerce's own
				// failed-order message is shown there, and repeating it in
				// another notice would only make two of it (plan 5.2). The
				// reason belongs on the order, where the shopper can read it.
				self::add_failure_note( $order->get_id(), $transaction );
			}
		}

		// The order object this method was handed predates the lock, so the
		// answer is read from a freshly loaded one (F8).
		$fresh = wc_get_order( $order->get_id() );

		return $fresh instanceof WC_Order ? self::local_status( $fresh ) : self::local_status( $order );
	}

	/**
	 * The one transaction worth acting on, or null when there is none.
	 *
	 * A SUCCESS always wins, whenever it arrived: it is the only row that can
	 * complete the order. Otherwise the most recently updated row is used, so
	 * a terminal failure fails the order and a non-terminal status leaves its
	 * one deduplicated note.
	 *
	 * The rows are filtered again here rather than trusted, because Afriex may
	 * ignore a filter it does not know (plan 16, question 8).
	 *
	 * @param array  $rows      Rows as Afriex listed them.
	 * @param string $reference The reference they were asked for.
	 */
	private static function pick_transaction( array $rows, string $reference ): ?array {
		$latest = null;
		$stamp  = -1;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || Afriex_Settlement_Decision::reference( $row ) !== $reference ) {
				continue;
			}

			if ( Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $row ) ) ) {
				return $row;
			}

			$updated = isset( $row['updatedAt'] ) && is_scalar( $row['updatedAt'] )
				? (int) strtotime( (string) $row['updatedAt'] )
				: 0;

			if ( $updated >= $stamp ) {
				$stamp  = $updated;
				$latest = $row;
			}
		}

		return $latest;
	}

	/**
	 * Tell the shopper, on the order, why Afriex refused the payment.
	 *
	 * Deduplicated by transaction id, so a reload, a webhook and the sweep
	 * cannot make three of it.
	 *
	 * @param int   $order_id    Order id.
	 * @param array $transaction Transaction as Afriex returned it.
	 */
	private static function add_failure_note( int $order_id, array $transaction ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// The note and its deduplication key live with the settlement engine,
		// which now also writes them when it fails the order. Whichever of
		// the two arrives first adds the note and the other adds nothing.
		Afriex_Settlement::note_failure_reason( $order, $transaction );
	}

	/**
	 * What the store itself believes about this order, in one word.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function local_status( WC_Order $order ): string {
		if ( $order->is_paid() || null !== $order->get_date_paid() ) {
			return self::STATUS_PAID;
		}

		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			return self::STATUS_FAILED;
		}

		return self::STATUS_PENDING;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The status endpoint (plan 5.3).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register GET /wp-json/afriex/v1/order-status.
	 *
	 * Neither argument is declared required: a missing one would make
	 * WordPress answer 400 and say which, and every failure here answers the
	 * same 404 instead.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_status_request' ),
				'permission_callback' => array( __CLASS__, 'check_status_request' ),
				'args'                => array(
					'order' => array(
						'sanitize_callback' => 'absint',
					),
					'key'   => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * The order key is the permission (plan 5.3, 13).
	 *
	 * A wrong key, an order that does not exist, an order paid by another
	 * method and a request with no arguments at all are one answer: 404. The
	 * caller learns nothing it did not already know.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_status_request( WP_REST_Request $request ) {
		// Sent from here so that the 404s get them too: a permission callback
		// runs for every request to this route.
		self::send_no_store_headers();

		return null === self::status_order( $request ) ? self::not_found() : true;
	}

	/**
	 * Answer with one word and nothing else.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_status_request( WP_REST_Request $request ) {
		$order = self::status_order( $request );

		if ( null === $order ) {
			return self::not_found();
		}

		$response = new WP_REST_Response(
			array( 'status' => self::maybe_check( $order, Afriex_Settlement::SOURCE_STATUS ) ),
			200
		);

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * The order this request may see, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function status_order( WP_REST_Request $request ): ?WC_Order {
		$order_id = absint( $request->get_param( 'order' ) );
		$key      = (string) $request->get_param( 'key' );

		if ( $order_id <= 0 || '' === $key ) {
			return null;
		}

		$memo = $order_id . ':' . $key;

		// What is remembered is the DECISION — that this (order, key) pair
		// may see this order — and never the loaded object. This request's
		// own work settles the payment through a separately loaded order, so
		// a cached copy would be stale the moment it mattered and a second
		// look-up in the same process would report an order as unpaid after
		// paying it.
		if ( isset( self::$status_orders[ $memo ] ) ) {
			$allowed = wc_get_order( self::$status_orders[ $memo ] );

			return $allowed instanceof WC_Order ? $allowed : null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || AFRIEX_WC_CHECKOUT_GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}

		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}

		self::$status_orders[ $memo ] = $order->get_id();

		return $order;
	}

	/**
	 * The one answer every refusal gives.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			'afriex_order_status_not_found',
			__( 'Not found.', 'afriex-gateway-for-woocommerce' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Never cache an order's payment status, anywhere, for anyone.
	 */
	private static function send_no_store_headers(): void {
		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private' );
		}
	}

	/**
	 * The URL the panel polls.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function status_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'order' => $order->get_id(),
				'key'   => $order->get_order_key(),
			),
			rest_url( self::REST_NAMESPACE . self::REST_ROUTE )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * The confirming panel (plan 5.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The classic order-received template, and the block template's
	 * additional-information block, which replays the same hook.
	 *
	 * @param mixed $order_id Order id, as WooCommerce passes it.
	 */
	public static function render_thankyou_panel( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Only the elements a panel is made of; the markup below is built
		// here, so this is belt and braces rather than the real defence.
		echo wp_kses_post( self::panel_markup( $order ) );
	}

	/**
	 * The block theme's Order Confirmation template.
	 *
	 * The panel goes under the status block, where the shopper is already
	 * reading what happened to the order, rather than at the foot of the page
	 * where the legacy hooks land.
	 *
	 * @param string|mixed $block_content Rendered block HTML.
	 * @param array|mixed  $block         Parsed block.
	 * @return string|mixed
	 */
	public static function append_panel_to_status_block( $block_content, $block ) {
		if ( ! is_string( $block_content ) || ! is_array( $block ) ) {
			return $block_content;
		}

		if ( ! isset( $block['blockName'] ) || self::STATUS_BLOCK !== $block['blockName'] ) {
			return $block_content;
		}

		$order = self::confirmation_order();

		if ( null === $order ) {
			return $block_content;
		}

		return $block_content . self::panel_markup( $order );
	}

	/**
	 * The order the Order Confirmation page is showing, or null.
	 *
	 * The page is reached with the order key in the URL, exactly as the
	 * classic template is; a logged-in shopper reading their own order is
	 * allowed through without one.
	 */
	private static function confirmation_order(): ?WC_Order {
		$order_id = absint( get_query_var( 'order-received' ) );

		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the key WooCommerce itself put in this URL; nothing is changed.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return $order;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- view_order is WooCommerce's own meta capability, mapped in WC_Post_Types/wc_shop_order_map_meta_cap; it is the same check WooCommerce's own order-received template makes.
		return current_user_can( 'view_order', $order_id ) ? $order : null;
	}

	/**
	 * The panel, or an empty string when this order does not need one.
	 *
	 * A paid order gets WooCommerce's ordinary thank-you content, and a failed
	 * one gets WooCommerce's own failed-order message and Pay link; adding a
	 * second message to either would only say the same thing twice (plan 5.2).
	 *
	 * @param WC_Order $order Order.
	 */
	private static function panel_markup( WC_Order $order ): string {
		if ( self::$panel_printed ) {
			return '';
		}

		if ( AFRIEX_WC_CHECKOUT_GATEWAY_ID !== $order->get_payment_method() ) {
			return '';
		}

		if ( self::STATUS_PENDING !== self::local_status( $order ) ) {
			return '';
		}

		if ( '' === Afriex_Order_Meta::get_current_checkout_reference( $order ) ) {
			return '';
		}

		self::$panel_printed = true;

		self::enqueue_panel_script( $order );

		return sprintf(
			'<div class="woocommerce-info afriex-checkout-status" id="%1$s"><p class="afriex-checkout-status__message">%2$s</p></div>',
			esc_attr( self::PANEL_ID ),
			esc_html__( 'Confirming your payment with Afriex. This page updates itself, so there is no need to reload it.', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Register, configure and enqueue the polling script.
	 *
	 * The endpoint URL comes from rest_url() through inline data rather than
	 * being built in JavaScript, so a store on plain permalinks — where the
	 * REST root is /?rest_route=/ — works like any other (plan 5.3).
	 *
	 * @param WC_Order $order Order the panel is for.
	 */
	private static function enqueue_panel_script( WC_Order $order ): void {
		$script_path = 'assets/js/build/afriex-checkout-return.js';
		$asset_path  = AFRIEX_WC_PLUGIN_DIR . 'assets/js/build/afriex-checkout-return.asset.php';

		if ( ! file_exists( AFRIEX_WC_PLUGIN_DIR . $script_path ) ) {
			// The panel still says what is happening; it just will not
			// refresh itself. Enqueuing a missing file would only 404.
			return;
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			$asset = file_exists( $asset_path )
				? require $asset_path
				: array(
					'dependencies' => array(),
					'version'      => AFRIEX_WC_VERSION,
				);

			wp_register_script(
				self::SCRIPT_HANDLE,
				plugins_url( $script_path, AFRIEX_WC_PLUGIN_FILE ),
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.afriexCheckoutReturn = ' . wp_json_encode( self::panel_data( $order ) ) . ';',
			'before'
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * What the panel's script needs to know.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function panel_data( WC_Order $order ): array {
		return array(
			'statusUrl' => self::status_url( $order ),
			'panelId'   => self::PANEL_ID,
			'interval'  => (int) apply_filters( 'afriex_wc_checkout_poll_interval_ms', self::POLL_INTERVAL_MS, $order ),
			'window'    => (int) apply_filters( 'afriex_wc_checkout_poll_window_ms', self::POLL_WINDOW_MS, $order ),
			'i18n'      => array(
				'settled'  => __( 'Payment confirmed. Loading your order...', 'afriex-gateway-for-woocommerce' ),
				'failed'   => __( 'The payment did not go through. Loading your order...', 'afriex-gateway-for-woocommerce' ),
				'timedOut' => __( 'Afriex has not confirmed this payment yet. You can close this page: we will email you as soon as the payment is confirmed.', 'afriex-gateway-for-woocommerce' ),
			),
		);
	}
}
