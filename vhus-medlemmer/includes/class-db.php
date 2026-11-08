<?php
/**
 * Table names and schema.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for table names so no SQL hard-codes a prefix.
 */
class DB {

	/**
	 * Logical name => table suffix.
	 */
	const TABLES = array(
		'members'      => 'vhus_members',
		'workshops'    => 'vhus_workshops',
		'seats'        => 'vhus_workshop_seats',
		'periods'      => 'vhus_periods',
		'keys'         => 'vhus_keys',
		'gate_actions' => 'vhus_gate_actions',
		'log'          => 'vhus_log',
	);

	/**
	 * Full table name for a logical name, e.g. DB::t( 'members' ).
	 *
	 * @param string $name Logical table name.
	 */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $name ];
	}

	/**
	 * CREATE TABLE statements in dbDelta format.
	 *
	 * @return string[]
	 */
	public static function schema(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		return array(
			'CREATE TABLE ' . self::t( 'members' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone_e164 varchar(20) NOT NULL DEFAULT '',
  address varchar(190) NOT NULL DEFAULT '',
  postcode varchar(10) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  dinero_contact_guid varchar(36) DEFAULT NULL,
  consent_at datetime DEFAULT NULL,
  notes text,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY phone_e164 (phone_e164)
) $c;",
			'CREATE TABLE ' . self::t( 'workshops' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(10) NOT NULL DEFAULT '',
  slug varchar(64) NOT NULL,
  name varchar(100) NOT NULL,
  contact_user_id bigint(20) unsigned DEFAULT NULL,
  capacity smallint(5) unsigned DEFAULT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY contact_user_id (contact_user_id)
) $c;",
			'CREATE TABLE ' . self::t( 'seats' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  workshop_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'waiting',
  requested_at datetime NOT NULL,
  decided_at datetime DEFAULT NULL,
  decided_by bigint(20) unsigned DEFAULT NULL,
  note text,
  PRIMARY KEY  (id),
  KEY member_id (member_id),
  KEY workshop_queue (workshop_id,status,requested_at)
) $c;",
			'CREATE TABLE ' . self::t( 'periods' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  year_label varchar(9) NOT NULL,
  fee_type varchar(10) NOT NULL DEFAULT 'full',
  amount_fee int(10) unsigned NOT NULL DEFAULT 0,
  amount_key int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  dinero_invoice_guid varchar(36) DEFAULT NULL,
  invoiced_at datetime DEFAULT NULL,
  paid_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY member_year (member_id,year_label),
  KEY status (status)
) $c;",
			'CREATE TABLE ' . self::t( 'keys' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  fob_number varchar(50) NOT NULL DEFAULT '',
  issued_at datetime DEFAULT NULL,
  returned_at datetime DEFAULT NULL,
  fee_paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id)
) $c;",
			'CREATE TABLE ' . self::t( 'gate_actions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  action varchar(30) NOT NULL,
  payload longtext,
  created_at datetime NOT NULL,
  done_at datetime DEFAULT NULL,
  done_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id),
  KEY open_actions (done_at,created_at)
) $c;",
			'CREATE TABLE ' . self::t( 'log' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(50) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY created_at (created_at)
) $c;",
		);
	}
}
