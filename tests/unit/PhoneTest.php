<?php
/**
 * Unit tests for Afriex_Phone.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Afriex rejects anything that is not E.164 (F41), so the formats shoppers
 * actually type in the plugin's main markets are pinned down here.
 */
class PhoneTest extends TestCase {

	/**
	 * @dataProvider normalise_cases
	 */
	public function test_normalise( string $phone, string $calling_code, ?string $expected ): void {
		$this->assertSame( $expected, Afriex_Phone::normalise( $phone, $calling_code ) );
	}

	public function normalise_cases(): array {
		return array(
			// Nigeria, +234.
			'NG trunk 0 with spaces'     => array( '0803 123 4567', '+234', '+2348031234567' ),
			'NG trunk 0 compact'         => array( '08031234567', '+234', '+2348031234567' ),
			'NG no trunk'                => array( '8031234567', '+234', '+2348031234567' ),
			'NG code without plus'       => array( '2348031234567', '+234', '+2348031234567' ),
			'NG already E.164'           => array( '+2348031234567', '+234', '+2348031234567' ),
			'NG E.164 with spaces'       => array( '+234 803 123 4567', '+234', '+2348031234567' ),
			'NG plus code then trunk 0'  => array( '+234 0803 123 4567', '+234', '+2348031234567' ),
			'NG 00 prefix'               => array( '002348031234567', '+234', '+2348031234567' ),
			'NG brackets and dashes'     => array( '(0803) 123-4567', '+234', '+2348031234567' ),
			'NG dots'                    => array( '0803.123.4567', '+234', '+2348031234567' ),
			'NG code given bare'         => array( '08031234567', '234', '+2348031234567' ),

			// Kenya, +254.
			'KE trunk 0'                 => array( '0712 345 678', '+254', '+254712345678' ),
			'KE no trunk'                => array( '712345678', '+254', '+254712345678' ),
			'KE already E.164'           => array( '+254712345678', '+254', '+254712345678' ),
			'KE 00 prefix'               => array( '00254 712 345 678', '+254', '+254712345678' ),

			// Ghana, +233.
			'GH trunk 0'                 => array( '024 123 4567', '+233', '+233241234567' ),
			'GH compact'                 => array( '0241234567', '+233', '+233241234567' ),
			'GH already E.164'           => array( '+233 24 123 4567', '+233', '+233241234567' ),

			// United States and the NANP, +1.
			'US brackets'                => array( '(415) 555-2671', '+1', '+14155552671' ),
			'US national prefix 1'       => array( '1-415-555-2671', '+1', '+14155552671' ),
			'US dots'                    => array( '415.555.2671', '+1', '+14155552671' ),
			'US already E.164'           => array( '+1 415 555 2671', '+1', '+14155552671' ),
			'US 00 prefix'               => array( '0014155552671', '+1', '+14155552671' ),
			'US dialling abroad via 011' => array( '011 44 20 7946 0958', '+1', '+442079460958' ),
			'US seven digits no area'    => array( '555-2671', '+1', null ),
			'US area code starting 1'    => array( '1234567890', '+1', null ),
			'US too long'                => array( '415555267100', '+1', null ),
			'DO area code from WC'       => array( '809-555-1234', '+1809', '+18095551234' ),
			'DO local seven digits'      => array( '555-1234', '+1809', '+18095551234' ),
			'DO already E.164'           => array( '+1 809 555 1234', '+1809', '+18095551234' ),

			// Countries that keep the leading 0 internationally.
			'IT keeps 0'                 => array( '06 1234 5678', '+39', '+390612345678' ),
			'IT E.164 keeps 0'           => array( '+39 06 1234 5678', '+39', '+390612345678' ),

			// A foreign E.164 number stays as typed whatever the billing country.
			'UK number, NG billing'      => array( '+44 7911 123456', '+234', '+447911123456' ),
			'UK 00 number, NG billing'   => array( '0044 7911 123456', '+234', '+447911123456' ),
			'E.164 with no country code' => array( '+2348031234567', '', '+2348031234567' ),

			// Junk.
			'empty'                      => array( '', '+234', null ),
			'spaces only'                => array( '   ', '+234', null ),
			'letters'                    => array( 'abc', '+234', null ),
			'plus only'                  => array( '+', '+234', null ),
			'vanity letters'             => array( '0803-CALL-NOW', '+234', null ),
			'extension'                  => array( '+234 803 123 4567 ext 12', '+234', null ),
			'two plus signs'             => array( '++2348031234567', '+234', null ),
			'plus in the middle'         => array( '0803+1234567', '+234', null ),
			'too short'                  => array( '12', '+234', null ),
			'trunk 0 only'               => array( '0', '+234', null ),
			'too long for E.164'         => array( '+1234567890123456', '+234', null ),
			'E.164 cannot start with 0'  => array( '+0123456789', '+234', null ),
			'national without a country' => array( '08031234567', '', null ),
		);
	}

	public function test_to_e164_without_woocommerce_accepts_only_e164(): void {
		// Outside WordPress there is no calling-code table: a national number
		// cannot be placed, but a full international one needs no country.
		$this->assertSame( '', Afriex_Phone::calling_code( 'NG' ) );
		$this->assertNull( Afriex_Phone::to_e164( '0803 123 4567', 'NG' ) );
		$this->assertSame( '+2348031234567', Afriex_Phone::to_e164( '+234 803 123 4567', 'NG' ) );
	}
}
