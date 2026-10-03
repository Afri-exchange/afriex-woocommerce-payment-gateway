<?php
/**
 * Plugin Name: Afriex dev: real sandbox return URL
 * Description: Development only (tests/ never ships). When the dev site talks to the real Afriex sandbox, gives Afriex Checkout the https return URL it requires.
 *
 * Why: Afriex refuses a redirectUrl that is not https, and the plugin hides
 * Afriex Checkout on a store whose return URL would not be. This dev site is
 * plain http. The mock API gives the return base the https scheme for the
 * same reason, but the mock is off when testing against the real sandbox,
 * so this does it instead.
 *
 * With AFRIEX_DEV_RETURN_HOST set to the tunnel host, Afriex sends the
 * browser back through the tunnel, which serves https. The tunnel guards
 * (00-tunnel.php and the .htaccess rule) let the return endpoint through;
 * it checks its HMAC and redirects to the order-received page on
 * http://localhost:8888, which the browser on this machine can open.
 * Arriving over the tunnel makes is_ssl() true, so home_url() would build
 * that redirect as https://localhost:8888; the wp_redirect filter below
 * puts it back on http.
 *
 * Without it the return base is https://localhost:8888/, which this
 * TLS-less site cannot serve: change https to http in the address bar.
 * The webhook depends on neither; it reaches the site through the tunnel.
 *
 * Active only when AFRIEX_DEV_ALLOW_LIVE_API is true, the mock is off, and
 * the environment type is local or development. Switch it on with
 * .wp-env.override.json (see tests/README.md).
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if (
	! ( defined( 'AFRIEX_DEV_ALLOW_LIVE_API' ) && AFRIEX_DEV_ALLOW_LIVE_API )
	|| ( defined( 'AFRIEX_DEV_MOCK_API' ) && AFRIEX_DEV_MOCK_API )
	|| ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
) {
	return;
}

add_filter(
	'afriex_wc_checkout_return_url_base',
	static function ( $base ): string {
		$host = defined( 'AFRIEX_DEV_RETURN_HOST' ) ? strtolower( trim( (string) AFRIEX_DEV_RETURN_HOST ) ) : '';

		if ( '' !== $host && preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return 'https://' . $host . '/';
		}

		return set_url_scheme( (string) $base, 'https' );
	}
);

add_filter(
	'wp_redirect',
	static function ( $location ) {
		$parts = wp_parse_url( (string) $location );

		if ( 'https' === ( $parts['scheme'] ?? '' ) && in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), array( 'localhost', '127.0.0.1' ), true ) ) {
			return set_url_scheme( (string) $location, 'http' );
		}

		return $location;
	}
);
