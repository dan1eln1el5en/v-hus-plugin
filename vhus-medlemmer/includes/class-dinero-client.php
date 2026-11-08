<?php
/**
 * Read-only Dinero API client for a personal integration.
 *
 * @package VHus
 */

namespace VHus;

defined( 'ABSPATH' ) || exit;

/**
 * Error from Dinero. `rate_limited` is true for HTTP 429, so the sync can stop and resume next run.
 */
class Dinero_Exception extends \RuntimeException {

	/** HTTP 429. */
	public bool $rate_limited = false;
}

/**
 * Credentials come only from wp-config.php constants, never from the database or the repo:
 *
 *   define( 'VHUS_DINERO_CLIENT_ID', '...' );
 *   define( 'VHUS_DINERO_CLIENT_SECRET', '...' );
 *   define( 'VHUS_DINERO_API_KEY', '...' );   // Generated in V-huset's Dinero organisation.
 *   define( 'VHUS_DINERO_ORG_ID', '123456' );
 *
 * Docs: https://developer.dinero.dk/documentation/personal-integration/
 */
class Dinero_Client {

	const TOKEN_URL = 'https://authz.dinero.dk/dineroapi/oauth/token';
	const API_BASE  = 'https://api.dinero.dk';
	const TOKEN_KEY = 'vhus_dinero_token';

	/** Personal integrations get 60 requests per minute; stay below it in one run. */
	const MAX_REQUESTS = 50;

	/** Requests made by this instance, token calls included. */
	public int $requests = 0;

	/** Names of constants that must be defined. */
	const CONSTANTS = array( 'VHUS_DINERO_CLIENT_ID', 'VHUS_DINERO_CLIENT_SECRET', 'VHUS_DINERO_API_KEY', 'VHUS_DINERO_ORG_ID' );

	/**
	 * Constants that are missing or empty.
	 *
	 * @return string[]
	 */
	public static function missing_constants(): array {
		return array_values( array_filter( self::CONSTANTS, fn( $c ) => ! defined( $c ) || '' === (string) constant( $c ) ) );
	}

	/** True when all credentials are present. */
	public static function is_configured(): bool {
		return ! self::missing_constants();
	}

	/** Token endpoint; overridable for tests with VHUS_DINERO_TOKEN_URL. */
	private static function token_url(): string {
		return defined( 'VHUS_DINERO_TOKEN_URL' ) ? (string) VHUS_DINERO_TOKEN_URL : self::TOKEN_URL;
	}

	/** API base; overridable for tests with VHUS_DINERO_API_BASE. */
	private static function api_base(): string {
		return rtrim( defined( 'VHUS_DINERO_API_BASE' ) ? (string) VHUS_DINERO_API_BASE : self::API_BASE, '/' );
	}

	/** Requests still allowed in this run. */
	public function budget_left(): int {
		return self::MAX_REQUESTS - $this->requests;
	}

	/**
	 * Access token, cached for slightly less than its lifetime.
	 *
	 * @throws Dinero_Exception When credentials are missing or Dinero refuses them.
	 */
	public function token( bool $refresh = false ): string {
		if ( ! $refresh ) {
			$cached = get_transient( self::TOKEN_KEY );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		if ( ! self::is_configured() ) {
			throw new Dinero_Exception( 'Dinero er ikke sat op: mangler ' . implode( ', ', self::missing_constants() ) . ' i wp-config.php.' );
		}
		++$this->requests;
		$res = wp_remote_post(
			self::token_url(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( VHUS_DINERO_CLIENT_ID . ':' . VHUS_DINERO_CLIENT_SECRET ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'password',
					'scope'      => 'read write',
					'username'   => VHUS_DINERO_API_KEY,
					'password'   => VHUS_DINERO_API_KEY,
				),
			)
		);
		$data = $this->decode( $res, 'login' );
		if ( empty( $data['access_token'] ) ) {
			throw new Dinero_Exception( 'Dinero returnerede intet adgangstoken.' );
		}
		$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 );
		set_transient( self::TOKEN_KEY, (string) $data['access_token'], $ttl );
		return (string) $data['access_token'];
	}

	/**
	 * GET /v1/{org}/{path}. Retries once with a fresh token on 401.
	 *
	 * @param string $path  e.g. 'invoices' or 'invoices/{guid}'.
	 * @param array  $query Query parameters.
	 * @return array Decoded JSON.
	 * @throws Dinero_Exception On HTTP or decode errors, or when the request budget is spent.
	 */
	public function get( string $path, array $query = array() ): array {
		$url = self::api_base() . '/v1/' . rawurlencode( (string) VHUS_DINERO_ORG_ID ) . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = $this->token( $attempt > 0 );
			if ( $this->budget_left() <= 0 ) {
				$e               = new Dinero_Exception( 'Grænsen for antal kald i én kørsel er nået; resten tages ved næste kørsel.' );
				$e->rate_limited = true;
				throw $e;
			}
			++$this->requests;
			$res = wp_remote_get(
				$url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);
			if ( 401 === (int) wp_remote_retrieve_response_code( $res ) && 0 === $attempt ) {
				delete_transient( self::TOKEN_KEY );
				continue;
			}
			return $this->decode( $res, $path );
		}
		throw new Dinero_Exception( 'Dinero afviste adgangstokenet.' );
	}

	/**
	 * All pages of a list endpoint.
	 *
	 * @param array $query Query; page and pageSize are set here.
	 * @return array[] Items from every page's Collection.
	 */
	public function get_all( string $path, array $query = array(), int $page_size = 500 ): array {
		$items = array();
		$page  = 0;
		do {
			$data  = $this->get( $path, $query + array( 'page' => (string) $page, 'pageSize' => (string) $page_size ) );
			$batch = is_array( $data['Collection'] ?? null ) ? $data['Collection'] : array();
			$items = array_merge( $items, $batch );
			$total = (int) ( $data['Pagination']['ResultWithoutFilter'] ?? 0 );
			++$page;
		} while ( count( $batch ) === $page_size && count( $items ) < $total );
		return $items;
	}

	/**
	 * Decode a response or throw a readable error. Error bodies are not logged in full:
	 * they may echo back personal data.
	 *
	 * @param array|\WP_Error $res  wp_remote_* result.
	 * @param string          $what For the message.
	 * @throws Dinero_Exception On any non-2xx response.
	 */
	private function decode( $res, string $what ): array {
		if ( is_wp_error( $res ) ) {
			throw new Dinero_Exception( sprintf( 'Kunne ikke kontakte Dinero (%s): %s', $what, $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code ) {
			$e               = new Dinero_Exception( 'Dinero siger, at der er sendt for mange kald (HTTP 429). Resten tages ved næste kørsel.' );
			$e->rate_limited = true;
			throw $e;
		}
		if ( $code < 200 || $code >= 300 ) {
			$hint = in_array( $code, array( 400, 401, 403 ), true ) ? ' Tjek nøglerne i wp-config.php og at abonnementet er Pro.' : '';
			throw new Dinero_Exception( sprintf( 'Dinero svarede HTTP %d på %s.%s', $code, $what, $hint ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			throw new Dinero_Exception( sprintf( 'Ugyldigt svar fra Dinero på %s.', $what ) );
		}
		return $data;
	}
}
