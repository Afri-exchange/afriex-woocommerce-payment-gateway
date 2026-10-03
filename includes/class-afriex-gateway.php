<?php
/**
 * Afriex Bank Transfer gateway.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bank Transfer: a dedicated Afriex virtual account per order, shown on the
 * order-received page and in emails.
 *
 * The class name and the gateway id 'afriex' are unchanged from 1.0 so that
 * existing orders, settings and meta keep working. The Afriex account
 * credentials are no longer fields of this gateway: they live in the shared
 * Afriex_Account_Settings section that Afriex_Payment_Gateway renders above
 * these fields. The 1.0 "Collection method" setting is gone because pool mode
 * is withdrawn (plan 9): every order gets a dedicated account.
 *
 * This is the single processing path for both checkouts: the block checkout
 * integration registers a client-side payment method whose name routes back to
 * this class's process_payment().
 */
class Afriex_Gateway extends Afriex_Payment_Gateway {

	/**
	 * Default payment window when Afriex returns no expiry (F32, F33).
	 */
	public const DEFAULT_PAYMENT_WINDOW_HOURS = 24;

	/**
	 * Longest payment window an admin may set: 30 days.
	 *
	 * Public because the sweep reads the saved window straight from the
	 * option rather than building a gateway, and has to apply the same range
	 * to it (plan 6.4).
	 */
	public const MAX_PAYMENT_WINDOW_HOURS = 720;

	/**
	 * The only currency Afriex issues customer virtual accounts in (F40).
	 *
	 * Anything else is refused upstream with
	 * UNSUPPORTED_VIRTUAL_ACCOUNT_CURRENCY, so the method hides itself rather
	 * than letting a shopper fill in the whole form first.
	 */
	public const SUPPORTED_CURRENCY = 'NGN';

	/**
	 * The largest virtual account Afriex will issue, in minor units (F40).
	 *
	 * ₦5,000,000. Held in minor units so the comparison is integer
	 * arithmetic, like every other amount comparison in the plugin.
	 */
	public const MAX_AMOUNT_MINOR = 500000000;

	/**
	 * Payment-method statuses a shopper can actually pay into (F48).
	 *
	 * An account that is not active cannot receive the transfer, so the order
	 * must not be moved to on-hold and told to wait for one.
	 */
	private const ACTIVE_STATUSES = array( 'active', 'enabled' );

	/**
	 * User meta prefix for the Afriex customer id, one per environment (F25).
	 *
	 * Sandbox and production are separate Afriex accounts with separate
	 * customer records, so an id learnt in one means nothing in the other.
	 */
	private const CUSTOMER_META_PREFIX = '_afriex_customer_id_';

	/**
	 * Set up the gateway and its display hooks.
	 */
	public function __construct() {
		$this->id                 = AFRIEX_WC_GATEWAY_ID;
		$this->method_title       = __( 'Afriex Bank Transfer', 'afriex-gateway-for-woocommerce' );
		$this->method_description = __(
			'Accept bank transfer payments through Afriex virtual accounts. Each order gets its own account, shown on the order-received page and in the order emails, and is confirmed automatically when payment is received.',
			'afriex-gateway-for-woocommerce'
		);
		$this->has_fields         = false;
		$this->icon               = self::icon_url();
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		// Show the account details on the order-received page and in emails.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment_instructions' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'render_instructions_in_email' ), 10, 3 );

		// Afriex will not open a virtual account without a phone number, and
		// a store that has set woocommerce_checkout_phone_field to hidden or
		// optional would otherwise send the shopper "Please enter your billing
		// phone number" with no box to type it in. While this method is on
		// offer at checkout the field is present, and it is required only
		// when the shopper chose this method; the refusal in validate_fields()
		// and process_payment() stays either way.
		add_filter( 'woocommerce_billing_fields', array( $this, 'require_billing_phone' ), 20 );
	}

	/**
	 * Keep the billing phone field on the form for this method, and required
	 * only when the shopper actually chose it.
	 *
	 * The store's own phone setting is left alone for everyone else. Making
	 * the field required whenever this method was merely enabled overrode a
	 * store's "optional" or "hidden" choice for every buyer, whatever they
	 * paid with, and even where Bank Transfer was not on offer at all (in
	 * Sandbox, outside NGN, above the ceiling). validate_fields() and
	 * process_payment() still refuse a missing phone for Bank Transfer.
	 *
	 * @param array|mixed $fields Billing fields WooCommerce is about to show.
	 * @return array|mixed
	 */
	public function require_billing_phone( $fields ) {
		if ( ! is_array( $fields ) || 'yes' !== $this->enabled || is_admin() ) {
			return $fields;
		}

		// My Account's address form uses this filter too; only checkout needs
		// the phone, and only while the method can actually be chosen.
		if ( ! ( function_exists( 'is_checkout' ) && is_checkout() ) && ! self::is_checkout_submission() ) {
			return $fields;
		}

		if ( ! $this->is_available() ) {
			return $fields;
		}

		if ( ! isset( $fields['billing_phone'] ) || ! is_array( $fields['billing_phone'] ) ) {
			// The store set woocommerce_checkout_phone_field to hidden, so
			// WooCommerce dropped the field altogether. Put it back rather
			// than refuse a checkout over a box that is not on the page.
			$fields['billing_phone'] = array(
				'label'        => __( 'Phone', 'afriex-gateway-for-woocommerce' ),
				'type'         => 'tel',
				'class'        => array( 'form-row-wide' ),
				'validate'     => array( 'phone' ),
				'autocomplete' => 'tel',
				'priority'     => 100,
			);
		}

		if ( self::is_checkout_submission() && AFRIEX_WC_GATEWAY_ID === self::submitted_payment_method() ) {
			$fields['billing_phone']['required'] = true;
		}

		return $fields;
	}

	/**
	 * Whether this request is WooCommerce processing a classic checkout form.
	 */
	private static function is_checkout_submission(): bool {
		return defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT;
	}

	/**
	 * The payment method the shopper chose on the submitted checkout form.
	 *
	 * Read only to decide whether the phone is required, the same value
	 * WooCommerce itself reads from the form it has just nonce-checked.
	 */
	private static function submitted_payment_method(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before validating fields; this only picks which fields are required.
		return isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
	}

	/**
	 * This method's own settings. The shared account fields are not here.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'              => array(
				'title'       => __( 'Enable/Disable', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Afriex Bank Transfer', 'afriex-gateway-for-woocommerce' ),
				'description' => sprintf(
					/* translators: 1: NGN, 2: the largest order total Afriex will issue an account for */
					__( 'Afriex issues virtual accounts in %1$s only, for orders up to %2$s, and only from a Production account. While any of those is not true the method is hidden at checkout and the reason is shown here.', 'afriex-gateway-for-woocommerce' ),
					self::SUPPORTED_CURRENCY,
					self::max_amount_text()
				),
				'default'     => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'afriex-gateway-for-woocommerce' ),
				'default'     => __( 'Bank Transfer', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'   => __( 'Description', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __(
					'Pay by bank transfer. You will receive account details after placing your order.',
					'afriex-gateway-for-woocommerce'
				),
			),
			'show_logo'            => self::show_logo_field(),
			'instructions'         => array(
				'title'       => __( 'Instructions', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown above the account details on the order-received page and in order emails, while the order is still waiting for the transfer.', 'afriex-gateway-for-woocommerce' ),
				'default'     => __(
					'Transfer the exact order total to the account below. Your order is confirmed automatically once the payment arrives.',
					'afriex-gateway-for-woocommerce'
				),
				'desc_tip'    => true,
			),
			'payment_window_hours' => array(
				'title'             => __( 'Payment window (hours)', 'afriex-gateway-for-woocommerce' ),
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_PAYMENT_WINDOW_HOURS,
				'description'       => __( 'How long an unpaid order waits for the transfer when Afriex does not say when the account expires. After that the order is cancelled, unless a payment is found.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => (string) self::MAX_PAYMENT_WINDOW_HOURS,
					'step' => '1',
				),
			),
		);
	}

	/**
	 * A whole number of hours between 1 and 720.
	 *
	 * An out-of-range value is refused rather than clamped, so the admin sees
	 * that the saved window is not what they typed.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 * @throws Exception When the value is not a whole number in range.
	 */
	public function validate_payment_window_hours_field( $key, $value ) {
		$value = null === $value ? '' : trim( sanitize_text_field( wp_unslash( (string) $value ) ) );

		if ( '' === $value ) {
			return (string) self::DEFAULT_PAYMENT_WINDOW_HOURS;
		}

		if ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > self::MAX_PAYMENT_WINDOW_HOURS ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %d: maximum number of hours */
						__( 'The payment window must be a whole number of hours from 1 to %d. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						self::MAX_PAYMENT_WINDOW_HOURS
					)
				)
			);
		}

		return (string) (int) $value;
	}

	/**
	 * The payment window in hours.
	 */
	public function get_payment_window_hours(): int {
		$hours = (int) $this->get_option( 'payment_window_hours', (string) self::DEFAULT_PAYMENT_WINDOW_HOURS );

		return $hours >= 1 && $hours <= self::MAX_PAYMENT_WINDOW_HOURS ? $hours : self::DEFAULT_PAYMENT_WINDOW_HOURS;
	}

	/**
	 * The URL Afriex should send webhooks to. Kept for 1.0 callers.
	 */
	public static function get_webhook_url(): string {
		return Afriex_Account_Settings::webhook_url();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Availability (plan 4.2; F40, F47).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Hidden from shoppers when a bank transfer cannot be collected here.
	 *
	 * On top of the base class's rules (disabled, no API key, a recorded
	 * 401): Afriex issues customer virtual accounts in naira only, up to
	 * ₦5,000,000, and only from a production account.
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
	 * settings page explains why", so the rules live in one place and the
	 * notice can never disagree with what the shopper sees. The base class's
	 * own rules are not repeated: they have their own notices.
	 *
	 * @return string[]
	 */
	private function availability_problems(): array {
		$problems = array();

		$context  = $this->context_money();
		$currency = $context['currency'];

		if ( self::SUPPORTED_CURRENCY !== $currency ) {
			$problems[] = sprintf(
				/* translators: 1: the currency the order or store is priced in, 2: NGN */
				__( 'Afriex issues virtual accounts in %2$s only, and this store prices in %1$s.', 'afriex-gateway-for-woocommerce' ),
				$currency,
				self::SUPPORTED_CURRENCY
			);
		} elseif ( null !== $context['total'] ) {
			$minor = Afriex_Money::to_minor( $context['total'], $currency );

			if ( null === $minor || $minor > self::MAX_AMOUNT_MINOR ) {
				$problems[] = sprintf(
					/* translators: %s: the largest order total Afriex will issue an account for */
					__( 'Afriex will not issue a virtual account for more than %s, and this order is above that.', 'afriex-gateway-for-woocommerce' ),
					self::max_amount_text()
				);
			}
		}

		if ( $this->is_in_test_mode() ) {
			// Afriex creates virtual and pool accounts in production only, so
			// the whole method is unusable in sandbox however well it is
			// configured (F47).
			$problems[] = __( 'Afriex creates virtual accounts from Production accounts only, so nobody can pay by bank transfer while the Afriex environment is set to Sandbox.', 'afriex-gateway-for-woocommerce' );
		}

		return $problems;
	}

	/**
	 * The Bank Transfer rules on top of the shared ones (plan 4.2).
	 */
	protected function render_setup_notice(): void {
		parent::render_setup_notice();

		$this->render_availability_notice( $this->availability_problems() );
	}

	/**
	 * The ceiling as a shopper would read it.
	 */
	private static function max_amount_text(): string {
		$major = (float) ( self::MAX_AMOUNT_MINOR / 100 );

		if ( ! function_exists( 'wc_price' ) ) {
			// The settings fields are built in the constructor, and a gateway
			// can be constructed before WooCommerce's formatting helpers are
			// loaded. A plain amount is better than a fatal error.
			return self::SUPPORTED_CURRENCY . ' ' . number_format( $major, 2 );
		}

		return Afriex_Order_Meta::format_amount( $major, self::SUPPORTED_CURRENCY );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Billing details (F41).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Classic checkout: refuse before the order is placed.
	 *
	 * Block checkout has no per-gateway validation step and never calls this,
	 * so process_payment() checks the same things again and throws the same
	 * sentences (F41, F43).
	 *
	 * The order-pay page calls it too, and there the posted form is the wrong
	 * place to look. WC_Form_Handler::pay_action() posts `payment_method` and
	 * a nonce and nothing else, and it copies only country, state, postcode
	 * and city from the order onto WC()->customer — never the name, the email
	 * or the phone. So a shopper paying an existing Bank Transfer order from
	 * the Pay link was told to fill in three fields that are not on the page,
	 * and pay_action() refuses to call process_payment() while any error
	 * notice stands. That is exactly the retry flow reuse_account() and the
	 * superseded-account handling were built for. When an order is in context,
	 * its own billing details are what process_payment() will use, so they are
	 * what gets validated.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$order  = self::order_in_context();
		$fields = $order instanceof WC_Order ? self::order_fields( $order ) : self::posted_fields();

		$errors = self::field_errors( $fields );

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		return empty( $errors );
	}

	/**
	 * The order the shopper is paying for, when this is the order-pay page.
	 *
	 * @return WC_Order|null
	 */
	private static function order_in_context(): ?WC_Order {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Form_Handler::pay_action() verifies woocommerce-pay before it reaches validate_fields(); this only decides WHICH billing details to validate, and the id is cast to an integer.
		$posted = isset( $_POST['woocommerce_pay'] ) ? absint( get_query_var( 'order-pay' ) ) : 0;

		if ( $posted <= 0 ) {
			$posted = is_wc_endpoint_url( 'order-pay' ) ? absint( get_query_var( 'order-pay' ) ) : 0;
		}

		if ( $posted <= 0 ) {
			return null;
		}

		$order = wc_get_order( $posted );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * The billing details Afriex needs, from the posted checkout form.
	 *
	 * Each field falls back to the customer session, which is what the form
	 * posts nothing for when a theme or an extension hides it.
	 *
	 * @return array<string, string|null>
	 */
	private static function posted_fields(): array {
		$customer = function_exists( 'WC' ) && WC()->customer ? WC()->customer : null;

		$read = static function ( $key, $fallback ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verifies its own nonce before it calls validate_fields(). Nothing is written or acted on here: the value is read only to tell the shopper which box to correct, and it is sanitised on the next line.
			if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
				return (string) $fallback;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
			return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		};

		return self::clean_fields(
			array(
				'first'   => $read( 'billing_first_name', $customer ? $customer->get_billing_first_name() : '' ),
				'last'    => $read( 'billing_last_name', $customer ? $customer->get_billing_last_name() : '' ),
				'email'   => $read( 'billing_email', $customer ? $customer->get_billing_email() : '' ),
				'country' => $read( 'billing_country', $customer ? $customer->get_billing_country() : '' ),
				'phone'   => $read( 'billing_phone', $customer ? $customer->get_billing_phone() : '' ),
			)
		);
	}

	/**
	 * The same details, from an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string|null>
	 */
	private static function order_fields( WC_Order $order ): array {
		return self::clean_fields(
			array(
				'first'   => (string) $order->get_billing_first_name(),
				'last'    => (string) $order->get_billing_last_name(),
				'email'   => (string) $order->get_billing_email(),
				'country' => (string) $order->get_billing_country(),
				'phone'   => (string) $order->get_billing_phone(),
			)
		);
	}

	/**
	 * Trim the raw values and derive the two Afriex actually wants.
	 *
	 * @param array $raw first, last, email, country, phone.
	 * @return array<string, string|null>
	 */
	private static function clean_fields( array $raw ): array {
		$fields = array(
			'first'   => trim( (string) ( $raw['first'] ?? '' ) ),
			'last'    => trim( (string) ( $raw['last'] ?? '' ) ),
			'email'   => trim( (string) ( $raw['email'] ?? '' ) ),
			'country' => strtoupper( trim( (string) ( $raw['country'] ?? '' ) ) ),
			'phone'   => trim( (string) ( $raw['phone'] ?? '' ) ),
		);

		$fields['name'] = trim( $fields['first'] . ' ' . $fields['last'] );
		$fields['e164'] = '' === $fields['phone'] ? null : Afriex_Phone::to_e164( $fields['phone'], $fields['country'] );

		return $fields;
	}

	/**
	 * Everything wrong with a set of billing details, one sentence each.
	 *
	 * Per field, so the shopper is told which box to fix rather than being
	 * shown a general apology (F41, F42), and in the order of the form.
	 *
	 * @param array $fields From clean_fields().
	 * @return array<string, string> field key => message.
	 */
	private static function field_errors( array $fields ): array {
		$errors = array();

		if ( '' === (string) ( $fields['name'] ?? '' ) ) {
			$errors['name'] = __( 'Please enter your billing first and last name. Afriex needs them to open the account for your transfer.', 'afriex-gateway-for-woocommerce' );
		}

		$email = (string) ( $fields['email'] ?? '' );

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid billing email address, so we can confirm your bank transfer.', 'afriex-gateway-for-woocommerce' );
		}

		$country = (string) ( $fields['country'] ?? '' );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$errors['country'] = __( 'Please choose your billing country.', 'afriex-gateway-for-woocommerce' );
		}

		if ( '' === (string) ( $fields['phone'] ?? '' ) ) {
			$errors['phone'] = __( 'Please enter your billing phone number. Afriex needs it to open the account for your transfer.', 'afriex-gateway-for-woocommerce' );
		} elseif ( null === ( $fields['e164'] ?? null ) ) {
			$errors['phone'] = __( 'Please check your billing phone number: enter it in full, including the area code, for example 0803 123 4567.', 'afriex-gateway-for-woocommerce' );
		}

		return $errors;
	}

	/**
	 * The Afriex customer payload for an order, or a refusal.
	 *
	 * Everything is validated here, before a single call is made, so a shopper
	 * whose phone number Afriex would reject is told what to fix instead of
	 * being shown Afriex's own validation error (F41, F42).
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 * @throws Exception With the first bad field's own message.
	 */
	private static function customer_payload( WC_Order $order ): array {
		$fields = self::order_fields( $order );
		$errors = self::field_errors( $fields );

		if ( ! empty( $errors ) ) {
			throw new Exception( esc_html( (string) reset( $errors ) ) );
		}

		return array(
			'fullName'    => (string) $fields['name'],
			'email'       => (string) $fields['email'],
			'phone'       => (string) $fields['e164'],
			'countryCode' => (string) $fields['country'],
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Taking the order (plan 9).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Called when the customer places the order.
	 *
	 * No payment happens here — this generates the account to pay into. The
	 * order goes to on-hold and the webhook is what promotes it, exactly as
	 * WooCommerce's own BACS gateway behaves for bank transfers.
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
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			throw new Exception( esc_html__( 'We could not load your order. Please try again.', 'afriex-gateway-for-woocommerce' ) );
		}

		$order_id = $order->get_id();

		// F38: one account at a time per order. A double submit, a second tab
		// and the webhook that settles the order all meet here, and without
		// this the second one opened a second Afriex account and emailed the
		// customer a second set of details.
		$lock = Afriex_Settlement::lock_key( $order_id );

		if ( ! Afriex_Ledger::lock( $lock, Afriex_Settlement::lock_timeout( Afriex_Settlement::SOURCE_CHECKOUT ), Afriex_Settlement::LOCK_TTL ) ) {
			throw new Exception( esc_html__( 'Your payment is being set up, please wait a moment.', 'afriex-gateway-for-woocommerce' ) );
		}

		try {
			// The copy loaded above predates the lock. Plan 6.1 is explicit:
			// clear WooCommerce's caches and load the order only once the
			// lock is held, so that what the reuse rule reads is the state
			// inside the lock and not a copy from before it (F8). This is
			// what makes a double submit see the first request's account.
			Afriex_Settlement::flush_order_cache( $order_id );

			$locked = wc_get_order( $order_id );

			if ( ! $locked instanceof WC_Order ) {
				throw new Exception( esc_html__( 'We could not load your order. Please try again.', 'afriex-gateway-for-woocommerce' ) );
			}

			return $this->issue_account( $locked );
		} finally {
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * Everything process_payment() does with the order lock held.
	 *
	 * @param WC_Order $order Order, loaded under the lock.
	 * @return array
	 * @throws Exception With a shopper-safe message, on any failure.
	 */
	private function issue_account( WC_Order $order ): array {
		$order_id = $order->get_id();

		// A webhook can settle an order between the shopper pressing Pay and
		// this request reaching the lock. Opening an account for money that
		// has already arrived would invite a second payment.
		if ( $order->is_paid() || '' !== Afriex_Order_Meta::get_settled_transaction_id( $order ) ) {
			Afriex_Logger::info( 'Bank transfer requested for an order that is already paid', array( 'order_id' => $order_id ) );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$this->assert_order_is_collectable( $order );

		// F38: an account this order already has, that is still open and was
		// issued for this same money, is the account to pay into. Minting a
		// second one strands whatever the shopper has already transferred.
		$reused = $this->reuse_account( $order );

		if ( null !== $reused ) {
			return $reused;
		}

		// Before any call: Afriex refuses a customer whose phone is not E.164,
		// and there is nothing to be learnt from being told so by a 400 (F41).
		$customer_data = self::customer_payload( $order );

		$client = $this->build_api_client();

		if ( ! $client->is_configured() ) {
			Afriex_Logger::error( 'Bank transfer attempted with no API key', array( 'order_id' => $order_id ) );

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		$customer_id = $this->resolve_customer_id( $order, $client, $customer_data );

		$account_result = $client->create_virtual_account(
			array(
				'currency'   => strtoupper( $order->get_currency() ),
				'customerId' => $customer_id,
				'amount'     => (float) $order->get_total(),
			)
		);

		if ( is_wp_error( $account_result ) ) {
			Afriex_Logger::error(
				'Account creation failed',
				array(
					'order_id' => $order_id,
					'error'    => $account_result->get_error_message(),
				)
			);

			throw new Exception( esc_html( self::shopper_message( $account_result ) ) );
		}

		// F48: an account with no number, or one that is not active, cannot
		// receive the transfer. Moving the order to on-hold and showing the
		// customer nothing to pay into is worse than refusing the checkout.
		$unusable = self::account_problem( $account_result );

		if ( null !== $unusable ) {
			Afriex_Logger::error(
				'Afriex returned a virtual account that cannot be paid into',
				array(
					'order_id' => $order_id,
					'problem'  => $unusable,
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: what was wrong with the account Afriex returned */
					__( 'Afriex returned a virtual account this order cannot use: %s. The checkout was refused and the order left unpaid.', 'afriex-gateway-for-woocommerce' ),
					$unusable
				)
			);
			$order->save();

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		// What this account was issued for (plan 11). Settlement compares a
		// deposit against this rather than against the live order total, so
		// an admin editing the total afterwards cannot turn an underpayment
		// into a completed order, or a correct payment into a mismatch. The
		// migration backfills the same two keys onto 1.0 orders.
		$expected_minor = Afriex_Money::to_minor( $order->get_total(), $order->get_currency() );

		if ( null !== $expected_minor ) {
			$order->update_meta_data( Afriex_Order_Meta::KEY_EXPECTED_AMOUNT_MINOR, $expected_minor );
			$order->update_meta_data( Afriex_Order_Meta::KEY_EXPECTED_CURRENCY, strtoupper( $order->get_currency() ) );
		} else {
			// A total that cannot be expressed in minor units is a store
			// misconfiguration, not a payment problem: record it for a person
			// rather than silently settling against whatever arrives.
			Afriex_Order_Meta::flag_for_review( $order, 'amount_unreadable' );
			Afriex_Logger::error(
				'Order total could not be expressed in minor units',
				array(
					'order_id' => $order_id,
					'currency' => $order->get_currency(),
				)
			);
		}

		// F20: the account this one replaces is remembered, and everything it
		// left behind — its transaction id, its expiry and the sweep deadline
		// built from it — is cleared, before the new account is written over
		// the top of it.
		$superseded = Afriex_Order_Meta::get_payment_method_id( $order );
		$new_id     = Afriex_Order_Meta::extract_payment_method_id( $account_result );

		if ( '' !== $superseded && $superseded !== $new_id ) {
			Afriex_Order_Meta::supersede_payment_method( $order, $superseded );

			Afriex_Logger::info(
				'A new Afriex account replaced this order\'s previous one',
				array(
					'order_id'   => $order_id,
					'superseded' => $superseded,
					'account'    => $new_id,
				)
			);
		}

		// The environment and the key this account was actually minted with,
		// written at the one point where the order provably belongs to them:
		// Afriex has just answered under this key. remember_customer_id()
		// writes the same two, but only when the customer id or the
		// environment changed — so a retry of an order that already carried a
		// customer id, after the store's key had been rotated, minted a live
		// account under the NEW key and left the OLD fingerprint on the order.
		// order_key_is_available() then said no for ever: the sweep dropped
		// the order, the webhook answered 500 until Afriex gave up, the expire
		// routine bailed at not_configured, and the shopper's transfer into an
		// account they had really been shown was silently lost (F50).
		$order->update_meta_data( Afriex_Order_Meta::KEY_ENVIRONMENT, $client->get_environment() );
		$order->update_meta_data( Afriex_Order_Meta::KEY_KEY_FINGERPRINT, Afriex_Account_Settings::key_fingerprint() );

		Afriex_Order_Meta::store_payment_details( $order, $account_result, $customer_id, 'dedicated' );

		if ( '' !== $superseded && $superseded !== $new_id ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: the Afriex account that was replaced, 2: the new Afriex account */
					__( 'A new Afriex bank account (%2$s) was issued for this order, replacing %1$s. A transfer into the old account is still matched to this order, and reported here for review rather than being lost.', 'afriex-gateway-for-woocommerce' ),
					$superseded,
					$new_id
				)
			);
		}

		$this->await_transfer( $order );

		Afriex_Logger::info( 'Afriex account issued for order', array( 'order_id' => $order_id ) );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * The account this order already has, when it can still be paid into.
	 *
	 * Three things have to hold (F38, plan 9): the account exists and has a
	 * number a person could transfer to, it has not passed its deadline, and
	 * it was issued for exactly the money this order now asks for. The last
	 * one matters because the expectation written beside the account is what
	 * settlement compares a deposit against: reusing an account issued for a
	 * cheaper cart would tell the shopper to transfer one amount and then
	 * refuse the deposit as a mismatch.
	 *
	 * @param WC_Order $order Order, loaded under the lock.
	 * @return array|null The process_payment() result, or null to mint.
	 */
	private function reuse_account( WC_Order $order ): ?array {
		$account_id = Afriex_Order_Meta::get_payment_method_id( $order );

		if ( '' === $account_id ) {
			return null;
		}

		if ( '' === trim( (string) $order->get_meta( Afriex_Order_Meta::KEY_ACCOUNT_NUMBER ) ) ) {
			// An account with no number is one the shopper was never shown
			// and never could be (F48). Nothing to reuse.
			return null;
		}

		$deadline = self::transfer_deadline( $order );

		if ( $deadline > 0 && time() >= $deadline ) {
			return null;
		}

		$expected = Afriex_Order_Meta::get_expected( $order );
		$minor    = Afriex_Money::to_minor( $order->get_total(), $order->get_currency() );

		if ( null === $expected['minor'] || null === $minor ) {
			return null;
		}

		if ( (int) $expected['minor'] !== (int) $minor
			|| strtoupper( (string) $expected['currency'] ) !== strtoupper( (string) $order->get_currency() )
		) {
			return null;
		}

		Afriex_Order_Meta::note_once(
			$order,
			'account_reused:' . $account_id,
			sprintf(
				/* translators: %s: the Afriex virtual account id */
				__( 'This order already has an open Afriex bank account (%s) for the same amount, so it was shown again rather than a second account being opened.', 'afriex-gateway-for-woocommerce' ),
				$account_id
			)
		);

		Afriex_Logger::info(
			'Reusing the Afriex account this order already has',
			array(
				'order_id' => $order->get_id(),
				'account'  => $account_id,
				'expires'  => $deadline,
			)
		);

		$this->await_transfer( $order );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Put the order where a bank transfer is expected, once.
	 *
	 * Shared by the mint and the reuse paths so a retry cannot skip the stock
	 * reduction or fire a second status transition. update_status() is called
	 * only when the status would actually change: a second submit on an order
	 * that is already on-hold must not send the customer a second email.
	 *
	 * @param WC_Order $order Order, loaded under the lock.
	 */
	private function await_transfer( WC_Order $order ): void {
		// Mark on-hold: we are awaiting an external bank transfer.
		// Filterable, mirroring the BACS gateway convention, so existing store
		// customisations behave the way merchants expect.
		$status = (string) apply_filters( 'afriex_wc_process_payment_order_status', 'on-hold', $order );

		if ( ! $order->has_status( $status ) ) {
			$order->update_status( $status, __( 'Awaiting Afriex bank transfer.', 'afriex-gateway-for-woocommerce' ) );
		} else {
			$order->save();
		}

		// Guarded by WooCommerce's own _order_stock_reduced marker, so a
		// retry does not take the stock twice.
		wc_reduce_stock_levels( $order->get_id() );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * The currency and ceiling rules, applied to this order's own money (F40).
	 *
	 * Availability can only ever have judged the store's currency and the
	 * cart; the order carries its own, and on the order-pay page, or with a
	 * multi-currency plugin filtering `woocommerce_currency` per request, the
	 * two diverge. Asking Afriex for an account it will refuse wastes the
	 * shopper's time, so the rules are asserted again against the money that
	 * is actually being collected.
	 *
	 * @param WC_Order $order Order.
	 * @throws Exception With a shopper-safe message.
	 */
	private function assert_order_is_collectable( WC_Order $order ): void {
		$currency = strtoupper( $order->get_currency() );

		if ( self::SUPPORTED_CURRENCY !== $currency ) {
			Afriex_Logger::error(
				'Bank transfer attempted in a currency Afriex does not issue accounts in',
				array(
					'order_id' => $order->get_id(),
					'currency' => $currency,
				)
			);

			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: NGN */
						__( 'Bank transfers can only be paid in %s. Please choose another payment method.', 'afriex-gateway-for-woocommerce' ),
						self::SUPPORTED_CURRENCY
					)
				)
			);
		}

		$minor = Afriex_Money::to_minor( $order->get_total(), $currency );

		if ( null === $minor ) {
			Afriex_Logger::error(
				'Order total could not be expressed in minor units',
				array(
					'order_id' => $order->get_id(),
					'currency' => $currency,
				)
			);

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		if ( $minor > self::MAX_AMOUNT_MINOR ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: the largest order total Afriex will issue an account for */
						__( 'Bank transfers are limited to %s per order. Please choose another payment method.', 'afriex-gateway-for-woocommerce' ),
						self::max_amount_text()
					)
				)
			);
		}
	}

	/**
	 * What is wrong with the account Afriex returned, or null when it is
	 * usable (F48).
	 *
	 * A missing status is not read as a bad status: an account that has just
	 * been created is active by definition, and refusing every checkout over a
	 * field the response may not carry would be worse than the check is good.
	 * A status that *is* there and is not an active one is refused.
	 *
	 * @param array $account Payment method payload from Afriex.
	 * @return string|null A sentence for the order note and the log.
	 */
	private static function account_problem( array $account ): ?string {
		if ( '' === Afriex_Order_Meta::extract_payment_method_id( $account ) ) {
			// Without a payment method id there is nothing for the webhook to
			// match the deposit against, so the order would strand on-hold.
			return __( 'it has no payment method id, so an incoming transfer could not be matched to this order', 'afriex-gateway-for-woocommerce' );
		}

		if ( '' === trim( (string) ( $account['accountNumber'] ?? '' ) ) ) {
			return __( 'it has no account number, so there would be nothing for the customer to transfer to', 'afriex-gateway-for-woocommerce' );
		}

		$status = strtolower( trim( (string) ( $account['status'] ?? '' ) ) );

		if ( '' !== $status && ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
			return sprintf(
				/* translators: %s: the account status Afriex returned */
				__( 'Afriex reported its status as "%s" rather than active, so it cannot receive a transfer', 'afriex-gateway-for-woocommerce' ),
				$status
			);
		}

		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reusing the Afriex customer (F25).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The Afriex customer id to issue this order's account against.
	 *
	 * Afriex refuses a second customer with the same email or phone, so a
	 * plugin that creates one per checkout locks every returning shopper out
	 * (F25). In order of preference: an id this order already has, one this
	 * WordPress user learnt in this environment, the one Afriex reports for
	 * the email address, and only then a new customer.
	 *
	 * The id is written to the order, and for a logged-in shopper to the user,
	 * as soon as it is known — before the account call, which is exactly the
	 * call whose timing out used to leave a first-time shopper unable to
	 * try again.
	 *
	 * @param WC_Order          $order    Order.
	 * @param Afriex_Api_Client $client   Client for the current environment.
	 * @param array             $customer Customer payload.
	 * @return string
	 * @throws Exception With a shopper-safe message.
	 */
	private function resolve_customer_id( WC_Order $order, Afriex_Api_Client $client, array $customer ): string {
		$environment = $client->get_environment();
		$email       = (string) $customer['email'];

		$known = $this->remembered_customer_id( $order, $environment );

		if ( '' !== $known ) {
			$this->remember_customer_id( $order, $environment, $known );

			return $known;
		}

		$found = $client->find_customer_by_email( $email );

		if ( is_wp_error( $found ) ) {
			// A look-up that fails is not a reason to refuse the order: the
			// create below gives the definitive answer, including the 409 that
			// carries the very id this look-up was after.
			Afriex_Logger::warning(
				'Afriex customer look-up failed; falling back to creating one',
				array(
					'order_id' => $order->get_id(),
					'error'    => $found->get_error_message(),
				)
			);
		} elseif ( Afriex_Customer::matches_email( $found, $email ) ) {
			$id = Afriex_Customer::extract_id( $found );

			Afriex_Logger::info(
				'Reusing the Afriex customer already registered for this email',
				array(
					'order_id' => $order->get_id(),
					'customer' => $id,
				)
			);

			$this->remember_customer_id( $order, $environment, $id );

			return $id;
		}

		$created = $client->create_customer( $customer );

		if ( is_wp_error( $created ) ) {
			$id = $this->adopt_conflicting_customer( $created, $client, $email, $order );

			$this->remember_customer_id( $order, $environment, $id );

			return $id;
		}

		$id = Afriex_Customer::extract_id( $created );

		if ( '' === $id ) {
			Afriex_Logger::error( 'Customer creation returned no id', array( 'order_id' => $order->get_id() ) );

			throw new Exception( esc_html( $this->unavailable_message() ) );
		}

		$this->remember_customer_id( $order, $environment, $id );

		return $id;
	}

	/**
	 * A 409's customer id, when this plugin may safely use it (F25).
	 *
	 * EMAIL_ALREADY_EXISTS names this shopper's own record, so it is adopted.
	 * PHONE_NUMBER_ALREADY_EXISTS names whoever registered that number, and
	 * adopting it unchecked would attach this order — and this shopper's money
	 * — to another person's Afriex customer, so the email address is confirmed
	 * with Afriex first and the shopper is told plainly when it does not match.
	 *
	 * @param WP_Error          $error  Error from create_customer().
	 * @param Afriex_Api_Client $client Client for the current environment.
	 * @param string            $email  The order's email address.
	 * @param WC_Order          $order  Order, for the log.
	 * @return string
	 * @throws Exception With a shopper-safe message.
	 */
	private function adopt_conflicting_customer( WP_Error $error, Afriex_Api_Client $client, string $email, WC_Order $order ): string {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		$code   = strtoupper( trim( (string) ( $data['code'] ?? '' ) ) );

		if ( 409 !== $status ) {
			Afriex_Logger::error(
				'Customer creation failed',
				array(
					'order_id' => $order->get_id(),
					'error'    => $error->get_error_message(),
				)
			);

			throw new Exception( esc_html( self::shopper_message( $error ) ) );
		}

		$conflict = Afriex_Customer::extract_id( isset( $data['details'] ) ? $data['details'] : array() );
		$decision = Afriex_Customer::conflict_decision( $code );

		if ( Afriex_Customer::ADOPT === $decision && '' !== $conflict ) {
			Afriex_Logger::info(
				'Adopting the Afriex customer the email conflict named',
				array(
					'order_id' => $order->get_id(),
					'customer' => $conflict,
				)
			);

			return $conflict;
		}

		// Either Afriex named no id, or the conflict is about the phone
		// number and the id it named is not this shopper's to use until the
		// email address proves otherwise. Both questions have one answer:
		// which customer does this email address belong to?
		$found = $client->find_customer_by_email( $email );
		$owned = ! is_wp_error( $found ) && Afriex_Customer::matches_email( $found, $email )
			? Afriex_Customer::extract_id( $found )
			: '';

		$adoptable = '' !== $owned
			&& ( Afriex_Customer::VERIFY_EMAIL !== $decision || '' === $conflict || $owned === $conflict );

		if ( $adoptable ) {
			Afriex_Logger::info(
				'Adopting the Afriex customer that owns this email address',
				array(
					'order_id' => $order->get_id(),
					'customer' => $owned,
					'code'     => $code,
				)
			);

			return $owned;
		}

		Afriex_Logger::warning(
			'Afriex refused the customer, and the id it named could not be used',
			array(
				'order_id' => $order->get_id(),
				'code'     => $code,
				'conflict' => $conflict,
			)
		);

		if ( Afriex_Customer::VERIFY_EMAIL === $decision ) {
			// Deliberately vague. Saying "that number is already registered
			// with Afriex under a different email address" turns a public
			// checkout into an oracle: anyone could type a phone number they
			// do not own and learn, from a message distinguishable from every
			// other refusal on the page, whether it belongs to an Afriex
			// customer. Nothing on this path is rate-limited — the ledger
			// throttle covers status checks, not process_payment() — so the
			// answer belongs where only the merchant reads it.
			$order->add_order_note(
				sprintf(
					/* translators: %s: the Afriex customer id the conflict named, or "none" */
					__( 'Afriex refused to set up this order: the billing phone number already belongs to a different Afriex customer (%s), and the billing email address does not confirm that it is this shopper\'s. The shopper was asked to check the number or choose another payment method.', 'afriex-gateway-for-woocommerce' ),
					'' === $conflict ? __( 'not named', 'afriex-gateway-for-woocommerce' ) : $conflict
				)
			);
			$order->save();

			throw new Exception(
				esc_html__(
					'We could not set up your bank transfer with this phone number. Please check it, or choose another payment method.',
					'afriex-gateway-for-woocommerce'
				)
			);
		}

		throw new Exception( esc_html( self::shopper_message( $error ) ) );
	}

	/**
	 * A customer id this order or this shopper already has for an environment.
	 *
	 * The order's own id wins, because it is the record of what this order was
	 * set up with. It is trusted only when the order was set up in the
	 * environment now in use: sandbox and production hold different customers,
	 * and an id from one names nothing in the other.
	 *
	 * Two further conditions, both about asking the right Afriex account.
	 *
	 * The order's id is trusted only while the store still holds the key the
	 * order was taken with. Every other look-up for such an order is already
	 * refused — Afriex_Settlement::client_for_order() hands back an
	 * unconfigured client — yet this one cheerfully returned an id belonging
	 * to the previous Afriex account, and issue_account() would then post it
	 * to create_virtual_account() under the CURRENT key, where it names
	 * nothing.
	 *
	 * The shopper's cached id is kept per key fingerprint and per email. Per
	 * key, because user meta is network-global on multisite and two stores in
	 * one network with different Afriex accounts would otherwise share one
	 * cache. Per email, because the billing address is unverified: a shopper
	 * who once checked out with somebody else's email address (a typo is
	 * enough) had their WordPress account bound to that third party's Afriex
	 * customer for ever, with no screen that shows or clears it.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $environment Environment.
	 */
	private function remembered_customer_id( WC_Order $order, string $environment ): string {
		$on_order = Afriex_Order_Meta::get_customer_id( $order );

		if ( '' !== $on_order && Afriex_Settlement::order_key_is_available( $order ) ) {
			$order_environment = Afriex_Order_Meta::get_environment( $order );

			if ( '' === $order_environment || $order_environment === $environment ) {
				return $on_order;
			}
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id <= 0 ) {
			return '';
		}

		$cache = self::customer_cache( $user_id, $environment );
		$slot  = Afriex_Account_Settings::key_fingerprint();
		$email = strtolower( trim( (string) $order->get_billing_email() ) );

		if ( ! isset( $cache[ $slot ] ) || ! is_array( $cache[ $slot ] ) ) {
			return '';
		}

		$remembered = (string) ( $cache[ $slot ]['id'] ?? '' );
		$learnt_for = strtolower( (string) ( $cache[ $slot ]['email'] ?? '' ) );

		if ( '' === $remembered || '' === $learnt_for || $learnt_for !== $email ) {
			return '';
		}

		return $remembered;
	}

	/**
	 * Remember the id on the order, and on the shopper's account (F25).
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $environment Environment.
	 * @param string   $customer_id Afriex customer id.
	 */
	private function remember_customer_id( WC_Order $order, string $environment, string $customer_id ): void {
		if ( '' === $customer_id ) {
			return;
		}

		// Written whether or not the id changed. The environment and the key
		// fingerprint describe the Afriex account this order is being set up
		// against, and they were previously written only inside the "did the
		// id change?" branch — so a retry after a key rotation left the old
		// fingerprint on an order that had just been given an account under
		// the new key (F50).
		$order->update_meta_data( Afriex_Order_Meta::KEY_CUSTOMER_ID, $customer_id );
		$order->update_meta_data( Afriex_Order_Meta::KEY_ENVIRONMENT, $environment );
		$order->update_meta_data( Afriex_Order_Meta::KEY_KEY_FINGERPRINT, Afriex_Account_Settings::key_fingerprint() );
		$order->save();

		$user_id = (int) $order->get_customer_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$cache = self::customer_cache( $user_id, $environment );

		$cache[ Afriex_Account_Settings::key_fingerprint() ] = array(
			'id'    => $customer_id,
			'email' => strtolower( trim( (string) $order->get_billing_email() ) ),
		);

		update_user_meta( $user_id, self::customer_meta_key( $environment ), wp_json_encode( $cache ) );
	}

	/**
	 * A shopper's cached Afriex customer ids for one environment.
	 *
	 * The stored value is a JSON map, {fingerprint: {id, email}}. A 1.1
	 * development build wrote a bare id string here; it carries neither the
	 * key it was learnt under nor the address it was learnt for, so it is
	 * ignored rather than trusted — the look-up that replaces it costs one
	 * GET, once.
	 *
	 * @param int    $user_id     WordPress user.
	 * @param string $environment Environment.
	 * @return array<string, array>
	 */
	private static function customer_cache( int $user_id, string $environment ): array {
		$raw = trim( (string) get_user_meta( $user_id, self::customer_meta_key( $environment ), true ) );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The user meta key holding a shopper's customer ids for one environment.
	 *
	 * Public so that uninstall and any future privacy eraser can name the
	 * same keys the gateway writes.
	 *
	 * @param string $environment Environment.
	 */
	public static function customer_meta_key( string $environment ): string {
		return self::CUSTOMER_META_PREFIX . sanitize_key( $environment );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Messages (F42).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The text a shopper may see for a failed Afriex call.
	 *
	 * The WP_Error message is written for the log and can carry Afriex's
	 * internal wording (F42). Only details.friendlyMessage is meant for
	 * shoppers, and it is often empty, so a translated message chosen by the
	 * Afriex code, and then by the HTTP status, stands in for it.
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

		// Afriex names the currency refusal, and it is the store's problem
		// rather than anything the shopper typed or can correct.
		if ( '' !== $code && false !== strpos( $code, 'CURRENCY' ) ) {
			return __( 'We cannot take a bank transfer for this order. Please choose another payment method.', 'afriex-gateway-for-woocommerce' );
		}

		if ( 0 === $status || in_array( $status, array( 408, 429, 500, 502, 503, 504 ), true ) ) {
			return __( 'We could not reach the payment service. Please try again in a moment.', 'afriex-gateway-for-woocommerce' );
		}

		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return __( 'This store cannot take bank transfers at the moment. Please choose another payment method.', 'afriex-gateway-for-woocommerce' );
		}

		if ( in_array( $status, array( 400, 422 ), true ) ) {
			return __( 'We could not set up your bank transfer. Please check your billing name, email, phone number and country, then try again.', 'afriex-gateway-for-woocommerce' );
		}

		return __( 'We could not set up your bank transfer. Please try again, or choose another payment method.', 'afriex-gateway-for-woocommerce' );
	}

	/**
	 * The message for a failure the shopper can do nothing about.
	 */
	private function unavailable_message(): string {
		return __( 'We could not set up your bank transfer just now. Please try again, or choose another payment method.', 'afriex-gateway-for-woocommerce' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The account details (F45, F89).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Account details on the order-received page.
	 *
	 * @param int $order_id Order id.
	 */
	public function render_payment_instructions( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->instructions_are_current( $order ) ) {
			$this->render_closed_window_markup( $order );
			return;
		}

		$this->render_instructions_markup( $order );
	}

	/**
	 * Account details in order emails.
	 *
	 * Only in emails to the customer, and only while the order is still
	 * waiting for this transfer: there is no reason to repeat bank details on
	 * an order that is paid, failed or past its account's expiry.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether this email goes to the admin.
	 * @param bool     $plain_text    Whether this is the plain-text email.
	 */
	public function render_instructions_in_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof WC_Order || $sent_to_admin ) {
			return;
		}

		if ( ! $this->instructions_are_current( $order ) ) {
			$this->render_closed_window_markup( $order, (bool) $plain_text );
			return;
		}

		$this->render_instructions_markup( $order, (bool) $plain_text );
	}

	/**
	 * A sentence for an order whose payment window has closed but which is
	 * still on-hold.
	 *
	 * F45 asks for the invitation to transfer to stop once the account has
	 * expired, and it does. It does not ask for silence, and silence is what
	 * this was: the order stays on-hold for the sweep's ten minutes of grace
	 * plus up to the next five-minute run, and a shopper reloading their
	 * order-received page in that window saw an on-hold order with no payment
	 * details and no explanation. That is what sends people to support.
	 *
	 * The account details themselves stay hidden, which is the part F45 is
	 * about.
	 *
	 * @param WC_Order $order      Order.
	 * @param bool     $plain_text Render without markup.
	 */
	private function render_closed_window_markup( WC_Order $order, bool $plain_text = false ): void {
		if ( $this->id !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		if ( $order->is_paid() || '' !== Afriex_Order_Meta::get_settled_transaction_id( $order ) ) {
			return;
		}

		if ( ! $this->account_has_expired( $order ) ) {
			// Not expiry, so something else already decided there is nothing
			// to show. Saying anything here would be guessing at what.
			return;
		}

		$heading = __( 'The payment window for this order has closed', 'afriex-gateway-for-woocommerce' );
		$body    = __( 'The bank account issued for this order is no longer accepting transfers, so the details have been removed. You have not been charged. Please place the order again, or contact us if you have already sent the transfer.', 'afriex-gateway-for-woocommerce' );

		if ( $plain_text ) {
			echo esc_html( $heading ) . "\n";
			echo esc_html( $body ) . "\n\n";
			return;
		}

		echo '<section class="woocommerce-afriex-instructions woocommerce-afriex-window-closed">';
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<p>' . esc_html( $body ) . '</p>';
		echo '</section>';
	}

	/**
	 * Whether this order is still waiting for this transfer (F45).
	 *
	 * Four things have to be true: the order is this method's, it is on-hold,
	 * nothing has settled it, and the account has not expired. An order that
	 * is paid, cancelled or failed must not ask the customer to transfer
	 * money, and an expired account could not receive it if they did.
	 *
	 * @param WC_Order $order Order.
	 */
	public function instructions_are_current( WC_Order $order ): bool {
		if ( $this->id !== $order->get_payment_method() ) {
			return false;
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			return false;
		}

		if ( $order->is_paid() || '' !== Afriex_Order_Meta::get_settled_transaction_id( $order ) ) {
			return false;
		}

		return ! $this->account_has_expired( $order );
	}

	/**
	 * When this order's transfer has to have arrived by (F32, F33).
	 *
	 * The virtual account's own expiry when Afriex gave one, because no
	 * transfer can arrive after it; otherwise the order's creation plus the
	 * admin's payment window. The same two terms the sweep's deadline is
	 * built from (plan 6.3), and the payment window is read from the saved
	 * option through the scheduler rather than from a gateway instance, so
	 * the shopper's page, the admin's order screen and the sweep cannot
	 * disagree about when the window closes.
	 *
	 * Public and static because the order screen and the reuse rule both need
	 * it, and neither wants to build a checkout gateway to ask.
	 *
	 * The sweep's ten minutes of grace are deliberately not included. That
	 * grace is for money that left the shopper before the deadline and lands
	 * just after it; this is the moment the shopper is being invited to send
	 * money, and there is no point inviting a transfer into a closed account.
	 *
	 * @param WC_Order $order Order.
	 * @return int UNIX time, or 0 when the order has no creation date.
	 */
	public static function transfer_deadline( WC_Order $order ): int {
		$expires = Afriex_Order_Meta::get_expires_at( $order );

		if ( $expires > 0 ) {
			return $expires;
		}

		$created = $order->get_date_created();

		if ( ! $created instanceof WC_DateTime ) {
			return 0;
		}

		return $created->getTimestamp() + Afriex_Scheduler::payment_window_seconds( AFRIEX_WC_GATEWAY_ID );
	}

	/**
	 * Whether the window for this order's transfer has closed.
	 *
	 * @param WC_Order $order Order.
	 */
	private function account_has_expired( WC_Order $order ): bool {
		$deadline = self::transfer_deadline( $order );

		return $deadline > 0 && time() >= $deadline;
	}

	/**
	 * The instructions and account details, as HTML or plain text.
	 *
	 * @param WC_Order $order      Order.
	 * @param bool     $plain_text Render without markup.
	 */
	private function render_instructions_markup( WC_Order $order, bool $plain_text = false ): void {
		$rows = Afriex_Order_Meta::get_payment_instructions( $order, self::transfer_deadline( $order ) );

		if ( empty( $rows ) ) {
			return;
		}

		$instructions = (string) $this->get_option( 'instructions' );

		if ( $plain_text ) {
			if ( '' !== $instructions ) {
				echo esc_html( wp_strip_all_tags( $instructions ) ) . "\n\n";
			}

			echo esc_html__( 'Afriex bank transfer details', 'afriex-gateway-for-woocommerce' ) . "\n";

			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}

			echo "\n";
			return;
		}

		if ( '' !== $instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $instructions ) ) );
		}

		echo '<section class="woocommerce-afriex-instructions">';
		echo '<h2>' . esc_html__( 'Afriex bank transfer details', 'afriex-gateway-for-woocommerce' ) . '</h2>';
		echo '<ul class="wc-afriex-account-details order_details">';

		foreach ( $rows as $label => $value ) {
			echo '<li>' . esc_html( $label ) . ': <strong>' . esc_html( $value ) . '</strong></li>';
		}

		echo '</ul>';
		echo '</section>';
	}
}
