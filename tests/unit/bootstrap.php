<?php
/**
 * Bootstrap for the WordPress-free unit tests.
 *
 * The classes under test start with `defined( 'ABSPATH' ) || exit;`, so ABSPATH
 * is defined first. Nothing else from WordPress is loaded: a class that needs
 * more than the stubs below belongs in an integration test against the
 * wp-env site, not here.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/afriex-unit-abspath/' );
}

// wpdb::get_row() output type, used by Afriex_Ledger with an injected fake.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$afriex_plugin_dir = dirname( __DIR__, 2 );

require_once $afriex_plugin_dir . '/vendor/autoload.php';

require_once $afriex_plugin_dir . '/includes/class-afriex-money.php';
require_once $afriex_plugin_dir . '/includes/class-afriex-phone.php';
require_once $afriex_plugin_dir . '/includes/class-afriex-status-mapper.php';

// Afriex_Settlement_Decision is the whole settlement decision table with no
// WordPress in it (plan 15). Afriex_Order_Meta is loaded for its reference
// minting and parsing, which are equally WordPress-free; every other method on
// it needs an order and belongs in a test against the wp-env site.
require_once $afriex_plugin_dir . '/includes/class-afriex-settlement-decision.php';
require_once $afriex_plugin_dir . '/includes/class-afriex-order-meta.php';

// Afriex_Checkout_Fields is the channel and metadata half of a checkout
// session request, with no WordPress in it. It was split out of
// Afriex_Checkout_Gateway so that the unit tests plan section 15 asks for —
// metadata stringifying and the channel list — can run here instead of
// needing WC_Payment_Gateway and half of WooCommerce loaded.
require_once $afriex_plugin_dir . '/includes/class-afriex-checkout-fields.php';

// Afriex_Customer is the customer-reuse half of a Bank Transfer checkout: where
// an id is in a payload, what a 409 code allows, and whether a looked-up
// customer is this shopper's (F25). Rules about values, so they belong here.
require_once $afriex_plugin_dir . '/includes/class-afriex-customer.php';
