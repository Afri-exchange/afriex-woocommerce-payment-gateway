<?php
/**
 * Plugin Name: Afriex Gateway for WooCommerce
 * Plugin URI: https://github.com/Afri-exchange/afriex-woocommerce-payment-gateway
 * Description: Accept card, bank transfer and mobile money payments through Afriex Checkout, or a dedicated Nigerian bank account for every order.
 * Version: 1.1.0
 * Author: Afriex
 * Author URI: https://afriex.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: afriex-gateway-for-woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'AFRIEX_WC_VERSION', '1.1.0' );
define( 'AFRIEX_WC_PLUGIN_FILE', __FILE__ );
define( 'AFRIEX_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Gateway ids. Declared once so neither PHP nor JS has to repeat the literal.
 *
 * 'afriex' is Bank Transfer, unchanged from 1.0 so that existing orders,
 * settings and meta keep working. 'afriex_checkout' is Afriex Checkout.
 */
define( 'AFRIEX_WC_GATEWAY_ID', 'afriex' );
define( 'AFRIEX_WC_CHECKOUT_GATEWAY_ID', 'afriex_checkout' );

/**
 * Load the plugin's classes.
 *
 * Kept as explicit requires rather than an autoloader: the file count is small,
 * and the WordPress.org review process reads a flat require list more easily
 * than a Composer autoloader that would also have to be committed.
 */
function afriex_wc_load_classes(): void {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	// Order matters only for inheritance: the account settings extend
	// WC_Settings_API and the gateways extend Afriex_Payment_Gateway. The
	// other classes reference each other only inside methods.
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-logger.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-money.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-phone.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-ledger.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-api-client.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-account-settings.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-customer.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-order-meta.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-status-mapper.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-settlement-decision.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-settlement.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-bank-transfer-sweep.php';
	// Before the Checkout gateway: that class's CHANNELS constant is defined
	// from this one's, and a class constant is resolved as the class is
	// declared, not when it is first read.
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-checkout-fields.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/abstract-afriex-payment-gateway.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-gateway.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-checkout-gateway.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-checkout-return.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-webhook-handler.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-scheduler.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-migration.php';
	require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-admin.php';

	$loaded = true;
}

/**
 * Declare compatibility with HPOS (custom order tables) and Cart & Checkout blocks.
 * Both must fire on before_woocommerce_init.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			AFRIEX_WC_PLUGIN_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			AFRIEX_WC_PLUGIN_FILE,
			true
		);
	}
);

/**
 * Register both payment methods.
 *
 * Each is enabled and configured independently (plan 2, 4.2); registering a
 * gateway only puts it on the Payments list, it does not switch it on.
 */
add_filter(
	'woocommerce_payment_gateways',
	function ( $gateways ) {
		$gateways[] = 'Afriex_Gateway';
		$gateways[] = 'Afriex_Checkout_Gateway';
		return $gateways;
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		afriex_wc_load_classes();

		// All four run whether or not a payment method is enabled: in-flight
		// orders must keep settling after a method is switched off, and
		// neither the webhook route nor the return route is a gateway's to
		// own (plan 4.2, plan 5.2, plan 7).
		Afriex_Webhook_Handler::register();
		Afriex_Checkout_Return::register();
		Afriex_Migration::register();
		Afriex_Scheduler::register();

		if ( is_admin() ) {
			Afriex_Payment_Gateway::register_admin_notices();
			Afriex_Settlement::register_admin_notices();

			// The order box, the "Check Afriex payment now" order action and
			// the notices that belong to no one settings page (plan 4.2, 4.3).
			Afriex_Admin::register();
		}
	}
);

/**
 * Register the block checkout integration.
 *
 * Purely additive: it bridges to the classic gateway's settings, title,
 * description and availability rather than duplicating them.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once AFRIEX_WC_PLUGIN_DIR . 'includes/class-afriex-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				// One integration object per gateway, one shared bundle. Each
				// reads its own settings key, afriex_data and
				// afriex_checkout_data (plan 10).
				$registry->register( new Afriex_Blocks_Support( AFRIEX_WC_GATEWAY_ID ) );
				$registry->register( new Afriex_Blocks_Support( AFRIEX_WC_CHECKOUT_GATEWAY_ID ) );
			}
		);
	}
);

/**
 * Settings link on the plugins list table.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url = admin_url(
			'admin.php?page=wc-settings&tab=checkout&section=' . AFRIEX_WC_GATEWAY_ID
		);

		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'afriex-gateway-for-woocommerce' ) . '</a>'
		);

		return $links;
	}
);

/**
 * The sweep is the backstop for lost webhooks: WC_Order::needs_payment()
 * returns false for on-hold orders, so nothing in WooCommerce core comes back
 * to an unpaid Afriex order on its own. It is an Action Scheduler action
 * owned by Afriex_Scheduler, registered on plugins_loaded above; the 1.0
 * WP-Cron event is removed by Afriex_Migration.
 */

/**
 * Activation: create the ledger table, migrate and schedule the sweep now,
 * rather than on the first request after it.
 *
 * Activation runs after plugins_loaded, so the classes are loaded here. When
 * WooCommerce is not active there is nothing to set up yet; the version check
 * on the first request with WooCommerce does it instead. The migration is
 * idempotent, so re-activating is harmless, and it re-creates the sweep that
 * deactivation removed.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		afriex_wc_load_classes();

		Afriex_Migration::run();
	}
);

/**
 * Deactivation: stop the sweep. Without the plugin loaded, Action Scheduler
 * would find no callback for it and log a failure every five minutes. The
 * 1.0 WP-Cron event is cleared too, for a site deactivated before migrating.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'Afriex_Scheduler' ) ) {
			Afriex_Scheduler::unschedule();
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'afriex_wc_sweep' );
		}

		wp_clear_scheduled_hook( 'afriex_reconcile_pending_orders' );
	}
);
