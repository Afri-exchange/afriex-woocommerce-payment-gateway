<?php
/**
 * Upgrade from 1.0: settings, order meta, scheduler (plan section 12).
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brings a site's stored data up to what this version expects.
 *
 * Plugin updates do not re-run the activation hook, so the migration is
 * driven by a version check instead: the stored afriex_wc_db_version is
 * compared with target_version() on every request, which costs one
 * autoloaded option read. When they differ, run() does the work.
 *
 * Every step is idempotent and checks the current state before it writes,
 * so run() can be called any number of times, and a run cut short (a
 * timeout, a fatal elsewhere, a batch limit) simply continues on the next
 * request. The version is recorded only after every step has finished.
 *
 * Concurrency: run() takes the ledger lock 'migration' without waiting. A
 * request that does not get it skips the migration; the accessors in
 * Afriex_Account_Settings read the 1.0 option until the copy exists, so the
 * site keeps working in the meantime.
 */
class Afriex_Migration {

	public const VERSION_OPTION = 'afriex_wc_db_version';

	/**
	 * Bump when a migration step is added. The stored version combines this
	 * with the ledger schema version, so a schema change alone also re-runs
	 * dbDelta().
	 */
	public const MIGRATION_VERSION = 1;

	/**
	 * Option holding the pending one-time notice about pool mode:
	 * array( 'store' => bool, 'orders' => int ).
	 */
	public const NOTICE_OPTION = 'afriex_wc_pool_notice';

	/**
	 * The 1.0 WP-Cron event, replaced by Afriex_Scheduler::HOOK.
	 */
	public const LEGACY_CRON_HOOK = 'afriex_reconcile_pending_orders';

	/**
	 * Keys of the 1.0 gateway option that now live in afriex_wc_account, or
	 * that no longer mean anything (collection_method: pool mode is
	 * withdrawn). They are removed from woocommerce_afriex_settings so the
	 * gateway REST API cannot expose them (F51).
	 */
	public const LEGACY_GATEWAY_KEYS = array( 'api_key', 'webhook_public_key', 'environment', 'logging', 'collection_method' );

	private const LEGACY_GATEWAY_OPTION = 'woocommerce_afriex_settings';

	/**
	 * Setting ids whose values are never returned by the gateway REST API.
	 */
	private const SECRET_SETTING_PATTERN = '/api_key|secret|public_key|private_key|password|token/i';

	/**
	 * Orders read per query, and queries per run, for the order backfills.
	 * A store with more unpaid orders than this finishes over the next few
	 * requests instead of making one request slow.
	 */
	private const BATCH_SIZE  = 100;
	private const MAX_BATCHES = 5;

	private const LOCK = 'migration';

	/**
	 * Seconds before an abandoned migration lock may be taken over.
	 */
	private const LOCK_TTL = 120;

	/**
	 * Hook the version check, the REST mask and the notice. Called on
	 * plugins_loaded once the classes are loaded.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_rest_prepare_payment_gateway', array( __CLASS__, 'mask_rest_secrets' ), 10, 2 );
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . AFRIEX_WC_GATEWAY_ID, array( __CLASS__, 'strip_legacy_gateway_keys' ) );

		// The check happens now, on plugins_loaded, but the work waits for
		// init: order statuses are registered and translations may load only
		// from init on, and the backfill queries orders by status and writes
		// translated order notes. Priority 15 is after WooCommerce and Action
		// Scheduler have initialised and before Afriex_Scheduler's own check.
		if ( self::needs_run() ) {
			add_action( 'init', array( __CLASS__, 'maybe_run' ), 15 );
		}

		// A second chance in wp-admin, e.g. when another request held the
		// lock during init.
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_notice_dismissal' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * The version this code expects to find stored.
	 */
	public static function target_version(): string {
		return self::MIGRATION_VERSION . '.' . Afriex_Ledger::SCHEMA_VERSION;
	}

	/**
	 * Whether the stored data is older (or newer) than this code.
	 */
	public static function needs_run(): bool {
		return self::target_version() !== (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Run the migration when the stored version differs.
	 */
	public static function maybe_run(): void {
		if ( self::needs_run() ) {
			self::run();
		}
	}

	/**
	 * Run every step, and record the version when all of them finished.
	 *
	 * @return bool True when the migration is complete.
	 */
	public static function run(): bool {
		Afriex_Ledger::create_table();

		if ( ! Afriex_Ledger::table_exists() ) {
			Afriex_Logger::error( 'Migration stopped: the Afriex ledger table could not be created' );
			return false;
		}

		if ( ! Afriex_Ledger::lock( self::LOCK, 0, self::LOCK_TTL ) ) {
			return false;
		}

		try {
			self::migrate_account_settings();

			$complete = self::backfill_unpaid_orders();
			$complete = self::flag_legacy_pool_orders() && $complete;

			wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
			Afriex_Scheduler::ensure_scheduled();

			if ( $complete ) {
				update_option( self::VERSION_OPTION, self::target_version(), true );
				Afriex_Logger::info( 'Migration complete', array( 'version' => self::target_version() ) );
			}

			return $complete;
		} catch ( Throwable $e ) {
			// A failing migration must not take the site down with it. It is
			// retried on the next request, and the log says why.
			Afriex_Logger::error( 'Migration failed', array( 'error' => $e->getMessage() ) );
			return false;
		} finally {
			Afriex_Ledger::unlock( self::LOCK );
		}
	}

	/**
	 * Step 1: copy the 1.0 credentials into afriex_wc_account, then remove
	 * them from the gateway option (plan 12.1, F51).
	 *
	 * When afriex_wc_account already exists it is authoritative: it can only
	 * have been written by this migration or by a save of the new settings
	 * section, which was seeded from the 1.0 values. Copying again would undo
	 * an admin's later change, such as a cleared key.
	 *
	 * The 1.0 keys are removed only once afriex_wc_account exists, so a key
	 * is never deleted before it has been copied.
	 */
	private static function migrate_account_settings(): void {
		$legacy = get_option( self::LEGACY_GATEWAY_OPTION, null );
		$legacy = is_array( $legacy ) ? $legacy : array();

		if ( ! is_array( get_option( Afriex_Account_Settings::OPTION_KEY, null ) ) ) {
			// values() reads the 1.0 option while the new one is missing: the
			// key, 'staging' as 'sandbox', and the single webhook key in the
			// slot for that environment.
			$values = Afriex_Account_Settings::values();

			foreach ( array( 'webhook_public_key_production', 'webhook_public_key_sandbox' ) as $slot ) {
				$values[ $slot ] = Afriex_Account_Settings::normalise_pem( (string) ( $values[ $slot ] ?? '' ) );
			}

			// add_option() does nothing if a racing save created it first.
			if ( add_option( Afriex_Account_Settings::OPTION_KEY, $values, '', 'yes' ) ) {
				Afriex_Logger::info(
					'Copied the Afriex account settings from the 1.0 gateway settings',
					array(
						'environment' => $values['environment'],
						'has_api_key' => '' !== (string) $values['api_key'],
					)
				);
			}
		}

		if ( ! is_array( get_option( Afriex_Account_Settings::OPTION_KEY, null ) ) ) {
			return;
		}

		if ( 'pool' === ( $legacy['collection_method'] ?? '' ) ) {
			self::queue_notice( true, 0 );
		}

		$remaining = array_diff_key( $legacy, array_flip( self::LEGACY_GATEWAY_KEYS ) );

		if ( count( $remaining ) !== count( $legacy ) ) {
			update_option( self::LEGACY_GATEWAY_OPTION, $remaining );
			Afriex_Logger::info( 'Removed the 1.0 account keys from the gateway settings' );
		}
	}

	/**
	 * Step 2: record what each unpaid 1.0 Bank Transfer order expects (plan 12.2).
	 *
	 * 1.0 stored no expected amount, so settlement compared against whatever
	 * the order total was at the time of the webhook. The order total at
	 * migration time is the best record of what the account was issued for.
	 * The environment is the current one: 1.0 had a single environment.
	 *
	 * The expected currency doubles as the "done" marker for the query, so
	 * each order is written once, and a batch limit resumes where it stopped.
	 *
	 * @return bool True when no order is left to backfill.
	 */
	private static function backfill_unpaid_orders(): bool {
		$environment = Afriex_Account_Settings::environment();

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$ids = Afriex_Order_Meta::query_orders(
				array(
					'limit'          => self::BATCH_SIZE,
					'return'         => 'ids',
					'payment_method' => AFRIEX_WC_GATEWAY_ID,
					'status'         => array( 'on-hold', 'pending' ),
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				array(
					array(
						'key'     => Afriex_Order_Meta::KEY_EXPECTED_CURRENCY,
						'compare' => 'NOT EXISTS',
					),
				)
			);

			if ( empty( $ids ) ) {
				return true;
			}

			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );

				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$currency = strtoupper( (string) $order->get_currency() );
				$minor    = Afriex_Money::to_minor( (string) $order->get_total(), $currency );

				$order->update_meta_data( Afriex_Order_Meta::KEY_EXPECTED_AMOUNT_MINOR, null === $minor ? '' : (string) $minor );
				$order->update_meta_data( Afriex_Order_Meta::KEY_EXPECTED_CURRENCY, $currency );

				if ( '' === (string) $order->get_meta( Afriex_Order_Meta::KEY_ENVIRONMENT ) ) {
					$order->update_meta_data( Afriex_Order_Meta::KEY_ENVIRONMENT, $environment );
				}

				// A total that cannot be expressed in minor units can never
				// match a payment automatically; say so instead of guessing.
				if ( null === $minor && '' === (string) $order->get_meta( Afriex_Order_Meta::KEY_NEEDS_REVIEW ) ) {
					$order->update_meta_data( Afriex_Order_Meta::KEY_NEEDS_REVIEW, 'amount_unreadable' );
				}

				$order->save();
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Step 3: flag unpaid orders that used a 1.0 pool account (plan 12.3).
	 *
	 * A pool account is shared by many orders, so a deposit into it cannot be
	 * tied to one order by the account id alone (F1, F24, F26). These orders
	 * are marked for review; the settlement engine settles them only on an
	 * exact pool id, customer and reference match (plan 6.2).
	 *
	 * Paid orders are left alone: they are settled already, and a flag on
	 * them would only fill the review list with finished orders. Failed ones
	 * are included because a payment can still arrive for them.
	 *
	 * @return bool True when no order is left to flag.
	 */
	private static function flag_legacy_pool_orders(): bool {
		$flagged = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$ids = Afriex_Order_Meta::query_orders(
				array(
					'limit'          => self::BATCH_SIZE,
					'return'         => 'ids',
					'payment_method' => AFRIEX_WC_GATEWAY_ID,
					'status'         => array( 'on-hold', 'pending', 'failed' ),
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				array(
					'relation' => 'AND',
					array(
						'key'   => Afriex_Order_Meta::KEY_COLLECTION_METHOD,
						'value' => 'pool',
					),
					array(
						'key'     => Afriex_Order_Meta::KEY_NEEDS_REVIEW,
						'compare' => 'NOT EXISTS',
					),
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );

				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$order->update_meta_data( Afriex_Order_Meta::KEY_NEEDS_REVIEW, 'legacy_pool' );
				$order->save();
				$order->add_order_note(
					__( 'Afriex 1.1: this order was placed with a shared (pool) account, which is no longer supported. A payment to it is not confirmed automatically. Check your Afriex dashboard, then update this order by hand.', 'afriex-gateway-for-woocommerce' )
				);

				++$flagged;
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				break;
			}
		}

		if ( $flagged > 0 ) {
			self::queue_notice( false, $flagged );
			Afriex_Logger::info( 'Flagged legacy pool orders for review', array( 'orders' => $flagged ) );
		}

		return $flagged < self::BATCH_SIZE * self::MAX_BATCHES;
	}

	/**
	 * Remember what the one-time pool notice has to say.
	 *
	 * @param bool $store_was_pool Whether the store was set to pool mode.
	 * @param int  $orders         Orders newly flagged.
	 */
	private static function queue_notice( bool $store_was_pool, int $orders ): void {
		$notice = get_option( self::NOTICE_OPTION, array() );
		$notice = is_array( $notice ) ? $notice : array();

		$notice['store']  = ! empty( $notice['store'] ) || $store_was_pool;
		$notice['orders'] = (int) ( $notice['orders'] ?? 0 ) + $orders;

		update_option( self::NOTICE_OPTION, $notice, false );
	}

	/**
	 * The one-time notice after the pool-mode switch, until dismissed.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = get_option( self::NOTICE_OPTION, null );

		if ( ! is_array( $notice ) ) {
			return;
		}

		$parts = array();

		if ( ! empty( $notice['store'] ) ) {
			$parts[] = __( 'Afriex Bank Transfer no longer uses a shared (pool) account. Your store was set to use one, and now gives each order its own virtual account. You do not need to change anything.', 'afriex-gateway-for-woocommerce' );
		}

		$orders = (int) ( $notice['orders'] ?? 0 );

		if ( $orders > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d unpaid order placed with a shared account is marked for review. A payment to a shared account cannot be matched to its order automatically, so check it in your Afriex dashboard and update the order by hand.',
					'%d unpaid orders placed with a shared account are marked for review. A payment to a shared account cannot be matched to its order automatically, so check them in your Afriex dashboard and update the orders by hand.',
					$orders,
					'afriex-gateway-for-woocommerce'
				),
				$orders
			);
		}

		if ( empty( $parts ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'afriex_wc_dismiss', 'pool' ), 'afriex_wc_dismiss_pool' );

		printf(
			'<div class="notice notice-warning afriex-pool-notice"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			esc_html( implode( ' ', $parts ) ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'afriex-gateway-for-woocommerce' )
		);
	}

	/**
	 * Dismiss the pool notice from its nonce-protected link.
	 */
	public static function handle_notice_dismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked below, before anything changes.
		if ( ! isset( $_GET['afriex_wc_dismiss'] ) || 'pool' !== sanitize_key( wp_unslash( $_GET['afriex_wc_dismiss'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'afriex_wc_dismiss_pool' );

		delete_option( self::NOTICE_OPTION );

		wp_safe_redirect( remove_query_arg( array( 'afriex_wc_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Never return an Afriex secret through the gateway REST API (F51).
	 *
	 * The secrets are no longer gateway fields, so WooCommerce does not list
	 * them today. This is the second line: any setting of an Afriex gateway
	 * whose id or type looks secret has its value blanked, whatever a later
	 * change or another plugin adds to the form fields.
	 *
	 * @param WP_REST_Response|mixed $response Response.
	 * @param WC_Payment_Gateway     $gateway  Gateway.
	 * @return WP_REST_Response|mixed
	 */
	public static function mask_rest_secrets( $response, $gateway ) {
		if ( ! $response instanceof WP_REST_Response || ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
			return $response;
		}

		if ( ! in_array( (string) $gateway->id, Afriex_Payment_Gateway::GATEWAY_IDS, true ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $response;
		}

		foreach ( $data['settings'] as $id => $setting ) {
			$type = is_array( $setting ) ? (string) ( $setting['type'] ?? '' ) : '';

			if ( preg_match( self::SECRET_SETTING_PATTERN, (string) $id ) || in_array( $type, array( 'password', 'afriex_secret' ), true ) ) {
				$data['settings'][ $id ]['value'] = '';
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Keep the removed 1.0 keys out of the Bank Transfer option on every save.
	 *
	 * WooCommerce writes the whole stored settings array back on each save,
	 * including the REST update path. Once afriex_wc_account holds the
	 * credentials, nothing may put the old copies back. Before it exists they
	 * are left alone, so a save can never drop a key that was not yet copied.
	 *
	 * @param array|mixed $settings Sanitised settings.
	 * @return array|mixed
	 */
	public static function strip_legacy_gateway_keys( $settings ) {
		if ( ! is_array( $settings ) || ! is_array( get_option( Afriex_Account_Settings::OPTION_KEY, null ) ) ) {
			return $settings;
		}

		return array_diff_key( $settings, array_flip( self::LEGACY_GATEWAY_KEYS ) );
	}
}
