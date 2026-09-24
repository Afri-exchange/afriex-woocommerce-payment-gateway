<?php
/**
 * E.164 phone normalisation.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the phone number a shopper typed into E.164 for Afriex.
 *
 * Afriex requires E.164 for customers and checkout sessions, and 1.0 sent the
 * billing phone untouched, so "0803 123 4567" reached Afriex as-is (F41). The
 * rules live in normalise(), which takes the calling code as an argument and
 * needs no WordPress, so it can be unit tested. to_e164() is the thin wrapper
 * that looks the calling code up from WooCommerce's own table.
 */
class Afriex_Phone {

	/**
	 * E.164 allows at most 15 digits after the "+".
	 */
	private const MAX_DIGITS = 15;

	/**
	 * Shortest number accepted, counting the calling code. Anything shorter is
	 * a typo, and sending it would only earn a validation error from Afriex.
	 */
	private const MIN_DIGITS = 8;

	/**
	 * Calling codes whose national numbers keep their leading 0 in
	 * international format (Italy, San Marino, Vatican City). Dropping it, as
	 * for trunk-prefix countries, would produce a different number.
	 */
	private const KEEP_LEADING_ZERO = array( '39', '378', '379' );

	/**
	 * Normalise a billing phone to E.164 for a billing country.
	 *
	 * @param string $phone   Phone as typed.
	 * @param string $country ISO 3166-1 alpha-2 billing country.
	 * @return string|null E.164 number, or null when it cannot be made valid.
	 */
	public static function to_e164( string $phone, string $country ): ?string {
		return self::normalise( $phone, self::calling_code( $country ) );
	}

	/**
	 * The country's calling code from WooCommerce, digits only ("234").
	 *
	 * @param string $country ISO 3166-1 alpha-2 code.
	 */
	public static function calling_code( string $country ): string {
		$country = strtoupper( trim( $country ) );

		if ( '' === $country || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$code = WC()->countries->get_country_calling_code( $country );

		if ( is_array( $code ) ) {
			$code = (string) reset( $code );
		}

		return (string) preg_replace( '/\D/', '', (string) $code );
	}

	/**
	 * The WordPress-free core of the conversion.
	 *
	 * Rules, in order:
	 * - Spaces, dots, dashes, slashes and brackets are dropped.
	 * - A leading "00" is the international prefix and becomes "+".
	 * - "+<code>0..." for the billing country's own code drops the 0: shoppers
	 *   often write "+234 0803..." by prefixing the code to the trunk form.
	 * - A number already in "+" form is kept.
	 * - A national number with the trunk prefix "0" loses it and gains the
	 *   calling code, except in the countries that keep the 0.
	 * - Digits that already start with the calling code and are long enough to
	 *   be international are given a "+".
	 * - Anything else gets the calling code prefixed.
	 * Finally the result must be "+" and 8 to 15 digits.
	 * Calling codes starting with 1 (the NANP) follow normalise_nanp() instead.
	 *
	 * @param string $phone        Phone as typed.
	 * @param string $calling_code Calling code of the billing country, with or
	 *                             without "+". May be empty.
	 * @return string|null
	 */
	public static function normalise( string $phone, string $calling_code ): ?string {
		$calling_code = (string) preg_replace( '/\D/', '', $calling_code );
		$phone        = trim( $phone );

		if ( '' === $phone ) {
			return null;
		}

		// North American Numbering Plan members (US, CA, JM, DO, PR...) share
		// country code 1. WooCommerce lists some of them with their area code
		// attached ("+1809", "+1876"); that is country code 1 plus an area
		// code, not a longer country code, and needs its own rules.
		if ( '' !== $calling_code && '1' === $calling_code[0] ) {
			return self::normalise_nanp( $phone, (string) substr( $calling_code, 1 ) );
		}

		$compact = (string) preg_replace( '/[\s.\-\/()\x{00A0}]+/u', '', $phone );

		if ( 0 === strpos( $compact, '00' ) ) {
			$compact = '+' . substr( $compact, 2 );
		}

		// Only digits may remain, apart from one leading "+".
		if ( ! preg_match( '/^\+?\d+$/', $compact ) ) {
			return null;
		}

		if ( '+' === $compact[0] ) {
			$digits = substr( $compact, 1 );

			if ( '' !== $calling_code
				&& ! in_array( $calling_code, self::KEEP_LEADING_ZERO, true )
				&& 0 === strpos( $digits, $calling_code . '0' )
			) {
				$digits = $calling_code . substr( $digits, strlen( $calling_code ) + 1 );
			}

			return self::finish( $digits );
		}

		if ( '' === $calling_code ) {
			// A national number means nothing without a country to place it in.
			return null;
		}

		$digits = $compact;

		if ( '0' === $digits[0] ) {
			if ( ! in_array( $calling_code, self::KEEP_LEADING_ZERO, true ) ) {
				$digits = substr( $digits, 1 );
			}

			return self::finish( $calling_code . $digits );
		}

		if ( 0 === strpos( $digits, $calling_code ) && strlen( $digits ) >= strlen( $calling_code ) + self::MIN_DIGITS ) {
			return self::finish( $digits );
		}

		return self::finish( $calling_code . $digits );
	}

	/**
	 * NANP rules: numbers are 10 digits (3-digit area code, 7-digit local);
	 * the national prefix is "1", not "0"; a 7-digit local number takes the
	 * country's area code when WooCommerce gave one.
	 *
	 * @param string $phone     Phone as typed, trimmed and non-empty.
	 * @param string $area_code Area code implied by the country ("809"), or
	 *                          empty for US and CA.
	 */
	private static function normalise_nanp( string $phone, string $area_code ): ?string {
		$compact = (string) preg_replace( '/[\s.\-\/()\x{00A0}]+/u', '', $phone );

		if ( 0 === strpos( $compact, '00' ) ) {
			// International format from abroad: must be country code 1 to be
			// a NANP number; any other country is kept as typed.
			$compact = '+' . substr( $compact, 2 );
		} elseif ( 0 === strpos( $compact, '011' ) ) {
			// NANP's own international prefix.
			$compact = '+' . substr( $compact, 3 );
		}

		if ( ! preg_match( '/^\+?\d+$/', $compact ) ) {
			return null;
		}

		if ( '+' === $compact[0] ) {
			return self::finish( substr( $compact, 1 ) );
		}

		$digits = $compact;

		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		} elseif ( 7 === strlen( $digits ) && 3 === strlen( $area_code ) ) {
			$digits = $area_code . $digits;
		}

		// Area codes and exchange codes never start with 0 or 1.
		if ( ! preg_match( '/^[2-9]\d{2}[2-9]\d{6}$/', $digits ) ) {
			return null;
		}

		return '+1' . $digits;
	}

	/**
	 * Final shape check: "+" and 8 to 15 digits, not starting with 0.
	 *
	 * @param string $digits Digits including the calling code, no "+".
	 */
	private static function finish( string $digits ): ?string {
		$length = strlen( $digits );

		if ( $length < self::MIN_DIGITS || $length > self::MAX_DIGITS || '0' === $digits[0] ) {
			return null;
		}

		return '+' . $digits;
	}
}
