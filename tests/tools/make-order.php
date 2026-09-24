<?php
/**
 * Creates an Afriex order to drive the webhook and settlement tests against.
 *
 * Run through WP-CLI: `npm run env:order`, or with `real` to go through the
 * gateway: `npm run env:order -- real`. (WP-CLI refuses unknown --flags on
 * eval-file, so it is a bare word.)
 *
 * Two ways to get an order:
 *
 * - The default writes the meta that store_payment_details() would have
 *   written and puts the order on hold, without calling anything. It works
 *   whether or not the mock API is enabled, and it is what the webhook tests
 *   need: a known payment method id to aim a deposit at.
 * - `real` calls the gateway's own process_payment(), so the account comes
 *   from the mock API (tests/mu-plugins/afriex-mock-api.php) and every field
 *   is whatever the plugin really stores. That needs the mock enabled.
 *
 * The billing phone is in E.164 either way: the mock refuses anything else,
 * as Afriex does (F41).
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
// A `wp eval-file` script runs at the top level by design: these are its local
// variables, nothing ever loads this file into WordPress, and no template is
// rendered afterwards that could read WooCommerce's own $order.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Whether the development mock API is answering Afriex calls.
 *
 * The mock's own helper is defined only when the mu-plugin loaded at all.
 */
function afriex_mock_api_is_active_for_cli(): bool {
	return function_exists( 'afriex_mock_api_is_active' ) && afriex_mock_api_is_active();
}

$real  = in_array( 'real', (array) $args, true );
$total = 49.99;
$phone = '+23480' . wp_rand( 10000000, 99999999 );

$order = wc_create_order();
$order->set_payment_method( 'afriex' );
$order->set_payment_method_title( 'Bank Transfer' );
$order->set_currency( 'NGN' );
$order->set_billing_email( 'test-' . wp_rand( 1000, 9999 ) . '@example.com' );
$order->set_billing_first_name( 'Test' );
$order->set_billing_last_name( 'Customer' );
$order->set_billing_country( 'NG' );
$order->set_billing_phone( $phone );

$products = wc_get_products(
	array(
		'limit'  => 1,
		'status' => 'publish',
	)
);

if ( ! empty( $products ) ) {
	$order->add_product( $products[0], 1 );
}

$order->set_total( $total );
$order->save();

if ( $real ) {
	if ( ! afriex_mock_api_is_active_for_cli() ) {
		WP_CLI::warning( 'The mock API is not active, so this will try to reach Afriex for real.' );
	}

	$result = ( new Afriex_Gateway() )->process_payment( $order->get_id() );

	if ( ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			WP_CLI::warning( is_array( $notice ) ? (string) $notice['notice'] : (string) $notice );
		}

		WP_CLI::error( 'process_payment() failed for order ' . $order->get_id() . '.' );
	}

	$order = wc_get_order( $order->get_id() );
} else {
	$payment_method_id = 'pm_test_' . wp_rand( 1000, 9999 );

	$order->update_meta_data( '_afriex_payment_method_id', $payment_method_id );
	$order->update_meta_data( '_afriex_customer_id', 'cus_test_1' );
	$order->update_meta_data( '_afriex_account_number', '0123456789' );
	$order->update_meta_data( '_afriex_account_name', 'Afriex Test Store' );
	$order->update_meta_data( '_afriex_bank_name', 'Test Bank' );
	$order->update_meta_data( '_afriex_reference', 'AFX-' . $order->get_id() );
	$order->update_meta_data( '_afriex_last_status', 'PENDING' );

	// 1.1 keys: the environment the order belongs to and what the account was
	// issued for. Settlement judges a payment against these (plan 11).
	$order->update_meta_data( '_afriex_environment', Afriex_Account_Settings::environment() );
	$order->update_meta_data( '_afriex_expected_amount_minor', (string) Afriex_Money::to_minor( $total, 'NGN' ) );
	$order->update_meta_data( '_afriex_expected_currency', 'NGN' );

	$order->update_status( 'on-hold', 'Awaiting Afriex bank transfer.' );
	$order->save();
}

$payment_method_id = (string) $order->get_meta( '_afriex_payment_method_id' );

WP_CLI::log( 'Order id:          ' . $order->get_id() );
WP_CLI::log( 'Status:            ' . $order->get_status() );
WP_CLI::log( 'Total:             ' . $order->get_total() . ' ' . $order->get_currency() );
WP_CLI::log( 'Expected (minor):  ' . $order->get_meta( '_afriex_expected_amount_minor' ) );
WP_CLI::log( 'Environment:       ' . $order->get_meta( '_afriex_environment' ) );
WP_CLI::log( 'Payment method id: ' . $payment_method_id );
WP_CLI::log( 'Account number:    ' . $order->get_meta( '_afriex_account_number' ) );
WP_CLI::log( 'Bank:              ' . $order->get_meta( '_afriex_bank_name' ) );
WP_CLI::log( '' );
WP_CLI::log( 'Pay it through the mock API (the transaction exists afterwards):' );
WP_CLI::log( '  npm run webhook:deposit -- --payment-method-id ' . $payment_method_id . ' --amount ' . $total );
WP_CLI::log( '' );
WP_CLI::log( 'Or aim a signed payload straight at the webhook route:' );
WP_CLI::log( '  npm run webhook:send -- --payment-method-id ' . $payment_method_id . ' --status SUCCESS --amount ' . $total );
WP_CLI::log( '' );
WP_CLI::log( 'Then look at it with:' );
WP_CLI::log( '  npm run env:show -- ' . $order->get_id() );
