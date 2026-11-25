<?php
/**
 * Admin settings screen (Settings › easyVerein Form).
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page, AJAX actions and admin notices.
 */
final class EVMF_Admin {

	const PAGE         = 'easyverein-membership-form';
	const GROUP        = 'evmf_settings_group';
	const NONCE        = 'evmf_admin';
	const TOKEN_FIELD  = 'evmf_new_token';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'token_notice' ) );
		add_action( 'wp_ajax_evmf_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_evmf_refresh_token', array( __CLASS__, 'ajax_refresh_token' ) );
		add_action( 'admin_post_evmf_clear_log', array( __CLASS__, 'clear_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EVMF_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings menu entry.
	 */
	public static function menu() {
		add_options_page(
			__( 'easyVerein Membership Form', 'easyverein-membership-form' ),
			__( 'easyVerein Form', 'easyverein-membership-form' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'easyverein-membership-form' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			EVMF_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => EVMF_Plugin::defaults(),
			)
		);
	}

	/**
	 * Admin CSS/JS on our page only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'evmf-admin', EVMF_PLUGIN_URL . 'assets/css/admin.css', array(), EVMF_VERSION );
		wp_enqueue_script( 'evmf-admin', EVMF_PLUGIN_URL . 'assets/js/admin.js', array(), EVMF_VERSION, true );
		wp_localize_script(
			'evmf-admin',
			'evmfAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'testing'    => __( 'Testing connection…', 'easyverein-membership-form' ),
					'refreshing' => __( 'Refreshing token…', 'easyverein-membership-form' ),
					'failed'     => __( 'Request failed:', 'easyverein-membership-form' ),
					'confirmRefresh' => __( 'Request a new token from easyVerein now? The current token stops working once a new one is issued.', 'easyverein-membership-form' ),
				),
			)
		);
	}

	/**
	 * Sanitize all settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = EVMF_Plugin::defaults();
		$out      = array();

		// Token replacement is handled here because it is posted with the settings form,
		// but it is stored separately and never echoed back into the form.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce.
		if ( current_user_can( 'manage_options' ) && ! empty( $_POST[ self::TOKEN_FIELD ] ) ) {
			$new_token = sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $new_token !== EVMF_Token_Store::get_token( true ) ) {
				EVMF_Token_Store::replace_token( $new_token );
				EVMF_Logger::info( 'API token replaced by an administrator.' );
				add_settings_error( EVMF_Plugin::OPTION, 'evmf_token', __( 'New API token saved. Use "Test connection" to verify it.', 'easyverein-membership-form' ), 'updated' );
			}
		}

		$text = array( 'org_name', 'from_name', 'confirmation_subject', 'sepa_creditor_name', 'sepa_creditor_address', 'recaptcha_site_key', 'recaptcha_secret_key' );
		foreach ( $text as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
		}

		foreach ( array( 'contact_email', 'from_email', 'notify_email' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_email( $input[ $key ] ) : '';
		}

		$out['privacy_url'] = isset( $input['privacy_url'] ) ? esc_url_raw( trim( $input['privacy_url'] ) ) : '';

		$base                = isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ), array( 'https', 'http' ) ) : '';
		$out['api_base_url'] = '' !== $base ? untrailingslashit( $base ) : EVMF_Plugin::DEFAULT_BASE_URL;
		if ( 0 !== strpos( $out['api_base_url'], 'https://' ) ) {
			add_settings_error( EVMF_Plugin::OPTION, 'evmf_base_url', __( 'Warning: the API base URL does not use HTTPS.', 'easyverein-membership-form' ), 'warning' );
		}

		$out['sepa_creditor_id']  = isset( $input['sepa_creditor_id'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $input['sepa_creditor_id'] ) ) ) : '';
		$out['sepa_mandate_text'] = isset( $input['sepa_mandate_text'] ) ? sanitize_textarea_field( $input['sepa_mandate_text'] ) : '';
		$out['success_message']   = isset( $input['success_message'] ) ? wp_kses_post( $input['success_message'] ) : '';

		$checkboxes = array( 'send_confirmation', 'notify_admin', 'mask_iban_in_emails', 'create_as_application', 'group_payment_active', 'require_phone', 'require_address', 'sepa_enabled', 'sepa_require_bic', 'debug_logging', 'delete_on_uninstall' );
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['min_age']      = isset( $input['min_age'] ) ? min( 120, absint( $input['min_age'] ) ) : $defaults['min_age'];
		$out['max_children'] = isset( $input['max_children'] ) ? min( EVMF_Plugin::MAX_CHILDREN_LIMIT, absint( $input['max_children'] ) ) : $defaults['max_children'];

		// Countries: comma separated ISO codes.
		$codes = isset( $input['countries'] ) ? preg_split( '/[\s,;]+/', strtoupper( (string) $input['countries'] ) ) : array();
		$codes = array_values( array_unique( array_filter( (array) $codes, static function ( $c ) {
			return (bool) preg_match( '/^[A-Z]{2}$/', $c );
		} ) ) );
		$out['countries']       = $codes ? implode( ',', $codes ) : $defaults['countries'];
		$default_country        = isset( $input['default_country'] ) ? strtoupper( sanitize_text_field( $input['default_country'] ) ) : 'DE';
		$out['default_country'] = in_array( $default_country, $codes, true ) ? $default_country : ( $codes ? $codes[0] : 'DE' );

		// Membership types.
		$out['membership_types'] = array();
		$seen                    = array();
		if ( isset( $input['membership_types'] ) && is_array( $input['membership_types'] ) ) {
			foreach ( $input['membership_types'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
				if ( '' === $label ) {
					continue;
				}
				$key = isset( $row['key'] ) && '' !== $row['key'] ? sanitize_key( $row['key'] ) : sanitize_key( sanitize_title( $label ) );
				if ( '' === $key ) {
					$key = 'type';
				}
				$base_key = $key;
				$n        = 2;
				while ( isset( $seen[ $key ] ) ) {
					$key = $base_key . '-' . $n++;
				}
				$seen[ $key ]              = true;
				$out['membership_types'][] = array(
					'key'      => $key,
					'label'    => $label,
					'fee'      => isset( $row['fee'] ) ? sanitize_text_field( $row['fee'] ) : '',
					'group_id' => isset( $row['group_id'] ) ? preg_replace( '/\D/', '', (string) $row['group_id'] ) : '',
					'family'   => empty( $row['family'] ) ? 0 : 1,
				);
			}
		}
		if ( empty( $out['membership_types'] ) ) {
			add_settings_error( EVMF_Plugin::OPTION, 'evmf_types', __( 'Please configure at least one membership type. The defaults were restored.', 'easyverein-membership-form' ) );
			$out['membership_types'] = $defaults['membership_types'];
		}

		// Custom field IDs (keep values for all possible children so lowering the max does not lose them).
		$out['custom_fields'] = array();
		foreach ( array_keys( EVMF_Plugin::family_field_keys( EVMF_Plugin::MAX_CHILDREN_LIMIT ) ) as $key ) {
			if ( ! empty( $input['custom_fields'][ $key ] ) ) {
				$out['custom_fields'][ $key ] = preg_replace( '/\D/', '', (string) $input['custom_fields'][ $key ] );
			}
		}

		if ( $out['sepa_enabled'] && '' === $out['sepa_creditor_id'] ) {
			add_settings_error( EVMF_Plugin::OPTION, 'evmf_sepa', __( 'SEPA is enabled but no creditor identifier is set. The creditor identifier must be shown on a SEPA mandate.', 'easyverein-membership-form' ), 'warning' );
		}

		return $out;
	}

	/**
	 * AJAX: test the stored token.
	 */
	public static function ajax_test_connection() {
		self::verify_ajax();
		$client = new EVMF_Api_Client();
		$result = $client->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'status'  => self::token_status_html(),
				)
			);
		}
		wp_send_json_success(
			array(
				'message' => __( 'Connection successful. The API token is valid.', 'easyverein-membership-form' ),
				'status'  => self::token_status_html(),
			)
		);
	}

	/**
	 * AJAX: force a token refresh.
	 */
	public static function ajax_refresh_token() {
		self::verify_ajax();
		$client = new EVMF_Api_Client();
		$result = $client->refresh_token( 'manual', true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'status'  => self::token_status_html(),
				)
			);
		}
		// Verify that the stored (possibly new) token works.
		$check = $client->test_connection();
		wp_send_json_success(
			array(
				'message' => is_wp_error( $check )
					? __( 'Token refreshed, but the follow-up test failed:', 'easyverein-membership-form' ) . ' ' . $check->get_error_message()
					: __( 'Token refreshed and verified.', 'easyverein-membership-form' ),
				'status'  => self::token_status_html(),
			)
		);
	}

	/**
	 * admin-post: clear log files.
	 */
	public static function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'easyverein-membership-form' ) );
		}
		check_admin_referer( 'evmf_clear_log' );
		EVMF_Logger::clear();
		wp_safe_redirect( self::page_url( 'logs' ) );
		exit;
	}

	/**
	 * Warn administrators when the token needs attention.
	 */
	public static function token_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return; // The page shows the status itself.
		}
		$status = EVMF_Token_Store::status_data();
		$token  = EVMF_Token_Store::get_token();

		if ( '' === $token ) {
			$message = __( 'easyVerein Membership Form: no API token configured yet.', 'easyverein-membership-form' );
			$class   = 'notice-warning';
		} elseif ( EVMF_Token_Store::STATUS_INVALID === $status['status'] ) {
			$message = __( 'easyVerein Membership Form: the API token was rejected by easyVerein. New applications will fail until you add a new token.', 'easyverein-membership-form' );
			$class   = 'notice-error';
		} elseif ( EVMF_Token_Store::STATUS_REFRESH_FAILED === $status['status'] && EVMF_Token_Store::estimated_expiry() && EVMF_Token_Store::estimated_expiry() - time() < 5 * DAY_IN_SECONDS ) {
			$message = __( 'easyVerein Membership Form: the API token could not be refreshed and will expire soon.', 'easyverein-membership-form' );
			$class   = 'notice-warning';
		} else {
			return;
		}
		printf(
			'<div class="notice %1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $class ),
			esc_html( $message ),
			esc_url( self::page_url() ),
			esc_html__( 'Open settings', 'easyverein-membership-form' )
		);
	}

	/**
	 * Settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'connection' => __( 'Connection', 'easyverein-membership-form' ),
			'general'    => __( 'Organisation & e-mails', 'easyverein-membership-form' ),
			'membership' => __( 'Membership types', 'easyverein-membership-form' ),
			'family'     => __( 'Family members', 'easyverein-membership-form' ),
			'form'       => __( 'Form', 'easyverein-membership-form' ),
			'sepa'       => __( 'SEPA', 'easyverein-membership-form' ),
			'spam'       => __( 'Spam protection', 'easyverein-membership-form' ),
			'logs'       => __( 'Logs', 'easyverein-membership-form' ),
		);
		?>
		<div class="wrap evmf-admin">
			<h1><?php esc_html_e( 'easyVerein Membership Form', 'easyverein-membership-form' ); ?></h1>
			<p class="evmf-shortcode-hint">
				<?php esc_html_e( 'Add the form to any page with the shortcode', 'easyverein-membership-form' ); ?>
				<code>[easyverein_membership_form]</code>
			</p>

			<nav class="nav-tab-wrapper evmf-tabs">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post" autocomplete="off">
				<?php settings_fields( self::GROUP ); ?>
				<?php
				foreach ( array_keys( $tabs ) as $id ) {
					if ( 'logs' === $id ) {
						continue;
					}
					echo '<section class="evmf-tab-panel" data-panel="' . esc_attr( $id ) . '">';
					call_user_func( array( __CLASS__, 'tab_' . $id ) );
					echo '</section>';
				}
				?>
				<section class="evmf-tab-panel" data-panel="logs">
					<?php self::tab_logs_settings(); ?>
				</section>
				<?php submit_button(); ?>
			</form>
			<?php self::log_viewer(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tabs
	 * ------------------------------------------------------------------ */

	/**
	 * Connection tab.
	 */
	private static function tab_connection() {
		?>
		<h2><?php esc_html_e( 'easyVerein API connection', 'easyverein-membership-form' ); ?></h2>
		<p><?php esc_html_e( 'Create an API token in easyVerein (Settings › Integrations › API) with permission to create contacts and members. Tokens are valid for 30 days; the plugin refreshes them automatically.', 'easyverein-membership-form' ); ?></p>

		<div id="evmf-token-status"><?php echo self::token_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

		<p>
			<button type="button" class="button" id="evmf-test-connection"><?php esc_html_e( 'Test connection', 'easyverein-membership-form' ); ?></button>
			<button type="button" class="button" id="evmf-refresh-token"><?php esc_html_e( 'Refresh token now', 'easyverein-membership-form' ); ?></button>
			<span id="evmf-connection-result" class="evmf-result" aria-live="polite"></span>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="evmf-new-token"><?php esc_html_e( 'Set a new API token', 'easyverein-membership-form' ); ?></label></th>
				<td>
					<input type="password" id="evmf-new-token" name="<?php echo esc_attr( self::TOKEN_FIELD ); ?>" value="" class="regular-text code" autocomplete="new-password" spellcheck="false">
					<p class="description"><?php esc_html_e( 'Leave empty to keep the current token. The stored token is never shown here, so saving this page cannot overwrite a refreshed token.', 'easyverein-membership-form' ); ?></p>
				</td>
			</tr>
			<?php
			self::row_text( 'api_base_url', __( 'API base URL', 'easyverein-membership-form' ), 'url', sprintf( /* translators: %s: default URL */ __( 'Default: %s. Keep a fixed version; "latest" may introduce breaking changes.', 'easyverein-membership-form' ), EVMF_Plugin::DEFAULT_BASE_URL ) );
			?>
		</table>
		<?php
		$next = wp_next_scheduled( EVMF_Plugin::CRON_HOOK );
		if ( $next ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic token check: %s. If your site has little traffic, set up a real cron job for wp-cron.php so this check runs reliably.', 'easyverein-membership-form' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				)
			) . '</p>';
		}
	}

	/**
	 * Organisation tab.
	 */
	private static function tab_general() {
		?>
		<h2><?php esc_html_e( 'Organisation', 'easyverein-membership-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			self::row_text( 'org_name', __( 'Organisation name', 'easyverein-membership-form' ), 'text', __( 'Used in e-mails and consent texts. Defaults to the site title.', 'easyverein-membership-form' ), get_bloginfo( 'name' ) );
			self::row_text( 'contact_email', __( 'Contact e-mail', 'easyverein-membership-form' ), 'email', __( 'Shown to applicants if something goes wrong, and used as Reply-To. Defaults to the site admin e-mail.', 'easyverein-membership-form' ) );
			?>
		</table>
		<h2><?php esc_html_e( 'E-mails', 'easyverein-membership-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			self::row_text( 'from_name', __( 'Sender name', 'easyverein-membership-form' ) );
			self::row_text( 'from_email', __( 'Sender e-mail', 'easyverein-membership-form' ), 'email', __( 'Should be an address on your own domain (SPF/DKIM). Leave empty to use the WordPress default.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'send_confirmation', __( 'Confirmation to applicant', 'easyverein-membership-form' ), __( 'Send the applicant a summary of the application.', 'easyverein-membership-form' ) );
			self::row_text( 'confirmation_subject', __( 'Confirmation subject', 'easyverein-membership-form' ), 'text', __( 'Use {org} for the organisation name.', 'easyverein-membership-form' ), __( 'Your membership application at {org}', 'easyverein-membership-form' ) );
			self::row_checkbox( 'notify_admin', __( 'Notify organisation', 'easyverein-membership-form' ), __( 'E-mail every new application (and every failed attempt) to the address below.', 'easyverein-membership-form' ) );
			self::row_text( 'notify_email', __( 'Notification e-mail', 'easyverein-membership-form' ), 'email', __( 'Defaults to the site admin e-mail.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'mask_iban_in_emails', __( 'Mask IBAN in e-mails', 'easyverein-membership-form' ), __( 'Show only the first and last four characters of the IBAN (recommended; e-mail is not encrypted).', 'easyverein-membership-form' ) );
			?>
		</table>
		<?php
	}

	/**
	 * Membership types tab.
	 */
	private static function tab_membership() {
		$types = (array) EVMF_Plugin::get( 'membership_types', array() );
		?>
		<h2><?php esc_html_e( 'Membership types', 'easyverein-membership-form' ); ?></h2>
		<p><?php esc_html_e( 'Each type is assigned to an easyVerein member group (use the numeric group ID). Mark family types to show the family member section.', 'easyverein-membership-form' ); ?></p>
		<table class="widefat striped evmf-types" id="evmf-types">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'easyverein-membership-form' ); ?></th>
					<th><?php esc_html_e( 'Fee (display only)', 'easyverein-membership-form' ); ?></th>
					<th><?php esc_html_e( 'easyVerein group ID', 'easyverein-membership-form' ); ?></th>
					<th><?php esc_html_e( 'Family', 'easyverein-membership-form' ); ?></th>
					<th><?php esc_html_e( 'Key', 'easyverein-membership-form' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'easyverein-membership-form' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( array_values( $types ) as $i => $type ) {
					self::type_row( (string) $i, $type );
				}
				?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="evmf-add-type"><?php esc_html_e( 'Add membership type', 'easyverein-membership-form' ); ?></button></p>
		<template id="evmf-type-template"><?php self::type_row( '__i__', array() ); ?></template>

		<table class="form-table" role="presentation">
			<?php
			self::row_checkbox( 'create_as_application', __( 'Create as application', 'easyverein-membership-form' ), __( 'Create a membership application that you accept in easyVerein (recommended). If unchecked, members are created directly.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'group_payment_active', __( 'Group fees active', 'easyverein-membership-form' ), __( 'Activate the billing settings of the member group for the new member.', 'easyverein-membership-form' ) );
			?>
		</table>
		<?php
	}

	/**
	 * One membership type row.
	 *
	 * @param string $i    Row index.
	 * @param array  $type Values.
	 */
	private static function type_row( $i, array $type ) {
		$type = wp_parse_args(
			$type,
			array(
				'key'      => '',
				'label'    => '',
				'fee'      => '',
				'group_id' => '',
				'family'   => 0,
			)
		);
		$name = EVMF_Plugin::OPTION . '[membership_types][' . $i . ']';
		?>
		<tr class="evmf-type-row">
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $type['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Individual', 'easyverein-membership-form' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[fee]" value="<?php echo esc_attr( $type['fee'] ); ?>" class="small-text evmf-w-8" placeholder="<?php esc_attr_e( '€30 / year', 'easyverein-membership-form' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[group_id]" value="<?php echo esc_attr( $type['group_id'] ); ?>" class="evmf-w-10 code" inputmode="numeric"></td>
			<td><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[family]" value="1" <?php checked( ! empty( $type['family'] ) ); ?>></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $type['key'] ); ?>" class="evmf-w-8 code" placeholder="<?php esc_attr_e( 'auto', 'easyverein-membership-form' ); ?>"></td>
			<td><button type="button" class="button-link evmf-remove-type"><?php esc_html_e( 'Remove', 'easyverein-membership-form' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Family tab.
	 */
	private static function tab_family() {
		$mapping = (array) EVMF_Plugin::get( 'custom_fields', array() );
		?>
		<h2><?php esc_html_e( 'Family members', 'easyverein-membership-form' ); ?></h2>
		<p><?php esc_html_e( 'Partner and children of family memberships are stored in easyVerein custom fields ("individuelle Felder") of the member. Create the fields in easyVerein and enter their numeric IDs here. Values without a configured field are reported in the notification e-mail.', 'easyverein-membership-form' ); ?></p>
		<table class="form-table" role="presentation">
			<?php self::row_number( 'max_children', __( 'Maximum number of children', 'easyverein-membership-form' ), 0, EVMF_Plugin::MAX_CHILDREN_LIMIT, __( 'Save to update the list of custom field IDs below.', 'easyverein-membership-form' ) ); ?>
			<?php foreach ( EVMF_Plugin::family_field_keys() as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="evmf-cf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" id="evmf-cf-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( EVMF_Plugin::OPTION ); ?>[custom_fields][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $mapping[ $key ] ) ? $mapping[ $key ] : '' ); ?>" class="evmf-w-10 code" inputmode="numeric" placeholder="<?php esc_attr_e( 'Custom field ID', 'easyverein-membership-form' ); ?>"></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
		// Keep IDs for children beyond the current maximum.
		foreach ( $mapping as $key => $id ) {
			if ( ! array_key_exists( $key, EVMF_Plugin::family_field_keys() ) ) {
				printf( '<input type="hidden" name="%1$s[custom_fields][%2$s]" value="%3$s">', esc_attr( EVMF_Plugin::OPTION ), esc_attr( $key ), esc_attr( $id ) );
			}
		}
	}

	/**
	 * Form tab.
	 */
	private static function tab_form() {
		?>
		<h2><?php esc_html_e( 'Form', 'easyverein-membership-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			self::row_number( 'min_age', __( 'Minimum age', 'easyverein-membership-form' ), 0, 120, __( '0 disables the age check.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'require_address', __( 'Address required', 'easyverein-membership-form' ), __( 'Street, postal code and city are mandatory.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'require_phone', __( 'Phone required', 'easyverein-membership-form' ) );
			self::row_text( 'countries', __( 'Countries', 'easyverein-membership-form' ), 'text', __( 'Comma separated ISO codes in display order, e.g. DE,AT,CH.', 'easyverein-membership-form' ) );
			self::row_text( 'default_country', __( 'Default country', 'easyverein-membership-form' ), 'text', '', 'DE' );
			self::row_text( 'privacy_url', __( 'Privacy policy URL', 'easyverein-membership-form' ), 'url', __( 'Defaults to the WordPress privacy policy page.', 'easyverein-membership-form' ), get_privacy_policy_url() );
			self::row_textarea( 'success_message', __( 'Success message', 'easyverein-membership-form' ), __( 'Shown after a successful submission. Basic HTML allowed. Leave empty for the default text.', 'easyverein-membership-form' ) );
			?>
		</table>
		<?php
	}

	/**
	 * SEPA tab.
	 */
	private static function tab_sepa() {
		?>
		<h2><?php esc_html_e( 'SEPA direct debit', 'easyverein-membership-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			self::row_checkbox( 'sepa_enabled', __( 'Collect SEPA mandate', 'easyverein-membership-form' ), __( 'Ask for bank details and a direct debit mandate. They are stored in the easyVerein contact (payment method: direct debit).', 'easyverein-membership-form' ) );
			self::row_text( 'sepa_creditor_name', __( 'Creditor name', 'easyverein-membership-form' ), 'text', __( 'Defaults to the organisation name.', 'easyverein-membership-form' ) );
			self::row_text( 'sepa_creditor_address', __( 'Creditor address', 'easyverein-membership-form' ), 'text', '', __( 'Street 1, 12345 City', 'easyverein-membership-form' ) );
			self::row_text( 'sepa_creditor_id', __( 'Creditor identifier', 'easyverein-membership-form' ), 'text', __( 'Gläubiger-Identifikationsnummer, e.g. DE98ZZZ09999999999.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'sepa_require_bic', __( 'BIC required', 'easyverein-membership-form' ), __( 'Within the SEPA area the BIC is optional.', 'easyverein-membership-form' ) );
			self::row_textarea( 'sepa_mandate_text', __( 'Mandate text', 'easyverein-membership-form' ), __( 'Leave empty for the standard German/English mandate wording. Use {creditor} for the creditor name.', 'easyverein-membership-form' ) );
			?>
		</table>
		<?php
	}

	/**
	 * Spam tab.
	 */
	private static function tab_spam() {
		?>
		<h2><?php esc_html_e( 'Spam protection', 'easyverein-membership-form' ); ?></h2>
		<p><?php esc_html_e( 'A hidden honeypot field and a minimum fill time are always active. For stronger protection add Google reCAPTCHA v2 ("I\'m not a robot" checkbox) keys from https://www.google.com/recaptcha/admin. Remember to mention reCAPTCHA in your privacy policy.', 'easyverein-membership-form' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			self::row_text( 'recaptcha_site_key', __( 'reCAPTCHA site key', 'easyverein-membership-form' ) );
			self::row_text( 'recaptcha_secret_key', __( 'reCAPTCHA secret key', 'easyverein-membership-form' ), 'password' );
			?>
		</table>
		<?php
	}

	/**
	 * Log settings (inside the form).
	 */
	private static function tab_logs_settings() {
		?>
		<h2><?php esc_html_e( 'Logs & maintenance', 'easyverein-membership-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			self::row_checkbox( 'debug_logging', __( 'Debug logging', 'easyverein-membership-form' ), __( 'Log every API request (personal data is masked). Turn off when done.', 'easyverein-membership-form' ) );
			self::row_checkbox( 'delete_on_uninstall', __( 'Delete data on uninstall', 'easyverein-membership-form' ), __( 'Remove settings, token and logs when the plugin is deleted.', 'easyverein-membership-form' ) );
			?>
		</table>
		<?php
	}

	/**
	 * Log viewer (outside the settings form, own action).
	 */
	private static function log_viewer() {
		$tail = EVMF_Logger::tail( 200 );
		?>
		<div class="evmf-tab-panel evmf-log-viewer" data-panel="logs">
			<h2><?php esc_html_e( 'Recent log entries', 'easyverein-membership-form' ); ?></h2>
			<p class="description">
				<?php
				/* translators: %s: directory path */
				echo esc_html( sprintf( __( 'Stored in %s (protected from web access).', 'easyverein-membership-form' ), EVMF_Logger::log_dir() ) );
				?>
			</p>
			<textarea readonly class="large-text code" rows="16"><?php echo esc_textarea( '' !== $tail ? $tail : __( 'The log is empty.', 'easyverein-membership-form' ) ); ?></textarea>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="evmf_clear_log">
				<?php wp_nonce_field( 'evmf_clear_log' ); ?>
				<?php submit_button( __( 'Clear log', 'easyverein-membership-form' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Token status table.
	 *
	 * @return string
	 */
	public static function token_status_html() {
		$status = EVMF_Token_Store::status_data( true );
		$token  = EVMF_Token_Store::token_data( true );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$labels = array(
			EVMF_Token_Store::STATUS_UNKNOWN        => array( __( 'Not verified yet', 'easyverein-membership-form' ), 'warning' ),
			EVMF_Token_Store::STATUS_OK             => array( __( 'Working', 'easyverein-membership-form' ), 'ok' ),
			EVMF_Token_Store::STATUS_INVALID        => array( __( 'Rejected by easyVerein – add a new token', 'easyverein-membership-form' ), 'error' ),
			EVMF_Token_Store::STATUS_REFRESH_FAILED => array( __( 'Working, but the last refresh failed', 'easyverein-membership-form' ), 'warning' ),
		);
		$state  = '' === $token['token']
			? array( __( 'No token configured', 'easyverein-membership-form' ), 'error' )
			: ( isset( $labels[ $status['status'] ] ) ? $labels[ $status['status'] ] : $labels[ EVMF_Token_Store::STATUS_UNKNOWN ] );

		$rows = array(
			__( 'Status', 'easyverein-membership-form' ) => '<span class="evmf-badge evmf-badge--' . esc_attr( $state[1] ) . '">' . esc_html( $state[0] ) . '</span>',
		);
		if ( '' !== $token['token'] ) {
			$rows[ __( 'Token', 'easyverein-membership-form' ) ] = '<code>' . esc_html( EVMF_Token_Store::masked() ) . '</code>';
			if ( $token['obtained_at'] ) {
				$rows[ __( 'Issued (approx.)', 'easyverein-membership-form' ) ]  = esc_html( wp_date( $fmt, $token['obtained_at'] ) );
				$rows[ __( 'Expires (approx.)', 'easyverein-membership-form' ) ] = esc_html( wp_date( $fmt, EVMF_Token_Store::estimated_expiry() ) );
			}
			if ( $status['last_refresh_at'] ) {
				$rows[ __( 'Last refresh', 'easyverein-membership-form' ) ] = esc_html( wp_date( $fmt, $status['last_refresh_at'] ) );
			}
			if ( $status['last_ok_at'] ) {
				$rows[ __( 'Last successful API call', 'easyverein-membership-form' ) ] = esc_html( wp_date( $fmt, $status['last_ok_at'] ) );
			}
			if ( '' !== $status['last_error'] && EVMF_Token_Store::STATUS_OK !== $status['status'] ) {
				$rows[ __( 'Last error', 'easyverein-membership-form' ) ] = esc_html( $status['last_error'] );
			}
		}

		$html = '<table class="widefat evmf-status-table"><tbody>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Text-like input row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $type        Input type.
	 * @param string $description Description.
	 * @param string $placeholder Placeholder.
	 */
	private static function row_text( $key, $label, $type = 'text', $description = '', $placeholder = '' ) {
		$id = 'evmf-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( EVMF_Plugin::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) EVMF_Plugin::get( $key, '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo 'password' === $type ? ' autocomplete="new-password"' : ''; ?>>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Number input row.
	 *
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param int    $min         Min.
	 * @param int    $max         Max.
	 * @param string $description Description.
	 */
	private static function row_number( $key, $label, $min, $max, $description = '' ) {
		$id = 'evmf-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( EVMF_Plugin::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) EVMF_Plugin::get( $key, 0 ) ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" class="small-text">
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param string $description Inline description.
	 */
	private static function row_checkbox( $key, $label, $description = '' ) {
		$id = 'evmf-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( EVMF_Plugin::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) EVMF_Plugin::get( $key ) ); ?>>
					<?php echo esc_html( '' !== $description ? $description : $label ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Textarea row.
	 *
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param string $description Description.
	 */
	private static function row_textarea( $key, $label, $description = '' ) {
		$id = 'evmf-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( EVMF_Plugin::OPTION . '[' . $key . ']' ); ?>" rows="5" class="large-text"><?php echo esc_textarea( (string) EVMF_Plugin::get( $key, '' ) ); ?></textarea>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Check AJAX permission and nonce.
	 */
	private static function verify_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easyverein-membership-form' ) ), 403 );
		}
	}

	/**
	 * Settings page URL.
	 *
	 * @param string $tab Optional tab anchor.
	 * @return string
	 */
	private static function page_url( $tab = '' ) {
		return admin_url( 'options-general.php?page=' . self::PAGE ) . ( '' !== $tab ? '#' . $tab : '' );
	}
}
