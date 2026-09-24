<?php
/**
 * The settlement decision table (plan 6.3), as a test.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Every arm of Afriex_Settlement_Decision, from plain arrays.
 *
 * These are the rules that decide whether a shopper's money completes an
 * order, fails it, or is set aside for a person. They are worth stating twice:
 * once in the code and once here, where a change that alters them has to be
 * written down on purpose.
 */
class SettlementDecisionTest extends TestCase {

	/**
	 * A payment that should simply work: an Afriex Checkout order waiting for
	 * 100.00 NGN, and a DEPOSIT of exactly that.
	 *
	 * @param array $overrides Keys to change.
	 */
	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'payment_method'       => 'afriex_checkout',
				'order_status'         => 'pending',
				'already_settled'      => false,
				'settled_txn'          => '',
				'failed_by_plugin'     => '',
				'txn_id'               => 'txn_1',
				'txn_status'           => 'SUCCESS',
				'txn_type'             => 'DEPOSIT',
				'is_current_attempt'   => true,
				'expected_minor'       => 10000,
				'expected_currency'    => 'NGN',
				'order_total_minor'    => 10000,
				'order_currency'       => 'NGN',
				'destination_amount'   => '100.00',
				'destination_currency' => 'NGN',
				'source_amount'        => '100.00',
				'source_currency'      => 'NGN',
			),
			$overrides
		);
	}

	/**
	 * @dataProvider decision_cases
	 *
	 * @param array  $overrides Input changes.
	 * @param string $expected  Expected decision code.
	 * @param bool   $claim     Whether the ledger claim is owed first.
	 */
	public function test_decide( array $overrides, string $expected, bool $claim ): void {
		$decision = Afriex_Settlement_Decision::decide( $this->input( $overrides ) );

		$this->assertSame( $expected, $decision['decision'] );
		$this->assertSame( $claim, $decision['claim'] );
	}

	public function decision_cases(): array {
		return array(

			/*
			 * Step 2: ownership.
			 */
			'another gateway is never touched'   => array(
				array( 'payment_method' => 'bacs' ),
				Afriex_Settlement_Decision::NOT_AFRIEX,
				false,
			),
			'bank transfer is ours too'          => array(
				array( 'payment_method' => 'afriex' ),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),

			/*
			 * Step 4: direction. Only a deposit pays for an order (F3).
			 */
			'a withdrawal never settles'         => array(
				array( 'txn_type' => 'WITHDRAW' ),
				Afriex_Settlement_Decision::NOT_DEPOSIT,
				false,
			),
			'a swap never settles'               => array(
				array( 'txn_type' => 'SWAP' ),
				Afriex_Settlement_Decision::NOT_DEPOSIT,
				false,
			),
			'an absent type is allowed'          => array(
				array( 'txn_type' => '' ),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'type is case insensitive'           => array(
				array( 'txn_type' => 'deposit' ),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),

			/*
			 * Step 5: the order is already settled. No status change, ever,
			 * whatever the merchant has done to the order since (F87).
			 */
			'the same payment delivered again'   => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'order_status'    => 'processing',
				),
				Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE,
				false,
			),
			'paid, then put back on hold'        => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'order_status'    => 'on-hold',
				),
				Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE,
				false,
			),
			'a second payment for one order'     => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_0',
					'order_status'    => 'processing',
				),
				Afriex_Settlement_Decision::SECOND_PAYMENT,
				false,
			),
			'a late failure on a paid order'     => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'FAILED',
					'order_status'    => 'processing',
				),
				Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE,
				false,
			),
			'a late PENDING on a paid order'     => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'PENDING',
					'order_status'    => 'processing',
				),
				Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE,
				false,
			),
			'a refund after payment'             => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'REFUNDED',
					'order_status'    => 'processing',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_REVIEW,
				false,
			),
			'a dispute after payment'            => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'DISPUTED',
					'order_status'    => 'completed',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_REVIEW,
				false,
			),
			'dispute evidence submitted'         => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'DISPUTE_EVIDENCE_SUBMITTED',
					'order_status'    => 'completed',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_REVIEW,
				false,
			),
			'a dispute lost'                     => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'DISPUTE_LOST',
					'order_status'    => 'completed',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_REVIEW,
				false,
			),
			'a dispute won is only a note'       => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'DISPUTE_WON',
					'order_status'    => 'completed',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_NOTE,
				false,
			),
			'a dispute resolved is only a note'  => array(
				array(
					'already_settled' => true,
					'settled_txn'     => 'txn_1',
					'txn_status'      => 'DISPUTE_RESOLVED',
					'order_status'    => 'completed',
				),
				Afriex_Settlement_Decision::POST_PAYMENT_NOTE,
				false,
			),

			/*
			 * Step 6: terminal failures.
			 */
			'a failure on the current attempt'   => array(
				array( 'txn_status' => 'FAILED' ),
				Afriex_Settlement_Decision::FAIL_ORDER,
				false,
			),
			'a rejection'                        => array(
				array( 'txn_status' => 'REJECTED' ),
				Afriex_Settlement_Decision::FAIL_ORDER,
				false,
			),
			'a cancellation at Afriex'           => array(
				array( 'txn_status' => 'CANCELLED' ),
				Afriex_Settlement_Decision::FAIL_ORDER,
				false,
			),
			'an on-hold order can fail'          => array(
				array(
					'txn_status'   => 'FAILED',
					'order_status' => 'on-hold',
				),
				Afriex_Settlement_Decision::FAIL_ORDER,
				false,
			),
			'a failure from an old session'      => array(
				array(
					'txn_status'         => 'FAILED',
					'is_current_attempt' => false,
				),
				Afriex_Settlement_Decision::STALE_FAILURE,
				false,
			),
			'a failure on an order already paid' => array(
				array(
					'txn_status'   => 'FAILED',
					'order_status' => 'completed',
				),
				Afriex_Settlement_Decision::STALE_FAILURE,
				false,
			),
			'a failure on a cancelled order'     => array(
				array(
					'txn_status'   => 'FAILED',
					'order_status' => 'cancelled',
				),
				Afriex_Settlement_Decision::STALE_FAILURE,
				false,
			),

			/*
			 * Step 6: everything still in flight.
			 */
			'pending'                            => array(
				array( 'txn_status' => 'PENDING' ),
				Afriex_Settlement_Decision::NOTE_STATUS,
				false,
			),
			'processing'                         => array(
				array( 'txn_status' => 'PROCESSING' ),
				Afriex_Settlement_Decision::NOTE_STATUS,
				false,
			),
			'customer action required'           => array(
				array( 'txn_status' => 'CUSTOMER_ACTION_REQUIRED' ),
				Afriex_Settlement_Decision::NOTE_STATUS,
				false,
			),
			'a status nobody here has seen'      => array(
				array( 'txn_status' => 'BRAND_NEW_STATUS' ),
				Afriex_Settlement_Decision::NOTE_STATUS,
				false,
			),
			'COMPLETED does not complete'        => array(
				array( 'txn_status' => 'COMPLETED' ),
				Afriex_Settlement_Decision::NOTE_STATUS,
				false,
			),
			'a refund before any payment'        => array(
				array( 'txn_status' => 'REFUNDED' ),
				Afriex_Settlement_Decision::UNSETTLED_POST_PAYMENT,
				false,
			),

			/*
			 * Step 7: the money.
			 */
			'the destination pair matches'       => array(
				array(
					'source_amount'   => '110.00',
					'source_currency' => 'NGN',
				),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'the source pair matches'            => array(
				array(
					'destination_amount'   => '98.50',
					'destination_currency' => 'NGN',
				),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'extra decimals are fine'            => array(
				array( 'destination_amount' => '100.0000' ),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'extra decimals round down'          => array(
				array(
					'destination_amount' => '100.004',
					'source_amount'      => '100.004',
				),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'extra decimals round up and differ' => array(
				array(
					'destination_amount' => '100.005',
					'source_amount'      => '100.005',
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),
			'an underpayment is not summed'      => array(
				array(
					'destination_amount' => '60.00',
					'source_amount'      => '60.00',
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),
			'an overpayment is refused too'      => array(
				array(
					'destination_amount' => '150.00',
					'source_amount'      => '150.00',
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),
			'the wrong currency'                 => array(
				array(
					'destination_currency' => 'KES',
					'source_currency'      => 'KES',
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),
			'an amount that is not a number'     => array(
				array(
					'destination_amount' => 'N100,00',
					'source_amount'      => '',
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),
			'no expectation to compare with'     => array(
				array( 'expected_minor' => null ),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				false,
			),

			/*
			 * Step 9: which orders a payment may complete.
			 */
			'an on-hold order completes'         => array(
				array( 'order_status' => 'on-hold' ),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'an order the plugin failed'         => array(
				array(
					'order_status'     => 'failed',
					'failed_by_plugin' => 'txn_0',
				),
				Afriex_Settlement_Decision::COMPLETE,
				true,
			),
			'an order a person failed'           => array(
				array( 'order_status' => 'failed' ),
				Afriex_Settlement_Decision::INELIGIBLE,
				true,
			),
			'a cancelled order is not revived'   => array(
				array( 'order_status' => 'cancelled' ),
				Afriex_Settlement_Decision::NOT_REVIVED,
				true,
			),
			'a refunded order is not revived'    => array(
				array( 'order_status' => 'refunded' ),
				Afriex_Settlement_Decision::NOT_REVIVED,
				true,
			),
			'an order already processing'        => array(
				array( 'order_status' => 'processing' ),
				Afriex_Settlement_Decision::INELIGIBLE,
				true,
			),
			'a draft order'                      => array(
				array( 'order_status' => 'checkout-draft' ),
				Afriex_Settlement_Decision::INELIGIBLE,
				true,
			),
		);
	}

	/**
	 * The mismatch reasons, which choose what the order note says.
	 *
	 * @dataProvider mismatch_reason_cases
	 *
	 * @param array  $overrides Input changes.
	 * @param string $reason    Expected reason code.
	 */
	public function test_mismatch_reasons( array $overrides, string $reason ): void {
		$decision = Afriex_Settlement_Decision::decide( $this->input( $overrides ) );

		$this->assertSame( Afriex_Settlement_Decision::AMOUNT_MISMATCH, $decision['decision'] );
		$this->assertSame( $reason, $decision['reason'] );
	}

	public function mismatch_reason_cases(): array {
		return array(
			'wrong amount'      => array(
				array(
					'destination_amount' => '60.00',
					'source_amount'      => '60.00',
				),
				'amount_mismatch',
			),
			'wrong currency'    => array(
				array(
					'destination_currency' => 'KES',
					'source_currency'      => 'USD',
				),
				'currency_mismatch',
			),
			'no expectation'    => array(
				array( 'expected_minor' => null ),
				'no_expectation',
			),
			'no currency known' => array(
				array( 'expected_currency' => '' ),
				'no_expectation',
			),
			'unreadable'        => array(
				array(
					'destination_amount' => 'about a hundred',
					'source_amount'      => 'about a hundred',
				),
				'amount_unreadable',
			),
		);
	}

	/**
	 * Which pair matched is recorded, because until Afriex says which one
	 * equals the session amount, that is the evidence (plan 16, question 1).
	 */
	public function test_money_reports_which_pair_matched(): void {
		$destination = Afriex_Settlement_Decision::money(
			$this->input( array( 'source_amount' => '110.00' ) )
		);

		$this->assertTrue( $destination['ok'] );
		$this->assertSame( 'destination', $destination['matched'] );
		$this->assertSame( 10000, $destination['destination_minor'] );
		$this->assertSame( 11000, $destination['source_minor'] );

		$source = Afriex_Settlement_Decision::money(
			$this->input( array( 'destination_amount' => '98.50' ) )
		);

		$this->assertTrue( $source['ok'] );
		$this->assertSame( 'source', $source['matched'] );
		$this->assertSame( 9850, $source['destination_minor'] );
	}

	public function test_money_never_guesses_a_missing_currency(): void {
		$result = Afriex_Settlement_Decision::money(
			$this->input(
				array(
					'destination_currency' => '',
					'source_currency'      => '',
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'currency_mismatch', $result['reason'] );
		$this->assertNull( $result['destination_minor'] );
		$this->assertNull( $result['source_minor'] );
	}

	/**
	 * Leaving a key out must never make a decision more permissive: the
	 * defaults say "not ours, no money, nothing current".
	 */
	public function test_empty_input_settles_nothing(): void {
		$decision = Afriex_Settlement_Decision::decide( array() );

		$this->assertSame( Afriex_Settlement_Decision::NOT_AFRIEX, $decision['decision'] );
		$this->assertFalse( $decision['claim'] );
	}

	public function test_a_success_with_no_expectation_never_completes(): void {
		$decision = Afriex_Settlement_Decision::decide(
			array(
				'payment_method' => 'afriex',
				'order_status'   => 'on-hold',
				'txn_status'     => 'SUCCESS',
				'txn_id'         => 'txn_9',
			)
		);

		$this->assertSame( Afriex_Settlement_Decision::AMOUNT_MISMATCH, $decision['decision'] );
	}

	/**
	 * Reading an Afriex transaction: field names differ between the webhook
	 * payload and the API response, and settlement must not care which.
	 */
	public function test_transaction_id_is_read_from_any_of_its_names(): void {
		$this->assertSame( 'a', Afriex_Settlement_Decision::transaction_id( array( 'transactionId' => 'a' ) ) );
		$this->assertSame( 'b', Afriex_Settlement_Decision::transaction_id( array( 'id' => 'b' ) ) );
		$this->assertSame( 'c', Afriex_Settlement_Decision::transaction_id( array( '_id' => 'c' ) ) );
		$this->assertSame(
			'a',
			Afriex_Settlement_Decision::transaction_id(
				array(
					'transactionId' => 'a',
					'id'            => 'b',
				)
			)
		);
		$this->assertSame( '', Afriex_Settlement_Decision::transaction_id( array( 'transactionId' => '' ) ) );
		$this->assertSame( '', Afriex_Settlement_Decision::transaction_id( array() ) );
		$this->assertSame( '', Afriex_Settlement_Decision::transaction_id( array( 'transactionId' => array( 'nested' ) ) ) );
	}

	public function test_reference_prefers_the_documented_fields(): void {
		$this->assertSame(
			'wc_1_aaaaaaaaaaaaaaaa',
			Afriex_Settlement_Decision::reference(
				array(
					'merchantReference' => 'wc_1_aaaaaaaaaaaaaaaa',
					'meta'              => array( 'reference' => 'wc_2_bbbbbbbbbbbbbbbb' ),
					'reference'         => 'wc_3_cccccccccccccccc',
				)
			)
		);

		$this->assertSame(
			'wc_2_bbbbbbbbbbbbbbbb',
			Afriex_Settlement_Decision::reference( array( 'meta' => array( 'reference' => 'wc_2_bbbbbbbbbbbbbbbb' ) ) )
		);

		// 1.0's pool payloads carried a bare reference, and those orders still
		// have to be matchable.
		$this->assertSame( 'AFX-123', Afriex_Settlement_Decision::reference( array( 'reference' => 'AFX-123' ) ) );
		$this->assertSame( '', Afriex_Settlement_Decision::reference( array() ) );
	}

	public function test_status_defaults_to_unknown(): void {
		$this->assertSame( 'SUCCESS', Afriex_Settlement_Decision::status( array( 'status' => ' success ' ) ) );
		$this->assertSame( 'UNKNOWN', Afriex_Settlement_Decision::status( array() ) );
		$this->assertSame( 'UNKNOWN', Afriex_Settlement_Decision::status( array( 'status' => '' ) ) );
	}

	public function test_amounts_fall_back_to_the_flat_fields(): void {
		$amounts = Afriex_Settlement_Decision::amounts(
			array(
				'amount'   => '250.00',
				'currency' => 'ngn',
				'fee'      => '1.25',
			)
		);

		$this->assertSame( '250.00', $amounts['destination_amount'] );
		$this->assertSame( 'ngn', $amounts['destination_currency'] );
		$this->assertSame( '', $amounts['source_amount'] );
		$this->assertSame( '1.25', $amounts['fee'] );
	}

	public function test_failure_reason_is_read_when_present(): void {
		$this->assertSame(
			'Insufficient funds',
			Afriex_Settlement_Decision::failure_reason(
				array( 'meta' => array( 'failureReason' => array( 'message' => 'Insufficient funds' ) ) )
			)
		);

		$this->assertSame( '', Afriex_Settlement_Decision::failure_reason( array( 'meta' => array() ) ) );
	}

	public function test_channel_is_upper_cased(): void {
		$this->assertSame( 'CARD', Afriex_Settlement_Decision::channel( array( 'channel' => 'card' ) ) );
		$this->assertSame( 'MOBILE_MONEY', Afriex_Settlement_Decision::channel( array( 'paymentChannel' => 'MOBILE_MONEY' ) ) );
		$this->assertSame( '', Afriex_Settlement_Decision::channel( array() ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Step 7, the amount guard: the session has to still be what the order
	 * costs. A payment is judged against the session it was made on (F29),
	 * and the session is judged against the order.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * @dataProvider guard_cases
	 *
	 * @param array  $overrides Input changes.
	 * @param string $expected  Expected decision code.
	 * @param string $reason    Expected reason code.
	 */
	public function test_amount_guard( array $overrides, string $expected, string $reason ): void {
		$decision = Afriex_Settlement_Decision::decide( $this->input( $overrides ) );

		$this->assertSame( $expected, $decision['decision'] );
		$this->assertSame( $reason, $decision['reason'] );
	}

	public function guard_cases(): array {
		return array(
			// The session and the order agree: the ordinary case.
			'the price has not moved'          => array(
				array(),
				Afriex_Settlement_Decision::COMPLETE,
				'ok',
			),

			// A session at the old, lower price against a dearer order. This
			// is the case the guard exists for: money() is perfectly happy,
			// because 100.00 is what that session asked for.
			'the total was raised afterwards'  => array(
				array( 'order_total_minor' => 19000 ),
				Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH,
				'order_total_mismatch',
			),
			'the total was lowered afterwards' => array(
				array( 'order_total_minor' => 5000 ),
				Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH,
				'order_total_mismatch',
			),
			'one minor unit is enough'         => array(
				array( 'order_total_minor' => 10001 ),
				Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH,
				'order_total_mismatch',
			),
			'the order currency changed'       => array(
				array( 'order_currency' => 'KES' ),
				Afriex_Settlement_Decision::ORDER_TOTAL_MISMATCH,
				'order_currency_mismatch',
			),

			// An older session at the same price is still a payment for this
			// order, whichever pair Afriex matched it on (F29).
			'an older session, same price'     => array(
				array( 'is_current_attempt' => false ),
				Afriex_Settlement_Decision::COMPLETE,
				'ok',
			),

			// Nothing to compare is not a mismatch. The order total is read
			// nowhere else either, and refusing every payment on a store
			// whose total cannot be expressed in minor units would be worse
			// than the guard is good.
			'an unreadable order total'        => array(
				array( 'order_total_minor' => null ),
				Afriex_Settlement_Decision::COMPLETE,
				'ok',
			),
			'no order currency'                => array(
				array( 'order_currency' => '' ),
				Afriex_Settlement_Decision::COMPLETE,
				'ok',
			),

			// The guard runs after the money check, never instead of it: a
			// payment that does not match its own session is still reported
			// as what it is.
			'a short payment is still short'   => array(
				array(
					'destination_amount' => '60.00',
					'source_amount'      => '60.00',
					'order_total_minor'  => 19000,
				),
				Afriex_Settlement_Decision::AMOUNT_MISMATCH,
				'amount_mismatch',
			),
		);
	}

	/**
	 * A failure or a non-terminal status is not money, so the guard has no
	 * opinion about it: an order whose total has moved can still be failed by
	 * its own failed payment.
	 */
	public function test_the_guard_only_judges_successful_payments(): void {
		$failed = Afriex_Settlement_Decision::decide(
			$this->input(
				array(
					'txn_status'        => 'FAILED',
					'order_total_minor' => 19000,
				)
			)
		);

		$this->assertSame( Afriex_Settlement_Decision::FAIL_ORDER, $failed['decision'] );

		$pending = Afriex_Settlement_Decision::decide(
			$this->input(
				array(
					'txn_status'        => 'PROCESSING',
					'order_total_minor' => 19000,
				)
			)
		);

		$this->assertSame( Afriex_Settlement_Decision::NOTE_STATUS, $pending['decision'] );
	}

	/**
	 * The guard carries the order's own figures, so the note can name all
	 * three amounts: the session, the payment and the order.
	 */
	public function test_the_guard_reports_the_order_total(): void {
		$decision = Afriex_Settlement_Decision::decide(
			$this->input( array( 'order_total_minor' => 19000 ) )
		);

		$this->assertSame( 19000, $decision['money']['order_total_minor'] );
		$this->assertSame( 'NGN', $decision['money']['order_currency'] );
		$this->assertSame( 10000, $decision['money']['expected_minor'] );
		$this->assertSame( 'destination', $decision['money']['matched'] );
	}

	/**
	 * A paid order is settled before the guard is ever reached, so a total
	 * edited after payment cannot re-open anything (F87).
	 */
	public function test_an_already_settled_order_is_not_re_judged(): void {
		$decision = Afriex_Settlement_Decision::decide(
			$this->input(
				array(
					'already_settled'   => true,
					'settled_txn'       => 'txn_1',
					'order_status'      => 'processing',
					'order_total_minor' => 19000,
				)
			)
		);

		$this->assertSame( Afriex_Settlement_Decision::ALREADY_SETTLED_NOTE, $decision['decision'] );
	}
}
