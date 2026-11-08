<?php
/**
 * Member ↔ WordPress user: box on the member page, panel on the user profile, list columns.
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\Members;
use VHus\Roles;
use VHus\Users;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator give a member a login and board roles (e.g. after the general assembly),
 * and see from a WordPress user which member they are.
 */
class Users_Admin {

	/** Hooks. */
	public static function init(): void {
		add_action( 'admin_post_vhus_wp_user', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_vhus_member_from_user', array( __CLASS__, 'handle_member_from_user' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_panel' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_panel' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/** May the current user manage logins and roles? */
	private static function can_manage(): bool {
		return current_user_can( Roles::CAP_ADMIN ) && current_user_can( 'promote_users' );
	}

	/** Redirect back to the member page. */
	private static function back( int $member_id, string $msg, string $type = 'success' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'vhus-members',
					'action'    => 'edit',
					'id'        => $member_id,
					'vhus_msg'  => rawurlencode( $msg ),
					'vhus_type' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Role checkboxes.
	 *
	 * @param string[] $checked Roles to tick.
	 */
	private static function role_checkboxes( array $checked ): void {
		$defs = Roles::definitions();
		foreach ( Users::ROLES as $r ) {
			printf(
				'<label class="vhus-check-inline"><input type="checkbox" name="roles[]" value="%s"%s> %s</label> ',
				esc_attr( $r ),
				checked( in_array( $r, $checked, true ), true, false ),
				esc_html( $defs[ $r ]['label'] )
			);
		}
	}

	/** Box on the member page (administrators only). */
	public static function member_box( object $m ): void {
		if ( ! self::can_manage() ) {
			return;
		}
		$u     = Users::user_for_member( (int) $m->id );
		$match = ! $u && $m->email ? get_user_by( 'email', $m->email ) : null;
		$match = $match && ! Users::member_for_user( (int) $match->ID ) ? $match : null;
		?>
		<h2>Login og roller</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-wpuser">
			<input type="hidden" name="action" value="vhus_wp_user">
			<input type="hidden" name="member_id" value="<?php echo (int) $m->id; ?>">
			<?php wp_nonce_field( 'vhus_wp_user_' . (int) $m->id ); ?>
			<?php if ( $u ) : ?>
				<p>
					WordPress-bruger: <a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><strong><?php echo esc_html( $u->user_login ); ?></strong></a>
					<?php if ( strtolower( $u->user_email ) !== strtolower( (string) $m->email ) ) : ?>
						<br><span class="vhus-error">Brugerens e-mail (<?php echo esc_html( $u->user_email ); ?>) er ikke den samme som medlemmets.</span>
					<?php endif; ?>
					<?php if ( in_array( 'administrator', $u->roles, true ) ) : ?>
						<br><span class="vhus-muted">Er også administrator af hjemmesiden.</span>
					<?php endif; ?>
				</p>
				<p><?php self::role_checkboxes( $u->roles ); ?></p>
				<p>
					<button class="button button-primary" name="do" value="roles">Gem roller</button>
					<button class="button-link vhus-danger" name="do" value="unlink" onclick="return confirm('Fjern koblingen? Brugeren og medlemmet bliver begge ved med at findes.')">Fjern kobling</button>
				</p>
				<p class="description">Fx efter generalforsamlingen: sæt flueben ved den nye kasserers rolle her, og fjern den hos den gamle. Roller gælder med det samme.</p>
			<?php elseif ( $match ) : ?>
				<p>Der findes en WordPress-bruger med samme e-mail: <a href="<?php echo esc_url( get_edit_user_link( $match->ID ) ); ?>"><?php echo esc_html( $match->user_login ); ?></a>.</p>
				<p><?php self::role_checkboxes( $match->roles ); ?></p>
				<p><button class="button" name="do" value="link">Kobl til denne bruger og gem roller</button></p>
			<?php else : ?>
				<p>Medlemmet har ikke et login. Opret et, hvis de skal være værkstedskontakt, kasserer eller portadministrator.</p>
				<?php if ( ! is_email( (string) $m->email ) ) : ?>
					<p class="vhus-error">Medlemmet skal have en e-mail først.</p>
				<?php else : ?>
					<p><?php self::role_checkboxes( array() ); ?></p>
					<p><button class="button" name="do" value="create">Opret WordPress-bruger</button></p>
					<p class="description">Brugernavnet bliver dannet ud fra e-mailen, og <?php echo esc_html( $m->email ); ?> får WordPress' e-mail om at vælge en adgangskode.</p>
				<?php endif; ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/** Create, link, change roles or unlink. */
	public static function handle(): void {
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		check_admin_referer( 'vhus_wp_user_' . $member_id );
		if ( ! self::can_manage() || ! Members::get( $member_id ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$do    = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array();
		$m     = Members::get( $member_id );

		switch ( $do ) {
			case 'create':
				if ( ! current_user_can( 'create_users' ) ) {
					wp_die( 'Du har ikke adgang.', 403 );
				}
				$res = Users::create_user_for( $member_id, $roles );
				if ( is_wp_error( $res ) ) {
					self::back( $member_id, $res->get_error_message(), 'error' );
				}
				self::back( $member_id, sprintf( 'Bruger oprettet. %s har fået en e-mail om at vælge adgangskode.', $m->email ) );
				break;
			case 'link':
				$u = $m->email ? get_user_by( 'email', $m->email ) : null;
				if ( ! $u || ! Users::link( $member_id, (int) $u->ID ) ) {
					self::back( $member_id, 'Kunne ikke koble brugeren.', 'error' );
				}
				Users::set_roles( (int) $u->ID, $roles );
				self::back( $member_id, 'Koblet til WordPress-brugeren.' );
				break;
			case 'roles':
				$u = Users::user_for_member( $member_id );
				if ( ! $u ) {
					self::back( $member_id, 'Medlemmet har ingen bruger.', 'error' );
				}
				if ( (int) $u->ID === get_current_user_id() && ! in_array( 'administrator', $u->roles, true ) ) {
					self::back( $member_id, 'Du kan ikke ændre dine egne roller her.', 'error' );
				}
				Users::set_roles( (int) $u->ID, $roles );
				$labels = Users::role_labels( get_userdata( (int) $u->ID ) );
				self::back( $member_id, 'Roller gemt: ' . ( $labels ? implode( ', ', $labels ) : 'ingen' ) . '.' );
				break;
			case 'unlink':
				Users::link( $member_id, 0 );
				self::back( $member_id, 'Koblingen er fjernet. Brugeren findes stadig i WordPress.' );
				break;
		}
		self::back( $member_id, 'Ukendt handling.', 'error' );
	}

	/** Panel on a WordPress user's profile. */
	public static function profile_panel( \WP_User $user ): void {
		if ( ! current_user_can( Roles::CAP_ADMIN ) && get_current_user_id() !== $user->ID ) {
			return;
		}
		$m = Users::member_for_user( (int) $user->ID );
		?>
		<h2>V-huset</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Medlem</th>
				<td>
					<?php if ( $m && Roles::can_access() ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $m->id ) ); ?>"><?php echo esc_html( $m->name ); ?></a> (medlemsnr. <?php echo (int) $m->id; ?>)
					<?php elseif ( $m ) : ?>
						<?php echo esc_html( $m->name ); ?> (medlemsnr. <?php echo (int) $m->id; ?>)
					<?php elseif ( current_user_can( Roles::CAP_MANAGE_ALL ) ) : ?>
						<em>Ikke koblet til et medlem.</em>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vhus_member_from_user&user_id=' . (int) $user->ID ), 'vhus_member_from_user_' . (int) $user->ID ) ); ?>">Opret som medlem</a>
						<p class="description">Opretter et medlem med brugerens navn og e-mail. Telefon og værksted tilføjes bagefter på medlemmets side.</p>
					<?php else : ?>
						<em>Ikke koblet til et medlem.</em>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Create a member from a WordPress user. */
	public static function handle_member_from_user(): void {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'vhus_member_from_user_' . $user_id );
		$u = get_userdata( $user_id );
		if ( ! current_user_can( Roles::CAP_MANAGE_ALL ) || ! $u ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$m = Users::member_for_user( $user_id ) ?? Members::find_by_email( (string) $u->user_email );
		if ( ! $m ) {
			$id = Members::create(
				array(
					'name'  => trim( $u->display_name ) ? $u->display_name : $u->user_login,
					'email' => $u->user_email,
				)
			);
		} else {
			$id = (int) $m->id;
		}
		if ( ! Users::member_for_user( $user_id ) ) {
			Users::link( $id, $user_id );
		}
		self::back( $id, $m ? 'Brugeren er koblet til det eksisterende medlem med samme e-mail.' : 'Medlem oprettet ud fra brugeren. Tilføj telefon og værksted.' );
	}

	/**
	 * "V-huset" column on the Users list.
	 *
	 * @param array $cols Columns.
	 */
	public static function users_column( array $cols ): array {
		if ( current_user_can( Roles::CAP_ADMIN ) ) {
			$cols['vhus_member'] = 'V-huset-medlem';
		}
		return $cols;
	}

	/**
	 * Column value.
	 *
	 * @param string $out     Output so far.
	 * @param string $column  Column.
	 * @param int    $user_id User.
	 */
	public static function users_column_value( $out, $column, $user_id ) {
		if ( 'vhus_member' !== $column ) {
			return $out;
		}
		$m = Users::member_for_user( (int) $user_id );
		return $m
			? '<a href="' . esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $m->id ) ) . '">Nr. ' . (int) $m->id . '</a>'
			: '—';
	}
}
