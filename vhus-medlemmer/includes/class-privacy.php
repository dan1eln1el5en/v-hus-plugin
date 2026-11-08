<?php
/**
 * GDPR: retention, erasure, WordPress privacy tools and the suggested privacy policy text.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Retention rules (from the spec, months in settings):
 * - Declined applicants: deleted `retention_declined` months (default 6) after the last decline.
 * - Former members: deleted `retention_former` months (default 12) after they left their last workshop.
 * - Someone still waiting or approved anywhere is never deleted automatically.
 * Done gate actions and log entries are purged after `retention_log` months.
 * The job runs daily and logs what it deleted (ids only).
 */
class Privacy {

	/** Hooks. */
	public static function init(): void {
		add_action( Gate::DAILY_HOOK, array( __CLASS__, 'run_retention' ), 20 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/* ---------------------------------------------------------------------
	 * Retention
	 * ------------------------------------------------------------------ */

	/**
	 * Members due for deletion, now or within $ahead days.
	 *
	 * @param \DateTimeImmutable|null $now   Reference time (site timezone).
	 * @param int                     $ahead Also include those due within this many days.
	 * @return array[] member_id, name, reason (declined|former), ended (Y-m-d H:i:s), delete_at (Y-m-d), due (bool).
	 */
	public static function candidates( ?\DateTimeImmutable $now = null, int $ahead = 0 ): array {
		global $wpdb;
		$now     = $now ?? current_datetime();
		$s       = Settings::all();
		$members = $wpdb->get_results( 'SELECT id, name FROM ' . DB::t( 'members' ), OBJECT_K );
		$seats   = $wpdb->get_results( 'SELECT member_id, status, decided_at, requested_at FROM ' . DB::t( 'seats' ) );

		$by = array();
		foreach ( $seats as $seat ) {
			$by[ (int) $seat->member_id ][] = $seat;
		}
		$out = array();
		foreach ( $by as $member_id => $list ) {
			if ( ! isset( $members[ $member_id ] ) ) {
				continue;
			}
			$statuses = array_column( $list, 'status' );
			if ( array_intersect( array( 'waiting', 'approved' ), $statuses ) ) {
				continue;
			}
			$former = in_array( 'left', $statuses, true );
			$ended  = max( array_map( fn( $x ) => (string) ( $x->decided_at ?? $x->requested_at ), $list ) );
			$months = (int) ( $former ? $s['retention_former'] : $s['retention_declined'] );
			$at     = ( new \DateTimeImmutable( $ended, wp_timezone() ) )->modify( "+{$months} months" );
			if ( $at > $now->modify( "+{$ahead} days" ) ) {
				continue;
			}
			$out[] = array(
				'member_id' => $member_id,
				'name'      => $members[ $member_id ]->name,
				'reason'    => $former ? 'former' : 'declined',
				'ended'     => $ended,
				'delete_at' => $at->format( 'Y-m-d' ),
				'due'       => $at <= $now,
			);
		}
		usort( $out, fn( $a, $b ) => strcmp( $a['delete_at'], $b['delete_at'] ) );
		return $out;
	}

	/**
	 * Daily job: delete due members, purge old done gate actions and log entries.
	 *
	 * @return array{members:int, gate_actions:int, log:int}
	 */
	public static function run_retention( ?\DateTimeImmutable $now = null ): array {
		global $wpdb;
		$now = $now ?? current_datetime();
		$n   = 0;
		foreach ( self::candidates( $now ) as $c ) {
			if ( $c['due'] ) {
				self::delete_member( (int) $c['member_id'], 'retention_' . $c['reason'] );
				++$n;
			}
		}
		$cutoff = $now->modify( '-' . (int) Settings::get( 'retention_log' ) . ' months' )->format( 'Y-m-d H:i:s' );
		$gate   = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . DB::t( 'gate_actions' ) . ' WHERE done_at IS NOT NULL AND done_at < %s', $cutoff ) );
		$log    = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . DB::t( 'log' ) . ' WHERE created_at < %s', $cutoff ) );
		$res    = array(
			'members'      => $n,
			'gate_actions' => $gate,
			'log'          => $log,
		);
		if ( $n || $gate || $log ) {
			Log::add( 'retention_run', 'privacy', 0, $res );
		}
		return $res;
	}

	/**
	 * Delete a member and everything that identifies them. Done gate actions for them are deleted;
	 * open ones keep their snapshot so the gate admin can still finish them. If the member is in
	 * EasyAccess and no removal is queued, one is queued first.
	 *
	 * @param string $reason For the log (retention_declined, retention_former, request, admin).
	 */
	public static function delete_member( int $member_id, string $reason ): bool {
		global $wpdb;
		$m = Members::get( $member_id );
		if ( ! $m ) {
			return false;
		}
		if ( Gate::in_easyaccess( $member_id ) ) {
			$queued = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'gate_actions' ) . " WHERE member_id = %d AND action = 'remove' AND done_at IS NULL", $member_id ) );
			if ( ! $queued ) {
				Gate::queue(
					$member_id,
					'remove',
					array(
						'name'  => $m->name,
						'phone' => $m->phone_e164,
						'fob'   => Members::current_fob( $member_id ),
						'note'  => 'Medlemmets data er slettet her.',
					)
				);
			}
		}
		foreach ( array( 'seats', 'periods', 'keys' ) as $t ) {
			$wpdb->delete( DB::t( $t ), array( 'member_id' => $member_id ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . DB::t( 'gate_actions' ) . ' WHERE member_id = %d AND done_at IS NOT NULL', $member_id ) );
		$wpdb->delete( DB::t( 'members' ), array( 'id' => $member_id ) );
		Log::add( 'member_deleted', 'member', $member_id, array( 'reason' => $reason ) );
		/**
		 * A member and their data were deleted.
		 *
		 * @param int    $member_id Member id (no longer exists).
		 * @param string $reason    Why.
		 */
		do_action( 'vhus_member_deleted', $member_id, $reason );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * WordPress privacy tools (Tools → Export / Erase Personal Data)
	 * ------------------------------------------------------------------ */

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['vhus-medlemmer'] = array(
			'exporter_friendly_name' => 'V-huset medlemskartotek',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['vhus-medlemmer'] = array(
			'eraser_friendly_name' => 'V-huset medlemskartotek',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Everything stored about the person with this e-mail. */
	public static function export( string $email, int $page = 1 ): array {
		$m = Members::find_by_email( $email );
		if ( ! $m ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$pair  = fn( $name, $value ) => array(
			'name'  => $name,
			'value' => (string) $value,
		);
		$items = array(
			array(
				'group_id'    => 'vhus-member',
				'group_label' => 'V-huset: medlem',
				'item_id'     => 'vhus-member-' . $m->id,
				'data'        => array(
					$pair( 'Medlemsnummer', $m->id ),
					$pair( 'Navn', $m->name ),
					$pair( 'E-mail', $m->email ),
					$pair( 'Telefon', $m->phone_e164 ),
					$pair( 'Adresse', trim( $m->address . ', ' . $m->postcode . ' ' . $m->city, ', ' ) ),
					$pair( 'Samtykke givet', (string) $m->consent_at ),
					$pair( 'Oprettet', $m->created_at ),
					$pair( 'Noter', (string) $m->notes ),
				),
			),
		);
		foreach ( Members::seats( (int) $m->id ) as $s ) {
			$items[] = array(
				'group_id'    => 'vhus-seats',
				'group_label' => 'V-huset: værksteder',
				'item_id'     => 'vhus-seat-' . $s->id,
				'data'        => array(
					$pair( 'Værksted', $s->workshop_name ),
					$pair( 'Status', Members::SEAT_STATUSES[ $s->status ] ?? $s->status ),
					$pair( 'Skrevet op', $s->requested_at ),
					$pair( 'Afgjort', (string) $s->decided_at ),
					$pair( 'Note', (string) $s->note ),
				),
			);
		}
		foreach ( Members::periods( (int) $m->id ) as $p ) {
			$items[] = array(
				'group_id'    => 'vhus-periods',
				'group_label' => 'V-huset: kontingent',
				'item_id'     => 'vhus-period-' . $p->id,
				'data'        => array(
					$pair( 'Medlemsår', $p->year_label ),
					$pair( 'Status', Members::PERIOD_STATUSES[ $p->status ] ?? $p->status ),
					$pair( 'Betalt', (string) $p->paid_at ),
				),
			);
		}
		foreach ( Members::keys( (int) $m->id ) as $k ) {
			$items[] = array(
				'group_id'    => 'vhus-keys',
				'group_label' => 'V-huset: nøglebrikker',
				'item_id'     => 'vhus-key-' . $k->id,
				'data'        => array(
					$pair( 'Briknummer', $k->fob_number ),
					$pair( 'Udleveret', (string) $k->issued_at ),
					$pair( 'Afleveret', (string) $k->returned_at ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/** Erase the person with this e-mail (after the admin confirms the request in WordPress). */
	public static function erase( string $email, int $page = 1 ): array {
		$m       = Members::find_by_email( $email );
		$removed = $m ? self::delete_member( (int) $m->id, 'request' ) : false;
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( 'Medlemmet er slettet fra V-husets medlemskartotek. Er personen oprettet i EasyAccess, ligger der nu en opgave om at slette dem dér.' ) : array(),
			'done'           => true,
		);
	}

	/** Suggested privacy-policy text (Settings → Privacy → policy guide). */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$s    = Settings::all();
		$text = '<h2>Medlemskab og venteliste</h2>'
			. '<p>Når du skriver dig op til et værksted, gemmer Værkstedshuset i Hannovergade dit navn, din e-mail, dit mobilnummer og eventuelt din adresse, hvilke værksteder du står på venteliste til, og hvornår du skrev dig op. Formålet er at administrere ventelister, medlemskab, kontingent og adgang til huset. Grundlaget er dit samtykke og medlemsforholdet.</p>'
			. '<p>Er du medlem, gemmer vi desuden dine værksteder, om dit kontingent er betalt, og nummeret på din nøglebrik. Vi gemmer ikke CPR-nummer, bankoplysninger eller fakturabeløb.</p>'
			. '<p>Værkstedskontakten for dit værksted kan se dine oplysninger. Kassereren og portadministratoren kan se alle medlemmer. Alle visninger og ændringer bliver logget.</p>'
			. '<p>Vi bruger disse databehandlere: webhotellet, Dinero (Visma) til fakturering og Access Technology (EasyAccess) til adgang til porten, hvor dit navn, mobilnummer og nøglebrik ligger.</p>'
			. sprintf( '<p>Bliver du afvist, sletter vi dine oplysninger efter %d måneder. Melder du dig ud, sletter vi dem %d måneder efter udmeldelsen. Du kan altid bede om at se eller få slettet dine oplysninger ved at skrive til bestyrelsen.</p>', (int) $s['retention_declined'], (int) $s['retention_former'] );
		wp_add_privacy_policy_content( 'V-huset Medlemmer', wp_kses_post( $text ) );
	}
}
