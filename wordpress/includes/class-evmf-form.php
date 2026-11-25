<?php
/**
 * Front-end form: shortcode, submission handling and validation.
 *
 * Submissions are processed on `template_redirect` (before any output) so that a
 * successful submission can redirect (Post/Redirect/Get) and a browser refresh
 * never submits the same application twice. On validation errors the form is
 * shown again with the entered values and inline messages.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [easyverein_membership_form].
 */
final class EVMF_Form {

	const SHORTCODE      = 'easyverein_membership_form';
	const SUCCESS_PARAM  = 'evmf';
	const HONEYPOT_FIELD = 'evmf_website';
	const TIME_FIELD     = 'evmf_ts';
	const MIN_FILL_TIME  = 3; // Seconds; bots usually submit instantly.

	/**
	 * Field-level errors of the current submission.
	 *
	 * @var array<string,string>
	 */
	private static $errors = array();

	/**
	 * General errors of the current submission.
	 *
	 * @var string[]
	 */
	private static $notices = array();

	/**
	 * Submitted values (for re-display).
	 *
	 * @var array
	 */
	private static $values = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_submission' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (and, on pages with the shortcode, enqueue) assets.
	 */
	public static function register_assets() {
		wp_register_style( 'evmf-form', EVMF_PLUGIN_URL . 'assets/css/form.css', array(), EVMF_VERSION );
		wp_register_script( 'evmf-form', EVMF_PLUGIN_URL . 'assets/js/form.js', array(), EVMF_VERSION, true );
		wp_localize_script(
			'evmf-form',
			'evmfForm',
			array(
				'maxChildren' => EVMF_Plugin::max_children(),
				'minAge'      => (int) EVMF_Plugin::get( 'min_age', 18 ),
				'familyTypes' => array_keys( array_filter( EVMF_Plugin::membership_types(), static function ( $t ) {
					return ! empty( $t['family'] );
				} ) ),
				'i18n'        => array(
					/* translators: %d: child number */
					'childName'    => __( 'Child %d – name', 'easyverein-membership-form' ),
					/* translators: %d: child number */
					'childDob'     => __( 'Child %d – date of birth', 'easyverein-membership-form' ),
					'remove'       => __( 'Remove', 'easyverein-membership-form' ),
					'invalidIban'  => __( 'Please enter a valid IBAN.', 'easyverein-membership-form' ),
					'invalidBic'   => __( 'Please enter a valid BIC (8 or 11 characters).', 'easyverein-membership-form' ),
					/* translators: %d: minimum age */
					'tooYoung'     => __( 'Applicants must be at least %d years old.', 'easyverein-membership-form' ),
					'captcha'      => __( 'Please confirm that you are not a robot.', 'easyverein-membership-form' ),
					'submitting'   => __( 'Submitting…', 'easyverein-membership-form' ),
				),
			)
		);

		if ( self::recaptcha_enabled() ) {
			wp_register_script( 'evmf-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			wp_enqueue_style( 'evmf-form' );
		}
	}

	/**
	 * Is reCAPTCHA configured?
	 *
	 * @return bool
	 */
	public static function recaptcha_enabled() {
		return '' !== trim( (string) EVMF_Plugin::get( 'recaptcha_site_key', '' ) )
			&& '' !== trim( (string) EVMF_Plugin::get( 'recaptcha_secret_key', '' ) );
	}

	/**
	 * Handle a POSTed application.
	 */
	public static function maybe_handle_submission() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; protected by honeypot, timing check and optional reCAPTCHA (nonces break with page caching).
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['evmf_submit'] ) ) {
			return;
		}

		$raw = isset( $_POST['evmf'] ) && is_array( $_POST['evmf'] ) ? wp_unslash( $_POST['evmf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in collect().
		$data         = self::collect( $raw );
		self::$values = $data;

		// Spam protection.
		if ( ! self::passes_spam_checks() ) {
			EVMF_Logger::warning( 'Submission rejected by spam protection (honeypot or timing).' );
			self::$notices[] = __( 'Your submission could not be verified. Please reload the page and try again.', 'easyverein-membership-form' );
			return;
		}
		if ( self::recaptcha_enabled() ) {
			$captcha = self::verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '' );
			if ( is_wp_error( $captcha ) ) {
				self::$notices[] = __( 'Please confirm that you are not a robot.', 'easyverein-membership-form' );
				return;
			}
		}
		// phpcs:enable

		self::$errors = self::validate( $data );
		if ( self::$errors ) {
			return;
		}

		/**
		 * Filter the validated data before it is sent to easyVerein.
		 * Return a WP_Error to abort with a message.
		 *
		 * @param array $data Validated form data.
		 */
		$data = apply_filters( 'evmf_before_submit', $data );
		if ( is_wp_error( $data ) ) {
			self::$notices[] = $data->get_error_message();
			return;
		}

		$service = new EVMF_Membership_Service();
		$result  = $service->create_application( $data );

		if ( ! $result['ok'] ) {
			self::$notices[] = self::friendly_error( $result['error_code'] );
			if ( in_array( $result['error_code'], array( 'unauthorized', 'unavailable', 'api' ), true ) ) {
				// Make sure the organisation does not lose the applicant because of a technical problem.
				EVMF_Mailer::send_admin_notification( $data, 0, array( __( 'The application could NOT be created in easyVerein:', 'easyverein-membership-form' ) . ' ' . $result['error'] ) );
			}
			return;
		}

		EVMF_Logger::info( 'Application created.', array( 'member_id' => $result['member_id'], 'email' => $data['email'] ) );
		EVMF_Mailer::send_confirmation( $data );
		EVMF_Mailer::send_admin_notification( $data, $result['member_id'], $result['warnings'] );

		$redirect = add_query_arg( self::SUCCESS_PARAM, 'success', self::current_url() ) . '#evmf-form';
		/**
		 * Filter the URL applicants are sent to after a successful submission.
		 *
		 * @param string $redirect  URL.
		 * @param int    $member_id easyVerein member ID.
		 */
		$redirect = apply_filters( 'evmf_success_redirect', $redirect, $result['member_id'] );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sanitize the raw POST array into a predictable structure.
	 *
	 * @param array $raw Unslashed POST data.
	 * @return array
	 */
	public static function collect( array $raw ) {
		$text = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		};

		$children = array();
		if ( isset( $raw['children'] ) && is_array( $raw['children'] ) ) {
			foreach ( array_values( $raw['children'] ) as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$name = isset( $child['name'] ) ? sanitize_text_field( (string) $child['name'] ) : '';
				$dob  = isset( $child['dob'] ) ? sanitize_text_field( (string) $child['dob'] ) : '';
				if ( '' === $name && '' === $dob ) {
					continue;
				}
				$children[] = array(
					'name' => $name,
					'dob'  => $dob,
				);
			}
		}

		return array(
			'membership_type'        => sanitize_key( $text( 'membership_type' ) ),
			'salutation'             => $text( 'salutation' ),
			'first_name'             => $text( 'first_name' ),
			'last_name'              => $text( 'last_name' ),
			'dob'                    => $text( 'dob' ),
			'email'                  => sanitize_email( $text( 'email' ) ),
			'phone'                  => $text( 'phone' ),
			'street'                 => $text( 'street' ),
			'zip'                    => $text( 'zip' ),
			'city'                   => $text( 'city' ),
			'country'                => strtoupper( $text( 'country' ) ),
			'partner_name'           => $text( 'partner_name' ),
			'partner_dob'            => $text( 'partner_dob' ),
			'children'               => array_slice( $children, 0, EVMF_Plugin::max_children() ),
			'account_holder'         => $text( 'account_holder' ),
			'account_holder_address' => $text( 'account_holder_address' ),
			'iban'                   => EVMF_Validator::normalize_bank_code( $text( 'iban' ) ),
			'bic'                    => EVMF_Validator::normalize_bank_code( $text( 'bic' ) ),
			'privacy_consent'        => ! empty( $raw['privacy_consent'] ),
			'sepa_consent'           => ! empty( $raw['sepa_consent'] ),
		);
	}

	/**
	 * Validate collected data.
	 *
	 * @param array $data Data from collect().
	 * @return array<string,string> Field => message.
	 */
	public static function validate( array $data ) {
		$errors   = array();
		$types    = EVMF_Plugin::membership_types();
		$required = __( 'This field is required.', 'easyverein-membership-form' );

		if ( ! isset( $types[ $data['membership_type'] ] ) ) {
			$errors['membership_type'] = __( 'Please choose a membership type.', 'easyverein-membership-form' );
		}
		if ( '' !== $data['salutation'] && ! array_key_exists( $data['salutation'], self::salutations() ) ) {
			$errors['salutation'] = __( 'Please choose a valid salutation.', 'easyverein-membership-form' );
		}
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( '' === $data[ $field ] ) {
				$errors[ $field ] = $required;
			} elseif ( mb_strlen( $data[ $field ] ) > 128 ) {
				$errors[ $field ] = __( 'Please use at most 128 characters.', 'easyverein-membership-form' );
			}
		}
		if ( '' === $data['email'] || ! is_email( $data['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid e-mail address.', 'easyverein-membership-form' );
		}

		$dob = EVMF_Validator::parse_date( $data['dob'] );
		if ( ! $dob || ! EVMF_Validator::is_plausible_birth_date( $dob ) ) {
			$errors['dob'] = __( 'Please enter a valid date of birth.', 'easyverein-membership-form' );
		} else {
			$min_age = (int) EVMF_Plugin::get( 'min_age', 18 );
			if ( $min_age > 0 && EVMF_Validator::age( $dob ) < $min_age ) {
				/* translators: %d: minimum age */
				$errors['dob'] = sprintf( __( 'Applicants must be at least %d years old.', 'easyverein-membership-form' ), $min_age );
			}
		}

		if ( EVMF_Plugin::get( 'require_address' ) ) {
			foreach ( array( 'street', 'zip', 'city' ) as $field ) {
				if ( '' === $data[ $field ] ) {
					$errors[ $field ] = $required;
				}
			}
		}
		if ( ! array_key_exists( $data['country'], self::countries() ) ) {
			$errors['country'] = __( 'Please choose a country.', 'easyverein-membership-form' );
		}
		if ( EVMF_Plugin::get( 'require_phone' ) && '' === $data['phone'] ) {
			$errors['phone'] = $required;
		}
		if ( '' !== $data['phone'] && ! preg_match( '/^[0-9 +()\/\-.]{4,30}$/', $data['phone'] ) ) {
			$errors['phone'] = __( 'Please enter a valid phone number.', 'easyverein-membership-form' );
		}

		// Family membership: partner and/or children.
		if ( isset( $types[ $data['membership_type'] ] ) && ! empty( $types[ $data['membership_type'] ]['family'] ) ) {
			if ( '' === $data['partner_name'] && empty( $data['children'] ) ) {
				$errors['partner_name'] = __( 'Please add at least one family member.', 'easyverein-membership-form' );
			}
			if ( '' !== $data['partner_dob'] && ! self::valid_birth_date( $data['partner_dob'] ) ) {
				$errors['partner_dob'] = __( 'Please enter a valid date of birth.', 'easyverein-membership-form' );
			}
			foreach ( $data['children'] as $i => $child ) {
				if ( '' === $child['name'] ) {
					$errors[ 'children_' . $i . '_name' ] = $required;
				}
				if ( '' !== $child['dob'] && ! self::valid_birth_date( $child['dob'] ) ) {
					$errors[ 'children_' . $i . '_dob' ] = __( 'Please enter a valid date of birth.', 'easyverein-membership-form' );
				}
			}
		}

		if ( EVMF_Plugin::get( 'sepa_enabled' ) ) {
			if ( '' === $data['account_holder'] ) {
				$errors['account_holder'] = $required;
			}
			if ( ! EVMF_Validator::is_valid_iban( $data['iban'] ) ) {
				$errors['iban'] = __( 'Please enter a valid IBAN.', 'easyverein-membership-form' );
			}
			if ( '' === $data['bic'] ) {
				if ( EVMF_Plugin::get( 'sepa_require_bic' ) ) {
					$errors['bic'] = $required;
				}
			} elseif ( ! EVMF_Validator::is_valid_bic( $data['bic'] ) ) {
				$errors['bic'] = __( 'Please enter a valid BIC (8 or 11 characters).', 'easyverein-membership-form' );
			}
			if ( ! $data['sepa_consent'] ) {
				$errors['sepa_consent'] = __( 'Please accept the SEPA direct debit mandate.', 'easyverein-membership-form' );
			}
		}

		if ( ! $data['privacy_consent'] ) {
			$errors['privacy_consent'] = __( 'Please accept the privacy policy.', 'easyverein-membership-form' );
		}

		/**
		 * Filter validation errors (field => message). Add your own rules here.
		 *
		 * @param array $errors Errors.
		 * @param array $data   Form data.
		 */
		return apply_filters( 'evmf_validation_errors', $errors, $data );
	}

	/**
	 * Shortcode output.
	 *
	 * @param array|string $atts Attributes: title.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Membership application', 'easyverein-membership-form' ),
			),
			$atts,
			self::SHORTCODE
		);

		wp_enqueue_style( 'evmf-form' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET[ self::SUCCESS_PARAM ] ) && 'success' === $_GET[ self::SUCCESS_PARAM ] ) {
			$message = trim( (string) EVMF_Plugin::get( 'success_message', '' ) );
			if ( '' === $message ) {
				$message = __( 'Thank you! Your membership application has been submitted. We have sent you a confirmation e-mail and will get back to you once your application has been reviewed.', 'easyverein-membership-form' );
			}
			return '<div id="evmf-form" class="evmf"><div class="evmf-notice evmf-notice--success" role="status">' . wp_kses_post( wpautop( $message ) ) . '</div></div>';
		}

		if ( ! is_admin() && '' === EVMF_Token_Store::get_token() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="evmf"><div class="evmf-notice evmf-notice--error">' . esc_html__( 'The membership form is not configured yet: please add your easyVerein API token under Settings › easyVerein Form.', 'easyverein-membership-form' ) . '</div></div>';
			}
			return '';
		}

		wp_enqueue_script( 'evmf-form' );
		if ( self::recaptcha_enabled() ) {
			wp_enqueue_script( 'evmf-recaptcha' );
		}

		$min_age = (int) EVMF_Plugin::get( 'min_age', 18 );
		$today   = new DateTimeImmutable( 'today', wp_timezone() );

		$vars = array(
			'title'            => $atts['title'],
			'values'           => wp_parse_args( self::$values, self::collect( array() ) ),
			'errors'           => self::$errors,
			'notices'          => self::$notices,
			'types'            => EVMF_Plugin::membership_types(),
			'salutations'      => self::salutations(),
			'countries'        => self::countries(),
			'default_country'  => (string) EVMF_Plugin::get( 'default_country', 'DE' ),
			'max_children'     => EVMF_Plugin::max_children(),
			'min_age'          => $min_age,
			'max_dob'          => $min_age > 0 ? $today->modify( '-' . $min_age . ' years' )->format( 'Y-m-d' ) : $today->format( 'Y-m-d' ),
			'today'            => $today->format( 'Y-m-d' ),
			'require_address'  => (bool) EVMF_Plugin::get( 'require_address' ),
			'require_phone'    => (bool) EVMF_Plugin::get( 'require_phone' ),
			'sepa_enabled'     => (bool) EVMF_Plugin::get( 'sepa_enabled' ),
			'sepa_require_bic' => (bool) EVMF_Plugin::get( 'sepa_require_bic' ),
			'creditor_name'    => self::creditor_name(),
			'creditor_address' => (string) EVMF_Plugin::get( 'sepa_creditor_address', '' ),
			'creditor_id'      => (string) EVMF_Plugin::get( 'sepa_creditor_id', '' ),
			'mandate_text'     => self::mandate_text(),
			'privacy_url'      => self::privacy_url(),
			'org_name'         => EVMF_Plugin::org_name(),
			'recaptcha_key'    => self::recaptcha_enabled() ? (string) EVMF_Plugin::get( 'recaptcha_site_key' ) : '',
			'honeypot_field'   => self::HONEYPOT_FIELD,
			'time_field'       => self::TIME_FIELD,
			'time_token'       => self::time_token(),
		);

		ob_start();
		self::load_template( 'form.php', $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Load a template from the theme (easyverein-membership-form/{name}) or the plugin.
	 *
	 * @param string $name Template file name.
	 * @param array  $vars Variables made available to the template.
	 */
	public static function load_template( $name, array $vars ) {
		$file = locate_template( 'easyverein-membership-form/' . $name );
		if ( ! $file ) {
			$file = EVMF_PLUGIN_DIR . 'templates/' . $name;
		}
		/**
		 * Filter the template file path.
		 *
		 * @param string $file Absolute path.
		 * @param string $name Template name.
		 */
		$file = apply_filters( 'evmf_template', $file, $name );
		if ( is_readable( $file ) ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $file;
		}
	}

	/**
	 * Salutation options: value stored in easyVerein => label.
	 *
	 * @return array<string,string>
	 */
	public static function salutations() {
		/**
		 * Filter salutation options. Keys are stored in easyVerein's "salutation" field.
		 *
		 * @param array $salutations Value => label.
		 */
		return apply_filters(
			'evmf_salutations',
			array(
				'Frau'   => __( 'Ms', 'easyverein-membership-form' ),
				'Herr'   => __( 'Mr', 'easyverein-membership-form' ),
				'Divers' => __( 'Mx', 'easyverein-membership-form' ),
			)
		);
	}

	/**
	 * Countries offered on the form (ISO 3166-1 alpha-2 => name).
	 *
	 * @return array<string,string>
	 */
	public static function countries() {
		$all = array(
			'AL' => __( 'Albania', 'easyverein-membership-form' ),
			'AT' => __( 'Austria', 'easyverein-membership-form' ),
			'BE' => __( 'Belgium', 'easyverein-membership-form' ),
			'BG' => __( 'Bulgaria', 'easyverein-membership-form' ),
			'CH' => __( 'Switzerland', 'easyverein-membership-form' ),
			'CY' => __( 'Cyprus', 'easyverein-membership-form' ),
			'CZ' => __( 'Czechia', 'easyverein-membership-form' ),
			'DE' => __( 'Germany', 'easyverein-membership-form' ),
			'DK' => __( 'Denmark', 'easyverein-membership-form' ),
			'EE' => __( 'Estonia', 'easyverein-membership-form' ),
			'ES' => __( 'Spain', 'easyverein-membership-form' ),
			'FI' => __( 'Finland', 'easyverein-membership-form' ),
			'FR' => __( 'France', 'easyverein-membership-form' ),
			'GB' => __( 'United Kingdom', 'easyverein-membership-form' ),
			'GR' => __( 'Greece', 'easyverein-membership-form' ),
			'HR' => __( 'Croatia', 'easyverein-membership-form' ),
			'HU' => __( 'Hungary', 'easyverein-membership-form' ),
			'IE' => __( 'Ireland', 'easyverein-membership-form' ),
			'IN' => __( 'India', 'easyverein-membership-form' ),
			'IS' => __( 'Iceland', 'easyverein-membership-form' ),
			'IT' => __( 'Italy', 'easyverein-membership-form' ),
			'LI' => __( 'Liechtenstein', 'easyverein-membership-form' ),
			'LT' => __( 'Lithuania', 'easyverein-membership-form' ),
			'LU' => __( 'Luxembourg', 'easyverein-membership-form' ),
			'LV' => __( 'Latvia', 'easyverein-membership-form' ),
			'MC' => __( 'Monaco', 'easyverein-membership-form' ),
			'MT' => __( 'Malta', 'easyverein-membership-form' ),
			'NL' => __( 'Netherlands', 'easyverein-membership-form' ),
			'NO' => __( 'Norway', 'easyverein-membership-form' ),
			'PL' => __( 'Poland', 'easyverein-membership-form' ),
			'PT' => __( 'Portugal', 'easyverein-membership-form' ),
			'RO' => __( 'Romania', 'easyverein-membership-form' ),
			'SE' => __( 'Sweden', 'easyverein-membership-form' ),
			'SI' => __( 'Slovenia', 'easyverein-membership-form' ),
			'SK' => __( 'Slovakia', 'easyverein-membership-form' ),
			'TR' => __( 'Türkiye', 'easyverein-membership-form' ),
			'UA' => __( 'Ukraine', 'easyverein-membership-form' ),
			'US' => __( 'United States', 'easyverein-membership-form' ),
		);
		$codes     = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) EVMF_Plugin::get( 'countries', '' ) ) ) ) );
		$countries = array();
		foreach ( $codes as $code ) {
			$countries[ $code ] = isset( $all[ $code ] ) ? $all[ $code ] : $code;
		}
		if ( empty( $countries ) ) {
			$countries = $all;
		}
		/**
		 * Filter the country list (ISO code => label). easyVerein stores the ISO code.
		 *
		 * @param array $countries Countries.
		 */
		return apply_filters( 'evmf_countries', $countries );
	}

	/**
	 * Name of the SEPA creditor (defaults to the organisation name).
	 *
	 * @return string
	 */
	public static function creditor_name() {
		$name = trim( (string) EVMF_Plugin::get( 'sepa_creditor_name', '' ) );
		return '' !== $name ? $name : EVMF_Plugin::org_name();
	}

	/**
	 * SEPA mandate wording. Default: the standard German and English mandate text.
	 *
	 * @return string
	 */
	public static function mandate_text() {
		$text = trim( (string) EVMF_Plugin::get( 'sepa_mandate_text', '' ) );
		if ( '' === $text ) {
			$german = 'Ich ermächtige {creditor}, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {creditor} auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.';
			$english = 'I authorise {creditor} to collect payments from my account by direct debit. At the same time, I instruct my bank to honour the direct debits drawn on my account by {creditor}. Note: I can request a refund of the debited amount within eight weeks, starting from the debit date. The conditions agreed with my bank apply.';
			// German sites show the German wording only; all others show English plus the German original.
			$text = 0 === strpos( determine_locale(), 'de' ) ? $german : $english . "\n\n" . $german;
		}
		// Avoid "e.V.." when the name already ends with a period.
		$text = str_replace( '{creditor}.', rtrim( self::creditor_name(), '.' ) . '.', $text );
		$text = str_replace( '{creditor}', self::creditor_name(), $text );
		/**
		 * Filter the SEPA mandate text shown next to the consent checkbox.
		 *
		 * @param string $text Text (plain text, line breaks allowed).
		 */
		return apply_filters( 'evmf_sepa_mandate_text', $text );
	}

	/**
	 * Privacy policy URL (setting, else the WordPress privacy page).
	 *
	 * @return string
	 */
	public static function privacy_url() {
		$url = trim( (string) EVMF_Plugin::get( 'privacy_url', '' ) );
		return '' !== $url ? $url : get_privacy_policy_url();
	}

	/**
	 * Applicant-facing message for an API failure category.
	 *
	 * @param string $code Category from EVMF_Membership_Service.
	 * @return string
	 */
	private static function friendly_error( $code ) {
		$contact = EVMF_Plugin::contact_email();
		switch ( $code ) {
			case 'duplicate':
				return __( 'It looks like there is already an account with this e-mail address. Please use a different e-mail address or contact us.', 'easyverein-membership-form' );
			case 'validation':
				/* translators: %s: contact e-mail address */
				return sprintf( __( 'Some of your details could not be accepted. Please check your entries and try again. If the problem persists, contact us at %s.', 'easyverein-membership-form' ), $contact );
			default:
				/* translators: %s: contact e-mail address */
				return sprintf( __( 'Your application could not be submitted because of a technical problem. We have been notified. Please try again later or contact us at %s.', 'easyverein-membership-form' ), $contact );
		}
	}

	/**
	 * Honeypot and minimum fill time.
	 *
	 * @return bool
	 */
	private static function passes_spam_checks() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST[ self::HONEYPOT_FIELD ] ) ) {
			return false;
		}
		$token = isset( $_POST[ self::TIME_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TIME_FIELD ] ) ) : '';
		// phpcs:enable
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( ! hash_equals( wp_hash( 'evmf_ts|' . $parts[0] ), $parts[1] ) ) {
			return false;
		}
		return ( time() - (int) $parts[0] ) >= self::MIN_FILL_TIME;
	}

	/**
	 * Signed timestamp for the timing check.
	 *
	 * @return string
	 */
	private static function time_token() {
		$now = (string) time();
		return $now . '.' . wp_hash( 'evmf_ts|' . $now );
	}

	/**
	 * Verify a reCAPTCHA v2 response.
	 *
	 * @param string $response Token from the widget.
	 * @return true|WP_Error
	 */
	private static function verify_recaptcha( $response ) {
		if ( '' === $response ) {
			return new WP_Error( 'evmf_captcha', 'empty' );
		}
		$result = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => (string) EVMF_Plugin::get( 'recaptcha_secret_key' ),
					'response' => $response,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			EVMF_Logger::error( 'reCAPTCHA verification request failed: ' . $result->get_error_message() );
			return $result;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $result ), true );
		if ( empty( $body['success'] ) ) {
			EVMF_Logger::warning( 'reCAPTCHA verification failed.', array( 'codes' => isset( $body['error-codes'] ) ? $body['error-codes'] : array() ) );
			return new WP_Error( 'evmf_captcha', 'failed' );
		}
		return true;
	}

	/**
	 * Check an optional family member's birth date.
	 *
	 * @param string $value Date string.
	 * @return bool
	 */
	private static function valid_birth_date( $value ) {
		$date = EVMF_Validator::parse_date( $value );
		return $date && EVMF_Validator::is_plausible_birth_date( $date );
	}

	/**
	 * URL of the current request (for the redirect after submission).
	 *
	 * @return string
	 */
	private static function current_url() {
		$fallback = home_url( '/' );
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $fallback;
		}
		$url = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$url = remove_query_arg( self::SUCCESS_PARAM, $url );
		return wp_validate_redirect( $url, $fallback );
	}
}
