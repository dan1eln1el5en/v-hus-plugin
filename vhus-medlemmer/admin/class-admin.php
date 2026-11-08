<?php
/**
 * Admin menus, screens and form handlers.
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\Flow;
use VHus\Importer;
use VHus\Log;
use VHus\Members;
use VHus\Roles;
use VHus\Settings;
use VHus\Util;
use VHus\Workshops;
use VHus\Xlsx_Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Every handler checks a capability and a nonce; menus only hide what handlers already refuse.
 */
class Admin {

	/** Hook everything up. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'option_page_capability_vhus_settings', fn() => Roles::CAP_ADMIN );

		foreach ( array( 'save_member', 'seat_action', 'add_seat', 'add_key', 'save_workshops', 'import_preview', 'import_commit' ) as $a ) {
			add_action( 'admin_post_vhus_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/** The capability that opens the top-level menu for this user. */
	private static function menu_cap(): string {
		foreach ( array( Roles::CAP_MANAGE_ALL, Roles::CAP_VIEW_ALL, Roles::CAP_OWN_WORKSHOP ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}
		return Roles::CAP_MANAGE_ALL;
	}

	/** Admin menu. */
	public static function menu(): void {
		$cap = self::menu_cap();
		add_menu_page( 'V-huset', 'V-huset', $cap, 'vhus', array( __CLASS__, 'page_overview' ), 'dashicons-hammer', 26 );
		add_submenu_page( 'vhus', 'Oversigt', 'Oversigt', $cap, 'vhus', array( __CLASS__, 'page_overview' ) );
		add_submenu_page( 'vhus', 'Medlemmer', 'Medlemmer', $cap, 'vhus-members', array( __CLASS__, 'page_members' ) );
		add_submenu_page( 'vhus', 'Værksteder', 'Værksteder', Roles::CAP_ADMIN, 'vhus-workshops', array( __CLASS__, 'page_workshops' ) );
		add_submenu_page( 'vhus', 'Import', 'Import', Roles::CAP_ADMIN, 'vhus-import', array( __CLASS__, 'page_import' ) );
		add_submenu_page( 'vhus', 'Indstillinger', 'Indstillinger', Roles::CAP_ADMIN, 'vhus-settings', array( __CLASS__, 'page_settings' ) );
	}

	/** Small stylesheet on our screens only. */
	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'vhus' ) ) {
			return;
		}
		wp_enqueue_style( 'vhus-admin', VHUS_URL . 'admin/admin.css', array(), VHUS_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Stop unless the user has the capability. */
	private static function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
	}

	/** Stop unless the user may open the plugin. */
	private static function require_access(): void {
		if ( ! Roles::can_access() ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string $url    Target URL.
	 * @param string $msg    Message code.
	 * @param string $type   success|error|warning.
	 */
	private static function redirect( string $url, string $msg, string $type = 'success' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'vhus_msg'  => rawurlencode( $msg ),
					'vhus_type' => $type,
				),
				$url
			)
		);
		exit;
	}

	/** Print the notice set by redirect(). */
	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['vhus_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['vhus_type'] ) ? sanitize_key( $_GET['vhus_type'] ) : 'success';
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		$msg  = sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhus_msg'] ) ) );
		// phpcs:enable
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	/** URL of a member's edit screen. */
	private static function member_url( int $id ): string {
		return add_query_arg(
			array(
				'page'   => 'vhus-members',
				'action' => 'edit',
				'id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Workshops the current user may assign seats in.
	 *
	 * @return object[]
	 */
	private static function assignable_workshops(): array {
		$all = Workshops::all();
		if ( current_user_can( Roles::CAP_MANAGE_ALL ) ) {
			return $all;
		}
		$own = Workshops::ids_for_contact( get_current_user_id() );
		return array_values( array_filter( $all, fn( $w ) => in_array( (int) $w->id, $own, true ) ) );
	}

	/** Format a MySQL datetime for display. */
	private static function date( ?string $mysql ): string {
		if ( ! $mysql ) {
			return '—';
		}
		return esc_html( mysql2date( 'j. M Y', $mysql ) );
	}

	/* ---------------------------------------------------------------------
	 * Overview
	 * ------------------------------------------------------------------ */

	/** Dashboard with counts per workshop. */
	public static function page_overview(): void {
		self::require_access();
		$counts  = Workshops::seat_counts();
		$visible = Roles::visible_workshop_ids();
		$rows    = Members::query();
		$by_st   = array_count_values( array_column( $rows, 'status' ) );
		?>
		<div class="wrap vhus">
			<h1>V-huset – oversigt</h1>
			<?php self::notice(); ?>
			<p>Medlemsår <strong><?php echo esc_html( Util::year_label() ); ?></strong>.</p>

			<div class="vhus-cards">
				<?php if ( current_user_can( Roles::CAP_INVOICE ) ) : ?>
					<a class="vhus-card" href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-invoicing' ) ); ?>">
						<span class="vhus-card-n"><?php echo count( Flow::invoice_list( 'pending' ) ); ?></span>
						<span class="vhus-card-l">Klar til fakturering</span>
					</a>
				<?php endif; ?>
				<?php if ( current_user_can( Roles::CAP_GATE ) ) : ?>
					<a class="vhus-card" href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-gate' ) ); ?>">
						<span class="vhus-card-n"><?php echo (int) \VHus\Gate::open_count(); ?></span>
						<span class="vhus-card-l">Portopgaver</span>
					</a>
				<?php endif; ?>
				<?php foreach ( array( 'active', 'awaiting_payment', 'waiting' ) as $st ) : ?>
					<a class="vhus-card" href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&status=' . $st ) ); ?>">
						<span class="vhus-card-n"><?php echo (int) ( $by_st[ $st ] ?? 0 ); ?></span>
						<span class="vhus-card-l"><?php echo esc_html( Members::MEMBER_STATUSES[ $st ] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<h2>Værksteder</h2>
			<table class="widefat striped vhus-narrow">
				<thead><tr><th>Værksted</th><th class="num">Medlemmer</th><th class="num">Venteliste</th><th class="num">Pladser</th><th>Kontakt</th></tr></thead>
				<tbody>
				<?php
				foreach ( Workshops::all() as $w ) :
					if ( is_array( $visible ) && ! in_array( (int) $w->id, $visible, true ) ) {
						continue;
					}
					$c       = $counts[ (int) $w->id ] ?? array();
					$contact = $w->contact_user_id ? get_userdata( (int) $w->contact_user_id ) : null;
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&workshop=' . (int) $w->id ) ); ?>"><?php echo esc_html( $w->name ); ?></a></td>
						<td class="num"><?php echo (int) ( $c['approved'] ?? 0 ); ?></td>
						<td class="num"><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-waiting&workshop=' . (int) $w->id ) ); ?>"><?php echo (int) ( $c['waiting'] ?? 0 ); ?></a></td>
						<td class="num"><?php echo null === $w->capacity ? '—' : (int) $w->capacity; ?></td>
						<td><?php echo $contact ? esc_html( $contact->display_name ) : '<em>Ingen</em>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Members
	 * ------------------------------------------------------------------ */

	/** Router for list / edit / new. */
	public static function page_members(): void {
		self::require_access();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( 'edit' === $action && $id ) {
			self::page_member_edit( $id );
			return;
		}
		if ( 'new' === $action ) {
			self::require_cap( Roles::CAP_MANAGE_ALL );
			self::page_member_edit( 0 );
			return;
		}

		$table = new Members_Table();
		$table->prepare_items();
		?>
		<div class="wrap vhus">
			<h1 class="wp-heading-inline">Medlemmer</h1>
			<?php if ( current_user_can( Roles::CAP_MANAGE_ALL ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=new' ) ); ?>" class="page-title-action">Tilføj medlem</a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php self::notice(); ?>
			<form method="get">
				<input type="hidden" name="page" value="vhus-members">
				<?php
				$table->search_box( 'Søg', 'vhus-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/** Edit (or create when $id is 0) one member. */
	private static function page_member_edit( int $id ): void {
		$m = $id ? Members::get( $id ) : null;
		if ( $id && ( ! $m || ! Roles::can_view_member( $id ) ) ) {
			wp_die( 'Medlemmet findes ikke, eller du har ikke adgang.', 404 );
		}
		$can_edit = $id ? Roles::can_edit_member( $id ) : current_user_can( Roles::CAP_MANAGE_ALL );
		if ( $id && ! current_user_can( Roles::CAP_VIEW_ALL ) && ! current_user_can( Roles::CAP_MANAGE_ALL ) ) {
			Log::add( 'member_view', 'member', $id );
		}
		$v        = fn( string $f ) => $m ? (string) $m->$f : '';
		$disabled = $can_edit ? '' : ' disabled';
		$own_ws   = current_user_can( Roles::CAP_MANAGE_ALL ) ? null : Workshops::ids_for_contact( get_current_user_id() );
		?>
		<div class="wrap vhus">
			<h1><?php echo $m ? esc_html( $m->name ) : 'Nyt medlem'; ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members' ) ); ?>">&larr; Alle medlemmer</a></p>
			<?php self::notice(); ?>

			<div class="vhus-columns">
			<div class="vhus-col">
			<h2>Stamdata</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vhus_save_member">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'vhus_save_member_' . $id ); ?>
				<table class="form-table" role="presentation">
					<?php
					$fields = array(
						'name'       => array( 'Navn', 'text', true ),
						'email'      => array( 'E-mail', 'email', false ),
						'phone_e164' => array( 'Telefon', 'tel', false ),
						'address'    => array( 'Adresse', 'text', false ),
						'postcode'   => array( 'Postnummer', 'text', false ),
						'city'       => array( 'By', 'text', false ),
					);
					foreach ( $fields as $f => $def ) :
						?>
						<tr>
							<th scope="row"><label for="vhus-<?php echo esc_attr( $f ); ?>"><?php echo esc_html( $def[0] ); ?></label></th>
							<td><input class="regular-text" type="<?php echo esc_attr( $def[1] ); ?>" id="vhus-<?php echo esc_attr( $f ); ?>" name="member[<?php echo esc_attr( $f ); ?>]" value="<?php echo esc_attr( $v( $f ) ); ?>"<?php echo $def[2] ? ' required' : ''; ?><?php echo esc_attr( $disabled ); ?>></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="vhus-notes">Noter</label></th>
						<td><textarea class="large-text" rows="4" id="vhus-notes" name="member[notes]"<?php echo esc_attr( $disabled ); ?>><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea></td>
					</tr>
					<?php if ( ! $m ) : ?>
						<tr>
							<th scope="row"><label for="vhus-workshop">Værksted</label></th>
							<td>
								<select id="vhus-workshop" name="workshop_id">
									<option value="">— Vælg —</option>
									<?php foreach ( Workshops::all() as $w ) : ?>
										<option value="<?php echo (int) $w->id; ?>"><?php echo esc_html( $w->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="seat_status">
									<option value="waiting">På venteliste</option>
									<option value="approved">Godkendt</option>
								</select>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php
				if ( $can_edit ) {
					submit_button( $m ? 'Gem' : 'Opret medlem' );
				}
				?>
			</form>
			</div>

			<?php if ( $m ) : ?>
			<div class="vhus-col">
				<h2>Værksteder</h2>
				<table class="widefat striped">
					<thead><tr><th>Værksted</th><th>Status</th><th>Tilmeldt</th><th>Note</th><th></th></tr></thead>
					<tbody>
					<?php
					$seats = Members::seats( $id );
					if ( ! $seats ) {
						echo '<tr><td colspan="5"><em>Intet værksted.</em></td></tr>';
					}
					foreach ( $seats as $s ) :
						$seat_editable = $can_edit && ( null === $own_ws || in_array( (int) $s->workshop_id, $own_ws, true ) );
						?>
						<tr>
							<td><?php echo esc_html( $s->workshop_name ); ?></td>
							<?php if ( $seat_editable ) : ?>
								<td colspan="4">
									<form class="vhus-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="vhus_seat_action">
										<input type="hidden" name="seat_id" value="<?php echo (int) $s->id; ?>">
										<?php wp_nonce_field( 'vhus_seat_' . (int) $s->id ); ?>
										<select name="status" aria-label="Status">
											<?php foreach ( Members::SEAT_STATUSES as $k => $label ) : ?>
												<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $s->status, $k ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<span class="vhus-muted"><?php echo self::date( $s->requested_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in date(). ?></span>
										<input type="text" name="note" value="<?php echo esc_attr( (string) $s->note ); ?>" placeholder="Note" aria-label="Note">
										<button class="button">Gem</button>
									</form>
								</td>
							<?php else : ?>
								<td><?php echo esc_html( Members::SEAT_STATUSES[ $s->status ] ?? $s->status ); ?></td>
								<td><?php echo self::date( $s->requested_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( (string) $s->note ); ?></td>
								<td></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$assignable = $can_edit ? self::assignable_workshops() : array();
				if ( $assignable ) :
					?>
					<form class="vhus-inline vhus-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="vhus_add_seat">
						<input type="hidden" name="member_id" value="<?php echo (int) $id; ?>">
						<?php wp_nonce_field( 'vhus_add_seat_' . $id ); ?>
						<select name="workshop_id" aria-label="Værksted">
							<?php foreach ( $assignable as $w ) : ?>
								<option value="<?php echo (int) $w->id; ?>"><?php echo esc_html( $w->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="status" aria-label="Status">
							<option value="waiting">På venteliste</option>
							<option value="approved">Godkendt</option>
						</select>
						<button class="button">Tilføj værksted</button>
					</form>
				<?php endif; ?>

				<h2>Kontingent</h2>
				<table class="widefat striped">
					<thead><tr><th>År</th><th>Kontingent</th><th>Status</th><th>Betalt</th></tr></thead>
					<tbody>
					<?php
					$periods = Members::periods( $id );
					if ( ! $periods ) {
						echo '<tr><td colspan="4"><em>Ingen perioder endnu.</em></td></tr>';
					}
					foreach ( $periods as $p ) :
						?>
						<tr>
							<td><?php echo esc_html( $p->year_label ); ?></td>
							<td><?php echo (int) $p->amount_fee + (int) $p->amount_key > 0 ? esc_html( Flow::specification( $p ) ) : ( 'half' === $p->fee_type ? 'Halvt år' : 'Helt år' ); ?></td>
							<td><?php echo esc_html( Members::PERIOD_STATUSES[ $p->status ] ?? $p->status ); ?></td>
							<td><?php echo self::date( $p->paid_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php echo $m->dinero_contact_guid ? 'Koblet til Dinero.' : 'Ikke koblet til en Dinero-kontakt endnu.'; ?> Betalingsstatus kommer fra Dinero og kan ikke ændres her. Ændringer af værkstedsstatus her sender ingen e-mails; brug Ventelister for det.</p>

				<h2>Nøglebrik</h2>
				<table class="widefat striped">
					<thead><tr><th>Nummer</th><th>Udleveret</th><th>Afleveret</th></tr></thead>
					<tbody>
					<?php
					$keys = Members::keys( $id );
					if ( ! $keys ) {
						echo '<tr><td colspan="3"><em>Ingen brik registreret.</em></td></tr>';
					}
					foreach ( $keys as $k ) :
						?>
						<tr>
							<td><?php echo esc_html( $k->fob_number ); ?></td>
							<td><?php echo self::date( $k->issued_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo self::date( $k->returned_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( current_user_can( Roles::CAP_MANAGE_ALL ) || current_user_can( Roles::CAP_GATE ) ) : ?>
					<form class="vhus-inline vhus-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="vhus_add_key">
						<input type="hidden" name="member_id" value="<?php echo (int) $id; ?>">
						<?php wp_nonce_field( 'vhus_add_key_' . $id ); ?>
						<input type="text" name="fob_number" placeholder="Briknummer" aria-label="Briknummer" required>
						<button class="button">Registrér brik</button>
					</form>
				<?php endif; ?>

				<?php if ( current_user_can( Roles::CAP_ADMIN ) ) : ?>
					<h2>Log</h2>
					<ul class="vhus-log">
						<?php foreach ( Log::for_object( 'member', $id, 20 ) as $entry ) : ?>
							<?php $u = $entry->user_id ? get_userdata( (int) $entry->user_id ) : null; ?>
							<li><?php echo esc_html( mysql2date( 'j. M Y H:i', $entry->created_at ) . ' · ' . ( $u ? $u->display_name : 'system' ) . ' · ' . $entry->action ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php Tools_Pages::delete_box( $m ); ?>
			</div>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Save or create a member. */
	public static function handle_save_member(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'vhus_save_member_' . $id );
		if ( $id ? ! Roles::can_edit_member( $id ) : ! current_user_can( Roles::CAP_MANAGE_ALL ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$data = isset( $_POST['member'] ) && is_array( $_POST['member'] ) ? wp_unslash( $_POST['member'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in Members::clean().
		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			self::redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=vhus-members' ), 'Navn skal udfyldes.', 'error' );
		}
		$clean = Members::clean( $data );
		if ( '' !== ( $clean['email'] ?? '' ) ) {
			$dup = Members::find_by_email( $clean['email'] );
			if ( $dup && (int) $dup->id !== $id ) {
				self::redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=vhus-members' ), 'E-mailadressen bruges allerede af et andet medlem.', 'error' );
			}
		}
		if ( $id ) {
			Members::update( $id, $data );
			self::redirect( self::member_url( $id ), 'Gemt.' );
		}
		$id    = Members::create( $data );
		$ws_id = isset( $_POST['workshop_id'] ) ? absint( $_POST['workshop_id'] ) : 0;
		if ( $ws_id && Workshops::get( $ws_id ) ) {
			$seat_id = Members::add_seat( $id, $ws_id, 'waiting' );
			if ( isset( $_POST['seat_status'] ) && 'approved' === $_POST['seat_status'] ) {
				Flow::approve( $seat_id, false );
			}
		}
		self::redirect( self::member_url( $id ), 'Medlemmet er oprettet.' );
	}

	/** Change a seat's status and note. */
	public static function handle_seat_action(): void {
		$seat_id = isset( $_POST['seat_id'] ) ? absint( $_POST['seat_id'] ) : 0;
		check_admin_referer( 'vhus_seat_' . $seat_id );
		$seat = Members::seat( $seat_id );
		if ( ! $seat || ! Roles::can_manage_workshop( (int) $seat->workshop_id ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( 'approved' === $status ) {
			Flow::approve( $seat_id, false );
		} elseif ( 'declined' === $status ) {
			Flow::decline( $seat_id, '', false );
		} else {
			Members::set_seat_status( $seat_id, $status );
		}
		Members::set_seat_note( $seat_id, $note );
		$back = Roles::can_view_member( (int) $seat->member_id ) ? self::member_url( (int) $seat->member_id ) : admin_url( 'admin.php?page=vhus-waiting&workshop=' . (int) $seat->workshop_id );
		self::redirect( $back, 'Værkstedsstatus gemt.' );
	}

	/** Add a seat to a member. */
	public static function handle_add_seat(): void {
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		check_admin_referer( 'vhus_add_seat_' . $member_id );
		$ws_id = isset( $_POST['workshop_id'] ) ? absint( $_POST['workshop_id'] ) : 0;
		if ( ! Members::get( $member_id ) || ! Workshops::get( $ws_id ) || ! Roles::can_manage_workshop( $ws_id ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$seat_id = Members::add_seat( $member_id, $ws_id, 'waiting' );
		if ( isset( $_POST['status'] ) && 'approved' === $_POST['status'] ) {
			Flow::approve( $seat_id, false );
		}
		self::redirect( self::member_url( $member_id ), 'Værksted tilføjet.' );
	}

	/** Register a fob. */
	public static function handle_add_key(): void {
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		check_admin_referer( 'vhus_add_key_' . $member_id );
		if ( ! current_user_can( Roles::CAP_MANAGE_ALL ) && ! current_user_can( Roles::CAP_GATE ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		if ( ! Members::get( $member_id ) ) {
			wp_die( 'Medlemmet findes ikke.', 404 );
		}
		Members::add_key( $member_id, isset( $_POST['fob_number'] ) ? sanitize_text_field( wp_unslash( $_POST['fob_number'] ) ) : '' );
		self::redirect( self::member_url( $member_id ), 'Brik registreret.' );
	}


	/* ---------------------------------------------------------------------
	 * Workshops
	 * ------------------------------------------------------------------ */

	/** Edit workshop names, contacts and capacity. */
	public static function page_workshops(): void {
		self::require_cap( Roles::CAP_ADMIN );
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		?>
		<div class="wrap vhus">
			<h1>Værksteder</h1>
			<?php self::notice(); ?>
			<p>Værkstedskontakten skal have rollen <strong>Værkstedskontakt</strong> (eller være administrator) for at kunne se sit værksted.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vhus_save_workshops">
				<?php wp_nonce_field( 'vhus_save_workshops' ); ?>
				<table class="widefat striped vhus-narrow">
					<thead><tr><th>Kode</th><th>Navn</th><th>Kontakt</th><th>Pladser</th></tr></thead>
					<tbody>
					<?php foreach ( Workshops::all() as $w ) : ?>
						<tr>
							<td><code><?php echo esc_html( $w->code ); ?></code></td>
							<td><input type="text" name="ws[<?php echo (int) $w->id; ?>][name]" value="<?php echo esc_attr( $w->name ); ?>" aria-label="Navn"></td>
							<td>
								<select name="ws[<?php echo (int) $w->id; ?>][contact_user_id]" aria-label="Kontakt">
									<option value="">— Ingen —</option>
									<?php foreach ( $users as $u ) : ?>
										<option value="<?php echo (int) $u->ID; ?>"<?php selected( (int) $w->contact_user_id, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="number" min="0" class="small-text" name="ws[<?php echo (int) $w->id; ?>][capacity]" value="<?php echo esc_attr( (string) $w->capacity ); ?>" aria-label="Pladser"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'Gem værksteder' ); ?>
			</form>
		</div>
		<?php
	}

	/** Save workshops. */
	public static function handle_save_workshops(): void {
		check_admin_referer( 'vhus_save_workshops' );
		self::require_cap( Roles::CAP_ADMIN );
		$input = isset( $_POST['ws'] ) && is_array( $_POST['ws'] ) ? wp_unslash( $_POST['ws'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Workshops::update().
		foreach ( $input as $id => $data ) {
			if ( Workshops::get( absint( $id ) ) && is_array( $data ) ) {
				Workshops::update( absint( $id ), $data );
			}
		}
		self::redirect( admin_url( 'admin.php?page=vhus-workshops' ), 'Værksteder gemt.' );
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/** Transient key for the current user's pending import. */
	private static function import_key(): string {
		return 'vhus_import_' . get_current_user_id();
	}

	/** Upload form, or the dry-run preview when a plan is waiting. */
	public static function page_import(): void {
		self::require_cap( Roles::CAP_ADMIN );
		$plan = get_transient( self::import_key() );
		?>
		<div class="wrap vhus">
			<h1>Import af medlemsliste</h1>
			<?php self::notice(); ?>

			<?php if ( ! $plan ) : ?>
				<p>Upload medlemslisten som Excel (.xlsx) eller CSV. Første trin er en prøvekørsel: intet bliver gemt, før du har set listen og trykket <strong>Importér</strong>.</p>
				<p>Kolonnerne Reg og Konto bliver ikke læst ind. Importerede medlemmer bliver godkendt i deres værksted og får kontingent for <?php echo esc_html( Util::year_label() ); ?> med status "Importeret", som regnes som betalt, indtil Dinero bekræfter eller retter det.</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vhus_import_preview">
					<?php wp_nonce_field( 'vhus_import_preview' ); ?>
					<input type="file" name="file" accept=".xlsx,.csv" required>
					<?php submit_button( 'Vis prøvekørsel' ); ?>
				</form>
			<?php else : ?>
				<?php
				$s = $plan['summary'];
				?>
				<h2>Prøvekørsel: <?php echo esc_html( $plan['filename'] ); ?></h2>
				<?php foreach ( $plan['errors'] as $err ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $err ); ?></p></div>
				<?php endforeach; ?>
				<ul class="vhus-summary">
					<li><strong><?php echo (int) $s['create']; ?></strong> nye medlemmer</li>
					<li><strong><?php echo (int) $s['add_seat']; ?></strong> eksisterende får et nyt værksted</li>
					<li><strong><?php echo (int) $s['skip']; ?></strong> findes allerede og springes over</li>
					<li><strong><?php echo (int) $s['error']; ?></strong> fejl (bliver ikke importeret)</li>
					<li><strong><?php echo (int) $s['warnings']; ?></strong> med bemærkninger</li>
				</ul>
				<table class="widefat striped">
					<thead><tr><th>Række</th><th>Handling</th><th>Navn</th><th>Værksted</th><th>E-mail</th><th>Telefon</th><th>Adresse</th><th>Bemærkninger</th></tr></thead>
					<tbody>
					<?php
					$labels = array(
						'create'   => 'Opret',
						'add_seat' => 'Tilføj værksted',
						'skip'     => 'Spring over',
						'error'    => 'Fejl',
					);
					foreach ( $plan['items'] as $it ) :
						?>
						<tr class="vhus-import-<?php echo esc_attr( $it['action'] ); ?>">
							<td><?php echo (int) $it['row']; ?></td>
							<td><?php echo esc_html( $labels[ $it['action'] ] ?? $it['action'] ); ?></td>
							<td><?php echo esc_html( $it['name'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $it['workshops'] ) ); ?></td>
							<td><?php echo esc_html( $it['email'] ); ?></td>
							<td><?php echo esc_html( $it['phone_e164'] ); ?></td>
							<td><?php echo esc_html( trim( $it['address'] . ' ' . $it['postcode'] . ' ' . $it['city'] ) ); ?></td>
							<td><?php echo esc_html( implode( '; ', $it['warnings'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-import-actions">
					<input type="hidden" name="action" value="vhus_import_commit">
					<input type="hidden" name="token" value="<?php echo esc_attr( $plan['token'] ); ?>">
					<?php wp_nonce_field( 'vhus_import_commit' ); ?>
					<?php
					if ( $s['create'] + $s['add_seat'] > 0 ) {
						submit_button( 'Importér', 'primary', 'do_import', false );
					}
					submit_button( 'Annullér', 'secondary', 'cancel', false );
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Read the upload and store a dry-run plan for one hour. */
	public static function handle_import_preview(): void {
		check_admin_referer( 'vhus_import_preview' );
		self::require_cap( Roles::CAP_ADMIN );
		$back = admin_url( 'admin.php?page=vhus-import' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- file upload, validated below.
		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect( $back, 'Filen blev ikke modtaget.', 'error' );
		}
		$name = sanitize_file_name( $file['name'] );
		// phpcs:enable
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xlsx', 'csv' ), true ) ) {
			self::redirect( $back, 'Kun .xlsx og .csv kan importeres.', 'error' );
		}

		try {
			$rows = Xlsx_Reader::read( $file['tmp_name'], $name );
		} catch ( \RuntimeException $e ) {
			self::redirect( $back, $e->getMessage(), 'error' );
		}
		$plan             = Importer::plan( $rows );
		$plan['filename'] = $name;
		$plan['token']    = wp_generate_password( 20, false );
		set_transient( self::import_key(), $plan, HOUR_IN_SECONDS );
		wp_safe_redirect( $back );
		exit;
	}

	/** Commit or cancel the stored plan. */
	public static function handle_import_commit(): void {
		check_admin_referer( 'vhus_import_commit' );
		self::require_cap( Roles::CAP_ADMIN );
		$back = admin_url( 'admin.php?page=vhus-import' );
		$plan = get_transient( self::import_key() );
		delete_transient( self::import_key() );

		if ( isset( $_POST['cancel'] ) ) {
			self::redirect( $back, 'Importen er annulleret.', 'info' );
		}
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! $plan || ! hash_equals( (string) $plan['token'], $token ) ) {
			self::redirect( $back, 'Prøvekørslen er udløbet. Upload filen igen.', 'error' );
		}
		$counts = Importer::commit( $plan );
		self::redirect(
			admin_url( 'admin.php?page=vhus-members' ),
			sprintf( 'Import færdig: %d medlemmer oprettet, %d værkstedspladser tilføjet.', $counts['members'], $counts['seats'] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/** Register the option. */
	public static function register_settings(): void {
		register_setting(
			'vhus_settings',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
	}

	/** Settings form. */
	public static function page_settings(): void {
		self::require_cap( Roles::CAP_ADMIN );
		$s    = Settings::all();
		$name = fn( string $k ) => Settings::OPTION . '[' . $k . ']';
		$rows = array(
			'Kontingent'   => array(
				'fee_full'      => array( 'Helt år (kr)', 'number', '' ),
				'fee_half'      => array( 'Halvt år (kr)', 'number', '' ),
				'fee_key'       => array( 'Nøglebrik (kr)', 'number', 'Ved indmeldelse og for hver ny brik.' ),
				'half_fee_from' => array( 'Halvt kontingent fra', 'text', 'MM-DD. Indmeldelse fra denne dato betaler halvt år.' ),
			),
			'Medlemsår'    => array(
				'year_start'    => array( 'Medlemsåret starter', 'text', 'MM-DD, normalt efter generalforsamlingen.' ),
				'grace_until'   => array( 'Adgang uden fornyelse til', 'text', 'MM-DD året efter. Slutdato for årets adgangsgruppe i EasyAccess.' ),
				'group_pattern' => array( 'Navn på adgangsgruppe', 'text', '{year} bliver til fx 2026/27.' ),
				'renewal_from'  => array( 'Fornyelsesfakturaer fra', 'text', 'MM-DD. Kontingentfakturaer dateret fra denne dato til medlemsårets start gælder det kommende år.' ),
			),
			'Persondata'   => array(
				'retention_declined' => array( 'Slet afviste efter (måneder)', 'number', '' ),
				'retention_former'   => array( 'Slet udmeldte efter (måneder)', 'number', '' ),
				'retention_log'      => array( 'Gem log og udførte portopgaver (måneder)', 'number', '' ),
				'notify_email'       => array( 'E-mail til advarsler', 'email', 'Tom = sidens administrator-e-mail. Får også besked om nye på ventelisten, når et værksted ikke har en kontakt.' ),
				'privacy_url'        => array( 'Link til privatlivspolitik', 'url', 'Vises ved tilmeldingsformularen. Tom = WordPress’ privatlivsside.' ),
			),
		);
		foreach ( \VHus\Mailer::TEMPLATES as $key => $def ) {
			$rows[ 'E-mail: ' . $def[0] ] = array(
				"mail_{$key}_subject" => array( 'Emne', 'text', 'Tomt emne = e-mailen sendes ikke.' ),
				"mail_{$key}_body"    => array( 'Tekst', 'textarea', 'Felter: ' . $def[1] ),
			);
		}
		?>
		<div class="wrap vhus">
			<h1>Indstillinger</h1>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'vhus_settings' ); ?>
				<?php foreach ( $rows as $section => $fields ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $k => $def ) : ?>
							<tr>
								<th scope="row"><label for="vhus-s-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $def[0] ); ?></label></th>
								<td>
									<?php if ( 'textarea' === $def[1] ) : ?>
										<textarea id="vhus-s-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $name( $k ) ); ?>" rows="7" class="large-text"><?php echo esc_textarea( (string) $s[ $k ] ); ?></textarea>
									<?php else : ?>
										<input id="vhus-s-<?php echo esc_attr( $k ); ?>" type="<?php echo esc_attr( $def[1] ); ?>" name="<?php echo esc_attr( $name( $k ) ); ?>" value="<?php echo esc_attr( (string) $s[ $k ] ); ?>" class="<?php echo 'number' === $def[1] ? 'small-text' : ( 'text' === $def[1] && str_starts_with( $k, 'mail_' ) ? 'large-text' : 'regular-text' ); ?>"<?php echo 'number' === $def[1] ? ' min="0"' : ''; ?>>
									<?php endif; ?>
									<?php if ( $def[2] ) : ?>
										<p class="description"><?php echo esc_html( $def[2] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<p class="description">Medlemsår nu: <strong><?php echo esc_html( Util::year_label() ); ?></strong> · adgangsgruppe: <strong><?php echo esc_html( Util::group_name( Util::year_label() ) ); ?></strong></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
