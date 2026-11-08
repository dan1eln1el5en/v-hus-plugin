<?php
/**
 * Activation, schema upgrades and seed data.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, roles and the initial workshops.
 */
class Installer {

	/** Workshops from the Excel list. Code = the abbreviation used there. */
	const SEED_WORKSHOPS = array(
		array( 'AT', 'atelier', 'Atelier' ),
		array( 'FX', 'flex', 'Flex' ),
		array( 'GS', 'goldenspikes', 'GoldenSpikes' ),
		array( 'MC', 'mekanik', 'Mekanik' ),
		array( 'TX', 'textil', 'Textil' ),
		array( 'TM', 'trae-metal', 'Træ&Metal' ),
	);

	/** Activation hook. */
	public static function activate(): void {
		self::install();
	}

	/** Run the installer when the stored DB version is behind (covers updates without re-activation). */
	public static function maybe_upgrade(): void {
		if ( get_option( 'vhus_db_version' ) !== VHUS_DB_VERSION ) {
			self::install();
		}
	}

	/** Idempotent install. */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( DB::schema() as $sql ) {
			dbDelta( $sql );
		}
		Roles::install();
		self::seed_workshops();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		self::migrate( get_option( 'vhus_db_version' ) );
		Dinero_Sync::schedule();
		Gate::schedule();
		update_option( 'vhus_db_version', VHUS_DB_VERSION, false );
	}

	/**
	 * One-off data changes per schema version.
	 *
	 * @param mixed $from Stored DB version before this install.
	 */
	private static function migrate( $from ): void {
		global $wpdb;
		if ( $from && (int) $from < 4 ) {
			// v4: members imported from Excel are already in EasyAccess.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . DB::t( 'members' ) . ' SET easyaccess_linked_at = %s WHERE easyaccess_linked_at IS NULL AND id IN ( SELECT member_id FROM ' . DB::t( 'seats' ) . " WHERE note = 'Importeret fra Excel' )",
					Util::now()
				)
			);
		}
	}

	/** Insert seed workshops that don't exist yet (matched on slug). */
	private static function seed_workshops(): void {
		global $wpdb;
		$table = DB::t( 'workshops' );
		foreach ( self::SEED_WORKSHOPS as $i => $w ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s", $w[1] ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$table,
					array(
						'code'       => $w[0],
						'slug'       => $w[1],
						'name'       => $w[2],
						'sort_order' => $i,
					)
				);
			}
		}
	}
}
