<?php
/**
 * Plugin Name: Afriex dev mock API
 * Description: Development only (tests/ never ships). Answers every Afriex API call locally and delivers genuinely signed webhooks, so the whole payment flow can be exercised without an Afriex account.
 *
 * Why this exists (plan section 15). There is no Afriex access on this site:
 * the gateway is configured with a fake API key, and calling the real sandbox
 * would record a 401 and hide the payment methods. Every scenario the plan
 * lists — a 409 on a duplicate customer, a 422 for unsupported channels, a 503
 * after the session was created, a lost webhook, a late SUCCESS — has to be
 * produced locally, on demand and repeatably.
 *
 * How it works:
 * - It hooks pre_http_request at the default priority, so it answers before
 *   tests/mu-plugins/10-afriex-block-live-api.php refuses the call, and only
 *   for afriex.com and its subdomains. Nothing else is touched.
 * - State lives in one non-autoloaded option, so a test can create a customer
 *   in one request and find it in the next, and `reset` wipes it in one call.
 * - Control and inspection happen over REST under /wp-json/afriex-mock/v1/,
 *   reachable only when the request's Host header is localhost or 127.0.0.1.
 *   The tunnel guard (00-tunnel.php) already 404s everything but the webhook
 *   route from any other host; this is the second lock on the same door.
 * - Webhooks it delivers are signed with openssl_sign( ..., SHA256 ) using the
 *   test private key in tests/tools/.keys/, the key whose public half the site
 *   trusts. The plugin's real verification path runs; nothing is stubbed.
 *
 * It is inert unless AFRIEX_DEV_MOCK_API is true AND the site's environment
 * type is 'local' or 'development'. Both, so that copying wp-config.php to a
 * production-typed site cannot turn a store's payments into make-believe.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- A mu-plugin is
// one file. The activation guard has to be a plain function so a test can call
// it while the mock is off, which is exactly when the class does not exist.

/**
 * Whether the mock should run, given the two facts it depends on.
 *
 * Split from the live check so a test can ask the question for combinations
 * that are not this site's, without toggling a constant that would need the
 * container restarted to take effect.
 *
 * @param bool   $constant_enabled Value of AFRIEX_DEV_MOCK_API; false when it is not defined.
 * @param string $environment      Result of wp_get_environment_type().
 */
function afriex_mock_api_should_activate( bool $constant_enabled, string $environment ): bool {
	return $constant_enabled && in_array( $environment, array( 'local', 'development' ), true );
}

/**
 * Whether the mock is running on this site.
 */
function afriex_mock_api_is_active(): bool {
	return afriex_mock_api_should_activate(
		defined( 'AFRIEX_DEV_MOCK_API' ) && AFRIEX_DEV_MOCK_API,
		function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ''
	);
}

if ( ! afriex_mock_api_is_active() ) {
	return;
}

/**
 * A local stand-in for the Afriex Business API.
 *
 * Fidelity is deliberate where the plugin branches on it: the error envelope
 * {code, error, details:{errorMessage, friendlyMessage, data}}, the zero-based
 * {page, total, data} list envelope, minor-unit session amounts, major-unit
 * decimal-string transaction amounts, and the documented status and channel
 * enums. Where Afriex's behaviour is not documented (section 16 of the plan),
 * the mock takes the plan's assumption and says so in a comment.
 */
class Afriex_Mock_Api {

	/**
	 * One non-autoloaded option holds the whole fake account.
	 */
	public const OPTION = 'afriex_mock_api_store';

	/**
	 * Namespace of the control and inspection routes.
	 */
	public const REST_NAMESPACE = 'afriex-mock/v1';

	/**
	 * Default webhook target: the site as seen from inside the container.
	 * Host 'localhost' is what the tunnel guard allows through.
	 */
	public const DEFAULT_WEBHOOK_URL = 'http://localhost/wp-json/afriex/v1/webhook';

	/**
	 * How many intercepted calls and deliveries are remembered for inspection.
	 */
	private const MAX_LOG = 50;

	/**
	 * Channels the mock treats as supported per currency, used to intersect a
	 * session's requested channels exactly as the real API documents. Afriex
	 * has not published the real matrix (plan section 16, question 4), so this
	 * follows the supported-currencies page and is editable at runtime through
	 * POST /settings.
	 */
	private const DEFAULT_CHANNELS = array(
		'NGN' => array( 'VIRTUAL_BANK_ACCOUNT' ),
		'GHS' => array( 'MOBILE_MONEY', 'VIRTUAL_BANK_ACCOUNT' ),
		'KES' => array( 'MOBILE_MONEY' ),
		'USD' => array( 'CARD', 'VIRTUAL_BANK_ACCOUNT' ),
		'EUR' => array( 'CARD', 'VIRTUAL_BANK_ACCOUNT' ),
		'GBP' => array( 'CARD', 'VIRTUAL_BANK_ACCOUNT' ),
		'UGX' => array( 'MOBILE_MONEY' ),
	);

	/**
	 * Every channel a checkout session may ask for.
	 */
	private const SESSION_CHANNELS = array( 'VIRTUAL_BANK_ACCOUNT', 'MOBILE_MONEY', 'CARD' );

	/**
	 * Currencies the virtual-account endpoint accepts (OpenAPI enum).
	 */
	private const VA_CURRENCIES = array( 'USD', 'NGN', 'GBP', 'EUR' );

	/**
	 * Query argument that opens the fake hosted payment page.
	 */
	public const PAGE_QUERY_VAR = 'afriex-mock-pay';

	/**
	 * Cron hook that flushes deliveries the page deferred.
	 */
	public const DUE_HOOK = 'afriex_mock_api_run_due';

	/**
	 * Longest "pay in N seconds" or webhook delay the page will accept, so a
	 * mistyped field cannot leave a job in the queue for a week.
	 */
	private const MAX_DELAY = 900;

	/**
	 * Register the interception and the control routes.
	 */
	public static function init(): void {
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// The fake hosted payment page (plan 15). 'init' rather than
		// 'template_redirect': the page answers without a query it has no use
		// for, and a POST to it must be handled before anything sends output.
		add_action( 'init', array( __CLASS__, 'maybe_render_checkout_page' ), 20 );

		// Whatever the page deferred: "success after N seconds", and any
		// webhook given a delay. Real time has to pass, so cron is the honest
		// trigger; POST /tick runs the same queue for a test that will not
		// wait two minutes for WP-Cron to be visited.
		add_action( self::DUE_HOOK, array( __CLASS__, 'run_due' ) );

		// The plugin refuses a checkoutUrl that is not on afriex.com (plan
		// 5.1 step 7). The mock's hosted page is this site, so allow this
		// host — only ever here, in a file that never ships.
		add_filter( 'afriex_wc_checkout_url_hosts', array( __CLASS__, 'allow_local_checkout_host' ) );

		// Afriex refuses a redirectUrl that is not https, and the plugin
		// hides Afriex Checkout on a store whose return URL would not be
		// (plan 4.2, 5.1). This dev site is plain http, so the return base is
		// given the https scheme here — only ever here, in a file that never
		// ships — so that the method can be exercised at all locally.
		add_filter( 'afriex_wc_checkout_return_url_base', array( __CLASS__, 'https_return_base' ) );

		// The https checkoutUrl above cannot be opened on this TLS-less site,
		// so the redirect the shopper's browser follows is sent over http.
		add_filter( 'woocommerce_payment_successful_result', array( __CLASS__, 'browser_checkout_result' ), 20, 2 );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( __CLASS__, 'browser_checkout_block_result' ), 1000, 2 );
	}

	/**
	 * Pretend this development site is served over https.
	 *
	 * @param mixed $base Return URL base.
	 */
	public static function https_return_base( $base ): string {
		return set_url_scheme( (string) $base, 'https' );
	}

	/**
	 * Add this site's host to the checkout-URL allow-list.
	 *
	 * @param array $hosts Allowed hosts.
	 */
	public static function allow_local_checkout_host( $hosts ): array {
		$hosts = is_array( $hosts ) ? $hosts : array();
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $host ) {
			$hosts[] = $host;
		}

		return array_values( array_unique( $hosts ) );
	}

	// -----------------------------------------------------------------
	// Interception
	// -----------------------------------------------------------------

	/**
	 * Answer an outgoing HTTP request aimed at Afriex.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value; false until something answers.
	 * @param array                $args wp_remote_request() arguments.
	 * @param string               $url  Request URL.
	 * @return false|array|WP_Error
	 */
	public static function intercept( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}

		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

		if ( ! self::is_afriex_host( $host ) ) {
			return $pre;
		}

		$path      = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$raw_query = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
		$query     = array();

		if ( '' !== $raw_query ) {
			parse_str( $raw_query, $query );
		}

		$method = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' );
		$body   = array();

		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$body = $args['body'];
		} elseif ( isset( $args['body'] ) && is_string( $args['body'] ) && '' !== $args['body'] ) {
			$decoded = json_decode( $args['body'], true );
			$body    = is_array( $decoded ) ? $decoded : array();
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] )
			? array_change_key_case( $args['headers'], CASE_LOWER )
			: array();

		// Everything after the /api/v1 prefix, without a trailing slash.
		$route = (string) preg_replace( '#^/api/v\d+#', '', $path );
		$route = rtrim( $route, '/' );
		$route = '' === $route ? '/' : $route;

		// The caller's own timeout, so a delayed answer can time out here the
		// way it would on a socket. WP_Http's default is 5 seconds.
		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 5.0;

		$response = self::dispatch( $method, $route, $query, $body, $headers, $host, $timeout );

		self::log_request( $host, $method, $route, $response );

		return $response;
	}

	/**
	 * Whether a host is afriex.com or one of its subdomains.
	 *
	 * Repeated here rather than calling Afriex_Api_Client: mu-plugins load
	 * before the plugin, and this file must not depend on it.
	 *
	 * @param string $host Host name.
	 */
	public static function is_afriex_host( string $host ): bool {
		$host = strtolower( rtrim( trim( $host ), '.' ) );

		return 'afriex.com' === $host || '.afriex.com' === substr( $host, -11 );
	}

	/**
	 * Route one intercepted call to its handler.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   Path after /api/v1.
	 * @param array  $query   Query arguments.
	 * @param array  $body    Decoded JSON body.
	 * @param array  $headers Lower-cased request headers.
	 * @param string $host    Request host, which names the environment.
	 * @param float  $timeout The caller's HTTP timeout, in seconds.
	 * @return array|WP_Error
	 */
	private static function dispatch( string $method, string $route, array $query, array $body, array $headers, string $host, float $timeout = 0.0 ) {
		$settings = self::settings();

		if ( ! empty( $settings['require_api_key'] ) && '' === trim( (string) ( $headers['x-api-key'] ?? '' ) ) ) {
			return self::error( 401, 'AUTHENTICATION_ERROR', 'Missing or empty x-api-key header.' );
		}

		$environment = 0 === strpos( $host, 'sandbox.' ) ? 'sandbox' : 'production';
		$matches     = array();

		if ( 'POST' === $method && '/customer' === $route ) {
			$endpoint = 'customer_create';
		} elseif ( 'GET' === $method && '/customer' === $route ) {
			$endpoint = 'customer_list';
		} elseif ( 'POST' === $method && '/payment-method/virtual-account' === $route ) {
			$endpoint = 'virtual_account_create';
		} elseif ( 'GET' === $method && preg_match( '#^/payment-method/([^/]+)$#', $route, $matches ) ) {
			$endpoint = 'payment_method_get';
		} elseif ( 'GET' === $method && '/transaction' === $route ) {
			$endpoint = 'transaction_list';
		} elseif ( 'GET' === $method && preg_match( '#^/transaction/([^/]+)$#', $route, $matches ) ) {
			$endpoint = 'transaction_get';
		} elseif ( 'POST' === $method && '/checkout-session' === $route ) {
			$endpoint = 'checkout_session';
		} else {
			return self::error( 404, 'NOT_FOUND', 'The Afriex mock does not implement ' . $method . ' ' . $route . '.' );
		}

		$forced = self::take_failure( $endpoint );

		// 'slow' is not a failure: the call succeeds, late. It is how the
		// plan's "slow response" case is produced, and the only way to see
		// what the plugin does when a create takes longer than the shopper
		// will wait. The sleep is bounded by MAX_DELAY like every other
		// delay the mock honours.
		if ( 'slow' === $forced ) {
			$seconds = min( self::MAX_DELAY, max( 0, (int) self::settings( 'slow_seconds' ) ) );

			// A slow answer has to be able to be *too* slow. Sleeping past
			// the caller's timeout and then answering anyway is not what a
			// socket does: cURL gives up at the timeout and WP_Http hands
			// back a WP_Error. Without this the plugin's own 8-second
			// re-fetch timeout and its 20-second webhook budget could never
			// be reached from here, because pre_http_request runs instead of
			// the transport and nothing else is watching the clock.
			if ( $timeout > 0 && $seconds >= $timeout ) {
				usleep( (int) round( $timeout * 1000000 ) );

				return new WP_Error(
					'http_request_failed',
					sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received', (int) round( $timeout * 1000 ) )
				);
			}

			if ( $seconds > 0 ) {
				sleep( $seconds );
			}

			$forced = '';
		}

		// 'created_then_unavailable' is handled inside the session handler:
		// a 503 means the request may already have taken effect (plan 3), and
		// that is precisely the case worth reproducing.
		if ( '' !== $forced && 'created_then_unavailable' !== $forced ) {
			$response = self::forced_response( $forced );

			if ( null !== $response ) {
				return $response;
			}
		}

		switch ( $endpoint ) {
			case 'customer_create':
				return self::handle_customer_create( $body );

			case 'customer_list':
				return self::handle_customer_list( $query );

			case 'virtual_account_create':
				return self::handle_virtual_account_create( $body, $forced );

			case 'payment_method_get':
				return self::handle_payment_method_get( rawurldecode( $matches[1] ) );

			case 'transaction_list':
				return self::handle_transaction_list( $query );

			case 'transaction_get':
				return self::handle_transaction_get( rawurldecode( $matches[1] ) );

			default:
				return self::handle_checkout_session( $body, $environment, 'created_then_unavailable' === $forced );
		}
	}

	// -----------------------------------------------------------------
	// Endpoint handlers
	// -----------------------------------------------------------------

	/**
	 * POST /customer.
	 *
	 * @param array $body Request body.
	 * @return array
	 */
	private static function handle_customer_create( array $body ): array {
		foreach ( array( 'fullName', 'email', 'phone', 'countryCode' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) || '' === trim( $body[ $field ] ) ) {
				return self::error( 400, 'VALIDATION_ERROR', sprintf( '"%s" is required.', $field ) );
			}
		}

		if ( ! is_email( $body['email'] ) ) {
			return self::error( 400, 'VALIDATION_ERROR', '"email" must be a valid email address.' );
		}

		if ( ! self::is_e164( $body['phone'] ) ) {
			return self::error( 400, 'VALIDATION_ERROR', '"phone" must be in E.164 format, for example +2348192837465.' );
		}

		$store = self::store();
		$email = strtolower( trim( $body['email'] ) );
		$phone = trim( $body['phone'] );

		foreach ( $store['customers'] as $customer ) {
			if ( strtolower( (string) $customer['email'] ) === $email ) {
				// F25: the existing id comes back in details.data so the
				// caller can adopt it without a follow-up look-up.
				return self::error(
					409,
					'EMAIL_ALREADY_EXISTS',
					'Email already exists',
					array( 'customerId' => $customer['customerId'] )
				);
			}
		}

		foreach ( $store['customers'] as $customer ) {
			if ( (string) $customer['phone'] === $phone ) {
				return self::error(
					409,
					'PHONE_NUMBER_ALREADY_EXISTS',
					'Phone number already exists',
					array( 'customerId' => $customer['customerId'] )
				);
			}
		}

		$customer = array(
			'customerId'  => self::new_id(),
			'fullName'    => trim( $body['fullName'] ),
			'email'       => trim( $body['email'] ),
			'phone'       => $phone,
			'countryCode' => strtoupper( trim( $body['countryCode'] ) ),
			'createdAt'   => self::now(),
		);

		if ( isset( $body['meta'] ) && is_array( $body['meta'] ) ) {
			$customer['meta'] = $body['meta'];
		}

		$store['customers'][ $customer['customerId'] ] = $customer;
		self::save( $store );

		return self::response( 201, array( 'data' => $customer ) );
	}

	/**
	 * GET /customer.
	 *
	 * @param array $query Query arguments.
	 * @return array
	 */
	private static function handle_customer_list( array $query ): array {
		$store = self::store();
		$rows  = array_values( $store['customers'] );
		$email = strtolower( trim( (string) ( $query['email'] ?? '' ) ) );
		$phone = trim( (string) ( $query['phone'] ?? '' ) );

		if ( '' !== $email ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $email ) {
						return strtolower( (string) $row['email'] ) === $email;
					}
				)
			);
		}

		if ( '' !== $phone ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $phone ) {
						return (string) $row['phone'] === $phone;
					}
				)
			);
		}

		return self::paginate( $rows, $query );
	}

	/**
	 * POST /payment-method/virtual-account.
	 *
	 * Three modes shape the 201 rather than replacing it, because F48 is about
	 * a *successful* create whose payload cannot be paid into:
	 * 'no_account_number' drops accountNumber, 'inactive' returns the account
	 * with status 'pending', and 'no_expiry' returns a dynamic account that
	 * carries no expiresInMinutes at all. The last is F33's case — Afriex says
	 * nothing about when the account closes, so the store's payment window is
	 * the whole answer — and it is the one thing this endpoint could not
	 * otherwise produce, because an account created with an amount always gets
	 * a window here. All three are answers the real API can give, and none is
	 * an error the client would otherwise notice.
	 *
	 * @param array  $body   Request body.
	 * @param string $forced Forced mode consumed for this call, or ''.
	 * @return array
	 */
	private static function handle_virtual_account_create( array $body, string $forced = '' ): array {
		$currency = strtoupper( trim( (string) ( $body['currency'] ?? '' ) ) );

		if ( ! in_array( $currency, self::VA_CURRENCIES, true ) ) {
			return self::error( 400, 'VALIDATION_ERROR', '"currency" must be one of ' . implode( ', ', self::VA_CURRENCIES ) . '.' );
		}

		if ( isset( $body['amount'] ) && isset( $body['label'] ) ) {
			return self::error( 400, 'VALIDATION_ERROR', '"amount" and "label" are mutually exclusive.' );
		}

		$amount = null;

		if ( isset( $body['amount'] ) ) {
			if ( ! is_numeric( $body['amount'] ) ) {
				return self::error( 400, 'VALIDATION_ERROR', '"amount" must be a number.' );
			}

			$amount = (float) $body['amount'];

			// The real ceiling, so F40's "NGN only, up to 5,000,000" rule can
			// be exercised from both sides.
			if ( $amount < 0 || $amount > 5000000 ) {
				return self::error( 400, 'VALIDATION_ERROR', '"amount" must be between 0 and 5000000.' );
			}
		}

		$store       = self::store();
		$customer_id = trim( (string) ( $body['customerId'] ?? '' ) );
		$name        = 'Afriex Mock Store';

		if ( '' !== $customer_id && isset( $store['customers'][ $customer_id ]['fullName'] ) ) {
			$name = (string) $store['customers'][ $customer_id ]['fullName'];
		}

		$id      = self::new_id();
		$account = array(
			'paymentMethodId' => $id,
			'channel'         => 'VIRTUAL_BANK_ACCOUNT',
			'customerId'      => $customer_id,
			'institution'     => array( 'institutionName' => 'MOCK BANK' ),
			'accountName'     => $name,
			'accountNumber'   => (string) wp_rand( 1000000000, 9999999999 ),
			'countryCode'     => self::country_for_currency( $currency ),
			'currency'        => $currency,
			'status'          => 'active',
			'capabilities'    => array( 'DEPOSIT' ),
			'reference'       => 'MOCK-' . strtoupper( substr( $id, 0, 8 ) ),
			'createdAt'       => self::now(),
		);

		if ( null !== $amount ) {
			// A dynamic account: the response carries the window the plugin
			// stores as _afriex_expires_at (F32, F33).
			$account['amount']           = $amount;
			$account['expiresInMinutes'] = (int) self::settings( 'va_expires_in_minutes' );
		}

		$store['payment_methods'][ $id ] = $account;
		self::save( $store );

		if ( 'no_account_number' === $forced ) {
			unset( $account['accountNumber'] );
		} elseif ( 'inactive' === $forced ) {
			$account['status'] = 'pending';
		} elseif ( 'no_expiry' === $forced ) {
			// Both the response and the stored account, so a later
			// GET /payment-method/{id} tells the same story as the create did.
			unset( $account['expiresInMinutes'] );
			unset( $store['payment_methods'][ $id ]['expiresInMinutes'] );
			self::save( $store );
		}

		return self::response( 201, array( 'data' => $account ) );
	}

	/**
	 * GET /payment-method/{id}.
	 *
	 * @param string $id Payment method id.
	 * @return array
	 */
	private static function handle_payment_method_get( string $id ): array {
		$store = self::store();

		if ( ! isset( $store['payment_methods'][ $id ] ) ) {
			return self::error( 404, 'BUSINESS_PAYMENT_METHOD_NOT_FOUND', 'Business payment method not found' );
		}

		return self::response( 200, array( 'data' => $store['payment_methods'][ $id ] ) );
	}

	/**
	 * GET /transaction/{id}.
	 *
	 * @param string $id Transaction id.
	 * @return array
	 */
	private static function handle_transaction_get( string $id ): array {
		$store = self::store();

		if ( ! isset( $store['transactions'][ $id ] ) ) {
			return self::error( 404, 'BUSINESS_TRANSACTION_NOT_FOUND', 'Business transaction not found' );
		}

		return self::response( 200, array( 'data' => $store['transactions'][ $id ] ) );
	}

	/**
	 * GET /transaction.
	 *
	 * Filters are comma-separated lists, as the OpenAPI describes, and an
	 * unknown filter narrows nothing — the plugin must not depend on that
	 * either way (plan section 16, question 8).
	 *
	 * @param array $query Query arguments.
	 * @return array
	 */
	private static function handle_transaction_list( array $query ): array {
		$store = self::store();
		$rows  = array_values( $store['transactions'] );

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['createdAt'] ?? '' ), (string) ( $a['createdAt'] ?? '' ) );
			}
		);

		$reference = trim( (string) ( $query['reference'] ?? '' ) );

		if ( '' !== $reference ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $reference ) {
						return (string) ( $row['merchantReference'] ?? '' ) === $reference
							|| (string) ( $row['meta']['reference'] ?? '' ) === $reference;
					}
				)
			);
		}

		$transaction_id = trim( (string) ( $query['transactionId'] ?? '' ) );

		if ( '' !== $transaction_id ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $transaction_id ) {
						return (string) ( $row['transactionId'] ?? '' ) === $transaction_id;
					}
				)
			);
		}

		foreach ( array( 'type', 'status', 'channel' ) as $field ) {
			$wanted = self::csv( (string) ( $query[ $field ] ?? '' ) );

			if ( empty( $wanted ) ) {
				continue;
			}

			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $field, $wanted ) {
						return in_array( strtoupper( (string) ( $row[ $field ] ?? '' ) ), $wanted, true );
					}
				)
			);
		}

		$currencies = self::csv( (string) ( $query['currency'] ?? '' ) );

		if ( ! empty( $currencies ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $currencies ) {
						return in_array( strtoupper( (string) ( $row['sourceCurrency'] ?? '' ) ), $currencies, true )
							|| in_array( strtoupper( (string) ( $row['destinationCurrency'] ?? '' ) ), $currencies, true );
					}
				)
			);
		}

		foreach ( array(
			'fromDate' => '>=',
			'toDate'   => '<=',
		) as $field => $operator ) {
			$value = trim( (string) ( $query[ $field ] ?? '' ) );
			$stamp = '' === $value ? false : strtotime( $value );

			if ( false === $stamp ) {
				continue;
			}

			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $stamp, $operator ) {
						$created = strtotime( (string) ( $row['createdAt'] ?? '' ) );

						if ( false === $created ) {
							return false;
						}

						return '>=' === $operator ? $created >= $stamp : $created <= $stamp;
					}
				)
			);
		}

		return self::paginate( $rows, $query );
	}

	/**
	 * POST /checkout-session.
	 *
	 * The documented rules are enforced here so the plugin's request is
	 * checked by something other than itself: minor-unit integer amount of at
	 * least 100, upper-case currency, a non-empty unique reference, an HTTPS
	 * redirect URL, an E.164 customer phone, at least one known channel, and
	 * metadata of at most 50 string values.
	 *
	 * @param array  $body                Request body.
	 * @param string $environment         Environment the call was aimed at.
	 * @param bool   $fail_after_creating Record the session, then answer 503.
	 * @return array
	 */
	private static function handle_checkout_session( array $body, string $environment, bool $fail_after_creating ): array {
		if ( ! isset( $body['amount'] ) || ! is_int( $body['amount'] ) ) {
			return self::validation_error( '"amount" must be an integer in minor units.' );
		}

		if ( $body['amount'] < 100 ) {
			return self::validation_error( '"amount" must be at least 100 minor units (one major unit).' );
		}

		$currency = (string) ( $body['currency'] ?? '' );

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return self::validation_error( '"currency" must be an upper-case ISO 4217 code.' );
		}

		$reference = isset( $body['merchantReference'] ) && is_string( $body['merchantReference'] )
			? trim( $body['merchantReference'] )
			: '';

		if ( '' === $reference ) {
			return self::validation_error( '"merchantReference" is required.' );
		}

		$redirect_url = (string) ( $body['redirectUrl'] ?? '' );

		if ( 'https' !== strtolower( (string) wp_parse_url( $redirect_url, PHP_URL_SCHEME ) ) ) {
			return self::validation_error( '"redirectUrl" must be an https URL.' );
		}

		$customer = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();

		foreach ( array( 'name', 'email', 'phone', 'countryCode' ) as $field ) {
			if ( ! isset( $customer[ $field ] ) || ! is_string( $customer[ $field ] ) || '' === trim( $customer[ $field ] ) ) {
				return self::validation_error( sprintf( '"customer.%s" is required.', $field ) );
			}
		}

		if ( ! is_email( $customer['email'] ) ) {
			return self::validation_error( '"customer.email" must be a valid email address.' );
		}

		if ( ! self::is_e164( $customer['phone'] ) ) {
			return self::validation_error( '"customer.phone" must be in E.164 format, for example +2348192837465.' );
		}

		if ( ! preg_match( '/^[A-Z]{2}$/', strtoupper( $customer['countryCode'] ) ) ) {
			return self::validation_error( '"customer.countryCode" must be an ISO 3166-1 alpha-2 code.' );
		}

		$requested = isset( $body['channels'] ) && is_array( $body['channels'] ) ? array_values( $body['channels'] ) : array();

		if ( empty( $requested ) ) {
			return self::validation_error( '"channels" must list at least one channel.' );
		}

		foreach ( $requested as $channel ) {
			if ( ! is_string( $channel ) || ! in_array( $channel, self::SESSION_CHANNELS, true ) ) {
				return self::validation_error( '"channels" may only contain ' . implode( ', ', self::SESSION_CHANNELS ) . '.' );
			}
		}

		$metadata = array();

		if ( isset( $body['metadata'] ) ) {
			if ( ! is_array( $body['metadata'] ) ) {
				return self::validation_error( '"metadata" must be an object of string values.' );
			}

			if ( count( $body['metadata'] ) > 50 ) {
				return self::validation_error( '"metadata" may hold at most 50 entries.' );
			}

			foreach ( $body['metadata'] as $key => $value ) {
				if ( ! is_string( $value ) ) {
					return self::validation_error( sprintf( '"metadata.%s" must be a string.', $key ) );
				}

				if ( strlen( (string) $key ) > 128 ) {
					return self::validation_error( 'A "metadata" key may be at most 128 characters.' );
				}

				if ( strlen( $value ) > 1024 ) {
					return self::validation_error( sprintf( '"metadata.%s" may be at most 1024 characters.', $key ) );
				}

				$metadata[ (string) $key ] = $value;
			}
		}

		// The cap rule: the request's channels in request order, minus the
		// ones this currency cannot collect on. Empty means 422.
		$supported = self::supported_channels( $currency );
		$offered   = array_values( array_intersect( $requested, $supported ) );

		if ( empty( $offered ) ) {
			return self::unsupported_channels_error( $currency );
		}

		$store   = self::store();
		$ttl     = max( 1, (int) self::settings( 'session_ttl_minutes' ) );
		$session = array(
			'sessionId'         => self::new_id(),
			'merchantReference' => $reference,
			'amount'            => (int) $body['amount'],
			'currency'          => $currency,
			'channels'          => $offered,
			'requestedChannels' => $requested,
			'redirectUrl'       => $redirect_url,
			'customer'          => array(
				'name'        => trim( $customer['name'] ),
				'email'       => trim( $customer['email'] ),
				'phone'       => trim( $customer['phone'] ),
				'countryCode' => strtoupper( trim( $customer['countryCode'] ) ),
			),
			'metadata'          => $metadata,
			'environment'       => $environment,
			'createdAt'         => self::now(),
			'expiresAt'         => self::now( $ttl * MINUTE_IN_SECONDS ),
			'checkoutUrl'       => self::checkout_page_url( $reference ),
		);

		$store['sessions'][ $reference ] = $session;
		self::save( $store );

		if ( $fail_after_creating ) {
			// The session exists; the merchant never learns its URL. This is
			// what the plan means by "503 ... may already have taken effect".
			return self::error( 503, 'EXTERNAL_REQUEST_ERROR', 'Upstream timeout after the session was created.', array(), '', array( 'retry-after' => '1' ) );
		}

		return self::response(
			201,
			array(
				'data' => array(
					'checkoutUrl' => $session['checkoutUrl'],
					'channels'    => $offered,
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// Control and inspection routes
	// -----------------------------------------------------------------

	/**
	 * Register the dev-only control routes.
	 */
	public static function register_routes(): void {
		$permission = array( __CLASS__, 'local_request_only' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_state' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_reset' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_settings' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/failure',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_failure' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transaction',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_create_transaction' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transaction/(?P<id>[A-Za-z0-9_\-]+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_update_transaction' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout-session-event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_checkout_session_event' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/payment-method-event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_payment_method_event' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/tick',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_tick' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_pay' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Control routes answer only to this machine.
	 *
	 * The tunnel guard already 404s a foreign host before WordPress routes the
	 * request, but a mu-plugin that can forge a signed webhook deserves its
	 * own lock rather than trusting another file to stay in place.
	 *
	 * @return true|WP_Error
	 */
	public static function local_request_only() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';

		$allowed = array( 'localhost', 'localhost:80', 'localhost:8888', '127.0.0.1', '127.0.0.1:80', '127.0.0.1:8888' );

		if ( in_array( $host, $allowed, true ) ) {
			return true;
		}

		// The same answer an unknown route gives, so nothing is confirmed.
		return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
	}

	/**
	 * GET /state: the whole fake account, or one section of it.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_state( WP_REST_Request $request ): WP_REST_Response {
		$store   = self::store();
		$section = (string) $request->get_param( 'section' );

		if ( '' !== $section && isset( $store[ $section ] ) ) {
			return new WP_REST_Response( array( $section => $store[ $section ] ), 200 );
		}

		$store['counts'] = array(
			'customers'       => count( $store['customers'] ),
			'payment_methods' => count( $store['payment_methods'] ),
			'transactions'    => count( $store['transactions'] ),
			'sessions'        => count( $store['sessions'] ),
		);

		return new WP_REST_Response( $store, 200 );
	}

	/**
	 * POST /reset: forget every fake customer, account, transaction and session.
	 */
	public static function route_reset(): WP_REST_Response {
		delete_option( self::OPTION );

		return new WP_REST_Response( array( 'reset' => true ), 200 );
	}

	/**
	 * POST /settings: change how the mock behaves.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_settings( WP_REST_Request $request ): WP_REST_Response {
		$store    = self::store();
		$settings = $store['settings'];

		foreach ( array( 'va_expires_in_minutes', 'session_ttl_minutes' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$settings[ $key ] = max( 1, (int) $request->get_param( $key ) );
			}
		}

		foreach ( array( 'slow_seconds', 'webhook_delay_seconds' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$settings[ $key ] = min( self::MAX_DELAY, max( 0, (int) $request->get_param( $key ) ) );
			}
		}

		if ( null !== $request->get_param( 'lose_webhook' ) ) {
			$settings['lose_webhook'] = self::truthy( $request->get_param( 'lose_webhook' ) );
		}

		if ( null !== $request->get_param( 'webhook_order' ) ) {
			$settings['webhook_order'] = 'session_first' === sanitize_key( (string) $request->get_param( 'webhook_order' ) )
				? 'session_first'
				: 'transaction_first';
		}

		if ( null !== $request->get_param( 'redirect_url_scheme' ) ) {
			$scheme                          = strtolower( sanitize_key( (string) $request->get_param( 'redirect_url_scheme' ) ) );
			$settings['redirect_url_scheme'] = in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : 'as-is';
		}

		if ( null !== $request->get_param( 'webhook_url' ) ) {
			$settings['webhook_url'] = esc_url_raw( (string) $request->get_param( 'webhook_url' ) );
		}

		if ( null !== $request->get_param( 'require_api_key' ) ) {
			$settings['require_api_key'] = (bool) $request->get_param( 'require_api_key' );
		}

		if ( null !== $request->get_param( 'unsupported_channels_code' ) ) {
			$settings['unsupported_channels_code'] = sanitize_text_field( (string) $request->get_param( 'unsupported_channels_code' ) );
		}

		if ( null !== $request->get_param( 'checkout_url_scheme' ) ) {
			$scheme                          = strtolower( sanitize_key( (string) $request->get_param( 'checkout_url_scheme' ) ) );
			$settings['checkout_url_scheme'] = 'http' === $scheme ? 'http' : 'https';
		}

		$channels = $request->get_param( 'channels' );

		if ( is_array( $channels ) ) {
			$clean = array();

			foreach ( $channels as $currency => $list ) {
				$currency = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $currency ), 0, 3 ) );

				if ( 3 !== strlen( $currency ) || ! is_array( $list ) ) {
					continue;
				}

				$clean[ $currency ] = array_values( array_intersect( array_map( 'strval', $list ), self::SESSION_CHANNELS ) );
			}

			$settings['channels'] = $clean;
		}

		$store['settings'] = $settings;
		self::save( $store );

		return new WP_REST_Response( array( 'settings' => $settings ), 200 );
	}

	/**
	 * POST /failure: make the next call (or calls) to one endpoint fail.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_failure( WP_REST_Request $request ): WP_REST_Response {
		$endpoint = sanitize_key( (string) $request->get_param( 'endpoint' ) );
		$mode     = sanitize_key( (string) $request->get_param( 'mode' ) );
		$times    = null === $request->get_param( 'times' ) ? 0 : max( 0, (int) $request->get_param( 'times' ) );

		$store = self::store();

		if ( '' === $mode || 'none' === $mode ) {
			unset( $store['failures'][ $endpoint ] );
		} else {
			$store['failures'][ $endpoint ] = array(
				'mode'  => $mode,
				'times' => $times,
			);
		}

		self::save( $store );

		return new WP_REST_Response( array( 'failures' => $store['failures'] ), 200 );
	}

	/**
	 * POST /transaction: put a transaction in the fake store, and by default
	 * deliver the signed webhook that Afriex would have sent for it.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_create_transaction( WP_REST_Request $request ): WP_REST_Response {
		$reference = (string) ( $request->get_param( 'merchantReference' ) ?? $request->get_param( 'reference' ) ?? '' );
		$amount    = $request->get_param( 'amount' );

		$source_currency      = strtoupper( (string) ( $request->get_param( 'sourceCurrency' ) ?? $request->get_param( 'currency' ) ?? 'NGN' ) );
		$destination_currency = strtoupper( (string) ( $request->get_param( 'destinationCurrency' ) ?? $source_currency ) );
		$source_amount        = (string) ( $request->get_param( 'sourceAmount' ) ?? $amount ?? '0' );
		$destination_amount   = (string) ( $request->get_param( 'destinationAmount' ) ?? $amount ?? $source_amount );

		$id = (string) ( $request->get_param( 'transactionId' ) ?? '' );
		$id = '' === $id ? self::new_id() : $id;

		$now = self::now();

		$transaction = array(
			'transactionId'       => $id,
			'type'                => strtoupper( (string) ( $request->get_param( 'type' ) ?? 'DEPOSIT' ) ),
			'channel'             => strtoupper( (string) ( $request->get_param( 'channel' ) ?? 'VIRTUAL_BANK_ACCOUNT' ) ),
			'status'              => strtoupper( (string) ( $request->get_param( 'status' ) ?? 'SUCCESS' ) ),
			'sourceAmount'        => $source_amount,
			'sourceCurrency'      => $source_currency,
			'destinationAmount'   => $destination_amount,
			'destinationCurrency' => $destination_currency,
			'sourceId'            => (string) ( $request->get_param( 'sourceId' ) ?? '' ),
			'destinationId'       => (string) ( $request->get_param( 'destinationId' ) ?? '' ),
			'customerId'          => (string) ( $request->get_param( 'customerId' ) ?? '' ),
			'merchantReference'   => $reference,
			'meta'                => array(
				'reference'      => $reference,
				'idempotencyKey' => $reference,
			),
			'createdAt'           => (string) ( $request->get_param( 'createdAt' ) ?? $now ),
			'updatedAt'           => (string) ( $request->get_param( 'updatedAt' ) ?? $now ),
		);

		if ( null !== $request->get_param( 'fee' ) ) {
			$transaction['fee'] = (string) $request->get_param( 'fee' );
		}

		$meta = $request->get_param( 'meta' );

		if ( is_array( $meta ) ) {
			$transaction['meta'] = array_merge( $transaction['meta'], $meta );
		}

		$failure_reason = $request->get_param( 'failureReason' );

		if ( is_array( $failure_reason ) ) {
			$transaction['meta']['failureReason'] = $failure_reason;
		}

		$store                        = self::store();
		$store['transactions'][ $id ] = $transaction;
		self::save( $store );

		$deliver  = (string) ( $request->get_param( 'deliver' ) ?? 'created' );
		$delivery = null;

		if ( 'none' !== $deliver && 'false' !== $deliver && '0' !== $deliver ) {
			$event    = 'updated' === $deliver ? 'TRANSACTION.UPDATED' : 'TRANSACTION.CREATED';
			$delivery = self::deliver_event( $event, $transaction );
		}

		return new WP_REST_Response(
			array(
				'transaction' => $transaction,
				'delivery'    => $delivery,
			),
			201
		);
	}

	/**
	 * POST /transaction/{id}/status: move a transaction on and re-deliver.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_update_transaction( WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$store = self::store();

		if ( ! isset( $store['transactions'][ $id ] ) ) {
			return new WP_Error( 'afriex_mock_unknown_transaction', 'No mock transaction ' . $id, array( 'status' => 404 ) );
		}

		$transaction           = $store['transactions'][ $id ];
		$transaction['status'] = strtoupper( (string) $request->get_param( 'status' ) );

		$transaction['updatedAt'] = (string) ( $request->get_param( 'updatedAt' ) ?? self::now() );

		$failure_reason = $request->get_param( 'failureReason' );

		if ( is_array( $failure_reason ) ) {
			$transaction['meta']['failureReason'] = $failure_reason;
		}

		foreach ( array( 'sourceAmount', 'destinationAmount', 'fee', 'destinationId', 'sourceId' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$transaction[ $field ] = (string) $request->get_param( $field );
			}
		}

		$store['transactions'][ $id ] = $transaction;
		self::save( $store );

		$deliver  = $request->get_param( 'deliver' );
		$delivery = null;

		if ( null === $deliver || ( 'none' !== $deliver && 'false' !== $deliver && '0' !== $deliver && false !== $deliver ) ) {
			$delivery = self::deliver_event( 'TRANSACTION.UPDATED', $transaction );
		}

		return new WP_REST_Response(
			array(
				'transaction' => $transaction,
				'delivery'    => $delivery,
			),
			200
		);
	}

	/**
	 * POST /checkout-session-event: deliver CHECKOUT_SESSION.CREATED.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_checkout_session_event( WP_REST_Request $request ): WP_REST_Response {
		$reference = (string) ( $request->get_param( 'merchantReference' ) ?? $request->get_param( 'reference' ) ?? '' );
		$store     = self::store();
		$session   = isset( $store['sessions'][ $reference ] ) ? $store['sessions'][ $reference ] : array();

		$data = array(
			'sessionId'         => (string) ( $request->get_param( 'sessionId' ) ?? ( $session['sessionId'] ?? self::new_id() ) ),
			'merchantReference' => $reference,
			'amount'            => (int) ( $request->get_param( 'amount' ) ?? ( $session['amount'] ?? 0 ) ),
			'currency'          => strtoupper( (string) ( $request->get_param( 'currency' ) ?? ( $session['currency'] ?? 'NGN' ) ) ),
			'expiresAt'         => (string) ( $request->get_param( 'expiresAt' ) ?? ( $session['expiresAt'] ?? self::now( 15 * MINUTE_IN_SECONDS ) ) ),
			'createdAt'         => (string) ( $session['createdAt'] ?? self::now() ),
			'metadata'          => isset( $session['metadata'] ) ? $session['metadata'] : array(),
			'customer'          => isset( $session['customer'] ) ? $session['customer'] : array(),
		);

		// Present only once the session is paid, per the event docs.
		if ( null !== $request->get_param( 'afriexTransactionId' ) ) {
			$data['afriexTransactionId'] = (string) $request->get_param( 'afriexTransactionId' );
			$data['paidAt']              = (string) ( $request->get_param( 'paidAt' ) ?? self::now() );
		}

		return new WP_REST_Response(
			array(
				'data'     => $data,
				'delivery' => self::deliver_event( 'CHECKOUT_SESSION.CREATED', $data ),
			),
			200
		);
	}

	/**
	 * POST /payment-method-event: move a virtual account's status and deliver
	 * the PAYMENT_METHOD.UPDATED that Afriex would have sent for it.
	 *
	 * An account the mock has never seen is created first, so an order made
	 * by a fixture rather than by a real process_payment() run can still have
	 * its account expire. The plugin re-fetches the account before acting on
	 * the event, so the record has to exist either way.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_payment_method_event( WP_REST_Request $request ) {
		$id = trim( (string) ( $request->get_param( 'paymentMethodId' ) ?? $request->get_param( 'id' ) ?? '' ) );

		if ( '' === $id ) {
			return new WP_Error( 'afriex_mock_missing_id', 'paymentMethodId is required', array( 'status' => 400 ) );
		}

		$store = self::store();

		if ( ! isset( $store['payment_methods'][ $id ] ) ) {
			$store['payment_methods'][ $id ] = array(
				'paymentMethodId' => $id,
				'channel'         => 'VIRTUAL_BANK_ACCOUNT',
				'customerId'      => (string) ( $request->get_param( 'customerId' ) ?? '' ),
				'institution'     => array( 'institutionName' => 'MOCK BANK' ),
				'accountName'     => 'Afriex Mock Store',
				'accountNumber'   => (string) wp_rand( 1000000000, 9999999999 ),
				'countryCode'     => 'NG',
				'currency'        => strtoupper( (string) ( $request->get_param( 'currency' ) ?? 'NGN' ) ),
				'status'          => 'active',
				'capabilities'    => array( 'DEPOSIT' ),
				'reference'       => 'MOCK-' . strtoupper( substr( $id, 0, 8 ) ),
				'createdAt'       => self::now(),
			);
		}

		$account              = $store['payment_methods'][ $id ];
		$account['status']    = strtolower( (string) ( $request->get_param( 'status' ) ?? 'expired' ) );
		$account['updatedAt'] = (string) ( $request->get_param( 'updatedAt' ) ?? self::now() );

		$store['payment_methods'][ $id ] = $account;
		self::save( $store );

		$data = array(
			'paymentMethodId' => $id,
			'channel'         => (string) $account['channel'],
			'customerId'      => (string) $account['customerId'],
			'currency'        => (string) $account['currency'],
			'status'          => (string) $account['status'],
			'updatedAt'       => (string) $account['updatedAt'],
		);

		$deliver  = $request->get_param( 'deliver' );
		$delivery = null;

		if ( null === $deliver || ( 'none' !== $deliver && 'false' !== $deliver && '0' !== $deliver && false !== $deliver ) ) {
			$delivery = self::deliver_event( 'PAYMENT_METHOD.UPDATED', $data );
		}

		return new WP_REST_Response(
			array(
				'paymentMethod' => $account,
				'delivery'      => $delivery,
			),
			200
		);
	}

	/**
	 * POST /tick: deliver whatever the fake page deferred.
	 *
	 * A test that armed "success in 20 seconds" does not want to wait twenty
	 * seconds, and WP-Cron only runs when somebody visits the site, so the
	 * queue is also drainable on demand. With force=1 everything pending is
	 * delivered now, whatever its due time.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function route_tick( WP_REST_Request $request ): WP_REST_Response {
		if ( self::truthy( $request->get_param( 'force' ) ) ) {
			self::force_due();
		}

		$done = self::run_due();

		return new WP_REST_Response(
			array(
				'ran'     => $done,
				'pending' => array_values( self::store()['pending'] ),
			),
			200
		);
	}

	/**
	 * POST /pay: press one of the fake hosted page's buttons without a browser.
	 *
	 * The page and this route call the same pay_session(), so a curl-driven
	 * test and a real click exercise one code path. It takes the same fields
	 * the form posts, and answers with the transaction and the deliveries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_pay( WP_REST_Request $request ) {
		self::run_due();

		$reference = trim( (string) ( $request->get_param( 'merchantReference' ) ?? $request->get_param( 'reference' ) ?? '' ) );
		$store     = self::store();

		if ( '' === $reference || ! isset( $store['sessions'][ $reference ] ) ) {
			return new WP_Error( 'afriex_mock_unknown_session', 'No mock checkout session for ' . $reference, array( 'status' => 404 ) );
		}

		$result = self::pay_session( $store['sessions'][ $reference ], self::submission( $request->get_params() ) );

		return new WP_REST_Response( $result, 200 );
	}

	// -----------------------------------------------------------------
	// The fake hosted payment page
	// -----------------------------------------------------------------

	/**
	 * Answer /?afriex-mock-pay={reference} when the mock owns that reference.
	 *
	 * This stands in for Afriex's hosted page: it is where the shopper is
	 * sent by process_payment(), it is what decides the payment's outcome,
	 * and it is what sends the shopper back to the store's redirectUrl. The
	 * plugin cannot tell the difference, which is the point.
	 */
	public static function maybe_render_checkout_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the query argument that selects this page; nothing is changed by a GET.
		if ( ! isset( $_GET[ self::PAGE_QUERY_VAR ] ) ) {
			return;
		}

		// Afriex's page is on Afriex's domain and could never be reached from
		// this site's other hosts. The control routes' lock is reused so the
		// tunnel cannot expose a page that mints payments.
		if ( true !== self::local_request_only() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$reference = sanitize_text_field( wp_unslash( (string) $_GET[ self::PAGE_QUERY_VAR ] ) );

		self::run_due();

		$store   = self::store();
		$session = isset( $store['sessions'][ $reference ] ) ? $store['sessions'][ $reference ] : null;

		if ( null === $session ) {
			self::send_page(
				404,
				'<h1>No such checkout session</h1><p>The mock Afriex API has no session for <code>' . esc_html( $reference ) . '</code>.</p>'
			);
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'POST' === $method ) {
			self::handle_page_submission( $session );
		}

		self::render_checkout_page( $session );
	}

	/**
	 * Take the payment the shopper chose, then send them back to the store.
	 *
	 * There is no nonce, because the page this imitates is on another origin
	 * and WordPress has never heard of the shopper who reaches it. What
	 * stands in for one: the mock is inert outside a local or development
	 * environment, the page answers only to a localhost Host header, and
	 * everything it can do is invent a fake payment for a session that this
	 * same site created a moment ago.
	 *
	 * @param array $session Session record.
	 */
	private static function handle_page_submission( array $session ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See the docblock: a hosted payment page on another origin has no WordPress nonce, and this file never ships.
		$submission = self::submission( wp_unslash( $_POST ) );

		self::pay_session( $session, $submission );

		$target = self::redirect_target( $session, true );

		// Exactly what the real page does: 302 to the merchant's redirectUrl.
		// wp_redirect(), not wp_safe_redirect(): the store is a different
		// origin from Afriex, and safe_redirect would refuse it.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The merchant's own redirectUrl, which is the whole purpose of this page.
		wp_redirect( $target, 302 );
		exit;
	}

	/**
	 * The fields the page and POST /pay both understand.
	 *
	 * @param array $raw Posted or request parameters.
	 * @return array{outcome:string, channel:string, delay:int, webhook_order:string, webhook_delay:int, lose_webhook:bool, amount:string, currency:string}
	 */
	private static function submission( array $raw ): array {
		$settings = self::settings();

		$outcome = sanitize_key( (string) ( $raw['outcome'] ?? 'success' ) );

		if ( ! in_array( $outcome, array( 'success', 'failure', 'processing', 'delayed' ), true ) ) {
			$outcome = 'success';
		}

		$channel = strtoupper( sanitize_text_field( (string) ( $raw['channel'] ?? '' ) ) );

		return array(
			'outcome'       => $outcome,
			'channel'       => in_array( $channel, self::SESSION_CHANNELS, true ) ? $channel : '',
			'delay'         => min( self::MAX_DELAY, max( 0, (int) ( $raw['delay'] ?? 10 ) ) ),
			'webhook_order' => 'session_first' === sanitize_key( (string) ( $raw['webhook_order'] ?? $settings['webhook_order'] ) )
				? 'session_first'
				: 'transaction_first',
			'webhook_delay' => min( self::MAX_DELAY, max( 0, (int) ( $raw['webhook_delay'] ?? $settings['webhook_delay_seconds'] ) ) ),
			'lose_webhook'  => isset( $raw['lose_webhook'] )
				? self::truthy( $raw['lose_webhook'] )
				: (bool) $settings['lose_webhook'],
			// Deliberately wrong values are part of the matrix: a shopper who
			// pays the wrong amount, or a transaction in another currency.
			'amount'        => isset( $raw['amount'] ) && '' !== trim( (string) $raw['amount'] )
				? sanitize_text_field( (string) $raw['amount'] )
				: '',
			'currency'      => isset( $raw['currency'] ) && '' !== trim( (string) $raw['currency'] )
				? strtoupper( sanitize_text_field( (string) $raw['currency'] ) )
				: '',
		);
	}

	/**
	 * Record the transaction this payment produced and fire its webhooks.
	 *
	 * @param array $session    Session record.
	 * @param array $submission Normalised submission.
	 * @return array What was recorded, delivered and queued.
	 */
	private static function pay_session( array $session, array $submission ): array {
		$status = 'success' === $submission['outcome'] ? 'SUCCESS' : 'PROCESSING';

		if ( 'failure' === $submission['outcome'] ) {
			$status = 'FAILED';
		}

		$transaction = self::session_transaction( $session, $submission, $status );

		$store = self::store();
		$store['transactions'][ $transaction['transactionId'] ] = $transaction;
		self::save( $store );

		// Both events the real flow produces, in the order this payment asked
		// for. CHECKOUT_SESSION.CREATED carries the transaction id only once
		// the session is paid, which is what the event documentation says.
		$events = array(
			array(
				'event' => 'TRANSACTION.CREATED',
				'data'  => $transaction,
			),
			array(
				'event' => 'CHECKOUT_SESSION.CREATED',
				'data'  => self::session_event_data( $session, 'SUCCESS' === $status ? $transaction : null ),
			),
		);

		if ( 'session_first' === $submission['webhook_order'] ) {
			$events = array_reverse( $events );
		}

		$result = array(
			'transaction' => $transaction,
			'redirectTo'  => self::redirect_target( $session ),
			'deliveries'  => self::deliver_or_queue( $events, $submission['webhook_delay'], $submission['lose_webhook'] ),
			'queued'      => array(),
		);

		if ( 'delayed' === $submission['outcome'] ) {
			// "Success after N seconds": the shopper leaves a PROCESSING
			// transaction behind and the money lands later, which is the
			// mobile-money case the return panel and the sweep exist for.
			$result['queued'][] = self::queue(
				array(
					'kind'          => 'complete',
					'transactionId' => $transaction['transactionId'],
					'status'        => 'SUCCESS',
					'reference'     => (string) $session['merchantReference'],
					'lose_webhook'  => $submission['lose_webhook'],
				),
				$submission['delay']
			);
		}

		return $result;
	}

	/**
	 * A transaction for one session, as Afriex would report it.
	 *
	 * Amounts are major-unit decimal strings, as every other transaction the
	 * mock makes is; the session's own amount is in minor units.
	 *
	 * @param array  $session    Session record.
	 * @param array  $submission Normalised submission.
	 * @param string $status     Transaction status.
	 */
	private static function session_transaction( array $session, array $submission, string $status ): array {
		$channel = '' !== $submission['channel'] && in_array( $submission['channel'], (array) $session['channels'], true )
			? $submission['channel']
			: (string) ( $session['channels'][0] ?? 'VIRTUAL_BANK_ACCOUNT' );

		$currency = '' !== $submission['currency'] ? $submission['currency'] : (string) $session['currency'];
		$amount   = '' !== $submission['amount'] ? $submission['amount'] : self::major_amount( (int) $session['amount'] );
		$now      = self::now();
		$id       = self::new_id();

		$transaction = array(
			'transactionId'       => $id,
			'type'                => 'DEPOSIT',
			'channel'             => $channel,
			'status'              => $status,
			'sourceAmount'        => $amount,
			'sourceCurrency'      => $currency,
			'destinationAmount'   => $amount,
			'destinationCurrency' => $currency,
			'sourceId'            => '',
			'destinationId'       => (string) ( $session['sessionId'] ?? '' ),
			'customerId'          => '',
			'merchantReference'   => (string) $session['merchantReference'],
			'meta'                => array(
				'reference'      => (string) $session['merchantReference'],
				'idempotencyKey' => (string) $session['merchantReference'],
				'sessionId'      => (string) ( $session['sessionId'] ?? '' ),
			),
			'createdAt'           => $now,
			'updatedAt'           => $now,
		);

		if ( 'FAILED' === $status ) {
			$transaction['meta']['failureReason'] = array(
				'code'    => 'PAYMENT_DECLINED',
				'message' => 'The payment was declined on the Afriex page.',
			);
		}

		return $transaction;
	}

	/**
	 * The CHECKOUT_SESSION.CREATED body for a session.
	 *
	 * @param array      $session     Session record.
	 * @param array|null $transaction The paying transaction, once there is one.
	 */
	private static function session_event_data( array $session, ?array $transaction = null ): array {
		$data = array(
			'sessionId'         => (string) ( $session['sessionId'] ?? '' ),
			'merchantReference' => (string) $session['merchantReference'],
			'amount'            => (int) $session['amount'],
			'currency'          => (string) $session['currency'],
			'expiresAt'         => (string) ( $session['expiresAt'] ?? '' ),
			'createdAt'         => (string) ( $session['createdAt'] ?? '' ),
			'metadata'          => isset( $session['metadata'] ) ? $session['metadata'] : array(),
			'customer'          => isset( $session['customer'] ) ? $session['customer'] : array(),
		);

		if ( null !== $transaction ) {
			$data['afriexTransactionId'] = (string) $transaction['transactionId'];
			$data['paidAt']              = (string) $transaction['updatedAt'];
		}

		return $data;
	}

	/**
	 * Deliver events now, queue them for later, or drop them on the floor.
	 *
	 * A dropped delivery is still written to the delivery log, so a test can
	 * prove the webhook was lost on purpose rather than merely absent.
	 *
	 * @param array $events Each {event, data}.
	 * @param int   $delay  Seconds to wait, 0 for immediately.
	 * @param bool  $lose   Deliver nothing at all.
	 */
	private static function deliver_or_queue( array $events, int $delay, bool $lose ): array {
		$out = array();

		foreach ( $events as $entry ) {
			if ( $lose ) {
				$out[] = self::record_delivery(
					array(
						'ok'     => false,
						'event'  => (string) $entry['event'],
						'status' => 0,
						'body'   => 'dropped: lose_webhook',
						'time'   => self::now(),
					)
				);

				continue;
			}

			if ( $delay > 0 ) {
				$out[] = self::queue(
					array(
						'kind'  => 'event',
						'event' => (string) $entry['event'],
						'data'  => (array) $entry['data'],
					),
					$delay
				);

				continue;
			}

			$out[] = self::deliver_event( (string) $entry['event'], (array) $entry['data'] );
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// The deferred queue
	// -----------------------------------------------------------------

	/**
	 * Put one job in the queue and ask cron to come back for it.
	 *
	 * The cron event carries the job's own id so that two jobs a few seconds
	 * apart are not collapsed into one by WordPress's duplicate check.
	 *
	 * @param array $job     Job, without its due time.
	 * @param int   $seconds How long to wait.
	 */
	private static function queue( array $job, int $seconds ): array {
		$job['id']  = self::new_id();
		$job['due'] = time() + max( 0, $seconds );

		$store              = self::store();
		$store['pending'][] = $job;
		self::save( $store );

		wp_schedule_single_event( $job['due'], self::DUE_HOOK, array( $job['id'] ) );

		return $job;
	}

	/**
	 * Run every job whose time has come.
	 *
	 * Jobs are taken out of the store and written back before anything is
	 * delivered, because a delivery re-reads and re-saves the store; holding
	 * a stale copy across it would resurrect the job it just ran.
	 *
	 * @param mixed $ignored The job id cron passes; the whole queue is run anyway.
	 */
	public static function run_due( $ignored = null ): array {
		unset( $ignored );

		$store   = self::store();
		$pending = isset( $store['pending'] ) && is_array( $store['pending'] ) ? $store['pending'] : array();

		if ( empty( $pending ) ) {
			return array();
		}

		$now  = time();
		$due  = array();
		$keep = array();

		foreach ( $pending as $job ) {
			if ( (int) ( $job['due'] ?? 0 ) <= $now ) {
				$due[] = $job;
			} else {
				$keep[] = $job;
			}
		}

		if ( empty( $due ) ) {
			return array();
		}

		$store['pending'] = $keep;
		self::save( $store );

		$ran = array();

		foreach ( $due as $job ) {
			$ran[] = self::run_job( $job );
		}

		return $ran;
	}

	/**
	 * Run one queued job.
	 *
	 * @param array $job Job.
	 */
	private static function run_job( array $job ): array {
		if ( 'complete' === ( $job['kind'] ?? '' ) ) {
			$store = self::store();
			$id    = (string) $job['transactionId'];

			if ( ! isset( $store['transactions'][ $id ] ) ) {
				return array(
					'job'   => $job,
					'error' => 'the transaction is gone',
				);
			}

			$transaction              = $store['transactions'][ $id ];
			$transaction['status']    = (string) $job['status'];
			$transaction['updatedAt'] = self::now();

			$store['transactions'][ $id ] = $transaction;
			self::save( $store );

			$delivery = empty( $job['lose_webhook'] )
				? self::deliver_event( 'TRANSACTION.UPDATED', $transaction )
				: self::record_delivery(
					array(
						'ok'     => false,
						'event'  => 'TRANSACTION.UPDATED',
						'status' => 0,
						'body'   => 'dropped: lose_webhook',
						'time'   => self::now(),
					)
				);

			return array(
				'job'      => $job,
				'delivery' => $delivery,
			);
		}

		return array(
			'job'      => $job,
			'delivery' => self::deliver_event( (string) $job['event'], (array) $job['data'] ),
		);
	}

	/**
	 * Make every queued job due now.
	 */
	private static function force_due(): void {
		$store = self::store();

		foreach ( array_keys( (array) $store['pending'] ) as $index ) {
			$store['pending'][ $index ]['due'] = 0;
		}

		self::save( $store );
	}

	// -----------------------------------------------------------------
	// Page rendering
	// -----------------------------------------------------------------

	/**
	 * The page the shopper sees on "Afriex".
	 *
	 * @param array $session Session record.
	 */
	private static function render_checkout_page( array $session ): void {
		$reference = (string) $session['merchantReference'];
		$channels  = (array) $session['channels'];
		$settings  = self::settings();

		$rows = array(
			'Amount'     => self::major_amount( (int) $session['amount'] ) . ' ' . $session['currency']
				. ' (' . (int) $session['amount'] . ' minor units)',
			'Reference'  => $reference,
			'Session'    => (string) ( $session['sessionId'] ?? '' ),
			'Expires'    => (string) ( $session['expiresAt'] ?? '' ),
			'Customer'   => (string) ( $session['customer']['name'] ?? '' ) . ' <' . (string) ( $session['customer']['email'] ?? '' ) . '>',
			'Returns to' => self::redirect_target( $session, true ),
		);

		$html  = '<h1>Afriex (mock hosted page)</h1>';
		$html .= '<p class="note">Development only. Nothing here reaches Afriex; every button invents a transaction and delivers the webhooks Afriex would have sent.</p>';
		$html .= '<table>';

		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		$html .= '</table>';

		// No action: the form posts to the URL the browser actually opened.
		// The session's own checkoutUrl is https, because the plugin refuses
		// any other scheme, and this dev site has no TLS — so a page opened
		// over http must post over http or the shopper meets an SSL error
		// instead of a payment.
		$html .= '<form method="post">';

		$html .= '<fieldset><legend>Pay with</legend>';

		foreach ( $channels as $index => $channel ) {
			$html .= '<label><input type="radio" name="channel" value="' . esc_attr( $channel ) . '"'
				. ( 0 === $index ? ' checked' : '' ) . '> ' . esc_html( $channel ) . '</label>';
		}

		$html .= '</fieldset>';

		$html .= '<fieldset><legend>Webhooks</legend>';
		$html .= '<label>Order <select name="webhook_order">'
			. '<option value="transaction_first"' . selected( $settings['webhook_order'], 'transaction_first', false ) . '>TRANSACTION.* first</option>'
			. '<option value="session_first"' . selected( $settings['webhook_order'], 'session_first', false ) . '>CHECKOUT_SESSION.CREATED first</option>'
			. '</select></label>';
		$html .= '<label>Delay (seconds) <input type="number" name="webhook_delay" min="0" max="' . (int) self::MAX_DELAY . '" value="' . (int) $settings['webhook_delay_seconds'] . '"></label>';
		$html .= '<label><input type="checkbox" name="lose_webhook" value="1"' . checked( (bool) $settings['lose_webhook'], true, false ) . '> Lose the webhook</label>';
		$html .= '</fieldset>';

		$html .= '<fieldset><legend>Pay a different amount (the store should refuse it)</legend>';
		$html .= '<label>Amount <input type="text" name="amount" placeholder="' . esc_attr( self::major_amount( (int) $session['amount'] ) ) . '"></label>';
		$html .= '<label>Currency <input type="text" name="currency" size="4" placeholder="' . esc_attr( (string) $session['currency'] ) . '"></label>';
		$html .= '</fieldset>';

		$html .= '<fieldset><legend>Outcome</legend>';
		$html .= '<button type="submit" name="outcome" value="success" class="primary">Pay now</button>';
		$html .= '<button type="submit" name="outcome" value="failure">Fail the payment</button>';
		$html .= '<button type="submit" name="outcome" value="processing">Leave it processing</button>';
		$html .= '<button type="submit" name="outcome" value="delayed">Succeed after <input type="number" name="delay" min="0" max="' . (int) self::MAX_DELAY . '" value="10" size="3"> seconds</button>';
		$html .= '</fieldset>';

		$html .= '</form>';

		self::send_page( 200, $html );
	}

	/**
	 * Send one page and stop. Never cached: it is a payment page.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Escaped HTML for the body.
	 */
	private static function send_page( int $status, string $body ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$style = 'body{font:15px/1.5 system-ui,sans-serif;max-width:46em;margin:3em auto;padding:0 1em;color:#1d2327}'
			. 'h1{font-size:1.4em}table{border-collapse:collapse;margin:1em 0;width:100%}'
			. 'th,td{border-bottom:1px solid #dcdcde;padding:.4em .6em;text-align:left;vertical-align:top;font-weight:400}'
			. 'th{width:9em;color:#50575e}fieldset{border:1px solid #dcdcde;margin:1em 0;padding:.6em 1em}'
			. 'legend{color:#50575e;font-size:.9em}label{display:inline-block;margin:.3em 1.2em .3em 0}'
			. 'button{margin:.3em .6em .3em 0;padding:.5em .9em;cursor:pointer}'
			. 'button.primary{background:#2271b1;color:#fff;border:1px solid #2271b1}'
			. '.note{color:#50575e;font-size:.9em}code{background:#f0f0f1;padding:.1em .3em}';

		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Afriex (mock hosted page)</title><style>' . esc_html( $style ) . '</style></head><body>'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped values only.
			. $body
			. '</body></html>';

		exit;
	}

	/**
	 * Where the page sends the shopper when the payment is done.
	 *
	 * Afriex always uses the redirectUrl it was given, which is https. This
	 * dev site is http, so a browser run can ask for the scheme to be
	 * downgraded; by default nothing is touched.
	 *
	 * A browser that opened the page itself over plain http is already on
	 * this TLS-less site, so an https return here could only fail with
	 * ERR_SSL_PROTOCOL_ERROR; it goes back over http instead.
	 *
	 * @param array $session Session record.
	 * @param bool  $browser Whether the target is for the browser on the page.
	 */
	private static function redirect_target( array $session, bool $browser = false ): string {
		$url    = (string) ( $session['redirectUrl'] ?? home_url( '/' ) );
		$scheme = (string) self::settings( 'redirect_url_scheme' );

		if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return set_url_scheme( $url, $scheme );
		}

		if ( $browser && ! is_ssl() && self::is_this_site( $url ) ) {
			return set_url_scheme( $url, 'http' );
		}

		return $url;
	}

	/**
	 * Whether a URL points at this site's own host and port.
	 *
	 * @param string $url URL.
	 */
	private static function is_this_site( string $url ): bool {
		$home = wp_parse_url( home_url() );
		$that = wp_parse_url( $url );

		return is_array( $home ) && is_array( $that )
			&& strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $that['host'] ?? '' ) )
			&& (int) ( $home['port'] ?? 0 ) === (int) ( $that['port'] ?? 0 );
	}

	/**
	 * Send the browser to the fake hosted page over this site's own scheme.
	 *
	 * The stored checkoutUrl stays https, so the plugin's https-only rule is
	 * still exercised (plan 5.1 step 7). Only the redirect handed to the
	 * shopper's browser is rewritten, and only when it is this mock's page
	 * on this http site; the real Afriex page is never touched.
	 *
	 * @param string $url Redirect URL.
	 */
	private static function browser_checkout_url( string $url ): string {
		if ( is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return $url;
		}

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! self::is_this_site( $url ) ) {
			return $url;
		}

		if ( false === strpos( (string) wp_parse_url( $url, PHP_URL_QUERY ), self::PAGE_QUERY_VAR . '=' ) ) {
			return $url;
		}

		return set_url_scheme( $url, 'http' );
	}

	/**
	 * Classic checkout and the order-pay page.
	 *
	 * @param mixed $result   Gateway result.
	 * @param mixed $order_id Order id.
	 * @return mixed
	 */
	public static function browser_checkout_result( $result, $order_id ) {
		unset( $order_id );

		if ( is_array( $result ) && isset( $result['redirect'] ) && is_string( $result['redirect'] ) ) {
			$result['redirect'] = self::browser_checkout_url( $result['redirect'] );
		}

		return $result;
	}

	/**
	 * Block checkout (the Store API sets the redirect at priority 999).
	 *
	 * @param mixed $context Payment context.
	 * @param mixed $result  PaymentResult.
	 */
	public static function browser_checkout_block_result( $context, $result ): void {
		unset( $context );

		if ( is_object( $result ) && method_exists( $result, 'set_redirect_url' ) && is_string( $result->redirect_url ) && '' !== $result->redirect_url ) {
			$result->set_redirect_url( self::browser_checkout_url( $result->redirect_url ) );
		}
	}

	/**
	 * Minor units as the major-unit decimal string a transaction carries.
	 *
	 * Every currency the mock offers a checkout session in has two decimal
	 * places; the plugin hides Checkout for the others (plan 16, question 3).
	 *
	 * @param int $minor Amount in minor units.
	 */
	private static function major_amount( int $minor ): string {
		return number_format( $minor / 100, 2, '.', '' );
	}

	/**
	 * Whether a value posted as a string means yes.
	 *
	 * @param mixed $value Anything a form or a REST client sent.
	 */
	private static function truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Remember a delivery that was never attempted.
	 *
	 * @param array $result Delivery record.
	 */
	private static function record_delivery( array $result ): array {
		$store                 = self::store();
		$store['deliveries'][] = $result;
		$store['deliveries']   = array_slice( $store['deliveries'], -self::MAX_LOG );
		self::save( $store );

		return $result;
	}

	// -----------------------------------------------------------------
	// Webhook delivery
	// -----------------------------------------------------------------

	/**
	 * Sign a payload with the test private key and POST it at the plugin.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 * @return array What happened, for the caller to assert on.
	 */
	public static function deliver_event( string $event, array $data ): array {
		$payload = array(
			'event' => $event,
			'data'  => $data,
		);

		// Sign the exact bytes that go on the wire: re-encoding between
		// signing and sending is the classic way to break a good signature.
		$body = (string) wp_json_encode( $payload );
		$key  = self::private_key();

		if ( '' === $key ) {
			return array(
				'ok'    => false,
				'event' => $event,
				'error' => 'No test signing key found. Run: npm run webhook:keygen',
			);
		}

		$signature = '';

		if ( ! openssl_sign( $body, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return array(
				'ok'    => false,
				'event' => $event,
				'error' => 'openssl_sign() failed',
			);
		}

		$url  = (string) self::settings( 'webhook_url' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => array(
					'Content-Type'        => 'application/json',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a binary signature, exactly as Afriex does.
					'x-webhook-signature' => base64_encode( $signature ),
					'Host'                => $host,
				),
				'body'        => $body,
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		$result = array(
			'ok'     => ! is_wp_error( $response ),
			'event'  => $event,
			'url'    => $url,
			'status' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_body( $response ),
			'time'   => self::now(),
		);

		$result['ok'] = $result['ok'] && $result['status'] >= 200 && $result['status'] < 300;

		$store                 = self::store();
		$store['deliveries'][] = $result;
		$store['deliveries']   = array_slice( $store['deliveries'], -self::MAX_LOG );
		self::save( $store );

		return $result;
	}

	/**
	 * The test private key, whose public half the site trusts.
	 *
	 * The key lives in the plugin's tests/tools/.keys/, which is not where
	 * this file sits: mu-plugins are mounted from tests/mu-plugins. So find
	 * the plugin first, and let a constant override it outright.
	 */
	private static function private_key(): string {
		$candidates = array();

		if ( defined( 'AFRIEX_DEV_MOCK_KEY_PATH' ) ) {
			$candidates[] = (string) AFRIEX_DEV_MOCK_KEY_PATH;
		}

		if ( defined( 'AFRIEX_WC_PLUGIN_DIR' ) ) {
			$candidates[] = AFRIEX_WC_PLUGIN_DIR . 'tests/tools/.keys/private.pem';
		}

		$globbed = glob( WP_PLUGIN_DIR . '/*/tests/tools/.keys/private.pem' );

		if ( is_array( $globbed ) ) {
			$candidates = array_merge( $candidates, $globbed );
		}

		foreach ( $candidates as $path ) {
			if ( '' !== $path && is_readable( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, not a URL.
				return (string) file_get_contents( $path );
			}
		}

		return '';
	}

	// -----------------------------------------------------------------
	// Responses
	// -----------------------------------------------------------------

	/**
	 * A wp_remote_request()-shaped success response.
	 *
	 * @param int   $status  HTTP status.
	 * @param array $body    Response body, encoded as JSON.
	 * @param array $headers Response headers.
	 */
	private static function response( int $status, array $body, array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * An Afriex-shaped error response.
	 *
	 * @param int    $status   HTTP status.
	 * @param string $code     Machine-readable code.
	 * @param string $message  errorMessage, written for logs.
	 * @param array  $data     details.data, e.g. an existing customerId.
	 * @param string $friendly friendlyMessage; often empty, which is the point.
	 * @param array  $headers  Response headers.
	 */
	private static function error( int $status, string $code, string $message, array $data = array(), string $friendly = '', array $headers = array() ): array {
		$details = array(
			'errorMessage'    => $message,
			'friendlyMessage' => $friendly,
		);

		if ( ! empty( $data ) ) {
			$details['data'] = $data;
		}

		return self::response(
			$status,
			array(
				'code'    => $code,
				'error'   => $message,
				'details' => $details,
			),
			$headers
		);
	}

	/**
	 * The generic 422 the plan tells the plugin to branch away from.
	 *
	 * @param string $message What was wrong.
	 */
	private static function validation_error( string $message ): array {
		return self::error( 422, 'VALIDATION_ERROR', $message );
	}

	/**
	 * The fake hosted page's URL for one reference.
	 *
	 * @param string $reference Merchant reference.
	 */
	private static function checkout_page_url( string $reference ): string {
		$url    = home_url( '/?afriex-mock-pay=' . rawurlencode( $reference ) );
		$scheme = (string) self::settings( 'checkout_url_scheme' );

		return set_url_scheme( $url, 'http' === $scheme ? 'http' : 'https' );
	}

	/**
	 * The other 422: no requested channel works for this currency.
	 *
	 * Afriex does not publish the code for this case, so it is configurable;
	 * the plugin must branch on `code`, and this is the value it will see.
	 *
	 * @param string $currency Session currency.
	 */
	private static function unsupported_channels_error( string $currency ): array {
		return self::error(
			422,
			(string) self::settings( 'unsupported_channels_code' ),
			'None of the requested channels is supported for ' . $currency . '.',
			array( 'currency' => $currency ),
			'This payment method is not available for ' . $currency . ' right now.'
		);
	}

	/**
	 * A forced failure, or null when the mode is not one this mock knows.
	 *
	 * @param string $mode Failure mode.
	 * @return array|WP_Error|null
	 */
	private static function forced_response( string $mode ) {
		switch ( $mode ) {
			case 'validation':
				return self::validation_error( 'Forced failure: the request did not pass validation.' );

			case 'unsupported_channels':
				return self::unsupported_channels_error( 'the requested currency' );

			case 'unavailable':
				return self::error( 503, 'EXTERNAL_REQUEST_ERROR', 'Forced failure: upstream unavailable.', array(), '', array( 'retry-after' => '1' ) );

			case 'rate_limited':
				return self::error( 429, 'RATE_LIMITED', 'Forced failure: too many requests.', array(), '', array( 'retry-after' => '1' ) );

			case 'gateway':
				// Cloudflare's 502/504: HTML, no Afriex JSON body at all.
				return array(
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => '<html><head><title>502 Bad Gateway</title></head><body>502 Bad Gateway</body></html>',
					'response' => array(
						'code'    => 502,
						'message' => 'Bad Gateway',
					),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'auth':
				return self::error( 401, 'AUTHENTICATION_ERROR', 'Forced failure: invalid business API key.' );

			case 'forbidden':
				// The body Afriex documents for a checkout-session route it
				// will not serve this business: a bare message, no code.
				return self::response( 403, array( 'message' => 'Not allowed' ) );

			case 'server':
				return self::error( 500, 'INTERNAL_SERVER_ERROR', 'Forced failure: internal server error.' );

			case 'network':
				return new WP_Error( 'http_request_failed', 'Forced failure: cURL error 28: Operation timed out.' );

			case 'empty_body':
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);

			default:
				return null;
		}
	}

	// -----------------------------------------------------------------
	// Store
	// -----------------------------------------------------------------

	/**
	 * The fake account, with every key present.
	 *
	 * Always read from the database, never from the object cache. Three
	 * processes share this store at once — the page that calls the API, the
	 * control route that armed a failure mode a moment ago, and the webhook
	 * delivery that lands back in a fourth — and a cached copy from earlier in
	 * one long-lived process (a test script, WP-CLI) would hide what the
	 * others just wrote and clobber it on the next save. A dev mock can pay
	 * for one query per read.
	 */
	private static function store(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$store  = array_merge( self::defaults(), $stored );

		$store['settings'] = array_merge(
			self::defaults()['settings'],
			isset( $stored['settings'] ) && is_array( $stored['settings'] ) ? $stored['settings'] : array()
		);

		return $store;
	}

	/**
	 * Write the fake account back. Never autoloaded: it can hold hundreds of
	 * fake transactions and has no business on every page load.
	 *
	 * @param array $store Store.
	 */
	private static function save( array $store ): void {
		update_option( self::OPTION, $store, false );
	}

	/**
	 * An empty fake account.
	 */
	private static function defaults(): array {
		return array(
			'customers'       => array(),
			'payment_methods' => array(),
			'transactions'    => array(),
			'sessions'        => array(),
			'failures'        => array(),
			'requests'        => array(),
			'deliveries'      => array(),
			'pending'         => array(),
			'settings'        => array(
				'webhook_url'               => self::DEFAULT_WEBHOOK_URL,
				'va_expires_in_minutes'     => 60,
				'session_ttl_minutes'       => 15,
				'require_api_key'           => true,
				'unsupported_channels_code' => 'UNSUPPORTED_CHANNELS',
				'channels'                  => self::DEFAULT_CHANNELS,
				// How long the 'slow' failure mode holds a call open.
				'slow_seconds'              => 3,
				// Defaults the fake hosted page offers; every one of them is
				// also a field on the page, so a single payment can differ
				// from the store-wide setting without changing it.
				'webhook_order'             => 'transaction_first',
				'webhook_delay_seconds'     => 0,
				'lose_webhook'              => false,
				// What the page does with the session's redirectUrl. Afriex
				// only ever sends the shopper back to the https URL it was
				// given, which is 'as-is'; a browser run against this http
				// dev site sets 'http' so the redirect can be followed.
				'redirect_url_scheme'       => 'as-is',
				// Afriex's hosted page is always https, and the plugin
				// refuses a checkoutUrl that is not (plan 5.1 step 7). This
				// dev site is http, so the fake page's URL is given the https
				// scheme by default and that rule is genuinely exercised. A
				// test that has to open the page in a browser sets this to
				// 'http' through POST /settings.
				'checkout_url_scheme'       => 'https',
			),
		);
	}

	/**
	 * One setting, or all of them.
	 *
	 * @param string|null $key Setting name, or null for the lot.
	 * @return mixed
	 */
	private static function settings( ?string $key = null ) {
		$settings = self::store()['settings'];

		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}

	/**
	 * Channels this currency can collect on.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	private static function supported_channels( string $currency ): array {
		$channels = self::settings( 'channels' );
		$channels = is_array( $channels ) ? $channels : self::DEFAULT_CHANNELS;

		if ( isset( $channels[ $currency ] ) && is_array( $channels[ $currency ] ) ) {
			return array_values( $channels[ $currency ] );
		}

		// An unlisted currency collects on nothing, which is the 422 case.
		return array();
	}

	/**
	 * Consume a forced failure for an endpoint, if one is armed.
	 *
	 * @param string $endpoint Endpoint key.
	 * @return string The mode, or '' when there is none.
	 */
	private static function take_failure( string $endpoint ): string {
		$store = self::store();

		if ( ! isset( $store['failures'][ $endpoint ]['mode'] ) ) {
			return '';
		}

		$mode  = (string) $store['failures'][ $endpoint ]['mode'];
		$times = (int) ( $store['failures'][ $endpoint ]['times'] ?? 0 );

		// times = 0 means "until it is cleared".
		if ( $times > 0 ) {
			--$times;

			if ( 0 === $times ) {
				unset( $store['failures'][ $endpoint ] );
			} else {
				$store['failures'][ $endpoint ]['times'] = $times;
			}

			self::save( $store );
		}

		return $mode;
	}

	/**
	 * Remember an intercepted call, so a test can prove where it went — and
	 * that it never left this machine.
	 *
	 * @param string         $host     Host the plugin aimed at.
	 * @param string         $method   HTTP method.
	 * @param string         $route    Path after /api/v1.
	 * @param array|WP_Error $response What the mock answered.
	 */
	private static function log_request( string $host, string $method, string $route, $response ): void {
		$store = self::store();

		$store['requests'][] = array(
			'time'        => self::now(),
			'host'        => $host,
			'environment' => 0 === strpos( $host, 'sandbox.' ) ? 'sandbox' : 'production',
			'method'      => $method,
			'route'       => $route,
			'status'      => is_wp_error( $response ) ? 0 : (int) ( $response['response']['code'] ?? 0 ),
			'answered_by' => 'mock',
		);

		$store['requests'] = array_slice( $store['requests'], -self::MAX_LOG );
		self::save( $store );
	}

	// -----------------------------------------------------------------
	// Small helpers
	// -----------------------------------------------------------------

	/**
	 * {page, total, data}, the envelope every Afriex list uses.
	 *
	 * @param array $rows  All matching rows.
	 * @param array $query Query arguments carrying page and limit.
	 */
	private static function paginate( array $rows, array $query ): array {
		$page  = max( 0, (int) ( $query['page'] ?? 0 ) );
		$limit = (int) ( $query['limit'] ?? 10 );
		$limit = min( 100, max( 1, $limit ) );

		return self::response(
			200,
			array(
				'page'  => $page,
				'total' => count( $rows ),
				'data'  => array_values( array_slice( $rows, $page * $limit, $limit ) ),
			)
		);
	}

	/**
	 * Split a comma-separated filter into upper-case values.
	 *
	 * @param string $value Raw filter value.
	 */
	private static function csv( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $part ) {
						return strtoupper( trim( (string) $part ) );
					},
					explode( ',', $value )
				),
				static function ( $part ) {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * Whether a phone number is in E.164 form.
	 *
	 * @param string $phone Candidate number.
	 */
	private static function is_e164( string $phone ): bool {
		return 1 === preg_match( '/^\+[1-9]\d{7,14}$/', trim( $phone ) );
	}

	/**
	 * A 24-character hexadecimal id, the shape Afriex's ids have.
	 */
	private static function new_id(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	/**
	 * An ISO 8601 UTC timestamp with milliseconds, as Afriex sends.
	 *
	 * @param int $offset Seconds from now.
	 */
	private static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d\TH:i:s', time() + $offset ) . '.000Z';
	}

	/**
	 * A plausible country for a virtual account's currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	private static function country_for_currency( string $currency ): string {
		$map = array(
			'NGN' => 'NG',
			'USD' => 'US',
			'GBP' => 'GB',
			'EUR' => 'DE',
		);

		return $map[ $currency ] ?? 'NG';
	}
}

Afriex_Mock_Api::init();
