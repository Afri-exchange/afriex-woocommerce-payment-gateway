<?php
/**
 * Afriex Checkout gateway.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Afriex Checkout: the shopper pays on an Afriex-hosted page and comes back.
 *
 * The gateway's process_payment() creates a Checkout Session and returns its
 * checkoutUrl as the redirect, so WooCommerce sends the shopper to Afriex. The order stays
 * `pending` and nothing about the redirect is treated as proof of payment: a
 * payment is only ever applied by Afriex_Settlement, from a transaction
 * fetched from Afriex (plan 5.2, 13).
 *
 * Nothing that settles a payment lives here. The webhook, the return handler
 * and the sweep act on any order whose payment method is `afriex_checkout`,
 * whatever this method's enabled flag says (plan 4.2), so switching the
 * method off stops new orders and nothing else.
 */
class Afriex_Checkout_Gateway extends Afriex_Payment_Gateway {

	/**
	 * Channels Afriex accepts on a session, in the order the admin sees them.
	 *
	 * The list is a cap, not a promise: Afriex drops the ones the store's
	 * currency cannot collect on and answers with what is left (plan 3).
	 *
	 * Defined once, in Afriex_Checkout_Fields, so that the unit tests can
	 * reach it without WooCommerce; kept here because this is where the rest
	 * of the plugin has always asked for it.
	 */
	public const CHANNELS = Afriex_Checkout_Fields::CHANNELS;

	/**
	 * Channels Afriex has actually offered, per currency (plan 4.2, 11).
	 */
	public const CHANNELS_OPTION = 'afriex_wc_channels';

	/**
	 * Afriex's own session minimum: 100 minor units, "one major unit" (plan 3).
	 */
	public const MIN_AMOUNT_MINOR = 100;

	/**
	 * How long an unpaid Checkout order waits, in minutes (plan 4.1).
	 */
	public const DEFAULT_PAYMENT_WINDOW_MINUTES = 60;

	/**
	 * The longest window an admin may set: 30 days.
	 *
	 * Public because Afriex_Scheduler reads the saved window straight from
	 * the option rather than building a gateway in a background job, and has
	 * to apply the same range to it (plan 6.4).
	 */
	public const MAX_PAYMENT_WINDOW_MINUTES = 43200;

	/**
	 * How long a session is assumed to live while Afriex has not said.
	 *
	 * The real lifetime arrives with CHECKOUT_SESSION.CREATED as `expiresAt`
	 * and is written to the reference's row; until then plan 5.1 step 2 uses
	 * 15 minutes, which is short enough that a shopper who abandons the
	 * hosted page is not stuck with a dead URL for long.
	 */
	public const DEFAULT_SESSION_LIFETIME = 900;

	/**
	 * Create timeout (plan 5.1 step 6). The POST is never retried (F39).
	 */
	private const CREATE_TIMEOUT = 15;

	/**
	 * Timeout for the reuse-or-refuse listing. Shorter than the create call:
	 * the shopper is waiting and this is only a look-up.
	 */
	private const LIST_TIMEOUT = 8;

	/**
	 * The whole reuse-or-refuse listing's share of the shopper's request.
	 *
	 * A payment starts with up to 3 seconds waiting for the order lock, then
	 * this listing, then the 15-second create. Ten seconds here keeps the
	 * worst case inside the 30-second max_execution_time a shared host
	 * typically allows (plan 8, F39).
	 */
	private const LIST_BUDGET = 10.0;

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = AFRIEX_WC_CHECKOUT_GATEWAY_ID;
		$this->method_title       = __( 'Afriex Checkout', 'afriex-gateway-for-woocommerce' );
		$this->method_description = __(
			'Send the shopper to an Afriex-hosted payment page, where they pay with whichever options Afriex supports for your store currency, and bring them back to your store. Afriex confirms the payment; the redirect on its own is never treated as proof.',
			'afriex-gateway-for-woocommerce'
		);
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * This method's own settings. The shared account fields are not here.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Enable/Disable', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Afriex Checkout', 'afriex-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Title', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'afriex-gateway-for-woocommerce' ),
				'default'     => __( 'Pay with Afriex', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'            => array(
				'title'   => __( 'Description', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( "You'll complete payment on Afriex's secure page.", 'afriex-gateway-for-woocommerce' ),
			),
			'channels'               => array(
				'title'             => __( 'Allowed payment options', 'afriex-gateway-for-woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 400px;',
				'options'           => self::channel_labels(),
				'default'           => self::CHANNELS,
				'description'       => __( 'Afriex shows only the options it supports for your store currency, so choosing one it cannot offer simply leaves it out. At least one option is required.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Choose at least one', 'afriex-gateway-for-woocommerce' ),
				),
			),
			'payment_window_minutes' => array(
				'title'             => __( 'Payment window (minutes)', 'afriex-gateway-for-woocommerce' ),
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_PAYMENT_WINDOW_MINUTES,
				'description'       => __( 'How long an unpaid order waits for the payment when Afriex does not say when the session expires. After that the order is cancelled, unless a payment is found.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => (string) self::MAX_PAYMENT_WINDOW_MINUTES,
					'step' => '1',
				),
			),
		);
	}

	/**
	 * The channel names an admin reads, keyed by Afriex's own value.
	 *
	 * @return array<string, string>
	 */
	public static function channel_labels(): array {
		return array(
			'CARD'                 => __( 'Card', 'afriex-gateway-for-woocommerce' ),
			'MOBILE_MONEY'         => __( 'Mobile money', 'afriex-gateway-for-woocommerce' ),
			'VIRTUAL_BANK_ACCOUNT' => __( 'Bank transfer', 'afriex-gateway-for-woocommerce' ),
		);
	}

	/**
	 * One channel's label, or the raw value when Afriex sends a new one.
	 *
	 * @param string $channel Afriex channel value.
	 */
	public static function channel_label( string $channel ): string {
		$labels = self::channel_labels();

		return isset( $labels[ $channel ] ) ? $labels[ $channel ] : $channel;
	}

	/**
	 * Only Afriex's own channel values, de-duplicated, in this class's order.
	 *
	 * @param mixed $value Anything a form or an API response offered.
	 * @return string[]
	 */
	public static function sanitize_channels( $value ): array {
		return Afriex_Checkout_Fields::sanitize_channels( $value );
	}

	/**
	 * At least one real channel, and not a set Afriex is known to refuse.
	 *
	 * An empty selection would 422 on every order; a selection disjoint from
	 * the channels Afriex has actually offered for the store currency would
	 * do the same, so the save is refused rather than stored (plan 4.2).
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string[]
	 * @throws Exception When nothing usable is selected.
	 */
	public function validate_channels_field( $key, $value ) {
		$chosen = self::sanitize_channels( $value );

		if ( empty( $chosen ) ) {
			throw new Exception(
				esc_html__( 'Choose at least one allowed payment option for Afriex Checkout. The previous value was kept.', 'afriex-gateway-for-woocommerce' )
			);
		}

		$known = self::known_channels( get_woocommerce_currency() );

		if ( ! empty( $known ) && empty( array_intersect( $chosen, $known ) ) ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: 1: store currency, 2: comma-separated payment option names */
						__( 'Afriex collects %1$s only with: %2$s. Include at least one of those, or every order would be refused. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						get_woocommerce_currency(),
						implode( ', ', array_map( array( __CLASS__, 'channel_label' ), $known ) )
					)
				)
			);
		}

		return $chosen;
	}

	/**
	 * A whole number of minutes between 1 and 43200.
	 *
	 * Refused rather than clamped, so the admin sees that the saved window is
	 * not what they typed.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 * @throws Exception When the value is not a whole number in range.
	 */
	public function validate_payment_window_minutes_field( $key, $value ) {
		$value = null === $value ? '' : trim( sanitize_text_field( wp_unslash( (string) $value ) ) );

		if ( '' === $value ) {
			return (string) self::DEFAULT_PAYMENT_WINDOW_MINUTES;
		}

		if ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > self::MAX_PAYMENT_WINDOW_MINUTES ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %d: maximum number of minutes */
						__( 'The payment window must be a whole number of minutes from 1 to %d. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						self::MAX_PAYMENT_WINDOW_MINUTES
					)
				)
			);
		}

		return (string) (int) $value;
	}

	/**
	 * The channels the admin allows, never empty.
	 *
	 * @return string[]
	 */
	public function get_channels(): array {
		$chosen = self::sanitize_channels( $this->get_option( 'channels', self::CHANNELS ) );

		// A store whose option was emptied by hand still has to be able to
		// take a payment: an empty list is a 422 on every order.
		return empty( $chosen ) ? self::CHANNELS : $chosen;
	}

	/**
	 * The payment window in minutes.
	 */
	public function get_payment_window_minutes(): int {
		$minutes = (int) $this->get_option( 'payment_window_minutes', (string) self::DEFAULT_PAYMENT_WINDOW_MINUTES );

		return $minutes >= 1 && $minutes <= self::MAX_PAYMENT_WINDOW_MINUTES
			? $minutes
			: self::DEFAULT_PAYMENT_WINDOW_MINUTES;
	}

	/*
	 * ---------------------------------------------------------------------
	 * What Afriex has told us about each currency (plan 4.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The channels Afriex has actually offered for a currency, or an empty
	 * array while the store has never created a session in it.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return string[]
	 */
	public static function known_channels( string $currency ): array {
		$currency = strtoupper( trim( $currency ) );
		$map      = get_option( self::CHANNELS_OPTION, array() );

		if ( ! is_array( $map ) || ! isset( $map[ $currency ] ) ) {
			return array();
		}

		return self::sanitize_channels( $map[ $currency ] );
	}

	/**
	 * Remember what Afriex offered for a currency.
	 *
	 * Only what a session response actually carried is stored, so the map is
	 * evidence rather than a guess. It is written only when it changes, to
	 * keep an option update off every order.
	 *
	 * @param string $currency ISO 4217 code.
	 * @param array  $channels Channels from the session response.
	 */
	public static function record_channels( string $currency, array $channels ): void {
		$currency = strtoupper( trim( $currency ) );
		$channels = self::sanitize_channels( $channels );

		if ( '' === $currency || empty( $channels ) ) {
			return;
		}

		$map = get_option( self::CHANNELS_OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		if ( isset( $map[ $currency ] ) && self::sanitize_channels( $map[ $currency ] ) === $channels ) {
			return;
		}

		$map[ $currency ] = $channels;

		update_option( self::CHANNELS_OPTION, $map, false );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Availability (plan 4.2).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Hidden from shoppers whenever a session could not be created.
	 *
	 * Each rule is something process_payment() would otherwise discover after
	 * the shopper had filled in the whole checkout form:
	 * - the base class's rules: disabled, no API key, a recorded 401/403;
	 * - a store whose return URL would not be HTTPS, which Afriex refuses;
	 * - a currency whose minor units Afriex has not defined (plan 16 q3);
	 * - a total below Afriex's minimum of 100 minor units.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		return empty( $this->availability_problems() );
	}

	/**
	 * Why this method cannot work here, as sentences an admin can act on.
	 *
	 * Plan 4.2's rule is "a method hides itself when it cannot work, and its
	 * settings page explains why". The base class explains only the missing
	 * account settings, so the three Checkout-specific rules are collected
	 * here and printed by render_setup_notice() below. The rules themselves
	 * live in one place, so the notice can never disagree with what the
	 * shopper sees.
	 *
	 * The base class's own rules are deliberately not repeated: they have
	 * their own notices already.
	 *
	 * @return string[]
	 */
	private function availability_problems(): array {
		$problems = array();

		if ( ! self::store_can_return() ) {
			$problems[] = sprintf(
				/* translators: %s: the site address Afriex would be given as the return URL */
				__( 'Afriex only sends shoppers back to an HTTPS address, and this store\'s is %s.', 'afriex-gateway-for-woocommerce' ),
				self::return_url_base()
			);
		}

		$context  = $this->context_money();
		$currency = $context['currency'];

		// Judged in the currency the total is actually denominated in: on the
		// order-pay page that is the order's, which a multi-currency plugin
		// can make different from the store's.
		if ( ! Afriex_Money::is_supported_for_checkout( $currency ) ) {
			$problems[] = sprintf(
				/* translators: %s: ISO 4217 currency code */
				__( 'Afriex Checkout works only with currencies that have two decimal places, and yours is %s.', 'afriex-gateway-for-woocommerce' ),
				$currency
			);

			// Without a usable exponent the minimum below cannot be judged at
			// all, so it is not guessed at.
			return $problems;
		}

		if ( null !== $context['total'] ) {
			$minor = Afriex_Money::to_minor( $context['total'], $currency );

			if ( null === $minor || $minor < self::MIN_AMOUNT_MINOR ) {
				$problems[] = sprintf(
					/* translators: 1: minimum order total, 2: currency code */
					__( 'Afriex will not take a payment below %1$s %2$s, and this order is below it.', 'afriex-gateway-for-woocommerce' ),
					Afriex_Money::to_major_string( self::MIN_AMOUNT_MINOR, $currency ),
					$currency
				);
			}
		}

		return $problems;
	}

	/**
	 * The Checkout rules on top of the shared ones (plan 4.2).
	 */
	protected function render_setup_notice(): void {
		parent::render_setup_notice();

		$this->render_availability_notice( $this->availability_problems() );
	}

	/**
	 * Whether the store can give Afriex an HTTPS redirectUrl.
	 *
	 * Afriex requires HTTPS, so on an HTTP store every session create would
	 * be a 422 (plan 3, 4.2).
	 */
	public static function store_can_return(): bool {
		return 'https' === strtolower( (string) wp_parse_url( self::return_url_base(), PHP_URL_SCHEME ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The return URL Afriex sends the shopper back to (plan 5.1 step 6).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The site URL the return handler lives on.
	 *
	 * Filterable so that a store whose WordPress home URL is HTTP while the
	 * site is actually served over HTTPS — a reverse proxy, or a local
	 * development site — can still be given a usable return URL. It is the
	 * one place both the availability rule and the redirectUrl read, so the
	 * two can never disagree.
	 */
	public static function return_url_base(): string {
		return (string) apply_filters( 'woocommerce_afriex_checkout_return_url_base', home_url( '/' ) );
	}

	/**
	 * The HMAC that proves a return URL was built by this store.
	 *
	 * The order key is never sent to Afriex (plan 13), so the reference plus
	 * this signature is all the return handler gets. wp_salt( 'auth' ) is
	 * per-site and not otherwise exposed.
	 *
	 * @param string $reference Merchant reference.
	 */
	public static function return_signature( string $reference ): string {
		return hash_hmac( 'sha256', 'afriex_checkout_return|' . $reference, wp_salt( 'auth' ) );
	}

	/**
	 * The plugin return URL for one reference.
	 *
	 * @param string $reference Merchant reference.
	 */
	public static function return_url( string $reference ): string {
		return add_query_arg(
			array(
				'wc-api' => 'afriex_checkout_return',
				'ref'    => rawurlencode( $reference ),
				'sig'    => self::return_signature( $reference ),
			),
			self::return_url_base()
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Taking a payment (plan 5.1).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Place the order and send the shopper to Afriex.
	 *
	 * Every failure throws, because a thrown Exception is the only failure
	 * report that shows correctly on classic checkout, the order-pay page and
	 * block checkout alike (F43). The message is always one a shopper may
	 * read: Afriex's friendlyMessage when it has one, otherwise a translated
	 * message chosen by code or status, never errorMessage or raw transport
	 * text (F42).
	 *
	 * @param int $order_id Order id.
	 * @return array
	 * @throws Exception With a shopper-safe message, on any failure.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			throw new Exception( esc_html__( 'We could not load your order. Please try again.', 'afriex-gateway-for-woocommerce' ) );
		}

		// Step 1. One session at a time per order: a double submit, a second
		// tab and a webhook settling the order all meet here.
		$lock = Afriex_Settlement::lock_key( $order->get_id() );

		if ( ! Afriex_Ledger::lock( $lock, Afriex_Settlement::lock_timeout( Afriex_Settlement::SOURCE_CHECKOUT ), Afriex_Settlement::LOCK_TTL ) ) {
			throw new Exception( esc_html__( 'Your payment is being set up, please wait a moment.', 'afriex-gateway-for-woocommerce' ) );
		}

		try {
			// Re-read the order now that the lock is held. The object loaded
			// above predates the lock, so a double submit or a second tab that
			// waited here would otherwise decide from stale meta: it would not
			// see the session the first request just created, and would mint
			// a second hosted session the shopper could also pay. Its save
			// would also write duplicate single-value meta rows.
			Afriex_Settlement::flush_order_cache( (int) $order->get_id() );
			$order = wc_get_order( $order->get_id() );

			if ( ! $order instanceof WC_Order ) {
				throw new Exception( esc_html__( 'We could not load your order. Please try again.', 'afriex-gateway-for-woocommerce' ) );
			}

			return $this->start_session( $order );
		} finally {
			// Step 10.
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * Steps 2 to 8, with the order lock held.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 * @throws Exception With a shopper-safe message.
	 */
	private function start_session( WC_Order $order ): array {
		$currency = strtoupper( $order->get_currency() );

		// The exponent-2 rule is a hard availability rule (plan 8), and
		// is_available() can only ever have judged the store currency. The
		// order carries its own, and a multi-currency plugin filtering
		// `woocommerce_currency` per request makes the two diverge — on the
		// order-pay page especially. Sending ISO minor units for a currency
		// Afriex scales differently could charge the shopper a hundredth of
		// the price, so the rule is asserted again here, against the currency
		// the money is actually in.
		if ( ! Afriex_Money::is_supported_for_checkout( $currency ) ) {
			Afriex_Logger::error(
				'Afriex Checkout was asked for a currency whose minor units Afriex has not defined',
				array(
					'order_id' => $order->get_id(),
					'currency' => $currency,
				)
			);

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		$expected = Afriex_Money::to_minor( $order->get_total(), $currency );

		if ( null === $expected ) {
			// A total that cannot be expressed in this currency's minor units
			// is a store misconfiguration, not something the shopper can fix.
			Afriex_Logger::error(
				'Order total could not be expressed in minor units',
				array(
					'order_id' => $order->get_id(),
					'currency' => $currency,
				)
			);

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		// Step 2: reuse an unexpired session, or refuse while Afriex still
		// has a payment in flight on the last one (F38).
		$current = Afriex_Order_Meta::get_current_checkout_reference( $order );

		if ( '' !== $current ) {
			$reuse = $this->reuse_or_refuse( $order, $current, $expected, $currency );

			if ( null !== $reuse ) {
				return $reuse;
			}
		}

		// Step 3.
		$customer = $this->build_customer( $order );

		if ( $expected < self::MIN_AMOUNT_MINOR ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: 1: minimum order total, 2: currency code */
						__( 'Afriex cannot take payments below %1$s %2$s. Please add to your order or choose another payment method.', 'afriex-gateway-for-woocommerce' ),
						Afriex_Money::to_major_string( self::MIN_AMOUNT_MINOR, $currency ),
						$currency
					)
				)
			);
		}

		// Steps 4 and 5: mint the reference and record what this session is
		// for before the call, so a session created behind a failed response
		// still maps to this order (plan 5.1 step 9).
		$environment = Afriex_Account_Settings::environment();
		$reference   = Afriex_Order_Meta::mint_reference( $order->get_id() );

		$order->update_meta_data( Afriex_Order_Meta::KEY_ENVIRONMENT, $environment );
		$order->update_meta_data( Afriex_Order_Meta::KEY_KEY_FINGERPRINT, Afriex_Account_Settings::key_fingerprint() );
		Afriex_Order_Meta::add_checkout_reference( $order, $reference, $expected, $currency, $environment );

		// Step 6.
		$return_url = self::return_url( $reference );

		if ( 'https' !== strtolower( (string) wp_parse_url( $return_url, PHP_URL_SCHEME ) ) ) {
			Afriex_Logger::error(
				'The Afriex return URL is not https, so no session can be created',
				array(
					'order_id' => $order->get_id(),
					'base'     => self::return_url_base(),
				)
			);

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		$client   = $this->build_api_client( $environment );
		$channels = $this->get_channels();

		$session = $client->create_checkout_session(
			array(
				'amount'            => $expected,
				'currency'          => $currency,
				'merchantReference' => $reference,
				'redirectUrl'       => $return_url,
				'customer'          => $customer,
				'channels'          => $channels,
				'metadata'          => $this->metadata( $order ),
			),
			array( 'timeout' => self::CREATE_TIMEOUT )
		);

		if ( is_wp_error( $session ) ) {
			Afriex_Logger::error(
				'Afriex Checkout session could not be created',
				array(
					'order_id'  => $order->get_id(),
					'reference' => $reference,
					'status'    => Afriex_Settlement::error_status( $session ),
					'error'     => $session->get_error_message(),
				)
			);

			self::record_currency_refusal( $session, $currency );

			// Tags stripped, entities left alone: block checkout escapes a
			// thrown message again on its way out (WooCommerce's
			// StoreApi CheckoutTrait), and an apostrophe escaped twice
			// reaches the shopper as `couldn&#039;t`. Escaping belongs at
			// output, and both checkouts already do it.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- wp_strip_all_tags() removes markup; WooCommerce escapes the message where it is printed.
			throw new Exception( wp_strip_all_tags( self::shopper_message( $session ) ) );
		}

		// Step 7.
		$checkout_url = $this->validate_checkout_url( $session, $order, $reference );

		// Step 8. The URL is written onto the reference's own row, because it
		// is that session's page and no other's: an order-level key would
		// outlive the reference it describes, and the next attempt would
		// reuse it (plan 5.1 step 2, plan 11). The order-level copy is kept
		// beside it purely for display.
		$offered = self::sanitize_channels( isset( $session['channels'] ) ? $session['channels'] : array() );

		$changes = array( 'url' => $checkout_url );

		if ( ! empty( $offered ) ) {
			$changes['channels'] = $offered;
		}

		Afriex_Order_Meta::update_checkout_reference( $order, $reference, $changes );

		$order->update_meta_data( Afriex_Order_Meta::KEY_CHECKOUT_URL, $checkout_url );
		$order->save();

		if ( ! empty( $offered ) ) {
			self::record_channels( $currency, $offered );
		}

		// A session Afriex accepted is the answer to any earlier refusal of
		// this currency, so the notice about it goes away on its own.
		Afriex_Admin::clear_unsupported_currency( $currency );

		$order->add_order_note(
			sprintf(
				/* translators: 1: merchant reference, 2: comma-separated payment option names */
				__( 'Afriex Checkout session started (%1$s; channels: %2$s).', 'afriex-gateway-for-woocommerce' ),
				$reference,
				implode( ', ', array_map( array( __CLASS__, 'channel_label' ), empty( $offered ) ? $channels : $offered ) )
			)
		);

		Afriex_Logger::info(
			'Afriex Checkout session created',
			array(
				'order_id'  => $order->get_id(),
				'reference' => $reference,
				'channels'  => $offered,
			)
		);

		// The order stays pending. WooCommerce reduces stock and empties the
		// cart when the payment completes, not here.
		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Step 2: reuse or refuse (plan 5.1, F38).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reuse the current session, settle a payment already made on it, or
	 * refuse; null means "mint a new session".
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference The order's current reference.
	 * @param int      $expected  What this attempt would ask for, in minor units.
	 * @param string   $currency  Store currency.
	 * @return array|null
	 * @throws Exception When a payment is already in progress, or Afriex
	 *                   cannot be asked what happened on the last session.
	 */
	private function reuse_or_refuse( WC_Order $order, string $reference, int $expected, string $currency ): ?array {
		$row = Afriex_Order_Meta::get_checkout_reference( $order, $reference );

		// The URL comes from the reference's own row, never from the
		// order-level key: that key holds whichever session was last created
		// successfully, so after a failed create it belongs to a different
		// reference than the current one, and reusing it would send the
		// shopper to an old — possibly differently priced — hosted page.
		$url = is_array( $row ) ? (string) $row['url'] : '';

		// A session Afriex has finished with is spent, whatever is left of
		// its lifetime: the hosted page will not take a second payment, so
		// sending the shopper back to it strands them (plan 3).
		$closed = is_array( $row ) && ! empty( $row['closed'] );

		if ( is_array( $row ) && '' !== $url && ! $closed
			&& (int) $row['amount_minor'] === $expected
			&& (string) $row['currency'] === $currency
			&& time() < self::session_deadline( $row )
		) {
			Afriex_Logger::info(
				'Reusing the Afriex Checkout session already issued for this order',
				array(
					'order_id'  => $order->get_id(),
					'reference' => $reference,
				)
			);

			return array(
				'result'   => 'success',
				'redirect' => $url,
			);
		}

		// The session has lapsed, or the basket is not what it was. Before
		// minting another one, find out what happened on this one: a second
		// session while a payment is in flight is how a shopper pays twice.
		$client = Afriex_Settlement::client_for_order( $order );

		// The key this order's earlier session was created with is gone, so
		// what happened on it cannot be found out. Minting another session
		// anyway is how a shopper pays twice, so this attempt stops here;
		// client_for_order() has flagged the order and said why.
		if ( ! $client->is_configured() ) {
			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		// The shopper is watching this happen, inside the order lock, and the
		// session create still has to follow it. Without a budget one page of
		// this listing can cost two 8-second attempts and a retry sleep
		// between them, which with the 15-second create is past the 30-second
		// max_execution_time most shared hosts run — and a PHP timeout here
		// is exactly what leaves a reference recorded with no session
		// (plan 8, F39).
		$client->set_time_budget( self::LIST_BUDGET );

		$result = $client->list_transactions(
			array( 'reference' => $reference ),
			Afriex_Api_Client::FOREGROUND_MAX_PAGES,
			array(
				'timeout' => self::LIST_TIMEOUT,
				'retry'   => false,
			)
		);

		$client->set_time_budget( null );

		if ( is_wp_error( $result ) ) {
			Afriex_Logger::error(
				'Could not check the previous Afriex Checkout session',
				array(
					'order_id'  => $order->get_id(),
					'reference' => $reference,
					'error'     => $result->get_error_message(),
				)
			);

			// See the create above: Afriex's own friendlyMessage, stripped of
			// markup but not entity-encoded, because block checkout encodes
			// it again on the way out.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- wp_strip_all_tags() removes markup; WooCommerce escapes the message where it is printed.
			throw new Exception( wp_strip_all_tags( self::shopper_message( $result ) ) );
		}

		$rows = array();

		foreach ( $result['data'] as $transaction ) {
			// Afriex may ignore a filter it does not know, so the rows are
			// filtered again here rather than trusted (plan 16 question 8).
			if ( is_array( $transaction ) && Afriex_Settlement_Decision::reference( $transaction ) === $reference ) {
				$rows[] = $transaction;
			}
		}

		foreach ( $rows as $transaction ) {
			if ( ! Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $transaction ) ) ) {
				continue;
			}

			// The shopper already paid on the old session. Apply it and send
			// them to the order-received page rather than charging them
			// again; the settlement engine decides what the payment means.
			Afriex_Settlement::settle( $order->get_id(), $transaction, Afriex_Settlement::SOURCE_CHECKOUT );

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		foreach ( $rows as $transaction ) {
			$status = Afriex_Settlement_Decision::status( $transaction );

			// Only a terminally failed attempt frees the order for another
			// session. Anything else — pending, in review, an Afriex status
			// this plugin has never seen — may still become a payment.
			if ( ! Afriex_Status_Mapper::is_terminal_failure( $status ) ) {
				Afriex_Logger::info(
					'Refused a new Afriex Checkout session: a payment is still in progress',
					array(
						'order_id'  => $order->get_id(),
						'reference' => $reference,
						'status'    => $status,
					)
				);

				throw new Exception(
					esc_html__( 'A payment for this order is already in progress. Please wait for it to finish.', 'afriex-gateway-for-woocommerce' )
				);
			}
		}

		return null;
	}

	/**
	 * When a session stops being usable.
	 *
	 * The real expiry arrives with CHECKOUT_SESSION.CREATED; until then the
	 * assumed lifetime stands in, filterable for a store whose Afriex account
	 * is configured with a different one (plan 5.1 step 2).
	 *
	 * @param array $row A reference row.
	 */
	public static function session_deadline( array $row ): int {
		if ( ! empty( $row['expires_at'] ) ) {
			$at = is_numeric( $row['expires_at'] )
				? (int) $row['expires_at']
				: (int) strtotime( (string) $row['expires_at'] );

			if ( $at > 0 ) {
				return $at;
			}
		}

		$created = isset( $row['created'] ) && is_numeric( $row['created'] ) ? (int) $row['created'] : 0;
		$life    = (int) apply_filters( 'woocommerce_afriex_checkout_session_lifetime', self::DEFAULT_SESSION_LIFETIME );

		return $created + ( $life > 0 ? $life : self::DEFAULT_SESSION_LIFETIME );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Step 3: the customer Afriex is told about.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Billing details in the shape the session endpoint wants.
	 *
	 * Each field is checked separately so the shopper is told which one to
	 * fix, rather than being handed Afriex's own 422 text.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 * @throws Exception When a field is missing or unusable.
	 */
	private function build_customer( WC_Order $order ): array {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		if ( '' === $name ) {
			throw new Exception( esc_html__( 'Please enter your billing first and last name.', 'afriex-gateway-for-woocommerce' ) );
		}

		$email = trim( $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			throw new Exception( esc_html__( 'Please enter a valid billing email address.', 'afriex-gateway-for-woocommerce' ) );
		}

		$country = strtoupper( trim( $order->get_billing_country() ) );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			throw new Exception( esc_html__( 'Please choose your billing country.', 'afriex-gateway-for-woocommerce' ) );
		}

		$phone = trim( $order->get_billing_phone() );

		if ( '' === $phone ) {
			throw new Exception( esc_html__( 'Please enter your billing phone number. Afriex needs it to confirm the payment.', 'afriex-gateway-for-woocommerce' ) );
		}

		// Afriex requires E.164 and refuses anything else, so the shopper's
		// national form is converted here rather than sent and bounced (F41).
		$e164 = Afriex_Phone::to_e164( $phone, $country );

		if ( null === $e164 ) {
			throw new Exception( esc_html__( 'Please enter your billing phone number in full, including the area code, so Afriex can confirm the payment.', 'afriex-gateway-for-woocommerce' ) );
		}

		return array(
			'name'        => $name,
			'email'       => $email,
			'phone'       => $e164,
			'countryCode' => $country,
		);
	}

	/**
	 * Metadata for the session: strings only, each capped at 1024 bytes.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function metadata( WC_Order $order ): array {
		$values = array(
			'wc_order_id'     => (string) $order->get_id(),
			'wc_order_number' => (string) $order->get_order_number(),
			'site_host'       => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'plugin_version'  => AFRIEX_WC_VERSION,
		);

		return Afriex_Checkout_Fields::metadata( $values );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Step 7: the URL the shopper is sent to.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The session's checkoutUrl, once it is one this store may redirect to.
	 *
	 * A redirect is a place the shopper will type card details, so the host
	 * is checked against an allow-list rather than trusted because it came
	 * over TLS from Afriex (plan 5.1 step 7, 13).
	 *
	 * @param array    $session   Session response.
	 * @param WC_Order $order     Order.
	 * @param string   $reference Merchant reference.
	 * @throws Exception When the URL is missing or not on the allow-list.
	 */
	private function validate_checkout_url( array $session, WC_Order $order, string $reference ): string {
		$url   = isset( $session['checkoutUrl'] ) && is_string( $session['checkoutUrl'] ) ? trim( $session['checkoutUrl'] ) : '';
		$parts = '' === $url ? false : wp_parse_url( $url );

		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host   = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if ( 'https' === $scheme && '' !== $host && self::is_allowed_checkout_host( $host ) ) {
			return $url;
		}

		Afriex_Logger::error(
			'Afriex returned a checkoutUrl this store will not redirect to',
			array(
				'order_id'  => $order->get_id(),
				'reference' => $reference,
				'url'       => $url,
			)
		);

		throw new Exception( esc_html( $this->unavailable_message() ) );
	}

	/**
	 * Whether a host may be redirected to for a hosted payment page.
	 *
	 * The filter is `woocommerce_afriex_checkout_url_hosts`: an array of
	 * host names, each matching itself and its subdomains. Afriex's own
	 * domain is always allowed.
	 *
	 * @param string $host Lower-case host from the checkoutUrl.
	 */
	public static function is_allowed_checkout_host( string $host ): bool {
		if ( Afriex_Api_Client::is_afriex_host( $host ) ) {
			return true;
		}

		$hosts = apply_filters( 'woocommerce_afriex_checkout_url_hosts', array( 'afriex.com' ) );

		if ( ! is_array( $hosts ) ) {
			return false;
		}

		foreach ( $hosts as $allowed ) {
			if ( ! is_scalar( $allowed ) ) {
				continue;
			}

			$allowed = strtolower( trim( rtrim( (string) $allowed, '.' ) ) );

			if ( '' === $allowed ) {
				continue;
			}

			if ( $host === $allowed || substr( $host, -( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
				return true;
			}
		}

		return false;
	}

	/*
	 * ---------------------------------------------------------------------
	 * What the shopper is told (plan 5.1 step 9, F42).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The text a shopper may see for a failed Afriex call.
	 *
	 * Only friendlyMessage is written for shoppers, and it is often an empty
	 * string; errorMessage and error are written for logs and
	 * can carry Afriex's internal wording, so they are never shown.
	 *
	 * @param WP_Error $error Error from Afriex_Api_Client.
	 */
	public static function shopper_message( WP_Error $error ): string {
		$data     = $error->get_error_data();
		$data     = is_array( $data ) ? $data : array();
		$friendly = isset( $data['friendly'] ) ? trim( (string) $data['friendly'] ) : '';

		if ( '' !== $friendly ) {
			return $friendly;
		}

		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		$code   = strtoupper( trim( (string) ( $data['code'] ?? '' ) ) );

		// 422 is either "none of the requested channels works for this
		// currency" or the generic VALIDATION_ERROR, and only the code tells
		// them apart (plan 3). The first is the store's problem, not the
		// shopper's, so they are told to pick another method rather than to
		// check their details.
		if ( '' !== $code && false !== strpos( $code, 'CHANNEL' ) ) {
			return __( 'Afriex cannot take this payment for our store currency right now. Please choose another payment method.', 'afriex-gateway-for-woocommerce' );
		}

		if ( 0 === $status || in_array( $status, array( 408, 429, 500, 502, 503, 504 ), true ) ) {
			return __( 'We could not reach the payment service. Please try again in a moment.', 'afriex-gateway-for-woocommerce' );
		}

		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return __( 'This store cannot take Afriex payments at the moment. Please choose another payment method.', 'afriex-gateway-for-woocommerce' );
		}

		if ( in_array( $status, array( 400, 422 ), true ) ) {
			return __( 'We could not start your payment. Please check your billing name, email, phone number and country, then try again.', 'afriex-gateway-for-woocommerce' );
		}

		return __( 'We could not start your payment. Please try again, or choose another payment method.', 'afriex-gateway-for-woocommerce' );
	}

	/**
	 * Record a 422 that means Afriex cannot collect this currency at all
	 * (plan 4.2).
	 *
	 * A 422 is either the generic VALIDATION_ERROR — something about this one
	 * order's customer details — or "none of the requested channels works for
	 * this currency", which is about the store and will happen to every
	 * shopper until a setting changes. Only the second is worth an admin
	 * notice, and only the code tells them apart (plan 3, plan 16 q4).
	 *
	 * @param WP_Error $error    Error from Afriex_Api_Client.
	 * @param string   $currency The currency the session asked for.
	 */
	private static function record_currency_refusal( WP_Error $error, string $currency ): void {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		if ( 422 !== ( isset( $data['status'] ) ? (int) $data['status'] : 0 ) ) {
			return;
		}

		$code = strtoupper( trim( (string) ( $data['code'] ?? '' ) ) );

		if ( '' === $code || 'VALIDATION_ERROR' === $code ) {
			return;
		}

		Afriex_Admin::record_unsupported_currency( $currency, $code );
	}

	/**
	 * The message for a failure the shopper can do nothing about.
	 */
	private function unavailable_message(): string {
		return __( 'We could not start your payment. Please choose another payment method, or contact us if the problem continues.', 'afriex-gateway-for-woocommerce' );
	}
}
