<?php
/**
 * Import of the existing member list (Medlemmer.xlsx) with a dry run.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Two steps: plan() reads the file and returns what would happen; commit() writes it.
 * Bank columns (Reg, Konto) are dropped while reading and never reach the plan.
 */
class Importer {

	/** Header text (lower case) => field. Unknown headers are ignored. */
	const HEADER_MAP = array(
		'kontaktnavn' => 'name',
		'navn'        => 'name',
		'fulde navn'  => 'name',
		'værksted'    => 'workshop',
		'vaerksted'   => 'workshop',
		'adresse'     => 'address',
		'postnummer'  => 'postcode',
		'postnr'      => 'postcode',
		'postnr.'     => 'postcode',
		'by'          => 'city',
		'telefon'     => 'phone',
		'mobil'       => 'phone',
		'tlf'         => 'phone',
		'tlf.'        => 'phone',
		'e-mail'      => 'email',
		'email'       => 'email',
		'mail'        => 'email',
	);

	/**
	 * Build an import plan from rows.
	 *
	 * @param array<int,array<int,string>> $rows Rows from Xlsx_Reader.
	 * @return array{items: array[], errors: string[], summary: array<string,int>}
	 */
	public static function plan( array $rows ): array {
		$header_row = null;
		$cols       = array();
		foreach ( $rows as $n => $row ) {
			$found = array();
			foreach ( $row as $i => $cell ) {
				$key = mb_strtolower( trim( $cell ) );
				if ( isset( self::HEADER_MAP[ $key ] ) && ! isset( $found[ self::HEADER_MAP[ $key ] ] ) ) {
					$found[ self::HEADER_MAP[ $key ] ] = $i;
				}
			}
			if ( isset( $found['name'] ) && count( $found ) >= 2 ) {
				$header_row = $n;
				$cols       = $found;
				break;
			}
		}
		if ( null === $header_row ) {
			return array(
				'items'   => array(),
				'errors'  => array( 'Fandt ingen overskriftsrække med "Kontaktnavn" eller "Navn".' ),
				'summary' => array(),
			);
		}

		$workshops = Workshops::lookup_map();
		$names     = array();
		foreach ( Workshops::all() as $w ) {
			$names[ (int) $w->id ] = $w->name;
		}

		$items    = array();
		$section  = '';
		$seen     = array(); // email/phone => item index, to merge repeats within the file.
		$errors   = array();

		foreach ( $rows as $n => $row ) {
			if ( $n <= $header_row ) {
				continue;
			}
			$get = fn( string $f ) => isset( $cols[ $f ] ) ? trim( (string) ( $row[ $cols[ $f ] ] ?? '' ) ) : '';

			$filled = array_filter( $row, fn( $v ) => '' !== trim( (string) $v ) );
			if ( ! $filled ) {
				continue;
			}
			// A row with only one cell that names a workshop is a section header (e.g. "TM").
			if ( 1 === count( $filled ) && '' === $get( 'name' ) ) {
				$only = mb_strtolower( trim( (string) reset( $filled ) ) );
				if ( isset( $workshops[ $only ] ) ) {
					$section = $only;
				}
				continue;
			}

			$name = $get( 'name' );
			if ( '' === $name ) {
				continue; // Leftover cells without a person.
			}

			$ws_raw = mb_strtolower( $get( 'workshop' ) );
			$ws_raw = '' !== $ws_raw ? $ws_raw : $section;
			$ws_id  = $workshops[ $ws_raw ] ?? 0;

			$email = strtolower( sanitize_email( $get( 'email' ) ) );
			$phone = Util::phone_e164( $get( 'phone' ) );

			$item = array(
				'row'           => $n,
				'name'          => sanitize_text_field( $name ),
				'email'         => $email,
				'phone_e164'    => $phone,
				'address'       => sanitize_text_field( $get( 'address' ) ),
				'postcode'      => Util::postcode( $get( 'postcode' ) ),
				'city'          => sanitize_text_field( $get( 'city' ) ),
				'workshop_ids'  => $ws_id ? array( $ws_id ) : array(),
				'workshops'     => $ws_id ? array( $names[ $ws_id ] ) : array(),
				'action'        => 'create',
				'existing_id'   => 0,
				'warnings'      => array(),
			);

			if ( ! $ws_id ) {
				$item['action']     = 'error';
				$item['warnings'][] = '' === $ws_raw ? 'Intet værksted angivet' : sprintf( 'Ukendt værksted "%s"', $ws_raw );
			}
			if ( '' === $email ) {
				$item['warnings'][] = '' === $get( 'email' ) ? 'Mangler e-mail' : 'Ugyldig e-mail';
			}
			if ( '' === $phone ) {
				$item['warnings'][] = 'Mangler telefon';
			} elseif ( ! Util::is_plausible_phone( $phone ) ) {
				$item['warnings'][] = 'Usædvanligt telefonnummer';
			}

			// Same person twice in the file: add the workshop to the first row instead.
			$dup_key = $email ? 'e:' . $email : ( $phone ? 'p:' . $phone : '' );
			if ( $dup_key && isset( $seen[ $dup_key ] ) && 'error' !== $item['action'] ) {
				$first = &$items[ $seen[ $dup_key ] ];
				if ( ! in_array( $ws_id, $first['workshop_ids'], true ) ) {
					$first['workshop_ids'][] = $ws_id;
					$first['workshops'][]    = $names[ $ws_id ];
				}
				$first['warnings'][] = sprintf( 'Står også i række %d', $n );
				unset( $first );
				continue;
			}

			// Already in the database (re-import): add missing seats only.
			$existing = ( $email ? Members::find_by_email( $email ) : null ) ?? ( $phone ? Members::find_by_phone( $phone ) : null );
			if ( $existing && 'error' !== $item['action'] ) {
				$item['existing_id'] = (int) $existing->id;
				$missing             = array_diff( $item['workshop_ids'], Members::workshop_ids( (int) $existing->id ) );
				$item['action']      = $missing ? 'add_seat' : 'skip';
			}

			$items[] = $item;
			if ( $dup_key ) {
				$seen[ $dup_key ] = array_key_last( $items );
			}
		}

		$summary = array_count_values( array_column( $items, 'action' ) ) + array(
			'create'   => 0,
			'add_seat' => 0,
			'skip'     => 0,
			'error'    => 0,
		);
		$summary['warnings'] = count( array_filter( $items, fn( $i ) => $i['warnings'] ) );

		return array(
			'items'   => $items,
			'errors'  => $errors,
			'summary' => $summary,
		);
	}

	/**
	 * Write a plan. Imported members get an approved seat and a current-year period with status "imported".
	 *
	 * @param array $plan Output of plan().
	 * @return array<string,int> Counts of created members and seats.
	 */
	public static function commit( array $plan ): array {
		global $wpdb;
		$year   = Util::year_label();
		$counts = array(
			'members' => 0,
			'seats'   => 0,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $plan['items'] as $item ) {
				if ( ! in_array( $item['action'], array( 'create', 'add_seat' ), true ) ) {
					continue;
				}
				$member_id = (int) $item['existing_id'];
				if ( 'create' === $item['action'] ) {
					$member_id = Members::create( $item, false );
					++$counts['members'];
				}
				foreach ( $item['workshop_ids'] as $ws_id ) {
					Members::add_seat( $member_id, (int) $ws_id, 'approved', null, 'Importeret fra Excel', false );
					++$counts['seats'];
				}
				Members::ensure_period( $member_id, $year, 'full', 'imported' );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		Log::add( 'import', 'import', 0, $counts + array( 'year' => $year ) );
		return $counts;
	}
}
