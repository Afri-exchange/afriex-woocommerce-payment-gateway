<?php
/**
 * Prints an order's current status, Afriex meta and notes.
 *
 * Run through WP-CLI: `npm run env:show -- <order-id>`, or with no id for the
 * most recent Afriex order of either method.
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

$order_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( ! $order_id ) {
	$orders = wc_get_orders(
		array(
			'limit'          => 1,
			'payment_method' => array( 'afriex', 'afriex_checkout' ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'status'         => 'any',
		)
	);

	if ( empty( $orders ) ) {
		WP_CLI::error( 'No Afriex orders found.' );
	}

	$order = $orders[0];
} else {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		WP_CLI::error( 'No order ' . $order_id );
	}
}

WP_CLI::log( 'Order:          #' . $order->get_id() );
WP_CLI::log( 'Method:         ' . $order->get_payment_method() );
WP_CLI::log( 'Status:         ' . $order->get_status() );
WP_CLI::log( 'Paid:           ' . ( $order->is_paid() ? 'yes' : 'no' ) );
WP_CLI::log( 'Total:          ' . $order->get_total() . ' ' . $order->get_currency() );
WP_CLI::log( 'Transaction id: ' . ( $order->get_transaction_id() ? $order->get_transaction_id() : '(none)' ) );
WP_CLI::log( '' );

/**
 * Every Afriex meta key the plugin writes, in the order of plan section 11.
 * A key with no value is skipped, so the output stays readable on a 1.0 order.
 */
$keys = array(
	'_afriex_environment'                => 'Environment',
	'_afriex_key_fingerprint'            => 'Key fingerprint',
	'_afriex_last_status'                => 'Afriex status',
	'_afriex_expected_amount_minor'      => 'Expected (minor)',
	'_afriex_expected_currency'          => 'Expected currency',
	'_afriex_payment_method_id'          => 'Payment method id',
	'_afriex_customer_id'                => 'Customer id',
	'_afriex_account_number'             => 'Account number',
	'_afriex_account_name'               => 'Account name',
	'_afriex_bank_name'                  => 'Bank',
	'_afriex_reference'                  => 'Reference (1.0)',
	'_afriex_expires_at'                 => 'Expires at',
	'_afriex_collection_method'          => 'Collection method',
	'_afriex_checkout_current_reference' => 'Checkout reference',
	'_afriex_checkout_url'               => 'Checkout URL',
	'_afriex_settled_transaction_id'     => 'Settled by',
	'_afriex_channel'                    => 'Channel',
	'_afriex_needs_review'               => 'Needs review',
	'_afriex_failed_by_plugin'           => 'Failed by plugin',
	'_afriex_next_check'                 => 'Next check',
	'_afriex_last_note_key'              => 'Last note key',
	'_afriex_transaction_id'             => 'Transaction id (1.0)',
);

foreach ( $keys as $key => $label ) {
	$value = $order->get_meta( $key );

	if ( '' === $value || null === $value || array() === $value ) {
		continue;
	}

	if ( '_afriex_expires_at' === $key && is_numeric( $value ) ) {
		$value = $value . '  (' . gmdate( 'Y-m-d H:i:s', (int) $value ) . ' UTC)';
	}

	WP_CLI::log( str_pad( $label . ':', 22 ) . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
}

// Checkout sessions are stored one meta row per reference (plan 11).
$references = $order->get_meta( '_afriex_checkout_reference', false );

if ( ! empty( $references ) ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'Checkout sessions:' );

	foreach ( $references as $reference ) {
		$value = $reference instanceof WC_Meta_Data ? $reference->value : $reference;
		WP_CLI::log( '  - ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Notes:' );

foreach ( array_reverse( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) as $note ) {
	WP_CLI::log( '  - ' . $note->content );
}
