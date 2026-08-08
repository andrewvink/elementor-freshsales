<?php
/**
 * Freshsales REST API client.
 *
 * @package Cornerstone\Elementor_Freshsales
 */

namespace Cornerstone\Elementor_Freshsales;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Minimal, dependency-free Freshsales API client built on the WordPress HTTP API.
 *
 * Security notes:
 *  - The account domain is validated against a fixed allowlist of Freshworks
 *    hosts, so the request URL can never be pointed at an arbitrary/internal host.
 *  - All requests use wp_safe_remote_request() (blocks private/loopback IPs) with
 *    redirects disabled, as extra SSRF protection.
 *  - The API key is only ever sent in the Authorization header; it is never
 *    echoed back to the browser or included in exception messages.
 */
class Freshsales_Handler {

	/**
	 * Hostnames that a Freshsales account is allowed to live on.
	 */
	const ALLOWED_DOMAIN_SUFFIXES = array( 'freshsales.io', 'myfreshworks.com', 'freshworks.com' );

	/**
	 * Prefix for the cached lead-source lookup transient.
	 */
	const SOURCE_TRANSIENT_PREFIX = 'csfs_source_';

	/**
	 * WordPress option keys (unprefixed — Elementor stores them as "elementor_" . KEY).
	 * Defined on this Pro-independent class so uninstall.php can reference them without
	 * loading Elementor Pro, keeping a single source of truth for the option names.
	 */
	const OPTION_API_KEY = 'cornerstone_freshsales_api_key';
	const OPTION_DOMAIN  = 'cornerstone_freshsales_domain';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string Fully-qualified API base, e.g. https://acme.freshsales.io/api/
	 */
	private $base_url;

	/**
	 * @param string $api_key Freshsales API key.
	 * @param string $domain  Freshsales account domain (e.g. acme.freshsales.io).
	 *
	 * @throws \Exception If the key is empty or the domain is missing/invalid.
	 */
	public function __construct( $api_key, $domain ) {
		$api_key = is_string( $api_key ) ? trim( $api_key ) : '';

		// Configuration errors use code 400 so run() surfaces them to admins (fail-loud).
		if ( '' === $api_key ) {
			throw new \Exception( esc_html__( 'is missing an API key. Add it under Elementor → Settings → Integrations.', 'elementor-freshsales' ), 400 );
		}

		$host = self::normalize_domain( $domain );

		if ( '' === $host ) {
			throw new \Exception( esc_html__( 'is missing a valid Freshsales domain. Add it under Elementor → Settings → Integrations.', 'elementor-freshsales' ), 400 );
		}

		$this->api_key  = $api_key;
		$this->base_url = 'https://' . $host . '/api/';
	}

	/**
	 * Normalise and validate a user-supplied Freshsales domain.
	 *
	 * Strips scheme/path/port, lower-cases, and requires the host to end with one
	 * of the allowed Freshworks suffixes. Returns '' if the domain is not allowed.
	 *
	 * @param string $domain Raw domain input.
	 * @return string Clean host or '' when invalid.
	 */
	public static function normalize_domain( $domain ) {
		if ( ! is_string( $domain ) ) {
			return '';
		}

		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '~^https?://~', '', $domain ); // Drop scheme.
		$domain = preg_replace( '~[/:?#].*$~', '', $domain );  // Drop path/port/query/fragment.
		$domain = trim( $domain, '.' );

		if ( '' === $domain || ! preg_match( '/^[a-z0-9.-]+$/', $domain ) ) {
			return '';
		}

		// Must be a real hostname and end with an allowed Freshworks suffix.
		if ( ! filter_var( 'http://' . $domain, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		foreach ( self::ALLOWED_DOMAIN_SUFFIXES as $suffix ) {
			$needle = '.' . $suffix;
			if ( strlen( $domain ) > strlen( $needle ) && substr( $domain, -strlen( $needle ) ) === $needle ) {
				return $domain;
			}
		}

		return '';
	}

	/**
	 * Perform an authenticated request against the Freshsales API.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint relative to /api/ (no leading slash).
	 * @param array|null $body     Optional JSON body.
	 * @return array Decoded response body (associative array).
	 *
	 * @throws \Exception On transport error or non-2xx response.
	 */
	private function request( $method, $endpoint, $body = null ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Token token=' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $this->base_url . $endpoint, $args );

		// Transport failure — code 0 marks it transient so run() can fail soft.
		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html__( 'could not reach the Freshsales API.', 'elementor-freshsales' ), 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// Non-2xx: carry the HTTP status as the exception code so callers can tell a
		// transient error (429/5xx) from a configuration/client error (4xx).
		if ( $code < 200 || $code >= 300 ) {
			throw new \Exception(
				sprintf(
					/* translators: %d: HTTP status code. */
					esc_html__( 'returned an error from Freshsales (HTTP %d).', 'elementor-freshsales' ),
					absint( $code )
				),
				$code
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Validate the credentials with a lightweight authenticated call.
	 *
	 * @return bool True when the key + domain authenticate successfully.
	 *
	 * @throws \Exception On failure.
	 */
	public function validate() {
		$this->request( 'GET', 'selector/lead_sources' );

		return true;
	}

	/**
	 * Create a lead.
	 *
	 * @param array $lead Lead fields (already sanitised).
	 * @return int The new lead's numeric ID, or 0 if not returned.
	 *
	 * @throws \Exception On API failure.
	 */
	public function create_lead( array $lead ) {
		$data = $this->request( 'POST', 'leads', array( 'lead' => $lead ) );

		return isset( $data['lead']['id'] ) ? (int) $data['lead']['id'] : 0;
	}

	/**
	 * Resolve a lead-source name (e.g. "Web Form") to its numeric ID.
	 *
	 * @param string $name Lead-source name.
	 * @return int Lead-source ID, or 0 when not found/unavailable.
	 */
	public function get_lead_source_id( $name ) {
		return $this->resolve_selector_id( 'selector/lead_sources', 'lead_sources', $name );
	}

	/**
	 * Resolve a campaign name (e.g. a utm_campaign value) to its numeric ID.
	 *
	 * Campaign is a reference field on the lead, so it needs an ID — a campaign that
	 * does not exist in the account cannot be created here and resolves to 0.
	 *
	 * @param string $name Campaign name.
	 * @return int Campaign ID, or 0 when not found/unavailable.
	 */
	public function get_campaign_id( $name ) {
		return $this->resolve_selector_id( 'selector/campaigns', 'campaigns', $name );
	}

	/**
	 * Look up a named record in one of Freshsales' selector endpoints.
	 *
	 * The result — including a 0 "not found" result — is cached per domain+endpoint+name
	 * to avoid an extra API round-trip on every submission. Never throws.
	 *
	 * @param string $endpoint Selector endpoint, relative to /api/.
	 * @param string $key      Key holding the list in the decoded response.
	 * @param string $name     Record name to match (case-insensitive).
	 * @return int Record ID, or 0 when not found/unavailable.
	 */
	private function resolve_selector_id( $endpoint, $key, $name ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return 0;
		}

		$cache_key = self::SOURCE_TRANSIENT_PREFIX . md5( $this->base_url . '|' . $endpoint . '|' . strtolower( $name ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		try {
			$data    = $this->request( 'GET', $endpoint );
			$records = ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) ? $data[ $key ] : array();

			$id = 0;
			foreach ( $records as $record ) {
				if ( isset( $record['name'], $record['id'] ) && 0 === strcasecmp( trim( (string) $record['name'] ), $name ) ) {
					$id = (int) $record['id'];
					break;
				}
			}

			// Only cache a result derived from a successful API response — caching an
			// exception-path 0 would suppress the real record for the whole TTL.
			set_transient( $cache_key, $id, 12 * HOUR_IN_SECONDS );

			return $id;
		} catch ( \Exception $e ) {
			// Transient/auth/network failure: do not cache; retry on the next submission.
			return 0;
		}
	}

	/**
	 * Attach a note to a lead. Never throws — notes are best-effort.
	 *
	 * @param int    $lead_id     Target lead ID.
	 * @param string $description Note text.
	 * @return void
	 */
	public function add_note( $lead_id, $description ) {
		$lead_id     = (int) $lead_id;
		$description = trim( (string) $description );

		if ( $lead_id <= 0 || '' === $description ) {
			return;
		}

		try {
			$this->request(
				'POST',
				'notes',
				array(
					'note' => array(
						'description'     => $description,
						'targetable_type' => 'Lead',
						'targetable_id'   => $lead_id,
					),
				)
			);
		} catch ( \Exception $e ) {
			// Swallow — a failed note must never fail the lead creation.
			return;
		}
	}
}
