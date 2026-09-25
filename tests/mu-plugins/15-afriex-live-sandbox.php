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
 * The consequence: after paying on Afriex's page the browser is sent to
 * https://localhost:8888/..., which this TLS-less site cannot serve. Change
 * https to http in the address bar to land on the return page. The webhook
 * does not depend on that redirect; it reaches the site through the tunnel.
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
	'woocommerce_afriex_checkout_return_url_base',
	static function ( $base ): string {
		return set_url_scheme( (string) $base, 'https' );
	}
);
