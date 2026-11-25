<?php
/**
 * Confirmation and notification e-mails.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends HTML e-mails rendered from templates/email.php (overridable in the theme).
 */
final class EVMF_Mailer {

	/**
	 * Send the confirmation to the applicant.
	 *
	 * @param array $data Form data.
	 * @return bool
	 */
	public static function send_confirmation( array $data ) {
		if ( ! EVMF_Plugin::get( 'send_confirmation' ) ) {
			return true;
		}
		$subject = trim( (string) EVMF_Plugin::get( 'confirmation_subject', '' ) );
		if ( '' === $subject ) {
			/* translators: %s: organisation name */
			$subject = sprintf( __( 'Your membership application at %s', 'easyverein-membership-form' ), EVMF_Plugin::org_name() );
		}
		$subject = str_replace( '{org}', EVMF_Plugin::org_name(), $subject );

		$body = self::render(
			array(
				'data'     => $data,
				'audience' => 'applicant',
				'warnings' => array(),
			)
		);

		$headers   = self::headers();
		$headers[] = 'Reply-To: ' . EVMF_Plugin::contact_email();

		$sent = wp_mail( $data['email'], $subject, $body, $headers );
		if ( ! $sent ) {
			EVMF_Logger::error( 'Confirmation e-mail could not be sent.', array( 'email' => $data['email'] ) );
		}
		return $sent;
	}

	/**
	 * Notify the organisation about a new application.
	 *
	 * @param array    $data      Form data.
	 * @param int      $member_id easyVerein member ID.
	 * @param string[] $warnings  Non-fatal problems during creation.
	 * @return bool
	 */
	public static function send_admin_notification( array $data, $member_id, array $warnings ) {
		if ( ! EVMF_Plugin::get( 'notify_admin' ) ) {
			return true;
		}
		$to = sanitize_email( (string) EVMF_Plugin::get( 'notify_email', '' ) );
		if ( '' === $to ) {
			$to = get_option( 'admin_email' );
		}
		/* translators: 1: first name, 2: last name */
		$subject = sprintf( __( 'New membership application: %1$s %2$s', 'easyverein-membership-form' ), $data['first_name'], $data['last_name'] );
		if ( $warnings ) {
			$subject .= ' ' . __( '(needs attention)', 'easyverein-membership-form' );
		}

		$body = self::render(
			array(
				'data'      => $data,
				'audience'  => 'admin',
				'warnings'  => $warnings,
				'member_id' => (int) $member_id,
			)
		);

		$headers   = self::headers();
		$headers[] = 'Reply-To: ' . $data['email'];

		return wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * IBAN for display: masked by default ("DE89 •••• •••• •••• 3000").
	 *
	 * @param string $iban IBAN.
	 * @return string
	 */
	public static function display_iban( $iban ) {
		$iban = (string) $iban;
		if ( '' === $iban ) {
			return '';
		}
		$mask   = (bool) EVMF_Plugin::get( 'mask_iban_in_emails', 1 );
		$len    = strlen( $iban );
		$pos    = 0;
		$groups = array();
		foreach ( str_split( $iban, 4 ) as $chunk ) {
			$group      = '';
			$chunk_len  = strlen( $chunk );
			for ( $i = 0; $i < $chunk_len; $i++, $pos++ ) {
				$group .= ( ! $mask || $pos < 4 || $pos >= $len - 4 ) ? $chunk[ $i ] : '•';
			}
			$groups[] = $group;
		}
		return implode( ' ', $groups );
	}

	/**
	 * Common headers.
	 *
	 * @return string[]
	 */
	private static function headers() {
		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_email = sanitize_email( (string) EVMF_Plugin::get( 'from_email', '' ) );
		if ( '' !== $from_email ) {
			$from_name = trim( (string) EVMF_Plugin::get( 'from_name', '' ) );
			$from_name = '' !== $from_name ? $from_name : EVMF_Plugin::org_name();
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $from_name ), $from_email );
		}
		return $headers;
	}

	/**
	 * Render the e-mail template.
	 *
	 * @param array $vars Template variables.
	 * @return string
	 */
	private static function render( array $vars ) {
		$vars['org_name'] = EVMF_Plugin::org_name();
		$vars['types']    = EVMF_Plugin::membership_types();
		/**
		 * Filter variables passed to the e-mail template.
		 *
		 * @param array $vars Variables.
		 */
		$vars = apply_filters( 'evmf_email_template_vars', $vars );

		ob_start();
		EVMF_Form::load_template( 'email.php', $vars );
		return (string) ob_get_clean();
	}
}
