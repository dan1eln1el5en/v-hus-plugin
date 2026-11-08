<?php
/**
 * Workshop data access.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Read and update rows in vhus_workshops.
 */
class Workshops {

	/**
	 * All workshops in display order.
	 *
	 * @return object[]
	 */
	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . DB::t( 'workshops' ) . ' ORDER BY sort_order, name' );
	}

	/** One workshop. */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'workshops' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Map of workshop lookups by code, slug and lower-case name, for imports.
	 *
	 * @return array<string,int>
	 */
	public static function lookup_map(): array {
		$map = array();
		foreach ( self::all() as $w ) {
			foreach ( array( $w->code, $w->slug, $w->name ) as $k ) {
				if ( '' !== (string) $k ) {
					$map[ mb_strtolower( trim( $k ) ) ] = (int) $w->id;
				}
			}
		}
		return $map;
	}

	/**
	 * Workshop ids a user is contact for.
	 *
	 * @return int[]
	 */
	public static function ids_for_contact( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . DB::t( 'workshops' ) . ' WHERE contact_user_id = %d', $user_id ) )
		);
	}

	/**
	 * Update editable fields.
	 *
	 * @param array $data name, contact_user_id, capacity.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$row = array(
			'name'            => sanitize_text_field( $data['name'] ?? '' ),
			'contact_user_id' => ! empty( $data['contact_user_id'] ) ? absint( $data['contact_user_id'] ) : null,
			'capacity'        => ( isset( $data['capacity'] ) && '' !== $data['capacity'] ) ? absint( $data['capacity'] ) : null,
		);
		if ( '' === $row['name'] ) {
			unset( $row['name'] );
		}
		$wpdb->update( DB::t( 'workshops' ), $row, array( 'id' => $id ) );
		Log::add( 'workshop_update', 'workshop', $id, $row );
	}

	/**
	 * Seat counts per workshop and status.
	 *
	 * @return array<int,array<string,int>> workshop_id => status => count
	 */
	public static function seat_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT workshop_id, status, COUNT(*) AS n FROM ' . DB::t( 'seats' ) . ' GROUP BY workshop_id, status' );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->workshop_id ][ $r->status ] = (int) $r->n;
		}
		return $out;
	}
}
