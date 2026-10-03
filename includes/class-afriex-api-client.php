<?php
/**
 * REST client for the Afriex Business API over the WordPress HTTP API.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's entire Afriex integration surface.
 *
 * There is no Afriex PHP SDK, so every call goes directly against the REST API
 * using wp_remote_request(). Every method returns a plain array on success or a
 * WP_Error on failure, the WordPress convention, rather than throwing.
 *
 * Error contract. Afriex errors look like
 * {code, error, details: {errorMessage, friendlyMessage, data}}, but 502 and
 * 504 come from Cloudflare with an HTML body, and friendlyMessage is often "".
 * Every failure becomes WP_Error( 'afriex_api_error', $log_message, $data )
 * where $data is:
 * - status:      HTTP status, 0 for a transport failure;
 * - code:        Afriex's machine code, or '' when absent;
 * - friendly:    details.friendlyMessage when non-empty, else '' (the only
 *                text ever safe to show a shopper, F42);
 * - details:     details.data as an array (e.g. the existing customerId on a
 *                409), else array();
 * - retry_after: seconds from Retry-After, or 0.
 * Empty strings are treated as absent throughout (F12). Failures that never
 * reached Afriex (no key, spent budget) use their own WP_Error code with the
 * same data shape.
 *
 * Retries. Only idempotent GETs are retried, once, on 429/502/503/504, and
 * only when Retry-After is at most 3 s. A POST is never retried: a 503 from
 * Afriex means the request "may already have taken effect", and a blind retry
 * of a create call can mint a second customer, account or session (F39).
 *
 * Time. Each call has its own timeout, filterable through
 * afriex_wc_http_timeout, and a caller can set a total time budget so
 * two sequential calls cannot outlast a proxy's 60 s limit (F39) or Afriex's
 * 30 s webhook wait.
 *
 * Environments. One client serves one environment. for_environment() builds
 * the client for the environment an order was created under, so an admin
 * switching environment does not send old orders' look-ups to the wrong host
 * (F50). Clients are cheap and deliberately not cached: a time budget set by
 * one caller must never leak into another caller's requests.
 */
class Afriex_Api_Client {

	public const API_VERSION = '2026-05-18';

	public const ENV_SANDBOX    = 'sandbox';
	public const ENV_PRODUCTION = 'production';

	/**
	 * Option holding the last 401 per environment (F84).
	 */
	public const AUTH_ERROR_OPTION = 'afriex_wc_auth_error';

	/**
	 * Transaction list page size: Afriex's maximum.
	 */
	public const LIST_PAGE_SIZE = 100;

	/**
	 * Default ceiling on list pages fetched in one call.
	 */
	public const LIST_MAX_PAGES = 10;

	/**
	 * Page ceiling for a listing made while somebody is waiting for the page
	 * to load: the checkout retry check and the return handler's check.
	 *
	 * Five pages is 500 rows, which is far more than any one reference can
	 * carry; the ceiling is there so that a listing which is somehow not
	 * narrowing cannot hold a shopper's browser open. A result that would need
	 * more comes back truncated, and callers read that as "cannot decide"
	 * rather than as "no payment arrived".
	 */
	public const FOREGROUND_MAX_PAGES = 5;

	private const SANDBOX_BASE_URL    = 'https://sandbox.api.afriex.com/api/v1';
	private const PRODUCTION_BASE_URL = 'https://api.afriex.com/api/v1';

	/**
	 * Default per-call timeouts in seconds. Callers with a tighter deadline
	 * (webhook re-fetch, return handler) pass their own or set a budget.
	 */
	private const DEFAULT_TIMEOUTS = array(
		'GET'  => 10,
		'POST' => 15,
	);

	/**
	 * Longest Retry-After the client will sleep for before its one GET retry.
	 */
	private const MAX_RETRY_WAIT = 3;

	/**
	 * Wait used when a retryable response carries no Retry-After.
	 */
	private const DEFAULT_RETRY_WAIT = 1;

	/**
	 * A call is not started with less than this left in the budget: it could
	 * only time out.
	 */
	private const MIN_USEFUL_SECONDS = 1.0;

	/**
	 * Hosts already warned about in this request, so a filtered base URL logs
	 * once rather than on every call.
	 *
	 * @var array<string, bool>
	 */
	private static $warned_hosts = array();

	/**
	 * API key from the merchant's Afriex Business dashboard.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Either 'sandbox' or 'production'.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Absolute microtime() deadline for calls on this client, or 0 for none.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * One client talks to one environment with one key.
	 *
	 * @param string $environment 'sandbox' or 'production'; the 1.0 value
	 *                            'staging' and anything unknown mean sandbox.
	 * @param string $api_key     API key for that environment.
	 */
	public function __construct( string $environment, string $api_key ) {
		$this->environment = self::normalise_environment( $environment );
		$this->api_key     = trim( $api_key );
	}

	/**
	 * A new client for an environment, built from the shared account settings.
	 *
	 * @param string|null $environment 'sandbox', 'production', or null for the
	 *                                 currently configured environment.
	 */
	public static function for_environment( ?string $environment = null ): self {
		$environment = null === $environment || '' === $environment
			? Afriex_Account_Settings::environment()
			: self::normalise_environment( $environment );

		return new self( $environment, Afriex_Account_Settings::api_key() );
	}

	/**
	 * Map any stored environment value to 'sandbox' or 'production'.
	 *
	 * @param string $environment Raw value; 'staging' (1.0) and '' mean sandbox.
	 */
	public static function normalise_environment( string $environment ): string {
		return self::ENV_PRODUCTION === strtolower( trim( $environment ) ) ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
	}

	/**
	 * The environment this client talks to: 'sandbox' or 'production'.
	 */
	public function get_environment(): string {
		return $this->environment;
	}

	/**
	 * Whether the client has enough configuration to be usable at all.
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Limit the total time every following call on this client may take.
	 *
	 * @param float|null $seconds Seconds from now, or null to remove the budget.
	 */
	public function set_time_budget( ?float $seconds ): void {
		$this->deadline = null === $seconds ? 0.0 : microtime( true ) + max( 0.0, $seconds );
	}

	/**
	 * Seconds left in the budget, or null when there is none.
	 */
	public function remaining_budget(): ?float {
		return $this->deadline > 0 ? max( 0.0, $this->deadline - microtime( true ) ) : null;
	}

	/**
	 * Create a customer.
	 *
	 * A 409 EMAIL_ALREADY_EXISTS or PHONE_NUMBER_ALREADY_EXISTS carries the
	 * existing id in the error data as details['customerId'] (F25).
	 *
	 * @param array $customer_data fullName, email, phone (E.164), countryCode.
	 * @param array $args          Request options: timeout.
	 * @return array|WP_Error
	 */
	public function create_customer( array $customer_data, array $args = array() ) {
		return $this->unwrap( $this->request( 'POST', '/customer', array(), $customer_data, $args ) );
	}

	/**
	 * Find a customer by exact email.
	 *
	 * @param string $email Email address.
	 * @param array  $args  Request options.
	 * @return array|null|WP_Error The customer, or null when none matches.
	 */
	public function find_customer_by_email( string $email, array $args = array() ) {
		$email = trim( $email );

		if ( '' === $email ) {
			return null;
		}

		$result = $this->request(
			'GET',
			'/customer',
			array(
				'email' => $email,
				'page'  => 0,
				'limit' => 10,
			),
			null,
			$args
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Nothing documents the filter as an exact match, so check.
		foreach ( self::list_items( $result ) as $customer ) {
			if ( is_array( $customer ) && isset( $customer['email'] ) && 0 === strcasecmp( (string) $customer['email'], $email ) ) {
				return $customer;
			}
		}

		return null;
	}

	/**
	 * Create a virtual account. With an amount it is a dynamic account for
	 * that amount, and the response carries expiresInMinutes.
	 *
	 * @param array $account_data currency, customerId, amount (major units).
	 * @param array $args         Request options.
	 * @return array|WP_Error
	 */
	public function create_virtual_account( array $account_data, array $args = array() ) {
		return $this->unwrap( $this->request( 'POST', '/payment-method/virtual-account', array(), $account_data, $args ) );
	}

	/**
	 * Fetch one payment method, e.g. to re-check a virtual account's status.
	 *
	 * @param string $payment_method_id Afriex payment method id.
	 * @param array  $args              Request options.
	 * @return array|WP_Error
	 */
	public function get_payment_method( string $payment_method_id, array $args = array() ) {
		if ( '' === trim( $payment_method_id ) ) {
			return $this->local_error( 'afriex_invalid_argument', __( 'A payment method id is required.', 'afriex-gateway-for-woocommerce' ) );
		}

		return $this->unwrap( $this->request( 'GET', '/payment-method/' . rawurlencode( $payment_method_id ), array(), null, $args ) );
	}

	/**
	 * Fetch one transaction. Webhooks are only a trigger; the fetched object
	 * is what settlement acts on (F27).
	 *
	 * @param string $transaction_id Afriex transaction id.
	 * @param array  $args           Request options.
	 * @return array|WP_Error
	 */
	public function get_transaction( string $transaction_id, array $args = array() ) {
		if ( '' === trim( $transaction_id ) ) {
			return $this->local_error( 'afriex_invalid_argument', __( 'A transaction id is required.', 'afriex-gateway-for-woocommerce' ) );
		}

		return $this->unwrap( $this->request( 'GET', '/transaction/' . rawurlencode( $transaction_id ), array(), null, $args ) );
	}

	/**
	 * List transactions matching filters, across pages.
	 *
	 * Pages start at 0 with limit 100. Paging stops when a page is empty, when
	 * (page + 1) * limit >= total, or at $max_pages, whichever comes first, so
	 * a wrong total can never make this loop forever. A response without a
	 * total keeps paging only while pages come back full.
	 *
	 * Only filters Afriex documents are ever sent. The undocumented sourceId
	 * and destinationId pair was removed in 1.1: a filter the API may quietly
	 * ignore is not a filter, and matching a deposit to an account is now done
	 * here, in memory, where it can be trusted (plan 9, F2).
	 *
	 * @param array $filters   reference, type, status, channel, currency,
	 *                         transactionId, fromDate, toDate. Anything else
	 *                         is dropped.
	 * @param int   $max_pages Page ceiling.
	 * @param array $args      Request options.
	 * @return array|WP_Error array( 'data' => rows, 'total' => int,
	 *                        'pages' => pages fetched, 'truncated' => bool ).
	 */
	public function list_transactions( array $filters = array(), int $max_pages = self::LIST_MAX_PAGES, array $args = array() ) {
		$allowed = array( 'reference', 'type', 'status', 'channel', 'currency', 'transactionId', 'fromDate', 'toDate' );
		$query   = array();

		foreach ( $allowed as $name ) {
			if ( isset( $filters[ $name ] ) && '' !== (string) $filters[ $name ] ) {
				$query[ $name ] = (string) $filters[ $name ];
			}
		}

		$rows      = array();
		$total     = 0;
		$page      = 0;
		$max_pages = max( 1, $max_pages );
		$truncated = false;

		while ( true ) {
			$query['page']  = $page;
			$query['limit'] = self::LIST_PAGE_SIZE;

			$result = $this->request( 'GET', '/transaction', $query, null, $args );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$items     = self::list_items( $result );
			$has_total = isset( $result['total'] ) && is_numeric( $result['total'] );

			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$rows[] = $item;
				}
			}

			if ( $has_total ) {
				$total = (int) $result['total'];
				$done  = ( $page + 1 ) * self::LIST_PAGE_SIZE >= $total;
			} else {
				$total = count( $rows );
				$done  = count( $items ) < self::LIST_PAGE_SIZE;
			}

			if ( empty( $items ) || $done ) {
				break;
			}

			if ( $page + 1 >= $max_pages ) {
				$truncated = true;
				Afriex_Logger::warning(
					'Transaction list truncated at the page ceiling',
					array(
						'pages' => $max_pages,
						'total' => $total,
					)
				);
				break;
			}

			++$page;
		}

		return array(
			'data'      => $rows,
			'total'     => $total,
			'pages'     => $page + 1,
			'truncated' => $truncated,
		);
	}

	/**
	 * Create a hosted Checkout Session. Never retried automatically.
	 *
	 * @param array $session_data amount (minor units), currency,
	 *                            merchantReference, redirectUrl, customer,
	 *                            channels, metadata.
	 * @param array $args         Request options.
	 * @return array|WP_Error checkoutUrl and channels.
	 */
	public function create_checkout_session( array $session_data, array $args = array() ) {
		return $this->unwrap( $this->request( 'POST', '/checkout-session', array(), $session_data, $args ) );
	}

	/**
	 * The last 401 recorded for an environment, or null.
	 *
	 * @param string|null $environment Environment, null for the configured one.
	 * @return array|null status, code, time.
	 */
	public static function auth_error( ?string $environment = null ): ?array {
		$environment = null === $environment ? Afriex_Account_Settings::environment() : self::normalise_environment( $environment );
		$errors      = get_option( self::AUTH_ERROR_OPTION, array() );

		return is_array( $errors ) && isset( $errors[ $environment ] ) && is_array( $errors[ $environment ] ) ? $errors[ $environment ] : null;
	}

	/**
	 * Forget recorded 401s, e.g. when the account settings are saved.
	 */
	public static function clear_auth_errors(): void {
		if ( false !== get_option( self::AUTH_ERROR_OPTION, false ) ) {
			delete_option( self::AUTH_ERROR_OPTION );
		}
	}

	/**
	 * Whether a host is afriex.com or one of its subdomains.
	 *
	 * @param string $host Host name.
	 */
	public static function is_afriex_host( string $host ): bool {
		$host = strtolower( rtrim( trim( $host ), '.' ) );

		return 'afriex.com' === $host || '.afriex.com' === substr( $host, -11 );
	}

	/**
	 * Read Afriex's error shape, tolerating anything else.
	 *
	 * Public and static so it can be unit tested without HTTP.
	 *
	 * @param array|null $body   Decoded JSON body, or null when it was not JSON.
	 * @param int        $status HTTP status.
	 * @return array code, message (for logs), friendly (for shoppers), details.
	 */
	public static function parse_error_body( ?array $body, int $status ): array {
		$details  = isset( $body['details'] ) && is_array( $body['details'] ) ? $body['details'] : array();
		$code     = isset( $body['code'] ) && is_int( $body['code'] ) ? (string) $body['code'] : self::non_empty_string( $body['code'] ?? null );
		$friendly = self::non_empty_string( $details['friendlyMessage'] ?? null );
		$message  = self::non_empty_string( $details['errorMessage'] ?? null );

		if ( '' === $message ) {
			$message = self::non_empty_string( $body['error'] ?? null );
		}

		if ( '' === $message ) {
			// Foreign shapes, e.g. a proxy's {"message": "..."}.
			$message = self::non_empty_string( $body['message'] ?? null );
		}

		if ( '' === $message && null === $body && in_array( $status, array( 502, 504 ), true ) ) {
			$message = 'Upstream gateway error (no Afriex body)';
		}

		return array(
			'code'     => $code,
			'message'  => $message,
			'friendly' => $friendly,
			'details'  => isset( $details['data'] ) && is_array( $details['data'] ) ? $details['data'] : array(),
		);
	}

	/**
	 * Retry-After as whole seconds: delta-seconds or an HTTP date; 0 if absent.
	 *
	 * @param mixed $header Header value.
	 */
	public static function parse_retry_after( $header ): int {
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}

		$header = trim( (string) $header );

		if ( '' === $header ) {
			return 0;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$time = strtotime( $header );

		return false === $time ? 0 : max( 0, $time - time() );
	}

	/**
	 * Send one request, applying the GET retry rule.
	 *
	 * @param string     $method GET or POST.
	 * @param string     $path   Path after /api/v1, e.g. '/transaction'.
	 * @param array      $query  Query arguments.
	 * @param array|null $body   JSON body for POST.
	 * @param array      $args   timeout (seconds), retry (bool, GET only).
	 * @return array|WP_Error Decoded response body.
	 */
	private function request( string $method, string $path, array $query, ?array $body, array $args ) {
		if ( ! $this->is_configured() ) {
			return $this->local_error(
				'afriex_not_configured',
				__( 'Afriex is not configured. Add an API key in the Afriex account settings.', 'afriex-gateway-for-woocommerce' )
			);
		}

		$base = $this->base_url();

		if ( '' === $base ) {
			return $this->local_error( 'afriex_bad_base_url', __( 'The Afriex API address is not a secure URL.', 'afriex-gateway-for-woocommerce' ) );
		}

		$url = $base . $path;

		if ( ! empty( $query ) ) {
			// add_query_arg() does not encode values, so encode them here.
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$can_retry = 'GET' === $method && ( ! isset( $args['retry'] ) || false !== $args['retry'] );
		$attempt   = 0;

		while ( true ) {
			++$attempt;

			$timeout = $this->timeout_for( $method, $path, $args );

			if ( null === $timeout ) {
				return $this->local_error( 'afriex_time_budget_exceeded', __( 'The Afriex request was not sent because the time budget ran out.', 'afriex-gateway-for-woocommerce' ) );
			}

			Afriex_Logger::debug(
				$method . ' ' . $path,
				array(
					'environment' => $this->environment,
					'attempt'     => $attempt,
					'timeout'     => $timeout,
				)
			);

			$request_args = array(
				'method'      => $method,
				'headers'     => $this->request_headers(),
				'timeout'     => $timeout,
				'redirection' => 0,
			);

			if ( null !== $body ) {
				$request_args['body'] = wp_json_encode( $body );
			}

			$result = $this->parse_response( wp_remote_request( $url, $request_args ), $method, $path );

			if ( ! is_wp_error( $result ) || ! $can_retry || $attempt > 1 ) {
				return $result;
			}

			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

			if ( ! in_array( $status, array( 429, 502, 503, 504 ), true ) ) {
				return $result;
			}

			$wait      = is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : self::DEFAULT_RETRY_WAIT;
			$remaining = $this->remaining_budget();

			// Honour Retry-After, but never sleep longer than 3 s inside a web
			// request, and never past the caller's budget.
			if ( $wait > self::MAX_RETRY_WAIT || ( null !== $remaining && $remaining < $wait + self::MIN_USEFUL_SECONDS ) ) {
				return $result;
			}

			sleep( $wait );
		}
	}

	/**
	 * The timeout for one call: the caller's value or the default, filtered,
	 * then capped by the remaining budget. Null when the budget is spent.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 * @param array  $args   Request options.
	 */
	private function timeout_for( string $method, string $path, array $args ): ?float {
		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) ( self::DEFAULT_TIMEOUTS[ $method ] ?? 10 );

		/**
		 * Filter the timeout of one Afriex API call, in seconds.
		 *
		 * @param float  $timeout     Timeout.
		 * @param string $method      HTTP method.
		 * @param string $path        Path after /api/v1.
		 * @param string $environment 'sandbox' or 'production'.
		 */
		$timeout = max( 1.0, (float) apply_filters( 'afriex_wc_http_timeout', $timeout, $method, $path, $this->environment ) );

		$remaining = $this->remaining_budget();

		if ( null !== $remaining ) {
			if ( $remaining < self::MIN_USEFUL_SECONDS ) {
				return null;
			}

			$timeout = min( $timeout, $remaining );
		}

		return $timeout;
	}

	/**
	 * The API base URL for this client's environment.
	 *
	 * The filter lets an integrator point at another host (a proxy, a staging
	 * host Afriex supplies), but the API key travels in a header, so the result
	 * must be HTTPS: anything else is refused and logged. A host outside
	 * afriex.com is allowed but logged, since it receives the key.
	 */
	private function base_url(): string {
		$default = self::ENV_PRODUCTION === $this->environment ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;

		/**
		 * Filter the Afriex API base URL.
		 *
		 * @param string $url         Base URL including the /api/v1 prefix.
		 * @param string $environment 'sandbox' or 'production'.
		 */
		$url = untrailingslashit( (string) apply_filters( 'afriex_wc_api_base_url', $default, $this->environment ) );

		if ( $url === $default ) {
			return $url;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== $scheme || '' === $host ) {
			Afriex_Logger::error( 'Filtered Afriex API base URL rejected: it must be an https URL', array( 'host' => $host ) );
			return '';
		}

		if ( ! self::is_afriex_host( $host ) && ! isset( self::$warned_hosts[ $host ] ) ) {
			self::$warned_hosts[ $host ] = true;
			Afriex_Logger::warning( 'Afriex API base URL points outside afriex.com; the API key is sent to this host', array( 'host' => $host ) );
		}

		return $url;
	}

	/**
	 * Headers for every call. x-api-version pins the response shapes this
	 * client parses, so an Afriex default-version change cannot alter them.
	 */
	private function request_headers(): array {
		return array(
			'x-api-key'     => $this->api_key,
			'x-api-version' => self::API_VERSION,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'User-Agent'    => 'afriex-gateway-for-woocommerce/' . AFRIEX_WC_VERSION,
		);
	}

	/**
	 * Turn a wp_remote_request() result into a decoded body or a WP_Error.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @param string         $method   HTTP method, for the log.
	 * @param string         $path     Path, for the log.
	 * @return array|WP_Error
	 */
	private function parse_response( $response, string $method, string $path ) {
		if ( is_wp_error( $response ) ) {
			Afriex_Logger::error(
				'Afriex request failed to send',
				array(
					'request' => $method . ' ' . $path,
					'error'   => $response->get_error_message(),
				)
			);

			return new WP_Error(
				'afriex_api_error',
				$response->get_error_message(),
				array(
					'status'      => 0,
					'code'        => 'HTTP_REQUEST_FAILED',
					'friendly'    => '',
					'details'     => array(),
					'retry_after' => 0,
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $raw ? null : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			$this->clear_auth_error_if_set();

			if ( ! is_array( $decoded ) ) {
				Afriex_Logger::error(
					'Afriex returned an unreadable success response',
					array(
						'request' => $method . ' ' . $path,
						'status'  => $status,
					)
				);

				return new WP_Error(
					'afriex_api_error',
					'Unreadable response body',
					array(
						'status'      => $status,
						'code'        => 'UNREADABLE_RESPONSE',
						'friendly'    => '',
						'details'     => array(),
						'retry_after' => 0,
					)
				);
			}

			return $decoded;
		}

		$error       = self::parse_error_body( is_array( $decoded ) ? $decoded : null, $status );
		$retry_after = self::parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );

		// Only a 401 says anything about the key: Afriex answers a missing,
		// revoked or under-permissioned key with 401 alike. A 403 is one
		// endpoint refusing this business (a checkout session the account
		// may not create, a virtual account pending compliance review), so
		// it must not hide every Afriex method; the caller handles it.
		if ( 401 === $status ) {
			$this->record_auth_error( $status, $error['code'] );
		}

		$log_message = '' !== $error['message'] ? $error['message'] : 'HTTP ' . $status;

		if ( '' !== $error['code'] ) {
			$log_message = $error['code'] . ': ' . $log_message;
		}

		Afriex_Logger::error(
			'Afriex API error',
			array(
				'request' => $method . ' ' . $path,
				'status'  => $status,
				'code'    => $error['code'],
				'message' => $error['message'],
			)
		);

		return new WP_Error(
			'afriex_api_error',
			$log_message,
			array(
				'status'      => $status,
				'code'        => $error['code'],
				'friendly'    => $error['friendly'],
				'details'     => $error['details'],
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * A trimmed string, or '' for anything else: empty means absent (F12).
	 *
	 * @param mixed $value Candidate string.
	 */
	private static function non_empty_string( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The "data" member of a success envelope, or the body itself.
	 *
	 * @param array|WP_Error $result Decoded body.
	 * @return array|WP_Error
	 */
	private function unwrap( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
	}

	/**
	 * Rows of a list envelope {page, total, data: [...]}, or of a bare list.
	 *
	 * @param array $result Decoded body.
	 */
	private static function list_items( array $result ): array {
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			return array_values( $result['data'] );
		}

		if ( empty( $result ) ) {
			return array();
		}

		return array_keys( $result ) === range( 0, count( $result ) - 1 ) ? $result : array();
	}

	/**
	 * Record a 401 so an admin notice can explain why Afriex is hidden
	 * (F84). Keyed by environment: a bad production key says nothing about
	 * sandbox.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Afriex code.
	 */
	private function record_auth_error( int $status, string $code ): void {
		$errors = get_option( self::AUTH_ERROR_OPTION, array() );
		$errors = is_array( $errors ) ? $errors : array();

		$errors[ $this->environment ] = array(
			'status' => $status,
			'code'   => $code,
			'time'   => time(),
		);

		update_option( self::AUTH_ERROR_OPTION, $errors, true );
	}

	/**
	 * A successful call proves the key works again.
	 */
	private function clear_auth_error_if_set(): void {
		$errors = get_option( self::AUTH_ERROR_OPTION, array() );

		if ( ! is_array( $errors ) || ! isset( $errors[ $this->environment ] ) ) {
			return;
		}

		unset( $errors[ $this->environment ] );

		if ( empty( $errors ) ) {
			delete_option( self::AUTH_ERROR_OPTION );
		} else {
			update_option( self::AUTH_ERROR_OPTION, $errors, true );
		}
	}

	/**
	 * A WP_Error for a failure that never reached Afriex, in the same shape.
	 *
	 * @param string $code    Local code.
	 * @param string $message Message.
	 */
	private function local_error( string $code, string $message ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'      => 0,
				'code'        => $code,
				'friendly'    => '',
				'details'     => array(),
				'retry_after' => 0,
			)
		);
	}
}
