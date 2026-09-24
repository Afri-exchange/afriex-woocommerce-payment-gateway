<?php
/**
 * The rules for reusing an Afriex customer, with no WordPress in them.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What to do with a customer payload, and with a 409 from POST /customer.
 *
 * Afriex refuses a second customer with the same email or phone and hands the
 * existing id back in `details.data.customerId` (F25). Deciding what that means
 * is a rule about values — a code, an id and two email addresses — so it lives
 * here and can be unit tested without WooCommerce, exactly as
 * Afriex_Checkout_Fields does for the session request.
 *
 * The gateway does the talking: it looks customers up, adopts ids and writes
 * meta. This class only answers questions.
 */
class Afriex_Customer {

	/**
	 * The conflicting id may be adopted as it stands.
	 */
	public const ADOPT = 'adopt';

	/**
	 * The conflicting id may be adopted only if the customer it names turns
	 * out to have the order's email address.
	 */
	public const VERIFY_EMAIL = 'verify_email';

	/**
	 * Nothing here can be adopted.
	 */
	public const REFUSE = 'refuse';

	/**
	 * Keys Afriex has been seen to carry a customer id under, in the order
	 * they are trusted.
	 */
	private const ID_KEYS = array( 'customerId', 'id', '_id' );

	/**
	 * The customer id in a payload, or '' when there is none.
	 *
	 * The same shape comes back from three places — the 201 of a create, a row
	 * of GET /customer, and `details.data` of a 409 — so one reader serves all
	 * three.
	 *
	 * @param mixed $payload Anything Afriex returned.
	 */
	public static function extract_id( $payload ): string {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		foreach ( self::ID_KEYS as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
				return trim( (string) $payload[ $key ] );
			}
		}

		return '';
	}

	/**
	 * What a 409 code allows.
	 *
	 * - `EMAIL_ALREADY_EXISTS` means the id belongs to this email address, so
	 *   it is this shopper's own record and is adopted (plan 9, F25).
	 * - `PHONE_NUMBER_ALREADY_EXISTS` means only that somebody already uses
	 *   that phone number. Adopting it unchecked would hand this shopper
	 *   another person's Afriex customer, so the email has to be confirmed
	 *   first.
	 * - Anything else is not a conflict this plugin understands.
	 *
	 * @param string $code Afriex error code.
	 */
	public static function conflict_decision( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( 'EMAIL_ALREADY_EXISTS' === $code ) {
			return self::ADOPT;
		}

		if ( 'PHONE_NUMBER_ALREADY_EXISTS' === $code ) {
			return self::VERIFY_EMAIL;
		}

		return self::REFUSE;
	}

	/**
	 * Whether two email addresses are the same address.
	 *
	 * Case-insensitive, because the local part is case-sensitive only in
	 * theory and no mail provider in this plugin's markets treats it that way;
	 * Afriex's own duplicate check is case-insensitive too.
	 *
	 * @param string $a One address.
	 * @param string $b The other.
	 */
	public static function same_email( string $a, string $b ): bool {
		$a = strtolower( trim( $a ) );
		$b = strtolower( trim( $b ) );

		return '' !== $a && $a === $b;
	}

	/**
	 * Whether a customer record found by look-up may be used for this email.
	 *
	 * The id must be there and the record must carry the address that was
	 * searched for. A record with no email at all is refused rather than
	 * trusted: Afriex does not document the filter as an exact match, and a
	 * partial match would attach this order to somebody else's customer.
	 *
	 * @param mixed  $customer A row from GET /customer.
	 * @param string $email    The order's email address.
	 */
	public static function matches_email( $customer, string $email ): bool {
		if ( ! is_array( $customer ) || '' === self::extract_id( $customer ) ) {
			return false;
		}

		return self::same_email( (string) ( $customer['email'] ?? '' ), $email );
	}
}
