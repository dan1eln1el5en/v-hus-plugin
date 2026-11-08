<?php
/**
 * Link between members and WordPress users (board members, contacts, cashier, gate admin).
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * A member can be linked to one WordPress user and vice versa. Links are made automatically
 * when the e-mail addresses match, or by hand. Removing either side only removes the link:
 * deleting a member never deletes a WordPress user, and the other way round.
 */
class Users {

	/** Plugin roles that can be given from the member page, in display order. */
	const ROLES = array( 'vhus_workshop_contact', 'vhus_cashier', 'vhus_gate_admin' );

	/** Hooks. */
	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'on_user_saved' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_user_saved' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
		add_action( 'vhus_member_created', array( __CLASS__, 'on_member_created' ) );
	}

	/** The member linked to a user, if any. */
	public static function member_for_user( int $user_id ): ?object {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'members' ) . ' WHERE wp_user_id = %d LIMIT 1', $user_id ) );
	}

	/** The user linked to a member, if it still exists. */
	public static function user_for_member( int $member_id ): ?\WP_User {
		$m = Members::get( $member_id );
		if ( ! $m || ! $m->wp_user_id ) {
			return null;
		}
		$u = get_userdata( (int) $m->wp_user_id );
		return $u ? $u : null;
	}

	/** Link (or unlink with 0). One user per member and one member per user. */
	public static function link( int $member_id, int $user_id ): bool {
		global $wpdb;
		if ( $user_id ) {
			$other = self::member_for_user( $user_id );
			if ( $other && (int) $other->id !== $member_id ) {
				return false;
			}
		}
		$wpdb->update( DB::t( 'members' ), array( 'wp_user_id' => $user_id ? $user_id : null ), array( 'id' => $member_id ) );
		Log::add( $user_id ? 'wp_user_linked' : 'wp_user_unlinked', 'member', $member_id, array( 'user' => $user_id ) );
		return true;
	}

	/** Link a member to the WordPress user with the same e-mail, if exactly that one exists and is free. */
	public static function link_by_email( int $member_id ): bool {
		$m = Members::get( $member_id );
		if ( ! $m || $m->wp_user_id || '' === (string) $m->email ) {
			return false;
		}
		$u = get_user_by( 'email', $m->email );
		if ( ! $u || self::member_for_user( (int) $u->ID ) ) {
			return false;
		}
		return self::link( $member_id, (int) $u->ID );
	}

	/** One-off: link every unlinked member whose e-mail matches a free user. Returns the number linked. */
	public static function link_all_by_email(): int {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_col( 'SELECT id FROM ' . DB::t( 'members' ) . " WHERE wp_user_id IS NULL AND email <> ''" ) as $id ) {
			$n += (int) self::link_by_email( (int) $id );
		}
		return $n;
	}

	/** New or changed WordPress user: link to the member with the same e-mail if neither is linked. */
	public static function on_user_saved( int $user_id ): void {
		if ( self::member_for_user( $user_id ) ) {
			return;
		}
		$u = get_userdata( $user_id );
		$m = $u ? Members::find_by_email( (string) $u->user_email ) : null;
		if ( $m && ! $m->wp_user_id ) {
			self::link( (int) $m->id, $user_id );
		}
	}

	/** A WordPress user was deleted: drop the link, keep the member. */
	public static function on_user_deleted( int $user_id ): void {
		$m = self::member_for_user( $user_id );
		if ( $m ) {
			self::link( (int) $m->id, 0 );
		}
	}

	/** New member: link to an existing user with the same e-mail. */
	public static function on_member_created( int $member_id ): void {
		self::link_by_email( $member_id );
	}

	/**
	 * Create a WordPress user for a member and link them. WordPress sends its own
	 * "set your password" e-mail, so no password is ever chosen or seen by the admin.
	 *
	 * @param string[] $roles Plugin roles to give (subset of ROLES).
	 * @return int|\WP_Error User id.
	 */
	public static function create_user_for( int $member_id, array $roles = array() ) {
		$m = Members::get( $member_id );
		if ( ! $m ) {
			return new \WP_Error( 'vhus_no_member', 'Medlemmet findes ikke.' );
		}
		if ( $m->wp_user_id && get_userdata( (int) $m->wp_user_id ) ) {
			return new \WP_Error( 'vhus_linked', 'Medlemmet har allerede en WordPress-bruger.' );
		}
		if ( ! is_email( $m->email ) ) {
			return new \WP_Error( 'vhus_no_email', 'Medlemmet skal have en e-mailadresse, før der kan oprettes en bruger.' );
		}
		$existing = get_user_by( 'email', $m->email );
		if ( $existing ) {
			if ( ! self::link( $member_id, (int) $existing->ID ) ) {
				return new \WP_Error( 'vhus_taken', 'Der findes allerede en WordPress-bruger med den e-mail, og den er koblet til et andet medlem.' );
			}
			self::set_roles( (int) $existing->ID, $roles );
			return (int) $existing->ID;
		}

		$parts   = preg_split( '/\s+/', trim( (string) $m->name ) );
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( (string) $m->email ),
				'user_email'   => $m->email,
				'user_pass'    => wp_generate_password( 32 ),
				'display_name' => $m->name,
				'first_name'   => (string) array_shift( $parts ),
				'last_name'    => implode( ' ', $parts ),
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		self::link( $member_id, (int) $user_id );
		self::set_roles( (int) $user_id, $roles );
		wp_new_user_notification( (int) $user_id, null, 'user' );
		Log::add( 'wp_user_created', 'member', $member_id, array( 'user' => (int) $user_id ) );
		return (int) $user_id;
	}

	/** Username from the e-mail's local part, made unique. */
	private static function unique_login( string $email ): string {
		$base = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
		$base = '' !== $base ? $base : 'medlem';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		return $login;
	}

	/**
	 * Give exactly these plugin roles (others of the user's roles are untouched).
	 * A user left with no role at all gets "subscriber" so they can still log in.
	 *
	 * @param string[] $roles Subset of ROLES.
	 */
	public static function set_roles( int $user_id, array $roles ): void {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		$want   = array_values( array_intersect( self::ROLES, $roles ) );
		$before = array_values( array_intersect( self::ROLES, $u->roles ) );
		foreach ( self::ROLES as $r ) {
			if ( in_array( $r, $want, true ) && ! in_array( $r, $u->roles, true ) ) {
				$u->add_role( $r );
			} elseif ( ! in_array( $r, $want, true ) && in_array( $r, $u->roles, true ) ) {
				$u->remove_role( $r );
			}
		}
		if ( ! $u->roles ) {
			$u->add_role( 'subscriber' );
		}
		if ( $want !== $before ) {
			$m = self::member_for_user( $user_id );
			Log::add( 'wp_roles_changed', 'member', $m ? (int) $m->id : 0, array( 'user' => $user_id, 'roles' => $want ) );
		}
	}

	/**
	 * Danish names of the plugin roles a user holds (plus "Administrator").
	 *
	 * @return string[]
	 */
	public static function role_labels( ?\WP_User $u ): array {
		if ( ! $u ) {
			return array();
		}
		$labels = array();
		if ( in_array( 'administrator', $u->roles, true ) ) {
			$labels[] = 'Administrator';
		}
		$defs = Roles::definitions();
		foreach ( self::ROLES as $r ) {
			if ( in_array( $r, $u->roles, true ) ) {
				$labels[] = $defs[ $r ]['label'];
			}
		}
		return $labels;
	}
}
