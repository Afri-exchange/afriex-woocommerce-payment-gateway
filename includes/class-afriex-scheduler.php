<?php
/**
 * The recurring Afriex sweep, on Action Scheduler.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the one recurring action, afriex_wc_sweep, every five minutes
 * (plan 6.4), and the cancellation rules of plan 5.6.
 *
 * Why Action Scheduler and not WP-Cron (F34):
 * - 1.0 scheduled its hourly WP-Cron event only on activation. An update does
 *   not re-run activation, and a cron event lost from the cron option was
 *   never re-created, so the backstop for lost webhooks could silently stop.
 * - Action Scheduler ships with WooCommerce, keeps a visible log under
 *   Tools > Scheduled Actions, and runs even when WP-Cron is replaced by a
 *   system cron.
 *
 * Self-healing: ensure_scheduled() runs on every init, after WooCommerce and
 * Action Scheduler are ready, and schedules the action whenever none is
 * pending or running. It does not depend on either payment method being
 * enabled, because in-flight orders must keep settling after a method is
 * switched off (plan 4.2).
 *
 * What a run does, and what it deliberately does not do:
 * - It is a **backstop**, not the settlement path. The webhook is what
 *   settles a payment. This exists for the deliveries that never arrived.
 * - For **Bank Transfer** the deposits are listed ONCE per run, with Afriex's
 *   documented filters, and fanned out to orders in memory: see
 *   Afriex_Bank_Transfer_Sweep, which also owns the one switch that decides
 *   whether a placed deposit may settle its order. That switch is off until
 *   Afriex answers questions 7 to 9 of plan 16, so the run records what it
 *   would have done and settles nothing. The one exception is expiry, which
 *   has to end somewhere (plan 9).
 * - For **Afriex Checkout** each order is polled by reference, which is
 *   positive identification, so a SUCCESS settles straight away.
 * - Expiry runs for both methods, because the expire routine settles any
 *   SUCCESS it finds before it cancels anything.
 */
class Afriex_Scheduler {

	public const HOOK = 'afriex_wc_sweep';

	public const GROUP = 'afriex-gateway-for-woocommerce';

	public const INTERVAL = 300;

	/**
	 * Orders one run will look at, however many are due (plan 6.4).
	 */
	public const BATCH_SIZE = 25;

	/**
	 * The backoff between checks of one order, in seconds (plan 6.4, F15).
	 * The last value repeats for as long as the order stays open.
	 */
	public const BACKOFF = array( 300, 900, 3600, 21600, 86400 );

	/**
	 * Grace after an order's payment window before it is expired, for money
	 * that left the shopper before the deadline and lands just after it
	 * (plan 5.6).
	 *
	 * The Bank Transfer thank-you page deliberately does not apply it: the
	 * grace is for money already on its way, not for inviting a transfer into
	 * an account that has closed (F45).
	 */
	private const DEADLINE_GRACE = 600;

	/**
	 * How long a *closed* Afriex Checkout order stays in the sweep after its
	 * deadline, watching for a late payment (plan 5.6).
	 *
	 * Forty-eight hours. Afriex retries a webhook for about 32 hours, so a
	 * SUCCESS that never reached the store has stopped being delivered well
	 * inside this; what is left over is for a mobile-money approval or a bank
	 * transfer that completed on Afriex's side after the store gave up.
	 */
	private const LATE_PAYMENT_WATCH = 172800;

	/**
	 * The longest gap between two checks of a closed Checkout order.
	 */
	private const LATE_PAYMENT_INTERVAL = 21600;

	/**
	 * Order statuses a closed Checkout order can be in and still be watched.
	 */
	private const CLOSED_STATUSES = array( 'cancelled', 'failed' );

	/**
	 * Order statuses that mean "still waiting for the money".
	 */
	private const OPEN_STATUSES = array( 'on-hold', 'pending' );

	/**
	 * Seconds of Afriex time one order's check may spend.
	 *
	 * The order lock is held for the whole check, so the work has to fit
	 * inside the lock's TTL (Afriex_Settlement::LOCK_TTL). Unbounded, a Bank
	 * Transfer check is three GETs at 10 s each plus two retry sleeps, which
	 * is more than twice that.
	 */
	private const ORDER_BUDGET = 45.0;

	/**
	 * Seconds one whole run may spend before it stops handing out orders
	 * (F15).
	 *
	 * The run holds the `sweep` ledger lock for INTERVAL - 10 = 290 s. Every
	 * order was bounded on its own — ORDER_BUDGET of Afriex time each — but
	 * BATCH_SIZE * ORDER_BUDGET is 1125 s, so twenty-five orders all at their
	 * deadline could run long past the lock's TTL and the next scheduled run
	 * would start on top of this one. The two would then compete for the
	 * per-order locks and orders would be skipped, which is the starvation
	 * F15 is about.
	 *
	 * So the run stops handing out orders once this much wall clock has gone.
	 * The orders it did not reach keep their cursors untouched — exactly what
	 * the 429 path does — and the next run starts with them.
	 */
	private const RUN_BUDGET = 240.0;

	/**
	 * The Afriex Checkout payment window, in minutes (plan 4.1).
	 *
	 * Aliases of the gateway's own constants, which are where the setting is
	 * defined and validated. The sweep keeps its own names because it reads
	 * the saved value from the option rather than from a gateway instance.
	 */
	public const DEFAULT_CHECKOUT_WINDOW_MINUTES = Afriex_Checkout_Gateway::DEFAULT_PAYMENT_WINDOW_MINUTES;

	/**
	 * The longest Afriex Checkout payment window an admin may save, in
	 * minutes. Thirty days, matching Bank Transfer's 720 hours.
	 */
	public const MAX_CHECKOUT_WINDOW_MINUTES = Afriex_Checkout_Gateway::MAX_PAYMENT_WINDOW_MINUTES;

	/**
	 * The ledger key that pauses the sweep after Afriex asks it to slow down.
	 */
	private const PAUSE_KEY = 'sweep-pause';

	/**
	 * The longest the sweep pauses after a 429 or a 503, whatever
	 * Retry-After says. A pause longer than this would leave the backstop off
	 * for longer than anyone would notice.
	 */
	private const MAX_PAUSE = 1800;

	/**
	 * The shortest pause, when Afriex sends no Retry-After.
	 */
	private const MIN_PAUSE = 300;

	/**
	 * Hook the action callback, the self-healing check and the cancellation
	 * rule.
	 *
	 * Called on plugins_loaded, so the callback is registered on every
	 * request Action Scheduler may run the queue in (WP-Cron, async
	 * requests, WP-CLI).
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		// Priority 20: WooCommerce initialises Action Scheduler on init at
		// priority 1, and as_*() calls made earlier are refused.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );

		// The plugin owns cancellation for its own orders (plan 5.6).
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid_order' ), 10, 2 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cancellation (plan 5.6).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Keep WooCommerce's hold-stock timer away from Afriex Checkout orders.
	 *
	 * WooCommerce cancels unpaid `pending` orders after the hold-stock
	 * minutes, which defaults to 60. An Afriex Checkout order sits at
	 * `pending` while the shopper is on Afriex's hosted page and while the
	 * payment clears afterwards, and a bank transfer made through that page
	 * can easily take longer than an hour. If WooCommerce cancelled it, the
	 * payment would arrive against a cancelled order and need a person.
	 *
	 * So this plugin decides when its own orders end: the deadline below,
	 * through the expire routine, which settles any payment it finds before
	 * it cancels anything.
	 *
	 * Bank Transfer orders are not covered because they are `on-hold`, which
	 * the hold-stock timer never touches.
	 *
	 * @param bool     $cancel Whether WooCommerce would cancel this order.
	 * @param WC_Order $order  The unpaid order.
	 * @return bool
	 */
	public static function keep_unpaid_order( $cancel, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $cancel;
		}

		if ( 'afriex_checkout' !== $order->get_payment_method() ) {
			return $cancel;
		}

		// Only once a session has actually been issued. An order that never
		// reached Afriex has nothing to wait for.
		if ( '' === Afriex_Order_Meta::get_current_checkout_reference( $order ) ) {
			return $cancel;
		}

		return false;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether Action Scheduler is loaded and initialised.
	 */
	public static function is_ready(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return false;
		}

		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'is_initialized' ) ) {
			return ActionScheduler::is_initialized();
		}

		return did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Schedule the sweep when no pending or running instance exists.
	 *
	 * One indexed query per request while the action exists. The scheduling
	 * itself happens under a ledger lock with no wait, so concurrent first
	 * requests do not create duplicates; the unique flag guards the same
	 * thing inside Action Scheduler.
	 *
	 * @return bool Whether the action is scheduled when this returns.
	 */
	public static function ensure_scheduled(): bool {
		if ( wp_installing() || ! self::is_ready() ) {
			return false;
		}

		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return true;
		}

		if ( ! Afriex_Ledger::lock( 'scheduler', 0, 30 ) ) {
			return false;
		}

		try {
			if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
				return true;
			}

			$action_id = as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), self::GROUP, true );

			if ( $action_id ) {
				Afriex_Logger::info( 'Scheduled the Afriex sweep', array( 'action_id' => $action_id ) );
			}

			return (bool) $action_id;
		} finally {
			Afriex_Ledger::unlock( 'scheduler' );
		}
	}

	/**
	 * Remove every pending instance of the sweep. Deactivation and uninstall.
	 */
	public static function unschedule(): void {
		// By hook alone: Action Scheduler then cancels every pending instance
		// whatever its arguments or group.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * The sweep.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * One sweep run (plan 6.4).
	 *
	 * Under a ledger lock with no wait, so a slow run and the next one never
	 * overlap; the lock expires on its own if a run dies.
	 *
	 * @return array What the run did, for tests and the log.
	 */
	public static function run(): array {
		$report = array(
			'checked' => 0,
			'skipped' => 0,
			'stopped' => '',
			'listing' => array(),
		);

		if ( ! Afriex_Ledger::lock( 'sweep', 0, self::INTERVAL - 10 ) ) {
			Afriex_Logger::info( 'Afriex sweep skipped: the previous run is still going' );
			$report['stopped'] = 'locked';
			return $report;
		}

		$started = microtime( true );

		try {
			Afriex_Ledger::purge_expired();

			if ( self::is_paused() ) {
				Afriex_Logger::info( 'Afriex sweep skipped: Afriex asked for a pause' );
				$report['stopped'] = 'paused';
				return $report;
			}

			$due = self::due_order_ids();

			// Bank Transfer is listed once for the whole run, before any order
			// lock is taken, and the rows are fanned out to orders in memory
			// (plan 9). Every Bank Transfer check below then reads that listing
			// instead of asking Afriex a question of its own.
			$report['listing'] = Afriex_Bank_Transfer_Sweep::prime(
				$due,
				min( Afriex_Bank_Transfer_Sweep::LIST_BUDGET, max( 5.0, self::RUN_BUDGET - ( microtime( true ) - $started ) ) )
			);

			if ( in_array( $report['listing']['api_status'], array( 429, 503 ), true ) ) {
				// Afriex asked the whole store to slow down before the run had
				// touched an order. Nothing has been checked, and the next run
				// starts again from the same due orders: their cursors have not
				// moved (F15).
				self::pause( $report['listing']['api_retry_after'] );
				$report['stopped'] = 'rate_limited:' . $report['listing']['api_status'];

				return $report;
			}

			foreach ( $due as $order_id ) {
				// The run holds one lock for all of this, and it expires. An
				// order started after the budget is gone risks finishing after
				// the next run has taken the lock over (F15). The ones left
				// keep their cursors, so the next run has them.
				if ( microtime( true ) - $started >= self::RUN_BUDGET ) {
					$report['stopped'] = 'run_budget';
					Afriex_Logger::info(
						'Afriex sweep stopped early: the run used its whole budget',
						array(
							'checked' => $report['checked'],
							'elapsed' => round( microtime( true ) - $started, 1 ),
						)
					);
					break;
				}

				$result = self::check_order( $order_id );

				if ( 'skipped' === $result['outcome'] ) {
					++$report['skipped'];
					continue;
				}

				++$report['checked'];

				// A 429 or a 503 is Afriex asking the whole store to slow
				// down, not this one order. Carrying on through the other
				// twenty-four would be the opposite of what it asked for.
				if ( in_array( $result['api_status'], array( 429, 503 ), true ) ) {
					self::pause( $result['api_retry_after'] );
					$report['stopped'] = 'rate_limited:' . $result['api_status'];
					break;
				}
			}
		} finally {
			Afriex_Bank_Transfer_Sweep::clear();
			Afriex_Ledger::unlock( 'sweep' );
		}

		if ( $report['checked'] > 0 || '' !== $report['stopped'] ) {
			Afriex_Logger::info( 'Afriex sweep finished', $report );
		}

		return $report;
	}

	/**
	 * The orders this run should look at: due and ours.
	 *
	 * One query per payment method, because wc_get_orders() takes a single
	 * one, plus a third for the closed Afriex Checkout orders that are still
	 * being watched for a late payment (plan 5.6). All are bounded, and the
	 * merged result is cut back to the batch size.
	 *
	 * @return int[]
	 */
	public static function due_order_ids(): array {
		$now = time();

		// Never checked, or due. A missing cursor has to count as due, or an
		// order placed before this version would never be swept again.
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => Afriex_Order_Meta::KEY_NEXT_CHECK,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Afriex_Order_Meta::KEY_NEXT_CHECK,
				'value'   => $now,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		);

		$ids = array();

		foreach ( Afriex_Payment_Gateway::GATEWAY_IDS as $gateway_id ) {
			$found = Afriex_Order_Meta::query_orders(
				array(
					'limit'          => self::BATCH_SIZE,
					'return'         => 'ids',
					'status'         => self::OPEN_STATUSES,
					'payment_method' => $gateway_id,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				$meta_query
			);

			foreach ( $found as $id ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}

		// Closed Afriex Checkout orders that still hold a session, until the
		// watch runs out (plan 5.6). The window is stored on the order the
		// first time the sweep meets it, so this clause is what eventually
		// takes the order out of the sweep again: no "finished" flag, and
		// nothing to tidy up. An order that has never been looked at has no
		// value yet, which is why NOT EXISTS has to count as inside it.
		$found = Afriex_Order_Meta::query_orders(
			array(
				'limit'          => self::BATCH_SIZE,
				'return'         => 'ids',
				'status'         => self::CLOSED_STATUSES,
				'payment_method' => 'afriex_checkout',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			),
			array(
				'relation' => 'AND',
				array(
					'key'     => Afriex_Order_Meta::KEY_CHECKOUT_REF_INDEX,
					'compare' => 'EXISTS',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => Afriex_Order_Meta::KEY_WATCH_UNTIL,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Afriex_Order_Meta::KEY_WATCH_UNTIL,
						'value'   => $now,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
				$meta_query,
			)
		);

		foreach ( $found as $id ) {
			$ids[ (int) $id ] = (int) $id;
		}

		$ids = array_values( $ids );
		sort( $ids );

		return array_slice( $ids, 0, self::BATCH_SIZE );
	}

	/**
	 * Look at one order.
	 *
	 * @param int $order_id Order id.
	 * @return array outcome, api_status, api_retry_after.
	 */
	public static function check_order( int $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::checked( 'skipped' );
		}

		if ( ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return self::checked( 'skipped' );
		}

		// A closed order is here for one reason only: an Afriex Checkout
		// order that was cancelled or failed keeps its sessions, and a
		// SUCCESS can still turn up on one (plan 5.6). Everything else that
		// has left the waiting statuses is finished with.
		if ( ! in_array( $order->get_status(), self::OPEN_STATUSES, true ) && ! self::is_watching_late_payment( $order ) ) {
			return self::checked( 'skipped' );
		}

		// Somebody else is working on this order right now — a webhook, most
		// likely, which is the authority anyway. The sweep never waits: it
		// comes back in five minutes.
		$lock = Afriex_Settlement::lock_key( $order_id );

		// The TTL has to cover the whole check, Afriex calls included, or the
		// row expires while this run is still inside the critical section and
		// a webhook takes it over.
		if ( ! Afriex_Ledger::lock( $lock, 0, Afriex_Settlement::LOCK_TTL ) ) {
			return self::checked( 'skipped' );
		}

		// Everything below takes the same lock again, through settle() or
		// expire(), which is safe: the ledger lock is re-entrant within one
		// request. Holding it here stops a webhook starting on this order
		// between the two halves of the work below.
		try {
			// The copy loaded above predates the lock, and everything below
			// writes through the object it is given — the deadline, the note
			// history, the watch. Plan 6.1 is explicit that a caller clears
			// WooCommerce's caches and loads the order only once it holds the
			// lock, so that what it reads and writes back is the state inside
			// it (F8). The pre-lock copy did nothing but the cheap screening.
			Afriex_Settlement::flush_order_cache( $order_id );

			$locked = wc_get_order( $order_id );

			if ( ! $locked instanceof WC_Order ) {
				return self::checked( 'skipped' );
			}

			$order = $locked;

			$deadline = self::deadline_for( $order );
			$due      = $deadline > 0 && time() >= $deadline;

			if ( 'afriex_checkout' === $order->get_payment_method() ) {
				$result = self::check_checkout_order( $order, $due );
			} else {
				$result = self::check_bank_transfer_order( $order, $due );
			}
		} finally {
			Afriex_Ledger::unlock( $lock );
		}

		self::schedule_next_check( $order_id );

		return $result;
	}

	/**
	 * The check behind "Check Afriex payment now" on the order screen
	 * (plan 4.3), for either payment method.
	 *
	 * It is the sweep's own work, under the sweep's own lock and sharing the
	 * page throttle: plan 4.3's "the same throttled, locked check as the
	 * sweep", and that includes the deadline. Plan 9 asks for expiry to be
	 * decided in one place, by the expire routine, on every path that can
	 * reach it (F32, F33) — so an order whose payment window has closed is
	 * expired here exactly as the sweep would expire it five minutes later,
	 * rather than this one path having a private answer about when an order
	 * ends. The routine settles any SUCCESS it finds before it considers
	 * cancelling and cancels only on positive evidence that every payment
	 * failed, so pressing the button can confirm a payment but cannot end an
	 * order Afriex is still working on.
	 *
	 * A Checkout order is polled across EVERY reference it has issued, which
	 * is the difference that matters on a retried order: the return handler's
	 * own check asks about the current reference alone, and a SUCCESS sitting
	 * on an earlier one would be reported as "nothing confirmed yet".
	 *
	 * @param int $order_id Order id.
	 * @return array outcome, api_status, api_retry_after.
	 */
	public static function check_now( int $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! in_array( $order->get_payment_method(), Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return self::checked( 'skipped' );
		}

		// The same marker the return handler and the status endpoint take, so
		// a person leaning on the button costs Afriex no more than a shopper
		// leaning on reload (plan 5.4).
		if ( ! Afriex_Ledger::throttle( 'check:order:' . $order_id, Afriex_Checkout_Return::THROTTLE_SECONDS ) ) {
			return self::checked( 'throttled' );
		}

		$lock = Afriex_Settlement::lock_key( $order_id );

		if ( ! Afriex_Ledger::lock( $lock, 0.0, Afriex_Settlement::LOCK_TTL ) ) {
			return self::checked( 'locked' );
		}

		try {
			// The order is loaded again inside the lock, caches cleared, so
			// that the copy written through below is the state inside the
			// lock rather than one from before it (plan 6.1, F8).
			Afriex_Settlement::flush_order_cache( $order_id );

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return self::checked( 'skipped' );
			}

			$deadline = self::deadline_for( $order );
			$due      = $deadline > 0 && time() >= $deadline;

			if ( 'afriex_checkout' === $order->get_payment_method() ) {
				return self::check_checkout_order( $order, $due );
			}

			return self::check_bank_transfer_order( $order, $due, true );
		} finally {
			Afriex_Ledger::unlock( $lock );
		}
	}

	/**
	 * A Bank Transfer order (plan 9).
	 *
	 * Before the deadline it reads the run's one listing rather than asking
	 * Afriex anything itself, and — with the switch off, which it is —
	 * records what settling from that listing would have done. At the deadline
	 * the expire routine takes over, which re-fetches the account and settles
	 * any SUCCESS before it considers cancelling.
	 *
	 * Ambiguity is decided BEFORE the deadline branch. It used to be decided
	 * after it, which meant that the one case F30 exists for — one Afriex
	 * account recorded against two orders — reached the expire routine
	 * unflagged. The run's listing deliberately withholds an ambiguous row
	 * from both orders, so the expire routine saw no transactions at all,
	 * found nothing to stop it and cancelled a paid order with "no payment
	 * arrived". Flagging first sets `may_cancel = false`, and the order waits
	 * for a person instead.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $due    Whether the deadline has passed.
	 * @param bool     $manual Whether a merchant pressed "Check Afriex payment
	 *                         now" rather than this being the scheduled sweep.
	 * @return array outcome, api_status, api_retry_after, reason.
	 */
	private static function check_bank_transfer_order( WC_Order $order, bool $due, bool $manual = false ): array {
		$order_id = $order->get_id();

		// One Afriex account against two orders. Refused and said out loud,
		// never guessed (F30) — and said before anything can end the order.
		$ambiguous = Afriex_Bank_Transfer_Sweep::ambiguous_accounts( $order_id );

		if ( ! empty( $ambiguous ) ) {
			Afriex_Bank_Transfer_Sweep::report_ambiguous( $order, $ambiguous );

			return self::checked( Afriex_Settlement::OUTCOME_FLAGGED, 0, 0, 'account_ambiguous' );
		}

		if ( $due ) {
			$outcome = Afriex_Settlement::expire( $order->get_id(), Afriex_Settlement::SOURCE_SWEEP, self::ORDER_BUDGET );

			return self::checked( $outcome['outcome'], $outcome['api_status'], $outcome['api_retry_after'], (string) $outcome['reason'] );
		}

		// A flagged order that the merchant has just pressed the button on is
		// the one case where a Bank Transfer payment is applied before the
		// deadline. The evidence is the transaction this order already
		// recorded, re-fetched by its own id — a per-id GET, so none of plan
		// 16's open questions about listing filters stand in the way — and
		// settle() still runs the whole decision table on it, so a payment
		// that is still wrong is still refused. Without this, an order flagged
		// `order_total_mismatch` could not be finished from anywhere a
		// merchant can reach once the total was put back: Afriex answers its
		// own retry "duplicate", and the note-only path never settles.
		if ( $manual ) {
			$applied = self::settle_recorded_transaction( $order );

			if ( null !== $applied ) {
				return $applied;
			}
		}

		$rows = Afriex_Bank_Transfer_Sweep::rows_for_order( $order_id );

		if ( null === $rows ) {
			// Not covered by a run listing: "Check Afriex payment now", or an
			// order the run's listing could not reach. The same strict listing
			// for one order.
			$client = Afriex_Settlement::client_for_order( $order );

			if ( ! $client->is_configured() ) {
				return self::checked( 'skipped' );
			}

			// The order lock is held across this call, so the work has to fit
			// inside the lock's TTL or a second process can take the row over
			// while this one is still using it.
			$client->set_time_budget( self::ORDER_BUDGET );

			$rows = Afriex_Bank_Transfer_Sweep::list_for_order( $order, $client );

			if ( is_wp_error( $rows ) ) {
				Afriex_Logger::info(
					'Sweep could not reach Afriex for an order',
					array(
						'order_id' => $order_id,
						'error'    => $rows->get_error_message(),
					)
				);

				return self::checked(
					'error',
					Afriex_Settlement::error_status( $rows ),
					Afriex_Settlement::error_retry_after( $rows )
				);
			}
		}

		return self::checked( Afriex_Bank_Transfer_Sweep::apply_rows( $order, $rows ) );
	}

	/**
	 * Re-fetch the transaction this order already recorded and settle from it.
	 *
	 * Only for a Bank Transfer order that carries a review flag, and only when
	 * a merchant asked. The flag is the signal that a decision was refused
	 * once and that whatever caused it may since have been put right — an
	 * edited order total, most of all. Everything that makes settling from a
	 * *listing* unsafe (which field carries the virtual account id, whether
	 * the filters are honoured, what units the amounts are in — plan 16
	 * questions 7 to 9) is absent here: the transaction is fetched by the id
	 * this order recorded, and settle() applies the full decision table, so a
	 * payment that is still wrong is still refused and re-flagged.
	 *
	 * @param WC_Order $order Order, loaded under its lock.
	 * @return array|null A result, or null to carry on with the usual path.
	 */
	private static function settle_recorded_transaction( WC_Order $order ): ?array {
		if ( '' === Afriex_Order_Meta::get_review_flag( $order ) ) {
			return null;
		}

		$transaction_id = Afriex_Order_Meta::get_transaction_id( $order );

		if ( '' === $transaction_id ) {
			return null;
		}

		if ( '' !== Afriex_Order_Meta::get_settled_transaction_id( $order ) ) {
			return null;
		}

		$client = Afriex_Settlement::client_for_order( $order );

		if ( ! $client->is_configured() ) {
			return null;
		}

		$client->set_time_budget( self::ORDER_BUDGET );

		$transaction = $client->get_transaction( $transaction_id );

		if ( is_wp_error( $transaction ) ) {
			Afriex_Logger::info(
				'The per-order check could not re-fetch this order\'s recorded transaction',
				array(
					'order_id'       => $order->get_id(),
					'transaction_id' => $transaction_id,
					'error'          => $transaction->get_error_message(),
				)
			);

			return null;
		}

		if ( ! Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $transaction ) ) ) {
			return null;
		}

		$outcome = Afriex_Settlement::settle( $order->get_id(), $transaction, Afriex_Settlement::SOURCE_SWEEP );

		if ( ! in_array( $outcome['outcome'], array( Afriex_Settlement::OUTCOME_SETTLED, Afriex_Settlement::OUTCOME_FLAGGED ), true ) ) {
			return null;
		}

		return self::checked( (string) $outcome['outcome'], 0, 0, (string) $outcome['reason'] );
	}

	/**
	 * Say what Afriex reports about one transaction, once.
	 *
	 * Nothing here changes the order. The note is deduplicated on the
	 * transaction and its status, so an unchanged payment is mentioned once
	 * however many times the sweep sees it (F21, F36).
	 *
	 * Only the Afriex Checkout poll reaches this now: it settles every SUCCESS
	 * it finds and passes on the rows it did not act on. Bank Transfer's rows
	 * go through Afriex_Bank_Transfer_Sweep, which has to say more about a
	 * SUCCESS it is deliberately not applying.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $row   Transaction payload.
	 */
	private static function note_transaction( WC_Order $order, array $row ): void {
		$transaction_id = Afriex_Settlement_Decision::transaction_id( $row );
		$status         = Afriex_Settlement_Decision::status( $row );

		if ( '' === $transaction_id ) {
			return;
		}

		Afriex_Order_Meta::note_once(
			$order,
			'sweep_seen:' . $transaction_id . ':' . $status,
			sprintf(
				/* translators: 1: Afriex transaction id, 2: Afriex status */
				__( 'Afriex reports transfer %1$s as %2$s.', 'afriex-gateway-for-woocommerce' ),
				$transaction_id,
				$status
			)
		);
	}

	/**
	 * An Afriex Checkout order (plan 5.6).
	 *
	 * Unlike Bank Transfer, this settles: a Checkout payment is bound to its
	 * order by a reference this plugin minted and nothing else can carry, so
	 * a listing for that reference is positive identification and none of
	 * plan 16's open questions stand in the way.
	 *
	 * Three shapes of run:
	 * - **before the deadline:** list every issued reference and settle any
	 *   SUCCESS, so a lost webhook still completes the order;
	 * - **at the deadline:** the expire routine, which does the same listing
	 *   and settling first and only then decides whether to cancel;
	 * - **after the order closed:** the same listing again, for as long as
	 *   the late-payment watch lasts, so a SUCCESS that arrives after a
	 *   cancellation is flagged rather than lost. settle() refuses to revive
	 *   a cancelled order, which is exactly what should happen.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $due   Whether the deadline has passed.
	 * @return array outcome, api_status, api_retry_after.
	 */
	private static function check_checkout_order( WC_Order $order, bool $due ): array {
		/**
		 * Fires once per sweep for each due Afriex Checkout order, just before
		 * the plugin lists its references at Afriex.
		 *
		 * The order lock is already held when this fires.
		 *
		 * @param WC_Order $order    The order being checked.
		 * @param bool     $due      Whether its deadline has passed.
		 * @param int      $deadline The deadline, as a UNIX time, or 0.
		 */
		do_action( 'woocommerce_afriex_sweep_checkout_order', $order, $due, Afriex_Order_Meta::get_deadline( $order ) );

		$open = in_array( $order->get_status(), self::OPEN_STATUSES, true );

		// At the deadline the expire routine replaces the poll rather than
		// following it: it lists exactly the same references and settles any
		// SUCCESS before it considers cancelling, so polling first would only
		// ask Afriex the same question twice.
		if ( $due && $open ) {
			$outcome = Afriex_Settlement::expire( $order->get_id(), Afriex_Settlement::SOURCE_SWEEP, self::ORDER_BUDGET );

			return self::checked( $outcome['outcome'], $outcome['api_status'], $outcome['api_retry_after'], (string) $outcome['reason'] );
		}

		return self::poll_checkout_order( $order );
	}

	/**
	 * List every reference this order has issued and act on what Afriex says.
	 *
	 * @param WC_Order $order Order.
	 * @return array outcome, api_status, api_retry_after.
	 */
	private static function poll_checkout_order( WC_Order $order ): array {
		$order_id = $order->get_id();

		if ( empty( Afriex_Order_Meta::get_checkout_reference_rows( $order ) ) ) {
			// No session was ever created, so Afriex has never heard of this
			// order and there is nothing to ask about.
			return self::checked( 'skipped' );
		}

		$client = Afriex_Settlement::client_for_order( $order );

		if ( ! $client->is_configured() ) {
			return self::checked( 'skipped' );
		}

		// The order lock is held across these calls, so the work has to fit
		// inside the lock's TTL.
		$client->set_time_budget( self::ORDER_BUDGET );

		$rows = Afriex_Settlement::collect_transactions( $order, $client );

		if ( is_wp_error( $rows ) ) {
			Afriex_Logger::info(
				'Sweep could not reach Afriex for a Checkout order',
				array(
					'order_id' => $order_id,
					'error'    => $rows->get_error_message(),
				)
			);

			return self::checked(
				'error',
				Afriex_Settlement::error_status( $rows ),
				Afriex_Settlement::error_retry_after( $rows )
			);
		}

		$settled_any = false;
		$others      = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! Afriex_Status_Mapper::is_success( Afriex_Settlement_Decision::status( $row ) ) ) {
				$others[] = $row;
				continue;
			}

			$outcome     = Afriex_Settlement::settle( $order_id, $row, Afriex_Settlement::SOURCE_SWEEP );
			$settled_any = true;

			// Settled, or refused and flagged: either way a person or the
			// order itself now has the answer, and the remaining rows are
			// history. A late SUCCESS on a cancelled order lands here.
			if ( in_array( $outcome['outcome'], array( Afriex_Settlement::OUTCOME_SETTLED, Afriex_Settlement::OUTCOME_FLAGGED ), true ) ) {
				return self::checked( $outcome['outcome'] );
			}
		}

		if ( empty( $others ) ) {
			return self::checked( 'noted' );
		}

		if ( $settled_any ) {
			// settle() wrote through its own copy of the order, so the one in
			// hand is stale and a note added from it would undo that work.
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return self::checked( 'skipped' );
			}
		}

		foreach ( $others as $row ) {
			self::note_transaction( $order, $row );
		}

		return self::checked( 'noted' );
	}

	/**
	 * Whether a closed Afriex Checkout order is still being watched for a
	 * late payment, working the window out and storing it the first time.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function is_watching_late_payment( WC_Order $order ): bool {
		if ( 'afriex_checkout' !== $order->get_payment_method() ) {
			return false;
		}

		if ( ! in_array( $order->get_status(), self::CLOSED_STATUSES, true ) ) {
			return false;
		}

		$until = Afriex_Order_Meta::get_watch_until( $order );

		if ( $until <= 0 ) {
			$deadline = self::deadline_for( $order );

			// A deadline of 0 means no session was ever issued, so there is
			// nothing to watch for. A time in the past is stored anyway, so
			// that the due-order query stops matching the order rather than
			// offering it again on every run.
			$until = $deadline > 0 ? $deadline + self::LATE_PAYMENT_WATCH : time() - 1;

			Afriex_Order_Meta::set_watch_until( $order, $until );
		}

		return time() <= $until;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Deadlines and backoff.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * When this order stops waiting for a payment (plan 5.6).
	 *
	 * The stored value is a floor, never the whole answer: the expire routine
	 * moves it forward when Afriex still has a payment in flight, and that
	 * must not be pulled back. For Checkout the plan's own terms can also
	 * move it forward AFTER it was first computed — a retry mints a session
	 * that outlives the frozen deadline, or a delayed CHECKOUT_SESSION.CREATED
	 * brings an expiresAt for the first time — so the plan's maximum is taken
	 * again on every run and the larger of the two wins. Reading the stored
	 * value alone would let the sweep cancel an order while the session the
	 * shopper is sitting on is still live.
	 *
	 * - **Bank Transfer:** the virtual account's own expiry when Afriex gave
	 *   one, because no transfer can arrive after it; otherwise the order's
	 *   creation plus the admin's payment window (F32, F33). Neither term
	 *   moves once the order exists, so the stored value is the answer.
	 * - **Checkout:** the first reference's creation plus the window, or the
	 *   latest session's expiry, whichever is later.
	 *
	 * Both get ten minutes' grace for money that left the shopper before the
	 * deadline and lands just after it.
	 *
	 * @param WC_Order $order Order.
	 * @return int UNIX time, or 0 when the order has nothing to expire.
	 */
	public static function deadline_for( WC_Order $order ): int {
		$stored = Afriex_Order_Meta::get_deadline( $order );

		if ( 'afriex_checkout' !== $order->get_payment_method() ) {
			if ( $stored > 0 ) {
				return $stored;
			}

			$created = $order->get_date_created();
			$start   = $created instanceof WC_DateTime ? $created->getTimestamp() : time();

			$account_expiry = Afriex_Order_Meta::get_expires_at( $order );
			$deadline       = ( $account_expiry > 0 ? $account_expiry : $start + self::payment_window_seconds( $order->get_payment_method() ) )
				+ self::DEADLINE_GRACE;

			Afriex_Order_Meta::extend_deadline( $order, $deadline );

			return $deadline;
		}

		$rows = Afriex_Order_Meta::get_checkout_reference_rows( $order );

		if ( empty( $rows ) ) {
			// No session was ever issued, so there is nothing to wait for and
			// nothing for this plugin to cancel.
			return $stored;
		}

		$created = $order->get_date_created();
		$first   = PHP_INT_MAX;
		$expires = 0;

		foreach ( $rows as $row ) {
			if ( isset( $row['created'] ) && is_numeric( $row['created'] ) ) {
				$first = min( $first, (int) $row['created'] );
			}

			// The same helper the reuse branch uses, so the two can never
			// disagree about whether a session is still live. It answers with
			// Afriex's own expiresAt once CHECKOUT_SESSION.CREATED has
			// brought one, and with the assumed lifetime until then — which
			// is what a session minted seconds ago has, and precisely the
			// case where the frozen deadline used to cancel an order out from
			// under a shopper who was still on the hosted page.
			$expires = max( $expires, Afriex_Checkout_Gateway::session_deadline( $row ) );
		}

		if ( PHP_INT_MAX === $first ) {
			$first = $created instanceof WC_DateTime ? $created->getTimestamp() : time();
		}

		$deadline = max( $first + self::payment_window_seconds( $order->get_payment_method() ), $expires )
			+ self::DEADLINE_GRACE;

		// extend_deadline() refuses to move backwards, so the expire
		// routine's own extensions survive this.
		Afriex_Order_Meta::extend_deadline( $order, $deadline );

		return max( $stored, $deadline );
	}

	/**
	 * The payment window an admin saved, in seconds.
	 *
	 * The two methods do not share a unit. Bank Transfer waits for a bank
	 * transfer, so its window is hours (plan 4.1, default 24). Afriex
	 * Checkout is a hosted page the shopper is sitting in front of, so its
	 * window is minutes (plan 4.1, default 60). Reading one option's key for
	 * both would give every Checkout order a 24-hour deadline.
	 *
	 * Read straight from the option rather than from a gateway instance:
	 * building a gateway inside a background job pulls in its checkout hooks
	 * for no reason, and the sweep has to work whether or not the method is
	 * enabled (plan 4.2).
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public static function payment_window_seconds( string $gateway_id ): int {
		if ( 'afriex_checkout' === $gateway_id ) {
			return self::payment_window_minutes( $gateway_id ) * MINUTE_IN_SECONDS;
		}

		return self::payment_window_hours( $gateway_id ) * HOUR_IN_SECONDS;
	}

	/**
	 * The Bank Transfer payment window an admin saved, in hours.
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public static function payment_window_hours( string $gateway_id ): int {
		$hours = self::saved_window( $gateway_id, 'payment_window_hours', Afriex_Gateway::DEFAULT_PAYMENT_WINDOW_HOURS );

		return $hours >= 1 && $hours <= Afriex_Gateway::MAX_PAYMENT_WINDOW_HOURS
			? $hours
			: Afriex_Gateway::DEFAULT_PAYMENT_WINDOW_HOURS;
	}

	/**
	 * The Afriex Checkout payment window an admin saved, in minutes.
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public static function payment_window_minutes( string $gateway_id ): int {
		$minutes = self::saved_window( $gateway_id, 'payment_window_minutes', self::DEFAULT_CHECKOUT_WINDOW_MINUTES );

		return $minutes >= 1 && $minutes <= self::MAX_CHECKOUT_WINDOW_MINUTES
			? $minutes
			: self::DEFAULT_CHECKOUT_WINDOW_MINUTES;
	}

	/**
	 * One numeric setting off a gateway's option, or its default.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $key        Setting key.
	 * @param int    $fallback   Value when the setting is absent or not a number.
	 */
	private static function saved_window( string $gateway_id, string $key, int $fallback ): int {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );

		return is_array( $settings ) && isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] )
			? (int) $settings[ $key ]
			: $fallback;
	}

	/**
	 * Move an order's cursor on, with the backoff (F15).
	 *
	 * Reloaded rather than reused: settle() and expire() write to the order
	 * through their own copy, and saving a stale one here would undo them.
	 *
	 * @param int $order_id Order id.
	 */
	private static function schedule_next_check( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// An order that has left the waiting statuses has no next check,
		// unless it is a closed Checkout order still being watched for a late
		// payment (plan 5.6). That watch ends on its own: once _afriex_watch_until
		// is in the past the due-order query stops matching the order, so the
		// cursor written here simply stops being read.
		if ( ! in_array( $order->get_status(), self::OPEN_STATUSES, true )
			&& ! self::is_watching_late_payment( $order )
		) {
			return;
		}

		$count = Afriex_Order_Meta::get_check_count( $order );

		/**
		 * The backoff between one order's upstream checks, in seconds.
		 *
		 * @param int[]    $backoff Intervals; the last repeats.
		 * @param WC_Order $order   Order.
		 */
		$backoff = apply_filters( 'woocommerce_afriex_sweep_backoff', self::BACKOFF, $order );
		$backoff = is_array( $backoff ) && ! empty( $backoff ) ? array_values( $backoff ) : self::BACKOFF;

		$step     = min( $count, count( $backoff ) - 1 );
		$interval = max( 60, (int) $backoff[ $step ] );

		// A closed order is usually one the sweep has already checked many
		// times, so its backoff has reached a day — which would buy only two
		// looks in the whole 48-hour late-payment watch. Six hours is the
		// longest gap worth leaving when the thing being watched for is money
		// that has already left the shopper.
		if ( ! in_array( $order->get_status(), self::OPEN_STATUSES, true ) ) {
			$interval = min( $interval, self::LATE_PAYMENT_INTERVAL );
		}

		Afriex_Order_Meta::set_next_check( $order, time() + $interval, $count + 1 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pausing after a 429 or a 503.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether Afriex has asked the sweep to wait.
	 */
	public static function is_paused(): bool {
		$holder = Afriex_Ledger::holder( Afriex_Ledger::KIND_THROTTLE, self::PAUSE_KEY );

		return is_array( $holder ) && empty( $holder['expired'] );
	}

	/**
	 * Stop sweeping for a while, honouring Retry-After (plan 6.4).
	 *
	 * @param int $retry_after Seconds Afriex asked for, or 0.
	 */
	public static function pause( int $retry_after ): void {
		$seconds = $retry_after > 0 ? $retry_after : self::MIN_PAUSE;
		$seconds = max( self::MIN_PAUSE, min( self::MAX_PAUSE, $seconds ) );

		Afriex_Ledger::release( Afriex_Ledger::KIND_THROTTLE, self::PAUSE_KEY );
		Afriex_Ledger::claim( Afriex_Ledger::KIND_THROTTLE, self::PAUSE_KEY, 0, 'paused', $seconds );

		Afriex_Logger::warning( 'Afriex asked the sweep to slow down', array( 'seconds' => $seconds ) );
	}

	/**
	 * Shape one order's result.
	 *
	 * @param string $outcome     What happened.
	 * @param int    $api_status  HTTP status of a failed Afriex call, or 0.
	 * @param int    $retry_after Retry-After in seconds, or 0.
	 * @param string $reason      The settlement reason behind the outcome, when
	 *                            there is one. "Check Afriex payment now" tells
	 *                            the merchant a different sentence for
	 *                            `listing_truncated` than for `in_flight`, and
	 *                            without this they were the same message.
	 */
	private static function checked( string $outcome, int $api_status = 0, int $retry_after = 0, string $reason = '' ): array {
		return array(
			'outcome'         => $outcome,
			'api_status'      => $api_status,
			'api_retry_after' => $retry_after,
			'reason'          => $reason,
		);
	}
}
