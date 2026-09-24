<?php
/**
 * Unit tests for Afriex_Status_Mapper.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * The enum decides whether an order completes, fails or waits, so every
 * published status is pinned to its class here, and the two statuses 1.0
 * invented are pinned to being unrecognised.
 */
class StatusMapperTest extends TestCase {

	/**
	 * @dataProvider class_cases
	 *
	 * @param string $status   Afriex status.
	 * @param string $expected Expected class.
	 */
	public function test_classify( string $status, string $expected ): void {
		$this->assertSame( $expected, Afriex_Status_Mapper::classify( $status ) );
	}

	public function class_cases(): array {
		return array(
			// The one status that pays for an order.
			'SUCCESS'                     => array( 'SUCCESS', Afriex_Status_Mapper::CLASS_SUCCESS ),
			'lower case'                  => array( 'success', Afriex_Status_Mapper::CLASS_SUCCESS ),
			'padded'                      => array( "  SUCCESS\n", Afriex_Status_Mapper::CLASS_SUCCESS ),

			// Terminal failures.
			'FAILED'                      => array( 'FAILED', Afriex_Status_Mapper::CLASS_TERMINAL_FAILURE ),
			'REJECTED'                    => array( 'REJECTED', Afriex_Status_Mapper::CLASS_TERMINAL_FAILURE ),
			'CANCELLED'                   => array( 'CANCELLED', Afriex_Status_Mapper::CLASS_TERMINAL_FAILURE ),

			// Non-terminal.
			'PENDING'                     => array( 'PENDING', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'PROCESSING'                  => array( 'PROCESSING', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'RETRY'                       => array( 'RETRY', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'SCHEDULED'                   => array( 'SCHEDULED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'UNKNOWN'                     => array( 'UNKNOWN', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'IN_REVIEW'                   => array( 'IN_REVIEW', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'CUSTOMER_ACTION_REQUIRED'    => array( 'CUSTOMER_ACTION_REQUIRED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'CHECKER_APPROVAL_REQUIRED'   => array( 'CHECKER_APPROVAL_REQUIRED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'ENGINEERING_REVIEW_REQUIRED' => array( 'ENGINEERING_REVIEW_REQUIRED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'RFI_REQUESTED'               => array( 'RFI_REQUESTED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),

			// Post-payment.
			'REFUNDED'                    => array( 'REFUNDED', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),
			'DISPUTED'                    => array( 'DISPUTED', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),
			'DISPUTE_EVIDENCE_SUBMITTED'  => array( 'DISPUTE_EVIDENCE_SUBMITTED', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),
			'DISPUTE_WON'                 => array( 'DISPUTE_WON', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),
			'DISPUTE_LOST'                => array( 'DISPUTE_LOST', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),
			'DISPUTE_RESOLVED'            => array( 'DISPUTE_RESOLVED', Afriex_Status_Mapper::CLASS_POST_PAYMENT ),

			// Not in Afriex's enum: 1.0 completed orders on COMPLETED and
			// cancelled them on CANCELED, neither of which Afriex ever sends.
			'COMPLETED is not real'       => array( 'COMPLETED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'CANCELED is not real'        => array( 'CANCELED', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),

			// Anything new waits; it never fails or completes an order.
			'invented status'             => array( 'SOMETHING_NEW', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
			'empty'                       => array( '', Afriex_Status_Mapper::CLASS_NON_TERMINAL ),
		);
	}

	/**
	 * @dataProvider known_cases
	 *
	 * @param string $status   Afriex status.
	 * @param bool   $expected Whether Afriex publishes it.
	 */
	public function test_is_known( string $status, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Status_Mapper::is_known( $status ) );
	}

	public function known_cases(): array {
		return array(
			'SUCCESS'   => array( 'SUCCESS', true ),
			'CANCELLED' => array( 'CANCELLED', true ),
			'RETRY'     => array( 'RETRY', true ),
			'DISPUTED'  => array( 'DISPUTED', true ),
			'COMPLETED' => array( 'COMPLETED', false ),
			'CANCELED'  => array( 'CANCELED', false ),
			'nonsense'  => array( 'NOT_A_STATUS', false ),
			'empty'     => array( '', false ),
		);
	}

	public function test_every_published_status_is_known(): void {
		foreach ( Afriex_Status_Mapper::all_statuses() as $status ) {
			$this->assertTrue( Afriex_Status_Mapper::is_known( $status ), $status . ' should be known' );
		}

		$this->assertCount( 20, Afriex_Status_Mapper::all_statuses() );
	}

	public function test_no_status_is_in_two_classes(): void {
		$seen = array();

		foreach ( Afriex_Status_Mapper::all_statuses() as $status ) {
			$this->assertArrayNotHasKey( $status, $seen, $status . ' is listed twice' );
			$seen[ $status ] = Afriex_Status_Mapper::classify( $status );
		}
	}

	/**
	 * @dataProvider terminal_cases
	 *
	 * @param string $status   Afriex status.
	 * @param bool   $expected Whether Afriex has finished deciding.
	 */
	public function test_is_terminal( string $status, bool $expected ): void {
		$this->assertSame( $expected, Afriex_Status_Mapper::is_terminal( $status ) );
	}

	public function terminal_cases(): array {
		return array(
			'SUCCESS'                  => array( 'SUCCESS', true ),
			'FAILED'                   => array( 'FAILED', true ),
			'REJECTED'                 => array( 'REJECTED', true ),
			'CANCELLED'                => array( 'CANCELLED', true ),
			'PENDING'                  => array( 'PENDING', false ),
			'UNKNOWN'                  => array( 'UNKNOWN', false ),
			// A refund is not a failed payment: the expire routine must not
			// read one as a reason to cancel the order.
			'REFUNDED is not terminal' => array( 'REFUNDED', false ),
			'DISPUTE_LOST'             => array( 'DISPUTE_LOST', false ),
		);
	}

	/**
	 * @dataProvider review_cases
	 *
	 * @param string $status Afriex status.
	 * @param bool   $review Whether a person is emailed.
	 * @param bool   $note   Whether a note alone is enough.
	 */
	public function test_post_payment_handling( string $status, bool $review, bool $note ): void {
		$this->assertSame( $review, Afriex_Status_Mapper::needs_review( $status ) );
		$this->assertSame( $note, Afriex_Status_Mapper::is_dispute_resolution( $status ) );
	}

	public function review_cases(): array {
		return array(
			'REFUNDED'                   => array( 'REFUNDED', true, false ),
			'DISPUTED'                   => array( 'DISPUTED', true, false ),
			'DISPUTE_EVIDENCE_SUBMITTED' => array( 'DISPUTE_EVIDENCE_SUBMITTED', true, false ),
			'DISPUTE_LOST'               => array( 'DISPUTE_LOST', true, false ),
			'DISPUTE_WON'                => array( 'DISPUTE_WON', false, true ),
			'DISPUTE_RESOLVED'           => array( 'DISPUTE_RESOLVED', false, true ),
			'SUCCESS'                    => array( 'SUCCESS', false, false ),
		);
	}
}
