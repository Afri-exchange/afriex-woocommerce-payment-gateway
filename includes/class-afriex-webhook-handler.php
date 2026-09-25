<?php
/**
 * Webhook REST route: signature verification, event claiming and dispatch.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives Afriex webhook deliveries (plan 7).
 *
 * Three rules shape this class.
 *
 * 1. **Verification is the permission check.** It happens in the route's
 *    permission_callback, so a forged or tampered delivery is refused by
 *    WordPress before the callback runs: no order look-up, no database write,
 *    no side effect of any kind.
 * 2. **A webhook is a trigger, never evidence.** Nothing in the payload
 *    settles anything. The handler re-fetches the transaction or payment
 *    method from Afriex and acts on what comes back (F27). Afriex's own docs
 *    say deliveries can arrive out of order, so the delivered body may
 *    already be out of date by the time it lands.
 * 3. **Every delivery is claimed before the work starts, not after.** Afriex
 *    retries up to twelve times over about 32 hours and two deliveries can be
 *    in flight at once. The claim is a three-state row in the ledger table
 *    rather than a transient, so it is correct under concurrency and with a
 *    persistent object cache (F7, F16).
 *
 * The route is registered here and not from a gateway, and nothing below
 * looks at whether either payment method is enabled: an in-flight order has
 * to keep settling after the merchant switches its method off (plan 4.2).
 */
class Afriex_Webhook_Handler {

	private const REST_NAMESPACE = 'afriex/v1';
	private const REST_ROUTE     = '/webhook';

	private const SIGNATURE_HEADER = 'x-webhook-signature';

	/**
	 * Afriex does not document an environment indicator (plan 16, question
	 * 10). If one ever appears under this name it decides which key is tried
	 * first; until then both keys are tried anyway, so nothing depends on it.
	 */
	private const ENVIRONMENT_HEADER = 'x-afriex-env';

	/**
	 * How long a finished event is remembered, so a replay days later is
	 * still recognised. Afriex's retry window is about 32 hours.
	 */
	private const DONE_TTL = WEEK_IN_SECONDS;

	/**
	 * How long a delivery may hold an event before another delivery may take
	 * it over. Long enough for the whole budget below, short enough that a
	 * process killed mid-flight does not block the event for a week.
	 */
	private const PROCESSING_TTL = 60;

	/**
	 * A transaction event older than this is not acted on (F6). A delivery
	 * that has been stuck in a queue for a week cannot say anything useful
	 * about an order that has moved on since.
	 */
	private const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * The whole delivery's time budget. Afriex waits 30 s; finishing well
	 * inside that leaves room for PHP's own start-up and shutdown.
	 */
	private const TIME_BUDGET = 20.0;

	/**
	 * One re-fetch, with no automatic retry: a second attempt inside this
	 * request would eat the budget the settlement still needs.
	 */
	private const FETCH_TIMEOUT = 8.0;

	/**
	 * The least time worth starting a re-fetch and a settlement with.
	 */
	private const MIN_USEFUL_BUDGET = 5.0;

	/**
	 * The furthest ahead a Checkout session's expiresAt is believed.
	 *
	 * The deadline only ever moves forward, so a wrong value here cannot be
	 * undone by a later delivery. Thirty days is longer than any hosted
	 * session and short enough that a stuck order still ends.
	 */
	private const MAX_SESSION_LIFETIME = 30 * DAY_IN_SECONDS;

	/**
	 * Events this handler acts on. Everything else is acknowledged with 200
	 * and ignored, so Afriex does not retry a delivery the plugin has no use
	 * for.
	 */
	private const HANDLED_EVENTS = array(
		'TRANSACTION.CREATED',
		'TRANSACTION.UPDATED',
		'CHECKOUT_SESSION.CREATED',
		'PAYMENT_METHOD.UPDATED',
	);

	/**
	 * When the current delivery's budget runs out, as a microtime value.
	 *
	 * @var float
	 */
	private static $deadline = 0.0;

	/**
	 * The environment whose key verified the current delivery, for the log.
	 *
	 * @var string
	 */
	private static $verified_environment = '';

	/**
	 * Register the REST route.
	 *
	 * Static, and called from the plugin's bootstrap rather than from a
	 * gateway: the route must exist whether or not a payment method is
	 * enabled.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the REST route (the rest_api_init callback).
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				// Signature verification IS the permission check. A forged
				// request never reaches the callback.
				'permission_callback' => array( __CLASS__, 'verify_signature' ),
			)
		);
	}

	/**
	 * The webhook URL, for the settings screen and the documentation.
	 */
	public static function url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Verification.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Verify the SHA-256 signature (ECDSA or RSA) against the raw request body.
	 *
	 * Afriex signs with its private key and the plugin verifies with the
	 * public key from the merchant's dashboard. This is asymmetric, not HMAC: there
	 * is no shared secret, and nothing that can only read a delivery can
	 * forge another one.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function verify_signature( WP_REST_Request $request ): bool {
		self::$verified_environment = '';

		if ( ! function_exists( 'openssl_verify' ) ) {
			Afriex_Logger::error( 'Webhook rejected: the PHP openssl extension is not available' );
			return false;
		}

		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );

		if ( '' === $signature ) {
			Afriex_Logger::warning( 'Webhook rejected: missing signature header' );
			return false;
		}

		// Strict base64: a signature with stray characters in it is not one
		// Afriex produced, and decoding it loosely would only hide that.
		$decoded_signature = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a binary signature, not obfuscating code.

		if ( false === $decoded_signature || '' === $decoded_signature ) {
			Afriex_Logger::warning( 'Webhook rejected: signature was not valid base64' );
			return false;
		}

		// Verify against the raw body, never a re-encoded parsed payload:
		// json_decode() followed by wp_json_encode() does not reproduce the
		// exact bytes Afriex signed.
		$raw_body = $request->get_body();

		if ( '' === $raw_body ) {
			Afriex_Logger::warning( 'Webhook rejected: empty body' );
			return false;
		}

		$keys = self::public_keys( (string) $request->get_header( self::ENVIRONMENT_HEADER ) );

		if ( empty( $keys ) ) {
			Afriex_Logger::error( 'Webhook rejected: no public key configured' );
			return false;
		}

		foreach ( $keys as $environment => $public_key ) {
			if ( 1 === openssl_verify( $raw_body, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256 ) ) {
				self::$verified_environment = (string) $environment;
				return true;
			}
		}

		Afriex_Logger::warning( 'Webhook rejected: signature verification failed' );
		return false;
	}

	/**
	 * The webhook public keys to try, keyed by environment.
	 *
	 * Afriex signs each environment with its own key and, as far as its docs
	 * say, sends no environment indicator. Both keys are kept and both are
	 * tried, so events for orders placed before an environment switch stay
	 * verifiable (plan 7). Keys come back as normalised PEM however they
	 * were pasted.
	 *
	 * @param string $hinted An environment named by the request, if any.
	 * @return array<string, string>
	 */
	private static function public_keys( string $hinted ): array {
		$configured = Afriex_Account_Settings::environment();
		$other      = Afriex_Api_Client::ENV_PRODUCTION === $configured ? Afriex_Api_Client::ENV_SANDBOX : Afriex_Api_Client::ENV_PRODUCTION;

		$order = array( $configured, $other );

		$hinted = trim( $hinted );

		if ( '' !== $hinted ) {
			$hinted = Afriex_Api_Client::normalise_environment( $hinted );

			if ( in_array( $hinted, $order, true ) ) {
				array_unshift( $order, $hinted );
			}
		}

		$keys = array();

		foreach ( $order as $environment ) {
			if ( isset( $keys[ $environment ] ) ) {
				continue;
			}

			$key = Afriex_Account_Settings::webhook_public_key( $environment );

			if ( '' !== $key ) {
				$keys[ $environment ] = $key;
			}
		}

		return $keys;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dispatch.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Handle one verified delivery.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		self::$deadline = microtime( true ) + self::TIME_BUDGET;

		$raw     = $request->get_body();
		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) || ! isset( $payload['event'] ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			// A signed body the plugin cannot read is not something a retry
			// will fix, but it is worth saying so out loud.
			Afriex_Logger::warning( 'Webhook rejected: the signed body was not a readable event' );
			return self::respond( 400, 'malformed' );
		}

		$event = strtoupper( trim( (string) $payload['event'] ) );
		$data  = $payload['data'];

		$context = array(
			'event'       => $event,
			'environment' => self::$verified_environment,
		);

		if ( ! in_array( $event, self::HANDLED_EVENTS, true ) ) {
			Afriex_Logger::debug( 'Webhook event ignored', $context );
			return self::respond( 200, 'ignored' );
		}

		// Staleness is decided before the claim: a delivery this old changes
		// nothing, so there is no reason to spend a ledger row remembering
		// it (F6).
		if ( self::is_stale( $event, $data ) ) {
			Afriex_Logger::info( 'Webhook ignored: the event is older than the staleness window', $context );
			return self::respond( 200, 'stale' );
		}

		$claim_key = self::claim_key( $event, $data, $raw );
		$claimed   = self::claim_event( $claim_key );

		if ( 'claimed' !== $claimed ) {
			Afriex_Logger::debug( 'Webhook not claimed', array_merge( $context, array( 'claim' => $claimed ) ) );

			// 'done': this event has already been handled, so the delivery is
			// a replay and gets the answer the first one got.
			// 'processing': another delivery is doing this work right now.
			// 409 asks Afriex to come back rather than have two copies race.
			return 'done' === $claimed
				? self::respond( 200, 'duplicate' )
				: self::respond( 409, 'in_progress' );
		}

		try {
			$result = self::dispatch( $event, $data, $context );
		} catch ( Throwable $e ) {
			// A claim held by a delivery that died would block the event for
			// its full TTL. Release it and let Afriex retry.
			Afriex_Ledger::release( Afriex_Ledger::KIND_EVENT, $claim_key );

			Afriex_Logger::error(
				'Webhook handling failed',
				array_merge( $context, array( 'error' => $e->getMessage() ) )
			);

			return self::respond( 500, 'error' );
		}

		if ( 500 === $result['status'] ) {
			// Nothing was decided, so nothing is remembered: the retry has to
			// be free to try the same event again.
			Afriex_Ledger::release( Afriex_Ledger::KIND_EVENT, $claim_key );

			return self::respond( 500, $result['reason'] );
		}

		// Decided. Remember it for a week, so a replay costs one SELECT.
		Afriex_Ledger::set_state( Afriex_Ledger::KIND_EVENT, $claim_key, 'done', self::DONE_TTL );

		return self::respond( $result['status'], $result['reason'] );
	}

	/**
	 * Route one claimed event to its handler.
	 *
	 * @param string $event   Event name.
	 * @param array  $data    Event data.
	 * @param array  $context Log context.
	 * @return array status and reason.
	 */
	private static function dispatch( string $event, array $data, array $context ): array {
		if ( 'TRANSACTION.CREATED' === $event || 'TRANSACTION.UPDATED' === $event ) {
			return self::handle_transaction_event( $data, $context );
		}

		if ( 'CHECKOUT_SESSION.CREATED' === $event ) {
			return self::handle_checkout_session_event( $data, $context );
		}

		if ( 'PAYMENT_METHOD.UPDATED' === $event ) {
			return self::handle_payment_method_event( $data, $context );
		}

		return self::result( 200, 'ignored' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * TRANSACTION.CREATED and TRANSACTION.UPDATED.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A transaction event is a trigger: resolve, re-fetch, settle (plan 7).
	 *
	 * @param array $data    Event data.
	 * @param array $context Log context.
	 * @return array status and reason.
	 */
	private static function handle_transaction_event( array $data, array $context ): array {
		$transaction_id = Afriex_Settlement_Decision::transaction_id( $data );

		if ( '' === $transaction_id ) {
			Afriex_Logger::warning( 'Webhook transaction event carried no transaction id', $context );
			return self::result( 200, 'no_transaction_id' );
		}

		$context['transaction_id'] = $transaction_id;

		$resolution = Afriex_Settlement::resolve_order_id( $data );

		if ( $resolution['order_id'] <= 0 ) {
			// "More than one order carries this account" is not the same
			// thing as "this money has nothing to do with this store". The
			// payment is still refused — guessing between orders is exactly
			// what F30 forbids — but somebody has to be told, and at webhook
			// time rather than at the payment deadline hours later.
			if ( in_array( $resolution['reason'], array( 'account_ambiguous', 'reference_ambiguous' ), true ) ) {
				return self::report_ambiguous( $resolution, $context );
			}

			// A reference that parses is one this store minted, so "no order
			// holds it" is never ordinary traffic: it is either a real
			// payment whose order has been deleted, or a forged reference.
			// Both are worth a line the merchant can find, and `info` is
			// dropped entirely unless debug logging is on.
			if ( in_array( $resolution['reason'], array( 'reference_unknown', 'reference_order_mismatch' ), true ) ) {
				Afriex_Logger::warning(
					'Webhook transaction carries a reference from this store that no order holds',
					array_merge( $context, array( 'reason' => $resolution['reason'] ) )
				);

				return self::result( 200, 'unmatched:' . $resolution['reason'] );
			}

			// Common and not an error: one Afriex account can receive money
			// that has nothing to do with this store. An unmatched
			// transaction is never applied (F2, F3, F26, F28).
			Afriex_Logger::info(
				'Webhook transaction did not match an order',
				array_merge( $context, array( 'reason' => $resolution['reason'] ) )
			);

			return self::result( 200, 'unmatched:' . $resolution['reason'] );
		}

		$order_id            = (int) $resolution['order_id'];
		$context['order_id'] = $order_id;

		$fetched = self::fetch_transaction( $order_id, $transaction_id, $context );

		if ( isset( $fetched['result'] ) ) {
			return $fetched['result'];
		}

		$outcome = Afriex_Settlement::settle( $order_id, $fetched['transaction'], Afriex_Settlement::SOURCE_WEBHOOK );

		return self::result(
			Afriex_Settlement::http_status( $outcome ),
			$outcome['outcome'] . ':' . $outcome['reason']
		);
	}

	/**
	 * A payment that could belong to more than one order: refuse it, and put
	 * every candidate in front of a person.
	 *
	 * The engine will reach the same conclusion later — the note-only sweep
	 * adds a note at the order's next check, and the expire routine flags it
	 * at the deadline — but "later" can be a day. Since the situation only
	 * arises when two orders share a virtual account, which should not
	 * happen at all, it is worth saying so immediately and loudly.
	 *
	 * Nothing is settled and no ledger row is spent: this is a report, not a
	 * decision about the money.
	 *
	 * @param array $resolution Resolution from Afriex_Settlement.
	 * @param array $context    Log context.
	 * @return array status and reason.
	 */
	private static function report_ambiguous( array $resolution, array $context ): array {
		$candidates = isset( $resolution['candidates'] ) && is_array( $resolution['candidates'] )
			? array_map( 'intval', $resolution['candidates'] )
			: array();

		Afriex_Logger::warning(
			'Webhook transaction matched more than one order and was refused',
			array_merge(
				$context,
				array(
					'reason'     => $resolution['reason'],
					'candidates' => $candidates,
				)
			)
		);

		$others = array();

		foreach ( $candidates as $candidate_id ) {
			$others[ $candidate_id ] = '#' . $candidate_id;
		}

		foreach ( $candidates as $candidate_id ) {
			$order = wc_get_order( $candidate_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$siblings = $others;
			unset( $siblings[ $candidate_id ] );

			Afriex_Order_Meta::flag_for_review( $order, $resolution['reason'] );
			Afriex_Order_Meta::note_once(
				$order,
				$resolution['reason'] . ':' . (string) $context['transaction_id'],
				sprintf(
					/* translators: 1: Afriex transaction id, 2: comma-separated order numbers */
					__( 'Afriex reported payment %1$s, but it could belong to this order or to %2$s, so it has not been applied to any of them. Decide by hand which order it paid for.', 'afriex-gateway-for-woocommerce' ),
					(string) $context['transaction_id'],
					implode( ', ', $siblings )
				)
			);
			Afriex_Settlement::queue_review_notice( $order, $resolution['reason'] );
		}

		return self::result( 200, 'unmatched:' . $resolution['reason'] );
	}

	/**
	 * Re-fetch one transaction with the order's own environment client.
	 *
	 * @param int    $order_id       Order the transaction was resolved to.
	 * @param string $transaction_id Transaction id.
	 * @param array  $context        Log context.
	 * @return array Either array( 'transaction' => array ), or
	 *               array( 'result' => array ) when the caller should stop.
	 */
	private static function fetch_transaction( int $order_id, string $transaction_id, array $context ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => self::result( 200, 'order_missing' ) );
		}

		$budget = self::remaining_budget();

		if ( $budget < self::MIN_USEFUL_BUDGET ) {
			// Out of time before the useful work even starts. Say so, so that
			// Afriex delivers again rather than the sweep having to notice.
			Afriex_Logger::warning( 'Webhook ran out of time before the re-fetch', $context );
			return array( 'result' => self::result( 500, 'budget_exhausted' ) );
		}

		// The credentials the ORDER was created with, not the store's current
		// ones: an environment switch or a key rotation must not orphan an
		// in-flight order, and must not have it looked up with a key that
		// belongs to another account (F50).
		// Read-only: this order was loaded before any lock was taken, and
		// flagging it from here would save through an object a concurrent lock
		// holder is also writing. The sweep's per-order path writes the flag
		// and the note under the lock, which is where that belongs.
		$client = Afriex_Settlement::client_for_order( $order, false );

		if ( ! $client->is_configured() ) {
			// client_for_order() has already said why in the log. 500 rather
			// than 200: the delivery was not handled, and an admin who puts
			// the key back inside Afriex's retry window still gets the payment
			// settled without touching anything.
			Afriex_Logger::warning( 'Webhook could not use this order\'s Afriex credentials', $context );
			return array( 'result' => self::result( 500, 'credentials_unavailable' ) );
		}

		$client->set_time_budget( $budget );

		$transaction = $client->get_transaction(
			$transaction_id,
			array(
				'timeout' => self::FETCH_TIMEOUT,
				'retry'   => false,
			)
		);

		if ( is_wp_error( $transaction ) ) {
			return array( 'result' => self::api_failure( $transaction, 'transaction', $context ) );
		}

		if ( ! is_array( $transaction ) ) {
			return array( 'result' => self::result( 200, 'refetch_empty' ) );
		}

		return array( 'transaction' => $transaction );
	}

	/*
	 * ---------------------------------------------------------------------
	 * CHECKOUT_SESSION.CREATED.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record the session against the exact reference it names (plan 7).
	 *
	 * This event is not a payment signal on its own. It matters because it
	 * carries the session id and the required expiresAt, which are what the
	 * sweep's deadline is built from (plan 5.6). When Afriex also sends an
	 * afriexTransactionId the session has been paid, and that transaction is
	 * re-fetched and settled like any other.
	 *
	 * @param array $data    Event data.
	 * @param array $context Log context.
	 * @return array status and reason.
	 */
	private static function handle_checkout_session_event( array $data, array $context ): array {
		$reference = Afriex_Settlement_Decision::reference( $data );
		$parsed    = Afriex_Order_Meta::parse_reference( $reference );

		if ( $parsed <= 0 ) {
			Afriex_Logger::info( 'Checkout session event carried no reference of ours', $context );
			return self::result( 200, 'not_our_reference' );
		}

		$ids = Afriex_Order_Meta::find_order_ids_by_checkout_reference( $reference );

		// The order id inside the reference is a hint, never the authority:
		// anyone who has seen one reference can write another with a
		// different id in it. The look-up has to agree (plan 6.2).
		if ( 1 !== count( $ids ) || $ids[0] !== $parsed ) {
			Afriex_Logger::info(
				'Checkout session event did not match exactly one order',
				array_merge( $context, array( 'candidates' => count( $ids ) ) )
			);

			return self::result( 200, 'reference_unmatched' );
		}

		$order_id            = $ids[0];
		$context['order_id'] = $order_id;

		$session_id = self::string_field( $data, 'sessionId' );
		$expires_at = self::string_field( $data, 'expiresAt' );

		$lock = Afriex_Settlement::lock_key( $order_id );

		if ( ! Afriex_Ledger::lock( $lock, Afriex_Settlement::lock_timeout( Afriex_Settlement::SOURCE_WEBHOOK ) ) ) {
			return self::result( 500, 'locked' );
		}

		try {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return self::result( 200, 'order_missing' );
			}

			// This is the one handled event whose fields go straight to order
			// meta with no re-fetch, so the two guards the other handlers get
			// for free have to be made explicitly here.
			//
			// First: the key that verified this delivery. Both environments'
			// keys are tried on every delivery (F10), which is right for
			// verification and wrong as authority — without this check a
			// sandbox-signed event could move a production order's payment
			// deadline.
			$order_environment = Afriex_Order_Meta::get_environment( $order );

			if ( '' !== $order_environment && '' !== self::$verified_environment && $order_environment !== self::$verified_environment ) {
				Afriex_Logger::warning(
					'Checkout session event was signed by the other environment\'s key',
					array_merge(
						$context,
						array(
							'order_environment'    => $order_environment,
							'verified_environment' => self::$verified_environment,
						)
					)
				);

				return self::result( 200, 'environment_mismatch' );
			}

			$changes = array();

			if ( '' !== $session_id ) {
				$changes['session_id'] = $session_id;
			}

			// Second: expiresAt only ever moves a deadline forward
			// (Afriex_Order_Meta::extend_deadline), so an absurd value would
			// hold the order open for ever and could not be walked back. A
			// session cannot outlive the payment window by more than the
			// ceiling below.
			if ( '' !== $expires_at ) {
				$stamp = is_numeric( $expires_at ) ? (int) $expires_at : (int) strtotime( $expires_at );

				if ( $stamp <= 0 ) {
					Afriex_Logger::warning( 'Checkout session event carried an unreadable expiresAt', array_merge( $context, array( 'expires_at' => $expires_at ) ) );
				} elseif ( $stamp > time() + self::MAX_SESSION_LIFETIME ) {
					Afriex_Logger::warning( 'Checkout session expiry is further off than a session can be; ignored', array_merge( $context, array( 'expires_at' => $expires_at ) ) );
				} else {
					$changes['expires_at'] = $expires_at;
				}
			}

			if ( ! empty( $changes ) ) {
				Afriex_Order_Meta::update_checkout_reference( $order, $reference, $changes );
			}
		} finally {
			Afriex_Ledger::unlock( $lock );
		}

		$transaction_id = self::string_field( $data, 'afriexTransactionId' );

		if ( '' === $transaction_id ) {
			return self::result( 200, 'session_recorded' );
		}

		$context['transaction_id'] = $transaction_id;

		$fetched = self::fetch_transaction( $order_id, $transaction_id, $context );

		if ( isset( $fetched['result'] ) ) {
			return $fetched['result'];
		}

		$outcome = Afriex_Settlement::settle( $order_id, $fetched['transaction'], Afriex_Settlement::SOURCE_WEBHOOK );

		return self::result(
			Afriex_Settlement::http_status( $outcome ),
			$outcome['outcome'] . ':' . $outcome['reason']
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * PAYMENT_METHOD.UPDATED.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A virtual account changed. A trigger only (plan 7, plan 16 question 6).
	 *
	 * The event is never believed on its own: the account is re-fetched, and
	 * only an account Afriex itself now calls expired starts the expire
	 * routine. That routine settles any SUCCESS it finds before it cancels
	 * anything, so an expiry delivered ahead of the deposit's own event
	 * cannot end an order that was in fact paid.
	 *
	 * @param array $data    Event data.
	 * @param array $context Log context.
	 * @return array status and reason.
	 */
	private static function handle_payment_method_event( array $data, array $context ): array {
		$payment_method_id = self::string_field( $data, 'paymentMethodId' );

		if ( '' === $payment_method_id ) {
			$payment_method_id = self::string_field( $data, 'id' );
		}

		if ( '' === $payment_method_id ) {
			return self::result( 200, 'no_payment_method_id' );
		}

		$context['payment_method_id'] = $payment_method_id;

		$order_id = 0;

		// Open orders first: an account that somehow belongs to more than one
		// order should expire the one still waiting for money, and more than
		// one candidate is refused rather than guessed at (F30).
		foreach ( array( array( 'on-hold', 'pending' ), array() ) as $statuses ) {
			$ids = Afriex_Order_Meta::find_order_ids_by_payment_method_id( $payment_method_id, $statuses );

			if ( 1 === count( $ids ) ) {
				$order_id = $ids[0];
				break;
			}

			if ( count( $ids ) > 1 ) {
				Afriex_Logger::warning( 'Payment method event matched more than one order', $context );
				return self::result( 200, 'account_ambiguous' );
			}
		}

		if ( $order_id <= 0 ) {
			Afriex_Logger::info( 'Payment method event did not match an order', $context );
			return self::result( 200, 'unmatched' );
		}

		$context['order_id'] = $order_id;

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::result( 200, 'order_missing' );
		}

		$budget = self::remaining_budget();

		if ( $budget < self::MIN_USEFUL_BUDGET ) {
			Afriex_Logger::warning( 'Webhook ran out of time before the payment method re-fetch', $context );
			return self::result( 500, 'budget_exhausted' );
		}

		// Read-only for the same reason as fetch_transaction(): no lock is held
		// on this order yet.
		$client = Afriex_Settlement::client_for_order( $order, false );

		if ( ! $client->is_configured() ) {
			Afriex_Logger::warning( 'Webhook could not use this order\'s Afriex credentials', $context );
			return self::result( 500, 'credentials_unavailable' );
		}

		$client->set_time_budget( $budget );

		$account = $client->get_payment_method(
			$payment_method_id,
			array(
				'timeout' => self::FETCH_TIMEOUT,
				'retry'   => false,
			)
		);

		if ( is_wp_error( $account ) ) {
			return self::api_failure( $account, 'payment method', $context );
		}

		$status = strtolower( self::string_field( is_array( $account ) ? $account : array(), 'status' ) );

		if ( 'expired' !== $status ) {
			Afriex_Logger::debug( 'Payment method is not expired; nothing to do', array_merge( $context, array( 'status' => $status ) ) );
			return self::result( 200, 'not_expired' );
		}

		// The budget set on the client above dies with it: the settlement
		// engine builds its own client for the order's environment, and the
		// client deliberately does not cache instances, so a budget has to be
		// handed on explicitly. Without it the expire routine runs with full
		// default timeouts and retries — up to a minute more on top of a
		// delivery Afriex only waits 30 s for.
		$outcome = Afriex_Settlement::expire( $order_id, Afriex_Settlement::SOURCE_WEBHOOK, self::remaining_budget() );

		return self::result(
			Afriex_Settlement::http_status( $outcome ),
			$outcome['outcome'] . ':' . $outcome['reason']
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * The event claim (plan 7).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Claim one event, or say who holds it.
	 *
	 * @param string $key Claim key.
	 * @return string 'claimed', 'done' or 'processing'.
	 */
	private static function claim_event( string $key ): string {
		if ( Afriex_Ledger::claim( Afriex_Ledger::KIND_EVENT, $key, 0, 'processing', self::PROCESSING_TTL ) ) {
			return 'claimed';
		}

		$holder = Afriex_Ledger::holder( Afriex_Ledger::KIND_EVENT, $key );

		if ( ! is_array( $holder ) ) {
			// The row went away between the claim and the read — a purge, or
			// another delivery releasing it. One more try; if that also
			// loses, somebody else is genuinely holding it.
			return Afriex_Ledger::claim( Afriex_Ledger::KIND_EVENT, $key, 0, 'processing', self::PROCESSING_TTL )
				? 'claimed'
				: 'processing';
		}

		return 'done' === $holder['state'] ? 'done' : 'processing';
	}

	/**
	 * The key that identifies one logical event (plan 7).
	 *
	 * Each family gets its own prefix, so two families can never collide on
	 * an id, and each key carries whatever makes a genuine change a new
	 * event: a transaction's status and updatedAt, a payment method's status
	 * and the body itself. An event with no identifying id falls back to the
	 * hash of the raw body, which is the most anyone can say about it.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 * @param string $raw   Raw request body.
	 */
	private static function claim_key( string $event, array $data, string $raw ): string {
		if ( 0 === strpos( $event, 'TRANSACTION.' ) ) {
			$transaction_id = Afriex_Settlement_Decision::transaction_id( $data );

			if ( '' !== $transaction_id ) {
				return 'txn|' . $transaction_id . '|' . self::string_field( $data, 'status' ) . '|' . self::string_field( $data, 'updatedAt' );
			}
		}

		if ( 'CHECKOUT_SESSION.CREATED' === $event ) {
			$session_id = self::string_field( $data, 'sessionId' );

			if ( '' !== $session_id ) {
				return 'session|' . $session_id;
			}
		}

		if ( 0 === strpos( $event, 'PAYMENT_METHOD.' ) ) {
			$payment_method_id = self::string_field( $data, 'paymentMethodId' );
			$payment_method_id = '' === $payment_method_id ? self::string_field( $data, 'id' ) : $payment_method_id;

			if ( '' !== $payment_method_id ) {
				return 'pm|' . $payment_method_id . '|' . self::string_field( $data, 'status' ) . '|' . hash( 'sha256', $raw );
			}
		}

		return 'body|' . $event . '|' . hash( 'sha256', $raw );
	}

	/**
	 * Whether a transaction event is too old to act on (F6).
	 *
	 * Only TRANSACTION.* events carry an updatedAt the plan trusts for this.
	 * A timestamp that will not parse is not treated as stale: refusing a
	 * delivery because its date format surprised us would lose a payment.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	private static function is_stale( string $event, array $data ): bool {
		if ( 0 !== strpos( $event, 'TRANSACTION.' ) ) {
			return false;
		}

		$updated_at = self::string_field( $data, 'updatedAt' );

		if ( '' === $updated_at ) {
			return false;
		}

		$time = strtotime( $updated_at );

		if ( false === $time ) {
			return false;
		}

		return $time < ( time() - self::STALE_AFTER );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * What to do about a failed re-fetch.
	 *
	 * A 5xx, a 429 or a transport failure is Afriex having a bad moment, so
	 * the delivery is refused and Afriex tries again. A 4xx is an answer: the
	 * transaction does not exist, or this key may not see it, and eleven more
	 * deliveries would reach the same answer. Those are acknowledged and
	 * logged, and left to the sweep.
	 *
	 * @param WP_Error $error   The client's error.
	 * @param string   $what    What was being fetched, for the log message.
	 * @param array    $context Log context.
	 * @return array status and reason.
	 */
	private static function api_failure( WP_Error $error, string $what, array $context ): array {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		$permanent = $status >= 400 && $status < 500 && 429 !== $status;

		Afriex_Logger::warning(
			'Webhook could not re-fetch the ' . $what,
			array_merge(
				$context,
				array(
					'status' => $status,
					'error'  => $error->get_error_message(),
				)
			)
		);

		return $permanent
			? self::result( 200, 'refetch_refused:' . $status )
			: self::result( 500, 'refetch_failed:' . $status );
	}

	/**
	 * Seconds left in this delivery's budget.
	 */
	private static function remaining_budget(): float {
		if ( self::$deadline <= 0.0 ) {
			return self::TIME_BUDGET;
		}

		return max( 0.0, self::$deadline - microtime( true ) );
	}

	/**
	 * One scalar field of an event payload, as a trimmed string.
	 *
	 * @param array  $data Event data.
	 * @param string $key  Field name.
	 */
	private static function string_field( array $data, string $key ): string {
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return '';
		}

		return trim( (string) $data[ $key ] );
	}

	/**
	 * Shape one handler result.
	 *
	 * @param int    $status HTTP status.
	 * @param string $reason Short reason code, for the log and the body.
	 */
	private static function result( int $status, string $reason ): array {
		return array(
			'status' => $status,
			'reason' => $reason,
		);
	}

	/**
	 * Answer the delivery.
	 *
	 * The body says only what happened, in the plugin's own words. It never
	 * carries order data: whoever reaches this route has a valid Afriex
	 * signature, but the answer still travels back over the internet.
	 *
	 * @param int    $status HTTP status.
	 * @param string $reason Short reason code.
	 */
	private static function respond( int $status, string $reason ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'received' => $status < 400,
				'result'   => $reason,
			),
			$status
		);
	}
}
