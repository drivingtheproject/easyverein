<?php
/**
 * Input validation helpers (IBAN, BIC, dates, age).
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless validators.
 */
final class EVMF_Validator {

	/**
	 * Remove spaces and upper-case an IBAN or BIC.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_bank_code( $value ) {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
	}

	/**
	 * Validate an IBAN using the ISO 13616 mod-97 checksum (works for every country).
	 *
	 * @param string $iban IBAN (spaces allowed).
	 * @return bool
	 */
	public static function is_valid_iban( $iban ) {
		$iban = self::normalize_bank_code( $iban );
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}
		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric    = '';
		$length     = strlen( $rearranged );
		for ( $i = 0; $i < $length; $i++ ) {
			$char     = $rearranged[ $i ];
			$numeric .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}
		// Piecewise mod 97 to avoid big integer arithmetic.
		$remainder = 0;
		$len       = strlen( $numeric );
		for ( $i = 0; $i < $len; $i += 7 ) {
			$remainder = (int) ( $remainder . substr( $numeric, $i, 7 ) ) % 97;
		}
		return 1 === $remainder;
	}

	/**
	 * Validate the format of a BIC / SWIFT code (8 or 11 characters).
	 *
	 * @param string $bic BIC.
	 * @return bool
	 */
	public static function is_valid_bic( $bic ) {
		return (bool) preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', self::normalize_bank_code( $bic ) );
	}

	/**
	 * Parse a Y-m-d date from an <input type="date">.
	 *
	 * @param string $value Value.
	 * @return DateTimeImmutable|null
	 */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return $date;
	}

	/**
	 * Age in full years on today's date (site time zone).
	 *
	 * @param DateTimeImmutable $dob Date of birth.
	 * @return int
	 */
	public static function age( DateTimeImmutable $dob ) {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		return (int) $dob->diff( $today )->y * ( $dob <= $today ? 1 : -1 );
	}

	/**
	 * Is the date in the past (or today) and not absurdly old?
	 *
	 * @param DateTimeImmutable $date Date.
	 * @return bool
	 */
	public static function is_plausible_birth_date( DateTimeImmutable $date ) {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		return $date <= $today && (int) $date->format( 'Y' ) >= 1900;
	}
}
