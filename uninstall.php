<?php
/**
 * Removes plugin data on uninstall.
 *
 * Deliberately narrow: settings, the ledger table and the scheduled sweep go,
 * order meta stays. Order meta is a record of how a real order was paid, and
 * a store that removes the plugin still needs it for accounting and disputes.
 *
 * The plugin's classes are not loaded here, so everything is done with core
 * and Action Scheduler functions directly. On multisite the same cleanup runs
 * for every site, because each site has its own options, table and scheduled
 * actions (F59).
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 */
function afriex_wc_uninstall_site(): void {
	global $wpdb;

	$options = array(
		'woocommerce_afriex_settings',
		'woocommerce_afriex_checkout_settings',
		'afriex_wc_account',
		'afriex_wc_channels',
		'afriex_wc_db_version',
		'afriex_wc_auth_error',
		'afriex_wc_checkout_refused',
		'afriex_wc_pool_notice',
		'afriex_wc_review_notice',
		'afriex_wc_environment_notice',
		'afriex_wc_currency_notice',
		'afriex_wc_dismissed_notices',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The remembered Afriex customer id, one per environment (F25). It is a
	// cache of something Afriex holds, not a record of a payment, so unlike
	// order meta it goes.
	foreach ( array( 'sandbox', 'production' ) as $afriex_wc_environment ) {
		delete_metadata( 'user', 0, '_afriex_customer_id_' . $afriex_wc_environment, '', true );
	}

	// Deactivation already removed the sweep; this catches a site where it
	// was re-created in between, e.g. by a request during deactivation.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'afriex_wc_sweep' );
	}

	wp_clear_scheduled_hook( 'afriex_reconcile_pending_orders' );

	$table = $wpdb->prefix . 'afriex_ledger';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

if ( is_multisite() ) {
	$afriex_wc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $afriex_wc_site_ids as $afriex_wc_site_id ) {
		switch_to_blog( (int) $afriex_wc_site_id );
		afriex_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	afriex_wc_uninstall_site();
}
