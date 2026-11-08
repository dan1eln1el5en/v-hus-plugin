<?php
/**
 * Membership flow: sign-up, approve, decline, invoiced.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Business rules for moving a person through the waiting list to the cashier's list.
 * Screens call these; nothing else changes seat or period status for the flow.
 */
class Flow {

	/**
	 * Register an applicant from the public form: new member (or existing one matched on e-mail)
	 * plus one waiting seat per workshop. Existing members' details are never overwritten from
	 * the public form, so nobody can change another person's data by typing their e-mail.
	 *
	 * @param array $data         Member fields.
	 * @param int[] $workshop_ids Chosen workshops.
	 * @return array{member_id:int, seats:int[], existing:bool}
	 */
	public static function sign_up( array $data, array $workshop_ids ): array {
		$existing  = Members::find_by_email( (string) ( $data['email'] ?? '' ) );
		$member_id = $existing ? (int) $existing->id : Members::create( $data + array( 'consent_at' => Util::now() ) );
		if ( $existing ) {
			Log::add( 'signup_existing', 'member', $member_id );
		}

		$seats = array();
		$new   = array();
		foreach ( array_unique( array_map( 'intval', $workshop_ids ) ) as $ws_id ) {
			if ( ! Workshops::get( $ws_id ) ) {
				continue;
			}
			$before  = Members::workshop_ids( $member_id );
			$seat_id = Members::add_seat( $member_id, $ws_id, 'waiting', null, 'Tilmeldt via hjemmesiden' );
			$seats[] = $seat_id;
			if ( ! in_array( $ws_id, $before, true ) ) {
				$new[] = $seat_id;
			}
		}

		$member = Members::get( $member_id );
		$names  = array();
		foreach ( $new as $seat_id ) {
			$seat    = Members::seat( $seat_id );
			$ws      = Workshops::get( (int) $seat->workshop_id );
			$names[] = $ws->name;
			$contact = $ws->contact_user_id ? get_userdata( (int) $ws->contact_user_id ) : null;
			Mailer::send(
				'contact',
				$contact ? $contact->user_email : Mailer::admin_email(),
				array(
					'navn'      => $member->name,
					'vaerksted' => $ws->name,
					'placering' => self::queue_position( $seat_id ),
					'link'      => admin_url( 'admin.php?page=vhus-waiting&workshop=' . (int) $ws->id ),
				),
				$member_id
			);
		}
		if ( $names ) {
			Mailer::send(
				'receipt',
				$member->email,
				array(
					'navn'        => $member->name,
					'vaerksteder' => implode( ', ', $names ),
				),
				$member_id
			);
		}

		return array(
			'member_id' => $member_id,
			'seats'     => $seats,
			'existing'  => (bool) $existing,
		);
	}

	/** 1-based place in the workshop's queue, ordered by sign-up time. 0 if not waiting. */
	public static function queue_position( int $seat_id ): int {
		global $wpdb;
		$seat = Members::seat( $seat_id );
		if ( ! $seat || 'waiting' !== $seat->status ) {
			return 0;
		}
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . DB::t( 'seats' ) . " WHERE workshop_id = %d AND status = 'waiting' AND ( requested_at < %s OR ( requested_at = %s AND id < %d ) )",
				$seat->workshop_id,
				$seat->requested_at,
				$seat->requested_at,
				$seat->id
			)
		);
	}

	/**
	 * Approve a seat. Creates this year's period on the cashier's list unless the member
	 * already has one (e.g. a paying member joining a second workshop).
	 *
	 * @param bool $notify Send the approval and cashier e-mails.
	 * @return bool False if the seat was not found or already approved.
	 */
	public static function approve( int $seat_id, bool $notify = true ): bool {
		$seat = Members::seat( $seat_id );
		if ( ! $seat || 'approved' === $seat->status ) {
			return false;
		}
		Members::set_seat_status( $seat_id, 'approved' );
		$member_id = (int) $seat->member_id;

		$created = false;
		$period  = Members::period( self::ensure_invoice_period( $member_id, $created ) );

		if ( $notify ) {
			$member = Members::get( $member_id );
			$ws     = Workshops::get( (int) $seat->workshop_id );
			$vars   = array(
				'navn'          => $member->name,
				'vaerksted'     => $ws->name,
				'aar'           => $period->year_label,
				'beloeb'        => Mailer::kr( self::amount( $period ) ),
				'specifikation' => self::specification( $period ),
			);
			if ( $created ) {
				$vars['faktura'] = sprintf(
					'Du modtager snart en faktura fra kassereren på %s kr (%s). Når den er betalt, får du adgang til porten.',
					$vars['beloeb'],
					$vars['specifikation']
				);
			} else {
				$vars['faktura'] = sprintf( 'Du betaler kun ét kontingent for %s, også når du er med i flere værksteder, så du får ikke en ekstra faktura.', $period->year_label );
			}
			Mailer::send( 'approved', $member->email, $vars, $member_id );
			if ( $created ) {
				Mailer::send( 'cashier', Mailer::cashier_emails(), $vars + array( 'link' => admin_url( 'admin.php?page=vhus-invoicing' ) ), $member_id );
			}
		}
		return true;
	}

	/**
	 * The current year's period for a member, creating it as "pending" (ready to invoice) when missing.
	 *
	 * @param bool $created Set to true if a new period was made.
	 * @return int Period id.
	 */
	public static function ensure_invoice_period( int $member_id, ?bool &$created = null ): int {
		$fee_type = Util::fee_type_for_join();
		$amounts  = array(
			'amount_fee' => (int) Settings::get( 'half' === $fee_type ? 'fee_half' : 'fee_full' ),
			'amount_key' => Members::is_returning( $member_id ) ? 0 : (int) Settings::get( 'fee_key' ),
		);
		return Members::ensure_period( $member_id, Util::year_label(), $fee_type, 'pending', $amounts, $created );
	}

	/**
	 * Decline a seat and tell the applicant.
	 *
	 * @param string $message Optional message to the applicant, added to the e-mail.
	 * @param bool   $notify  Send the e-mail.
	 */
	public static function decline( int $seat_id, string $message = '', bool $notify = true ): bool {
		$seat = Members::seat( $seat_id );
		if ( ! $seat || 'declined' === $seat->status ) {
			return false;
		}
		Members::set_seat_status( $seat_id, 'declined' );
		if ( $notify ) {
			$member  = Members::get( (int) $seat->member_id );
			$ws      = Workshops::get( (int) $seat->workshop_id );
			$message = trim( sanitize_textarea_field( $message ) );
			Mailer::send(
				'declined',
				$member->email,
				array(
					'navn'      => $member->name,
					'vaerksted' => $ws->name,
					'besked'    => '' !== $message ? "\n\n" . $message : '',
				),
				(int) $member->id
			);
		}
		return true;
	}

	/** Cashier: the invoice is created in Dinero. */
	public static function mark_invoiced( int $period_id ): bool {
		return self::set_period_status( $period_id, 'pending', 'invoiced' );
	}

	/** Cashier: undo mark_invoiced. */
	public static function unmark_invoiced( int $period_id ): bool {
		return self::set_period_status( $period_id, 'invoiced', 'pending' );
	}

	/** Move a period from one status to another; no-op if it isn't in $from. */
	private static function set_period_status( int $period_id, string $from, string $to ): bool {
		global $wpdb;
		$p = Members::period( $period_id );
		if ( ! $p || $p->status !== $from ) {
			return false;
		}
		$wpdb->update(
			DB::t( 'periods' ),
			array(
				'status'      => $to,
				'invoiced_at' => 'invoiced' === $to ? Util::now() : null,
			),
			array( 'id' => $period_id )
		);
		Log::add( 'period_' . $to, 'member', (int) $p->member_id, array( 'period' => $period_id ) );
		return true;
	}

	/** Total in kr for a period. */
	public static function amount( object $period ): int {
		return (int) $period->amount_fee + (int) $period->amount_key;
	}

	/** "kontingent 1.000 kr + nøglebrik 200 kr". */
	public static function specification( object $period ): string {
		$parts = array( sprintf( '%s %s kr', 'half' === $period->fee_type ? 'halvt kontingent' : 'kontingent', Mailer::kr( (int) $period->amount_fee ) ) );
		if ( (int) $period->amount_key > 0 ) {
			$parts[] = sprintf( 'nøglebrik %s kr', Mailer::kr( (int) $period->amount_key ) );
		}
		return implode( ' + ', $parts );
	}

	/**
	 * Waiting seats for a workshop, oldest first, with member details and other open workshops.
	 *
	 * @return object[]
	 */
	public static function waiting_list( int $workshop_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.id AS seat_id, s.requested_at, s.note, m.id AS member_id, m.name, m.email, m.phone_e164
				FROM ' . DB::t( 'seats' ) . ' s JOIN ' . DB::t( 'members' ) . " m ON m.id = s.member_id
				WHERE s.workshop_id = %d AND s.status = 'waiting'
				ORDER BY s.requested_at, s.id",
				$workshop_id
			)
		);
		foreach ( $rows as $i => $r ) {
			$r->position = $i + 1;
			$others      = array();
			foreach ( Members::seats( (int) $r->member_id ) as $s ) {
				if ( (int) $s->workshop_id !== $workshop_id && in_array( $s->status, array( 'waiting', 'approved' ), true ) ) {
					$others[] = $s->workshop_name . ( 'waiting' === $s->status ? ' (venteliste)' : '' );
				}
			}
			$r->other_workshops = implode( ', ', $others );
		}
		return $rows;
	}

	/**
	 * Periods for the cashier, with member name, e-mail and approved workshops.
	 *
	 * @param string $status pending|invoiced.
	 * @return object[]
	 */
	public static function invoice_list( string $status ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.*, m.name, m.email, m.dinero_contact_guid
				FROM ' . DB::t( 'periods' ) . ' p JOIN ' . DB::t( 'members' ) . ' m ON m.id = p.member_id
				WHERE p.status = %s
				ORDER BY p.created_at, p.id',
				$status
			)
		);
		foreach ( $rows as $r ) {
			$names = array();
			foreach ( Members::seats( (int) $r->member_id ) as $s ) {
				if ( 'approved' === $s->status ) {
					$names[] = $s->workshop_name;
				}
			}
			$r->workshops = implode( ', ', $names );
		}
		return $rows;
	}
}
