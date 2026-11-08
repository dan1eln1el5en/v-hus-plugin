<?php
/**
 * Public sign-up form: shortcode [vhus_tilmelding].
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the form and handles the POST before any output, so a successful
 * sign-up can redirect (no double submit on reload) and errors keep the input.
 *
 * No nonce: the page may be cached for visitors and the form grants nothing.
 * Spam protection is a honeypot, a signed minimum fill time and a per-IP limit.
 */
class Signup {

	const MAX_PER_HOUR = 5;
	const MIN_SECONDS  = 3;

	/** Validation errors and the submitted values for re-display. */
	private static array $errors = array();
	private static array $input  = array();

	/** Hooks. */
	public static function init(): void {
		add_shortcode( 'vhus_tilmelding', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
	}

	/** Process a submitted form. */
	public static function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, see class docblock.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['vhus_signup'] ) ) {
			return;
		}
		$raw = wp_unslash( $_POST );
		// phpcs:enable

		$input = array(
			'name'         => sanitize_text_field( (string) ( $raw['vhus_name'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $raw['vhus_email'] ?? '' ) ),
			'phone_e164'   => sanitize_text_field( (string) ( $raw['vhus_phone'] ?? '' ) ),
			'address'      => sanitize_text_field( (string) ( $raw['vhus_address'] ?? '' ) ),
			'postcode'     => sanitize_text_field( (string) ( $raw['vhus_postcode'] ?? '' ) ),
			'city'         => sanitize_text_field( (string) ( $raw['vhus_city'] ?? '' ) ),
			'workshop_ids' => array_map( 'absint', (array) ( $raw['vhus_workshops'] ?? array() ) ),
			'consent'      => ! empty( $raw['vhus_consent'] ),
		);
		self::$input = $input;

		// Fields are prefixed: "name", "year" etc. are WordPress query vars and would 404 the page.
		// Spam checks fail silently with the success page, so bots learn nothing.
		$spam = '' !== (string) ( $raw['vhus_website'] ?? '' ) || ! self::valid_timestamp( (string) ( $raw['vhus_ts'] ?? '' ) );
		if ( $spam ) {
			self::redirect_done();
		}
		if ( self::rate_limited() ) {
			self::$errors[] = 'Der er sendt for mange tilmeldinger fra din forbindelse. Prøv igen om en time.';
			return;
		}

		self::$errors = self::validate( $input );
		if ( self::$errors ) {
			return;
		}

		self::count_attempt();
		Flow::sign_up( $input, $input['workshop_ids'] );
		self::redirect_done();
	}

	/**
	 * Validation messages for the input; empty when valid.
	 *
	 * @param array $in Sanitized input.
	 * @return string[]
	 */
	public static function validate( array $in ): array {
		$e = array();
		if ( '' === $in['name'] ) {
			$e[] = 'Skriv dit navn.';
		}
		if ( ! is_email( $in['email'] ) ) {
			$e[] = 'Skriv en gyldig e-mailadresse.';
		}
		if ( ! Util::is_plausible_phone( Util::phone_e164( $in['phone_e164'] ) ) ) {
			$e[] = 'Skriv et gyldigt telefonnummer (8 cifre, eller med landekode).';
		}
		$valid_ws = array_map( fn( $w ) => (int) $w->id, Workshops::all() );
		if ( ! array_intersect( $in['workshop_ids'], $valid_ws ) ) {
			$e[] = 'Vælg mindst ét værksted.';
		}
		if ( ! $in['consent'] ) {
			$e[] = 'Du skal acceptere, at vi gemmer dine oplysninger.';
		}
		return $e;
	}

	/** Redirect to the same page with ?tilmeldt=1. */
	private static function redirect_done(): void {
		$page = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
		$url  = remove_query_arg( 'tilmeldt', wp_get_referer() ? wp_get_referer() : $page );
		wp_safe_redirect( add_query_arg( 'tilmeldt', '1', $url ) );
		exit;
	}

	/** Signed "form rendered at" time. */
	private static function timestamp(): string {
		$t = (string) time();
		return $t . '.' . substr( wp_hash( 'vhus_signup_' . $t ), 0, 16 );
	}

	/** Form must be at least MIN_SECONDS and at most a day old. */
	private static function valid_timestamp( string $ts ): bool {
		$parts = explode( '.', $ts );
		if ( 2 !== count( $parts ) || ! hash_equals( substr( wp_hash( 'vhus_signup_' . $parts[0] ), 0, 16 ), $parts[1] ) ) {
			return false;
		}
		$age = time() - (int) $parts[0];
		return $age >= self::MIN_SECONDS && $age <= DAY_IN_SECONDS;
	}

	/** Transient key for the visitor's IP (hashed; the IP itself is not stored). */
	private static function rate_key(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return 'vhus_su_' . substr( wp_hash( $ip ), 0, 20 );
	}

	/** Too many sign-ups from this IP in the last hour? */
	private static function rate_limited(): bool {
		return (int) get_transient( self::rate_key() ) >= self::MAX_PER_HOUR;
	}

	/** Count a successful sign-up for the IP. */
	private static function count_attempt(): void {
		set_transient( self::rate_key(), (int) get_transient( self::rate_key() ) + 1, HOUR_IN_SECONDS );
	}

	/** URL of the privacy notice. */
	private static function privacy_url(): string {
		$url = (string) Settings::get( 'privacy_url' );
		return '' !== $url ? $url : get_privacy_policy_url();
	}

	/** Shortcode output. */
	public static function render(): string {
		wp_enqueue_style( 'vhus-signup', VHUS_URL . 'public/signup.css', array(), VHUS_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['tilmeldt'] ) && ! self::$errors ) {
			return '<div class="vhus-signup vhus-signup-done" role="status"><p><strong>Tak for din tilmelding.</strong></p><p>Du står nu på ventelisten og får en bekræftelse på e-mail. Værkstedskontakten kontakter dig, når der er en ledig plads.</p></div>';
		}

		$in      = self::$input + array(
			'name'         => '',
			'email'        => '',
			'phone_e164'   => '',
			'address'      => '',
			'postcode'     => '',
			'city'         => '',
			'workshop_ids' => array(),
			'consent'      => false,
		);
		$counts  = Workshops::seat_counts();
		$privacy = self::privacy_url();

		ob_start();
		?>
		<form class="vhus-signup" method="post" novalidate>
			<?php if ( self::$errors ) : ?>
				<div class="vhus-signup-errors" role="alert">
					<p><strong>Tilmeldingen er ikke sendt endnu:</strong></p>
					<ul>
						<?php foreach ( self::$errors as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<input type="hidden" name="vhus_signup" value="1">
			<input type="hidden" name="vhus_ts" value="<?php echo esc_attr( self::timestamp() ); ?>">
			<div class="vhus-hp" aria-hidden="true">
				<label>Lad dette felt være tomt <input type="text" name="vhus_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<fieldset>
				<legend>Hvilke værksteder vil du på venteliste til?</legend>
				<?php
				foreach ( Workshops::all() as $w ) :
					$waiting = (int) ( $counts[ (int) $w->id ]['waiting'] ?? 0 );
					?>
					<label class="vhus-check">
						<input type="checkbox" name="vhus_workshops[]" value="<?php echo (int) $w->id; ?>"<?php checked( in_array( (int) $w->id, $in['workshop_ids'], true ) ); ?>>
						<?php echo esc_html( $w->name ); ?>
						<span class="vhus-queue"><?php echo esc_html( 0 === $waiting ? 'ingen venteliste' : sprintf( '%d på venteliste', $waiting ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p>
				<label for="vhus-su-name">Navn</label>
				<input id="vhus-su-name" type="text" name="vhus_name" value="<?php echo esc_attr( $in['name'] ); ?>" autocomplete="name" required>
			</p>
			<p>
				<label for="vhus-su-email">E-mail</label>
				<input id="vhus-su-email" type="email" name="vhus_email" value="<?php echo esc_attr( $in['email'] ); ?>" autocomplete="email" required>
			</p>
			<p>
				<label for="vhus-su-phone">Mobilnummer</label>
				<input id="vhus-su-phone" type="tel" name="vhus_phone" value="<?php echo esc_attr( $in['phone_e164'] ); ?>" autocomplete="tel" required>
				<small>Bruges til at åbne porten, når du er blevet medlem.</small>
			</p>
			<p>
				<label for="vhus-su-address">Adresse <small>(valgfri)</small></label>
				<input id="vhus-su-address" type="text" name="vhus_address" value="<?php echo esc_attr( $in['address'] ); ?>" autocomplete="street-address">
			</p>
			<p class="vhus-row">
				<span>
					<label for="vhus-su-postcode">Postnummer</label>
					<input id="vhus-su-postcode" type="text" name="vhus_postcode" value="<?php echo esc_attr( $in['postcode'] ); ?>" autocomplete="postal-code" inputmode="numeric">
				</span>
				<span>
					<label for="vhus-su-city">By</label>
					<input id="vhus-su-city" type="text" name="vhus_city" value="<?php echo esc_attr( $in['city'] ); ?>" autocomplete="address-level2">
				</span>
			</p>

			<label class="vhus-check vhus-consent">
				<input type="checkbox" name="vhus_consent" value="1"<?php checked( $in['consent'] ); ?> required>
				<span>
					Jeg accepterer, at Værkstedshuset gemmer mine oplysninger for at administrere venteliste og medlemskab.
					<?php if ( $privacy ) : ?>
						<a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener">Læs hvordan vi behandler dine data</a>.
					<?php endif; ?>
				</span>
			</label>

			<p><button type="submit">Skriv mig op</button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
