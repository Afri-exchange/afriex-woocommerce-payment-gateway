<?php
/**
 * Plugin Name: Afriex test probe
 * Description: Development only (tests/ never ships). Counts WooCommerce events in a site option, so a test can assert on what happened inside a webhook request it did not run itself.
 * Version: 1.0.0
 *
 * A signed webhook arrives in its own PHP process. A test script that drives
 * one cannot add_action() its way into that process, so anything it wants to
 * count has to be written down where both can see it. That is all this is.
 *
 * It does nothing at all until a test switches it on:
 *
 *     update_option( 'afriex_test_probe', array( 'on' => 1 ) );
 *
 * and the counts come back from the same option, keyed
 * "payment_complete:{order_id}". Delete the option to switch it off again.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'afriex_test_probe_record' ) ) {
	/**
	 * Add one to a counter, read fresh from the database.
	 *
	 * Several processes touch this option at once — the page, the webhook
	 * delivery, the test — so a cached copy from earlier in the request would
	 * hide what the others have just written and clobber it on the next save.
	 *
	 * @param string $key Counter name.
	 */
	function afriex_test_probe_record( string $key ): void {
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( 'local' !== $type && 'development' !== $type ) {
			return;
		}

		wp_cache_delete( 'afriex_test_probe', 'options' );

		$counts = get_option( 'afriex_test_probe', array() );

		if ( ! is_array( $counts ) || empty( $counts['on'] ) ) {
			return;
		}

		$counts[ $key ] = isset( $counts[ $key ] ) ? (int) $counts[ $key ] + 1 : 1;

		update_option( 'afriex_test_probe', $counts, false );
	}
}

add_action(
	'woocommerce_payment_complete',
	function ( $order_id ) {
		afriex_test_probe_record( 'payment_complete:' . (int) $order_id );
	},
	999
);

add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $from, $to ) {
		afriex_test_probe_record( 'status:' . (int) $order_id . ':' . $from . '>' . $to );
	},
	999,
	3
);
