<?php
/**
 * Plugin Name:       V-huset Medlemmer
 * Description:       Medlemskartotek for Værkstedshuset i Hannovergade: venteliste pr. værksted, betaling via Dinero og adgang via EasyAccess.
 * Version:           0.8.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Værkstedshuset i Hannovergade
 * License:           GPL-2.0-or-later
 * Text Domain:       vhus-medlemmer
 *
 * @package VHus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VHUS_VERSION', '0.8.0' );
define( 'VHUS_DB_VERSION', '7' );
define( 'VHUS_FILE', __FILE__ );
define( 'VHUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VHUS_URL', plugin_dir_url( __FILE__ ) );

require_once VHUS_DIR . 'includes/class-db.php';
require_once VHUS_DIR . 'includes/class-roles.php';
require_once VHUS_DIR . 'includes/class-settings.php';
require_once VHUS_DIR . 'includes/class-util.php';
require_once VHUS_DIR . 'includes/class-log.php';
require_once VHUS_DIR . 'includes/class-installer.php';
require_once VHUS_DIR . 'includes/class-members.php';
require_once VHUS_DIR . 'includes/class-workshops.php';
require_once VHUS_DIR . 'includes/class-xlsx-reader.php';
require_once VHUS_DIR . 'includes/class-importer.php';
require_once VHUS_DIR . 'includes/class-mailer.php';
require_once VHUS_DIR . 'includes/class-flow.php';
require_once VHUS_DIR . 'includes/class-signup.php';
require_once VHUS_DIR . 'includes/class-dinero-client.php';
require_once VHUS_DIR . 'includes/class-dinero-sync.php';
require_once VHUS_DIR . 'includes/class-xlsx-writer.php';
require_once VHUS_DIR . 'includes/class-easyaccess.php';
require_once VHUS_DIR . 'includes/class-gate.php';
require_once VHUS_DIR . 'includes/class-privacy.php';
require_once VHUS_DIR . 'includes/class-users.php';

register_activation_hook( __FILE__, array( 'VHus\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VHus\\Dinero_Sync', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'VHus\\Gate', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'VHus\\Installer', 'maybe_upgrade' ) );
add_action( 'init', array( 'VHus\\Signup', 'init' ) );
add_action( 'init', array( 'VHus\\Dinero_Sync', 'init' ) );
add_action( 'init', array( 'VHus\\Gate', 'init' ) );
add_action( 'init', array( 'VHus\\Privacy', 'init' ) );
add_action( 'init', array( 'VHus\\Users', 'init' ) );

if ( is_admin() ) {
	require_once VHUS_DIR . 'admin/class-admin.php';
	require_once VHUS_DIR . 'admin/class-members-table.php';
	require_once VHUS_DIR . 'admin/class-queues.php';
	require_once VHUS_DIR . 'admin/class-dinero-page.php';
	require_once VHUS_DIR . 'admin/class-gate-page.php';
	require_once VHUS_DIR . 'admin/class-tools-pages.php';
	require_once VHUS_DIR . 'admin/class-users-admin.php';
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Admin', 'init' ) );
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Queues', 'init' ) );
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Dinero_Page', 'init' ) );
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Gate_Page', 'init' ) );
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Tools_Pages', 'init' ) );
	add_action( 'plugins_loaded', array( 'VHus\\Admin\\Users_Admin', 'init' ) );
}
