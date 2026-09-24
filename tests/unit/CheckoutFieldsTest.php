<?php
/**
 * Unit tests for the channel list and the metadata of a checkout session.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Both of these decide what goes into the create-session request, and both
 * failure modes are a 422 on a live order rather than anything a developer
 * would see: an unknown channel value, or a metadata value one byte over the
 * limit. So they are checked here, against the rules in plan section 3, rather
 * than against what Afriex happens to have rejected.
 */
class CheckoutFieldsTest extends TestCase {
	/*
	 * ---------------------------------------------------------------------
	 * Channels.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The list is what Afriex's OpenAPI enumerates, spelled Afriex's way.
	 */
	public function test_the_channel_list_is_afriex_own_values(): void {
		$this->assertSame(
			array( 'CARD', 'MOBILE_MONEY', 'VIRTUAL_BANK_ACCOUNT' ),
			Afriex_Checkout_Fields::CHANNELS
		);
	}

	public function test_every_channel_survives_sanitising(): void {
		$this->assertSame(
			Afriex_Checkout_Fields::CHANNELS,
			Afriex_Checkout_Fields::sanitize_channels( Afriex_Checkout_Fields::CHANNELS )
		);
	}

	/**
	 * @dataProvider channel_cases
	 *
	 * @param mixed $input    Anything a form or an API response offered.
	 * @param array $expected The channels that should survive.
	 */
	public function test_sanitize_channels( $input, array $expected ): void {
		$this->assertSame( $expected, Afriex_Checkout_Fields::sanitize_channels( $input ) );
	}

	public function channel_cases(): array {
		return array(
			'nothing at all'            => array( array(), array() ),
			'null'                      => array( null, array() ),
			'an empty string'           => array( '', array() ),
			'a bare string'             => array( 'CARD', array( 'CARD' ) ),
			'lower case'                => array( array( 'card' ), array( 'CARD' ) ),
			'padded'                    => array( array( "  card \n" ), array( 'CARD' ) ),
			'an unknown channel'        => array( array( 'CRYPTO' ), array() ),
			'a near miss'               => array( array( 'BANK_ACCOUNT' ), array() ),
			'known and unknown mixed'   => array( array( 'CRYPTO', 'CARD' ), array( 'CARD' ) ),
			'a repeat'                  => array( array( 'CARD', 'card', 'CARD' ), array( 'CARD' ) ),
			'out of order'              => array(
				array( 'VIRTUAL_BANK_ACCOUNT', 'CARD' ),
				array( 'CARD', 'VIRTUAL_BANK_ACCOUNT' ),
			),
			'a nested array is dropped' => array( array( array( 'CARD' ) ), array() ),
			'an object is dropped'      => array( array( new stdClass() ), array() ),
			'a number is dropped'       => array( array( 42 ), array() ),
			'keys are ignored'          => array(
				array(
					'a' => 'MOBILE_MONEY',
					'b' => 'CARD',
				),
				array( 'CARD', 'MOBILE_MONEY' ),
			),
		);
	}

	/**
	 * The order is Afriex's answer order, not the admin's click order: Afriex
	 * returns the requested channels intersected with the currency's, in
	 * request order, and the order screen shows that list back.
	 */
	public function test_channels_come_back_in_the_class_order(): void {
		$this->assertSame(
			array( 'CARD', 'MOBILE_MONEY', 'VIRTUAL_BANK_ACCOUNT' ),
			Afriex_Checkout_Fields::sanitize_channels(
				array( 'VIRTUAL_BANK_ACCOUNT', 'MOBILE_MONEY', 'CARD' )
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Metadata.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Afriex accepts string values only; anything else is a 422.
	 */
	public function test_metadata_values_become_strings(): void {
		$this->assertSame(
			array(
				'id'     => '42',
				'ratio'  => '0.5',
				'yes'    => '1',
				'string' => 'plain',
			),
			Afriex_Checkout_Fields::metadata(
				array(
					'id'     => 42,
					'ratio'  => 0.5,
					'yes'    => true,
					'string' => 'plain',
				)
			)
		);
	}

	/**
	 * An empty value is dropped rather than sent: false stringifies to '',
	 * Afriex allows 50 entries, and an empty one says nothing.
	 */
	public function test_empty_values_are_dropped(): void {
		$this->assertSame(
			array( 'kept' => 'x' ),
			Afriex_Checkout_Fields::metadata(
				array(
					'kept'   => 'x',
					'false'  => false,
					'empty'  => '',
					'null'   => null,
					'array'  => array( 1, 2 ),
					'object' => new stdClass(),
				)
			)
		);
	}

	public function test_an_entry_with_no_name_is_dropped(): void {
		$this->assertSame(
			array( 'kept' => 'x' ),
			Afriex_Checkout_Fields::metadata(
				array(
					''     => 'no key at all',
					'  '   => 'nothing but spaces',
					'kept' => 'x',
				)
			)
		);
	}

	public function test_a_value_at_the_limit_is_untouched(): void {
		$value = str_repeat( 'a', Afriex_Checkout_Fields::METADATA_MAX );

		$this->assertSame(
			array( 'note' => $value ),
			Afriex_Checkout_Fields::metadata( array( 'note' => $value ) )
		);
	}

	public function test_a_longer_value_is_cut_to_the_limit(): void {
		$metadata = Afriex_Checkout_Fields::metadata(
			array( 'note' => str_repeat( 'a', Afriex_Checkout_Fields::METADATA_MAX + 50 ) )
		);

		$this->assertSame( Afriex_Checkout_Fields::METADATA_MAX, strlen( $metadata['note'] ) );
	}

	/**
	 * The limit is in bytes, and a multi-byte character cut in half would be
	 * invalid UTF-8 in a JSON body — which json_encode() refuses outright, so
	 * the whole request would be lost, not just the metadata.
	 */
	public function test_a_multibyte_value_is_cut_on_a_character_boundary(): void {
		if ( ! function_exists( 'mb_strcut' ) ) {
			$this->markTestSkipped( 'mbstring is not available in this build.' );
		}

		// 'é' is two bytes, so the cut lands mid-character unless it is moved.
		$value    = str_repeat( 'é', Afriex_Checkout_Fields::METADATA_MAX );
		$metadata = Afriex_Checkout_Fields::metadata( array( 'note' => $value ) );

		$this->assertLessThanOrEqual( Afriex_Checkout_Fields::METADATA_MAX, strlen( $metadata['note'] ) );
		$this->assertSame( Afriex_Checkout_Fields::METADATA_MAX / 2, mb_strlen( $metadata['note'], 'UTF-8' ) );

		// The real test: the encoder the request goes through accepts it.
		// json_encode() returns false for invalid UTF-8, and the whole body
		// would be lost, not just this value.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- These tests deliberately run without WordPress, so wp_json_encode() does not exist.
		$this->assertIsString( json_encode( $metadata['note'] ) );
	}

	public function test_no_more_than_fifty_entries_are_sent(): void {
		$values = array();

		for ( $i = 0; $i < Afriex_Checkout_Fields::METADATA_MAX_ENTRIES + 10; $i++ ) {
			$values[ 'k' . $i ] = 'v' . $i;
		}

		$metadata = Afriex_Checkout_Fields::metadata( $values );

		$this->assertCount( Afriex_Checkout_Fields::METADATA_MAX_ENTRIES, $metadata );
		$this->assertArrayHasKey( 'k0', $metadata );
		$this->assertArrayNotHasKey( 'k' . Afriex_Checkout_Fields::METADATA_MAX_ENTRIES, $metadata );
	}

	public function test_metadata_value_on_its_own(): void {
		$this->assertSame( '', Afriex_Checkout_Fields::metadata_value( array( 'x' ) ) );
		$this->assertSame( '7', Afriex_Checkout_Fields::metadata_value( 7 ) );
		$this->assertSame( 'x', Afriex_Checkout_Fields::metadata_value( 'x' ) );
	}
}
