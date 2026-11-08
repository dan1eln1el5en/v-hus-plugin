<?php
/**
 * Member data access: members, seats, periods and keys.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * All reads and writes for a person and the rows that belong to them.
 */
class Members {

	const SEAT_STATUSES = array(
		'waiting'  => 'På venteliste',
		'approved' => 'Godkendt',
		'declined' => 'Afvist',
		'left'     => 'Udmeldt',
	);

	const PERIOD_STATUSES = array(
		'pending'  => 'Klar til fakturering',
		'invoiced' => 'Faktureret',
		'paid'     => 'Betalt',
		'imported' => 'Importeret (regnes som betalt)',
		'unpaid'   => 'Ikke betalt',
	);

	/** Overall status shown in lists; derived, never stored. */
	const MEMBER_STATUSES = array(
		'active'           => 'Aktiv',
		'awaiting_payment' => 'Afventer betaling',
		'waiting'          => 'På venteliste',
		'former'           => 'Udmeldt',
		'declined'         => 'Afvist',
		'none'             => 'Intet værksted',
	);

	/** Board posts, in the order they are listed on the Kontakt page. */
	const BOARD_TITLES = array( 'Formand', 'Næstformand', 'Kasserer', 'Sekretær', 'Bestyrelsesmedlem', 'Suppleant', 'Revisor' );

	/** Set board post and public e-mail (administrators only; '' clears). */
	public static function set_public_profile( int $id, string $title, string $email ): void {
		global $wpdb;
		$title = in_array( $title, self::BOARD_TITLES, true ) ? $title : '';
		$email = sanitize_email( $email );
		$m     = self::get( $id );
		if ( ! $m || ( $m->board_title === $title && $m->public_email === $email ) ) {
			return;
		}
		$wpdb->update( DB::t( 'members' ), array( 'board_title' => $title, 'public_email' => $email ), array( 'id' => $id ) );
		Log::add( 'member_public_profile', 'member', $id, array( 'title' => $title, 'email' => '' !== $email ) );
	}

	/** Editable member fields. */
	const FIELDS = array( 'name', 'email', 'phone_e164', 'address', 'postcode', 'city', 'notes' );

	/** One member. */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'members' ) . ' WHERE id = %d', $id ) );
	}

	/** Find by e-mail, case-insensitive. */
	public static function find_by_email( string $email ): ?object {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'members' ) . ' WHERE LOWER(email) = %s LIMIT 1', $email ) );
	}

	/** Find by E.164 phone. */
	public static function find_by_phone( string $e164 ): ?object {
		global $wpdb;
		if ( '' === $e164 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'members' ) . ' WHERE phone_e164 = %s LIMIT 1', $e164 ) );
	}

	/**
	 * Clean an input array down to member fields.
	 *
	 * @param array $data Raw input.
	 */
	public static function clean( array $data ): array {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			if ( ! array_key_exists( $f, $data ) ) {
				continue;
			}
			$v = $data[ $f ];
			switch ( $f ) {
				case 'email':
					$out[ $f ] = strtolower( sanitize_email( (string) $v ) );
					break;
				case 'phone_e164':
					$out[ $f ] = Util::phone_e164( $v );
					break;
				case 'postcode':
					$out[ $f ] = Util::postcode( $v );
					break;
				case 'notes':
					$out[ $f ] = sanitize_textarea_field( (string) $v );
					break;
				default:
					$out[ $f ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * Create a member. Returns the new id.
	 *
	 * @param array $data Member fields.
	 */
	public static function create( array $data, bool $log = true ): int {
		global $wpdb;
		$row               = self::clean( $data );
		$row['created_at'] = Util::now();
		$row['updated_at'] = $row['created_at'];
		if ( ! empty( $data['consent_at'] ) ) {
			$row['consent_at'] = $data['consent_at'];
		}
		$wpdb->insert( DB::t( 'members' ), $row );
		$id = (int) $wpdb->insert_id;
		if ( $log ) {
			Log::add( 'member_create', 'member', $id );
		}
		/**
		 * A member was created.
		 *
		 * @param int $id Member id.
		 */
		do_action( 'vhus_member_created', $id );
		return $id;
	}

	/**
	 * Update a member's fields. Logs which fields changed (not their values).
	 *
	 * @param array $data Member fields.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		$row     = self::clean( $data );
		$before  = (array) self::get( $id );
		$changed = array_keys( array_diff_assoc( $row, array_intersect_key( $before, $row ) ) );
		if ( ! $changed ) {
			return;
		}
		$row['updated_at'] = Util::now();
		$wpdb->update( DB::t( 'members' ), $row, array( 'id' => $id ) );
		Log::add( 'member_update', 'member', $id, array( 'fields' => $changed ) );
		/**
		 * Member details changed.
		 *
		 * @param int      $id      Member id.
		 * @param string[] $changed Changed field names.
		 * @param array    $before  Row before the change.
		 */
		do_action( 'vhus_member_updated', $id, $changed, $before );
	}

	/**
	 * Workshop ids where the member has a waiting or approved seat.
	 *
	 * @return int[]
	 */
	public static function workshop_ids( int $member_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT workshop_id FROM ' . DB::t( 'seats' ) . " WHERE member_id = %d AND status IN ('waiting','approved')",
					$member_id
				)
			)
		);
	}

	/**
	 * Seats with workshop names.
	 *
	 * @return object[]
	 */
	public static function seats( int $member_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, w.name AS workshop_name FROM ' . DB::t( 'seats' ) . ' s JOIN ' . DB::t( 'workshops' ) . ' w ON w.id = s.workshop_id WHERE s.member_id = %d ORDER BY s.requested_at',
				$member_id
			)
		);
	}

	/** One seat. */
	public static function seat( int $seat_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'seats' ) . ' WHERE id = %d', $seat_id ) );
	}

	/**
	 * Add a seat unless an open (waiting/approved) one exists for the same workshop.
	 * Returns the seat id (existing or new).
	 */
	public static function add_seat( int $member_id, int $workshop_id, string $status = 'waiting', ?string $requested_at = null, string $note = '', bool $log = true ): int {
		global $wpdb;
		$table    = DB::t( 'seats' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE member_id = %d AND workshop_id = %d AND status IN ('waiting','approved')",
				$member_id,
				$workshop_id
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}
		$status = isset( self::SEAT_STATUSES[ $status ] ) ? $status : 'waiting';
		$now    = Util::now();
		$wpdb->insert(
			$table,
			array(
				'member_id'    => $member_id,
				'workshop_id'  => $workshop_id,
				'status'       => $status,
				'requested_at' => $requested_at ?? $now,
				'decided_at'   => 'waiting' === $status ? null : $now,
				'decided_by'   => 'waiting' === $status ? null : get_current_user_id(),
				'note'         => $note,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $log ) {
			Log::add( 'seat_create', 'member', $member_id, array( 'seat' => $id, 'workshop' => $workshop_id, 'status' => $status ) );
		}
		return $id;
	}

	/** Change a seat's status (approve, decline, leave, back to waiting). */
	public static function set_seat_status( int $seat_id, string $status, ?string $note = null ): void {
		global $wpdb;
		if ( ! isset( self::SEAT_STATUSES[ $status ] ) ) {
			return;
		}
		$seat = self::seat( $seat_id );
		if ( ! $seat || $seat->status === $status ) {
			return;
		}
		$row = array(
			'status'     => $status,
			'decided_at' => 'waiting' === $status ? null : Util::now(),
			'decided_by' => 'waiting' === $status ? null : get_current_user_id(),
		);
		if ( null !== $note ) {
			$row['note'] = sanitize_textarea_field( $note );
		}
		$wpdb->update( DB::t( 'seats' ), $row, array( 'id' => $seat_id ) );
		Log::add( 'seat_status', 'member', (int) $seat->member_id, array( 'seat' => $seat_id, 'from' => $seat->status, 'to' => $status ) );
		/**
		 * A seat changed status (approve, decline, leave, ...).
		 *
		 * @param int    $seat_id   Seat id.
		 * @param string $from      Old status.
		 * @param string $to        New status.
		 * @param int    $member_id Member id.
		 */
		do_action( 'vhus_seat_status', $seat_id, $seat->status, $status, (int) $seat->member_id );
	}

	/** Update just a seat's note. */
	public static function set_seat_note( int $seat_id, string $note ): void {
		global $wpdb;
		$wpdb->update( DB::t( 'seats' ), array( 'note' => sanitize_textarea_field( $note ) ), array( 'id' => $seat_id ) );
	}

	/**
	 * Periods newest first.
	 *
	 * @return object[]
	 */
	public static function periods( int $member_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'periods' ) . ' WHERE member_id = %d ORDER BY year_label DESC', $member_id ) );
	}

	/**
	 * Create the period for a year if it doesn't exist. Returns its id.
	 *
	 * @param int[] $amounts Optional amount_fee and amount_key in kr.
	 * @param bool  $created Set to true when a new row was inserted.
	 */
	public static function ensure_period( int $member_id, string $year_label, string $fee_type, string $status, array $amounts = array(), ?bool &$created = null ): int {
		global $wpdb;
		$created = false;
		$table   = DB::t( 'periods' );
		$id      = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE member_id = %d AND year_label = %s", $member_id, $year_label ) );
		if ( $id ) {
			return (int) $id;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'  => $member_id,
				'year_label' => $year_label,
				'fee_type'   => in_array( $fee_type, array( 'full', 'half' ), true ) ? $fee_type : 'full',
				'amount_fee' => absint( $amounts['amount_fee'] ?? 0 ),
				'amount_key' => absint( $amounts['amount_key'] ?? 0 ),
				'status'     => isset( self::PERIOD_STATUSES[ $status ] ) ? $status : 'pending',
				'created_at' => Util::now(),
			)
		);
		$created = true;
		return (int) $wpdb->insert_id;
	}

	/** One period. */
	public static function period( int $period_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'periods' ) . ' WHERE id = %d', $period_id ) );
	}

	/** Has this member ever had a period or a key? New members pay the key fee. */
	public static function is_returning( int $member_id ): bool {
		global $wpdb;
		$periods = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'periods' ) . ' WHERE member_id = %d', $member_id ) );
		$keys    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'keys' ) . ' WHERE member_id = %d', $member_id ) );
		return $periods + $keys > 0;
	}

	/**
	 * Workshop keys, newest first, with workshop name. (Keys are physical workshop keys;
	 * the gate itself opens by phone. The number column is still called fob_number.)
	 *
	 * @return object[]
	 */
	public static function keys( int $member_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT k.*, w.name AS workshop_name FROM ' . DB::t( 'keys' ) . ' k LEFT JOIN ' . DB::t( 'workshops' ) . ' w ON w.id = k.workshop_id WHERE k.member_id = %d ORDER BY k.id DESC',
				$member_id
			)
		);
	}

	/** One key row. */
	public static function key( int $key_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'keys' ) . ' WHERE id = %d', $key_id ) );
	}

	/**
	 * Suggested payment for a key about to be handed out:
	 * 'joining' when the member's joining invoice already includes the key fee and this is their first key,
	 * 'due' otherwise (a replacement or an extra key). People can override it in the form.
	 */
	public static function suggested_key_payment( int $member_id ): string {
		global $wpdb;
		$keys    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'keys' ) . ' WHERE member_id = %d', $member_id ) );
		$covered = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::t( 'periods' ) . ' WHERE member_id = %d AND amount_key > 0', $member_id ) );
		return ( 0 === $keys && $covered > 0 ) ? 'joining' : 'due';
	}

	/**
	 * Record a workshop key handed out. A new key for a workshop where the member already
	 * holds one replaces it (the old one is marked returned/replaced).
	 *
	 * @param string $payment joining (in the joining invoice), due (cashier invoices the key fee), none.
	 * @return int Key row id.
	 */
	public static function add_key( int $member_id, ?int $workshop_id, string $number = '', string $payment = 'due' ): int {
		global $wpdb;
		$now   = Util::now();
		$table = DB::t( 'keys' );
		if ( $workshop_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET returned_at = %s WHERE member_id = %d AND workshop_id = %d AND returned_at IS NULL", $now, $member_id, $workshop_id ) );
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'   => $member_id,
				'workshop_id' => $workshop_id ? $workshop_id : null,
				'fob_number'  => sanitize_text_field( $number ),
				'issued_at'   => $now,
				'fee_due'     => 'due' === $payment ? 1 : 0,
				'fee_paid_at' => 'none' === $payment ? $now : null,
			)
		);
		$key_id = (int) $wpdb->insert_id;
		Log::add( 'key_issue', 'member', $member_id, array( 'key' => $key_id, 'workshop' => (int) $workshop_id, 'payment' => $payment ) );
		/**
		 * A workshop key was handed out.
		 *
		 * @param int    $member_id Member id.
		 * @param int    $key_id    Key row id.
		 * @param string $payment   joining|due|none.
		 */
		do_action( 'vhus_key_issued', $member_id, $key_id, $payment );
		return $key_id;
	}

	/** Mark a key as handed back. */
	public static function return_key( int $key_id ): bool {
		global $wpdb;
		$k = self::key( $key_id );
		if ( ! $k || $k->returned_at ) {
			return false;
		}
		$wpdb->update( DB::t( 'keys' ), array( 'returned_at' => Util::now() ), array( 'id' => $key_id ) );
		Log::add( 'key_return', 'member', (int) $k->member_id, array( 'key' => $key_id ) );
		return true;
	}

	/**
	 * Derive the overall status from seats and the current period.
	 *
	 * @param string[]    $seat_statuses Statuses of the member's seats.
	 * @param string|null $period_status Status of the current-year period.
	 */
	public static function derive_status( array $seat_statuses, ?string $period_status ): string {
		if ( in_array( 'approved', $seat_statuses, true ) ) {
			return in_array( $period_status, array( 'paid', 'imported' ), true ) ? 'active' : 'awaiting_payment';
		}
		foreach ( array( 'waiting' => 'waiting', 'left' => 'former', 'declined' => 'declined' ) as $seat => $status ) {
			if ( in_array( $seat, $seat_statuses, true ) ) {
				return $status;
			}
		}
		return 'none';
	}

	/**
	 * Members for list screens, filtered in PHP (the association has under a few hundred members).
	 *
	 * @param array $args search, workshop_id, status, orderby, order.
	 * @return array[] Rows with member fields plus workshops, status, period_status.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$visible = Roles::visible_workshop_ids();
		if ( is_array( $visible ) && ! $visible ) {
			return array();
		}

		$members = $wpdb->get_results( 'SELECT * FROM ' . DB::t( 'members' ), ARRAY_A );
		$seats   = $wpdb->get_results( 'SELECT s.member_id, s.workshop_id, s.status, w.name FROM ' . DB::t( 'seats' ) . ' s JOIN ' . DB::t( 'workshops' ) . ' w ON w.id = s.workshop_id ORDER BY w.sort_order', ARRAY_A );
		$periods = $wpdb->get_results( $wpdb->prepare( 'SELECT member_id, status FROM ' . DB::t( 'periods' ) . ' WHERE year_label = %s', Util::year_label() ), ARRAY_A );

		$by_member = array();
		foreach ( $seats as $s ) {
			$by_member[ $s['member_id'] ][] = $s;
		}
		$period_by = array_column( $periods, 'status', 'member_id' );

		$search = mb_strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$ws     = (int) ( $args['workshop_id'] ?? 0 );
		$status = (string) ( $args['status'] ?? '' );
		$rows   = array();

		foreach ( $members as $m ) {
			$my_seats = $by_member[ $m['id'] ] ?? array();
			$open     = array_filter( $my_seats, fn( $s ) => in_array( $s['status'], array( 'waiting', 'approved' ), true ) );
			$open_ids = array_map( 'intval', array_column( $open, 'workshop_id' ) );

			if ( is_array( $visible ) && ! array_intersect( $visible, $open_ids ) ) {
				continue;
			}
			if ( $ws && ! in_array( $ws, array_map( 'intval', array_column( $my_seats, 'workshop_id' ) ), true ) ) {
				continue;
			}
			$m['period_status'] = $period_by[ $m['id'] ] ?? null;
			$m['status']        = self::derive_status( array_column( $my_seats, 'status' ), $m['period_status'] );
			if ( $status && $m['status'] !== $status ) {
				continue;
			}
			if ( $search ) {
				$hay = mb_strtolower( $m['name'] . ' ' . $m['email'] . ' ' . $m['phone_e164'] );
				if ( ! str_contains( $hay, $search ) ) {
					continue;
				}
			}
			$m['workshops'] = implode(
				', ',
				array_map(
					fn( $s ) => 'waiting' === $s['status'] ? $s['name'] . ' (venteliste)' : $s['name'],
					$open
				)
			);
			$rows[]         = $m;
		}

		$orderby = in_array( $args['orderby'] ?? '', array( 'name', 'email', 'workshops', 'status', 'created_at' ), true ) ? $args['orderby'] : 'name';
		$desc    = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) );
		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $desc ) {
				$r = strcoll( mb_strtolower( (string) $a[ $orderby ] ), mb_strtolower( (string) $b[ $orderby ] ) );
				return $desc ? -$r : $r;
			}
		);
		return $rows;
	}
}
