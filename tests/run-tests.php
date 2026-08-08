<?php
/**
 * Elementor Freshsales — test suite.
 *
 * Dependency-free by design, matching the plugin itself: no Composer, no PHPUnit. It boots a real
 * WordPress install so the code under test runs against the same WordPress APIs it uses in
 * production (sanitisers, magic-quotes handling, is_email, transients).
 *
 * Usage (from the plugin directory):
 *   php tests/run-tests.php
 *   php tests/run-tests.php --wp=/path/to/wordpress
 *
 * Exits 0 when everything passes, 1 otherwise, so it can gate a release.
 *
 * @package Cornerstone\Elementor_Freshsales
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 'Run this from the command line.' );
}

// ---------------------------------------------------------------------------
// Boot WordPress.
// ---------------------------------------------------------------------------

$wp_root = '';

foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--wp=' ) ) {
		$wp_root = rtrim( substr( $arg, 5 ), '/\\' );
	}
}

if ( '' === $wp_root ) {
	// Walk up from wp-content/plugins/<plugin>/tests to the WordPress root.
	$candidate = dirname( __DIR__, 4 );
	$wp_root   = is_file( $candidate . '/wp-load.php' ) ? $candidate : '';
}

if ( '' === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not locate wp-load.php. Pass --wp=/path/to/wordpress\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require $wp_root . '/wp-load.php';

if ( ! class_exists( '\Cornerstone\Elementor_Freshsales\Freshsales_Action' ) ) {
	fwrite( STDERR, "Freshsales_Action not loaded — is the plugin active and Elementor Pro running?\n" );
	exit( 1 );
}

use Cornerstone\Elementor_Freshsales\Freshsales_Action;
use Cornerstone\Elementor_Freshsales\Freshsales_Handler;

use const Cornerstone\Elementor_Freshsales\CAMPAIGN_COOKIE;
use const Cornerstone\Elementor_Freshsales\CAMPAIGN_VALUE_MAX;

// ---------------------------------------------------------------------------
// Tiny harness.
// ---------------------------------------------------------------------------

$tests  = array();
$passed = 0;
$failed = array();
$group  = '';

/**
 * Register a test case.
 *
 * @param string   $name Test name.
 * @param callable $fn   Test body.
 */
function test( $name, callable $fn ) {
	global $tests, $group;
	$tests[] = array( 'group' => $group, 'name' => $name, 'fn' => $fn );
}

/**
 * Start a named group of tests.
 *
 * @param string $name Group name.
 */
function group( $name ) {
	global $group;
	$group = $name;
}

/**
 * Assert two values are identical (===).
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Context for the failure.
 */
function is_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new Exception(
			trim( $message . "\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) )
		);
	}
}

/**
 * Assert a condition holds.
 *
 * @param bool   $condition Condition.
 * @param string $message   Context for the failure.
 */
function is_true( $condition, $message = '' ) {
	if ( true !== (bool) $condition ) {
		throw new Exception( $message ? $message : 'expected true' );
	}
}

/**
 * Call a private/protected method.
 *
 * @param object $object Target instance.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function call_private( $object, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );

	return $ref->invokeArgs( $object, $args );
}

/**
 * Stand-in for Elementor Pro's Form_Record — the code under test only ever asks it
 * for submitted fields and form settings.
 */
class Test_Record {

	/** @var array */
	private $fields;

	/** @var array */
	private $settings;

	public function __construct( array $fields, array $settings ) {
		$this->fields   = $fields;
		$this->settings = $settings;
	}

	public function get( $key ) {
		return 'fields' === $key ? $this->fields : null;
	}

	public function get_form_settings( $key ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
	}
}

/**
 * Build a record from simple field id => value pairs.
 *
 * @param array $values   Field id => submitted value.
 * @param array $map      Mapping rows.
 * @param array $settings Extra form settings.
 * @return Test_Record
 */
function record( array $values, array $map, array $settings = array() ) {
	$fields = array();
	foreach ( $values as $id => $value ) {
		$fields[ $id ] = array( 'value' => $value );
	}

	return new Test_Record( $fields, array_merge( array( 'freshsales_fields_map' => $map ), $settings ) );
}

/**
 * Set the campaign cookie the way WordPress delivers it (magic-quoted).
 *
 * @param mixed $data Array to encode, or a raw string to use verbatim.
 */
function set_campaign_cookie( $data ) {
	$_COOKIE[ CAMPAIGN_COOKIE ] = wp_slash( is_string( $data ) ? $data : wp_json_encode( $data ) );
}

$action = new Freshsales_Action();

// ---------------------------------------------------------------------------
// Field mapping.
// ---------------------------------------------------------------------------

group( 'field mapping' );

test( 'maps a single field', function () use ( $action ) {
	$r = record(
		array( 'name' => 'Ada' ),
		array( array( 'local_id' => 'name', 'remote_id' => 'first_name' ) )
	);
	is_same( 'Ada', call_private( $action, 'get_mapped_value', array( $r, 'first_name' ) ) );
} );

test( 'returns empty for an unmapped field', function () use ( $action ) {
	$r = record( array( 'name' => 'Ada' ), array() );
	is_same( '', call_private( $action, 'get_mapped_value', array( $r, 'first_name' ) ) );
} );

test( 'survives a missing or malformed map', function () use ( $action ) {
	$r = new Test_Record( array(), array( 'freshsales_fields_map' => 'not an array' ) );
	is_same( '', call_private( $action, 'get_mapped_value', array( $r, 'first_name' ) ) );
} );

test( 'concatenates several sources into one field, in panel order', function () use ( $action ) {
	$r = record(
		array( 'message' => 'I need a quote', 'product' => 'Garment Steamer' ),
		array(
			array( 'local_id' => 'message', 'remote_id' => 'notes' ),
			array( 'local_id' => 'product', 'remote_id' => 'notes' ),
		)
	);
	is_same( "I need a quote\nGarment Steamer", call_private( $action, 'get_mapped_value', array( $r, 'notes' ) ) );
} );

test( 'an empty first source does not suppress later ones', function () use ( $action ) {
	$r = record(
		array( 'message' => '', 'product' => 'Garment Steamer' ),
		array(
			array( 'local_id' => 'message', 'remote_id' => 'notes' ),
			array( 'local_id' => 'product', 'remote_id' => 'notes' ),
		)
	);
	is_same( 'Garment Steamer', call_private( $action, 'get_mapped_value', array( $r, 'notes' ) ) );
} );

test( 'resolves the Form Name virtual source', function () use ( $action ) {
	$r = record(
		array(),
		array( array( 'local_id' => '__form_name', 'remote_id' => 'cf_notes' ) ),
		array( 'form_name' => 'Ask a question' )
	);
	is_same( 'Ask a question', call_private( $action, 'get_mapped_value', array( $r, 'cf_notes' ) ) );
} );

test( 'a form with no Form Name contributes nothing', function () use ( $action ) {
	$r = record( array(), array( array( 'local_id' => '__form_name', 'remote_id' => 'cf_notes' ) ) );
	is_same( '', call_private( $action, 'get_mapped_value', array( $r, 'cf_notes' ) ) );
} );

// ---------------------------------------------------------------------------
// Lead payload.
// ---------------------------------------------------------------------------

group( 'lead payload' );

test( 'nests company and validates email', function () use ( $action ) {
	$r = record(
		array( 'c' => 'Acme', 'e' => 'ada@example.com' ),
		array(
			array( 'local_id' => 'c', 'remote_id' => 'company_name' ),
			array( 'local_id' => 'e', 'remote_id' => 'email' ),
		)
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_same( array( 'name' => 'Acme' ), $lead['company'] );
	is_same( 'ada@example.com', $lead['email'] );
} );

test( 'drops an invalid email rather than sending it', function () use ( $action ) {
	$r = record(
		array( 'e' => 'definitely not an email' ),
		array( array( 'local_id' => 'e', 'remote_id' => 'email' ) )
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_true( ! isset( $lead['email'] ), 'invalid email must not be sent' );
} );

test( 'routes cf_* fields into custom_field', function () use ( $action ) {
	$r = record(
		array( 'n' => 'note text' ),
		array( array( 'local_id' => 'n', 'remote_id' => 'cf_notes' ) )
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_same( array( 'cf_notes' => 'note text' ), $lead['custom_field'] );
} );

test( 'keeps line breaks in custom note fields', function () use ( $action ) {
	$r = record(
		array( 'a' => 'one', 'b' => 'two' ),
		array(
			array( 'local_id' => 'a', 'remote_id' => 'cf_notes' ),
			array( 'local_id' => 'b', 'remote_id' => 'cf_notes' ),
		)
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_same( "one\ntwo", $lead['custom_field']['cf_notes'] );
} );

test( 'collapses a doubled-up single-line field to a space', function () use ( $action ) {
	$r = record(
		array( 'a' => 'John', 'b' => 'Smith' ),
		array(
			array( 'local_id' => 'a', 'remote_id' => 'first_name' ),
			array( 'local_id' => 'b', 'remote_id' => 'first_name' ),
		)
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_same( 'John Smith', $lead['first_name'] );
} );

test( 'strips markup from submitted values', function () use ( $action ) {
	$r = record(
		array( 'n' => '<script>alert(1)</script>Ada' ),
		array( array( 'local_id' => 'n', 'remote_id' => 'first_name' ) )
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );
	is_true( false === strpos( $lead['first_name'], '<' ), 'no raw markup may reach the payload' );
} );

// ---------------------------------------------------------------------------
// Campaign capture — the cookie is attacker-controlled, so this is security surface.
// ---------------------------------------------------------------------------

group( 'campaign capture' );

test( 'reads a normal cookie', function () use ( $action ) {
	set_campaign_cookie(
		array(
			'utm_source'   => 'google',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'Spring Sale',
			'landing_page' => 'https://example.test/steamers',
		)
	);
	$data = call_private( $action, 'get_campaign_data' );
	is_same( 'google', $data['utm_source'] );
	is_same( 'Spring Sale', $data['utm_campaign'] );
	is_same( 'https://example.test/steamers', $data['landing_page'] );
} );

test( 'returns empty when no cookie is present', function () use ( $action ) {
	unset( $_COOKIE[ CAMPAIGN_COOKIE ] );
	is_same( array(), call_private( $action, 'get_campaign_data' ) );
} );

test( 'ignores a non-JSON cookie', function () use ( $action ) {
	set_campaign_cookie( 'not json at all' );
	is_same( array(), call_private( $action, 'get_campaign_data' ) );
} );

test( 'ignores JSON that is not an object', function () use ( $action ) {
	foreach ( array( '"a string"', '12345', 'true', 'null', '[1,2,3]' ) as $payload ) {
		set_campaign_cookie( $payload );
		is_same( array(), call_private( $action, 'get_campaign_data' ), 'payload: ' . $payload );
	}
} );

test( 'rejects an oversized cookie before decoding it', function () use ( $action ) {
	set_campaign_cookie( str_repeat( 'x', 5000 ) );
	is_same( array(), call_private( $action, 'get_campaign_data' ) );
} );

test( 'drops keys that are not on the allowlist', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_source' => 'google', 'evil_key' => 'payload', 'campaign_id' => 999 ) );
	$data = call_private( $action, 'get_campaign_data' );
	is_same( array( 'utm_source' => 'google' ), $data );
} );

test( 'drops non-scalar values', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_source' => array( 'nested' => 'array' ), 'utm_medium' => 'cpc' ) );
	$data = call_private( $action, 'get_campaign_data' );
	is_true( ! isset( $data['utm_source'] ), 'array values must be dropped' );
	is_same( 'cpc', $data['utm_medium'] );
} );

test( 'caps every value at CAMPAIGN_VALUE_MAX', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_term' => str_repeat( 'A', 400 ) ) );
	$data = call_private( $action, 'get_campaign_data' );
	is_same( CAMPAIGN_VALUE_MAX, strlen( $data['utm_term'] ) );
} );

test( 'strips markup and control characters', function () use ( $action ) {
	set_campaign_cookie(
		array(
			'utm_source' => '<script>alert(1)</script>',
			'utm_medium' => "cpc\r\nX-Injected: yes",
		)
	);
	$data = call_private( $action, 'get_campaign_data' );
	is_true( ! isset( $data['utm_source'] ), 'a pure-markup value sanitises to empty and is dropped' );
	is_true( ! preg_match( '/[\r\n]/', implode( '', $data ) ), 'no CR/LF may survive' );
	is_true( ! preg_match( '/[<>]/', implode( '', $data ) ), 'no raw angle brackets may survive' );
} );

test( 'ignores an array cookie without a PHP warning', function () use ( $action ) {
	// Cookie: csfs_campaign[a]=1 makes $_COOKIE hold an array, not a string.
	$_COOKIE[ CAMPAIGN_COOKIE ] = array( 'a' => '1' );

	set_error_handler(
		function ( $severity, $message ) {
			throw new ErrorException( $message, 0, $severity );
		}
	);

	try {
		is_same( array(), call_private( $action, 'get_campaign_data' ) );
	} finally {
		restore_error_handler();
	}
} );

test( 'truncates on characters so multi-byte values are not destroyed', function () use ( $action ) {
	// A 3-byte character cut mid-sequence makes sanitize_text_field() bin the whole value.
	set_campaign_cookie( array( 'utm_term' => str_repeat( '日', 300 ) ) );

	$data = call_private( $action, 'get_campaign_data' );

	is_true( isset( $data['utm_term'] ), 'the value must survive truncation' );
	is_same( CAMPAIGN_VALUE_MAX, mb_strlen( $data['utm_term'] ), 'capped at 200 characters' );
} );

test( 'survives a quote-heavy cookie through WordPress unslashing', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_campaign' => 'He said "hello"', 'utm_term' => 'back\\slash' ) );
	$data = call_private( $action, 'get_campaign_data' );
	is_same( 'He said "hello"', $data['utm_campaign'] );
} );

group( 'campaign precedence' );

test( 'an explicit mapping is never overwritten', function () use ( $action ) {
	$lead = array( 'medium' => 'MAPPED', 'email' => 'a@b.com' );
	call_private( $action, 'apply_campaign_fields', array( &$lead, array( 'utm_medium' => 'cpc' ) ) );
	is_same( 'MAPPED', $lead['medium'] );
} );

test( 'fills a field the mapping left empty', function () use ( $action ) {
	$lead = array( 'email' => 'a@b.com' );
	call_private( $action, 'apply_campaign_fields', array( &$lead, array( 'utm_medium' => 'cpc', 'utm_term' => 'steamer' ) ) );
	is_same( 'cpc', $lead['medium'] );
	is_same( 'steamer', $lead['keyword'] );
} );

test( 'adds nothing when there is no campaign data', function () use ( $action ) {
	$lead = array( 'email' => 'a@b.com' );
	call_private( $action, 'apply_campaign_fields', array( &$lead, array() ) );
	is_same( array( 'email' => 'a@b.com' ), $lead );
} );

test( 'builds no note without campaign data', function () use ( $action ) {
	is_same( '', call_private( $action, 'build_campaign_note', array( array() ) ) );
} );

test( 'builds a readable note', function () use ( $action ) {
	$note = call_private( $action, 'build_campaign_note', array( array( 'utm_source' => 'google', 'utm_medium' => 'cpc' ) ) );
	is_true( false !== strpos( $note, 'Source: google' ), 'note lists the source' );
	is_true( false !== strpos( $note, 'Medium: cpc' ), 'note lists the medium' );
} );

// ---------------------------------------------------------------------------
// End-to-end: a real submission carrying campaign data.
// ---------------------------------------------------------------------------

group( 'UTM end to end' );

/**
 * Build the lead exactly as run() does: map the form, then layer capture on top.
 *
 * @param Freshsales_Action $action Action instance.
 * @param Test_Record       $record Submission.
 * @param array             $form   Form settings (for the capture switch).
 * @return array
 */
function lead_for( $action, $record, array $form = array() ) {
	$lead    = call_private( $action, 'build_lead', array( $record ) );
	$capture = isset( $form['freshsales_capture_campaign'] ) ? $form['freshsales_capture_campaign'] : 'yes';
	$data    = ( 'yes' === $capture ) ? call_private( $action, 'get_campaign_data' ) : array();

	call_private( $action, 'apply_campaign_fields', array( &$lead, $data ) );

	return $lead;
}

test( 'a Google Ads click fills medium and keyword on the lead', function () use ( $action ) {
	set_campaign_cookie(
		array(
			'utm_source'   => 'google',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'Garment Steamers',
			'utm_term'     => 'commercial garment steamer',
			'gclid'        => 'Cj0KCQjw_abc123',
			'landing_page' => 'https://example.test/product/garment-steamer',
		)
	);

	$r = record(
		array( 'email' => 'buyer@example.com', 'message' => 'Please quote 50 units' ),
		array(
			array( 'local_id' => 'email', 'remote_id' => 'email' ),
			array( 'local_id' => 'message', 'remote_id' => 'cf_notes' ),
		)
	);

	$lead = lead_for( $action, $r );

	is_same( 'cpc', $lead['medium'], 'utm_medium fills the lead Medium field' );
	is_same( 'commercial garment steamer', $lead['keyword'], 'utm_term fills the lead Keyword field' );
	is_same( 'buyer@example.com', $lead['email'] );
	is_same( 'Please quote 50 units', $lead['custom_field']['cf_notes'] );
} );

test( 'turning the switch off sends no campaign data', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_medium' => 'cpc', 'utm_term' => 'steamer' ) );

	$r    = record( array( 'email' => 'a@b.com' ), array( array( 'local_id' => 'email', 'remote_id' => 'email' ) ) );
	$lead = lead_for( $action, $r, array( 'freshsales_capture_campaign' => '' ) );

	is_true( ! isset( $lead['medium'] ), 'Medium must stay unset when capture is off' );
	is_true( ! isset( $lead['keyword'] ), 'Keyword must stay unset when capture is off' );
} );

test( 'an organic visit with no tags adds nothing', function () use ( $action ) {
	unset( $_COOKIE[ CAMPAIGN_COOKIE ] );

	$r    = record( array( 'email' => 'a@b.com' ), array( array( 'local_id' => 'email', 'remote_id' => 'email' ) ) );
	$lead = lead_for( $action, $r );

	is_same( array( 'email' => 'a@b.com' ), $lead, 'no blank campaign fields on an untagged visit' );
} );

test( 'the enquiry note and the campaign note stay separate', function () use ( $action ) {
	set_campaign_cookie( array( 'utm_source' => 'google', 'utm_medium' => 'cpc' ) );

	$r = record(
		array( 'message' => 'I need a quote', 'product' => 'Garment Steamer' ),
		array(
			array( 'local_id' => 'message', 'remote_id' => 'notes' ),
			array( 'local_id' => 'product', 'remote_id' => 'notes' ),
		)
	);

	$enquiry  = sanitize_textarea_field( call_private( $action, 'get_mapped_value', array( $r, 'notes' ) ) );
	$campaign = call_private( $action, 'build_campaign_note', array( call_private( $action, 'get_campaign_data' ) ) );

	is_same( "I need a quote\nGarment Steamer", $enquiry );
	is_true( false === strpos( $enquiry, 'google' ), 'campaign data must not leak into the enquiry note' );
	is_true( false !== strpos( $campaign, 'Source: google' ), 'campaign note carries the source' );
} );

test( 'the account custom fields are mappable', function () use ( $action ) {
	$r = record(
		array( 'p' => 'Garment Steamer', 't' => 'Quote request' ),
		array(
			array( 'local_id' => 'p', 'remote_id' => 'cf_product_enquiry' ),
			array( 'local_id' => 't', 'remote_id' => 'cf_enquiry_type' ),
		)
	);
	$lead = call_private( $action, 'build_lead', array( $r ) );

	is_same( 'Garment Steamer', $lead['custom_field']['cf_product_enquiry'] );
	is_same( 'Quote request', $lead['custom_field']['cf_enquiry_type'] );
} );

test( 'Medium and Keyword are no longer offered for manual mapping', function () {
	$ids = array_column( \Cornerstone\Elementor_Freshsales\get_remote_fields(), 'remote_id' );

	is_true( ! in_array( 'medium', $ids, true ), 'Medium must not appear in the dropdown' );
	is_true( ! in_array( 'keyword', $ids, true ), 'Keyword must not appear in the dropdown' );
} );

test( 'every offered field has a real home in the payload', function () use ( $action ) {
	// Guards against offering a field the account does not actually have.
	$known = array( 'first_name', 'last_name', 'email', 'mobile_number', 'company_name', 'notes' );

	foreach ( \Cornerstone\Elementor_Freshsales\get_remote_fields() as $field ) {
		$id = $field['remote_id'];
		is_true(
			in_array( $id, $known, true ) || 0 === strpos( $id, 'cf_' ),
			'unrecognised remote field offered in the dropdown: ' . $id
		);
	}
} );

// ---------------------------------------------------------------------------
// SSRF allowlist — the plugin's most important security boundary.
// ---------------------------------------------------------------------------

group( 'domain allowlist (SSRF)' );

test( 'accepts genuine Freshworks hosts', function () {
	foreach ( array( 'acme.freshsales.io', 'acme.myfreshworks.com', 'acme.freshworks.com' ) as $host ) {
		is_same( $host, Freshsales_Handler::normalize_domain( $host ), $host );
	}
} );

test( 'normalises scheme, case, path and port', function () {
	is_same( 'acme.freshsales.io', Freshsales_Handler::normalize_domain( 'HTTPS://ACME.Freshsales.IO/api/' ) );
	is_same( 'acme.freshsales.io', Freshsales_Handler::normalize_domain( '  acme.freshsales.io:8080  ' ) );
} );

test( 'rejects hosts outside the allowlist', function () {
	$bad = array(
		'evil.com',
		'freshsales.io',                       // bare suffix, no subdomain
		'acme.freshsales.io.evil.com',         // suffix in the middle
		'evil-freshsales.io',                  // missing dot boundary
		'127.0.0.1',
		'localhost',
		'169.254.169.254',                     // cloud metadata
		'acme.freshsales.io@evil.com',         // userinfo trick
		'',
		'   ',
	);
	foreach ( $bad as $host ) {
		is_same( '', Freshsales_Handler::normalize_domain( $host ), 'must reject: ' . var_export( $host, true ) );
	}
} );

test( 'rejects non-string input', function () {
	foreach ( array( null, 123, array(), true ) as $input ) {
		is_same( '', Freshsales_Handler::normalize_domain( $input ) );
	}
} );

group( 'selector cache' );

test( 'caches the whole list once, not one entry per looked-up name', function () {
	// A visitor-supplied utm_campaign must not be able to mint unbounded transients, and a
	// campaign created after a miss must not stay invisible for the whole TTL.
	$handler = new Freshsales_Handler( 'key', 'acme.freshsales.io' );
	$method  = new ReflectionMethod( $handler, 'resolve_selector_id' );
	$method->setAccessible( true );

	$prefix = Freshsales_Handler::SOURCE_TRANSIENT_PREFIX;
	$key    = $prefix . md5( 'https://acme.freshsales.io/api/|selector/campaigns' );

	set_transient( $key, array( 'spring sale' => 42 ), 60 );

	is_same( 42, $method->invoke( $handler, 'selector/campaigns', 'campaigns', 'Spring Sale' ), 'matches case-insensitively' );
	is_same( 42, $method->invoke( $handler, 'selector/campaigns', 'campaigns', '  spring sale  ' ), 'trims' );
	is_same( 0, $method->invoke( $handler, 'selector/campaigns', 'campaigns', 'Unknown Campaign' ), 'unknown name resolves to 0' );

	// The unknown lookup must not have written a second transient keyed by that name.
	is_same( false, get_transient( $prefix . md5( 'https://acme.freshsales.io/api/|selector/campaigns|unknown campaign' ) ), 'no per-name transient' );

	delete_transient( $key );
} );

test( 'ignores a stale value left by the old per-name cache scheme', function () {
	$handler = new Freshsales_Handler( 'key', 'acme.freshsales.io' );
	$method  = new ReflectionMethod( $handler, 'resolve_selector_id' );
	$method->setAccessible( true );

	$key = Freshsales_Handler::SOURCE_TRANSIENT_PREFIX . md5( 'https://acme.freshsales.io/api/|selector/lead_sources' );
	set_transient( $key, 180663, 60 ); // an int, as the previous scheme stored.

	// Must not treat the int as a map; with no network here it falls through to 0 rather than fatal.
	is_same( 0, $method->invoke( $handler, 'selector/lead_sources', 'lead_sources', 'Web Form' ) );

	delete_transient( $key );
} );

test( 'an empty name never hits the network', function () {
	$handler = new Freshsales_Handler( 'key', 'acme.freshsales.io' );
	$method  = new ReflectionMethod( $handler, 'resolve_selector_id' );
	$method->setAccessible( true );

	is_same( 0, $method->invoke( $handler, 'selector/campaigns', 'campaigns', '   ' ) );
} );

group( 'credential handling' );

test( 'refuses to build a client without an API key', function () {
	try {
		new Freshsales_Handler( '', 'acme.freshsales.io' );
	} catch ( Exception $e ) {
		is_same( 400, $e->getCode(), 'config errors must use code 400 so run() fails loud' );
		return;
	}
	throw new Exception( 'expected an exception for an empty API key' );
} );

test( 'refuses to build a client for a disallowed domain', function () {
	try {
		new Freshsales_Handler( 'key', 'evil.com' );
	} catch ( Exception $e ) {
		is_same( 400, $e->getCode() );
		return;
	}
	throw new Exception( 'expected an exception for a disallowed domain' );
} );

test( 'never puts the API key in an exception message', function () {
	$secret = 'SuperSecretKey12345';
	try {
		new Freshsales_Handler( $secret, 'evil.com' );
	} catch ( Exception $e ) {
		is_true( false === strpos( $e->getMessage(), $secret ), 'the key must never appear in an error' );
	}
} );

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------

$current = null;

foreach ( $tests as $case ) {
	if ( $case['group'] !== $current ) {
		$current = $case['group'];
		echo "\n" . $current . "\n";
	}

	try {
		$case['fn']();
		++$passed;
		echo "  PASS  " . $case['name'] . "\n";
	} catch ( Throwable $e ) {
		$failed[] = $case['group'] . ' / ' . $case['name'] . "\n      " . $e->getMessage();
		echo "  FAIL  " . $case['name'] . "\n      " . $e->getMessage() . "\n";
	}
}

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed, %d total\n", $passed, count( $failed ), count( $tests ) );

exit( empty( $failed ) ? 0 : 1 );
