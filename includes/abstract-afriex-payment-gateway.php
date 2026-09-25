<?php
/**
 * Base class for the Afriex payment gateways.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What every Afriex method shares: the account section of the settings page,
 * setup and test-mode reporting, admin notices, and building an API client.
 *
 * The account settings (API key, environment, webhook keys, logging) are not
 * form fields of any gateway. Each gateway renders Afriex_Account_Settings
 * above its own fields and saves it first, so both gateways edit one stored
 * copy and the secrets never pass through the gateway REST API (F51).
 *
 * Nothing that settles a payment may live here or depend on a gateway
 * instance: webhooks, the return handler and the sweep act on any Afriex
 * order whatever the method's enabled flag (plan 4.2). This class only
 * decides what shoppers and admins see.
 */
abstract class Afriex_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * Every Afriex gateway id, including ones a later release adds. Used by
	 * the global notices, which run without a gateway instance.
	 */
	public const GATEWAY_IDS = array( 'afriex', 'afriex_checkout' );

	/**
	 * The Afriex mark, for the gateway's `icon`.
	 *
	 * A plain URL rather than markup: WooCommerce's Payments settings list
	 * shows `icon` only when it is a valid URL, and classic checkout wraps
	 * it in an <img> itself.
	 */
	protected static function icon_url(): string {
		return plugins_url( 'assets/images/afriex-icon.svg', AFRIEX_WC_PLUGIN_FILE );
	}

	/**
	 * The shared account settings.
	 */
	public function account(): Afriex_Account_Settings {
		return Afriex_Account_Settings::instance();
	}

	/**
	 * Settings page: header, readiness notices, the shared account section,
	 * then this method's own fields.
	 *
	 * WC_Payment_Gateway::admin_options() prints the header and the method
	 * table in one go, with no hook between them, so the header is rebuilt
	 * here the same way WooCommerce builds it.
	 */
	public function admin_options() {
		// The same URL WooCommerce's internal Settings Utils builds; not
		// called directly because Internal classes carry no stability promise.
		$payments_url = admin_url( 'admin.php?page=wc-settings&tab=checkout' );

		if ( function_exists( 'wc_back_header' ) ) {
			wc_back_header( $this->get_method_title(), __( 'Return to payments', 'afriex-gateway-for-woocommerce' ), $payments_url );
		} else {
			echo '<h2>' . esc_html( $this->get_method_title() );
			wc_back_link( __( 'Return to payments', 'afriex-gateway-for-woocommerce' ), $payments_url );
			echo '</h2>';
		}

		echo wp_kses_post( wpautop( $this->get_method_description() ) );

		$this->render_setup_notice();
		self::render_auth_error_notice( true );

		// render_admin_notices() skips the gateway settings pages because the
		// notices above are printed here instead — but the environment
		// warning had no inline counterpart, so it appeared nowhere on the
		// one page the admin is redirected back to after changing the
		// environment (plan 4.2, 12).
		self::render_environment_notice();

		echo '<h3 class="wc-settings-sub-title" id="afriex-account-settings">' . esc_html__( 'Afriex account', 'afriex-gateway-for-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'Shared by all Afriex payment methods. Changing these here changes them for every Afriex method.', 'afriex-gateway-for-woocommerce' ) . '</p>';
		echo '<table class="form-table afriex-account-settings">' . $this->account()->generate_settings_html( $this->account()->get_form_fields(), false ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes each field.

		echo '<h3 class="wc-settings-sub-title" id="afriex-method-settings">' . esc_html(
			sprintf(
				/* translators: %s: payment method name, e.g. "Afriex Bank Transfer" */
				__( '%s settings', 'afriex-gateway-for-woocommerce' ),
				$this->get_method_title()
			)
		) . '</h3>';
		echo '<table class="form-table afriex-method-settings">' . $this->generate_settings_html( $this->get_form_fields(), false ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes each field.
	}

	/**
	 * Save the shared account section first, then this method's own fields.
	 *
	 * Order matters: the method's validators may depend on the account values
	 * (for example the environment), so those are written first. WooCommerce
	 * has already checked its settings nonce and manage_woocommerce before
	 * this runs.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$this->account()->process_admin_options();

		$saved = parent::process_admin_options();

		Afriex_Logger::reset();

		return $saved;
	}

	/**
	 * Report a validation error through WooCommerce's settings page too.
	 *
	 * WooCommerce re-creates the gateways after saving, so an error kept only
	 * on this object would never be shown and the page would still say "Your
	 * settings have been saved" (F83).
	 *
	 * @param string $error Message.
	 */
	public function add_error( $error ) {
		parent::add_error( $error );

		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( $error );
		}
	}

	/**
	 * Shown by the WooCommerce Payments list as "needs setup" (F78).
	 *
	 * Only the API key is required to take an order. A missing webhook key is
	 * a warning, not a blocker, because payments can still be confirmed later.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		return '' === Afriex_Account_Settings::api_key();
	}

	/**
	 * Drives the test-mode badge on the WooCommerce Payments list (F79).
	 *
	 * @return bool
	 */
	public function is_in_test_mode() {
		return Afriex_Api_Client::ENV_SANDBOX === Afriex_Account_Settings::environment();
	}

	/**
	 * This method's own settings page.
	 *
	 * @return string
	 */
	public function get_settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( $this->id ) );
	}

	/**
	 * Account settings that are missing, as field key => label.
	 *
	 * 'api_key' blocks payments; the webhook key for the current environment
	 * only delays confirmations. Callers decide which ones matter to them.
	 *
	 * @return array<string, string>
	 */
	public function get_missing_settings(): array {
		return self::missing_account_settings();
	}

	/**
	 * An API client for an environment: the order's own for existing orders,
	 * the configured one for new orders.
	 *
	 * @param string|null $environment 'sandbox', 'production' or null.
	 */
	public function build_api_client( ?string $environment = null ): Afriex_Api_Client {
		return Afriex_Api_Client::for_environment( $environment );
	}

	/**
	 * Hidden from shoppers when it cannot take a payment.
	 *
	 * Without an API key every order would fail in process_payment() after the
	 * shopper filled in the whole form; after a 401 every call fails the
	 * same way until the key is fixed (F84).
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( '' === Afriex_Account_Settings::api_key() || null !== Afriex_Api_Client::auth_error() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * The total this method is being asked about, and the currency it is in.
	 *
	 * The order-pay page has an order and no cart; the checkout page has a
	 * cart; the admin Payments list has neither, and must not hide a method
	 * just because nobody is buying anything.
	 *
	 * The two travel together because they have to agree. An order carries
	 * the currency it was placed in, which a multi-currency plugin filtering
	 * `woocommerce_currency` per request can make different from the store's
	 * — and judging an order's total against the shopper's current session
	 * currency answers the wrong question in both directions.
	 *
	 * Shared, because both methods have an availability rule about money: the
	 * Checkout minimum, and the Bank Transfer ceiling (F40).
	 *
	 * @return array total (string|float|null) and currency (string).
	 */
	protected function context_money(): array {
		$order_id = absint( get_query_var( 'order-pay' ) );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				return array(
					'total'    => $order->get_total(),
					'currency' => strtoupper( $order->get_currency() ),
				);
			}
		}

		$currency = strtoupper( (string) get_woocommerce_currency() );

		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			return array(
				'total'    => WC()->cart->get_total( 'edit' ),
				'currency' => $currency,
			);
		}

		return array(
			'total'    => null,
			'currency' => $currency,
		);
	}

	/**
	 * The "hidden at checkout, and here is why" box on a settings page.
	 *
	 * Plan 4.2: a method hides itself when it cannot work, and its settings
	 * page explains why. Each gateway collects its own reasons; the box they
	 * go in is the same one.
	 *
	 * @param string[] $problems Sentences an admin can act on.
	 */
	protected function render_availability_notice( array $problems ): void {
		if ( empty( $problems ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline afriex-availability-notice"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: payment method name, e.g. "Afriex Bank Transfer" */
				__( '%s is hidden at checkout, whatever the Enable switch says:', 'afriex-gateway-for-woocommerce' ),
				$this->get_method_title()
			)
		);
		echo '</p><ul style="list-style:disc;margin-left:2em">';

		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * The readiness warning above the settings.
	 */
	protected function render_setup_notice(): void {
		$missing = $this->get_missing_settings();

		if ( empty( $missing ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline afriex-setup-notice"><p>';
		echo esc_html( self::missing_settings_message( $missing ) );
		echo '</p></div>';
	}

	/**
	 * Missing account settings, without needing a gateway instance.
	 *
	 * @return array<string, string>
	 */
	public static function missing_account_settings(): array {
		$missing = array();

		if ( '' === Afriex_Account_Settings::api_key() ) {
			$missing['api_key'] = __( 'API key', 'afriex-gateway-for-woocommerce' );
		}

		if ( '' === Afriex_Account_Settings::webhook_public_key() ) {
			$missing['webhook_public_key'] = sprintf(
				/* translators: %s: environment, "sandbox" or "production" */
				__( 'Webhook public key (%s)', 'afriex-gateway-for-woocommerce' ),
				Afriex_Account_Settings::environment()
			);
		}

		return $missing;
	}

	/**
	 * The sentence for a set of missing settings.
	 *
	 * @param array<string, string> $missing Missing settings.
	 */
	private static function missing_settings_message( array $missing ): string {
		if ( isset( $missing['api_key'] ) ) {
			return sprintf(
				/* translators: %s: comma-separated list of setting names */
				__( 'Afriex is not ready to take payments, so its payment methods are hidden at checkout. Missing: %s.', 'afriex-gateway-for-woocommerce' ),
				implode( ', ', $missing )
			);
		}

		return sprintf(
			/* translators: %s: setting name */
			__( 'Missing: %s. Payments can be taken, but Afriex payment notifications are rejected until it is added, so confirmations arrive late.', 'afriex-gateway-for-woocommerce' ),
			implode( ', ', $missing )
		);
	}

	/**
	 * Hook the site-wide Afriex notices. Called once from the main plugin file.
	 */
	public static function register_admin_notices(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_environment_notice_dismissal' ) );
	}

	/**
	 * Warn that the environment was switched with orders still in flight.
	 *
	 * Written by Afriex_Account_Settings::process_admin_options(). Shown on
	 * every admin screen, like the auth error, because the consequence is the
	 * same: payments that will not settle, and nothing else says so.
	 */
	protected static function render_environment_notice(): void {
		$notice = get_option( Afriex_Account_Settings::ENVIRONMENT_NOTICE_OPTION, null );

		if ( ! is_array( $notice ) || empty( $notice['orders'] ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'afriex_wc_dismiss', 'environment' ), 'afriex_wc_dismiss_environment' );

		printf(
			'<div class="notice notice-warning afriex-environment-notice"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number of orders, 2: previous environment, 3: new environment */
					_n(
						'The Afriex environment was changed from %2$s to %3$s while %1$d unpaid Afriex order was still waiting for payment. That order is still recorded against %2$s, and it can only be settled while an API key for %2$s is saved. Check it before the payment window closes.',
						'The Afriex environment was changed from %2$s to %3$s while %1$d unpaid Afriex orders were still waiting for payment. Those orders are still recorded against %2$s, and they can only be settled while an API key for %2$s is saved. Check them before their payment windows close.',
						(int) $notice['orders'],
						'afriex-gateway-for-woocommerce'
					),
					(int) $notice['orders'],
					(string) ( $notice['from'] ?? '' ),
					(string) ( $notice['to'] ?? '' )
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Dismiss the environment-switch notice.
	 */
	public static function handle_environment_notice_dismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked below, before anything changes.
		if ( ! isset( $_GET['afriex_wc_dismiss'] ) || 'environment' !== sanitize_key( wp_unslash( $_GET['afriex_wc_dismiss'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'afriex_wc_dismiss_environment' );

		delete_option( Afriex_Account_Settings::ENVIRONMENT_NOTICE_OPTION );

		wp_safe_redirect( remove_query_arg( array( 'afriex_wc_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Site-wide notices for store managers, while an Afriex method is enabled.
	 *
	 * - A recorded 401 (F84) shows on every admin screen: both methods have
	 *   vanished from checkout, which is urgent and otherwise invisible.
	 * - Missing settings show on the dashboard, Plugins and WooCommerce
	 *   settings screens only, to inform without nagging.
	 *
	 * On an Afriex settings page the same messages are printed inline by
	 * admin_options(), so they are skipped here to avoid showing them twice.
	 */
	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::any_gateway_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( in_array( $section, self::GATEWAY_IDS, true ) ) {
			return;
		}

		self::render_auth_error_notice( false );
		self::render_session_refusal_notice();
		self::render_environment_notice();

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' ), true ) ) {
			return;
		}

		$missing = self::missing_account_settings();

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice %1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			isset( $missing['api_key'] ) ? 'notice-error' : 'notice-warning',
			esc_html( self::missing_settings_message( $missing ) ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_GATEWAY_ID ) ),
			esc_html__( 'Open the Afriex settings', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Tell the store manager that Afriex rejected the API key (F84).
	 *
	 * @param bool $inline Inline notice inside a settings page.
	 */
	protected static function render_auth_error_notice( bool $inline ): void {
		$error = Afriex_Api_Client::auth_error();

		if ( null === $error ) {
			return;
		}

		echo '<div class="notice notice-error' . ( $inline ? ' inline' : '' ) . ' afriex-auth-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: HTTP status code, 2: environment name */
				__( 'Afriex rejected the API key (HTTP %1$d, %2$s). The key is invalid, revoked, for the other environment, or lacks permissions. Afriex payment methods are hidden at checkout until a call succeeds or the Afriex account settings are saved.', 'afriex-gateway-for-woocommerce' ),
				(int) ( $error['status'] ?? 0 ),
				Afriex_Account_Settings::environment()
			)
		);

		if ( ! $inline ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_GATEWAY_ID ) ) . '">' . esc_html__( 'Review the Afriex settings', 'afriex-gateway-for-woocommerce' ) . '</a>';
		}

		echo '</p></div>';
	}

	/**
	 * Tell the store manager that Afriex refused Afriex Checkout a session,
	 * which hides that method alone. Its own settings page lists the same
	 * reason inline among the availability problems.
	 */
	protected static function render_session_refusal_notice(): void {
		$settings = get_option( 'woocommerce_' . AFRIEX_WC_CHECKOUT_GATEWAY_ID . '_settings', array() );

		if ( ! is_array( $settings ) || 'yes' !== ( $settings['enabled'] ?? '' ) ) {
			return;
		}

		$refusal = Afriex_Checkout_Gateway::session_refusal();

		if ( null === $refusal ) {
			return;
		}

		echo '<div class="notice notice-error afriex-session-refused"><p>';
		echo esc_html__( 'Afriex Checkout is hidden at checkout.', 'afriex-gateway-for-woocommerce' ) . ' ';
		echo esc_html( Afriex_Checkout_Gateway::session_refusal_message( $refusal ) );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_CHECKOUT_GATEWAY_ID ) ) . '">' . esc_html__( 'Review the Afriex settings', 'afriex-gateway-for-woocommerce' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Whether any Afriex method is switched on, read from the stored options
	 * so no gateway has to be constructed.
	 */
	public static function any_gateway_enabled(): bool {
		foreach ( self::GATEWAY_IDS as $id ) {
			$settings = get_option( 'woocommerce_' . $id . '_settings', array() );

			if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
				return true;
			}
		}

		return false;
	}
}
