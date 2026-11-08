<?php
/**
 * Gate-action queue for EasyAccess, worked by a person (EasyAccess has no API).
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Turns events into actions the gate admin applies in EasyAccess:
 *
 * | Event                                        | Action        |
 * |----------------------------------------------|---------------|
 * | Paid, member not yet in EasyAccess           | add           |
 * | Paid, member already in EasyAccess           | add_to_group  |
 * | New phone, member in EasyAccess               | update        |
 * | Last approved seat ends, member in EasyAccess | remove       |
 *
 * The gate opens when a member calls it from their registered mobile number, so the phone
 * number is the only thing EasyAccess needs; workshop keys are handled outside EasyAccess.
 * Access is only ever given through the yearly group, so an unpaid member drops out
 * when the group's end date passes and needs no action.
 */
class Gate {

	const ACTIONS = array(
		'add'          => 'Opret bruger',
		'add_to_group' => 'Tilføj til gruppe',
		'update'       => 'Ret bruger',
		'remove'       => 'Slet bruger',
	);

	const DAILY_HOOK = 'vhus_daily';

	/** Hooks. */
	public static function init(): void {
		add_action( 'vhus_period_paid', array( __CLASS__, 'on_period_paid' ), 10, 2 );
		add_action( 'vhus_member_updated', array( __CLASS__, 'on_member_updated' ), 10, 3 );
		add_action( 'vhus_seat_status', array( __CLASS__, 'on_seat_status' ), 10, 4 );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
	}

	/** Daily reminders at 07:00 site time. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			$next = new \DateTimeImmutable( 'tomorrow 07:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY_HOOK );
		}
	}

	/** Deactivation. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------ */

	/**
	 * Queue an action unless an identical one is already open. Returns the action id.
	 *
	 * @param array $payload Snapshot of what the gate admin needs, kept even if the member is deleted later.
	 */
	public static function queue( int $member_id, string $action, array $payload = array() ): int {
		global $wpdb;
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return 0;
		}
		$json = wp_json_encode( $payload );
		$t    = DB::t( 'gate_actions' );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE member_id = %d AND action = %s AND payload = %s AND done_at IS NULL", $member_id, $action, $json ) );
		if ( $id ) {
			return $id;
		}
		$wpdb->insert(
			$t,
			array(
				'member_id'  => $member_id,
				'action'     => $action,
				'payload'    => $json,
				'created_at' => Util::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Log::add( 'gate_queue', 'member', $member_id, array( 'gate_action' => $id, 'action' => $action ) );
		return $id;
	}

	/** One action. */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'gate_actions' ) . ' WHERE id = %d', $id ) );
	}

	/** Mark done; an "add" also marks the member as present in EasyAccess. */
	public static function mark_done( int $id ): bool {
		global $wpdb;
		$a = self::get( $id );
		if ( ! $a || $a->done_at ) {
			return false;
		}
		$wpdb->update(
			DB::t( 'gate_actions' ),
			array(
				'done_at' => Util::now(),
				'done_by' => get_current_user_id(),
			),
			array( 'id' => $id )
		);
		if ( 'add' === $a->action ) {
			// Unless the member has since been removed (an add marked done late).
			$removed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'gate_actions' ) . " WHERE member_id = %d AND action = 'remove' AND id > %d", $a->member_id, $a->id ) );
			if ( ! $removed ) {
				self::set_linked( (int) $a->member_id, true );
			}
		}
		if ( 'remove' === $a->action ) {
			self::set_linked( (int) $a->member_id, false );
		}
		Log::add( 'gate_done', 'member', (int) $a->member_id, array( 'gate_action' => $id, 'action' => $a->action ) );
		return true;
	}

	/** Undo a "done" click. */
	public static function reopen( int $id ): bool {
		global $wpdb;
		$a = self::get( $id );
		if ( ! $a || ! $a->done_at ) {
			return false;
		}
		$wpdb->update(
			DB::t( 'gate_actions' ),
			array(
				'done_at' => null,
				'done_by' => null,
			),
			array( 'id' => $id )
		);
		Log::add( 'gate_reopen', 'member', (int) $a->member_id, array( 'gate_action' => $id ) );
		return true;
	}

	/** Mark a member as present in EasyAccess (or not). */
	public static function set_linked( int $member_id, bool $linked ): void {
		global $wpdb;
		$wpdb->update( DB::t( 'members' ), array( 'easyaccess_linked_at' => $linked ? Util::now() : null ), array( 'id' => $member_id ) );
	}

	/**
	 * Open actions (oldest first) or recently done ones (newest first), with member name.
	 *
	 * @return object[]
	 */
	public static function actions( bool $open = true, int $days = 30 ): array {
		global $wpdb;
		$a = DB::t( 'gate_actions' );
		$m = DB::t( 'members' );
		if ( $open ) {
			$rows = $wpdb->get_results( "SELECT a.*, m.name, m.phone_e164 FROM $a a LEFT JOIN $m m ON m.id = a.member_id WHERE a.done_at IS NULL ORDER BY a.created_at, a.id" );
		} else {
			$since = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ); // Stored times are site time.
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, m.name, m.phone_e164 FROM $a a LEFT JOIN $m m ON m.id = a.member_id WHERE a.done_at IS NOT NULL AND a.done_at >= %s ORDER BY a.done_at DESC, a.id DESC", $since ) );
		}
		foreach ( $rows as $r ) {
			$r->data = json_decode( (string) $r->payload, true ) ?: array();
			$r->name = $r->name ?? ( $r->data['name'] ?? '(slettet)' );
		}
		return $rows;
	}

	/** Number of open actions (menu badge). */
	public static function open_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::t( 'gate_actions' ) . ' WHERE done_at IS NULL' );
	}

	/* ---------------------------------------------------------------------
	 * Who is in EasyAccess?
	 * ------------------------------------------------------------------ */

	/**
	 * A member is in EasyAccess when flagged: everyone from the old Excel list (set at import),
	 * anyone matched from the EasyAccess export, and anyone whose "add" was done.
	 * A done "remove" clears the flag.
	 */
	public static function in_easyaccess( int $member_id ): bool {
		$m = Members::get( $member_id );
		return $m && ! empty( $m->easyaccess_linked_at );
	}

	/** Open "add" action for the member, if any. */
	private static function open_add( int $member_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'gate_actions' ) . " WHERE member_id = %d AND action = 'add' AND done_at IS NULL", $member_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Event handlers
	 * ------------------------------------------------------------------ */

	/** A membership period was paid in Dinero. */
	public static function on_period_paid( int $period_id, int $member_id ): void {
		$period = Members::period( $period_id );
		if ( ! $period ) {
			return;
		}
		$group = Util::group_name( $period->year_label );
		if ( self::in_easyaccess( $member_id ) ) {
			self::queue( $member_id, 'add_to_group', array( 'group' => $group ) );
		} elseif ( ! self::open_add( $member_id ) ) {
			self::queue( $member_id, 'add', array( 'group' => $group ) );
		}
	}

	/**
	 * Member details changed: a new phone number must be changed in EasyAccess (it opens the gate).
	 *
	 * @param string[] $changed Changed fields.
	 * @param array    $before  Row before.
	 */
	public static function on_member_updated( int $member_id, array $changed, array $before ): void {
		if ( ! in_array( 'phone_e164', $changed, true ) || ! self::in_easyaccess( $member_id ) ) {
			return;
		}
		$m = Members::get( $member_id );
		self::queue(
			$member_id,
			'update',
			array(
				'field' => 'phone',
				'old'   => (string) ( $before['phone_e164'] ?? '' ),
				'new'   => $m->phone_e164,
			)
		);
	}

	/** When the member's last approved seat ends, they leave EasyAccess. */
	public static function on_seat_status( int $seat_id, string $from, string $to, int $member_id ): void {
		if ( 'approved' !== $from || 'approved' === $to ) {
			return;
		}
		foreach ( Members::seats( $member_id ) as $s ) {
			if ( 'approved' === $s->status ) {
				return; // Still in another workshop.
			}
		}
		$open_add = self::open_add( $member_id );
		if ( ! self::in_easyaccess( $member_id ) && ! $open_add ) {
			return;
		}
		$m = Members::get( $member_id );
		self::queue(
			$member_id,
			'remove',
			array(
				'name'  => $m->name,
				'phone' => $m->phone_e164,
				'note'  => $open_add ? 'Har også en åben "Opret bruger". Er brugeren ikke oprettet endnu, så markér begge som udført.' : '',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * EasyAccess import file
	 * ------------------------------------------------------------------ */

	/** Column headers of the EasyAccess user import template (manual section 8.11). */
	const TEMPLATE_COLUMNS = array( 'Fulde navn', 'ID', 'Pinkode', 'Nøglekort/Brik', 'Telefon', 'Gruppenavn' );

	/**
	 * Rows for the import file from open "add" actions, using current member data.
	 *
	 * @param int[]|null $ids Limit to these action ids.
	 * @return array{rows: array[], action_ids: int[]}
	 */
	public static function import_rows( ?array $ids = null ): array {
		$rows   = array();
		$action = array();
		foreach ( self::actions( true ) as $a ) {
			if ( 'add' !== $a->action || ( null !== $ids && ! in_array( (int) $a->id, $ids, true ) ) ) {
				continue;
			}
			$m = Members::get( (int) $a->member_id );
			if ( ! $m ) {
				continue;
			}
			$rows[]   = array(
				$m->name,
				(string) $m->id,
				'',
				'', // Nøglekort/Brik: not used; the gate opens by phone.
				$m->phone_e164,
				(string) ( $a->data['group'] ?? Util::group_name( Util::year_label() ) ),
			);
			$action[] = (int) $a->id;
		}
		return array(
			'rows'       => $rows,
			'action_ids' => $action,
		);
	}

	/* ---------------------------------------------------------------------
	 * Reminders
	 * ------------------------------------------------------------------ */

	/**
	 * Addresses of gate admins; falls back to the administrator.
	 *
	 * @return string[]
	 */
	public static function gate_admin_emails(): array {
		$emails = get_users(
			array(
				'role__in' => array( 'vhus_gate_admin' ),
				'fields'   => 'user_email',
			)
		);
		return $emails ? $emails : array( Mailer::admin_email() );
	}

	/** Daily: weekly nudge about old open actions, and the March group reminder. */
	public static function daily( ?\DateTimeImmutable $today = null ): void {
		$today = $today ?? current_datetime();
		$sent  = get_option( 'vhus_gate_reminders', array() );
		$sent  = is_array( $sent ) ? $sent : array();

		// Open actions older than 7 days: at most one e-mail a week.
		$old = array_filter( self::actions( true ), fn( $a ) => (int) mysql2date( 'U', $a->created_at ) < $today->getTimestamp() - 7 * DAY_IN_SECONDS );
		if ( $old && ( empty( $sent['stale'] ) || (int) $sent['stale'] < $today->getTimestamp() - 6 * DAY_IN_SECONDS ) ) {
			$lines = array_map( fn( $a ) => '- ' . self::ACTIONS[ $a->action ] . ': ' . $a->name . ' (siden ' . mysql2date( 'j. M', $a->created_at ) . ')', $old );
			wp_mail(
				self::gate_admin_emails(),
				1 === count( $old ) ? 'V-huset: 1 portopgave venter' : sprintf( 'V-huset: %d portopgaver venter', count( $old ) ),
				"Disse opgaver i EasyAccess har ventet over en uge:\n\n" . implode( "\n", $lines ) . "\n\nSe listen: " . admin_url( 'admin.php?page=vhus-gate' )
			);
			$sent['stale'] = $today->getTimestamp();
		}

		// March: create next year's group in EasyAccess.
		$next = self::next_group();
		if ( '03' === $today->format( 'm' ) && ( $sent['group'] ?? '' ) !== $next['name'] ) {
			wp_mail(
				array_unique( array_merge( self::gate_admin_emails(), array( Mailer::admin_email() ) ) ),
				'V-huset: opret næste års adgangsgruppe i EasyAccess',
				sprintf(
					"Det er tid til at oprette næste års adgangsgruppe i EasyAccess (Kontrolpanel, brugergrupper):\n\nNavn: %s\nStartdato: %s\nSlutdato: %s\n\nNår fornyelserne bliver betalt, kommer \"Tilføj til gruppe\"-opgaverne på portlisten: %s",
					$next['name'],
					$next['start'],
					$next['end'],
					admin_url( 'admin.php?page=vhus-gate' )
				)
			);
			$sent['group'] = $next['name'];
		}
		update_option( 'vhus_gate_reminders', $sent, false );
	}

	/**
	 * The access group for a membership year: name and the dates to set in EasyAccess.
	 *
	 * @param int|null $start_year First calendar year of the membership year; default the coming one in spring.
	 * @return array{name:string, start:string, end:string, year:string}
	 */
	public static function group_for_year( int $start_year ): array {
		$s     = Settings::all();
		$label = sprintf( '%d/%02d', $start_year, ( $start_year + 1 ) % 100 );
		$start = new \DateTimeImmutable( $start_year . '-' . $s['year_start'] );
		$end   = new \DateTimeImmutable( ( $start_year + 1 ) . '-' . $s['grace_until'] );
		return array(
			'name'  => Util::group_name( $label ),
			'year'  => $label,
			'start' => wp_date( 'j. F Y', $start->getTimestamp() ),
			'end'   => wp_date( 'j. F Y', $end->getTimestamp() ),
		);
	}

	/** The group for the year after the current membership year. */
	public static function next_group(): array {
		return self::group_for_year( Util::membership_start_year() + 1 );
	}

	/** The group for the current membership year. */
	public static function current_group(): array {
		return self::group_for_year( Util::membership_start_year() );
	}
}
