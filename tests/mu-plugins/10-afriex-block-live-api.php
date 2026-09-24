<?php
/**
 * Plugin Name: Afriex dev guard: block the live Afriex API
 * Description: Development only (tests/ never ships). Refuses every HTTP request to afriex.com and its subdomains that no mock has answered.
 *
 * Why: the dev site runs with a fake API key, and from 1.1 the plugin's
 * Action Scheduler sweep runs every five minutes on its own. Without this
 * guard that sweep would call the real Afriex sandbox with the fake key, get
 * a 401, record it, and hide the payment methods at checkout. A refused
 * request looks to the plugin like a network failure (status 0), which
 * changes no order and records no auth error.
 *
 * It runs at the last priority, so a mock hooked on pre_http_request (for
 * example tests/mu-plugins/afriex-mock-api.php, or a pre_http_request filter
 * in a test script) answers first and is never overridden.
 *
 * To talk to the real Afriex sandbox on purpose, define
 * AFRIEX_DEV_ALLOW_LIVE_API as true in wp-config.php.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}

		if ( defined( 'AFRIEX_DEV_ALLOW_LIVE_API' ) && AFRIEX_DEV_ALLOW_LIVE_API ) {
			return $pre;
		}

		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

		if ( 'afriex.com' !== $host && '.afriex.com' !== substr( $host, -11 ) ) {
			return $pre;
		}

		return new WP_Error(
			'afriex_dev_live_api_blocked',
			'Dev guard: requests to the real Afriex API are blocked on this site (tests/mu-plugins/10-afriex-block-live-api.php).'
		);
	},
	PHP_INT_MAX,
	3
);
