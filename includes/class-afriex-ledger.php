<?php
/**
 * Atomic claims, locks and throttles in a plugin-owned table.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The {$wpdb->prefix}afriex_ledger table and everything that must be atomic.
 *
 * 1.0 deduplicated webhooks with get_transient() then set_transient(), which is
 * a read-then-write race, and is not shared between nodes when a persistent
 * object cache holds transients (F16). Nothing stopped one transaction from
 * settling two orders (F23). Both need a primitive that exactly one caller can
 * win, on every host WordPress runs on.
 *
 * The primitive is a UNIQUE (kind, item_key) row:
 * - A claim is INSERT IGNORE; the caller that gets exactly one affected row
 *   wins. The database, not PHP, arbitrates.
 * - A row whose expiry has passed can be taken over with
 *   UPDATE ... WHERE kind = %s AND item_key = %s AND expires_at < now, again
 *   won by whoever gets the one affected row.
 * - No options, transients or object cache are involved, so a persistent cache
 *   cannot hand two processes a stale "not claimed" answer.
 * - No GET_LOCK(): it is per connection, does not replicate on Galera, and the
 *   SQLite drop-in has none. Polling a row works everywhere.
 *
 * Time: every expires_at and created_at is UTC, formatted 'Y-m-d H:i:s' by PHP
 * (gmdate()) and passed as a query parameter. Database clock functions are
 * avoided on purpose: NOW() follows the connection's time zone, and
 * UTC_TIMESTAMP()/DATE_ADD() are not reliably translated by the SQLite
 * drop-in. Comparing a column with a bound UTC string is portable, and the
 * format sorts lexically, so expiry checks work the same in SQL and in PHP.
 * Web nodes are assumed to keep NTP time; the shortest TTL used (15 s) is far
 * above normal skew.
 *
 * Kinds: 'settled' (a transaction's owner order; never expires), 'event'
 * (webhook deliveries), 'lock' (order and migration locks) and 'throttle'
 * (rate limits).
 */
class Afriex_Ledger {

	public const KIND_SETTLED  = 'settled';
	public const KIND_EVENT    = 'event';
	public const KIND_LOCK     = 'lock';
	public const KIND_THROTTLE = 'throttle';

	/**
	 * Schema version of the table, independent of the plugin version. Bump it
	 * when the CREATE TABLE statement changes so the version check re-runs
	 * dbDelta().
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Lock poll interval, in microseconds (100 ms, plan section 6.1).
	 */
	private const POLL_INTERVAL_US = 100000;

	/**
	 * Longest item key stored. The UNIQUE index covers kind + item_key, and
	 * 191 characters keeps it inside InnoDB's 767-byte index limit for
	 * utf8mb4 on older MySQL.
	 */
	private const MAX_KEY_LENGTH = 191;

	/**
	 * Database handle. Null means the global $wpdb.
	 *
	 * @var wpdb|object|null
	 */
	private static $db = null;

	/**
	 * Locks held by this process: key => array( token, depth ).
	 *
	 * The token proves ownership, so unlock() never deletes a lock another
	 * process took over after ours expired. The depth makes the lock
	 * re-entrant within one request: settle() called from inside
	 * process_payment() must not deadlock on, or release, the outer lock.
	 *
	 * @var array<string, array>
	 */
	private static $held = array();

	/**
	 * Tokens of claims made by this process, keyed "kind|key".
	 *
	 * @var array<string, string>
	 */
	private static $claims = array();

	/**
	 * Whether this request already tried to create a missing table.
	 *
	 * @var bool
	 */
	private static $repaired = false;

	/**
	 * Inject a database handle. Tests pass a fake; production never calls this.
	 *
	 * @param object|null $db Object with prepare(), query(), get_row() and
	 *                        get_var() like wpdb, and a $prefix property.
	 */
	public static function set_db( $db ): void {
		self::$db       = $db;
		self::$held     = array();
		self::$claims   = array();
		self::$repaired = false;
	}

	/**
	 * Full table name for the current site.
	 */
	public static function table(): string {
		return self::db()->prefix . 'afriex_ledger';
	}

	/**
	 * Current UTC time, or UTC time $seconds from now, as 'Y-m-d H:i:s'.
	 *
	 * @param int $seconds Offset in seconds.
	 */
	public static function utc( int $seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	/**
	 * Create or upgrade the table with dbDelta().
	 *
	 * Safe to call repeatedly and concurrently: dbDelta() only issues
	 * CREATE TABLE when the table is missing, and an "already exists" error from
	 * a racing request changes nothing.
	 */
	public static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = $wpdb->prefix . 'afriex_ledger';
		$collate = $wpdb->get_charset_collate();

		// dbDelta() parses this text: one column per line, no backticks, KEY
		// rather than INDEX, and two spaces after PRIMARY KEY. Deviating makes
		// it re-issue ALTERs on every run or skip the index entirely.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL auto_increment,
  kind varchar(20) NOT NULL,
  item_key varchar(191) NOT NULL,
  order_id bigint(20) unsigned NOT NULL default 0,
  state varchar(20) NOT NULL default '',
  token char(32) NOT NULL default '',
  hits int(10) unsigned NOT NULL default 0,
  expires_at datetime NULL default NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_item (kind,item_key),
  KEY expires_at (expires_at),
  KEY order_id (order_id)
) {$collate};";

		$suppress = $wpdb->suppress_errors( true );
		dbDelta( $sql );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Whether the table exists on this site.
	 */
	public static function table_exists(): bool {
		$db    = self::db();
		$table = self::table();

		return $table === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', self::escape_like( $table ) ) );
	}

	/**
	 * Try to claim (kind, key). Exactly one concurrent caller gets true.
	 *
	 * @param string   $kind     One of the KIND_* constants.
	 * @param string   $key      Item key, e.g. a transaction id.
	 * @param int      $order_id Order the claim belongs to, 0 for none.
	 * @param string   $state    Free-form state, e.g. 'processing'.
	 * @param int|null $ttl      Seconds until the claim may be taken over, or
	 *                           null for a claim that never expires.
	 */
	public static function claim( string $kind, string $key, int $order_id = 0, string $state = '', ?int $ttl = null ): bool {
		$key = self::normalise_key( $key );

		if ( '' === $key || '' === $kind ) {
			return false;
		}

		$token    = self::new_token();
		$affected = self::insert_row( $kind, $key, $order_id, $state, $ttl, $token );

		if ( false === $affected && self::repair() ) {
			$affected = self::insert_row( $kind, $key, $order_id, $state, $ttl, $token );
		}

		if ( ! self::won( $affected ) ) {
			$affected = self::take_over( $kind, $key, $order_id, $state, $ttl, $token );
		}

		if ( ! self::won( $affected ) ) {
			return false;
		}

		self::$claims[ $kind . '|' . $key ] = $token;

		return true;
	}

	/**
	 * The current holder of (kind, key), read straight from the table.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Item key.
	 * @return array|null order_id, state, expires_at, created_at, expired (bool),
	 *                    or null when nobody holds it.
	 */
	public static function holder( string $kind, string $key ): ?array {
		$db  = self::db();
		$key = self::normalise_key( $key );

		if ( '' === $key ) {
			return null;
		}

		$table = self::table();
		$row   = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"SELECT order_id, state, expires_at, created_at FROM {$table} WHERE kind = %s AND item_key = %s",
				$kind,
				$key
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$expires_at = null === $row['expires_at'] ? null : (string) $row['expires_at'];

		return array(
			'order_id'   => (int) $row['order_id'],
			'state'      => (string) $row['state'],
			'expires_at' => $expires_at,
			'created_at' => (string) $row['created_at'],
			'expired'    => null !== $expires_at && $expires_at < self::utc(),
		);
	}

	/**
	 * Change the state (and expiry) of a claim this process holds.
	 *
	 * Used to move a webhook event from 'processing' (short TTL) to 'done'
	 * (long TTL) without ever releasing it in between.
	 *
	 * @param string   $kind  Kind.
	 * @param string   $key   Item key.
	 * @param string   $state New state.
	 * @param int|null $ttl   New TTL in seconds, or null to make it permanent.
	 * @return bool False when the claim is not ours any more.
	 */
	public static function set_state( string $kind, string $key, string $state, ?int $ttl = null ): bool {
		$db    = self::db();
		$key   = self::normalise_key( $key );
		$token = self::$claims[ $kind . '|' . $key ] ?? '';

		if ( '' === $key || '' === $token ) {
			return false;
		}

		$table = self::table();

		if ( null === $ttl ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$sql = $db->prepare( "UPDATE {$table} SET state = %s, expires_at = NULL WHERE kind = %s AND item_key = %s AND token = %s", $state, $kind, $key, $token );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$sql = $db->prepare( "UPDATE {$table} SET state = %s, expires_at = %s WHERE kind = %s AND item_key = %s AND token = %s", $state, self::utc( max( 1, $ttl ) ), $kind, $key, $token );
		}

		// MySQL reports zero affected rows when nothing changed, so only false
		// is a failure; ownership is then confirmed by reading the token back.
		if ( false === self::write( $sql ) ) {
			return false;
		}

		$holder = $db->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$db->prepare( "SELECT token FROM {$table} WHERE kind = %s AND item_key = %s", $kind, $key )
		);

		return $holder === $token;
	}

	/**
	 * Release a claim.
	 *
	 * When this process made the claim, only a row still carrying its token is
	 * deleted, so a claim that expired and was taken over by someone else is
	 * left alone. Without a token (a claim made by another request), the row
	 * is deleted outright: that is an administrative release.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Item key.
	 */
	public static function release( string $kind, string $key ): bool {
		$db    = self::db();
		$key   = self::normalise_key( $key );
		$table = self::table();

		if ( '' === $key ) {
			return false;
		}

		$token = self::$claims[ $kind . '|' . $key ] ?? '';
		unset( self::$claims[ $kind . '|' . $key ] );

		if ( '' !== $token ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$sql = $db->prepare( "DELETE FROM {$table} WHERE kind = %s AND item_key = %s AND token = %s", $kind, $key, $token );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$sql = $db->prepare( "DELETE FROM {$table} WHERE kind = %s AND item_key = %s", $kind, $key );
		}

		return 1 === self::write( $sql );
	}

	/**
	 * Take a named lock, waiting up to $timeout_seconds for it.
	 *
	 * The lock is a 'lock' row with a TTL, so a request that dies holding it
	 * blocks others for at most $ttl seconds. Waiting polls every 100 ms.
	 * Re-entrant within one request.
	 *
	 * The re-entrant path re-reads the row rather than trusting the in-memory
	 * depth counter. A caller that holds the lock across slow work — the
	 * sweep holds it across Afriex calls — can have had it taken over in the
	 * meantime, and the inner call is the last chance to notice before the
	 * mutating section runs. Assuming it is still held is how two processes
	 * end up inside one order's critical section at once. One indexed SELECT
	 * is a cheap price for that.
	 *
	 * @param string $key             Lock name, e.g. "order:123".
	 * @param float  $timeout_seconds Seconds to wait; 0 tries once.
	 * @param int    $ttl             Seconds before an abandoned lock may be
	 *                                taken over.
	 */
	public static function lock( string $key, float $timeout_seconds = 0.0, int $ttl = 30 ): bool {
		$key = self::normalise_key( $key );

		if ( '' === $key ) {
			return false;
		}

		if ( isset( self::$held[ $key ] ) ) {
			if ( ! self::still_holds( $key ) ) {
				return false;
			}

			++self::$held[ $key ][1];
			return true;
		}

		$deadline = microtime( true ) + max( 0.0, $timeout_seconds );

		while ( true ) {
			if ( self::claim( self::KIND_LOCK, $key, 0, 'locked', max( 1, $ttl ) ) ) {
				self::$held[ $key ] = array( self::$claims[ self::KIND_LOCK . '|' . $key ], 1 );
				unset( self::$claims[ self::KIND_LOCK . '|' . $key ] );
				return true;
			}

			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0 ) {
				return false;
			}

			usleep( (int) min( self::POLL_INTERVAL_US, $remaining * 1000000 ) );
		}
	}

	/**
	 * Release a lock taken by lock() in this request.
	 *
	 * @param string $key Lock name.
	 * @return bool False when this request did not hold it, or it had expired
	 *              and been taken over.
	 */
	public static function unlock( string $key ): bool {
		$key = self::normalise_key( $key );

		if ( ! isset( self::$held[ $key ] ) ) {
			return false;
		}

		if ( --self::$held[ $key ][1] > 0 ) {
			return true;
		}

		$token = self::$held[ $key ][0];
		unset( self::$held[ $key ] );

		$db    = self::db();
		$table = self::table();

		return 1 === self::write(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$db->prepare( "DELETE FROM {$table} WHERE kind = %s AND item_key = %s AND token = %s", self::KIND_LOCK, $key, $token )
		);
	}

	/**
	 * Whether this request currently holds a lock.
	 *
	 * @param string $key Lock name.
	 */
	public static function holds_lock( string $key ): bool {
		return isset( self::$held[ self::normalise_key( $key ) ] );
	}

	/**
	 * Whether the lock row for a key is still the one this request took.
	 *
	 * Compares the stored token, so a row that expired and was taken over by
	 * another process reads as not held even though it exists.
	 *
	 * @param string $key Already-normalised lock name.
	 */
	private static function still_holds( string $key ): bool {
		if ( ! isset( self::$held[ $key ] ) ) {
			return false;
		}

		$db    = self::db();
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ledger table, deliberately uncached; the table name is built from $wpdb->prefix.
		$row = $db->get_row( $db->prepare( "SELECT token, expires_at FROM {$table} WHERE kind = %s AND item_key = %s", self::KIND_LOCK, $key ), ARRAY_A );

		if ( ! is_array( $row ) || ! hash_equals( (string) self::$held[ $key ][0], (string) $row['token'] ) ) {
			return false;
		}

		// An expired row is still ours only until somebody takes it over, so
		// it is not something to build a critical section on.
		return null === $row['expires_at'] || (string) $row['expires_at'] >= self::utc();
	}

	/**
	 * Atomic rate limit: allow at most $limit calls per $ttl-second window.
	 *
	 * Returns true when the caller may proceed. Three statements, each atomic on
	 * its own, and the decision is the affected-row count of the last one:
	 * 1. INSERT IGNORE creates the window row with zero hits.
	 * 2. An expired window is reset to zero hits and a fresh expiry; only one
	 *    racing caller can match "expires_at < now", because the first moves it.
	 * 3. hits = hits + 1 only WHERE hits < limit and the window is current.
	 * Two callers can never both take the last slot, because statement 3 is
	 * one conditional UPDATE.
	 *
	 * With the default $limit of 1 this is the per-order marker of plan 5.4:
	 * at most one upstream check per window.
	 *
	 * @param string $key   Throttle name, e.g. "check:order:123".
	 * @param int    $ttl   Window length in seconds.
	 * @param int    $limit Calls allowed per window.
	 */
	public static function throttle( string $key, int $ttl, int $limit = 1 ): bool {
		$db    = self::db();
		$key   = self::normalise_key( $key );
		$table = self::table();
		$ttl   = max( 1, $ttl );
		$limit = max( 1, $limit );

		if ( '' === $key ) {
			return false;
		}

		$created = self::insert_row( self::KIND_THROTTLE, $key, 0, '', $ttl, self::new_token() );

		if ( false === $created && self::repair() ) {
			self::insert_row( self::KIND_THROTTLE, $key, 0, '', $ttl, self::new_token() );
		}

		$now = self::utc();

		self::write(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"UPDATE {$table} SET hits = 0, expires_at = %s, created_at = %s WHERE kind = %s AND item_key = %s AND expires_at < %s",
				self::utc( $ttl ),
				$now,
				self::KIND_THROTTLE,
				$key,
				$now
			)
		);

		$affected = self::write(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"UPDATE {$table} SET hits = hits + 1 WHERE kind = %s AND item_key = %s AND hits < %d AND expires_at >= %s",
				self::KIND_THROTTLE,
				$key,
				$limit,
				$now
			)
		);

		return self::won( $affected );
	}

	/**
	 * Site-wide rate limit on upstream Afriex calls (plan 5.4: at most 30
	 * checks per minute across every order).
	 *
	 * A separate helper so every caller uses the same key namespace and the
	 * limit is filterable in one place.
	 *
	 * @param string $name   Budget name, e.g. 'checks'.
	 * @param int    $limit  Calls allowed per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function global_rate( string $name = 'checks', int $limit = 30, int $window = 60 ): bool {
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the site-wide cap on upstream Afriex checks per window.
			 *
			 * @param int    $limit  Calls allowed per window.
			 * @param string $name   Budget name.
			 * @param int    $window Window length in seconds.
			 */
			$limit = (int) apply_filters( 'afriex_wc_global_rate_limit', $limit, $name, $window );
		}

		return self::throttle( 'global:' . $name, $window, $limit );
	}

	/**
	 * Delete expired lock, throttle and event rows.
	 *
	 * Settled rows never expire and are never purged: they are the record that
	 * a transaction has already paid for an order.
	 *
	 * @param int $limit Maximum rows to delete in one call.
	 * @return int Rows deleted.
	 */
	public static function purge_expired( int $limit = 500 ): int {
		$db    = self::db();
		$table = self::table();

		$deleted = self::write(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"DELETE FROM {$table} WHERE kind <> %s AND expires_at IS NOT NULL AND expires_at < %s LIMIT %d",
				self::KIND_SETTLED,
				self::utc(),
				max( 1, $limit )
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Drop the table. Uninstall only.
	 */
	public static function drop_table(): void {
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is built from $wpdb->prefix; uninstall drops the plugin's own table.
		self::write( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Whether an affected-row count means this caller won.
	 *
	 * Exactly one row: anything else (0 for a lost race, false for an error)
	 * is a loss.
	 *
	 * @param int|bool $affected Return value of wpdb::query().
	 */
	public static function won( $affected ): bool {
		return 1 === $affected;
	}

	/**
	 * Keys longer than the column are hashed rather than truncated, so two long
	 * keys that share a prefix can never collide.
	 *
	 * @param string $key Raw key.
	 */
	public static function normalise_key( string $key ): string {
		$key = trim( $key );

		if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
			return 'sha256:' . hash( 'sha256', $key );
		}

		return $key;
	}

	/**
	 * INSERT IGNORE one row; a duplicate (kind, item_key) affects zero rows.
	 *
	 * @param string   $kind     Kind.
	 * @param string   $key      Normalised key.
	 * @param int      $order_id Order id.
	 * @param string   $state    State.
	 * @param int|null $ttl      TTL or null.
	 * @param string   $token    Ownership token.
	 * @return int|bool Affected rows, or false on a database error.
	 */
	private static function insert_row( string $kind, string $key, int $order_id, string $state, ?int $ttl, string $token ) {
		$db    = self::db();
		$table = self::table();

		if ( null === $ttl ) {
			return self::write(
				$db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
					"INSERT IGNORE INTO {$table} ( kind, item_key, order_id, state, token, expires_at, created_at ) VALUES ( %s, %s, %d, %s, %s, NULL, %s )",
					$kind,
					$key,
					$order_id,
					$state,
					$token,
					self::utc()
				)
			);
		}

		return self::write(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"INSERT IGNORE INTO {$table} ( kind, item_key, order_id, state, token, expires_at, created_at ) VALUES ( %s, %s, %d, %s, %s, %s, %s )",
				$kind,
				$key,
				$order_id,
				$state,
				$token,
				self::utc( max( 1, $ttl ) ),
				self::utc()
			)
		);
	}

	/**
	 * Take over an expired row. Rows without an expiry are never taken over.
	 *
	 * The new token always differs from the old one, so a successful takeover
	 * always reports one affected row.
	 *
	 * @param string   $kind     Kind.
	 * @param string   $key      Normalised key.
	 * @param int      $order_id Order id.
	 * @param string   $state    State.
	 * @param int|null $ttl      TTL or null.
	 * @param string   $token    Ownership token.
	 * @return int|bool Affected rows, or false on a database error.
	 */
	private static function take_over( string $kind, string $key, int $order_id, string $state, ?int $ttl, string $token ) {
		$db    = self::db();
		$table = self::table();
		$now   = self::utc();

		if ( null === $ttl ) {
			return self::write(
				$db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
					"UPDATE {$table} SET order_id = %d, state = %s, token = %s, hits = 0, expires_at = NULL, created_at = %s WHERE kind = %s AND item_key = %s AND expires_at IS NOT NULL AND expires_at < %s",
					$order_id,
					$state,
					$token,
					$now,
					$kind,
					$key,
					$now
				)
			);
		}

		return self::write(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
				"UPDATE {$table} SET order_id = %d, state = %s, token = %s, hits = 0, expires_at = %s, created_at = %s WHERE kind = %s AND item_key = %s AND expires_at IS NOT NULL AND expires_at < %s",
				$order_id,
				$state,
				$token,
				self::utc( max( 1, $ttl ) ),
				$now,
				$kind,
				$key,
				$now
			)
		);
	}

	/**
	 * Run one write statement, retrying when InnoDB picks it as a deadlock
	 * victim.
	 *
	 * INSERT IGNORE on a unique key takes a shared lock on the duplicate row;
	 * when the holder deletes that row (unlock, release) while several
	 * requests poll for it, InnoDB can resolve the gap-lock cycle by rolling
	 * one INSERT back with "Deadlock found". The statement had no effect, so
	 * retrying it is safe, and without a retry the victim would wrongly report
	 * a lost claim even though nobody holds the key. Errors are suppressed
	 * during the attempt so an expected deadlock does not flood the PHP error
	 * log; any other failure is logged once, to the WooCommerce log.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int|bool wpdb::query() result.
	 */
	private static function write( string $sql ) {
		$db       = self::db();
		$attempts = 3;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$suppress = method_exists( $db, 'suppress_errors' ) ? $db->suppress_errors( true ) : null;
			$result   = $db->query( $sql );

			if ( null !== $suppress ) {
				$db->suppress_errors( $suppress );
			}

			if ( false !== $result ) {
				return $result;
			}

			$error = isset( $db->last_error ) ? (string) $db->last_error : '';

			if ( $attempt < $attempts && 1 === preg_match( '/deadlock|lock wait timeout/i', $error ) ) {
				usleep( random_int( 5000, 25000 ) );
				continue;
			}

			// A missing table is repaired by the caller; anything else is a
			// real fault the site owner needs to see.
			if ( '' !== $error && 1 !== preg_match( "/doesn't exist|no such table/i", $error ) ) {
				Afriex_Logger::error( 'Afriex ledger database error', array( 'error' => $error ) );
			}

			return false;
		}

		return false;
	}

	/**
	 * Create the table once per request if a write failed because it is
	 * missing, e.g. after a site was restored from a backup without it.
	 */
	private static function repair(): bool {
		if ( self::$repaired || null !== self::$db ) {
			return false;
		}

		self::$repaired = true;

		if ( self::table_exists() ) {
			return false;
		}

		self::create_table();

		return self::table_exists();
	}

	/**
	 * A random ownership token.
	 */
	private static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Escape text for literal use in a LIKE pattern.
	 *
	 * @param string $text Text.
	 */
	private static function escape_like( string $text ): string {
		$db = self::db();

		return method_exists( $db, 'esc_like' ) ? $db->esc_like( $text ) : addcslashes( $text, '_%\\' );
	}

	/**
	 * The database handle.
	 *
	 * @return wpdb|object
	 */
	private static function db() {
		if ( null !== self::$db ) {
			return self::$db;
		}

		global $wpdb;

		return $wpdb;
	}
}
