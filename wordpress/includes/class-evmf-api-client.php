<?php
/**
 * easyVerein API v3.0 client with automatic token refresh.
 *
 * How easyVerein API tokens work:
 * - A token is valid for 30 days.
 * - When it is close to expiry, every API response carries the header
 *   `token_refresh_needed: True`.
 * - GET {base}/refresh-token with the current token returns {"Bearer": "<new token>"}.
 *   The old token stops working, so the new one must be stored immediately.
 *
 * This client:
 * - checks the refresh header after every call and refreshes once per request,
 * - serialises refreshes across PHP processes with a DB lock,
 * - retries a request once on HTTP 401 if another process already rotated the token,
 * - proactively refreshes old tokens from WP-Cron, so a quiet site does not lose its token,
 * - alerts the site admin when the token is no longer valid.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around wp_remote_request() for the easyVerein API.
 */
class EVMF_Api_Client {

	/** Refresh proactively once the token is this old (seconds). */
	const PROACTIVE_REFRESH_AGE = 1728000; // 20 days.

	/** Throttle for admin alert e-mails (seconds). */
	const ALERT_INTERVAL = 86400;

	/**
	 * Whether this PHP request already refreshed the token.
	 *
	 * @var bool
	 */
	private static $refreshed_this_request = false;

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_url Optional base URL override.
	 */
	public function __construct( $base_url = null ) {
		$base_url       = $base_url ? $base_url : (string) EVMF_Plugin::get( 'api_base_url', EVMF_Plugin::DEFAULT_BASE_URL );
		$this->base_url = untrailingslashit( $base_url );
	}

	/**
	 * GET helper.
	 *
	 * @param string $endpoint Endpoint, e.g. "member".
	 * @param array  $query    Query string parameters.
	 * @return array|WP_Error
	 */
	public function get( $endpoint, array $query = array() ) {
		return $this->request( 'GET', $endpoint, null, $query );
	}

	/**
	 * POST helper.
	 *
	 * @param string $endpoint Endpoint.
	 * @param array  $body     JSON body (v3.0 expects snake_case field names).
	 * @return array|WP_Error
	 */
	public function post( $endpoint, array $body ) {
		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * DELETE helper.
	 *
	 * @param string $endpoint Endpoint incl. ID.
	 * @return array|WP_Error
	 */
	public function delete( $endpoint ) {
		return $this->request( 'DELETE', $endpoint );
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint relative to the base URL.
	 * @param array|null $body     JSON body.
	 * @param array      $query    Query parameters.
	 * @param bool       $retry    Internal: allow one retry.
	 * @return array|WP_Error Decoded JSON (empty array for empty bodies) or error with data {status, body}.
	 */
	public function request( $method, $endpoint, $body = null, array $query = array(), $retry = true ) {
		$token = EVMF_Token_Store::get_token();
		if ( '' === $token ) {
			return new WP_Error( 'evmf_no_token', __( 'No easyVerein API token is configured.', 'easyverein-membership-form' ), array( 'status' => 0 ) );
		}

		$url = $this->url( $endpoint, $query );

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		/**
		 * Filter the arguments passed to wp_remote_request().
		 *
		 * @param array  $args     Request arguments.
		 * @param string $method   HTTP method.
		 * @param string $endpoint Endpoint.
		 */
		$args = apply_filters( 'evmf_api_request_args', $args, $method, $endpoint );

		EVMF_Logger::debug( "API {$method} {$endpoint}", array( 'payload' => $body ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			EVMF_Logger::error(
				"Network error on {$method} {$endpoint}: " . $response->get_error_message()
			);
			return new WP_Error( 'evmf_http', $response->get_error_message(), array( 'status' => 0 ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $raw ? array() : json_decode( $raw, true );

		// Token rotated by another request while we were waiting? Retry once with the new token.
		if ( 401 === $code ) {
			$current = EVMF_Token_Store::get_token( true );
			if ( $retry && '' !== $current && $current !== $token ) {
				EVMF_Logger::info( "Got 401 on {$endpoint} with an outdated token; retrying with the current one." );
				return $this->request( $method, $endpoint, $body, $query, false );
			}
			$this->handle_invalid_token( $this->error_message( $decoded, $raw ) );
			return new WP_Error(
				'evmf_unauthorized',
				__( 'The easyVerein API token is invalid or expired.', 'easyverein-membership-form' ),
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		// Rate limited: honour a short Retry-After once.
		if ( 429 === $code && $retry ) {
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $wait > 0 && $wait <= 5 ) {
				sleep( $wait );
				return $this->request( $method, $endpoint, $body, $query, false );
			}
		}

		if ( $code >= 200 && $code < 300 ) {
			EVMF_Token_Store::mark_ok();
		}

		// Refresh after the call so the current request is not delayed beforehand.
		if ( $this->refresh_requested( $response ) ) {
			$this->refresh_token( 'header' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->error_message( $decoded, $raw );
			EVMF_Logger::error(
				"API {$method} {$endpoint} failed with HTTP {$code}: {$message}",
				array( 'payload' => $body )
			);
			return new WP_Error(
				'evmf_api',
				/* translators: 1: HTTP status code, 2: error message from easyVerein */
				sprintf( __( 'easyVerein API error (HTTP %1$d): %2$s', 'easyverein-membership-form' ), $code, $message ),
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Exchange the current token for a new one and store it.
	 *
	 * @param string $reason Why the refresh happens (for the log): header, scheduled, manual.
	 * @param bool   $force  Refresh even if this request already refreshed.
	 * @return true|WP_Error
	 */
	public function refresh_token( $reason = 'manual', $force = false ) {
		if ( self::$refreshed_this_request && ! $force ) {
			return true;
		}

		if ( ! EVMF_Token_Store::acquire_lock() ) {
			// Another process is refreshing right now; it will store the new token.
			EVMF_Logger::debug( 'Token refresh skipped: another request holds the lock.' );
			return new WP_Error( 'evmf_locked', __( 'A token refresh is already running. Please try again in a minute.', 'easyverein-membership-form' ) );
		}

		try {
			$token = EVMF_Token_Store::get_token( true );
			if ( '' === $token ) {
				return new WP_Error( 'evmf_no_token', __( 'No easyVerein API token is configured.', 'easyverein-membership-form' ) );
			}

			$response = wp_remote_get(
				$this->url( 'refresh-token' ),
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				EVMF_Token_Store::mark_error( EVMF_Token_Store::STATUS_REFRESH_FAILED, $message );
				EVMF_Logger::error( "Token refresh ({$reason}) network error: {$message}" );
				return new WP_Error( 'evmf_http', $message );
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$raw     = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );
			$new     = $this->extract_token( $decoded );

			if ( $code >= 200 && $code < 300 && '' !== $new ) {
				$changed                      = EVMF_Token_Store::store_refreshed( $new );
				self::$refreshed_this_request = true;
				EVMF_Logger::info(
					$changed
						? "Token refreshed ({$reason}). New token saved."
						: "Token refresh ({$reason}) returned the current token; it is not due for rotation yet."
				);
				/**
				 * Fires after a successful token refresh.
				 *
				 * @param bool   $changed Whether a new token was issued.
				 * @param string $reason  Trigger: header, scheduled, manual.
				 */
				do_action( 'evmf_token_refreshed', $changed, $reason );
				return true;
			}

			$message = $this->error_message( $decoded, $raw );
			if ( 401 === $code ) {
				$this->handle_invalid_token( $message );
			} else {
				EVMF_Token_Store::mark_error( EVMF_Token_Store::STATUS_REFRESH_FAILED, "HTTP {$code}: {$message}" );
			}
			EVMF_Logger::error( "Token refresh ({$reason}) failed with HTTP {$code}: {$message}" );
			return new WP_Error(
				'evmf_refresh_failed',
				/* translators: 1: HTTP status code, 2: error message */
				sprintf( __( 'Token refresh failed (HTTP %1$d): %2$s', 'easyverein-membership-form' ), $code, $message )
			);
		} finally {
			EVMF_Token_Store::release_lock();
		}
	}

	/**
	 * Cheap authenticated call to verify the token (also triggers header-based refresh).
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$result = $this->get(
			'member',
			array(
				'limit' => 1,
				'query' => '{id}',
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * WP-Cron job: keep the token alive even if nobody submits the form for weeks.
	 */
	public static function run_token_maintenance() {
		if ( '' === EVMF_Token_Store::get_token() ) {
			return;
		}
		$client = new self();
		$status = EVMF_Token_Store::status_data();

		if ( EVMF_Token_Store::token_age() >= self::PROACTIVE_REFRESH_AGE
			&& ( time() - (int) $status['last_refresh_try'] ) > 6 * HOUR_IN_SECONDS ) {
			$result = $client->refresh_token( 'scheduled' );
			if ( ! is_wp_error( $result ) ) {
				return;
			}
		}

		// A light request; the API answers with token_refresh_needed when rotation is due.
		$client->test_connection();
	}

	/**
	 * Full URL for an endpoint.
	 *
	 * @param string $endpoint Endpoint.
	 * @param array  $query    Query args.
	 * @return string
	 */
	public function url( $endpoint, array $query = array() ) {
		$url = $this->base_url . '/' . ltrim( $endpoint, '/' );
		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		return $url;
	}

	/**
	 * Did the API ask for a token refresh (header `token_refresh_needed`)?
	 *
	 * @param array $response wp_remote_* response.
	 * @return bool
	 */
	private function refresh_requested( $response ) {
		foreach ( array( 'token_refresh_needed', 'token-refresh-needed' ) as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( in_array( strtolower( trim( (string) $value ) ), array( 'true', '1', 'yes' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pull the token out of a refresh-token response.
	 *
	 * @param mixed $decoded Decoded body.
	 * @return string
	 */
	private function extract_token( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( array( 'Bearer', 'bearer', 'token', 'key' ) as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				return $decoded[ $key ];
			}
		}
		return '';
	}

	/**
	 * Mark the token invalid and alert the admin (throttled).
	 *
	 * @param string $message API message.
	 */
	private function handle_invalid_token( $message ) {
		EVMF_Token_Store::mark_error( EVMF_Token_Store::STATUS_INVALID, $message );
		EVMF_Logger::error( 'easyVerein rejected the API token (HTTP 401): ' . $message );

		$status = EVMF_Token_Store::status_data( true );
		if ( ( time() - (int) $status['last_alert_at'] ) < self::ALERT_INTERVAL ) {
			return;
		}
		EVMF_Token_Store::mark_alerted();

		$to = EVMF_Plugin::get( 'notify_email' ) ? EVMF_Plugin::get( 'notify_email' ) : get_option( 'admin_email' );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] easyVerein API token is no longer valid', 'easyverein-membership-form' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = sprintf(
			/* translators: 1: settings page URL */
			__( "The easyVerein membership form can no longer reach easyVerein because the API token was rejected.\n\nNew membership applications will fail until this is fixed.\n\nPlease create a new API token in easyVerein (Settings > Integrations > API) and paste it here:\n%1\$s\n", 'easyverein-membership-form' ),
			admin_url( 'options-general.php?page=easyverein-membership-form' )
		);
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Turn an easyVerein error body into a readable string.
	 *
	 * Handles {"detail": "..."}, {"error": "..."} and DRF field errors {"field": ["msg"]}.
	 *
	 * @param mixed  $decoded Decoded JSON.
	 * @param string $raw     Raw body.
	 * @return string
	 */
	private function error_message( $decoded, $raw ) {
		if ( is_array( $decoded ) ) {
			foreach ( array( 'detail', 'error', 'message' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					return $decoded[ $key ];
				}
			}
			$parts = array();
			foreach ( $decoded as $field => $errors ) {
				$errors  = is_array( $errors ) ? $errors : array( $errors );
				$flat    = array_filter( array_map( array( $this, 'stringify' ), $errors ) );
				$parts[] = $field . ': ' . implode( ' ', $flat );
			}
			if ( $parts ) {
				return implode( '; ', $parts );
			}
		}
		$raw = trim( wp_strip_all_tags( $raw ) );
		return '' !== $raw ? mb_substr( $raw, 0, 300 ) : __( 'Unknown error', 'easyverein-membership-form' );
	}

	/**
	 * Stringify nested error values.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stringify( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return (string) wp_json_encode( $value );
	}
}
