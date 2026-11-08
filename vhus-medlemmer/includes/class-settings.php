<?php
/**
 * Plugin settings: all amounts and dates live here, never in code.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper around the single `vhus_settings` option.
 */
class Settings {

	const OPTION = 'vhus_settings';

	/** Defaults from the spec. */
	public static function defaults(): array {
		return array(
			'fee_full'             => 1000,
			'fee_half'             => 500,
			'fee_key'              => 200,
			'half_fee_from'        => '09-01', // MM-DD: joining on/after this date pays fee_half.
			'year_start'           => '05-01', // MM-DD: first day of the membership year.
			'grace_until'          => '06-30', // MM-DD in the following year: last gate day for an unpaid renewal.
			'group_pattern'        => 'Medlem {year}', // EasyAccess group name; {year} = e.g. 2026/27.
			'retention_declined'   => 6,  // Months before declined applicants are deleted.
			'retention_former'     => 12, // Months before former members are deleted.
			'notify_email'         => '',  // Administrator e-mail for sync failures etc. Empty = admin_email.
			'privacy_url'          => '',  // Privacy notice linked from the sign-up form. Empty = WordPress privacy page.
			'renewal_from'         => '03-01', // MM-DD: invoices dated from here until year_start count for the NEXT membership year.
			'dinero_products_full' => array(), // Dinero ProductGuids for the full fee. Empty = match on amount.
			'dinero_products_half' => array(),
			'dinero_products_key'  => array(),
		) + self::default_templates();
	}

	/**
	 * E-mail templates: mail_<key>_subject / mail_<key>_body. An empty subject turns that e-mail off.
	 * Placeholders are listed in Mailer::PLACEHOLDERS.
	 */
	public static function default_templates(): array {
		$sign = "\n\nVenlig hilsen\nVærkstedshuset i Hannovergade";
		return array(
			'mail_receipt_subject' => 'Tak for din tilmelding til Værkstedshuset',
			'mail_receipt_body'    => "Hej {navn}\n\nTak for din tilmelding til Værkstedshuset i Hannovergade. Du står nu på ventelisten til: {vaerksteder}.\n\nVærkstedskontakten kontakter dig, når der er en ledig plads. Du skal ikke gøre mere nu." . $sign,
			'mail_contact_subject' => 'Ny på ventelisten til {vaerksted}',
			'mail_contact_body'    => "Hej\n\n{navn} har skrevet sig op til {vaerksted} og er nr. {placering} på ventelisten.\n\nSe ventelisten: {link}",
			'mail_approved_subject' => 'Du har fået plads i {vaerksted}',
			'mail_approved_body'   => "Hej {navn}\n\nDu har fået plads i {vaerksted} i Værkstedshuset.\n\n{faktura}" . $sign,
			'mail_declined_subject' => 'Din tilmelding til {vaerksted}',
			'mail_declined_body'   => "Hej {navn}\n\nVi kan desværre ikke tilbyde dig en plads i {vaerksted}.{besked}" . $sign,
			'mail_cashier_subject' => 'Klar til fakturering: {navn}',
			'mail_cashier_body'    => "{navn} er godkendt i {vaerksted} og skal faktureres {beloeb} kr ({specifikation}) for medlemsåret {aar}.\n\nSe listen: {link}",
		);
	}

	/** All settings merged over defaults. */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Validate submitted settings.
	 *
	 * @param mixed $input Raw form input.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::all();

		foreach ( array( 'fee_full', 'fee_half', 'fee_key', 'retention_declined', 'retention_former' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = max( 0, absint( $input[ $k ] ) );
			}
		}
		foreach ( array( 'dinero_products_full', 'dinero_products_half', 'dinero_products_key' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = array_values( array_filter( array_map( 'strtolower', (array) $input[ $k ] ), fn( $g ) => (bool) preg_match( '/^[0-9a-f-]{36}$/', (string) $g ) ) );
			}
		}
		foreach ( array( 'half_fee_from', 'year_start', 'grace_until', 'renewal_from' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$v = trim( (string) $input[ $k ] );
				if ( Util::is_month_day( $v ) ) {
					$out[ $k ] = $v;
				} else {
					add_settings_error( self::OPTION, $k, sprintf( 'Ugyldig dato for %s – brug formatet MM-DD.', $k ) );
				}
			}
		}
		if ( isset( $input['group_pattern'] ) ) {
			$v = sanitize_text_field( $input['group_pattern'] );
			$out['group_pattern'] = str_contains( $v, '{year}' ) ? $v : $out['group_pattern'];
		}
		if ( isset( $input['notify_email'] ) ) {
			$out['notify_email'] = sanitize_email( $input['notify_email'] );
		}
		if ( isset( $input['privacy_url'] ) ) {
			$out['privacy_url'] = esc_url_raw( trim( (string) $input['privacy_url'] ) );
		}
		foreach ( array_keys( self::default_templates() ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = str_ends_with( $k, '_subject' ) ? sanitize_text_field( $input[ $k ] ) : sanitize_textarea_field( $input[ $k ] );
			}
		}
		return $out;
	}
}
