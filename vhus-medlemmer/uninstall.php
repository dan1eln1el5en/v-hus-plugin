<?php
/**
 * Uninstall: removes roles only. Member data is kept unless VHUS_DELETE_DATA_ON_UNINSTALL is true,
 * so deleting the plugin by mistake never deletes the member list.
 *
 * @package VHus
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-roles.php';

VHus\Roles::uninstall();

if ( defined( 'VHUS_DELETE_DATA_ON_UNINSTALL' ) && VHUS_DELETE_DATA_ON_UNINSTALL ) {
	global $wpdb;
	foreach ( array_keys( VHus\DB::TABLES ) as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . VHus\DB::t( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	delete_option( 'vhus_settings' );
	delete_option( 'vhus_db_version' );
}
