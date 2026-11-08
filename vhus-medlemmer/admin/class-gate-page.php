<?php
/**
 * Gate admin screen: the EasyAccess to-do list, import file and export matching.
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\EasyAccess;
use VHus\Gate;
use VHus\Roles;
use VHus\Util;
use VHus\Xlsx_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * "Port" screen for users with vhus_gate.
 */
class Gate_Page {

	/** Hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		foreach ( array( 'done', 'reopen', 'download', 'xml', 'xml_link' ) as $a ) {
			add_action( 'admin_post_vhus_gate_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/** Submenu with open-count badge. */
	public static function menu(): void {
		$n     = current_user_can( Roles::CAP_GATE ) ? Gate::open_count() : 0;
		$label = 'Port' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
		add_submenu_page( 'vhus', 'Port (EasyAccess)', $label, Roles::CAP_GATE, 'vhus-gate', array( __CLASS__, 'page' ), 5 );
	}

	/** Redirect back with a notice. */
	private static function back( string $msg, string $type = 'success' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'vhus-gate',
					'vhus_msg'  => rawurlencode( $msg ),
					'vhus_type' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Nonce + capability. */
	private static function guard( string $action ): void {
		check_admin_referer( 'vhus_gate_' . $action );
		if ( ! current_user_can( Roles::CAP_GATE ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
	}

	/** Opening tag of a form posting to admin-post. */
	private static function form_open( string $action, string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra is a literal from this class.
		echo '<input type="hidden" name="action" value="vhus_gate_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'vhus_gate_' . $action );
	}

	/** What the gate admin should do, in words. */
	private static function describe( object $a ): string {
		$d = $a->data;
		switch ( $a->action ) {
			case 'add':
				return 'Med i importfilen (gruppe ' . ( $d['group'] ?? '' ) . ')';
			case 'add_to_group':
				return 'Tilføj til gruppen ' . ( $d['group'] ?? '' );
			case 'update':
				return sprintf( 'Skift telefonnummer fra %s til %s', '' !== ( $d['old'] ?? '' ) ? $d['old'] : '(intet)', $d['new'] ?? '' );
			case 'remove':
				return 'Slet brugeren (telefon ' . ( $d['phone'] ?? '' ) . '). Kan ikke fortrydes i EasyAccess.' . ( ! empty( $d['note'] ) ? ' ' . $d['note'] : '' );
		}
		return '';
	}

	/** The page. */
	public static function page(): void {
		if ( ! current_user_can( Roles::CAP_GATE ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$open   = Gate::actions( true );
		$adds   = array_values( array_filter( $open, fn( $a ) => 'add' === $a->action ) );
		$others = array_values( array_filter( $open, fn( $a ) => 'add' !== $a->action ) );
		$done   = Gate::actions( false, 30 );
		$cur    = Gate::current_group();
		$next   = Gate::next_group();
		$month  = (int) current_datetime()->format( 'n' );
		$match  = get_transient( 'vhus_ea_match_' . get_current_user_id() );
		$week   = time() - 7 * DAY_IN_SECONDS;
		?>
		<div class="wrap vhus">
			<h1>Port (EasyAccess)</h1>
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
			if ( ! empty( $_GET['vhus_msg'] ) ) {
				$type = isset( $_GET['vhus_type'] ) && 'error' === $_GET['vhus_type'] ? 'error' : 'success';
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhus_msg'] ) ) ) ) );
			}
			// phpcs:enable
			?>
			<p>
				Adgang gives kun gennem årets brugergruppe. Gruppen nu: <strong><?php echo esc_html( $cur['name'] ); ?></strong>
				(<?php echo esc_html( $cur['start'] . ' – ' . $cur['end'] ); ?>).
				<?php if ( $month >= 2 && $month <= 5 ) : ?>
					Næste år: <strong><?php echo esc_html( $next['name'] ); ?></strong> (<?php echo esc_html( $next['start'] . ' – ' . $next['end'] ); ?>) skal være oprettet i EasyAccess, før fornyelserne kommer.
				<?php endif; ?>
			</p>

			<h2>Nye brugere (<?php echo count( $adds ); ?>)</h2>
			<?php if ( ! $adds ) : ?>
				<p><em>Ingen nye brugere at oprette.</em></p>
			<?php else : ?>
				<p>Download importfilen, importér den i EasyAccess (Brugere → Importér), og markér dem som udført bagefter.</p>
				<table class="widefat striped">
					<thead><tr><th>Navn</th><th>ID</th><th>Telefon</th><th>Gruppe</th><th>Siden</th></tr></thead>
					<tbody>
					<?php foreach ( $adds as $a ) : ?>
						<?php $m = \VHus\Members::get( (int) $a->member_id ); ?>
						<tr<?php echo (int) mysql2date( 'U', $a->created_at ) < $week ? ' class="vhus-old"' : ''; ?>>
							<td><?php echo esc_html( $a->name ); ?></td>
							<td><code><?php echo (int) $a->member_id; ?></code></td>
							<td><?php echo esc_html( $m ? $m->phone_e164 : '' ); ?></td>
							<td><?php echo esc_html( (string) ( $a->data['group'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M', $a->created_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="vhus-inline vhus-add">
					<?php self::form_open( 'download' ); ?>
						<button class="button button-primary">Download importfil (.xlsx)</button>
					</form>
					<?php self::form_open( 'done', ' onsubmit="return confirm(\'Er alle i filen importeret i EasyAccess?\')"' ); ?>
						<?php foreach ( $adds as $a ) : ?>
							<input type="hidden" name="ids[]" value="<?php echo (int) $a->id; ?>">
						<?php endforeach; ?>
						<button class="button">Markér alle <?php echo count( $adds ); ?> som oprettet</button>
					</form>
				</div>
			<?php endif; ?>

			<h2>Andre opgaver (<?php echo count( $others ); ?>)</h2>
			<?php if ( ! $others ) : ?>
				<p><em>Ingen.</em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Opgave</th><th>Medlem</th><th>ID</th><th>Hvad skal gøres</th><th>Siden</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $others as $a ) : ?>
						<tr<?php echo (int) mysql2date( 'U', $a->created_at ) < $week ? ' class="vhus-old"' : ''; ?>>
							<td><strong><?php echo esc_html( Gate::ACTIONS[ $a->action ] ); ?></strong></td>
							<td><?php echo esc_html( $a->name ); ?></td>
							<td><code><?php echo (int) $a->member_id; ?></code></td>
							<td><?php echo esc_html( self::describe( $a ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M', $a->created_at ) ); ?></td>
							<td>
								<?php self::form_open( 'done' ); ?>
									<input type="hidden" name="ids[]" value="<?php echo (int) $a->id; ?>">
									<button class="button">Udført</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description">Opgaver over en uge gamle er markeret og bliver sendt på e-mail til portadministratoren én gang om ugen.</p>

			<h2>Udført de sidste 30 dage</h2>
			<?php if ( ! $done ) : ?>
				<p><em>Ingen.</em></p>
			<?php else : ?>
				<table class="widefat striped vhus-narrow">
					<thead><tr><th>Opgave</th><th>Medlem</th><th>Udført</th><th>Af</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $done as $a ) : ?>
						<?php $u = $a->done_by ? get_userdata( (int) $a->done_by ) : null; ?>
						<tr>
							<td><?php echo esc_html( Gate::ACTIONS[ $a->action ] ); ?></td>
							<td><?php echo esc_html( $a->name ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M H:i', $a->done_at ) ); ?></td>
							<td><?php echo esc_html( $u ? $u->display_name : '' ); ?></td>
							<td>
								<?php self::form_open( 'reopen' ); ?>
									<input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">
									<button class="button-link">Fortryd</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Afstem med EasyAccess</h2>
			<p>Eksportér brugerne fra EasyAccess som XML og upload filen her. Brugerne matches på telefonnummer. Skriv derefter medlemsnummeret ind i ID-feltet i EasyAccess for dem, der mangler det; det skal kun gøres én gang.</p>
			<?php self::form_open( 'xml', ' enctype="multipart/form-data" class="vhus-inline"' ); ?>
				<input type="file" name="file" accept=".xml" required>
				<button class="button">Sammenlign</button>
			</form>
			<?php
			if ( is_array( $match ) ) {
				self::match_results( $match );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Results of an XML comparison.
	 *
	 * @param array $r Output of EasyAccess::match() plus filename.
	 */
	private static function match_results( array $r ): void {
		$need_id = array_filter( $r['matched'], fn( $u ) => ! $u['id_ok'] );
		?>
		<h3>Resultat for <?php echo esc_html( $r['filename'] ); ?></h3>
		<ul class="vhus-summary">
			<li><strong><?php echo count( $r['matched'] ); ?></strong> <?php echo 1 === count( $r['matched'] ) ? 'bruger matcher' : 'brugere matcher'; ?> et medlem, heraf <strong><?php echo count( $need_id ); ?></strong> der mangler medlemsnummeret i ID-feltet</li>
			<li><strong><?php echo count( $r['unknown'] ); ?></strong> <?php echo 1 === count( $r['unknown'] ) ? 'bruger i EasyAccess, der ikke er medlem' : 'brugere i EasyAccess, der ikke er medlemmer'; ?> her</li>
			<li><strong><?php echo count( $r['missing'] ); ?></strong> <?php echo 1 === count( $r['missing'] ) ? 'godkendt medlem, der ikke findes' : 'godkendte medlemmer, der ikke findes'; ?> i EasyAccess</li>
		</ul>
		<?php if ( $need_id ) : ?>
			<h4>Skriv ID i EasyAccess</h4>
			<table class="widefat striped vhus-narrow">
				<thead><tr><th>Navn i EasyAccess</th><th>Telefon</th><th>ID nu</th><th>Skriv ID</th></tr></thead>
				<tbody>
				<?php foreach ( $need_id as $u ) : ?>
					<tr><td><?php echo esc_html( $u['name'] ? $u['name'] : $u['member_name'] ); ?></td><td><?php echo esc_html( $u['phone'] ); ?></td><td><?php echo esc_html( '' !== $u['id'] ? $u['id'] : '—' ); ?></td><td><code><?php echo (int) $u['member_id']; ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $r['unknown'] ) : ?>
			<h4>I EasyAccess, men ikke medlem her</h4>
			<p>Kan være bestyrelse, håndværkere eller tidligere medlemmer. Tjek om de skal slettes i EasyAccess, eller om telefonnummeret er forkert her.</p>
			<table class="widefat striped vhus-narrow">
				<thead><tr><th>Navn</th><th>Telefon</th><th>ID</th></tr></thead>
				<tbody>
				<?php foreach ( $r['unknown'] as $u ) : ?>
					<tr><td><?php echo esc_html( $u['name'] ); ?></td><td><?php echo esc_html( $u['phone'] ); ?></td><td><?php echo esc_html( $u['id'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $r['missing'] ) : ?>
			<h4>Godkendte medlemmer, der ikke er i EasyAccess</h4>
			<table class="widefat striped vhus-narrow">
				<thead><tr><th>Navn</th><th>Telefon her</th><th>ID</th></tr></thead>
				<tbody>
				<?php foreach ( $r['missing'] as $m ) : ?>
					<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $m->id ) ); ?>"><?php echo esc_html( $m->name ); ?></a></td><td><?php echo esc_html( $m->phone_e164 ); ?></td><td><code><?php echo (int) $m->id; ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $r['matched'] ) : ?>
			<?php self::form_open( 'xml_link', ' class="vhus-add"' ); ?>
				<button class="button button-primary">Gem: de <?php echo count( $r['matched'] ); ?> matchede er oprettet i EasyAccess</button>
			</form>
		<?php endif; ?>
		<?php
	}

	/** Mark actions done. */
	public static function handle_done(): void {
		self::guard( 'done' );
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += (int) Gate::mark_done( $id );
		}
		self::back( sprintf( '%d markeret som udført.', $n ) );
	}

	/** Undo done. */
	public static function handle_reopen(): void {
		self::guard( 'reopen' );
		Gate::reopen( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::back( 'Opgaven er åben igen.' );
	}

	/** Send the import file for all open "add" actions. */
	public static function handle_download(): void {
		self::guard( 'download' );
		$data = Gate::import_rows();
		if ( ! $data['rows'] ) {
			self::back( 'Ingen nye brugere at eksportere.', 'error' );
		}
		$bytes = Xlsx_Writer::build( Gate::TEMPLATE_COLUMNS, $data['rows'], 'Brugere' );
		\VHus\Log::add( 'gate_download', 'gate', 0, array( 'actions' => $data['action_ids'] ) );
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="easyaccess-nye-brugere-' . wp_date( 'Y-m-d' ) . '.xlsx"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
		exit;
	}

	/** Compare an EasyAccess XML export. */
	public static function handle_xml(): void {
		self::guard( 'xml' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- file upload, validated below.
		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( 'Filen blev ikke modtaget.', 'error' );
		}
		$name = sanitize_file_name( $file['name'] );
		$xml  = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// phpcs:enable
		try {
			$users = EasyAccess::parse( $xml );
		} catch ( \RuntimeException $e ) {
			self::back( $e->getMessage(), 'error' );
		}
		if ( ! $users ) {
			self::back( 'Fandt ingen brugere med telefonnummer i filen. Send gerne filen til den, der vedligeholder pluginet.', 'error' );
		}
		$res = EasyAccess::match( $users ) + array( 'filename' => $name );
		// Only what the screen needs; the raw export is not kept.
		foreach ( array( 'matched', 'unknown' ) as $k ) {
			$res[ $k ] = array_map( fn( $u ) => array_diff_key( $u, array( 'raw' => 1 ) ), $res[ $k ] );
		}
		set_transient( 'vhus_ea_match_' . get_current_user_id(), $res, HOUR_IN_SECONDS );
		self::back( sprintf( '%d brugere læst fra filen.', count( $users ) ) );
	}

	/** Confirm the comparison: matched members are in EasyAccess. */
	public static function handle_xml_link(): void {
		self::guard( 'xml_link' );
		$key = 'vhus_ea_match_' . get_current_user_id();
		$res = get_transient( $key );
		if ( ! is_array( $res ) ) {
			self::back( 'Sammenligningen er udløbet. Upload filen igen.', 'error' );
		}
		$n = EasyAccess::link( $res['matched'] );
		self::back( sprintf( '%d medlemmer er markeret som oprettet i EasyAccess.', $n ) );
	}
}
