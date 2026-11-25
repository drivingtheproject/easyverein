<?php
/**
 * HTML e-mail template (applicant confirmation and admin notification).
 *
 * Copy to yourtheme/easyverein-membership-form/email.php to customise.
 *
 * @package EasyVereinMembershipForm
 *
 * @var array    $data      Form data.
 * @var string   $audience  "applicant" or "admin".
 * @var string[] $warnings  Problems (admin e-mail only).
 * @var int      $member_id easyVerein member ID (admin e-mail only, 0 if creation failed).
 * @var string   $org_name  Organisation name.
 * @var array    $types     Membership types.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evmf_type_label = isset( $types[ $data['membership_type'] ] ) ? $types[ $data['membership_type'] ]['label'] : $data['membership_type'];
$evmf_countries  = EVMF_Form::countries();
$evmf_salutation = EVMF_Form::salutations();

$evmf_rows = array(
	__( 'Membership type', 'easyverein-membership-form' ) => $evmf_type_label,
	__( 'Salutation', 'easyverein-membership-form' )      => isset( $evmf_salutation[ $data['salutation'] ] ) ? $evmf_salutation[ $data['salutation'] ] : '',
	__( 'Name', 'easyverein-membership-form' )            => trim( $data['first_name'] . ' ' . $data['last_name'] ),
	__( 'Date of birth', 'easyverein-membership-form' )   => $data['dob'],
	__( 'E-mail', 'easyverein-membership-form' )          => $data['email'],
	__( 'Phone', 'easyverein-membership-form' )           => $data['phone'],
	__( 'Address', 'easyverein-membership-form' )         => trim( $data['street'] . ', ' . $data['zip'] . ' ' . $data['city'] . ', ' . ( isset( $evmf_countries[ $data['country'] ] ) ? $evmf_countries[ $data['country'] ] : $data['country'] ), ' ,' ),
);

$evmf_family = array();
if ( '' !== $data['partner_name'] ) {
	$evmf_family[ __( 'Partner', 'easyverein-membership-form' ) ] = $data['partner_name'] . ( '' !== $data['partner_dob'] ? ' (' . $data['partner_dob'] . ')' : '' );
}
foreach ( $data['children'] as $evmf_i => $evmf_child ) {
	/* translators: %d: child number */
	$evmf_family[ sprintf( __( 'Child %d', 'easyverein-membership-form' ), $evmf_i + 1 ) ] = $evmf_child['name'] . ( '' !== $evmf_child['dob'] ? ' (' . $evmf_child['dob'] . ')' : '' );
}

$evmf_sepa = array();
if ( '' !== $data['iban'] ) {
	$evmf_sepa = array(
		__( 'Account holder', 'easyverein-membership-form' ) => $data['account_holder'],
		__( 'IBAN', 'easyverein-membership-form' )           => EVMF_Mailer::display_iban( $data['iban'] ),
		__( 'BIC', 'easyverein-membership-form' )            => $data['bic'],
	);
	if ( '' !== $data['account_holder_address'] ) {
		$evmf_sepa[ __( 'Account holder address', 'easyverein-membership-form' ) ] = $data['account_holder_address'];
	}
}

$evmf_table = static function ( array $rows ) {
	$html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px;">';
	foreach ( $rows as $label => $value ) {
		if ( '' === (string) $value ) {
			continue;
		}
		$html .= '<tr><td style="padding:8px;border:1px solid #e2e4e7;background:#f6f7f7;font-weight:bold;width:38%;">' . esc_html( $label ) . '</td>';
		$html .= '<td style="padding:8px;border:1px solid #e2e4e7;">' . esc_html( $value ) . '</td></tr>';
	}
	return $html . '</table>';
};
$evmf_h = 'style="font-size:16px;margin:24px 0 8px;color:#1d2327;"';
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1d2327;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;padding:28px;border-radius:6px;">
	<?php if ( 'admin' === $audience ) : ?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'New membership application', 'easyverein-membership-form' ); ?></h2>
		<?php if ( $member_id ) : ?>
			<?php /* translators: %d: easyVerein member ID */ ?>
			<p><?php echo esc_html( sprintf( __( 'The application was created in easyVerein (member ID %d).', 'easyverein-membership-form' ), $member_id ) ); ?></p>
		<?php endif; ?>
		<?php if ( $warnings ) : ?>
			<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px;margin:0 0 20px;">
				<strong><?php esc_html_e( 'Please check:', 'easyverein-membership-form' ); ?></strong>
				<ul style="margin:8px 0 0;padding-left:18px;">
					<?php foreach ( $warnings as $evmf_warning ) : ?>
						<li><?php echo esc_html( $evmf_warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Thank you for your application', 'easyverein-membership-form' ); ?></h2>
		<?php /* translators: %s: first name */ ?>
		<p><?php echo esc_html( sprintf( __( 'Hello %s,', 'easyverein-membership-form' ), $data['first_name'] ) ); ?></p>
		<?php /* translators: %s: organisation name */ ?>
		<p><?php echo esc_html( sprintf( __( 'we have received your membership application for %s. Here is a summary of your details. We will contact you once your application has been reviewed.', 'easyverein-membership-form' ), $org_name ) ); ?></p>
	<?php endif; ?>

	<h3 <?php echo $evmf_h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Applicant', 'easyverein-membership-form' ); ?></h3>
	<?php echo $evmf_table( $evmf_rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<?php if ( $evmf_family ) : ?>
		<h3 <?php echo $evmf_h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Family members', 'easyverein-membership-form' ); ?></h3>
		<?php echo $evmf_table( $evmf_family ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php endif; ?>

	<?php if ( $evmf_sepa ) : ?>
		<h3 <?php echo $evmf_h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'SEPA direct debit mandate', 'easyverein-membership-form' ); ?></h3>
		<?php echo $evmf_table( $evmf_sepa ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( 'applicant' === $audience ) : ?>
			<p style="font-size:12px;color:#50575e;"><?php echo nl2br( esc_html( EVMF_Form::mandate_text() ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'applicant' === $audience ) : ?>
		<p><?php esc_html_e( 'Kind regards,', 'easyverein-membership-form' ); ?><br><?php echo esc_html( $org_name ); ?></p>
	<?php endif; ?>
</div>
</body>
</html>
