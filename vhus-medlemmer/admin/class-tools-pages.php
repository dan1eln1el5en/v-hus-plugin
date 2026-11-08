<?php
/**
 * Persondata (retention), Log (audit trail) and Vejledning (user guides).
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\DB;
use VHus\Gate;
use VHus\Members;
use VHus\Privacy;
use VHus\Roles;
use VHus\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The quieter screens: data protection, the audit log, and the guide.
 */
class Tools_Pages {

	/** Log action => Danish label. Unknown actions are shown as-is. */
	const LOG_LABELS = array(
		'member_create'          => 'Medlem oprettet',
		'member_update'          => 'Medlem ændret',
		'member_view'            => 'Medlem vist',
		'member_deleted'         => 'Medlem slettet',
		'seat_create'            => 'Værkstedsplads oprettet',
		'seat_status'            => 'Værkstedsstatus ændret',
		'signup_existing'        => 'Tilmelding med kendt e-mail',
		'key_issue'              => 'Brik registreret',
		'period_invoiced'        => 'Markeret faktureret',
		'period_pending'         => 'Fakturering fortrudt',
		'period_paid'            => 'Betalt (Dinero)',
		'period_invoiced_dinero' => 'Faktureret (Dinero)',
		'period_unpaid'          => 'Markeret ikke betalt',
		'email_sent'             => 'E-mail sendt',
		'email_failed'           => 'E-mail fejlede',
		'import'                 => 'Import',
		'workshop_update'        => 'Værksted ændret',
		'dinero_sync'            => 'Dinero-synkronisering',
		'dinero_sync_error'      => 'Dinero-fejl',
		'dinero_link'            => 'Koblet til Dinero',
		'dinero_unlink'          => 'Dinero-kobling fjernet',
		'dinero_products'        => 'Dinero-varer ændret',
		'dinero_invoice_deleted' => 'Faktura slettet i Dinero',
		'gate_queue'             => 'Portopgave oprettet',
		'gate_done'              => 'Portopgave udført',
		'gate_reopen'            => 'Portopgave genåbnet',
		'gate_download'          => 'Importfil hentet',
		'easyaccess_match'       => 'Afstemt med EasyAccess',
		'retention_run'          => 'Automatisk sletning',
	);

	/** Hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 14 );
		add_action( 'admin_post_vhus_retention_now', array( __CLASS__, 'handle_retention_now' ) );
		add_action( 'admin_post_vhus_delete_member', array( __CLASS__, 'handle_delete_member' ) );
	}

	/** Submenus. */
	public static function menu(): void {
		add_submenu_page( 'vhus', 'Persondata', 'Persondata', Roles::CAP_ADMIN, 'vhus-privacy', array( __CLASS__, 'page_privacy' ) );
		add_submenu_page( 'vhus', 'Log', 'Log', Roles::CAP_ADMIN, 'vhus-log', array( __CLASS__, 'page_log' ) );
		$cap = 'read';
		foreach ( array( Roles::CAP_MANAGE_ALL, Roles::CAP_VIEW_ALL, Roles::CAP_OWN_WORKSHOP ) as $c ) {
			if ( current_user_can( $c ) ) {
				$cap = $c;
				break;
			}
		}
		if ( Roles::can_access() ) {
			add_submenu_page( 'vhus', 'Vejledning', 'Vejledning', $cap, 'vhus-help', array( __CLASS__, 'page_help' ) );
		}
	}

	/** Notice from a redirect. */
	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['vhus_msg'] ) ) {
			$type = isset( $_GET['vhus_type'] ) && 'error' === $_GET['vhus_type'] ? 'error' : 'success';
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhus_msg'] ) ) ) ) );
		}
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
	 * Persondata
	 * ------------------------------------------------------------------ */

	/** Retention overview. */
	public static function page_privacy(): void {
		if ( ! current_user_can( Roles::CAP_ADMIN ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$s     = Settings::all();
		$soon  = Privacy::candidates( null, 30 );
		$due   = array_filter( $soon, fn( $c ) => $c['due'] );
		$next  = wp_next_scheduled( Gate::DAILY_HOOK );
		?>
		<div class="wrap vhus">
			<h1>Persondata</h1>
			<?php self::notice(); ?>
			<p>
				Afviste ansøgere slettes <?php echo (int) $s['retention_declined']; ?> måneder efter afvisningen, udmeldte medlemmer <?php echo (int) $s['retention_former']; ?> måneder efter udmeldelsen.
				Log og udførte portopgaver gemmes i <?php echo (int) $s['retention_log']; ?> måneder. Oprydningen kører hver dag<?php echo $next ? ' (næste gang ' . esc_html( wp_date( 'j. M H:i', $next ) ) . ')' : ''; ?>. Tiderne ændres under <a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-settings' ) ); ?>">Indstillinger</a>.
			</p>

			<h2>Slettes inden for 30 dage (<?php echo count( $soon ); ?>)</h2>
			<?php if ( ! $soon ) : ?>
				<p><em>Ingen.</em></p>
			<?php else : ?>
				<table class="widefat striped vhus-narrow">
					<thead><tr><th>Navn</th><th>Grund</th><th>Sluttede</th><th>Slettes</th></tr></thead>
					<tbody>
					<?php foreach ( $soon as $c ) : ?>
						<tr<?php echo $c['due'] ? ' class="vhus-old"' : ''; ?>>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $c['member_id'] ) ); ?>"><?php echo esc_html( $c['name'] ); ?></a></td>
							<td><?php echo 'former' === $c['reason'] ? 'Udmeldt' : 'Afvist'; ?></td>
							<td><?php echo esc_html( mysql2date( 'j. M Y', $c['ended'] ) ); ?></td>
							<td><?php echo $c['due'] ? '<strong>Nu</strong>' : esc_html( mysql2date( 'j. M Y', $c['delete_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $due ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-add" onsubmit="return confirm('Slet <?php echo count( $due ); ?> nu? Det kan ikke fortrydes.')">
						<input type="hidden" name="action" value="vhus_retention_now">
						<?php wp_nonce_field( 'vhus_retention_now' ); ?>
						<button class="button">Kør oprydningen nu (<?php echo count( $due ); ?>)</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<h2>Indsigt og sletning på anmodning</h2>
			<p>Beder en person om at se eller få slettet sine oplysninger, så brug WordPress' egne værktøjer:
				<a href="<?php echo esc_url( admin_url( 'export-personal-data.php' ) ); ?>">Eksportér persondata</a> og
				<a href="<?php echo esc_url( admin_url( 'erase-personal-data.php' ) ); ?>">Slet persondata</a>, med personens e-mail.
				Medlemskartoteket er med i begge. Et enkelt medlem kan også slettes nederst på medlemmets side.</p>

			<h2>Tjekliste</h2>
			<ul class="vhus-checklist">
				<li>Databehandleraftale med webhotellet.</li>
				<li>Databehandleraftale med Dinero (Visma).</li>
				<li>Databehandleraftale med Access Technology (EasyAccess).</li>
				<li>Privatlivspolitikken på hjemmesiden nævner medlemskartoteket. Forslag til tekst: <a href="<?php echo esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ); ?>">Indstillinger → Privatliv → Vejledning</a>.</li>
				<li>Tilmeldingsformularen linker til privatlivspolitikken (Indstillinger → Persondata → Link til privatlivspolitik).</li>
			</ul>
		</div>
		<?php
	}

	/** Run retention now. */
	public static function handle_retention_now(): void {
		check_admin_referer( 'vhus_retention_now' );
		if ( ! current_user_can( Roles::CAP_ADMIN ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$r = Privacy::run_retention();
		self::redirect( admin_url( 'admin.php?page=vhus-privacy' ), sprintf( '%d medlemmer slettet.', $r['members'] ) );
	}

	/** Delete one member from their page (right to erasure). */
	public static function handle_delete_member(): void {
		$id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		check_admin_referer( 'vhus_delete_member_' . $id );
		if ( ! current_user_can( Roles::CAP_ADMIN ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
		$m       = Members::get( $id );
		$confirm = isset( $_POST['confirm_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm_name'] ) ) ) : '';
		if ( ! $m || mb_strtolower( $confirm ) !== mb_strtolower( trim( $m->name ) ) ) {
			self::redirect( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . $id ), 'Skriv medlemmets navn præcist for at bekræfte sletningen.', 'error' );
		}
		$in_ea = Gate::in_easyaccess( $id );
		Privacy::delete_member( $id, 'admin' );
		self::redirect(
			admin_url( 'admin.php?page=vhus-members' ),
			$in_ea ? 'Medlemmet er slettet. Der ligger en opgave på Port om at slette dem i EasyAccess.' : 'Medlemmet er slettet.'
		);
	}

	/** Delete box for the member page (administrators only). */
	public static function delete_box( object $m ): void {
		if ( ! current_user_can( Roles::CAP_ADMIN ) ) {
			return;
		}
		?>
		<details class="vhus-delete">
			<summary>Slet medlem</summary>
			<p>Sletter alle oplysninger om <?php echo esc_html( $m->name ); ?> her: stamdata, værksteder, kontingent og brikker. Det kan ikke fortrydes. Er medlemmet i EasyAccess, kommer der en opgave på Port om at slette dem dér. Fakturaer i Dinero berøres ikke.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-inline">
				<input type="hidden" name="action" value="vhus_delete_member">
				<input type="hidden" name="member_id" value="<?php echo (int) $m->id; ?>">
				<?php wp_nonce_field( 'vhus_delete_member_' . (int) $m->id ); ?>
				<label for="vhus-confirm-name">Skriv navnet for at bekræfte:</label>
				<input id="vhus-confirm-name" type="text" name="confirm_name" autocomplete="off" required>
				<button class="button vhus-button-danger">Slet for altid</button>
			</form>
		</details>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------ */

	/** Audit log with filters. */
	public static function page_log(): void {
		global $wpdb;
		if ( ! current_user_can( Roles::CAP_ADMIN ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$action = isset( $_GET['log_action'] ) ? sanitize_key( $_GET['log_action'] ) : '';
		$user   = isset( $_GET['log_user'] ) ? absint( $_GET['log_user'] ) : 0;
		$member = isset( $_GET['log_member'] ) ? absint( $_GET['log_member'] ) : 0;
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$per   = 50;
		$where = array( '1=1' );
		$args  = array();
		if ( $action ) {
			$where[] = 'action = %s';
			$args[]  = $action;
		}
		if ( $user ) {
			$where[] = 'user_id = %d';
			$args[]  = $user;
		}
		if ( $member ) {
			$where[] = "object_type = 'member' AND object_id = %d";
			$args[]  = $member;
		}
		$sql_where = implode( ' AND ', $where );
		$t         = DB::t( 'log' );
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql_where", $args ) : "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql_where holds only placeholders.
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actions   = $wpdb->get_col( "SELECT DISTINCT action FROM $t ORDER BY action" );
		$users     = $wpdb->get_col( "SELECT DISTINCT user_id FROM $t WHERE user_id > 0" );
		$pages     = max( 1, (int) ceil( $total / $per ) );
		?>
		<div class="wrap vhus">
			<h1>Log</h1>
			<p>Hvem har set eller ændret hvad. Loggen indeholder kun id'er og feltnavne, ikke selve oplysningerne.</p>
			<form method="get" class="vhus-inline">
				<input type="hidden" name="page" value="vhus-log">
				<select name="log_action" aria-label="Handling">
					<option value="">Alle handlinger</option>
					<?php foreach ( $actions as $a ) : ?>
						<option value="<?php echo esc_attr( $a ); ?>"<?php selected( $action, $a ); ?>><?php echo esc_html( self::LOG_LABELS[ $a ] ?? $a ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="log_user" aria-label="Bruger">
					<option value="">Alle brugere</option>
					<?php foreach ( $users as $uid ) : ?>
						<?php $u = get_userdata( (int) $uid ); ?>
						<option value="<?php echo (int) $uid; ?>"<?php selected( $user, (int) $uid ); ?>><?php echo esc_html( $u ? $u->display_name : '#' . $uid ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" min="1" name="log_member" value="<?php echo $member ? (int) $member : ''; ?>" placeholder="Medlemsnr." class="small-text" aria-label="Medlemsnummer">
				<button class="button">Filtrér</button>
			</form>
			<table class="widefat striped vhus-add">
				<thead><tr><th>Tid</th><th>Bruger</th><th>Handling</th><th>Hvad</th><th>Detaljer</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><em>Ingen poster.</em></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$u    = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
					$what = '';
					if ( 'member' === $r->object_type && $r->object_id ) {
						$m    = Members::get( (int) $r->object_id );
						$what = $m
							? '<a href="' . esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $r->object_id ) ) . '">' . esc_html( $m->name ) . '</a>'
							: 'Medlem #' . (int) $r->object_id . ' (slettet)';
					} elseif ( $r->object_type ) {
						$what = esc_html( $r->object_type . ( $r->object_id ? ' #' . $r->object_id : '' ) );
					}
					?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'j. M Y H:i', $r->created_at ) ); ?></td>
						<td><?php echo esc_html( $u ? $u->display_name : 'system' ); ?></td>
						<td><?php echo esc_html( self::LOG_LABELS[ $r->action ] ?? $r->action ); ?></td>
						<td><?php echo $what; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></td>
						<td><code class="vhus-details"><?php echo esc_html( (string) $r->details ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<p class="vhus-pager">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Vejledning
	 * ------------------------------------------------------------------ */

	/** Short guide per role; the reader's own roles are open, the rest folded. */
	public static function page_help(): void {
		if ( ! Roles::can_access() ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$sections = array(
			array(
				'title' => 'Værkstedskontakt',
				'mine'  => current_user_can( Roles::CAP_OWN_WORKSHOP ) && ! current_user_can( Roles::CAP_MANAGE_ALL ),
				'steps' => array(
					'Du får en e-mail, når nogen skriver sig op til dit værksted. Linket fører til <strong>Ventelister</strong>.',
					'Listen står i den rækkefølge, folk skrev sig op. Ring eller skriv til den næste, når der er en ledig plads.',
					'Tryk <strong>Godkend</strong>, når personen skal være medlem. De får en e-mail, og kassereren får besked om at sende en faktura. Porten åbner først for dem, når fakturaen er betalt.',
					'Tryk <strong>Afvis</strong>, hvis der ikke er plads til dem. Du kan skrive en kort besked, som kommer med i e-mailen.',
					'Melder et medlem sig ud, så åbn medlemmet under <strong>Medlemmer</strong> og sæt værkstedet til <em>Udmeldt</em>. Portadministratoren får en opgave om at fjerne adgangen.',
					'Du kan kun se dit eget værksted og kun om kontingentet er betalt, ikke beløb.',
				),
			),
			array(
				'title' => 'Kasserer',
				'mine'  => current_user_can( Roles::CAP_INVOICE ),
				'steps' => array(
					'Nye medlemmer kommer på <strong>Fakturering</strong>, når de er godkendt, med beløbet udregnet: helt eller halvt kontingent og nøglebrik for nye medlemmer.',
					'Opret og send fakturaen i Dinero som altid. Brug de samme varer hver gang (kontingent, halvt kontingent, nøglebrik).',
					'Markér personen som faktureret på listen bagefter. Kan fortrydes.',
					'Betaling kommer automatisk fra Dinero hver nat, eller når du trykker <strong>Synkronisér nu</strong> under <strong>Dinero</strong>. Betalt kan ikke sættes med hånden.',
					'Under <strong>Dinero</strong> vælger du én gang, hvilke Dinero-varer der er kontingent og nøglebrik, og kobler medlemmer, hvis e-mail ikke passer med Dinero.',
					'I april fakturerer du fornyelserne i Dinero som altid. Når de bliver betalt, får portadministratoren en opgave om at give adgang til næste år.',
				),
			),
			array(
				'title' => 'Portadministrator',
				'mine'  => current_user_can( Roles::CAP_GATE ),
				'steps' => array(
					'Alt, der skal gøres i EasyAccess, står under <strong>Port</strong>. Tallet i menuen er antallet af åbne opgaver.',
					'<strong>Nye brugere:</strong> Download importfilen, importér den i EasyAccess, og tryk <em>Markér alle som oprettet</em>.',
					'<strong>Tilføj til gruppe, Ret bruger, Slet bruger:</strong> Gør det i EasyAccess, og tryk <em>Udført</em>. Sletning kan ikke fortrydes i EasyAccess.',
					'Hver marts kommer en e-mail om at oprette næste års brugergruppe med navn og datoer. Adgang gives kun gennem gruppen, så den, der ikke betaler, mister adgangen af sig selv, når gruppen udløber.',
					'Første gang: eksportér brugerne fra EasyAccess som XML, og upload filen under <strong>Afstem med EasyAccess</strong>. Skriv medlemsnummeret ind i ID-feltet for dem, der står på listen.',
					'Ny brik: registrér den på medlemmets side. Opgaven om at skifte brik kommer straks, og kassereren ser, at der skal opkræves for brikken.',
				),
			),
			array(
				'title' => 'Administrator',
				'mine'  => current_user_can( Roles::CAP_ADMIN ),
				'steps' => array(
					'Giv folk en rolle under <em>Brugere</em> i WordPress: Værkstedskontakt, Kasserer eller Portadministrator. Én person kan have flere roller.',
					'Vælg kontakten for hvert værksted under <strong>Værksteder</strong>.',
					'Beløb, datoer, gruppenavn, sletning og e-mailtekster ligger under <strong>Indstillinger</strong>.',
					'Sæt tilmeldingsformularen ind på en side med kortkoden <code>[vhus_tilmelding]</code>.',
					'Dinero-nøglerne skal stå i <code>wp-config.php</code>; se <strong>Dinero</strong> for præcis hvilke.',
					'<strong>Persondata</strong> viser, hvem der snart slettes automatisk. <strong>Log</strong> viser, hvem der har set eller ændret hvad.',
				),
			),
		);
		usort( $sections, fn( $a, $b ) => (int) $b['mine'] - (int) $a['mine'] );
		?>
		<div class="wrap vhus vhus-help">
			<h1>Vejledning</h1>
			<p>Medlemskartoteket samler venteliste, medlemmer, kontingent og adgang til porten. Herunder står, hvad hver rolle gør. Dine egne roller er foldet ud.</p>
			<?php foreach ( $sections as $sec ) : ?>
				<details<?php echo $sec['mine'] ? ' open' : ''; ?>>
					<summary><h2><?php echo esc_html( $sec['title'] ); ?></h2></summary>
					<ol>
						<?php foreach ( $sec['steps'] as $step ) : ?>
							<li><?php echo wp_kses( $step, array( 'strong' => array(), 'em' => array(), 'code' => array() ) ); ?></li>
						<?php endforeach; ?>
					</ol>
				</details>
			<?php endforeach; ?>
			<h2>Sådan hænger det sammen</h2>
			<ol>
				<li>Tilmelding på hjemmesiden → venteliste for hvert værksted.</li>
				<li>Værkstedskontakten godkender → kassereren fakturerer i Dinero.</li>
				<li>Dinero viser fakturaen betalt → portadministratoren giver adgang i EasyAccess.</li>
				<li>Hvert år i april: fornyelse i Dinero → betalt → tilføjes til næste års gruppe.</li>
				<li>Udmeldelse → adgangen fjernes → oplysningerne slettes efter <?php echo (int) Settings::get( 'retention_former' ); ?> måneder.</li>
			</ol>
		</div>
		<?php
	}
}
