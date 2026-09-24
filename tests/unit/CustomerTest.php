<?php
/**
 * Unit tests for Afriex_Customer.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * F25 turns on three small questions: where the id is in a payload, what a 409
 * code allows, and whether a looked-up customer is really this shopper's. The
 * gateway does the talking; these are the rules it talks by.
 */
class CustomerTest extends TestCase {

	/**
	 * @dataProvider id_cases
	 *
	 * @param mixed  $payload  Anything Afriex might return.
	 * @param string $expected The id that should be read out of it.
	 */
	public function test_extract_id( $payload, string $expected ): void {
		$this->assertSame( $expected, Afriex_Customer::extract_id( $payload ) );
	}

	public function id_cases(): array {
		return array(
			'create response'  => array( array( 'customerId' => 'cus_1' ), 'cus_1' ),
			'409 details.data' => array(
				array(
					'customerId' => 'cus_2',
					'email'      => 'a@b.c',
				),
				'cus_2',
			),
			'id key'           => array( array( 'id' => 'cus_3' ), 'cus_3' ),
			'mongo style'      => array( array( '_id' => 'cus_4' ), 'cus_4' ),
			'customerId wins'  => array(
				array(
					'_id'        => 'cus_4',
					'customerId' => 'cus_5',
				),
				'cus_5',
			),
			'padded'           => array( array( 'customerId' => "  cus_6\n" ), 'cus_6' ),
			'numeric'          => array( array( 'customerId' => 12345 ), '12345' ),
			'empty string'     => array( array( 'customerId' => '   ' ), '' ),
			'null'             => array( array( 'customerId' => null ), '' ),
			'array value'      => array( array( 'customerId' => array( 'x' ) ), '' ),
			'no id at all'     => array( array( 'email' => 'a@b.c' ), '' ),
			'not an array'     => array( 'cus_7', '' ),
			'empty array'      => array( array(), '' ),
		);
	}

	/**
	 * @dataProvider conflict_cases
	 */
	public function test_conflict_decision( string $code, string $expected ): void {
		$this->assertSame( $expected, Afriex_Customer::conflict_decision( $code ) );
	}

	public function conflict_cases(): array {
		return array(
			'email conflict'   => array( 'EMAIL_ALREADY_EXISTS', Afriex_Customer::ADOPT ),
			'email lower case' => array( 'email_already_exists', Afriex_Customer::ADOPT ),
			'email padded'     => array( ' EMAIL_ALREADY_EXISTS ', Afriex_Customer::ADOPT ),
			'phone conflict'   => array( 'PHONE_NUMBER_ALREADY_EXISTS', Afriex_Customer::VERIFY_EMAIL ),
			'phone lower case' => array( 'phone_number_already_exists', Afriex_Customer::VERIFY_EMAIL ),
			'something else'   => array( 'VALIDATION_ERROR', Afriex_Customer::REFUSE ),
			'no code'          => array( '', Afriex_Customer::REFUSE ),
			'a near miss'      => array( 'EMAIL_EXISTS', Afriex_Customer::REFUSE ),
		);
	}

	/**
	 * The phone conflict is the dangerous one: it names whoever registered the
	 * number, who is not necessarily the person checking out.
	 */
	public function test_a_phone_conflict_is_never_adopted_on_the_code_alone(): void {
		$this->assertNotSame(
			Afriex_Customer::ADOPT,
			Afriex_Customer::conflict_decision( 'PHONE_NUMBER_ALREADY_EXISTS' )
		);
	}

	/**
	 * @dataProvider email_cases
	 */
	public function test_same_email( string $a, string $b, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Customer::same_email( $a, $b ) );
	}

	public function email_cases(): array {
		return array(
			'identical'   => array( 'ada@example.com', 'ada@example.com', true ),
			'case'        => array( 'Ada@Example.com', 'ada@example.com', true ),
			'padding'     => array( ' ada@example.com ', 'ada@example.com', true ),
			'different'   => array( 'ada@example.com', 'bob@example.com', false ),
			'both empty'  => array( '', '', false ),
			'one empty'   => array( '', 'ada@example.com', false ),
			'other empty' => array( 'ada@example.com', '', false ),
			'subaddress'  => array( 'ada+1@example.com', 'ada@example.com', false ),
		);
	}

	/**
	 * @dataProvider match_cases
	 *
	 * @param mixed  $customer A row from GET /customer.
	 * @param string $email    The order's email address.
	 * @param bool   $expected Whether it may be used.
	 */
	public function test_matches_email( $customer, string $email, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Customer::matches_email( $customer, $email ) );
	}

	public function match_cases(): array {
		return array(
			'exact match'          => array(
				array(
					'customerId' => 'cus_1',
					'email'      => 'ada@example.com',
				),
				'ada@example.com',
				true,
			),
			'case-insensitive'     => array(
				array(
					'customerId' => 'cus_1',
					'email'      => 'ADA@example.com',
				),
				'ada@example.com',
				true,
			),
			'a different customer' => array(
				array(
					'customerId' => 'cus_1',
					'email'      => 'bob@example.com',
				),
				'ada@example.com',
				false,
			),
			'no email in the row'  => array( array( 'customerId' => 'cus_1' ), 'ada@example.com', false ),
			'no id in the row'     => array( array( 'email' => 'ada@example.com' ), 'ada@example.com', false ),
			'not a row'            => array( null, 'ada@example.com', false ),
			'a WP_Error stand-in'  => array( 'error', 'ada@example.com', false ),
			'empty email asked'    => array(
				array(
					'customerId' => 'cus_1',
					'email'      => 'ada@example.com',
				),
				'',
				false,
			),
		);
	}
}
