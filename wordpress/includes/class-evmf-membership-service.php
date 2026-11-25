<?php
/**
 * Creates a membership application in easyVerein (API v3.0).
 *
 * Steps:
 * 1. POST contact-details                 (the address record)
 * 2. POST member                          (application, linked to the contact details)
 * 3. POST member-group-assignment         (membership type -> member group)
 * 4. POST member-custom-field-assignment  (partner / children, family memberships only)
 *
 * In v3.0 all field names are snake_case and the former sub-endpoints
 * member/{id}/groups and member/{id}/custom-fields are standalone endpoints that
 * require `user_object`.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the API calls for one application.
 */
class EVMF_Membership_Service {

	/** contact-details.method_of_payment: 1 = direct debit, 2 = bank transfer. */
	const PAYMENT_DIRECT_DEBIT = 1;

	/**
	 * API client.
	 *
	 * @var EVMF_Api_Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param EVMF_Api_Client|null $api Client (injectable for tests).
	 */
	public function __construct( $api = null ) {
		$this->api = $api ? $api : new EVMF_Api_Client();
	}

	/**
	 * Create the application.
	 *
	 * @param array $data Validated, sanitized form data (see EVMF_Form::collect()).
	 * @return array{ok:bool,member_id:int,contact_id:int,warnings:string[],error:string,error_code:string}
	 */
	public function create_application( array $data ) {
		$result = array(
			'ok'         => false,
			'member_id'  => 0,
			'contact_id' => 0,
			'warnings'   => array(),
			'error'      => '',
			'error_code' => '',
		);

		$types = EVMF_Plugin::membership_types();
		$type  = isset( $types[ $data['membership_type'] ] ) ? $types[ $data['membership_type'] ] : null;

		// 1. Contact details.
		$contact = $this->api->post( 'contact-details', $this->contact_payload( $data ) );
		if ( is_wp_error( $contact ) ) {
			$result['error']      = $contact->get_error_message();
			$result['error_code'] = $this->classify( $contact );
			return $result;
		}
		$contact_id = isset( $contact['id'] ) ? (int) $contact['id'] : 0;
		if ( ! $contact_id ) {
			EVMF_Logger::error( 'contact-details response did not contain an id.' );
			$result['error']      = __( 'Unexpected response from easyVerein (no contact ID).', 'easyverein-membership-form' );
			$result['error_code'] = 'api';
			return $result;
		}
		$result['contact_id'] = $contact_id;

		// 2. Member (application).
		$member = $this->api->post( 'member', $this->member_payload( $data, $contact_id ) );
		if ( is_wp_error( $member ) || empty( $member['id'] ) ) {
			$error = is_wp_error( $member ) ? $member : new WP_Error( 'evmf_api', __( 'Unexpected response from easyVerein (no member ID).', 'easyverein-membership-form' ) );
			$this->delete_orphan_contact( $contact_id );
			$result['error']      = $error->get_error_message();
			$result['error_code'] = $this->classify( $error );
			return $result;
		}
		$member_id           = (int) $member['id'];
		$result['member_id'] = $member_id;
		$result['ok']        = true;

		// 3. Member group for the selected membership type.
		$group_id = $type ? (int) $type['group_id'] : 0;
		if ( $group_id ) {
			$assignment = $this->api->post(
				'member-group-assignment',
				array(
					'member_group'   => $group_id,
					'user_object'    => $member_id,
					'payment_active' => (bool) EVMF_Plugin::get( 'group_payment_active', 1 ),
				)
			);
			if ( is_wp_error( $assignment ) ) {
				/* translators: 1: group ID, 2: error message */
				$result['warnings'][] = sprintf( __( 'Could not add the member to group %1$d: %2$s', 'easyverein-membership-form' ), $group_id, $assignment->get_error_message() );
			}
		} else {
			/* translators: %s: membership type key */
			$result['warnings'][] = sprintf( __( 'No easyVerein group ID is configured for membership type "%s".', 'easyverein-membership-form' ), $data['membership_type'] );
		}

		// 4. Family members as custom fields.
		if ( $type && ! empty( $type['family'] ) ) {
			$result['warnings'] = array_merge( $result['warnings'], $this->assign_family_fields( $member_id, $data ) );
		}

		foreach ( $result['warnings'] as $warning ) {
			EVMF_Logger::warning( "Member {$member_id}: {$warning}" );
		}

		/**
		 * Fires after an application was created in easyVerein.
		 *
		 * @param int   $member_id  easyVerein member ID.
		 * @param int   $contact_id easyVerein contact-details ID.
		 * @param array $data       Submitted form data.
		 * @param array $result     Result incl. warnings.
		 */
		do_action( 'evmf_application_created', $member_id, $contact_id, $data, $result );

		return $result;
	}

	/**
	 * Build the contact-details payload (v3.0 snake_case).
	 *
	 * @param array $data Form data.
	 * @return array
	 */
	public function contact_payload( array $data ) {
		$payload = array(
			'is_company'    => false,
			'salutation'    => $data['salutation'],
			'first_name'    => $data['first_name'],
			'family_name'   => $data['last_name'],
			'date_of_birth' => $data['dob'],
			'private_email' => $data['email'],
			'primary_email' => $data['email'],
			'private_phone' => $data['phone'],
			'street'        => $data['street'],
			'zip'           => $data['zip'],
			'city'          => $data['city'],
			'country'       => $data['country'],
		);

		if ( EVMF_Plugin::get( 'sepa_enabled' ) && ! empty( $data['iban'] ) ) {
			$payload['iban']               = $data['iban'];
			$payload['bic']                = $data['bic'];
			$payload['bank_account_owner'] = $data['account_holder'];
			$payload['method_of_payment']  = self::PAYMENT_DIRECT_DEBIT;
			$payload['sepa_date']          = current_time( 'Y-m-d' );
			if ( '' !== $data['account_holder_address'] ) {
				/* translators: %s: postal address of the bank account holder */
				$payload['internal_note'] = sprintf( __( 'SEPA account holder address: %s', 'easyverein-membership-form' ), $data['account_holder_address'] );
			}
		}

		// Drop empty optional values so easyVerein keeps its own defaults.
		$payload = array_filter(
			$payload,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		/**
		 * Filter the contact-details payload (v3.0, snake_case field names).
		 *
		 * @param array $payload Payload.
		 * @param array $data    Form data.
		 */
		return apply_filters( 'evmf_contact_details_payload', $payload, $data );
	}

	/**
	 * Build the member payload.
	 *
	 * @param array $data       Form data.
	 * @param int   $contact_id Contact details ID.
	 * @return array
	 */
	public function member_payload( array $data, $contact_id ) {
		$payload = array(
			'contact_details'    => (int) $contact_id,
			'email_or_user_name' => $data['email'],
			'is_application'     => (bool) EVMF_Plugin::get( 'create_as_application', 1 ),
		);

		/**
		 * Filter the member payload (v3.0, snake_case field names).
		 *
		 * @param array $payload    Payload.
		 * @param array $data       Form data.
		 * @param int   $contact_id Contact details ID.
		 */
		return apply_filters( 'evmf_member_payload', $payload, $data, $contact_id );
	}

	/**
	 * Store partner and children in the mapped custom fields.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $data      Form data.
	 * @return string[] Warnings.
	 */
	private function assign_family_fields( $member_id, array $data ) {
		$warnings = array();
		$mapping  = (array) EVMF_Plugin::get( 'custom_fields', array() );

		$values = array(
			'partner_name' => $data['partner_name'],
			'partner_dob'  => $data['partner_dob'],
		);
		foreach ( $data['children'] as $index => $child ) {
			$n                              = $index + 1;
			$values[ 'child_' . $n . '_name' ] = $child['name'];
			$values[ 'child_' . $n . '_dob' ]  = $child['dob'];
		}

		/**
		 * Filter custom field values before they are sent (key => value).
		 *
		 * @param array $values    Values keyed by family field key.
		 * @param array $data      Form data.
		 * @param int   $member_id Member ID.
		 */
		$values = apply_filters( 'evmf_family_custom_field_values', $values, $data, $member_id );

		foreach ( $values as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$field_id = isset( $mapping[ $key ] ) ? (int) $mapping[ $key ] : 0;
			if ( ! $field_id ) {
				/* translators: %s: field key such as child_1_name */
				$warnings[] = sprintf( __( 'No custom field ID configured for "%s"; value not stored.', 'easyverein-membership-form' ), $key );
				continue;
			}
			$response = $this->api->post(
				'member-custom-field-assignment',
				array(
					'custom_field' => $field_id,
					'user_object'  => (int) $member_id,
					'value'        => (string) $value,
				)
			);
			if ( is_wp_error( $response ) ) {
				/* translators: 1: field key, 2: error message */
				$warnings[] = sprintf( __( 'Could not store "%1$s": %2$s', 'easyverein-membership-form' ), $key, $response->get_error_message() );
			}
		}
		return $warnings;
	}

	/**
	 * Remove the contact record if the member could not be created.
	 *
	 * @param int $contact_id Contact ID.
	 */
	private function delete_orphan_contact( $contact_id ) {
		$deleted = $this->api->delete( 'contact-details/' . (int) $contact_id );
		if ( is_wp_error( $deleted ) ) {
			EVMF_Logger::error( "Could not delete orphaned contact-details/{$contact_id}: " . $deleted->get_error_message() );
		} else {
			EVMF_Logger::info( "Deleted orphaned contact-details/{$contact_id} after member creation failed." );
		}
	}

	/**
	 * Map an API error to a category the form can show a friendly message for.
	 *
	 * @param WP_Error $error Error.
	 * @return string duplicate|unauthorized|validation|unavailable|api
	 */
	private function classify( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$body   = is_array( $data ) && isset( $data['body'] ) ? $data['body'] : array();

		if ( 401 === $status || 'evmf_no_token' === $error->get_error_code() ) {
			return 'unauthorized';
		}
		if ( 400 === $status ) {
			$text = strtolower( (string) wp_json_encode( $body ) );
			if ( false !== strpos( $text, 'email' ) && ( false !== strpos( $text, 'exist' ) || false !== strpos( $text, 'vergeben' ) || false !== strpos( $text, 'bereits' ) || false !== strpos( $text, 'unique' ) ) ) {
				return 'duplicate';
			}
			if ( is_array( $body ) && ( isset( $body['email_or_user_name'] ) || isset( $body['emailOrUserName'] ) ) ) {
				return 'duplicate';
			}
			return 'validation';
		}
		if ( 0 === $status || $status >= 500 || 429 === $status ) {
			return 'unavailable';
		}
		return 'api';
	}
}
