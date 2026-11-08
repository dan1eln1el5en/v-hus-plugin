<?php
/**
 * Dinero screen: status, "sync now", product mapping, manual contact linking, import check.
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\Dinero_Client;
use VHus\Dinero_Exception;
use VHus\Dinero_Sync;
use VHus\Mailer;
use VHus\Roles;
use VHus\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cashier and administrator screen for the Dinero integration.
 */
class Dinero_Page {

	/** Hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		foreach ( array( 'sync', 'products', 'link', 'unpaid' ) as $a ) {
			add_action( 'admin_post_vhus_dinero_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/** Submenu. */
	public static function menu(): void {
		$state = Dinero_Sync::state();
		$label = 'Dinero' . ( $state['failures'] > 0 ? ' <span class="awaiting-mod">!</span>' : '' );
		add_submenu_page( 'vhus', 'Dinero', $label, Roles::CAP_INVOICE, 'vhus-dinero', array( __CLASS__, 'page' ), 4 );
	}

	/** Back to the page with a notice. */
	private static function back( string $msg, string $type = 'success' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'vhus-dinero',
					'vhus_msg'  => rawurlencode( $msg ),
					'vhus_type' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Capability + nonce for every handler. */
	private static function guard( string $action ): void {
		check_admin_referer( 'vhus_dinero_' . $action );
		if ( ! current_user_can( Roles::CAP_INVOICE ) ) {
			wp_die( 'Du har ikke adgang.', 403 );
		}
	}

	/** Small form with a single button. */
	private static function button_form( string $action, string $label, array $fields = array(), string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vhus-inline">';
		echo '<input type="hidden" name="action" value="vhus_dinero_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'vhus_dinero_' . $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** The page. */
	public static function page(): void {
		if ( ! current_user_can( Roles::CAP_INVOICE ) ) {
			wp_die( 'Du har ikke adgang til denne side.', 403 );
		}
		$state      = Dinero_Sync::state();
		$configured = Dinero_Client::is_configured();
		$next       = wp_next_scheduled( Dinero_Sync::HOOK );
		$client     = new Dinero_Client();
		$api_error  = '';
		$products   = array();
		$contacts   = array();
		$unlinked   = Dinero_Sync::unlinked_members();

		if ( $configured ) {
			try {
				$products = get_transient( 'vhus_dinero_products' );
				if ( ! is_array( $products ) ) {
					$products = $client->get_all( 'products', array( 'fields' => 'ProductGuid,Name,ProductNumber,TotalAmountInclVat' ), 1000 );
					set_transient( 'vhus_dinero_products', $products, 10 * MINUTE_IN_SECONDS );
				}
				if ( $unlinked ) {
					$contacts = Dinero_Sync::contacts( $client, true );
				}
			} catch ( Dinero_Exception $e ) {
				$api_error = $e->getMessage();
			}
		}
		?>
		<div class="wrap vhus">
			<h1>Dinero</h1>
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
			if ( ! empty( $_GET['vhus_msg'] ) ) {
				$type = isset( $_GET['vhus_type'] ) && 'error' === $_GET['vhus_type'] ? 'error' : 'success';
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhus_msg'] ) ) ) ) );
			}
			// phpcs:enable
			?>
			<p>Pluginet læser kun fra Dinero. Fakturaer oprettes og sendes i Dinero som altid; betalingsstatus her kommer fra Dinero og kan ikke sættes med hånden.</p>

			<h2>Status</h2>
			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning inline">
					<p><strong>Dinero er ikke sat op endnu.</strong> En administrator skal tilføje disse linjer i <code>wp-config.php</code> (aldrig i koden eller her på siden):</p>
					<pre class="vhus-pre"><?php foreach ( Dinero_Client::CONSTANTS as $c ) : ?>define( '<?php echo esc_html( $c ); ?>', '<?php echo in_array( $c, Dinero_Client::missing_constants(), true ) ? '…' : 'sat'; ?>' );
<?php endforeach; ?></pre>
					<p>Klient-id og -hemmelighed fås af Dinero, API-nøglen oprettes i V-husets Dinero under Indstillinger → Integrationer, og organisations-id står i Dinero-adressen. Kræver Dinero Pro.</p>
				</div>
			<?php endif; ?>
			<table class="widefat striped vhus-narrow">
				<tbody>
					<tr><th scope="row">Seneste kørsel</th><td><?php echo $state['last_run'] ? esc_html( mysql2date( 'j. M Y H:i', $state['last_run'] ) ) : 'Aldrig'; ?></td></tr>
					<tr><th scope="row">Resultat</th><td><?php echo esc_html( $state['last_result'] ? $state['last_result'] : '—' ); ?></td></tr>
					<?php if ( $state['last_error'] ) : ?>
						<tr><th scope="row">Fejl</th><td class="vhus-error"><?php echo esc_html( $state['last_error'] ); ?> (<?php echo (int) $state['failures']; ?> gang<?php echo 1 === (int) $state['failures'] ? '' : 'e'; ?> i træk)</td></tr>
					<?php endif; ?>
					<tr><th scope="row">Næste automatiske kørsel</th><td><?php echo $next ? esc_html( wp_date( 'j. M Y H:i', $next ) ) : 'Ikke planlagt'; ?></td></tr>
				</tbody>
			</table>
			<p>
				<?php
				if ( $configured ) {
					self::button_form( 'sync', 'Synkronisér nu', array(), 'button button-primary' );
				}
				?>
			</p>
			<?php if ( $api_error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $api_error ); ?></p></div>
			<?php endif; ?>

			<h2>Varer i Dinero</h2>
			<?php self::products_section( $products, $configured ); ?>

			<h2>Medlemmer uden Dinero-kontakt (<?php echo count( $unlinked ); ?>)</h2>
			<p>Synkroniseringen kobler automatisk medlemmer til Dinero-kontakter med samme e-mail. Dem, der står her, skal kobles med hånden, ellers kan deres betaling ikke ses.</p>
			<?php self::unlinked_section( $unlinked, $contacts ); ?>

			<?php if ( $state['last_success'] ) : ?>
				<?php $imports = Dinero_Sync::unconfirmed_imports(); ?>
				<h2>Importerede medlemmer uden betaling i Dinero (<?php echo count( $imports ); ?>)</h2>
				<p>Disse kom med fra Excel-listen og regnes stadig som betalt, fordi Dinero endnu ikke har vist en betalt kontingentfaktura for dette medlemsår. Tjek dem i Dinero; markér kun som ikke betalt, hvis de reelt ikke har betalt.</p>
				<?php if ( $imports ) : ?>
					<table class="widefat striped vhus-narrow">
						<thead><tr><th>Navn</th><th>E-mail</th><th>I Dinero</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $imports as $p ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $p->member_id ) ); ?>"><?php echo esc_html( $p->name ); ?></a></td>
								<td><?php echo esc_html( $p->email ); ?></td>
								<td><?php echo ! $p->dinero_contact_guid ? '<em>Ikke koblet</em>' : ( $p->dinero_invoice_guid ? 'Ubetalt faktura' : 'Ingen kontingentfaktura fundet' ); ?></td>
								<td><?php self::button_form( 'unpaid', 'Markér ikke betalt', array( 'period_id' => (int) $p->id ), 'button-link vhus-danger' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Product mapping form.
	 *
	 * @param array[] $products From Dinero.
	 */
	private static function products_section( array $products, bool $configured ): void {
		$s       = Settings::all();
		$current = array();
		foreach ( array( 'full', 'half', 'key' ) as $kind ) {
			foreach ( (array) $s[ 'dinero_products_' . $kind ] as $g ) {
				$current[ strtolower( $g ) ] = $kind;
			}
		}
		$fallback = sprintf( '%s kr = helt kontingent, %s kr = halvt, %s kr = værkstedsnøgle', Mailer::kr( (int) $s['fee_full'] ), Mailer::kr( (int) $s['fee_half'] ), Mailer::kr( (int) $s['fee_key'] ) );
		if ( ! $current ) {
			echo '<p>Ingen varer er valgt, så fakturalinjer genkendes på beløbet: ' . esc_html( $fallback ) . '. Det er mere sikkert at vælge de varer, kassereren bruger.</p>';
		} else {
			echo '<p>Fakturalinjer genkendes på de valgte varer.</p>';
		}
		if ( ! $configured || ! $products ) {
			if ( $configured ) {
				echo '<p><em>Ingen varer fundet i Dinero.</em></p>';
			}
			return;
		}
		$labels = array(
			''     => '—',
			'full' => 'Kontingent, helt år',
			'half' => 'Kontingent, halvt år',
			'key'  => 'Værkstedsnøgle',
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vhus_dinero_products">
			<?php wp_nonce_field( 'vhus_dinero_products' ); ?>
			<table class="widefat striped vhus-narrow">
				<thead><tr><th>Vare</th><th>Varenr.</th><th class="num">Pris (kr)</th><th>Bruges til</th></tr></thead>
				<tbody>
				<?php
				foreach ( $products as $p ) :
					$g = strtolower( (string) ( $p['ProductGuid'] ?? '' ) );
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $p['Name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $p['ProductNumber'] ?? '' ) ); ?></td>
						<td class="num"><?php echo esc_html( Mailer::kr( (int) round( (float) ( $p['TotalAmountInclVat'] ?? 0 ) ) ) ); ?></td>
						<td>
							<select name="product[<?php echo esc_attr( $g ); ?>]" aria-label="<?php echo esc_attr( 'Bruges til: ' . ( $p['Name'] ?? '' ) ); ?>">
								<?php foreach ( $labels as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $current[ $g ] ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Gem varer' ); ?>
		</form>
		<?php
	}

	/**
	 * Manual linking of members to Dinero contacts.
	 *
	 * @param object[] $unlinked Members.
	 * @param array[]  $contacts Dinero contacts.
	 */
	private static function unlinked_section( array $unlinked, array $contacts ): void {
		if ( ! $unlinked ) {
			echo '<p><em>Alle er koblet.</em></p>';
			return;
		}
		global $wpdb;
		$taken = array_flip( array_filter( $wpdb->get_col( 'SELECT dinero_contact_guid FROM ' . \VHus\DB::t( 'members' ) . " WHERE dinero_contact_guid <> ''" ) ) );
		$free  = array_values( array_filter( $contacts, fn( $c ) => ! isset( $taken[ $c['ContactGuid'] ] ) ) );
		usort( $free, fn( $a, $b ) => strcoll( $a['Name'], $b['Name'] ) );
		?>
		<table class="widefat striped vhus-narrow">
			<thead><tr><th>Medlem</th><th>E-mail i kartoteket</th><th>Kobl til Dinero-kontakt</th></tr></thead>
			<tbody>
			<?php foreach ( $unlinked as $m ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vhus-members&action=edit&id=' . (int) $m->id ) ); ?>"><?php echo esc_html( $m->name ); ?></a></td>
					<td><?php echo esc_html( $m->email ? $m->email : '—' ); ?></td>
					<td>
						<?php if ( $free ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhus-inline">
								<input type="hidden" name="action" value="vhus_dinero_link">
								<input type="hidden" name="member_id" value="<?php echo (int) $m->id; ?>">
								<?php wp_nonce_field( 'vhus_dinero_link' ); ?>
								<select name="contact_guid" aria-label="<?php echo esc_attr( 'Dinero-kontakt for ' . $m->name ); ?>">
									<option value="">— Vælg kontakt —</option>
									<?php foreach ( $free as $c ) : ?>
										<option value="<?php echo esc_attr( $c['ContactGuid'] ); ?>"><?php echo esc_html( $c['Name'] . ( $c['Email'] ? ' (' . $c['Email'] . ')' : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button">Kobl</button>
							</form>
						<?php else : ?>
							<em>Ingen ledige kontakter hentet fra Dinero.</em>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** "Synkronisér nu". */
	public static function handle_sync(): void {
		self::guard( 'sync' );
		$res = Dinero_Sync::run( 'manual' );
		self::back( $res['message'], $res['ok'] ? 'success' : 'error' );
	}

	/** Save product mapping. */
	public static function handle_products(): void {
		self::guard( 'products' );
		$map = isset( $_POST['product'] ) && is_array( $_POST['product'] ) ? wp_unslash( $_POST['product'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below and in Settings::sanitize().
		$new = array(
			'dinero_products_full' => array(),
			'dinero_products_half' => array(),
			'dinero_products_key'  => array(),
		);
		foreach ( $map as $guid => $kind ) {
			$kind = sanitize_key( (string) $kind );
			if ( isset( $new[ 'dinero_products_' . $kind ] ) ) {
				$new[ 'dinero_products_' . $kind ][] = sanitize_text_field( (string) $guid );
			}
		}
		update_option( Settings::OPTION, Settings::sanitize( $new + Settings::all() ) ); // Resets the sync cursor if the mapping changed.
		\VHus\Log::add( 'dinero_products', 'settings', 0, array_map( 'count', $new ) );
		self::back( 'Varer gemt. Næste synkronisering læser fakturalinjerne igen.' );
	}

	/** Link a member to a contact chosen from Dinero's list. */
	public static function handle_link(): void {
		self::guard( 'link' );
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$guid      = isset( $_POST['contact_guid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['contact_guid'] ) ) ) : '';
		$known     = get_transient( 'vhus_dinero_contacts' );
		$valid     = is_array( $known ) && in_array( $guid, array_column( $known, 'ContactGuid' ), true );
		if ( ! $member_id || ! \VHus\Members::get( $member_id ) || ! $valid ) {
			self::back( 'Vælg en kontakt fra listen (listen kan være udløbet; prøv igen).', 'error' );
		}
		Dinero_Sync::set_contact( $member_id, $guid );
		self::back( 'Koblet. Betalinger vises efter næste synkronisering.' );
	}

	/** Mark an imported period unpaid. */
	public static function handle_unpaid(): void {
		self::guard( 'unpaid' );
		$ok = Dinero_Sync::mark_import_unpaid( isset( $_POST['period_id'] ) ? absint( $_POST['period_id'] ) : 0 );
		self::back( $ok ? 'Markeret som ikke betalt.' : 'Ingen ændring.', $ok ? 'success' : 'error' );
	}
}
