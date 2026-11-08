<?php
/**
 * Public shortcodes for the website: waiting list, workshop list, board and contacts.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * [vhus_venteliste]   On a workshop page: "Der står 3 på ventelisten." Finds the workshop from the page.
 *                     Attributes: vaerksted="atelier" (slug, code or name), visning="tekst|tal",
 *                     pladser="ja" (capacity), knap="ja" (sign-up button with the workshop pre-ticked).
 * [vhus_vaerksteder]  List of workshops with links. Attributes: venteliste="ja|nej", kontakt="ja|nej".
 * [vhus_kontakter]    Board and workshop contacts. Attribute: vis="alle|bestyrelse|kontakter".
 *
 * Only names, board posts, workshop names, counts and e-mails chosen for the website are shown.
 * E-mail addresses are obfuscated against simple harvesters.
 */
class Shortcodes {

	/** Hooks. */
	public static function init(): void {
		add_shortcode( 'vhus_venteliste', array( __CLASS__, 'waiting' ) );
		add_shortcode( 'vhus_vaerksteder', array( __CLASS__, 'workshops' ) );
		add_shortcode( 'vhus_kontakter', array( __CLASS__, 'contacts' ) );
	}

	/** Load the small public stylesheet. */
	private static function style(): void {
		wp_enqueue_style( 'vhus-public', VHUS_URL . 'public/public.css', array(), VHUS_VERSION );
	}

	/** "ja"/"nej" attribute to bool. */
	private static function yes( $v ): bool {
		return in_array( strtolower( (string) $v ), array( 'ja', 'yes', '1', 'true' ), true );
	}

	/** Number waiting per workshop id. */
	private static function waiting_counts(): array {
		static $counts = null;
		if ( null === $counts ) {
			$counts = array();
			foreach ( Workshops::seat_counts() as $id => $c ) {
				$counts[ $id ] = array(
					'waiting'  => (int) ( $c['waiting'] ?? 0 ),
					'approved' => (int) ( $c['approved'] ?? 0 ),
				);
			}
		}
		return $counts;
	}

	/** URL of the sign-up page: the setting, else the first published page with [vhus_tilmelding]. */
	public static function signup_url( ?object $w = null ): string {
		$id = (int) Settings::get( 'signup_page_id' );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			$id = (int) get_transient( 'vhus_signup_page' );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				global $wpdb;
				$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[vhus_tilmelding%' ORDER BY ID LIMIT 1" );
				set_transient( 'vhus_signup_page', $id, DAY_IN_SECONDS );
			}
		}
		if ( ! $id ) {
			return '';
		}
		$url = (string) get_permalink( $id );
		return $w ? add_query_arg( 'vaerksted', $w->slug, $url ) : $url;
	}

	/**
	 * [vhus_venteliste]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function waiting( $atts ): string {
		$a = shortcode_atts(
			array(
				'vaerksted' => '',
				'visning'   => 'tekst',
				'pladser'   => 'nej',
				'knap'      => 'nej',
			),
			$atts,
			'vhus_venteliste'
		);
		$w = Workshops::resolve( (string) $a['vaerksted'] );
		if ( ! $w ) {
			return current_user_can( 'edit_posts' ) ? '<em>[vhus_venteliste: værkstedet blev ikke fundet. Angiv vaerksted="…" eller vælg siden under V-huset → Værksteder.]</em>' : '';
		}
		self::style();
		$c = self::waiting_counts()[ (int) $w->id ] ?? array( 'waiting' => 0, 'approved' => 0 );
		$n = $c['waiting'];

		if ( 'tal' === $a['visning'] ) {
			return '<span class="vhus-waiting-count">' . (int) $n . '</span>';
		}
		$text = 0 === $n ? 'Der er ingen venteliste lige nu.' : sprintf( 'Der står %d på ventelisten.', $n );
		if ( self::yes( $a['pladser'] ) && null !== $w->capacity ) {
			$free  = max( 0, (int) $w->capacity - $c['approved'] );
			$text .= ' ' . ( $free > 0 ? sprintf( 'Værkstedet har %d pladser, og %d er ledige.', (int) $w->capacity, $free ) : sprintf( 'Værkstedets %d pladser er alle optaget.', (int) $w->capacity ) );
		}
		$out = '<span class="vhus-waiting">' . esc_html( $text ) . '</span>';
		$url = self::yes( $a['knap'] ) ? self::signup_url( $w ) : '';
		if ( $url ) {
			$out = '<p class="vhus-waiting-box">' . $out . ' <a class="vhus-button wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( 'Skriv dig op til ' . $w->name ) . '</a></p>';
		}
		return $out;
	}

	/**
	 * [vhus_vaerksteder]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function workshops( $atts ): string {
		$a = shortcode_atts(
			array(
				'venteliste' => 'ja',
				'kontakt'    => 'nej',
			),
			$atts,
			'vhus_vaerksteder'
		);
		self::style();
		$counts = self::waiting_counts();
		$items  = '';
		foreach ( Workshops::all() as $w ) {
			$url   = Workshops::url( $w );
			$name  = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $w->name ) . '</a>' : esc_html( $w->name );
			$extra = array();
			if ( self::yes( $a['venteliste'] ) ) {
				$n       = $counts[ (int) $w->id ]['waiting'] ?? 0;
				$extra[] = 0 === $n ? 'ingen venteliste' : sprintf( '%d på venteliste', $n );
			}
			if ( self::yes( $a['kontakt'] ) ) {
				$p = self::contact_person( $w );
				if ( $p ) {
					$extra[] = 'kontakt: ' . $p['name'];
				}
			}
			$items .= '<li>' . $name . ( $extra ? ' <span class="vhus-meta">(' . esc_html( implode( ', ', $extra ) ) . ')</span>' : '' ) . '</li>';
		}
		return '<ul class="vhus-workshops">' . $items . '</ul>';
	}

	/**
	 * The public contact for a workshop: name from the linked member (or the user), e-mail from the
	 * workshop's public e-mail, else the member's public e-mail. Never the private member e-mail.
	 *
	 * @return array{name:string, email:string, member_id:int, user_id:int}|null
	 */
	private static function contact_person( object $w ): ?array {
		if ( ! $w->contact_user_id ) {
			return null;
		}
		$u = get_userdata( (int) $w->contact_user_id );
		if ( ! $u ) {
			return null;
		}
		$m = Users::member_for_user( (int) $u->ID );
		return array(
			'name'      => $m ? $m->name : $u->display_name,
			'email'     => '' !== (string) $w->public_email ? $w->public_email : ( $m ? (string) $m->public_email : '' ),
			'member_id' => $m ? (int) $m->id : 0,
			'user_id'   => (int) $u->ID,
		);
	}

	/**
	 * [vhus_kontakter]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function contacts( $atts ): string {
		global $wpdb;
		$a = shortcode_atts( array( 'vis' => 'alle' ), $atts, 'vhus_kontakter' );
		self::style();

		$people = array(); // key => name, title, rank, email, workshops[] (name => email).
		if ( 'kontakter' !== $a['vis'] ) {
			$board = $wpdb->get_results( 'SELECT id, name, board_title, public_email FROM ' . DB::t( 'members' ) . " WHERE board_title <> ''" );
			foreach ( $board as $m ) {
				$people[ 'm' . $m->id ] = array(
					'name'      => $m->name,
					'title'     => $m->board_title,
					'rank'      => (int) array_search( $m->board_title, Members::BOARD_TITLES, true ),
					'email'     => (string) $m->public_email,
					'workshops' => array(),
				);
			}
		}
		if ( 'bestyrelse' !== $a['vis'] ) {
			foreach ( Workshops::all() as $i => $w ) {
				$p = self::contact_person( $w );
				if ( ! $p ) {
					continue;
				}
				$key = $p['member_id'] ? 'm' . $p['member_id'] : 'u' . $p['user_id'];
				if ( ! isset( $people[ $key ] ) ) {
					if ( 'alle' !== $a['vis'] && 'kontakter' !== $a['vis'] ) {
						continue;
					}
					$m              = $p['member_id'] ? Members::get( $p['member_id'] ) : null;
					$people[ $key ] = array(
						'name'      => $p['name'],
						'title'     => $m ? (string) $m->board_title : '',
						'rank'      => 100 + $i,
						'email'     => $m ? (string) $m->public_email : '',
						'workshops' => array(),
					);
				}
				$people[ $key ]['workshops'][ $w->name ] = $p['email'];
			}
		}
		if ( ! $people ) {
			return '';
		}
		uasort( $people, fn( $x, $y ) => $x['rank'] <=> $y['rank'] ?: strcoll( $x['name'], $y['name'] ) );

		$rows = '';
		foreach ( $people as $p ) {
			$roles = array();
			if ( '' !== $p['title'] ) {
				$roles[] = $p['title'];
			}
			if ( $p['workshops'] ) {
				$roles[] = 'kontaktperson for ' . self::join_and( array_keys( $p['workshops'] ) );
			}
			// One address per person: their own public e-mail, else the first workshop address.
			$email = sanitize_email( '' !== $p['email'] ? $p['email'] : (string) current( array_filter( $p['workshops'] ) ) );
			// antispambot() returns HTML entities for a sanitized address; escaping again would print them literally.
			$rows .= '<li class="vhus-person"><strong>' . esc_html( $p['name'] ) . '</strong>'
				. ( $roles ? ' <span class="vhus-meta">' . esc_html( ucfirst( implode( ', ', $roles ) ) ) . '</span>' : '' )
				. ( '' !== $email ? ' <a href="mailto:' . antispambot( $email ) . '">' . antispambot( $email ) . '</a>' : '' )
				. '</li>';
		}
		return '<ul class="vhus-contacts">' . $rows . '</ul>';
	}

	/**
	 * "a, b og c".
	 *
	 * @param string[] $items Items.
	 */
	private static function join_and( array $items ): string {
		$last = array_pop( $items );
		return $items ? implode( ', ', $items ) . ' og ' . $last : (string) $last;
	}
}
