<?php
/**
 * Keeps the wp-env site private when it is exposed through a public tunnel.
 *
 * Local test environment only: this directory is mounted into mu-plugins by
 * .wp-env.json and never ships.
 *
 * A tunnel exists so Afriex can deliver webhooks to this machine. Nothing else
 * belongs on the public internet. The site runs with a well-known admin login,
 * WordPress admin access amounts to running PHP, and the repo is mounted
 * read-write, so a stranger in wp-admin could plant code you would later
 * commit. The test signing key also sits inside the plugin directory.
 *
 * So any request that did not come from this machine gets a 404, except the
 * webhook route. wp-admin is for http://localhost:8888 only.
 *
 * Apache serves static files (the key, .git, source) without running PHP, so
 * this guard cannot see those requests. tests/tools/tunnel-lockdown.mjs adds
 * the same rule to the site's .htaccess to cover them. Both are applied by
 * `npm run env:setup`.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

( function () {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a fixed allowlist only.
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';

	if ( in_array( $host, array( 'localhost', 'localhost:8888', '127.0.0.1', '127.0.0.1:8888' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a fixed path only.
	$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );

	if ( '/wp-json/afriex/v1/webhook' === rtrim( $path, '/' ) ) {
		return;
	}

	http_response_code( 404 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Not found\n";
	exit;
} )();
