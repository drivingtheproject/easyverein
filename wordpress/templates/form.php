<?php
/**
 * Membership form template.
 *
 * Copy this file to yourtheme/easyverein-membership-form/form.php to customise it.
 * Keep the field names (evmf[...]) intact; they are what the plugin reads.
 *
 * Available variables: see EVMF_Form::render_shortcode().
 *
 * @package EasyVereinMembershipForm
 *
 * @var string $title
 * @var array  $values
 * @var array  $errors
 * @var array  $notices
 * @var array  $types
 * @var array  $salutations
 * @var array  $countries
 * @var string $default_country
 * @var int    $max_children
 * @var int    $min_age
 * @var string $max_dob
 * @var string $today
 * @var bool   $require_address
 * @var bool   $require_phone
 * @var bool   $sepa_enabled
 * @var bool   $sepa_require_bic
 * @var string $creditor_name
 * @var string $creditor_address
 * @var string $creditor_id
 * @var string $mandate_text
 * @var string $privacy_url
 * @var string $org_name
 * @var string $recaptcha_key
 * @var string $honeypot_field
 * @var string $time_field
 * @var string $time_token
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$evmf_err = static function ( $key ) use ( $errors ) {
	if ( empty( $errors[ $key ] ) ) {
		return '';
	}
	return '<span class="evmf-field-error" id="evmf-err-' . esc_attr( $key ) . '">' . esc_html( $errors[ $key ] ) . '</span>';
};
$evmf_aria = static function ( $key ) use ( $errors ) {
	return empty( $errors[ $key ] ) ? '' : ' aria-invalid="true" aria-describedby="evmf-err-' . esc_attr( $key ) . '"';
};
$evmf_req     = ' <span class="evmf-required" aria-hidden="true">*</span>';
$evmf_country = '' !== $values['country'] ? $values['country'] : $default_country;
$evmf_family  = isset( $types[ $values['membership_type'] ] ) && ! empty( $types[ $values['membership_type'] ]['family'] );
?>
<div id="evmf-form" class="evmf">
	<?php if ( '' !== $title ) : ?>
		<h3 class="evmf-title"><?php echo esc_html( $title ); ?></h3>
	<?php endif; ?>

	<?php if ( $notices || $errors ) : ?>
		<div class="evmf-notice evmf-notice--error" role="alert" tabindex="-1">
			<?php foreach ( $notices as $notice ) : ?>
				<p><?php echo esc_html( $notice ); ?></p>
			<?php endforeach; ?>
			<?php if ( $errors ) : ?>
				<p><?php esc_html_e( 'Please correct the highlighted fields.', 'easyverein-membership-form' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="#evmf-form" class="evmf-form">
		<input type="hidden" name="evmf_submit" value="1">
		<input type="hidden" name="<?php echo esc_attr( $time_field ); ?>" value="<?php echo esc_attr( $time_token ); ?>">
		<div class="evmf-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $honeypot_field ); ?>"><?php esc_html_e( 'Leave this field empty', 'easyverein-membership-form' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $honeypot_field ); ?>" name="<?php echo esc_attr( $honeypot_field ); ?>" value="" tabindex="-1" autocomplete="off">
		</div>

		<fieldset class="evmf-fieldset">
			<legend><?php esc_html_e( 'Membership', 'easyverein-membership-form' ); ?></legend>
			<div class="evmf-row">
				<p class="evmf-col">
					<label for="evmf-membership-type"><?php esc_html_e( 'Membership type', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<select id="evmf-membership-type" name="evmf[membership_type]" required<?php echo $evmf_aria( 'membership_type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( '— Please choose —', 'easyverein-membership-form' ); ?></option>
						<?php foreach ( $types as $key => $type ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" data-family="<?php echo ! empty( $type['family'] ) ? '1' : '0'; ?>" <?php selected( $values['membership_type'], $key ); ?>>
								<?php echo esc_html( $type['label'] . ( '' !== $type['fee'] ? ' (' . $type['fee'] . ')' : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php echo $evmf_err( 'membership_type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</div>
		</fieldset>

		<fieldset class="evmf-fieldset">
			<legend><?php esc_html_e( 'Personal details', 'easyverein-membership-form' ); ?></legend>
			<div class="evmf-row">
				<p class="evmf-col evmf-col--narrow">
					<label for="evmf-salutation"><?php esc_html_e( 'Salutation', 'easyverein-membership-form' ); ?></label>
					<select id="evmf-salutation" name="evmf[salutation]"<?php echo $evmf_aria( 'salutation' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( '—', 'easyverein-membership-form' ); ?></option>
						<?php foreach ( $salutations as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['salutation'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php echo $evmf_err( 'salutation' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-first-name"><?php esc_html_e( 'First name', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="evmf-first-name" name="evmf[first_name]" value="<?php echo esc_attr( $values['first_name'] ); ?>" autocomplete="given-name" maxlength="128" required<?php echo $evmf_aria( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-last-name"><?php esc_html_e( 'Last name', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="evmf-last-name" name="evmf[last_name]" value="<?php echo esc_attr( $values['last_name'] ); ?>" autocomplete="family-name" maxlength="128" required<?php echo $evmf_aria( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</div>

			<div class="evmf-row">
				<p class="evmf-col">
					<label for="evmf-dob">
						<?php esc_html_e( 'Date of birth', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $min_age > 0 ) : ?>
							<?php /* translators: %d: minimum age */ ?>
							<small class="evmf-hint"><?php echo esc_html( sprintf( __( '(minimum age %d)', 'easyverein-membership-form' ), $min_age ) ); ?></small>
						<?php endif; ?>
					</label>
					<input type="date" id="evmf-dob" name="evmf[dob]" value="<?php echo esc_attr( $values['dob'] ); ?>" max="<?php echo esc_attr( $max_dob ); ?>" min="1900-01-01" autocomplete="bday" required<?php echo $evmf_aria( 'dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-email"><?php esc_html_e( 'E-mail', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="email" id="evmf-email" name="evmf[email]" value="<?php echo esc_attr( $values['email'] ); ?>" autocomplete="email" required<?php echo $evmf_aria( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-phone"><?php esc_html_e( 'Phone', 'easyverein-membership-form' ); ?><?php echo $require_phone ? $evmf_req : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="tel" id="evmf-phone" name="evmf[phone]" value="<?php echo esc_attr( $values['phone'] ); ?>" autocomplete="tel"<?php echo $require_phone ? ' required' : ''; ?><?php echo $evmf_aria( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</div>

			<div class="evmf-row">
				<p class="evmf-col evmf-col--wide">
					<label for="evmf-street"><?php esc_html_e( 'Street and house number', 'easyverein-membership-form' ); ?><?php echo $require_address ? $evmf_req : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="evmf-street" name="evmf[street]" value="<?php echo esc_attr( $values['street'] ); ?>" autocomplete="street-address" maxlength="128"<?php echo $require_address ? ' required' : ''; ?><?php echo $evmf_aria( 'street' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'street' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</div>
			<div class="evmf-row">
				<p class="evmf-col evmf-col--narrow">
					<label for="evmf-zip"><?php esc_html_e( 'Postal code', 'easyverein-membership-form' ); ?><?php echo $require_address ? $evmf_req : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="evmf-zip" name="evmf[zip]" value="<?php echo esc_attr( $values['zip'] ); ?>" autocomplete="postal-code" maxlength="20"<?php echo $require_address ? ' required' : ''; ?><?php echo $evmf_aria( 'zip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'zip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-city"><?php esc_html_e( 'City', 'easyverein-membership-form' ); ?><?php echo $require_address ? $evmf_req : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="evmf-city" name="evmf[city]" value="<?php echo esc_attr( $values['city'] ); ?>" autocomplete="address-level2" maxlength="100"<?php echo $require_address ? ' required' : ''; ?><?php echo $evmf_aria( 'city' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php echo $evmf_err( 'city' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="evmf-col">
					<label for="evmf-country"><?php esc_html_e( 'Country', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<select id="evmf-country" name="evmf[country]" autocomplete="country"<?php echo $evmf_aria( 'country' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php foreach ( $countries as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $evmf_country, $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php echo $evmf_err( 'country' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</div>
		</fieldset>

		<?php if ( array_filter( wp_list_pluck( $types, 'family' ) ) ) : ?>
			<fieldset class="evmf-fieldset evmf-family" id="evmf-family"<?php echo $evmf_family ? '' : ' hidden disabled'; ?>>
				<legend><?php esc_html_e( 'Family members', 'easyverein-membership-form' ); ?></legend>
				<?php echo $evmf_err( 'partner_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="evmf-row">
					<p class="evmf-col">
						<label for="evmf-partner-name"><?php esc_html_e( 'Partner – name', 'easyverein-membership-form' ); ?></label>
						<input type="text" id="evmf-partner-name" name="evmf[partner_name]" value="<?php echo esc_attr( $values['partner_name'] ); ?>" maxlength="128"<?php echo $evmf_aria( 'partner_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					</p>
					<p class="evmf-col">
						<label for="evmf-partner-dob"><?php esc_html_e( 'Partner – date of birth', 'easyverein-membership-form' ); ?></label>
						<input type="date" id="evmf-partner-dob" name="evmf[partner_dob]" value="<?php echo esc_attr( $values['partner_dob'] ); ?>" max="<?php echo esc_attr( $today ); ?>" min="1900-01-01"<?php echo $evmf_aria( 'partner_dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo $evmf_err( 'partner_dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				</div>

				<?php if ( $max_children > 0 ) : ?>
					<h4 class="evmf-subtitle"><?php esc_html_e( 'Children', 'easyverein-membership-form' ); ?></h4>
					<div class="evmf-children" id="evmf-children" data-max="<?php echo esc_attr( (string) $max_children ); ?>">
						<?php foreach ( $values['children'] as $i => $child ) : ?>
							<div class="evmf-row evmf-child">
								<p class="evmf-col">
									<?php /* translators: %d: child number */ ?>
									<label for="evmf-child-<?php echo (int) $i; ?>-name"><?php echo esc_html( sprintf( __( 'Child %d – name', 'easyverein-membership-form' ), $i + 1 ) ); ?></label>
									<input type="text" id="evmf-child-<?php echo (int) $i; ?>-name" name="evmf[children][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $child['name'] ); ?>" maxlength="128"<?php echo $evmf_aria( 'children_' . $i . '_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<?php echo $evmf_err( 'children_' . $i . '_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</p>
								<p class="evmf-col">
									<?php /* translators: %d: child number */ ?>
									<label for="evmf-child-<?php echo (int) $i; ?>-dob"><?php echo esc_html( sprintf( __( 'Child %d – date of birth', 'easyverein-membership-form' ), $i + 1 ) ); ?></label>
									<input type="date" id="evmf-child-<?php echo (int) $i; ?>-dob" name="evmf[children][<?php echo (int) $i; ?>][dob]" value="<?php echo esc_attr( $child['dob'] ); ?>" max="<?php echo esc_attr( $today ); ?>"<?php echo $evmf_aria( 'children_' . $i . '_dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<?php echo $evmf_err( 'children_' . $i . '_dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</p>
								<p class="evmf-col evmf-col--action">
									<button type="button" class="evmf-button evmf-button--secondary evmf-remove-child"><?php esc_html_e( 'Remove', 'easyverein-membership-form' ); ?></button>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
					<p>
						<button type="button" class="evmf-button evmf-button--secondary" id="evmf-add-child">
							<?php /* translators: %d: maximum number of children */ ?>
							<?php echo esc_html( sprintf( __( '+ Add child (up to %d)', 'easyverein-membership-form' ), $max_children ) ); ?>
						</button>
					</p>
					<template id="evmf-child-template">
						<div class="evmf-row evmf-child">
							<p class="evmf-col">
								<label for="evmf-child-__i__-name">__name_label__</label>
								<input type="text" id="evmf-child-__i__-name" name="evmf[children][__i__][name]" maxlength="128" required>
							</p>
							<p class="evmf-col">
								<label for="evmf-child-__i__-dob">__dob_label__</label>
								<input type="date" id="evmf-child-__i__-dob" name="evmf[children][__i__][dob]" max="<?php echo esc_attr( $today ); ?>">
							</p>
							<p class="evmf-col evmf-col--action">
								<button type="button" class="evmf-button evmf-button--secondary evmf-remove-child"><?php esc_html_e( 'Remove', 'easyverein-membership-form' ); ?></button>
							</p>
						</div>
					</template>
				<?php endif; ?>
			</fieldset>
		<?php endif; ?>

		<?php if ( $sepa_enabled ) : ?>
			<fieldset class="evmf-fieldset evmf-sepa">
				<legend><?php esc_html_e( 'SEPA direct debit mandate', 'easyverein-membership-form' ); ?></legend>
				<p class="evmf-creditor">
					<?php
					echo esc_html__( 'Creditor:', 'easyverein-membership-form' ) . ' ' . esc_html( $creditor_name );
					if ( '' !== $creditor_address ) {
						echo ', ' . esc_html( $creditor_address );
					}
					if ( '' !== $creditor_id ) {
						echo '<br>' . esc_html__( 'Creditor identifier:', 'easyverein-membership-form' ) . ' ' . esc_html( $creditor_id );
					}
					?>
				</p>
				<div class="evmf-row">
					<p class="evmf-col">
						<label for="evmf-account-holder"><?php esc_html_e( 'Account holder', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="evmf-account-holder" name="evmf[account_holder]" value="<?php echo esc_attr( $values['account_holder'] ); ?>" autocomplete="name" maxlength="128" required<?php echo $evmf_aria( 'account_holder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo $evmf_err( 'account_holder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
					<p class="evmf-col">
						<label for="evmf-account-holder-address"><?php esc_html_e( 'Account holder address (if different)', 'easyverein-membership-form' ); ?></label>
						<input type="text" id="evmf-account-holder-address" name="evmf[account_holder_address]" value="<?php echo esc_attr( $values['account_holder_address'] ); ?>" maxlength="255">
					</p>
				</div>
				<div class="evmf-row">
					<p class="evmf-col evmf-col--wide">
						<label for="evmf-iban"><?php esc_html_e( 'IBAN', 'easyverein-membership-form' ); ?><?php echo $evmf_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="evmf-iban" name="evmf[iban]" value="<?php echo esc_attr( trim( chunk_split( $values['iban'], 4, ' ' ) ) ); ?>" inputmode="text" autocomplete="off" spellcheck="false" maxlength="42" required<?php echo $evmf_aria( 'iban' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo $evmf_err( 'iban' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
					<p class="evmf-col">
						<label for="evmf-bic"><?php esc_html_e( 'BIC', 'easyverein-membership-form' ); ?><?php echo $sepa_require_bic ? $evmf_req : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="evmf-bic" name="evmf[bic]" value="<?php echo esc_attr( $values['bic'] ); ?>" autocomplete="off" spellcheck="false" maxlength="11"<?php echo $sepa_require_bic ? ' required' : ''; ?><?php echo $evmf_aria( 'bic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo $evmf_err( 'bic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				</div>
				<div class="evmf-consent">
					<label>
						<input type="checkbox" name="evmf[sepa_consent]" value="1" required <?php checked( $values['sepa_consent'] ); ?><?php echo $evmf_aria( 'sepa_consent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<span><?php echo wp_kses_post( nl2br( esc_html( $mandate_text ) ) ); ?></span>
					</label>
					<?php echo $evmf_err( 'sepa_consent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</fieldset>
		<?php endif; ?>

		<div class="evmf-consent">
			<label>
				<input type="checkbox" name="evmf[privacy_consent]" value="1" required <?php checked( $values['privacy_consent'] ); ?><?php echo $evmf_aria( 'privacy_consent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<span>
					<?php
					if ( $privacy_url ) {
						printf(
							/* translators: 1: opening link tag, 2: closing link tag, 3: organisation name */
							esc_html__( 'I have read and accept the %1$sprivacy policy%2$s and agree that %3$s processes my data to manage my membership. I confirm that the information provided is correct.', 'easyverein-membership-form' ),
							'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">',
							'</a>',
							esc_html( $org_name )
						);
					} else {
						/* translators: %s: organisation name */
						echo esc_html( sprintf( __( 'I agree that %s processes my data to manage my membership. I confirm that the information provided is correct.', 'easyverein-membership-form' ), $org_name ) );
					}
					?>
				</span>
			</label>
			<?php echo $evmf_err( 'privacy_consent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php if ( '' !== $recaptcha_key ) : ?>
			<div class="evmf-captcha">
				<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_key ); ?>"></div>
			</div>
		<?php endif; ?>

		<p class="evmf-submit">
			<button type="submit" class="evmf-button"><?php esc_html_e( 'Submit application', 'easyverein-membership-form' ); ?></button>
		</p>
		<p class="evmf-legend"><span class="evmf-required">*</span> <?php esc_html_e( 'Required field', 'easyverein-membership-form' ); ?></p>
	</form>
</div>
