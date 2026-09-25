<?php
/**
 * The Afriex account settings shared by every Afriex payment method.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * One API key, environment, pair of webhook keys and logging switch, stored
 * once in the afriex_wc_account option and shown at the top of each Afriex
 * gateway's settings page.
 *
 * Why a WC_Settings_API subclass rather than fields copied into each gateway:
 * - The values are stored exactly once, so the two gateways cannot disagree
 *   about which key or environment is live.
 * - WooCommerce renders, sanitises and saves the fields inside its own settings
 *   flow, so its nonce and manage_woocommerce checks apply unchanged.
 * - The secrets are not gateway form fields, so GET /wc/v3/payment_gateways
 *   never serialises them (F51), and the REST gateway update path, which skips
 *   validators (F83), cannot write them.
 *
 * Accessors used by the rest of the plugin are static and read the option
 * directly, so they work before any gateway is constructed.
 */
class Afriex_Account_Settings extends WC_Settings_API {

	public const OPTION_KEY = 'afriex_wc_account';

	/**
	 * Option holding the "you switched environment with orders in flight"
	 * warning, until an admin dismisses it (plan 12).
	 */
	public const ENVIRONMENT_NOTICE_OPTION = 'afriex_wc_environment_notice';

	/**
	 * The 1.0 gateway option, read as a fallback until the migration has run.
	 */
	private const LEGACY_OPTION_KEY = 'woocommerce_afriex_settings';

	/**
	 * Smallest RSA modulus accepted for a webhook key (F4).
	 */
	private const MIN_RSA_BITS = 2048;

	/**
	 * Fields that are actions or read-only displays, never stored.
	 */
	private const TRANSIENT_FIELDS = array( 'clear_api_key', 'webhook_url' );

	/**
	 * Shared instance: both gateways render and save the same object.
	 *
	 * @var Afriex_Account_Settings|null
	 */
	private static $instance = null;

	/**
	 * The shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Field keys are woocommerce_afriex_account_*, distinct from any gateway's.
	 */
	public function __construct() {
		$this->plugin_id = 'woocommerce_';
		$this->id        = 'afriex_account';

		$this->init_form_fields();
		$this->init_settings();

		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'strip_transient_fields' ) );
	}

	/**
	 * Load the stored values, or the 1.0 values until the first save.
	 *
	 * WC_Settings_API falls back to the field defaults when its option does not
	 * exist. Here that would render an empty key and Sandbox for a store that
	 * has been live on 1.0, and the first save would then write those defaults
	 * over the real key (a blank key field keeps the *loaded* value). Seeding
	 * from values() carries the 1.0 key, environment, webhook key and logging
	 * switch across instead.
	 */
	public function init_settings() {
		parent::init_settings();

		if ( ! is_array( get_option( self::OPTION_KEY, null ) ) ) {
			$this->settings = array_merge( $this->settings, self::values() );
		}
	}

	/**
	 * Store under afriex_wc_account rather than woocommerce_afriex_account_settings.
	 *
	 * @return string
	 */
	public function get_option_key() {
		return self::OPTION_KEY;
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'environment'                   => array(
				'title'       => __( 'Environment', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					Afriex_Api_Client::ENV_SANDBOX    => __( 'Sandbox', 'afriex-gateway-for-woocommerce' ),
					Afriex_Api_Client::ENV_PRODUCTION => __( 'Production', 'afriex-gateway-for-woocommerce' ),
				),
				'default'     => Afriex_Api_Client::ENV_SANDBOX,
				'description' => __( 'Sandbox is shown to shoppers as test mode. Afriex creates bank-transfer virtual accounts only in Production.', 'afriex-gateway-for-woocommerce' ),
			),
			'api_key'                       => array(
				'title'       => __( 'API key', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'afriex_secret',
				'default'     => '',
				'description' => __( 'From Settings > API Keys in your Afriex Business dashboard, for the environment selected above. The key needs access to customers, payment methods, transactions and checkout sessions.', 'afriex-gateway-for-woocommerce' ),
			),
			'clear_api_key'                 => array(
				'title'   => __( 'Clear API key', 'afriex-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Remove the saved API key when saving', 'afriex-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'webhook_public_key_production' => array(
				'title'       => __( 'Webhook public key: production', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'font-family: monospace; min-height: 8em;',
				'placeholder' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
				'description' => __( 'From Developers > Webhooks in your Afriex Business dashboard (production). An RSA key of at least 2048 bits, in PEM form.', 'afriex-gateway-for-woocommerce' ),
			),
			'webhook_public_key_sandbox'    => array(
				'title'       => __( 'Webhook public key: sandbox', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'font-family: monospace; min-height: 8em;',
				'placeholder' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
				'description' => __( 'The sandbox key differs from the production one. Both are kept, so payments for orders placed under either environment still verify after a switch.', 'afriex-gateway-for-woocommerce' ),
			),
			'webhook_url'                   => array(
				'title'       => __( 'Webhook URL', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'afriex_readonly_url',
				'description' => __( 'Add this URL under Developers > Webhooks in your Afriex Business dashboard. It serves every Afriex payment method.', 'afriex-gateway-for-woocommerce' ),
			),
			'logging'                       => array(
				'title'       => __( 'Debug logging', 'afriex-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log Afriex API requests and webhook events', 'afriex-gateway-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Written to WooCommerce > Status > Logs. Errors are always logged; this adds request-level detail.', 'afriex-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Save the account fields, then drop everything cached from the old values.
	 *
	 * Called by each Afriex gateway's process_admin_options(), which WooCommerce
	 * runs only after its settings nonce and capability checks. The capability
	 * is checked again here because the method is public.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$before = self::environment();

		$saved = parent::process_admin_options();

		Afriex_Logger::reset();

		// A saved key is a fresh start: the gateways stop hiding for a
		// previous 401, or Checkout for a refused session, until the next
		// call proves otherwise (F84).
		Afriex_Api_Client::clear_auth_errors();
		Afriex_Checkout_Gateway::clear_session_refusals();

		self::check_environment_switch( $before );

		return $saved;
	}

	/**
	 * Warn when the environment changed with orders still in flight (plan 12).
	 *
	 * An order keeps the environment it was created in (`_afriex_environment`,
	 * F50), so settlement still asks the right host about it — but the API key
	 * is a single stored value, and Afriex's keys are per environment. An
	 * admin who switches while unpaid orders are outstanding has very likely
	 * just stopped those orders settling, and nothing else on the page says
	 * so. Counting them is cheap and the notice is dismissible.
	 *
	 * @param string $before The environment in force before the save.
	 */
	private static function check_environment_switch( string $before ): void {
		$after = self::environment();

		if ( '' === $before || $before === $after ) {
			return;
		}

		$orders = Afriex_Order_Meta::query_orders(
			array(
				// _afriex_environment is only ever written by this plugin, so
				// the meta match already confines this to Afriex orders.
				'status' => array( 'on-hold', 'pending' ),
				'limit'  => 100,
				'return' => 'ids',
			),
			array(
				array(
					'key'     => Afriex_Order_Meta::KEY_ENVIRONMENT,
					'value'   => $before,
					'compare' => '=',
				),
			)
		);

		$count = is_array( $orders ) ? count( $orders ) : 0;

		if ( $count < 1 ) {
			return;
		}

		update_option(
			self::ENVIRONMENT_NOTICE_OPTION,
			array(
				'from'   => $before,
				'to'     => $after,
				'orders' => $count,
			),
			false
		);

		Afriex_Logger::warning(
			'Afriex environment changed with unpaid orders outstanding',
			array(
				'from'   => $before,
				'to'     => $after,
				'orders' => $count,
			)
		);
	}

	/**
	 * Report a validation error through WooCommerce's settings page too.
	 *
	 * WC_Settings_API keeps errors on the object, and WooCommerce re-creates
	 * the gateways after saving, so on their own they would never be shown and
	 * the page would still say "Your settings have been saved" (F83).
	 * WC_Admin_Settings::add_error() replaces that message with the error.
	 *
	 * @param string $error Message.
	 */
	public function add_error( $error ) {
		parent::add_error( $error );

		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( $error );
		}
	}

	/**
	 * Drop action and display fields before the option is written.
	 *
	 * @param array $settings Sanitised settings.
	 * @return array
	 */
	public function strip_transient_fields( $settings ) {
		if ( is_array( $settings ) ) {
			foreach ( self::TRANSIENT_FIELDS as $key ) {
				unset( $settings[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * The API key field: never echoes the stored key back into the page.
	 *
	 * A blank submission keeps the stored key, so rendering it empty costs
	 * nothing, and the key no longer sits in the page source for any browser
	 * extension or shoulder to read (F52).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_afriex_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$stored    = (string) $this->get_option( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
				'desc_tip'    => false,
			)
		);

		// The fingerprint, not the last four characters of the key. Order notes
		// and the order screen identify a key by its fingerprint — the first
		// twelve characters of sha256(key) — and having the settings page
		// print a different identifier under the same phrase ("ends in") had
		// admins compare a hash with a key fragment and conclude the wrong key
		// was installed. One identifier, everywhere, so the two can be
		// compared.
		$placeholder = '' === $stored
			? __( 'Not set', 'afriex-gateway-for-woocommerce' )
			: sprintf(
				/* translators: %s: a short hash identifying the saved API key */
				__( 'Saved (key fingerprint %s). Leave blank to keep.', 'afriex-gateway-for-woocommerce' ),
				self::key_fingerprint( $stored )
			);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes the tooltip. ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" spellcheck="false" />
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes the description. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The read-only webhook URL with a copy button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_afriex_readonly_url_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$url       = self::webhook_url();
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
				'desc_tip'    => false,
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input code" type="text" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $url ); ?>" readonly="readonly" onfocus="this.select();" />
					<button type="button" class="button afriex-copy-webhook-url" data-target="<?php echo esc_attr( $field_key ); ?>" onclick="var f=document.getElementById(this.getAttribute('data-target'));if(navigator.clipboard){navigator.clipboard.writeText(f.value);}else{f.select();document.execCommand('copy');}this.textContent=this.getAttribute('data-done');" data-done="<?php echo esc_attr__( 'Copied', 'afriex-gateway-for-woocommerce' ); ?>"><?php echo esc_html__( 'Copy', 'afriex-gateway-for-woocommerce' ); ?></button>
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes the description. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Blank keeps the stored key; the Clear checkbox removes it (F52).
	 *
	 * A value made only of mask characters is treated as blank too, so a
	 * masked value echoed back by some tool can never overwrite the real key.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 * @throws Exception When the value cannot be an API key.
	 */
	public function validate_api_key_field( $key, $value ) {
		$post  = $this->get_post_data();
		$clear = ! empty( $post[ $this->get_field_key( 'clear_api_key' ) ] );
		$value = null === $value ? '' : trim( wp_unslash( (string) $value ) );

		if ( '' === $value || preg_match( '/^[\*\x{2022}]+$/u', $value ) ) {
			return $clear ? '' : (string) $this->get_option( $key );
		}

		if ( strlen( $value ) > 512 || ! preg_match( '/^[\x21-\x7E]+$/', $value ) ) {
			throw new Exception( esc_html__( 'The Afriex API key contains characters an API key cannot have. The previous key was kept.', 'afriex-gateway-for-woocommerce' ) );
		}

		return $value;
	}

	/**
	 * The Clear checkbox is an action, never a stored preference.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_clear_api_key_field( $key, $value ) {
		return 'no';
	}

	/**
	 * The webhook URL is displayed, never submitted.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_webhook_url_field( $key, $value ) {
		return '';
	}

	/**
	 * Validate the production webhook key.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 * @throws Exception When the key is not a usable RSA public key.
	 */
	public function validate_webhook_public_key_production_field( $key, $value ) {
		return self::validate_public_key(
			null === $value ? '' : wp_unslash( (string) $value ),
			__( 'production webhook public key', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Validate the sandbox webhook key.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 * @throws Exception When the key is not a usable RSA public key.
	 */
	public function validate_webhook_public_key_sandbox_field( $key, $value ) {
		return self::validate_public_key(
			null === $value ? '' : wp_unslash( (string) $value ),
			__( 'sandbox webhook public key', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Only the two known environments can be stored.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_environment_field( $key, $value ) {
		return Afriex_Api_Client::normalise_environment( null === $value ? '' : sanitize_key( wp_unslash( (string) $value ) ) );
	}

	/**
	 * Normalise and validate a PEM public key.
	 *
	 * An unparseable key used to be saved silently and then made every genuine
	 * delivery fail exactly like a forgery (F4). Throwing here makes
	 * WooCommerce keep the old value and show the reason.
	 *
	 * @param string $raw   Submitted key.
	 * @param string $label Human name of the field, for the error.
	 * @return string Normalised PEM, or '' when the field was emptied.
	 * @throws Exception When the key is not an RSA public key of 2048+ bits.
	 */
	public static function validate_public_key( string $raw, string $label ): string {
		$pem = self::normalise_pem( $raw );

		if ( '' === $pem ) {
			return '';
		}

		if ( ! function_exists( 'openssl_pkey_get_public' ) ) {
			throw new Exception( esc_html__( 'The PHP openssl extension is not available, so webhook keys cannot be checked or used. Ask your host to enable it.', 'afriex-gateway-for-woocommerce' ) );
		}

		$key = openssl_pkey_get_public( $pem );

		if ( false === $key ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: field name, e.g. "sandbox webhook public key" */
						__( 'The %s is not a valid PEM public key. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						$label
					)
				)
			);
		}

		$details = openssl_pkey_get_details( $key );

		if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== ( $details['type'] ?? null ) ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: field name */
						__( 'The %s is not an RSA key. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						$label
					)
				)
			);
		}

		if ( (int) ( $details['bits'] ?? 0 ) < self::MIN_RSA_BITS ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: 1: field name, 2: minimum key size in bits */
						__( 'The %1$s is shorter than %2$d bits. The previous value was kept.', 'afriex-gateway-for-woocommerce' ),
						$label,
						self::MIN_RSA_BITS
					)
				)
			);
		}

		return $pem;
	}

	/**
	 * Rebuild a public key into canonical PEM from the ways it gets pasted.
	 *
	 * Handles real newlines, escaped "\n" sequences, JSON-escaped "\/",
	 * surrounding quotes, a PEM squashed onto one line, and bare base64 with no
	 * header at all (F4, F86).
	 *
	 * @param string $raw Key as submitted or stored.
	 * @return string PEM, or '' for an empty value. An unrecognisable value is
	 *                returned trimmed, for openssl to reject.
	 */
	public static function normalise_pem( string $raw ): string {
		$key = trim( $raw );
		$key = trim( $key, "\"' \t\r\n" );
		$key = str_replace( array( '\\r\\n', '\\n', '\\r', '\\/' ), array( "\n", "\n", "\n", '/' ), $key );
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		if ( preg_match( '/-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----/s', $key, $parts ) ) {
			$label = $parts[1];
			$body  = (string) preg_replace( '/\s+/', '', $parts[2] );
		} else {
			$label = 'PUBLIC KEY';
			$body  = (string) preg_replace( '/\s+/', '', $key );
		}

		if ( '' === $body || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $body ) ) {
			return $key;
		}

		return '-----BEGIN ' . $label . "-----\n" . rtrim( chunk_split( $body, 64, "\n" ) ) . "\n-----END " . $label . '-----';
	}

	/**
	 * Stored account values, with defaults, and a read-through of the 1.0
	 * gateway option until the migration has copied it. The fallback keeps a
	 * site working in the one request where the migration is still running
	 * elsewhere, or if it failed.
	 *
	 * @return array
	 */
	public static function values(): array {
		$defaults = array(
			'environment'                   => Afriex_Api_Client::ENV_SANDBOX,
			'api_key'                       => '',
			'webhook_public_key_production' => '',
			'webhook_public_key_sandbox'    => '',
			'logging'                       => 'no',
		);

		$stored = get_option( self::OPTION_KEY, false );

		if ( is_array( $stored ) ) {
			return array_merge( $defaults, $stored );
		}

		$legacy = get_option( self::LEGACY_OPTION_KEY, array() );

		if ( ! is_array( $legacy ) ) {
			return $defaults;
		}

		$environment = Afriex_Api_Client::normalise_environment( (string) ( $legacy['environment'] ?? '' ) );

		$defaults['environment'] = $environment;
		$defaults['api_key']     = (string) ( $legacy['api_key'] ?? '' );
		$defaults['logging']     = 'yes' === ( $legacy['logging'] ?? 'no' ) ? 'yes' : 'no';

		$defaults[ 'webhook_public_key_' . $environment ] = (string) ( $legacy['webhook_public_key'] ?? '' );

		return $defaults;
	}

	/**
	 * The configured environment: 'sandbox' or 'production'.
	 */
	public static function environment(): string {
		return Afriex_Api_Client::normalise_environment( (string) self::values()['environment'] );
	}

	/**
	 * The API key, or '' when none is saved.
	 */
	public static function api_key(): string {
		return trim( (string) self::values()['api_key'] );
	}

	/**
	 * A short, non-reversible fingerprint of an API key (F50).
	 *
	 * Written onto each order beside its environment, so that after a key
	 * rotation it is possible to tell which orders were created under which
	 * key. A hash rather than the last few characters of the key itself,
	 * because order meta is exported, emailed and read by other plugins, and
	 * a fragment of a key is still key material.
	 *
	 * @param string|null $key Key to fingerprint; null for the saved one.
	 */
	public static function key_fingerprint( ?string $key = null ): string {
		$key = null === $key ? self::api_key() : trim( $key );

		return '' === $key ? '' : substr( hash( 'sha256', $key ), 0, 12 );
	}

	/**
	 * The webhook public key for an environment, as PEM, or ''.
	 *
	 * @param string|null $environment Environment, null for the configured one.
	 */
	public static function webhook_public_key( ?string $environment = null ): string {
		$environment = null === $environment ? self::environment() : Afriex_Api_Client::normalise_environment( $environment );

		return self::normalise_pem( (string) ( self::values()[ 'webhook_public_key_' . $environment ] ?? '' ) );
	}

	/**
	 * Whether info and debug lines are logged. Errors always are.
	 */
	public static function logging_enabled(): bool {
		return 'yes' === self::values()['logging'];
	}

	/**
	 * The URL Afriex should send webhooks to, for every Afriex method.
	 */
	public static function webhook_url(): string {
		return rest_url( 'afriex/v1/webhook' );
	}
}
