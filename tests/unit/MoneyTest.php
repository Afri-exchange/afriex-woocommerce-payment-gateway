<?php
/**
 * Unit tests for Afriex_Money.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Settlement compares integers produced here, so every rounding and parsing
 * rule that decides whether a payment matches an order is pinned down.
 */
class MoneyTest extends TestCase {

	/**
	 * @dataProvider exponent_cases
	 */
	public function test_exponent( string $currency, int $expected ): void {
		$this->assertSame( $expected, Afriex_Money::exponent( $currency ) );
	}

	public function exponent_cases(): array {
		return array(
			'NGN'                => array( 'NGN', 2 ),
			'lower case'         => array( 'ngn', 2 ),
			'padded'             => array( ' usd ', 2 ),
			'KES'                => array( 'KES', 2 ),
			'JPY exponent 0'     => array( 'JPY', 0 ),
			'UGX exponent 0'     => array( 'UGX', 0 ),
			'XOF exponent 0'     => array( 'XOF', 0 ),
			'XAF exponent 0'     => array( 'XAF', 0 ),
			'RWF exponent 0'     => array( 'RWF', 0 ),
			'KWD exponent 3'     => array( 'KWD', 3 ),
			'BHD exponent 3'     => array( 'BHD', 3 ),
			'TND exponent 3'     => array( 'TND', 3 ),
			'CLF exponent 4'     => array( 'CLF', 4 ),
			'unknown defaults 2' => array( 'ZZZ', 2 ),
		);
	}

	/**
	 * @dataProvider to_minor_cases
	 *
	 * @param mixed    $amount   Amount.
	 * @param string   $currency Currency.
	 * @param int|null $expected Expected minor units.
	 */
	public function test_to_minor( $amount, string $currency, ?int $expected ): void {
		$this->assertSame( $expected, Afriex_Money::to_minor( $amount, $currency ) );
	}

	public function to_minor_cases(): array {
		return array(
			// Plain exponent-2 values.
			'two decimals'               => array( '49.99', 'NGN', 4999 ),
			'integer string'             => array( '10', 'NGN', 1000 ),
			'one decimal'                => array( '10.5', 'NGN', 1050 ),
			'zero'                       => array( '0', 'NGN', 0 ),
			'leading zeros'              => array( '007.10', 'NGN', 710 ),
			'leading dot'                => array( '.5', 'NGN', 50 ),
			'trailing dot'               => array( '5.', 'NGN', 500 ),
			'explicit plus'              => array( '+12.5', 'NGN', 1250 ),
			'surrounding spaces'         => array( ' 12.50 ', 'NGN', 1250 ),
			'lower-case currency'        => array( '1.23', 'usd', 123 ),

			// Extra decimals round half up.
			'extra decimals down'        => array( '14101.041', 'NGN', 1410104 ),
			'extra decimals half up'     => array( '14101.045', 'NGN', 1410105 ),
			'half up at one cent'        => array( '0.005', 'NGN', 1 ),
			'just below half'            => array( '0.0049999', 'NGN', 0 ),
			'carry through nines'        => array( '9.995', 'NGN', 1000 ),
			'carry adds a digit'         => array( '99.999', 'USD', 10000 ),
			'many decimals'              => array( '1.004999999999999999', 'USD', 100 ),
			'trailing zeros ignored'     => array( '1.2300000', 'USD', 123 ),

			// Negative amounts round away from zero, and -0 is 0.
			'negative'                   => array( '-1.005', 'USD', -101 ),
			'negative rounds to zero'    => array( '-0.004', 'USD', 0 ),

			// Exponent 0.
			'JPY integer'                => array( '1500', 'JPY', 1500 ),
			'UGX half up'                => array( '1999.5', 'UGX', 2000 ),
			'XOF rounds down'            => array( '1999.4', 'XOF', 1999 ),

			// Exponent 3.
			'KWD three decimals'         => array( '1.234', 'KWD', 1234 ),
			'KWD half up'                => array( '1.2345', 'KWD', 1235 ),
			'KWD carry'                  => array( '0.9995', 'KWD', 1000 ),
			'KWD two decimals'           => array( '1.5', 'KWD', 1500 ),

			// Non-string input.
			'int'                        => array( 5, 'NGN', 500 ),
			'float sum is exact'         => array( 0.1 + 0.2, 'USD', 30 ),
			'float 1.005'                => array( 1.005, 'USD', 101 ),
			'float 49.99'                => array( 49.99, 'NGN', 4999 ),

			// Large values.
			'largest accepted'           => array( '9999999999999999.99', 'NGN', 999999999999999999 ),
			'large NGN order'            => array( '5000000.00', 'NGN', 500000000 ),
			'too many digits'            => array( '99999999999999999.99', 'NGN', null ),
			'overflowing JPY'            => array( '9223372036854775807', 'JPY', null ),
			'rounding pushes past limit' => array( '9999999999999999.995', 'NGN', null ),

			// Refused input.
			'empty'                      => array( '', 'NGN', null ),
			'only a dot'                 => array( '.', 'NGN', null ),
			'only a sign'                => array( '-', 'NGN', null ),
			'letters'                    => array( 'abc', 'NGN', null ),
			'thousands separator'        => array( '1,000.00', 'NGN', null ),
			'exponent notation'          => array( '1e3', 'NGN', null ),
			'two dots'                   => array( '1.2.3', 'NGN', null ),
			'double sign'                => array( '--1', 'NGN', null ),
			'currency symbol'            => array( '$10', 'USD', null ),
			'null'                       => array( null, 'NGN', null ),
			'bool'                       => array( true, 'NGN', null ),
			'array'                      => array( array( '1' ), 'NGN', null ),
			'NAN'                        => array( NAN, 'NGN', null ),
			'INF'                        => array( INF, 'NGN', null ),
		);
	}

	/**
	 * @dataProvider from_string_cases
	 *
	 * @param mixed       $value    Input.
	 * @param string|null $expected Canonical form.
	 */
	public function test_from_string( $value, ?string $expected ): void {
		$this->assertSame( $expected, Afriex_Money::from_string( $value ) );
	}

	public function from_string_cases(): array {
		return array(
			'strips trailing zeros' => array( '14101.0410', '14101.041' ),
			'negative zero'         => array( '-0.00', '0' ),
			'zeros'                 => array( '000', '0' ),
			'negative'              => array( '-5', '-5' ),
			'plus sign dropped'     => array( '+5.50', '5.5' ),
			'trailing dot'          => array( '1.', '1' ),
			'leading dot'           => array( '-.25', '-0.25' ),
			'twenty decimals kept'  => array( '0.12345678901234567890', '0.1234567890123456789' ),
			'int'                   => array( 10, '10' ),
			'float'                 => array( 2.5, '2.5' ),
			'garbage'               => array( '12abc', null ),
			'inner space'           => array( '1 000', null ),
		);
	}

	/**
	 * @dataProvider to_major_cases
	 */
	public function test_to_major_string( int $minor, string $currency, string $expected ): void {
		$this->assertSame( $expected, Afriex_Money::to_major_string( $minor, $currency ) );
	}

	public function to_major_cases(): array {
		return array(
			'NGN'       => array( 4999, 'NGN', '49.99' ),
			'under one' => array( 5, 'NGN', '0.05' ),
			'zero'      => array( 0, 'NGN', '0.00' ),
			'negative'  => array( -5, 'NGN', '-0.05' ),
			'JPY'       => array( 1500, 'JPY', '1500' ),
			'KWD'       => array( 1234, 'KWD', '1.234' ),
		);
	}

	public function test_round_trip_is_exact(): void {
		foreach ( array( '0.01', '0.10', '1.00', '49.99', '1234567.89' ) as $amount ) {
			$minor = Afriex_Money::to_minor( $amount, 'NGN' );
			$this->assertSame( $amount, Afriex_Money::to_major_string( $minor, 'NGN' ), $amount );
		}
	}

	/**
	 * @dataProvider matches_cases
	 *
	 * @param mixed $amount Received amount.
	 */
	public function test_matches( $amount, string $currency, int $expected_minor, string $expected_currency, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Money::matches( $amount, $currency, $expected_minor, $expected_currency ) );
	}

	public function matches_cases(): array {
		return array(
			'exact'                => array( '49.99', 'NGN', 4999, 'NGN', true ),
			'currency case'        => array( '49.99', 'ngn', 4999, 'NGN', true ),
			'extra decimal rounds' => array( '49.991', 'NGN', 4999, 'NGN', true ),
			'rounds to next cent'  => array( '49.995', 'NGN', 4999, 'NGN', false ),
			'one cent short'       => array( '49.98', 'NGN', 4999, 'NGN', false ),
			'wrong currency'       => array( '49.99', 'USD', 4999, 'NGN', false ),
			'empty currency'       => array( '49.99', '', 4999, 'NGN', false ),
			'unparseable amount'   => array( 'n/a', 'NGN', 4999, 'NGN', false ),
			'N200 is not $200'     => array( '200', 'NGN', 20000, 'USD', false ),
		);
	}

	public function test_compare_and_equals(): void {
		$this->assertSame( 0, Afriex_Money::compare( '10.004', '10', 'NGN' ) );
		$this->assertSame( 1, Afriex_Money::compare( '10.005', '10', 'NGN' ) );
		$this->assertSame( -1, Afriex_Money::compare( '9.99', '10', 'NGN' ) );
		$this->assertSame( 1, Afriex_Money::compare( '10.4', '10', 'NGN' ) );
		$this->assertSame( 0, Afriex_Money::compare( '10.4', '10', 'JPY' ) );
		$this->assertNull( Afriex_Money::compare( 'x', '10', 'NGN' ) );
		$this->assertNull( Afriex_Money::compare( '10', '', 'NGN' ) );

		$this->assertTrue( Afriex_Money::equals( '0.1', 0.1, 'USD' ) );
		$this->assertTrue( Afriex_Money::equals( 0.1 + 0.2, '0.3', 'USD' ) );
		$this->assertFalse( Afriex_Money::equals( 'x', 'x', 'USD' ) );
	}

	/**
	 * @dataProvider checkout_support_cases
	 */
	public function test_is_supported_for_checkout( string $currency, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Money::is_supported_for_checkout( $currency ) );
	}

	public function checkout_support_cases(): array {
		return array(
			'NGN'        => array( 'NGN', true ),
			'USD'        => array( 'USD', true ),
			'KES'        => array( 'KES', true ),
			'GHS'        => array( 'GHS', true ),
			'lower case' => array( 'ngn', true ),
			'UGX'        => array( 'UGX', false ),
			'XOF'        => array( 'XOF', false ),
			'XAF'        => array( 'XAF', false ),
			'RWF'        => array( 'RWF', false ),
			'JPY'        => array( 'JPY', false ),
			'KWD'        => array( 'KWD', false ),
			'empty'      => array( '', false ),
			'too short'  => array( 'NG', false ),
			'digits'     => array( 'N1N', false ),
		);
	}
}
