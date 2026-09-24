<?php
/**
 * Unit tests for Checkout reference minting and parsing.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * The reference is how a Checkout payment finds its order, so it is parsed
 * strictly and never leniently. A pattern that accepted a little more than it
 * should would let a made-up reference name an order it has nothing to do
 * with, and binding would then rest on a string a stranger chose.
 */
class ReferenceTest extends TestCase {

	public function test_minted_references_parse_back_to_their_order(): void {
		foreach ( array( 1, 9, 42, 123456, 999999 ) as $order_id ) {
			$reference = Afriex_Order_Meta::mint_reference( $order_id );

			$this->assertSame( $order_id, Afriex_Order_Meta::parse_reference( $reference ) );
		}
	}

	public function test_minted_references_are_the_documented_shape(): void {
		$reference = Afriex_Order_Meta::mint_reference( 77 );

		$this->assertSame( 1, preg_match( '/^wc_77_[0-9a-f]{16}$/', $reference ) );
		$this->assertSame( 22, strlen( $reference ) );
	}

	public function test_minted_references_do_not_repeat(): void {
		$seen = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$reference = Afriex_Order_Meta::mint_reference( 5 );

			$this->assertArrayNotHasKey( $reference, $seen );
			$seen[ $reference ] = true;
		}
	}

	/**
	 * @dataProvider parse_cases
	 *
	 * @param string $reference Candidate reference.
	 * @param int    $expected  Expected order id, or 0.
	 */
	public function test_parse_reference( string $reference, int $expected ): void {
		$this->assertSame( $expected, Afriex_Order_Meta::parse_reference( $reference ) );
	}

	public function parse_cases(): array {
		$hex = 'a1b2c3d4e5f60718';

		return array(
			'a real one'                => array( 'wc_12_' . $hex, 12 ),
			'a long order id'           => array( 'wc_987654321_' . $hex, 987654321 ),
			'surrounding whitespace'    => array( "  wc_12_{$hex}\n", 12 ),

			// Everything below must parse to nothing.
			'upper-case hex'            => array( 'wc_12_A1B2C3D4E5F60718', 0 ),
			'fifteen hex digits'        => array( 'wc_12_a1b2c3d4e5f6071', 0 ),
			'seventeen hex digits'      => array( 'wc_12_a1b2c3d4e5f607188', 0 ),
			'not hex'                   => array( 'wc_12_zzzzzzzzzzzzzzzz', 0 ),
			'a leading zero in the id'  => array( 'wc_012_' . $hex, 0 ),
			'no id'                     => array( 'wc__' . $hex, 0 ),
			'a negative id'             => array( 'wc_-1_' . $hex, 0 ),
			'the wrong prefix'          => array( 'wo_12_' . $hex, 0 ),
			'no prefix'                 => array( '12_' . $hex, 0 ),
			'something before it'       => array( 'xwc_12_' . $hex, 0 ),
			'something after it'        => array( 'wc_12_' . $hex . 'x', 0 ),
			'a newline smuggled inside' => array( "wc_12_{$hex}\nwc_13_{$hex}", 0 ),
			'an sql fragment'           => array( "wc_12_' OR 1=1 --", 0 ),
			'a 1.0 pool reference'      => array( 'AFX-ORDER-12', 0 ),
			'empty'                     => array( '', 0 ),
			'the prefix alone'          => array( 'wc_', 0 ),
		);
	}
}
