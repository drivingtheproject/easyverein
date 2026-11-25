<?php
/**
 * Core plugin bootstrap: settings, activation, upgrades and scheduled jobs.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for settings and lifecycle hooks.
 */
final class EVMF_Plugin {

	/** Option holding all admin settings (never contains the API token). */
	const OPTION = 'evmf_settings';

	/** Stored schema version, used to run upgrade routines once. */
	const SCHEMA_OPTION = 'evmf_schema_version';
	const SCHEMA_VERSION = 1;

	/** WP-Cron hook that keeps the easyVerein token alive. */
	const CRON_HOOK = 'evmf_token_maintenance';

	/** Default easyVerein API base URL. Pin a version; "latest" may introduce breaking changes. */
	const DEFAULT_BASE_URL = 'https://easyverein.com/api/v3.0';

	/** Hard upper limit for the number of children on the form. */
	const MAX_CHILDREN_LIMIT = 10;

	/**
	 * Per-request settings cache.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_action( self::CRON_HOOK, array( 'EVMF_Api_Client', 'run_token_maintenance' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_settings_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush_settings_cache' ) );

		EVMF_Form::init();

		if ( is_admin() ) {
			EVMF_Admin::init();
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'easyverein-membership-form', false, dirname( plugin_basename( EVMF_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Default settings. Organisation-specific values are intentionally empty.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection.
			'api_base_url'          => self::DEFAULT_BASE_URL,

			// Organisation & e-mails.
			'org_name'              => '',
			'contact_email'         => '',
			'from_name'             => '',
			'from_email'            => '',
			'send_confirmation'     => 1,
			'confirmation_subject'  => '',
			'notify_admin'          => 1,
			'notify_email'          => '',
			'mask_iban_in_emails'   => 1,

			// Membership.
			'membership_types'      => array(
				array(
					'key'      => 'individual',
					'label'    => 'Individual',
					'fee'      => '',
					'group_id' => '',
					'family'   => 0,
				),
				array(
					'key'      => 'family',
					'label'    => 'Family',
					'fee'      => '',
					'group_id' => '',
					'family'   => 1,
				),
			),
			'create_as_application' => 1,
			'group_payment_active'  => 1,

			// Family members.
			'max_children'          => 4,
			'custom_fields'         => array(),

			// Form.
			'min_age'               => 18,
			'require_phone'         => 0,
			'require_address'       => 1,
			'countries'             => 'DE,AT,CH,NL,BE,LU,FR,IT,ES,PL,DK',
			'default_country'       => 'DE',
			'privacy_url'           => '',
			'success_message'       => '',

			// SEPA direct debit.
			'sepa_enabled'          => 1,
			'sepa_creditor_name'    => '',
			'sepa_creditor_address' => '',
			'sepa_creditor_id'      => '',
			'sepa_require_bic'      => 0,
			'sepa_mandate_text'     => '',

			// Spam protection.
			'recaptcha_site_key'    => '',
			'recaptcha_secret_key'  => '',

			// Maintenance.
			'debug_logging'         => 0,
			'delete_on_uninstall'   => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null === self::$settings ) {
			$stored         = get_option( self::OPTION, array() );
			self::$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$settings;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Reset the per-request cache after the option changed.
	 */
	public static function flush_settings_cache() {
		self::$settings = null;
	}

	/**
	 * Organisation name with a sensible fallback.
	 *
	 * @return string
	 */
	public static function org_name() {
		$name = trim( (string) self::get( 'org_name', '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Address applicants can contact if something goes wrong.
	 *
	 * @return string
	 */
	public static function contact_email() {
		$email = sanitize_email( (string) self::get( 'contact_email', '' ) );
		return '' !== $email ? $email : get_option( 'admin_email' );
	}

	/**
	 * Configured membership types, keyed by their slug.
	 *
	 * @return array<string,array>
	 */
	public static function membership_types() {
		$types = array();
		foreach ( (array) self::get( 'membership_types', array() ) as $type ) {
			if ( empty( $type['key'] ) || empty( $type['label'] ) ) {
				continue;
			}
			$types[ $type['key'] ] = wp_parse_args(
				$type,
				array(
					'fee'      => '',
					'group_id' => '',
					'family'   => 0,
				)
			);
		}
		/**
		 * Filter the membership types offered on the form.
		 *
		 * @param array $types Types keyed by slug: label, fee, group_id, family.
		 */
		return apply_filters( 'evmf_membership_types', $types );
	}

	/**
	 * Number of child rows offered for family memberships.
	 *
	 * @return int
	 */
	public static function max_children() {
		return max( 0, min( self::MAX_CHILDREN_LIMIT, (int) self::get( 'max_children', 4 ) ) );
	}

	/**
	 * Keys of the family custom fields that can be mapped to easyVerein custom field IDs.
	 *
	 * @param int|null $max_children Override for the number of children.
	 * @return array<string,string> Key => human label.
	 */
	public static function family_field_keys( $max_children = null ) {
		$max_children = null === $max_children ? self::max_children() : (int) $max_children;
		$keys         = array(
			'partner_name' => __( 'Partner name', 'easyverein-membership-form' ),
			'partner_dob'  => __( 'Partner date of birth', 'easyverein-membership-form' ),
		);
		for ( $i = 1; $i <= $max_children; $i++ ) {
			/* translators: %d: child number */
			$keys[ 'child_' . $i . '_name' ] = sprintf( __( 'Child %d name', 'easyverein-membership-form' ), $i );
			/* translators: %d: child number */
			$keys[ 'child_' . $i . '_dob' ] = sprintf( __( 'Child %d date of birth', 'easyverein-membership-form' ), $i );
		}
		return $keys;
	}

	/**
	 * Activation: defaults, upgrade, cron, log directory.
	 */
	public static function activate() {
		self::maybe_upgrade();
		self::ensure_cron();
		EVMF_Logger::ensure_log_dir();
	}

	/**
	 * Deactivation: stop scheduled jobs. Data is kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule the token maintenance job (twice daily keeps the 30 day token alive with margin).
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Create the default settings on first run and record the schema version.
	 */
	public static function maybe_upgrade() {
		$current = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', 'yes' );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		self::flush_settings_cache();
	}
}
