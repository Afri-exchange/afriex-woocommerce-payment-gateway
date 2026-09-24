<?php
/**
 * Exact money arithmetic for comparing what was asked for with what was paid.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts decimal amounts to integer minor units with string arithmetic.
 *
 * Floats cannot be trusted here. 0.1 + 0.2 is not 0.3 in binary floating
 * point, and Afriex reports amounts as decimal strings that may carry more
 * decimals than the currency has (e.g. "14101.041" NGN). The 1.0 code
 * compared floats with a 0.01 tolerance and ignored the currency, which is how
 * N200 could settle a $200 order (F24, F31). Everything that decides whether a
 * payment matches an order goes through this class and ends in an integer
 * comparison.
 *
 * The class is deliberately free of WordPress calls (except an optional
 * wc_format_decimal() fallback for localised strings) so it can be unit
 * tested without WordPress. WooCommerce's own totals are already plain
 * decimal strings, so they never need that fallback.
 */
class Afriex_Money {

	/**
	 * ISO 4217 minor-unit exponents that are not 2.
	 *
	 * Every currency not listed has exponent 2. The list is the ISO 4217
	 * "minor unit" column for active codes; MGA and MRU are non-decimal in
	 * practice but ISO still lists them as 2, so they are not here.
	 */
	private const EXPONENTS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
		'CLF' => 4,
		'UYW' => 4,
	);

	/**
	 * Largest number of digits a minor-unit amount may have and still fit in a
	 * signed 64-bit integer with room to spare. Anything longer is refused
	 * rather than silently wrapped.
	 */
	private const MAX_MINOR_DIGITS = 18;

	/**
	 * The ISO 4217 exponent of a currency: 2 unless the map says otherwise.
	 *
	 * @param string $currency ISO 4217 code, any case.
	 */
	public static function exponent( string $currency ): int {
		$currency = strtoupper( trim( $currency ) );

		return isset( self::EXPONENTS[ $currency ] ) ? self::EXPONENTS[ $currency ] : 2;
	}

	/**
	 * Normalise an amount to a canonical decimal string, or null when it is not
	 * a plain decimal number.
	 *
	 * Accepts any number of decimals ("10", "10.5", "14101.041", ".5", "5."),
	 * an optional sign, ints, and floats (converted through a fixed-precision
	 * format so no exponent notation leaks in). Refuses thousands separators,
	 * exponent notation, empty strings and anything else: an amount that has to
	 * be guessed at is not an amount that should settle an order.
	 *
	 * @param mixed $value Amount as received.
	 * @return string|null Canonical form such as "-12.5", "0" or "14101.041".
	 */
	public static function from_string( $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				return null;
			}

			// 12 decimals is well past any currency's exponent and well inside
			// what a double represents exactly for realistic order totals.
			$value = sprintf( '%.12F', $value );
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( ! preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $parts ) ) {
			return null;
		}

		$sign     = '-' === $parts[1] ? '-' : '';
		$integer  = ltrim( $parts[2], '0' );
		$fraction = isset( $parts[3] ) ? rtrim( $parts[3], '0' ) : '';

		// "." or "" or "+" on its own is not a number.
		if ( '' === $parts[2] && ( ! isset( $parts[3] ) || '' === $parts[3] ) ) {
			return null;
		}

		if ( '' === $integer ) {
			$integer = '0';
		}

		$canonical = '' === $fraction ? $integer : $integer . '.' . $fraction;

		// Negative zero is zero.
		if ( '0' === $canonical ) {
			return '0';
		}

		return $sign . $canonical;
	}

	/**
	 * Convert an amount to integer minor units of a currency.
	 *
	 * Rounds half up (away from zero) at the currency's exponent, using string
	 * arithmetic only. Returns null when the amount is not a number or does not
	 * fit in an integer.
	 *
	 * WooCommerce totals are already plain decimal strings. A value that does
	 * not parse is given one chance through wc_format_decimal(), which knows the
	 * store's decimal separator, when WooCommerce is loaded.
	 *
	 * @param mixed  $amount   Amount in major units, e.g. "49.99".
	 * @param string $currency ISO 4217 code.
	 */
	public static function to_minor( $amount, string $currency ): ?int {
		$canonical = self::from_string( $amount );

		if ( null === $canonical && is_string( $amount ) && function_exists( 'wc_format_decimal' ) ) {
			$canonical = self::from_string( (string) wc_format_decimal( $amount ) );
		}

		if ( null === $canonical ) {
			return null;
		}

		$exponent = self::exponent( $currency );
		$negative = 0 === strpos( $canonical, '-' );
		$unsigned = $negative ? substr( $canonical, 1 ) : $canonical;

		$dot      = strpos( $unsigned, '.' );
		$integer  = false === $dot ? $unsigned : substr( $unsigned, 0, $dot );
		$fraction = false === $dot ? '' : substr( $unsigned, $dot + 1 );

		// Pad so there is always one digit beyond the exponent to round on.
		$fraction = str_pad( $fraction, $exponent + 1, '0' );
		$kept     = substr( $fraction, 0, $exponent );
		$next     = (int) $fraction[ $exponent ];

		$digits = ltrim( $integer . $kept, '0' );
		$digits = '' === $digits ? '0' : $digits;

		if ( $next >= 5 ) {
			$digits = self::increment_digits( $digits );
		}

		if ( strlen( $digits ) > self::MAX_MINOR_DIGITS ) {
			return null;
		}

		$minor = (int) $digits;

		return $negative && 0 !== $minor ? -$minor : $minor;
	}

	/**
	 * Format integer minor units back to a major-unit decimal string.
	 *
	 * Used where Afriex takes major units (virtual-account amounts) so the
	 * value sent is derived from the same integer the plugin compares against.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217 code.
	 */
	public static function to_major_string( int $minor, string $currency ): string {
		$exponent = self::exponent( $currency );
		$negative = $minor < 0;
		$digits   = (string) abs( $minor );

		if ( 0 === $exponent ) {
			return ( $negative ? '-' : '' ) . $digits;
		}

		$digits   = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		$integer  = substr( $digits, 0, -$exponent );
		$fraction = substr( $digits, -$exponent );

		return ( $negative ? '-' : '' ) . $integer . '.' . $fraction;
	}

	/**
	 * Whether a received amount and currency match what was expected, exactly.
	 *
	 * Currency is compared first and case-insensitively; the amount is compared
	 * as integers of the received currency's minor units. An unparseable amount
	 * never matches.
	 *
	 * @param mixed  $received_amount   Received amount in major units.
	 * @param string $received_currency Received ISO 4217 code.
	 * @param int    $expected_minor    Expected amount in minor units.
	 * @param string $expected_currency Expected ISO 4217 code.
	 */
	public static function matches( $received_amount, string $received_currency, int $expected_minor, string $expected_currency ): bool {
		$received_currency = strtoupper( trim( $received_currency ) );

		if ( '' === $received_currency || strtoupper( trim( $expected_currency ) ) !== $received_currency ) {
			return false;
		}

		$received_minor = self::to_minor( $received_amount, $received_currency );

		return null !== $received_minor && $received_minor === $expected_minor;
	}

	/**
	 * Compare two major-unit amounts of one currency at its exponent.
	 *
	 * Both sides are rounded to minor units first, so "10.004" and "10" are
	 * equal in NGN while "10.005" is greater. Returns null when either side is
	 * not a number, so a caller can never mistake garbage for equality.
	 *
	 * @param mixed  $a        First amount.
	 * @param mixed  $b        Second amount.
	 * @param string $currency ISO 4217 code.
	 * @return int|null -1, 0 or 1, or null when either side does not parse.
	 */
	public static function compare( $a, $b, string $currency ): ?int {
		$a_minor = self::to_minor( $a, $currency );
		$b_minor = self::to_minor( $b, $currency );

		if ( null === $a_minor || null === $b_minor ) {
			return null;
		}

		return $a_minor <=> $b_minor;
	}

	/**
	 * Whether two major-unit amounts of one currency are equal in minor units.
	 *
	 * @param mixed  $a        First amount.
	 * @param mixed  $b        Second amount.
	 * @param string $currency ISO 4217 code.
	 */
	public static function equals( $a, $b, string $currency ): bool {
		return 0 === self::compare( $a, $b, $currency );
	}

	/**
	 * Whether Afriex Checkout may be offered for a currency.
	 *
	 * Afriex defines the session minimum of 100 minor units as "1 major unit",
	 * which implies it scales every currency by 100. For an ISO exponent-0
	 * currency (UGX, XOF) sending ISO minor units could charge 1/100th of the
	 * price, and for exponent 3 (KWD) ten times it. Until Afriex confirms its
	 * scaling (plan section 16, question 3), only exponent-2 currencies are
	 * allowed. The code must also look like an ISO code at all.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function is_supported_for_checkout( string $currency ): bool {
		$currency = strtoupper( trim( $currency ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) && 2 === self::exponent( $currency );
	}

	/**
	 * Add one to a non-negative decimal digit string.
	 *
	 * @param string $digits Digits only, no sign.
	 */
	private static function increment_digits( string $digits ): string {
		$index = strlen( $digits ) - 1;

		while ( $index >= 0 ) {
			if ( '9' !== $digits[ $index ] ) {
				$digits[ $index ] = (string) ( (int) $digits[ $index ] + 1 );
				return $digits;
			}

			$digits[ $index ] = '0';
			--$index;
		}

		return '1' . $digits;
	}
}
