<?php
/**
 * Reads the EasyAccess user export (XML, manual section 8.12) and matches it to members.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * The export's exact layout is not documented, so the reader is deliberately tolerant:
 * a "user" is any XML element whose direct children (or attributes) are simple values and
 * include something that looks like a phone number. Field names are recognised loosely.
 * Matching is on phone number, as the spec says.
 */
class EasyAccess {

	/** Lower-case tag/attribute name fragments => field. Checked in order. */
	const FIELD_HINTS = array(
		'phone' => array( 'telefon', 'phone', 'mobil', 'tlf' ),
		'group' => array( 'gruppe', 'group' ), // Before name: "Gruppenavn" contains "navn".
		'fob'   => array( 'brik', 'nøglekort', 'noeglekort', 'kort', 'card', 'tag', 'key' ),
		'name'  => array( 'fuldenavn', 'fulde_navn', 'fullname', 'navn', 'name' ),
		'pin'   => array( 'pin' ),
	);

	/**
	 * Parse an export into user records.
	 *
	 * @return array[] Each: name, id, phone (E.164), fob, raw (field => value).
	 * @throws \RuntimeException On unreadable XML.
	 */
	public static function parse( string $xml ): array {
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $doc ) {
			throw new \RuntimeException( 'Filen er ikke gyldig XML. Brug "Eksportér brugere" (XML) i EasyAccess.' );
		}
		$users = array();
		self::walk( $doc, $users );
		return $users;
	}

	/**
	 * Depth-first search for user-like elements.
	 *
	 * @param array[] $users Collected users.
	 */
	private static function walk( \SimpleXMLElement $el, array &$users ): void {
		$fields = array();
		$nested = false;
		foreach ( $el->attributes() as $k => $v ) {
			$fields[ (string) $k ] = trim( (string) $v );
		}
		foreach ( $el->children() as $child ) {
			// A child with its own children, or with several attributes (<User Name=".." Phone=".."/>), is a record.
			if ( $child->count() > 0 || count( $child->attributes() ) >= 2 ) {
				$nested = true;
				continue;
			}
			$fields[ $child->getName() ] = trim( (string) $child );
		}
		$user = self::to_user( $fields );
		if ( $user ) {
			$users[] = $user;
		}
		if ( $nested ) {
			foreach ( $el->children() as $child ) {
				if ( $child->count() > 0 || count( $child->attributes() ) >= 2 ) {
					self::walk( $child, $users );
				}
			}
		}
	}

	/**
	 * Recognise a user from flat fields, or null.
	 *
	 * @param array<string,string> $fields Name => value.
	 */
	public static function to_user( array $fields ): ?array {
		if ( count( $fields ) < 2 ) {
			return null;
		}
		$found = array();
		foreach ( $fields as $key => $value ) {
			$k = mb_strtolower( (string) $key );
			if ( 'id' === $k || 'userid' === $k || 'eksterntid' === $k || 'externalid' === $k ) {
				$found['id'] = $found['id'] ?? $value;
				continue;
			}
			foreach ( self::FIELD_HINTS as $field => $hints ) {
				if ( isset( $found[ $field ] ) ) {
					continue;
				}
				foreach ( $hints as $h ) {
					if ( str_contains( $k, $h ) ) {
						$found[ $field ] = $value;
						continue 3;
					}
				}
			}
		}
		// No phone-named field: take the first value that looks like a phone number.
		if ( empty( $found['phone'] ) ) {
			foreach ( $fields as $value ) {
				if ( preg_match( '/^\+?[\d\s]{8,15}$/', (string) $value ) ) {
					$found['phone'] = $value;
					break;
				}
			}
		}
		$phone = Util::phone_e164( $found['phone'] ?? '' );
		if ( '' === $phone || ! Util::is_plausible_phone( $phone ) ) {
			return null;
		}
		return array(
			'name'  => (string) ( $found['name'] ?? '' ),
			'id'    => (string) ( $found['id'] ?? '' ),
			'phone' => $phone,
			'fob'   => (string) ( $found['fob'] ?? '' ),
			'raw'   => $fields,
		);
	}

	/**
	 * Match export users to members on phone number.
	 *
	 * @param array[] $users From parse().
	 * @return array{matched: array[], unknown: array[], missing: object[]}
	 *   matched: user + member_id, member_name, id_ok (EasyAccess ID already equals member id);
	 *   unknown: users with no member; missing: members with an approved seat not in the export.
	 */
	public static function match( array $users ): array {
		global $wpdb;
		$members = $wpdb->get_results( 'SELECT id, name, phone_e164 FROM ' . DB::t( 'members' ) . " WHERE phone_e164 <> ''" );
		$by_phone = array();
		foreach ( $members as $m ) {
			$by_phone[ $m->phone_e164 ] = $m;
		}
		$out  = array(
			'matched' => array(),
			'unknown' => array(),
			'missing' => array(),
		);
		$seen = array();
		foreach ( $users as $u ) {
			$m = $by_phone[ $u['phone'] ] ?? null;
			if ( ! $m ) {
				$out['unknown'][] = $u;
				continue;
			}
			$seen[ (int) $m->id ] = true;
			$out['matched'][]     = $u + array(
				'member_id'   => (int) $m->id,
				'member_name' => $m->name,
				'id_ok'       => (string) $m->id === trim( $u['id'] ),
			);
		}
		$active = $wpdb->get_results(
			'SELECT DISTINCT m.id, m.name, m.phone_e164 FROM ' . DB::t( 'members' ) . ' m JOIN ' . DB::t( 'seats' ) . " s ON s.member_id = m.id AND s.status = 'approved' ORDER BY m.name"
		);
		foreach ( $active as $m ) {
			if ( ! isset( $seen[ (int) $m->id ] ) ) {
				$out['missing'][] = $m;
			}
		}
		return $out;
	}

	/**
	 * Mark matched members as present in EasyAccess.
	 *
	 * @param array[] $matched From match().
	 */
	public static function link( array $matched ): int {
		$n = 0;
		foreach ( $matched as $u ) {
			Gate::set_linked( (int) $u['member_id'], true );
			++$n;
		}
		Log::add( 'easyaccess_match', 'gate', 0, array( 'linked' => $n ) );
		return $n;
	}
}
