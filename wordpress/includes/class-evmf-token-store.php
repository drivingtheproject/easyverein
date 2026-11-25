<?php
/**
 * Storage for the easyVerein API token.
 *
 * The token lives in its own, non-autoloaded option and is NOT part of the settings
 * form. If it were a regular settings field, the settings page would still contain
 * the old token after an automatic refresh, and the next "Save Settings" would write
 * the already invalidated token back to the database.
 *
 * Status information (last error, last success, ...) is kept in a second option so
 * that status updates from one request can never overwrite a token that another
 * request has just refreshed (lost update).
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token persistence, status tracking and a cross-process refresh lock.
 */
final class EVMF_Token_Store {

	const OPTION        = 'evmf_token';
	const STATUS_OPTION = 'evmf_token_status';
	const LOCK_OPTION   = 'evmf_token_refresh_lock';
	const LOCK_TIMEOUT  = 60; // Seconds after which a lock is considered stale.

	/** easyVerein tokens are valid for 30 days from issue. */
	const TOKEN_LIFETIME = 2592000; // 30 * DAY_IN_SECONDS.

	const STATUS_UNKNOWN        = 'unknown';
	const STATUS_OK             = 'ok';
	const STATUS_INVALID        = 'invalid';
	const STATUS_REFRESH_FAILED = 'refresh_failed';

	/**
	 * Token record: token + when it was issued. Only written by an admin or under the refresh lock.
	 *
	 * @param bool $fresh Bypass the object cache (use after another process may have changed it).
	 * @return array{token:string,obtained_at:int}
	 */
	public static function token_data( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		return array(
			'token'       => isset( $data['token'] ) ? (string) $data['token'] : '',
			'obtained_at' => isset( $data['obtained_at'] ) ? (int) $data['obtained_at'] : 0,
		);
	}

	/**
	 * Status record.
	 *
	 * @param bool $fresh Bypass cache.
	 * @return array
	 */
	public static function status_data( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::STATUS_OPTION, 'options' );
		}
		$data = get_option( self::STATUS_OPTION, array() );
		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'status'           => self::STATUS_UNKNOWN,
				'last_error'       => '',
				'last_ok_at'       => 0, // Last successful authenticated API call.
				'last_refresh_at'  => 0, // Last successful refresh-token call.
				'last_refresh_try' => 0,
				'last_alert_at'    => 0,
			)
		);
	}

	/**
	 * Current token.
	 *
	 * @param bool $fresh Bypass cache.
	 * @return string
	 */
	public static function get_token( $fresh = false ) {
		$data = self::token_data( $fresh );
		return $data['token'];
	}

	/**
	 * Save a token entered by an administrator (assumed to be newly generated).
	 *
	 * @param string $token Token.
	 */
	public static function replace_token( $token ) {
		update_option(
			self::OPTION,
			array(
				'token'       => self::clean( $token ),
				'obtained_at' => time(),
			),
			false
		);
		self::update_status(
			array(
				'status'     => self::STATUS_UNKNOWN,
				'last_error' => '',
			)
		);
	}

	/**
	 * Save the result of a successful refresh. Call only while holding the lock.
	 *
	 * @param string $new_token Token returned by refresh-token.
	 * @return bool True if the token actually changed.
	 */
	public static function store_refreshed( $new_token ) {
		$new_token = self::clean( $new_token );
		$current   = self::token_data( true );
		$changed   = ( $new_token !== $current['token'] );

		if ( $changed ) {
			update_option(
				self::OPTION,
				array(
					'token'       => $new_token,
					'obtained_at' => time(),
				),
				false
			);
		}
		self::update_status(
			array(
				'status'           => self::STATUS_OK,
				'last_error'       => '',
				'last_refresh_at'  => time(),
				'last_refresh_try' => time(),
			)
		);
		return $changed;
	}

	/**
	 * Record a successful authenticated call (throttled to avoid a DB write per request).
	 */
	public static function mark_ok() {
		$status = self::status_data();
		if ( self::STATUS_OK === $status['status'] && ( time() - (int) $status['last_ok_at'] ) < 600 ) {
			return;
		}
		self::update_status(
			array(
				'status'     => self::STATUS_OK,
				'last_ok_at' => time(),
				'last_error' => '',
			)
		);
	}

	/**
	 * Record a failure.
	 *
	 * @param string $status  One of the STATUS_* constants.
	 * @param string $message Human readable error.
	 */
	public static function mark_error( $status, $message ) {
		$changes = array(
			'status'     => $status,
			'last_error' => $message,
		);
		if ( self::STATUS_REFRESH_FAILED === $status ) {
			$changes['last_refresh_try'] = time();
		}
		self::update_status( $changes );
	}

	/**
	 * Remember a refresh attempt (e.g. when the API returned the unchanged token).
	 */
	public static function mark_refresh_attempt() {
		self::update_status( array( 'last_refresh_try' => time() ) );
	}

	/**
	 * Remember when the admin was last alerted (to throttle e-mails).
	 */
	public static function mark_alerted() {
		self::update_status( array( 'last_alert_at' => time() ) );
	}

	/**
	 * Seconds since the current token was issued (0 if unknown).
	 *
	 * @return int
	 */
	public static function token_age() {
		$data = self::token_data();
		return $data['obtained_at'] ? max( 0, time() - $data['obtained_at'] ) : 0;
	}

	/**
	 * Estimated expiry timestamp (0 if unknown).
	 *
	 * @return int
	 */
	public static function estimated_expiry() {
		$data = self::token_data();
		return $data['obtained_at'] ? $data['obtained_at'] + self::TOKEN_LIFETIME : 0;
	}

	/**
	 * Masked token for display.
	 *
	 * @return string
	 */
	public static function masked() {
		$token = self::get_token();
		if ( '' === $token ) {
			return '';
		}
		return substr( $token, 0, 4 ) . str_repeat( '•', 8 ) . substr( $token, -4 );
	}

	/**
	 * Acquire an atomic, cross-process lock so two requests never refresh at the same time
	 * (a second refresh with the already-rotated token would fail and could lose the new token).
	 *
	 * Uses INSERT IGNORE on the options table because add_option() is not atomic.
	 *
	 * @return bool
	 */
	public static function acquire_lock() {
		global $wpdb;

		// Remove a stale lock left behind by a crashed request.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %s",
				self::LOCK_OPTION,
				(string) ( time() - self::LOCK_TIMEOUT )
			)
		);

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				(string) time()
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === (int) $inserted;
	}

	/**
	 * Release the refresh lock.
	 */
	public static function release_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Delete everything (used on uninstall).
	 */
	public static function delete_all() {
		delete_option( self::OPTION );
		delete_option( self::STATUS_OPTION );
		self::release_lock();
	}

	/**
	 * Merge changes into the status option.
	 *
	 * @param array $changes Fields to change.
	 */
	private static function update_status( array $changes ) {
		$status = array_merge( self::status_data( true ), $changes );
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Strip whitespace and an accidental "Bearer " prefix.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function clean( $token ) {
		$token = trim( (string) $token );
		$token = (string) preg_replace( '/^Bearer\s+/i', '', $token );
		return (string) preg_replace( '/\s+/', '', $token );
	}
}
