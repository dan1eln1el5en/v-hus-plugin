<?php
/**
 * Plain-text e-mails from editable templates.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Fills a template from Settings and sends it with wp_mail().
 */
class Mailer {

	/** Template key => [ label, placeholders available ]. Shown on the settings page. */
	const TEMPLATES = array(
		'receipt'  => array( 'Kvittering til ansøger', '{navn} {vaerksteder}' ),
		'contact'  => array( 'Besked til værkstedskontakt om ny på ventelisten', '{navn} {vaerksted} {placering} {link}' ),
		'approved' => array( 'Til ansøger ved godkendelse', '{navn} {vaerksted} {faktura} {beloeb} {specifikation} {aar}' ),
		'declined' => array( 'Til ansøger ved afvisning', '{navn} {vaerksted} {besked}' ),
		'cashier'  => array( 'Til kasserer: klar til fakturering', '{navn} {vaerksted} {beloeb} {specifikation} {aar} {link}' ),
	);

	/**
	 * Send one templated e-mail.
	 *
	 * @param string          $template Key in TEMPLATES.
	 * @param string|string[] $to       Recipient(s).
	 * @param array           $vars     Placeholder values without braces.
	 * @param int             $member_id For the log.
	 * @return bool True if sent (false if disabled, no recipient or wp_mail failed).
	 */
	public static function send( string $template, $to, array $vars, int $member_id = 0 ): bool {
		$to      = array_values( array_filter( array_map( 'sanitize_email', (array) $to ) ) );
		$subject = trim( (string) Settings::get( "mail_{$template}_subject" ) );
		$body    = (string) Settings::get( "mail_{$template}_body" );
		if ( ! $to || '' === $subject ) {
			return false;
		}
		$ok = wp_mail( $to, self::fill( $subject, $vars ), self::fill( $body, $vars ) );
		Log::add( $ok ? 'email_sent' : 'email_failed', 'member', $member_id, array( 'template' => $template ) );
		return $ok;
	}

	/**
	 * Replace {placeholders}. Unknown placeholders are left as they are.
	 *
	 * @param array $vars Values.
	 */
	public static function fill( string $text, array $vars ): string {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( $text, $map );
	}

	/**
	 * Addresses of everyone with the cashier role; falls back to the notify address.
	 *
	 * @return string[]
	 */
	public static function cashier_emails(): array {
		$emails = get_users(
			array(
				'role__in' => array( 'vhus_cashier' ),
				'fields'   => 'user_email',
			)
		);
		return $emails ? $emails : array( self::admin_email() );
	}

	/** Address for administrator notices. */
	public static function admin_email(): string {
		$e = (string) Settings::get( 'notify_email' );
		return '' !== $e ? $e : (string) get_option( 'admin_email' );
	}

	/** Danish number format for kroner. */
	public static function kr( int $amount ): string {
		return number_format( $amount, 0, ',', '.' );
	}
}
