<?php
/**
 * Small pure helpers (no database access), easy to unit test.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting and membership-year arithmetic.
 */
class Util {

	/**
	 * Normalise a Danish/international phone number to E.164.
	 * Handles spreadsheet floats such as "4.550276165E9" and "4550276165.0".
	 *
	 * @param mixed $raw Raw value.
	 * @return string E.164 number, or '' if nothing usable.
	 */
	public static function phone_e164( $raw ): string {
		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return '';
		}
		if ( preg_match( '/^\d+(\.\d+)?E\+?\d+$/i', $s ) || preg_match( '/^\d+\.0+$/', $s ) ) {
			$s = sprintf( '%.0f', (float) $s );
		}
		$plus   = str_starts_with( $s, '+' );
		$digits = preg_replace( '/\D+/', '', $s );
		if ( '' === $digits ) {
			return '';
		}
		if ( $plus ) {
			return '+' . $digits;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( 8 === strlen( $digits ) ) {
			return '+45' . $digits;
		}
		if ( 10 === strlen( $digits ) && str_starts_with( $digits, '45' ) ) {
			return '+' . $digits;
		}
		return '+' . $digits;
	}

	/** True if a phone number looks like a valid Danish mobile/landline. */
	public static function is_plausible_phone( string $e164 ): bool {
		if ( str_starts_with( $e164, '+45' ) ) {
			return 11 === strlen( $e164 );
		}
		return (bool) preg_match( '/^\+\d{8,15}$/', $e164 );
	}

	/**
	 * Clean a postcode that a spreadsheet stored as a number ("2300.0").
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function postcode( $raw ): string {
		$s = trim( (string) $raw );
		if ( preg_match( '/^(\d+)\.0+$/', $s, $m ) ) {
			$s = $m[1];
		}
		return substr( $s, 0, 10 );
	}

	/** MM-DD validation. */
	public static function is_month_day( string $v ): bool {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $v, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[1], (int) $m[2], 2024 );
	}

	/**
	 * First calendar year of the membership year containing $date.
	 * With year_start 05-01: 2026-11-08 => 2026, 2027-03-01 => 2026, 2027-05-01 => 2027.
	 *
	 * @param \DateTimeInterface|null $date Date (site timezone), default today.
	 */
	public static function membership_start_year( ?\DateTimeInterface $date = null, ?string $year_start = null ): int {
		$date       = $date ?? current_datetime();
		$year_start = $year_start ?? (string) Settings::get( 'year_start' );
		$y          = (int) $date->format( 'Y' );
		return $date->format( 'm-d' ) >= $year_start ? $y : $y - 1;
	}

	/**
	 * Label like "2026/27" for the membership year containing $date.
	 *
	 * @param \DateTimeInterface|null $date Date, default today.
	 */
	public static function year_label( ?\DateTimeInterface $date = null, ?string $year_start = null ): string {
		$y = self::membership_start_year( $date, $year_start );
		return sprintf( '%d/%02d', $y, ( $y + 1 ) % 100 );
	}

	/**
	 * Fee type for someone joining on $date: 'half' from half_fee_from until the next year start.
	 *
	 * @param \DateTimeInterface|null $date Join date.
	 */
	public static function fee_type_for_join( ?\DateTimeInterface $date = null, ?string $year_start = null, ?string $half_from = null ): string {
		$date       = $date ?? current_datetime();
		$year_start = $year_start ?? (string) Settings::get( 'year_start' );
		$half_from  = $half_from ?? (string) Settings::get( 'half_fee_from' );
		$md         = $date->format( 'm-d' );
		// Membership year wraps the calendar year (e.g. May–April): half fee applies
		// from half_from to Dec 31 and from Jan 1 until year_start.
		if ( $half_from >= $year_start ) {
			return ( $md >= $half_from || $md < $year_start ) ? 'half' : 'full';
		}
		return ( $md >= $half_from && $md < $year_start ) ? 'half' : 'full';
	}

	/** EasyAccess group name for a year label. */
	public static function group_name( string $year_label ): string {
		return str_replace( '{year}', $year_label, (string) Settings::get( 'group_pattern' ) );
	}

	/** Current time in MySQL format, site timezone. */
	public static function now(): string {
		return current_time( 'mysql' );
	}
}
