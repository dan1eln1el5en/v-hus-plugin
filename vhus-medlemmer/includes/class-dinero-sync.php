<?php
/**
 * Nightly read-only sync from Dinero: links contacts and derives paid status.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Rules (from the spec):
 * - The plugin never writes to Dinero. Paid status in WordPress comes only from here.
 * - A failed sync changes nothing. Two failures in a row e-mail the administrator.
 * - The sync only moves periods forward (pending → invoiced → paid). A paid period is never
 *   downgraded, and an imported period only changes when Dinero shows it paid.
 * - When a period becomes paid, the action `vhus_period_paid` fires (the gate queue hooks in there).
 */
class Dinero_Sync {

	const HOOK  = 'vhus_dinero_sync';
	const STATE = 'vhus_dinero_state';
	const SEEN  = 'vhus_dinero_seen';
	const LOCK  = 'vhus_dinero_lock';

	/** Hooks. */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	/** Settings that decide how invoice lines are read. */
	const MATCH_SETTINGS = array( 'fee_full', 'fee_half', 'fee_key', 'year_start', 'renewal_from', 'dinero_products_full', 'dinero_products_half', 'dinero_products_key' );

	/**
	 * When fees or product mapping change, read invoices again from the start of the year,
	 * so invoices that didn't match before are checked with the new rules.
	 *
	 * @param mixed $old Previous settings.
	 * @param mixed $new New settings.
	 */
	public static function settings_changed( $old, $new ): void {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		foreach ( self::MATCH_SETTINGS as $k ) {
			if ( ( $old[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) {
				self::reset_cursor();
				return;
			}
		}
	}

	/** Forget what has been read; the next run starts from initial_since(). Paid periods stay paid. */
	public static function reset_cursor(): void {
		delete_option( self::SEEN );
		$state                 = self::state();
		$state['last_success'] = '';
		self::save_state( $state );
	}

	/** Schedule the nightly run at 03:00 site time, if not already scheduled. */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$next = new \DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	/** Remove the schedule (plugin deactivation). */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Cron callback. */
	public static function cron(): void {
		if ( Dinero_Client::is_configured() ) {
			self::run( 'cron' );
		}
	}

	/** Stored state with defaults. */
	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return ( is_array( $s ) ? $s : array() ) + array(
			'last_success' => '',  // UTC 'Y-m-d\TH:i:s\Z' of the start of the last complete run; next changesSince.
			'last_run'     => '',  // Site time of the last attempt.
			'last_result'  => '',  // Human summary of the last attempt.
			'last_error'   => '',
			'failures'     => 0,
			'complete'     => true,
		);
	}

	/** Save state. */
	private static function save_state( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Run one sync.
	 *
	 * @param string             $trigger cron|manual (for the log).
	 * @param Dinero_Client|null $client  Injected in tests.
	 * @return array{ok:bool, message:string}
	 */
	public static function run( string $trigger = 'manual', ?Dinero_Client $client = null ): array {
		if ( get_transient( self::LOCK ) ) {
			return array(
				'ok'      => false,
				'message' => 'En synkronisering kører allerede. Prøv igen om et par minutter.',
			);
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$client  = $client ?? new Dinero_Client();
		$state   = self::state();
		$started = gmdate( 'Y-m-d\TH:i:s\Z' );
		$state['last_run'] = Util::now();

		try {
			$linked = self::link_contacts( $client );
			$since  = '' !== $state['last_success'] ? $state['last_success'] : self::initial_since();
			$res    = self::sync_invoices( $client, $since );

			$state['failures']   = 0;
			$state['last_error'] = '';
			$state['complete']   = $res['complete'];
			if ( $res['complete'] ) {
				$state['last_success'] = $started;
			}
			$state['last_result'] = sprintf(
				'%d kontakter koblet, %d fakturaer gennemgået, %d nye betalinger%s.',
				$linked,
				$res['checked'],
				$res['paid'],
				$res['complete'] ? '' : ' (ikke færdig: resten tages ved næste kørsel)'
			);
			Log::add( 'dinero_sync', 'dinero', 0, array( 'trigger' => $trigger, 'linked' => $linked ) + $res + array( 'requests' => $client->requests ) );
			$out = array(
				'ok'      => true,
				'message' => $state['last_result'],
			);
		} catch ( Dinero_Exception $e ) {
			if ( $e->rate_limited ) {
				// Not a failure: stop and continue from the same point next run.
				$state['complete']    = false;
				$state['last_result'] = $e->getMessage();
			} else {
				++$state['failures'];
				$state['last_error'] = $e->getMessage();
				if ( 2 === (int) $state['failures'] ) {
					self::notify_failure( $e->getMessage() );
				}
			}
			Log::add( 'dinero_sync_error', 'dinero', 0, array( 'trigger' => $trigger, 'error' => $e->getMessage(), 'rate_limited' => $e->rate_limited ) );
			$out = array(
				'ok'      => $e->rate_limited,
				'message' => $e->getMessage(),
			);
		} finally {
			self::save_state( $state );
			delete_transient( self::LOCK );
		}
		return $out;
	}

	/** First run reads invoices changed since three months before the current membership year began. */
	public static function initial_since(): string {
		$start = Util::membership_start_year();
		$date  = new \DateTimeImmutable( $start . '-' . Settings::get( 'year_start' ) . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		return $date->modify( '-3 months' )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/** E-mail the administrator after the second failure in a row. */
	private static function notify_failure( string $error ): void {
		wp_mail(
			Mailer::admin_email(),
			'V-huset: Dinero-synkroniseringen fejler',
			"Synkroniseringen med Dinero er fejlet to gange i træk. Betalingsstatus i medlemskartoteket bliver ikke opdateret, før det er rettet.\n\nFejl: {$error}\n\nSe status: " . admin_url( 'admin.php?page=vhus-dinero' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Contacts
	 * ------------------------------------------------------------------ */

	/**
	 * Members that should be linked but aren't: anyone with a waiting or approved seat or an open period.
	 *
	 * @return object[]
	 */
	public static function unlinked_members(): array {
		global $wpdb;
		$m = DB::t( 'members' );
		return $wpdb->get_results(
			"SELECT DISTINCT m.id, m.name, m.email FROM $m m
			WHERE ( m.dinero_contact_guid IS NULL OR m.dinero_contact_guid = '' )
			AND ( EXISTS ( SELECT 1 FROM " . DB::t( 'seats' ) . " s WHERE s.member_id = m.id AND s.status = 'approved' )
			   OR EXISTS ( SELECT 1 FROM " . DB::t( 'periods' ) . " p WHERE p.member_id = m.id AND p.status IN ('pending','invoiced','imported') ) )
			ORDER BY m.name"
		);
	}

	/**
	 * Link unlinked members to Dinero contacts with the same e-mail (case-insensitive).
	 * Reads the contact list only when someone is unlinked.
	 *
	 * @return int Number of members linked.
	 */
	public static function link_contacts( Dinero_Client $client ): int {
		global $wpdb;
		$unlinked = self::unlinked_members();
		if ( ! $unlinked ) {
			return 0;
		}
		$by_email = array();
		foreach ( self::contacts( $client ) as $c ) {
			$email = strtolower( trim( (string) ( $c['Email'] ?? '' ) ) );
			if ( '' !== $email && ! isset( $by_email[ $email ] ) ) {
				$by_email[ $email ] = (string) $c['ContactGuid'];
			}
		}
		$taken = array_flip( array_filter( $wpdb->get_col( 'SELECT dinero_contact_guid FROM ' . DB::t( 'members' ) . " WHERE dinero_contact_guid <> ''" ) ) );
		$n     = 0;
		foreach ( $unlinked as $m ) {
			$guid = $by_email[ strtolower( (string) $m->email ) ] ?? '';
			if ( '' === $guid || isset( $taken[ $guid ] ) ) {
				continue;
			}
			self::set_contact( (int) $m->id, $guid );
			$taken[ $guid ] = true;
			++$n;
		}
		return $n;
	}

	/**
	 * Dinero contacts (guid, name, e-mail), cached for 10 minutes for the manual-link dropdown.
	 *
	 * @return array[]
	 */
	public static function contacts( Dinero_Client $client, bool $use_cache = false ): array {
		if ( $use_cache ) {
			$cached = get_transient( 'vhus_dinero_contacts' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$list = array_map(
			fn( $c ) => array(
				'ContactGuid' => strtolower( (string) ( $c['ContactGuid'] ?? '' ) ),
				'Name'        => (string) ( $c['Name'] ?? '' ),
				'Email'       => (string) ( $c['Email'] ?? '' ),
			),
			$client->get_all( 'contacts', array( 'fields' => 'Name,ContactGuid,Email' ), 1000 )
		);
		set_transient( 'vhus_dinero_contacts', $list, 10 * MINUTE_IN_SECONDS );
		return $list;
	}

	/** Store (or clear, with '') a member's Dinero contact. */
	public static function set_contact( int $member_id, string $guid ): void {
		global $wpdb;
		$wpdb->update( DB::t( 'members' ), array( 'dinero_contact_guid' => '' === $guid ? null : strtolower( $guid ) ), array( 'id' => $member_id ) );
		Log::add( '' === $guid ? 'dinero_unlink' : 'dinero_link', 'member', $member_id );
	}

	/* ---------------------------------------------------------------------
	 * Invoices
	 * ------------------------------------------------------------------ */

	/**
	 * Read invoices changed since $since and apply them to linked members' periods.
	 *
	 * @return array{checked:int, paid:int, complete:bool}
	 */
	public static function sync_invoices( Dinero_Client $client, string $since ): array {
		global $wpdb;
		$members = array();
		foreach ( $wpdb->get_results( 'SELECT id, dinero_contact_guid FROM ' . DB::t( 'members' ) . " WHERE dinero_contact_guid <> ''" ) as $m ) {
			$members[ strtolower( $m->dinero_contact_guid ) ] = (int) $m->id;
		}
		$out = array(
			'checked'  => 0,
			'paid'     => 0,
			'complete' => true,
		);
		if ( ! $members ) {
			return $out;
		}

		$list = $client->get_all(
			'invoices',
			array(
				'changesSince' => $since,
				'fields'       => 'Guid,ContactGuid,Number,Date,PaymentDate,Status,UpdatedAt,DeletedAt',
			)
		);

		$seen = get_option( self::SEEN, array() );
		$seen = is_array( $seen ) ? $seen : array();

		foreach ( $list as $inv ) {
			$guid    = strtolower( (string) ( $inv['Guid'] ?? '' ) );
			$contact = strtolower( (string) ( $inv['ContactGuid'] ?? '' ) );
			$status  = (string) ( $inv['Status'] ?? '' );
			if ( '' === $guid || ! isset( $members[ $contact ] ) || 'Draft' === $status ) {
				continue;
			}
			$member_id = $members[ $contact ];
			$stamp     = (string) ( $inv['UpdatedAt'] ?? '' ) . '|' . $status . '|' . (string) ( $inv['DeletedAt'] ?? '' );
			if ( isset( $seen[ $guid ] ) && $seen[ $guid ]['s'] === $stamp ) {
				continue;
			}

			if ( ! empty( $inv['DeletedAt'] ) ) {
				self::invoice_deleted( $guid );
				$seen[ $guid ] = array(
					's' => $stamp,
					'k' => array(),
				);
				continue;
			}

			// Booked invoices can't be edited in Dinero, so the lines only need reading once.
			if ( isset( $seen[ $guid ]['k'] ) && is_array( $seen[ $guid ]['k'] ) ) {
				$kinds = $seen[ $guid ]['k'];
			} else {
				if ( $client->budget_left() < 2 ) {
					$out['complete'] = false;
					break;
				}
				$detail = $client->get( 'invoices/' . $guid );
				$kinds  = self::classify( (array) ( $detail['ProductLines'] ?? array() ) );
			}

			++$out['checked'];
			$out['paid']  += self::apply( $member_id, $guid, $inv, $kinds ) ? 1 : 0;
			$seen[ $guid ] = array(
				's' => $stamp,
				'k' => $kinds,
			);
		}
		update_option( self::SEEN, $seen, false );
		return $out;
	}

	/**
	 * Which fees an invoice contains. Uses the Dinero products chosen in settings;
	 * with none chosen, falls back to matching line totals against the fee amounts.
	 *
	 * @param array[] $lines ProductLines from GET invoices/{guid}.
	 * @return array{fee?:string, fee_amount?:int, key?:bool}
	 */
	public static function classify( array $lines ): array {
		$s         = Settings::all();
		$by_guid   = array();
		$use_guids = false;
		foreach ( array( 'full', 'half', 'key' ) as $kind ) {
			foreach ( (array) $s[ 'dinero_products_' . $kind ] as $g ) {
				$by_guid[ strtolower( $g ) ] = $kind;
				$use_guids                   = true;
			}
		}
		$amounts = array(
			(int) $s['fee_full'] => 'full',
			(int) $s['fee_half'] => 'half',
			(int) $s['fee_key']  => 'key',
		);

		$out = array();
		foreach ( $lines as $line ) {
			$total = (int) round( (float) ( $line['TotalAmountInclVat'] ?? $line['TotalAmount'] ?? 0 ) );
			$excl  = (int) round( (float) ( $line['TotalAmount'] ?? 0 ) );
			if ( $use_guids ) {
				$kind = $by_guid[ strtolower( (string) ( $line['ProductGuid'] ?? '' ) ) ] ?? null;
			} else {
				$kind = $amounts[ $total ] ?? ( $amounts[ $excl ] ?? null );
			}
			if ( 'key' === $kind ) {
				$out['key'] = true;
			} elseif ( $kind ) {
				$out['fee']        = $kind;
				$out['fee_amount'] = $total;
			}
		}
		return $out;
	}

	/**
	 * Apply one invoice to a member. Returns true if a period became paid.
	 *
	 * @param array $inv   List row (Status, Date, PaymentDate).
	 * @param array $kinds Output of classify().
	 */
	public static function apply( int $member_id, string $guid, array $inv, array $kinds ): bool {
		global $wpdb;
		$paid      = in_array( $inv['Status'] ?? '', array( 'Paid', 'OverPaid' ), true );
		$paid_at   = ! empty( $inv['PaymentDate'] ) ? $inv['PaymentDate'] . ' 00:00:00' : Util::now();
		$became    = false;

		if ( ! empty( $kinds['fee'] ) ) {
			$period = self::period_for_invoice( $member_id, $guid, (string) ( $inv['Date'] ?? '' ), $kinds );
			if ( 'imported' === $period->status && ! $paid ) {
				// An open invoice doesn't overrule the import; a person decides (see unconfirmed_imports()).
				$wpdb->update( DB::t( 'periods' ), array( 'dinero_invoice_guid' => $guid ), array( 'id' => $period->id ) );
			} elseif ( 'paid' !== $period->status ) {
				$row = array(
					'dinero_invoice_guid' => $guid,
					'status'              => $paid ? 'paid' : 'invoiced',
					'invoiced_at'         => $period->invoiced_at ? $period->invoiced_at : Util::now(),
				);
				if ( $paid ) {
					$row['paid_at'] = $paid_at;
				}
				$wpdb->update( DB::t( 'periods' ), $row, array( 'id' => $period->id ) );
				Log::add( $paid ? 'period_paid' : 'period_invoiced_dinero', 'member', $member_id, array( 'period' => (int) $period->id, 'invoice' => $guid ) );
				if ( $paid ) {
					$became = true;
					/**
					 * A membership period was paid in Dinero.
					 *
					 * @param int $period_id Period id.
					 * @param int $member_id Member id.
					 */
					do_action( 'vhus_period_paid', (int) $period->id, $member_id );
				}
			} elseif ( ! $period->dinero_invoice_guid ) {
				$wpdb->update( DB::t( 'periods' ), array( 'dinero_invoice_guid' => $guid ), array( 'id' => $period->id ) );
			}
		}

		if ( ! empty( $kinds['key'] ) && $paid ) {
			$key_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . DB::t( 'keys' ) . ' WHERE member_id = %d AND fee_paid_at IS NULL ORDER BY fee_due DESC, id DESC LIMIT 1', $member_id ) );
			if ( $key_id ) {
				$wpdb->update( DB::t( 'keys' ), array( 'fee_paid_at' => $paid_at ), array( 'id' => $key_id ) );
			}
			/**
			 * A key fee was paid in Dinero (joining or replacement key).
			 *
			 * @param int    $member_id Member id.
			 * @param string $guid      Dinero invoice guid.
			 */
			do_action( 'vhus_key_fee_paid', $member_id, $guid );
		}
		return $became;
	}

	/**
	 * The period an invoice pays for, in order: the period already linked to this invoice;
	 * the member's oldest open period (pending/invoiced) without an invoice; the period for
	 * the invoice's membership year (e.g. an imported one); otherwise a new period for that year.
	 *
	 * @param array $kinds Output of classify().
	 */
	private static function period_for_invoice( int $member_id, string $guid, string $date, array $kinds ): object {
		global $wpdb;
		$t = DB::t( 'periods' );

		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE dinero_invoice_guid = %s", $guid ) );
		if ( $p ) {
			return $p;
		}
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE member_id = %d AND status IN ('pending','invoiced') AND ( dinero_invoice_guid IS NULL OR dinero_invoice_guid = '' ) ORDER BY year_label, id LIMIT 1", $member_id ) );
		if ( $p ) {
			return $p;
		}
		$d    = $date ? new \DateTimeImmutable( $date, wp_timezone() ) : current_datetime();
		$year = Util::year_label_for_invoice( $d );
		$p    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE member_id = %d AND year_label = %s", $member_id, $year ) );
		if ( $p ) {
			return $p;
		}
		$id = Members::ensure_period(
			$member_id,
			$year,
			'half' === ( $kinds['fee'] ?? '' ) ? 'half' : 'full',
			'invoiced',
			array( 'amount_fee' => (int) ( $kinds['fee_amount'] ?? 0 ) )
		);
		return Members::period( $id );
	}

	/** An invoice was deleted in Dinero: an unpaid period linked to it goes back on the cashier's list. */
	private static function invoice_deleted( string $guid ): void {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'periods' ) . ' WHERE dinero_invoice_guid = %s', $guid ) );
		if ( ! $p || 'paid' === $p->status ) {
			return;
		}
		$wpdb->update(
			DB::t( 'periods' ),
			array(
				'dinero_invoice_guid' => null,
				'status'              => 'imported' === $p->status ? 'imported' : 'pending',
				'invoiced_at'         => null,
			),
			array( 'id' => $p->id )
		);
		Log::add( 'dinero_invoice_deleted', 'member', (int) $p->member_id, array( 'period' => (int) $p->id ) );
	}

	/**
	 * Imported periods for the current year that Dinero has not confirmed as paid.
	 * Only meaningful after a complete sync.
	 *
	 * @return object[]
	 */
	public static function unconfirmed_imports(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.id, p.member_id, p.year_label, p.dinero_invoice_guid, m.name, m.email, m.dinero_contact_guid FROM ' . DB::t( 'periods' ) . ' p JOIN ' . DB::t( 'members' ) . " m ON m.id = p.member_id
				WHERE p.status = 'imported' AND p.year_label = %s ORDER BY m.name",
				Util::year_label()
			)
		);
	}

	/** An imported period that Dinero does not confirm is set to "unpaid" by a person, never by the sync. */
	public static function mark_import_unpaid( int $period_id ): bool {
		global $wpdb;
		$p = Members::period( $period_id );
		if ( ! $p || 'imported' !== $p->status ) {
			return false;
		}
		$wpdb->update( DB::t( 'periods' ), array( 'status' => 'unpaid' ), array( 'id' => $period_id ) );
		Log::add( 'period_unpaid', 'member', (int) $p->member_id, array( 'period' => $period_id ) );
		return true;
	}
}
