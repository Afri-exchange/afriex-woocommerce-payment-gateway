<?php
/**
 * Cart & Checkout blocks integration.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one Afriex payment method to the block checkout's registry.
 *
 * A gateway that implements only WC_Payment_Gateway is completely invisible on
 * block checkout — stores using it see "There are no payment methods available"
 * even with the gateway enabled and correctly configured. There is no automatic
 * bridge between the two checkouts.
 *
 * One instance is registered per gateway id (plan 10). Both share one script
 * bundle and each hands its own data to it, reaching JS as
 * getSetting( 'afriex_data' ) and getSetting( 'afriex_checkout_data' ).
 *
 * This class is purely additive: it reads the classic gateway's settings,
 * title, description and availability rather than duplicating them, so the two
 * checkouts can never drift apart.
 */
class Afriex_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * The shared bundle's script handle.
	 */
	private const SCRIPT_HANDLE = 'afriex-blocks-checkout';

	/**
	 * Must match the gateway id, and the name registered in the JS module.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Lazily resolved classic gateway.
	 *
	 * @var Afriex_Payment_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Which Afriex method this instance describes.
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public function __construct( string $gateway_id = AFRIEX_WC_GATEWAY_ID ) {
		$this->name = $gateway_id;
	}

	/**
	 * Load this method's own settings.
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Availability is delegated, so one rule hides a method on both checkouts.
	 */
	public function is_active(): bool {
		$gateway = $this->get_gateway();

		return null !== $gateway && $gateway->is_available();
	}

	/**
	 * The bundle both methods are described by.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$script_path = 'assets/js/build/afriex-blocks-checkout.js';
		$asset_path  = AFRIEX_WC_PLUGIN_DIR . 'assets/js/build/afriex-blocks-checkout.asset.php';

		if ( ! file_exists( AFRIEX_WC_PLUGIN_DIR . $script_path ) ) {
			// Nothing to register. Returning an empty array leaves the method
			// off block checkout rather than enqueuing a 404 and breaking the
			// whole checkout bundle.
			return array();
		}

		// Both methods share the bundle, so the second instance to be asked
		// finds it registered already.
		if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			return array( self::SCRIPT_HANDLE );
		}

		$asset = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => AFRIEX_WC_VERSION,
			);

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( $script_path, AFRIEX_WC_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'afriex-gateway-for-woocommerce',
				AFRIEX_WC_PLUGIN_DIR . 'languages'
			);
		}

		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Data handed to the JS module, as getSetting( '{gateway id}_data' ).
	 *
	 * WooCommerce prints this only for methods that are active, so a method
	 * the store has switched off, or one that is hidden for any other reason,
	 * reaches the client with no data at all — which is how the JS knows not
	 * to register it.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->get_gateway();

		if ( null === $gateway ) {
			return array();
		}

		$data = array(
			'id'          => $this->name,
			'title'       => $gateway->get_title(),
			'description' => $gateway->get_description(),
			'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
		);

		if ( $gateway instanceof Afriex_Checkout_Gateway ) {
			// Badges for the options Afriex has actually offered for this
			// store's currency, and none at all while it has never said
			// (plan 4.2, 10). The button says where Place order goes.
			$data['channels'] = array();

			foreach ( Afriex_Checkout_Gateway::known_channels( get_woocommerce_currency() ) as $channel ) {
				$data['channels'][] = array(
					'code'  => $channel,
					'label' => Afriex_Checkout_Gateway::channel_label( $channel ),
				);
			}

			$data['placeOrderButtonLabel'] = __( 'Continue to Afriex', 'afriex-gateway-for-woocommerce' );
			$data['redirects']             = true;
		}

		return $data;
	}

	/**
	 * This instance's gateway, or null when WooCommerce has not built it.
	 */
	private function get_gateway(): ?Afriex_Payment_Gateway {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}

		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		if ( ! isset( $gateways[ $this->name ] ) || ! $gateways[ $this->name ] instanceof Afriex_Payment_Gateway ) {
			return null;
		}

		$this->gateway = $gateways[ $this->name ];

		return $this->gateway;
	}
}
