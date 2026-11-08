<?php
/**
 * Audit log: who viewed or changed what, when.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only log in vhus_log.
 */
class Log {

	/**
	 * Write a log entry. Details must never include bank data.
	 *
	 * @param string $action      Short verb, e.g. member_update.
	 * @param string $object_type member|workshop|seat|import|settings.
	 * @param int    $object_id   Object id.
	 * @param array  $details     Extra context, stored as JSON.
	 */
	public static function add( string $action, string $object_type = '', int $object_id = 0, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert(
			DB::t( 'log' ),
			array(
				'user_id'     => get_current_user_id(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'details'     => $details ? wp_json_encode( $details ) : null,
				'created_at'  => Util::now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Recent entries for one object.
	 *
	 * @return object[]
	 */
	public static function for_object( string $object_type, int $object_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . DB::t( 'log' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d',
				$object_type,
				$object_id,
				$limit
			)
		);
	}
}
