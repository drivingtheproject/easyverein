<?php
/**
 * Privacy-aware file logger.
 *
 * Logs go to wp-content/uploads/evmf-logs/ with a random file name and a deny-all
 * .htaccess, never into the (publicly reachable) plugin directory. Personal data
 * such as IBANs, e-mail addresses and tokens is masked before it is written.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal logger with redaction and size-based rotation.
 */
final class EVMF_Logger {

	const MAX_BYTES     = 1048576; // 1 MB, then rotate.
	const FILE_KEY_OPT  = 'evmf_log_file_key';
	const DIR_NAME      = 'evmf-logs';

	/**
	 * Context keys whose values are always masked.
	 *
	 * @var string[]
	 */
	private static $sensitive_keys = array(
		'iban',
		'bic',
		'token',
		'bearer',
		'authorization',
		'api_key',
		'secret',
		'password',
		'date_of_birth',
		'dob',
		'private_phone',
		'phone',
		'street',
		'bank_account_owner',
		'account_holder',
		'internal_note',
		'value',
	);

	/**
	 * Error entry (always written).
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data, redacted before writing.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Warning entry (always written).
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	public static function warning( $message, array $context = array() ) {
		self::write( 'WARNING', $message, $context );
	}

	/**
	 * Info entry (always written; keep these rare and free of personal data).
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	public static function info( $message, array $context = array() ) {
		self::write( 'INFO', $message, $context );
	}

	/**
	 * Debug entry (only when debug logging is enabled in the settings).
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	public static function debug( $message, array $context = array() ) {
		if ( class_exists( 'EVMF_Plugin' ) && EVMF_Plugin::get( 'debug_logging' ) ) {
			self::write( 'DEBUG', $message, $context );
		}
	}

	/**
	 * Absolute path of the log directory.
	 *
	 * @return string
	 */
	public static function log_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Absolute path of the current log file (random, unguessable name).
	 *
	 * @return string
	 */
	public static function log_file() {
		$key = get_option( self::FILE_KEY_OPT );
		if ( ! $key ) {
			$key = wp_generate_password( 20, false, false );
			update_option( self::FILE_KEY_OPT, $key, false );
		}
		return trailingslashit( self::log_dir() ) . 'evmf-' . $key . '.log';
	}

	/**
	 * Create the log directory and protect it from direct web access.
	 *
	 * @return bool
	 */
	public static function ensure_log_dir() {
		$dir = self::log_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$protect = array(
			'.htaccess'  => "# Apache 2.4+\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $protect as $file => $contents ) {
			$path = trailingslashit( $dir ) . $file;
			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $path, $contents );
			}
		}
		return true;
	}

	/**
	 * Last N lines of the log for the admin screen.
	 *
	 * @param int $lines Number of lines.
	 * @return string
	 */
	public static function tail( $lines = 200 ) {
		$file = self::log_file();
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$all     = explode( "\n", rtrim( $content ) );
		return implode( "\n", array_slice( $all, -1 * absint( $lines ) ) );
	}

	/**
	 * Delete current and rotated log files.
	 */
	public static function clear() {
		$file = self::log_file();
		foreach ( array( $file, $file . '.1' ) as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Mask an e-mail address: "jane.doe@example.org" -> "ja***@example.org".
	 *
	 * @param string $email E-mail.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strpos( $email, '@' );
		if ( false === $at ) {
			return self::mask_string( $email );
		}
		return substr( $email, 0, min( 2, $at ) ) . '***' . substr( $email, $at );
	}

	/**
	 * Mask a generic secret, keeping only the last 4 characters.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function mask_string( $value ) {
		$value = (string) $value;
		$len   = strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', min( 8, $len - 4 ) ) . substr( $value, -4 );
	}

	/**
	 * Recursively redact a context array.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$lower = strtolower( (string) $key );
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			if ( false !== strpos( $lower, 'email' ) || 'email_or_user_name' === $lower ) {
				$out[ $key ] = self::mask_email( (string) $value );
				continue;
			}
			$sensitive = false;
			foreach ( self::$sensitive_keys as $needle ) {
				if ( $lower === $needle || false !== strpos( $lower, $needle ) ) {
					$sensitive = true;
					break;
				}
			}
			if ( $sensitive && is_scalar( $value ) && '' !== (string) $value ) {
				$out[ $key ] = self::mask_string( (string) $value );
			} elseif ( in_array( $lower, array( 'first_name', 'family_name', 'name', 'last_name' ), true ) && is_string( $value ) && '' !== $value ) {
				$out[ $key ] = mb_substr( $value, 0, 1 ) . '.';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Write one entry.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! self::ensure_log_dir() ) {
			return;
		}
		$file = self::log_file();

		if ( file_exists( $file ) && filesize( $file ) > self::MAX_BYTES ) {
			@rename( $file, $file . '.1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}

		$line = sprintf( '[%s] %s: %s', current_time( 'mysql' ), $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}
}
