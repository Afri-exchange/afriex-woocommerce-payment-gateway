<?php
/**
 * Thin wrapper over WC_Logger.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under the "afriex" source, so entries show up
 * in WooCommerce > Status > Logs where merchants and support already look.
 */
class Afriex_Logger {

	private const SOURCE = 'afriex';

	/**
	 * Cached logger instance.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Whether debug logging is switched on in the gateway settings.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Reset the cached enabled flag. Called when settings are saved so a
	 * merchant toggling logging does not have to wait for the next request.
	 */
	public static function reset(): void {
		self::$enabled = null;
	}

	private static function log( string $level, string $message, array $context ): void {
		// Errors and warnings always land: they are what a support ticket needs.
		// Info and debug respect the merchant's logging preference.
		if ( in_array( $level, array( 'info', 'debug' ), true ) && ! self::is_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		self::$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	private static function is_enabled(): bool {
		if ( null === self::$enabled ) {
			// The switch lives in the shared account settings, which fall back
			// to the 1.0 gateway option until the migration has run.
			self::$enabled = class_exists( 'Afriex_Account_Settings' ) && Afriex_Account_Settings::logging_enabled();
		}

		return self::$enabled;
	}

	/**
	 * Strip anything that should never reach a log file.
	 *
	 * Logs are readable by any shop manager and are routinely pasted into
	 * support tickets, so credentials and full account numbers are masked at
	 * the point of writing rather than trusted not to be passed in.
	 */
	private static function redact( array $context ): array {
		$secret_keys = array( 'api_key', 'x-api-key', 'webhook_public_key', 'signature', 'authorization' );
		$masked_keys = array( 'account_number', 'accountNumber', 'phone', 'email' );

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $secret_keys, true ) ) {
				$context[ $key ] = '[redacted]';
				continue;
			}

			if ( in_array( (string) $key, $masked_keys, true ) && is_scalar( $value ) ) {
				$context[ $key ] = self::mask( (string) $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}

		return $context;
	}

	private static function mask( string $value ): string {
		$length = strlen( $value );

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - 4 ) . substr( $value, -4 );
	}
}
