<?php
/**
 * Roles and capabilities.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities are what every screen and action checks; roles are just bundles of them.
 * A user can hold several roles (e.g. cashier + gate admin) and gets the union.
 */
class Roles {

	/** See members in the workshops you are contact for. */
	const CAP_OWN_WORKSHOP = 'vhus_manage_own_workshop';
	/** See every member (read only). */
	const CAP_VIEW_ALL = 'vhus_view_all_members';
	/** Create, edit and end members in any workshop. */
	const CAP_MANAGE_ALL = 'vhus_manage_all_members';
	/** Ready-to-invoice list and Dinero sync. */
	const CAP_INVOICE = 'vhus_invoice';
	/** Gate-actions queue and EasyAccess files. */
	const CAP_GATE = 'vhus_gate';
	/** Settings, workshops, import. */
	const CAP_ADMIN = 'vhus_admin';

	/**
	 * Role slug => [ label, caps ].
	 */
	public static function definitions(): array {
		return array(
			'vhus_workshop_contact' => array(
				'label' => 'Værkstedskontakt',
				'caps'  => array( self::CAP_OWN_WORKSHOP ),
			),
			'vhus_cashier'          => array(
				'label' => 'Kasserer',
				'caps'  => array( self::CAP_VIEW_ALL, self::CAP_INVOICE ),
			),
			'vhus_gate_admin'       => array(
				'label' => 'Portadministrator',
				'caps'  => array( self::CAP_VIEW_ALL, self::CAP_GATE ),
			),
		);
	}

	/** Every plugin capability; administrators get all of them. */
	public static function all_caps(): array {
		return array(
			self::CAP_OWN_WORKSHOP,
			self::CAP_VIEW_ALL,
			self::CAP_MANAGE_ALL,
			self::CAP_INVOICE,
			self::CAP_GATE,
			self::CAP_ADMIN,
		);
	}

	/** Create or refresh roles. Safe to run repeatedly. */
	public static function install(): void {
		foreach ( self::definitions() as $slug => $def ) {
			remove_role( $slug );
			$caps = array( 'read' => true );
			foreach ( $def['caps'] as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( $slug, $def['label'], $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/** Remove roles and admin caps (uninstall only). */
	public static function uninstall(): void {
		foreach ( array_keys( self::definitions() ) as $slug ) {
			remove_role( $slug );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/** Can the current user open the plugin at all? */
	public static function can_access(): bool {
		return current_user_can( self::CAP_OWN_WORKSHOP ) || current_user_can( self::CAP_VIEW_ALL ) || current_user_can( self::CAP_MANAGE_ALL );
	}

	/** True if the user sees every workshop rather than only their own. */
	public static function sees_all( int $user_id = 0 ): bool {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::CAP_VIEW_ALL ) || user_can( $user_id, self::CAP_MANAGE_ALL );
	}

	/**
	 * Workshop ids the user may see. Null means all.
	 *
	 * @return int[]|null
	 */
	public static function visible_workshop_ids( int $user_id = 0 ): ?array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( self::sees_all( $user_id ) ) {
			return null;
		}
		if ( ! user_can( $user_id, self::CAP_OWN_WORKSHOP ) ) {
			return array();
		}
		return Workshops::ids_for_contact( $user_id );
	}

	/** May the current user see this member? */
	public static function can_view_member( int $member_id ): bool {
		$ids = self::visible_workshop_ids();
		if ( null === $ids ) {
			return true;
		}
		return (bool) array_intersect( $ids, Members::workshop_ids( $member_id ) );
	}

	/** May the current user approve, decline or add seats in this workshop? */
	public static function can_manage_workshop( int $workshop_id ): bool {
		if ( current_user_can( self::CAP_MANAGE_ALL ) ) {
			return true;
		}
		return current_user_can( self::CAP_OWN_WORKSHOP ) && in_array( $workshop_id, Workshops::ids_for_contact( get_current_user_id() ), true );
	}

	/** May the current user edit this member's details and seats? */
	public static function can_edit_member( int $member_id ): bool {
		if ( current_user_can( self::CAP_MANAGE_ALL ) ) {
			return true;
		}
		if ( ! current_user_can( self::CAP_OWN_WORKSHOP ) ) {
			return false;
		}
		return (bool) array_intersect( Workshops::ids_for_contact( get_current_user_id() ), Members::workshop_ids( $member_id ) );
	}
}
