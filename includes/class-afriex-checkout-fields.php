<?php
/**
 * The parts of a checkout-session request that are only rules about values.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Channel and metadata rules for Afriex Checkout, with no WordPress in them.
 *
 * Afriex_Checkout_Gateway extends WC_Payment_Gateway, so nothing on it can be
 * unit-tested without half of WooCommerce loaded, and plan section 15 asks for
 * unit tests of exactly these two things: metadata stringifying and the
 * channel list. They are rules about values rather than anything to do with an
 * order, a request or a screen, so they live here and the gateway delegates.
 *
 * Everything here is deliberately total: given any input at all it returns a
 * value the API will accept, or drops what it cannot use. Refusing a bad value
 * out loud is the gateway's job, because only the gateway knows who is asking.
 */
class Afriex_Checkout_Fields {

	/**
	 * Every channel a session may request, in the order the plugin sends and
	 * displays them. Afriex intersects this with what the currency supports
	 * and answers with the survivors in request order, so the order matters.
	 */
	public const CHANNELS = array( 'CARD', 'MOBILE_MONEY', 'VIRTUAL_BANK_ACCOUNT' );

	/**
	 * Longest metadata value Afriex accepts, in bytes (plan section 3).
	 */
	public const METADATA_MAX = 1024;

	/**
	 * Most metadata entries Afriex accepts (plan section 3).
	 */
	public const METADATA_MAX_ENTRIES = 50;

	/**
	 * Only Afriex's own channel values, de-duplicated, in this class's order.
	 *
	 * A stable order is what keeps the request, the stored setting and the
	 * admin screen from disagreeing about which channel came first.
	 *
	 * @param mixed $value Anything a form or an API response offered.
	 * @return string[]
	 */
	public static function sanitize_channels( $value ): array {
		$chosen = array();

		foreach ( (array) $value as $channel ) {
			if ( ! is_scalar( $channel ) ) {
				continue;
			}

			$channel = strtoupper( trim( (string) $channel ) );

			if ( in_array( $channel, self::CHANNELS, true ) && ! in_array( $channel, $chosen, true ) ) {
				$chosen[] = $channel;
			}
		}

		return array_values( array_intersect( self::CHANNELS, $chosen ) );
	}

	/**
	 * Metadata Afriex will accept: string values, capped in size and number.
	 *
	 * An entry whose value is empty after stringifying is dropped rather than
	 * sent: an empty string says nothing, and the 50-entry budget is better
	 * spent on something that does.
	 *
	 * @param array $values Candidate metadata, keyed by name.
	 * @return array<string, string>
	 */
	public static function metadata( array $values ): array {
		$metadata = array();

		foreach ( $values as $key => $value ) {
			if ( count( $metadata ) >= self::METADATA_MAX_ENTRIES ) {
				break;
			}

			$key   = trim( (string) $key );
			$value = self::metadata_value( $value );

			if ( '' === $key || '' === $value ) {
				continue;
			}

			$metadata[ $key ] = $value;
		}

		return $metadata;
	}

	/**
	 * One metadata value: a string, cut on a character boundary if need be.
	 *
	 * @param mixed $value Anything scalar.
	 */
	public static function metadata_value( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( strlen( $value ) <= self::METADATA_MAX ) {
			return $value;
		}

		// mb_strcut counts bytes but never splits a character, which is what
		// a byte limit on a UTF-8 field needs. substr() is the fallback for a
		// build without mbstring, where a cut character is still better than
		// a 422.
		return function_exists( 'mb_strcut' )
			? (string) mb_strcut( $value, 0, self::METADATA_MAX, 'UTF-8' )
			: (string) substr( $value, 0, self::METADATA_MAX );
	}
}
