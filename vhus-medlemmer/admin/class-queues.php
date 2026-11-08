<?php
/**
 * Work queues: waiting lists (workshop contacts) and ready-to-invoice (cashier).
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\Flow;
use VHus\Mailer;
use VHus\Members;
use VHus\Roles;
use VHus\Workshops;

defined( 'ABSPATH' ) || exit;

/**
 * The two lists people work through day to day.
 */
class Queues {

	/** Hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_vhus_decide', array( __CLASS__, 'handle_decide' ) );
		add_action( 'admin_post_vhus_invoiced', array( __CLASS__, 'handle_invoiced' ) );
	}

	/** Submenus, placed after "Medlemmer". */
	public static function menu(): void {
		$cap = current_user_can( Roles::CAP_OWN_WORKSHOP ) ? Roles::CAP_OWN_WORKSHOP : Roles::CAP_VIEW_ALL;
		if ( current_user_can( Roles::CAP_MANAGE_ALL ) ) {
			$cap = Roles::CAP_MANAGE_ALL;
		}
		$waiting = self::waiting_count();
		$label   = 'Ventelister' . ( $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '' );
		add_submenu_page( 'vhus', 'Ventelister', $label, $cap, 'vhus-waiting', array( __CLASS__, 'page_waiting' ), 2 );
		add_submenu_page( 'vhus', 'Klar til fakturering', 'Fakturering', Roles::CAP_INVOICE, 'vhus-invoicing', array( __CLASS__, 'page_invoicing' ), 3 );
	}

	/**
	 * Workshops shown as tabs for the current user.
	 *
	 * @return object[]
	 */
	private static function visible_workshops(): array {
		$visible = Roles::visible_workshop_ids();
		return array_values( array_filter( Workshops::all(), fn( $w ) => null === $visible || in_array( (int) $w->id, $visible, true ) ) );
	}

	/** Number of waiting seats in the user's workshops (menu badge). */
	private static function waiting_count(): int {
		$counts = Workshops::seat_counts();
		$n      = 0;
		foreach ( self::visible_workshops() as $w ) {
			$n += (int) ( $counts[ (int) $w->id ]['waiting'] ?? 0 );
		}
		return $n;
	}

	/** Print the redirect notice. */
	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['vhus_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['vhus_type'] ) && 'error' === $_GET['vhus_type'] ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhus_msg'] ) ) ) ) );
		// phpcs:enable
	}

	/** Redirect with a notice. */
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

	/* ---------------------------------------------------------------------
	 * Waiting lists
	 * ------------------------------------------------------------------ */

	/** One tab per visible workshop; the queue in sign-up order with approve / decline. */
	public static function page_waiting(): void {
		if ( ! Roles::can_access() ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$workshops = self::visible_workshops();
		if ( ! $workshops ) {
			echo '<div class="wrap vhus"><h1>Ventelister</h1><p>Du er ikke sat som kontakt for noget værksted endnu. Bed en administrator om at vælge dig under Værksteder.</p></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : (int) $workshops[0]->id;
		$ws      = null;
		foreach ( $workshops as $w ) {
			if ( (int) $w->id === $current ) {
				$ws = $w;
			}
		}
		$ws         = $ws ?? $workshops[0];
		$can_decide = Roles::can_manage_workshop( (int) $ws->id );
		$counts     = Workshops::seat_counts();
		$members_n  = (int) ( $counts[ (int) $ws->id ]['approved'] ?? 0 );
		$queue      = Flow::waiting_list( (int) $ws->id );
		$full       = null !== $ws->capacity && $members_n >= (int) $ws->capacity;
		?>
		<div class="wrap vhus">
			<h1>Ventelister</h1>
			<?php self::notice(); ?>
			<nav class="nav-tab-wrapper" aria-label="Værksteder">
				<?php foreach ( $workshops as $w ) : ?>
					<?php $n = (int) ( $counts[ (int) $w->id ]['waiting'] ?? 0 ); ?>
					<a class="nav-tab<?php echo (int) $w->id === (int) $ws->id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-waiting&workshop=' . (int) $w->id ) ); ?>"<?php echo (int) $w->id === (int) $ws->id ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $w->name ); ?> <span class="count">(<?php echo (int) $n; ?>)</span>
					</a>
				<?php endforeach; ?>
			</nav>

			<p class="vhus-capacity">
				<?php
				if ( null === $ws->capacity ) {
					printf( '%d medlemmer i %s.', (int) $members_n, esc_html( $ws->name ) );
				} else {
					printf( '%d af %d pladser er optaget i %s.', (int) $members_n, (int) $ws->capacity, esc_html( $ws->name ) );
					if ( $full ) {
						echo ' <strong>Værkstedet er fuldt.</strong>';
					}
				}
				?>
			</p>

			<?php if ( ! $queue ) : ?>
				<p><em>Ingen på ventelisten.</em></p>
			<?php else : ?>
				<table class="widefat striped vhus-queue-table">
					<thead><tr><th class="num">Nr.</th><th>Navn</th><th>Kontakt</th><th>Skrevet op</th><th>Andre værksteder</th><th>Note</th><?php echo $can_decide ? '<th>Beslutning</th>' : ''; ?></tr></thead>
					<tbody>
					<?php foreach ( $queue as $r ) : ?>
						<tr>
							<td class="num"><?php echo (int) $r->position; ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $r->member_id ) ); ?>"><?php echo esc_html( $r->name ); ?></a></td>
							<td>
								<a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a><br>
								<a href="tel:<?php echo esc_attr( $r->phone_e164 ); ?>"><?php echo esc_html( $r->phone_e164 ); ?></a>
							</td>
							<td><?php echo esc_html( mysql2date( 'j. M Y', $r->requested_at ) ); ?></td>
							<td><?php echo esc_html( $r->other_workshops ); ?></td>
							<td><?php echo esc_html( (string) $r->note ); ?></td>
							<?php if ( $can_decide ) : ?>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-decide">
										<input type="hidden" name="action" value="vhus_decide">
										<input type="hidden" name="seat_id" value="<?php echo (int) $r->seat_id; ?>">
										<?php wp_nonce_field( 'vhus_decide_' . (int) $r->seat_id ); ?>
										<button class="button button-primary" name="decision" value="approve"<?php echo $full ? ' onclick="return confirm(\'Værkstedet er fuldt. Godkend alligevel?\')"' : ''; ?>>Godkend</button>
										<details>
											<summary>Afvis</summary>
											<label class="screen-reader-text" for="vhus-msg-<?php echo (int) $r->seat_id; ?>">Besked til ansøgeren</label>
											<textarea id="vhus-msg-<?php echo (int) $r->seat_id; ?>" name="message" rows="3" placeholder="Besked til ansøgeren (valgfri, kommer med i e-mailen)"></textarea>
											<button class="button" name="decision" value="decline">Afvis og send e-mail</button>
										</details>
									</form>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $can_decide ) : ?>
					<p class="description">Godkend sender en e-mail til ansøgeren og sætter dem på kassererens liste til fakturering. Ansøgeren får først adgang til porten, når fakturaen er betalt.</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Approve or decline from the waiting list. */
	public static function handle_decide(): void {
		$seat_id = isset( $_POST['seat_id'] ) ? absint( $_POST['seat_id'] ) : 0;
		check_admin_referer( 'vhus_decide_' . $seat_id );
		$seat = Members::seat( $seat_id );
		if ( ! $seat || ! Roles::can_manage_workshop( (int) $seat->workshop_id ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$back     = admin_url( 'admin.php?page=vhus-waiting&workshop=' . (int) $seat->workshop_id );
		$member   = Members::get( (int) $seat->member_id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';

		if ( 'waiting' !== $seat->status ) {
			self::redirect( $back, 'Ansøgningen er allerede behandlet.', 'error' );
		}
		if ( 'approve' === $decision ) {
			Flow::approve( $seat_id );
			self::redirect( $back, sprintf( '%s er godkendt og sat på listen til fakturering.', $member->name ) );
		}
		if ( 'decline' === $decision ) {
			$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
			Flow::decline( $seat_id, $message );
			self::redirect( $back, sprintf( '%s er afvist, og der er sendt en e-mail.', $member->name ) );
		}
		self::redirect( $back, 'Ukendt handling.', 'error' );
	}

	/* ---------------------------------------------------------------------
	 * Ready to invoice
	 * ------------------------------------------------------------------ */

	/** The cashier's list. */
	public static function page_invoicing(): void {
		if ( ! current_user_can( Roles::CAP_INVOICE ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$pending  = Flow::invoice_list( 'pending' );
		$invoiced = Flow::invoice_list( 'invoiced' );
		$total    = array_sum( array_map( array( Flow::class, 'amount' ), $pending ) );
		?>
		<div class="wrap vhus">
			<h1>Klar til fakturering</h1>
			<?php self::notice(); ?>
			<p>Opret fakturaen i Dinero som altid, og markér den her bagefter. Betaling registreres automatisk, når Dinero-synkroniseringen er sat op.</p>

			<?php if ( ! $pending ) : ?>
				<p><em>Ingen venter på en faktura.</em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vhus_invoiced">
					<input type="hidden" name="do" value="mark">
					<?php wp_nonce_field( 'vhus_invoiced' ); ?>
					<table class="widefat striped">
						<thead><tr>
							<td class="check-column"><input type="checkbox" id="vhus-all" aria-label="Vælg alle" onclick="document.querySelectorAll('.vhus-pid').forEach(c=>c.checked=this.checked)"></td>
							<th>Navn</th><th>E-mail</th><th>Værksted</th><th>År</th><th>Specifikation</th><th class="num">I alt (kr)</th><th>Godkendt</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $pending as $p ) : ?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" class="vhus-pid" name="period_ids[]" value="<?php echo (int) $p->id; ?>" aria-label="<?php echo esc_attr( $p->name ); ?>"></th>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $p->member_id ) ); ?>"><?php echo esc_html( $p->name ); ?></a></td>
								<td><?php echo esc_html( $p->email ); ?></td>
								<td><?php echo esc_html( $p->workshops ); ?></td>
								<td><?php echo esc_html( $p->year_label ); ?></td>
								<td><?php echo esc_html( Flow::specification( $p ) ); ?></td>
								<td class="num"><?php echo esc_html( Mailer::kr( Flow::amount( $p ) ) ); ?></td>
								<td><?php echo esc_html( mysql2date( 'j. M Y', $p->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot><tr><td></td><th colspan="5">I alt</th><th class="num"><?php echo esc_html( Mailer::kr( $total ) ); ?></th><td></td></tr></tfoot>
					</table>
					<?php submit_button( 'Markér valgte som faktureret' ); ?>
				</form>
			<?php endif; ?>

			<?php
			global $wpdb;
			$keys = $wpdb->get_results(
				'SELECT k.*, m.name, m.email FROM ' . \VHus\DB::t( 'keys' ) . ' k JOIN ' . \VHus\DB::t( 'members' ) . ' m ON m.id = k.member_id
				WHERE k.fee_due = 1 AND k.fee_paid_at IS NULL ORDER BY k.issued_at'
			);
			if ( $keys ) :
				?>
				<h2>Nye nøglebrikker, der skal faktureres</h2>
				<p>Erstatningsbrikker koster <?php echo esc_html( Mailer::kr( (int) \VHus\Settings::get( 'fee_key' ) ) ); ?> kr. De forsvinder fra listen, når Dinero viser fakturaen betalt.</p>
				<table class="widefat striped vhus-narrow">
					<thead><tr><th>Navn</th><th>E-mail</th><th>Brik</th><th>Udleveret</th></tr></thead>
					<tbody>
					<?php foreach ( $keys as $k ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $k->member_id ) ); ?>"><?php echo esc_html( $k->name ); ?></a></td>
							<td><?php echo esc_html( $k->email ); ?></td>
							<td><?php echo esc_html( $k->fob_number ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M Y', (string) $k->issued_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Faktureret, venter på betaling</h2>
			<?php if ( ! $invoiced ) : ?>
				<p><em>Ingen.</em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Navn</th><th>Værksted</th><th>År</th><th class="num">Beløb (kr)</th><th>Faktureret</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $invoiced as $p ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $p->member_id ) ); ?>"><?php echo esc_html( $p->name ); ?></a></td>
							<td><?php echo esc_html( $p->workshops ); ?></td>
							<td><?php echo esc_html( $p->year_label ); ?></td>
							<td class="num"><?php echo esc_html( Mailer::kr( Flow::amount( $p ) ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M Y', (string) $p->invoiced_at ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="vhus_invoiced">
									<input type="hidden" name="do" value="undo">
									<input type="hidden" name="period_ids[]" value="<?php echo (int) $p->id; ?>">
									<?php wp_nonce_field( 'vhus_invoiced' ); ?>
									<button class="button-link">Fortryd</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Mark periods invoiced, or undo. */
	public static function handle_invoiced(): void {
		check_admin_referer( 'vhus_invoiced' );
		if ( ! current_user_can( Roles::CAP_INVOICE ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$ids  = isset( $_POST['period_ids'] ) ? array_map( 'absint', (array) $_POST['period_ids'] ) : array();
		$undo = isset( $_POST['do'] ) && 'undo' === $_POST['do'];
		$n    = 0;
		foreach ( $ids as $id ) {
			$n += (int) ( $undo ? Flow::unmark_invoiced( $id ) : Flow::mark_invoiced( $id ) );
		}
		$back = admin_url( 'admin.php?page=vhus-invoicing' );
		if ( ! $n ) {
			self::redirect( $back, 'Ingen ændringer. Vælg mindst én.', 'error' );
		}
		self::redirect( $back, $undo ? 'Fortrudt.' : sprintf( '%d markeret som faktureret.', $n ) );
	}
}
