<?php

require_once __DIR__ . '/../src/Autoloader.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress-stub/' );
}

$GLOBALS['argent_test_options']     = array();
$GLOBALS['argent_test_dbdelta_sql'] = array();
$GLOBALS['argent_test_actions']     = array();
$GLOBALS['argent_test_failed_option_writes'] = array();

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * @param string $sql Schema SQL.
	 */
	function dbDelta( string $sql ): array {
		$GLOBALS['argent_test_dbdelta_sql'][] = $sql;

		return array();
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default Default value.
	 *
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['argent_test_options'] )
			? $GLOBALS['argent_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param mixed $value Option value.
	 * @param mixed $autoload Autoload behavior.
	 */
	function add_option( string $name, $value, string $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $name, $GLOBALS['argent_test_options'] ) ) {
			return false;
		}

		$GLOBALS['argent_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param mixed $value Option value.
	 * @param mixed $autoload Autoload behavior.
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		if ( in_array( $name, $GLOBALS['argent_test_failed_option_writes'], true ) ) {
			return false;
		}

		$GLOBALS['argent_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		if ( ! array_key_exists( $name, $GLOBALS['argent_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['argent_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url(): string {
		return 'https://www.example.test/';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param mixed $value Filtered value.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param callable $callback Hook callback.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_arguments = 1 ): bool {
		$GLOBALS['argent_test_actions'][] = array(
			'hook_name'          => $hook_name,
			'callback'           => $callback,
			'priority'           => $priority,
			'accepted_arguments' => $accepted_arguments,
		);

		return true;
	}
}

use ArgentSentinel\WordPress\Abuse\AbuseEventSubscriber;
use ArgentSentinel\WordPress\Abuse\AccountIdentity;
use ArgentSentinel\WordPress\Abuse\AccountLookup;
use ArgentSentinel\WordPress\Activation;
use ArgentSentinel\WordPress\Autoloader;
use ArgentSentinel\WordPress\Database\Schema;
use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Events\Event;
use ArgentSentinel\WordPress\Events\EventRecorder;
use ArgentSentinel\WordPress\Events\EventSink;
use ArgentSentinel\WordPress\Events\EventType;
use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Events\Severity;
use ArgentSentinel\WordPress\Events\Uuid;
use ArgentSentinel\WordPress\Http\RequestContextFactory;
use ArgentSentinel\WordPress\Network\CidrMatcher;
use ArgentSentinel\WordPress\Network\ClientIpResolver;
use ArgentSentinel\WordPress\Plugin;
use ArgentSentinel\WordPress\Privacy\EmailIdentity;
use ArgentSentinel\WordPress\Settings\Settings;

Autoloader::register( __DIR__ . '/../src' );

final class TestFailure extends RuntimeException {
}

final class TestSuite {
	/** @var int */
	private $assertions = 0;

	public function assert( bool $condition, string $message ): void {
		++$this->assertions;

		if ( ! $condition ) {
			throw new TestFailure( $message );
		}
	}

	/**
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 */
	public function same( $expected, $actual, string $message ): void {
		$this->assert(
			$expected === $actual,
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}

	public function assertions(): int {
		return $this->assertions;
	}
}

final class FakeWpdb {
	/** @var string */
	public $prefix = 'wp_';

	/** @var array<string,mixed>|null */
	public $inserted_row;

	/** @var bool */
	public $fail_insert = false;

	/** @var bool */
	public $throw_on_insert = false;

	/** @var bool */
	public $table_exists = true;

	/** @var bool */
	public $schema_complete = true;

	/** @var bool */
	public $composite_uuid_index = false;

	/** @var bool */
	public $prefix_uuid_index = false;

	/** @var bool */
	public $id_auto_increment = true;

	/** @var string */
	public $id_type = 'bigint(20) unsigned';

	/** @var int */
	public $event_uuid_length = 36;

	/** @var bool */
	public $event_uuid_nullable = false;

	/** @var int */
	public $queued_count = 0;

	/** @var string|null */
	public $oldest_queued_at;

	/**
	 * @param array<string,mixed> $row Database row.
	 *
	 * @return int|false
	 */
	public function insert( string $table, array $row ) {
		if ( $this->throw_on_insert ) {
			throw new RuntimeException( 'Simulated database exception.' );
		}

		if ( $this->fail_insert ) {
			return false;
		}

		$this->inserted_row = $row;

		return 1;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	/**
	 * @param mixed $value Placeholder value.
	 */
	public function prepare( string $query, ...$values ): string {
		foreach ( $values as $value ) {
			if ( false !== strpos( $query, '%i' ) ) {
				$replacement = '`' . str_replace( '`', '``', (string) $value ) . '`';
				$query       = preg_replace( '/%i/', $replacement, $query, 1 );
			} else {
				$replacement = "'" . addslashes( (string) $value ) . "'";
				$query       = preg_replace( '/%s/', $replacement, $query, 1 );
			}
		}

		return $query;
	}

	/**
	 * @return int|null
	 */
	public function get_var( string $query ) {
		if ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
			return $this->queued_count;
		}

		if ( false !== strpos( $query, 'SELECT MIN(recorded_at_utc)' ) ) {
			return $this->oldest_queued_at;
		}

		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
			return $this->table_exists ? $this->prefix . Schema::TABLE_SUFFIX : null;
		}

		return null;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_col( string $query ): array {
		if ( false !== strpos( $query, 'SHOW COLUMNS' ) && $this->schema_complete ) {
			return Schema::requiredColumns();
		}

		return array( 'id', 'event_uuid' );
	}

	public function get_row( string $query ): ?object {
		if ( false === strpos( $query, 'SHOW COLUMNS' ) || ! $this->schema_complete ) {
			return null;
		}

		if ( false !== strpos( $query, "'event_uuid'" ) ) {
			return (object) array(
				'Field' => 'event_uuid',
				'Type'  => 'char(' . $this->event_uuid_length . ')',
				'Null'  => $this->event_uuid_nullable ? 'YES' : 'NO',
			);
		}

		return (object) array(
			'Field' => 'id',
			'Type'  => $this->id_type,
			'Null'  => 'NO',
			'Key'   => 'PRI',
			'Extra' => $this->id_auto_increment ? 'auto_increment' : '',
		);
	}

	/**
	 * @return array<int,object>
	 */
	public function get_results( string $query ): array {
		if ( false !== strpos( $query, 'SHOW INDEX' ) && $this->schema_complete ) {
			$indexes = array(
				(object) array(
					'Column_name'  => 'event_uuid',
					'Non_unique'   => 0,
					'Seq_in_index' => 1,
					'Sub_part'     => $this->prefix_uuid_index ? 8 : null,
				),
			);

			if ( $this->composite_uuid_index ) {
				$indexes[] = (object) array(
					'Column_name'  => 'site_id',
					'Non_unique'   => 0,
					'Seq_in_index' => 2,
					'Sub_part'     => null,
				);
			}

			return $indexes;
		}

		return array();
	}
}

final class CapturingSink implements EventSink {
	/** @var array<int,array<string,mixed>> */
	public $events = array();

	/**
	 * @param array<string,mixed> $attributes Attributes.
	 */
	public function record( string $event_type, string $severity, string $outcome, array $attributes = array() ): bool {
		$this->events[] = compact( 'event_type', 'severity', 'outcome', 'attributes' );

		return true;
	}
}

final class FakeAccountLookup implements AccountLookup {
	/** @var array<string,AccountIdentity> */
	private $accounts;

	/** @var int */
	public $calls = 0;

	/**
	 * @param array<string,AccountIdentity> $accounts Accounts by submitted identifier.
	 */
	public function __construct( array $accounts = array() ) {
		$this->accounts = $accounts;
	}

	public function findByLoginIdentifier( string $identifier ): ?AccountIdentity {
		++$this->calls;

		return $this->accounts[ $identifier ] ?? null;
	}
}

final class ThrowingAccountLookup implements AccountLookup {
	public function findByLoginIdentifier( string $identifier ): ?AccountIdentity {
		throw new RuntimeException( 'Simulated account store failure.' );
	}
}

final class ThrowingError {
	public function get_error_codes(): array {
		throw new RuntimeException( 'Simulated authentication error failure.' );
	}
}

final class FakeError {
	/** @var array<int,string> */
	private $codes;

	/**
	 * @param array<int,string> $codes Error codes.
	 */
	public function __construct( array $codes ) {
		$this->codes = $codes;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_error_codes(): array {
		return $this->codes;
	}
}

$suite = new TestSuite();

try {
	$uuids = array();

	for ( $index = 0; $index < 200; ++$index ) {
		$uuid = Uuid::v4();
		$suite->assert( Uuid::isValidV4( $uuid ), 'Generated UUID is a valid UUIDv4.' );
		$uuids[ $uuid ] = true;
	}

	$suite->same( 200, count( $uuids ), 'Generated event UUIDs are unique.' );

	$cidr = new CidrMatcher();
	$suite->assert( $cidr->contains( '192.0.2.0/24', '192.0.2.42' ), 'IPv4 address matches its CIDR.' );
	$suite->assert( ! $cidr->contains( '192.0.2.0/24', '192.0.3.42' ), 'IPv4 address outside the CIDR does not match.' );
	$suite->assert( $cidr->contains( '2001:db8:1::/48', '2001:db8:1::42' ), 'IPv6 address matches its CIDR.' );
	$suite->assert(
		! $cidr->contains( '2001:db8:1::/48', '2001:db8:2::42' ),
		'IPv6 address outside the CIDR does not match.'
	);

	$direct = new ClientIpResolver( array() );
	$suite->same(
		'198.51.100.9',
		$direct->resolve(
			array(
				'REMOTE_ADDR'         => '198.51.100.9',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
			)
		),
		'An untrusted peer cannot spoof the client with X-Forwarded-For.'
	);

	$proxied = new ClientIpResolver( array( '10.0.0.0/8', '2001:db8:ffff::/48' ) );
	$suite->same(
		'203.0.113.8',
		$proxied->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.8, 10.0.0.4',
			)
		),
		'A trusted proxy chain resolves from right to left.'
	);
	$suite->same(
		'2001:db8:abcd::7',
		$proxied->resolve(
			array(
				'REMOTE_ADDR'          => '2001:db8:ffff::5',
				'HTTP_X_FORWARDED_FOR' => '2001:db8:abcd::7',
			)
		),
		'Trusted proxy handling supports IPv6.'
	);
	$suite->same(
		'203.0.113.8',
		$proxied->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 203.0.113.8',
			)
		),
		'Untrusted client attribution ignores attacker-controlled junk to its left.'
	);
	$suite->same(
		'10.0.0.5',
		$proxied->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.8, not-an-ip, 10.0.0.4',
			)
		),
		'Malformed data inside the trusted suffix falls back to the immediate peer.'
	);
	$suite->same(
		'203.0.113.8',
		$proxied->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => str_repeat( 'attacker-junk,', 1000 ) . '203.0.113.8',
			)
		),
		'An overlong attacker-controlled prefix cannot collapse attribution to the proxy.'
	);

	$request = ( new RequestContextFactory( $direct ) )->create(
		array(
			'REMOTE_ADDR'    => '198.51.100.10',
			'REQUEST_METHOD' => 'post',
			'REQUEST_URI'    => '/wp-login.php?redirect_to=%2Fsecret&nonce=do-not-store',
			'HTTP_USER_AGENT' => 'Test Agent',
		)
	);
	$suite->same( '/wp-login.php', $request->path(), 'Request capture strips query strings and nonces.' );
	$suite->same( 'POST', $request->method(), 'Request methods are normalized.' );

	$email_identity = new EmailIdentity( 'unit-test-secret' );
	$email           = $email_identity->describe( ' Person@Example.NET ' );
	$suite->same( 'example.net', $email['domain'], 'Email domains are normalized separately.' );
	$suite->assert(
		1 === preg_match( '/^[0-9a-f]{64}$/', $email['identifier'] ),
		'Email identifiers are HMAC-SHA256 values.'
	);
	$suite->assert( false === strpos( $email['identifier'], 'person' ), 'Email identifiers do not contain the address.' );
	$suite->same(
		$email['identifier'],
		$email_identity->describe( 'person@example.net' )['identifier'],
		'Email normalization produces stable identifiers.'
	);
	$suite->same(
		null,
		( new EmailIdentity( '' ) )->describe( 'person@example.net' )['identifier'],
		'A missing secret omits the identifier rather than using a weak fallback.'
	);

	$event = Event::create(
		EventType::LOGIN_FAILED,
		Severity::WARNING,
		'denied',
		'example-site',
		'https://example.test/',
		'nidhoggur',
		array(
			'source_ip'        => '2001:0db8::1',
			'metadata'         => array(
				'reason_category'   => 'invalid_credentials',
				'password'          => 'must-not-survive',
				'verification_token' => 'must-not-survive',
					'verificationToken'  => 'must-not-survive',
					'passwordHash'       => 'must-not-survive',
					str_repeat( 'x', 70 ) . '_apiKey' => 'must-not-survive',
					'email_address'      => 'private@example.net',
				'request_body'       => 'must-not-survive',
				'not_a_number'       => NAN,
				'nested'            => array(
					'api_key' => 'must-not-survive',
					'safe'    => 'kept',
				),
			),
			'request_path'     => '/wp-login.php',
			'request_method'   => 'POST',
		)
	);
	$event_data = $event->toArray();
	$suite->same( '2001:db8::1', $event_data['source_ip'], 'Event IP addresses are normalized.' );
	$suite->same( 6, $event_data['source_ip_version'], 'Event IP version is recorded.' );
	$suite->assert( ! isset( $event_data['metadata']['password'] ), 'Password metadata is redacted.' );
	$suite->assert( ! isset( $event_data['metadata']['verification_token'] ), 'Verification tokens are redacted.' );
	$suite->assert( ! isset( $event_data['metadata']['verificationToken'] ), 'CamelCase tokens are redacted.' );
	$suite->assert( ! isset( $event_data['metadata']['passwordHash'] ), 'CamelCase password hashes are redacted.' );
	$suite->assert(
		! isset( $event_data['metadata'][ str_repeat( 'x', 64 ) ] ),
		'Sensitive suffixes are checked before metadata keys are truncated.'
	);
	$suite->assert( ! isset( $event_data['metadata']['email_address'] ), 'Full email metadata is redacted.' );
	$suite->assert( ! isset( $event_data['metadata']['request_body'] ), 'Request bodies are redacted.' );
	$suite->assert( ! isset( $event_data['metadata']['not_a_number'] ), 'Non-finite numbers are discarded.' );
	$suite->assert( ! isset( $event_data['metadata']['nested']['api_key'] ), 'Nested API keys are redacted.' );
	$suite->same( 'kept', $event_data['metadata']['nested']['safe'], 'Non-sensitive nested metadata is retained.' );

	$large_metadata = array();

	for ( $index = 0; $index < 20; ++$index ) {
		$large_metadata[ 'group_' . $index ] = array_fill( 0, 20, 'bounded' );
	}

	$bounded_event = Event::create(
		EventType::LOGIN_FAILED,
		Severity::WARNING,
		'denied',
		'example-site',
		'https://example.test/',
		'nidhoggur',
		array( 'metadata' => $large_metadata )
	);
	$metadata_iterator = new RecursiveIteratorIterator(
		new RecursiveArrayIterator( $bounded_event->toArray()['metadata'] ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	$metadata_items = 0;

	foreach ( $metadata_iterator as $unused ) {
		++$metadata_items;
	}

	$suite->assert( $metadata_items <= 50, 'The metadata item budget is global across nested arrays.' );

	$escaped_metadata_event = Event::create(
		EventType::LOGIN_FAILED,
		Severity::WARNING,
		'denied',
		'example-site',
		'https://example.test/',
		'nidhoggur',
		array(
			'metadata' => array_fill( 0, 20, str_repeat( "\0", 1024 ) ),
		)
	);
	$suite->assert(
		strlen( $escaped_metadata_event->toDatabaseRow()['metadata_json'] ) <= 16384,
		'The serialized metadata byte cap includes JSON escape expansion.'
	);

	$multibyte_event = Event::create(
		EventType::LOGIN_FAILED,
		Severity::WARNING,
		'denied',
		'example-site',
		'https://example.test/',
		'nidhoggur',
		array( 'user_agent' => str_repeat( 'a', 511 ) . 'é' )
	);
	$suite->assert(
		false !== json_encode( $multibyte_event->toDatabaseRow() ),
		'Byte limits do not split a UTF-8 code point.'
	);

	$wpdb       = new FakeWpdb();
	$repository = new QueueRepository( $wpdb );
	$suite->assert( $repository->insert( $event ), 'Queue insertion reports success.' );
	$suite->same( $event->uuid(), $wpdb->inserted_row['event_uuid'], 'Queue insertion preserves the event UUID.' );
	$suite->same( 'queued', $wpdb->inserted_row['export_state'], 'New events enter the queued state.' );
	$suite->assert(
		false === strpos( $wpdb->inserted_row['metadata_json'], 'must-not-survive' ),
		'Serialized metadata remains redacted.'
	);

	$wpdb->fail_insert = true;
	$suite->assert( ! $repository->insert( $event ), 'Queue insertion surfaces database failure.' );
	$wpdb->fail_insert = false;

	$previous_server = $_SERVER;
	$_SERVER         = array(
		'REMOTE_ADDR'     => '198.51.100.20',
		'REQUEST_METHOD'  => 'POST',
		'REQUEST_URI'     => '/wp-login.php?nonce=secret',
		'HTTP_USER_AGENT' => 'Recorder Test',
	);
	$recorder        = new EventRecorder(
		$repository,
		new RequestContextFactory( new ClientIpResolver( array() ) ),
		$email_identity,
		'example-site',
		'https://example.test/',
		'nidhoggur'
	);
	$suite->assert(
		$recorder->record(
			EventType::LOGIN_UNKNOWN_ACCOUNT,
			Severity::WARNING,
			'denied',
			array(
				'email'    => 'Ghost@Example.NET',
				'metadata' => array( 'identifier_kind' => 'email' ),
			)
		),
		'The event recorder inserts a privacy-transformed event.'
	);
	$serialized_row = json_encode( $wpdb->inserted_row );
	$suite->same( 'example.net', $wpdb->inserted_row['email_domain'], 'The recorder stores the normalized email domain.' );
	$suite->assert(
		false === stripos( $serialized_row, 'ghost@' ),
		'The recorder never persists the full email address.'
	);
	$suite->same( '/wp-login.php', $wpdb->inserted_row['request_path'], 'The recorder persists a query-free path.' );

	$suite->assert(
		$recorder->record(
			EventType::LOGIN_FAILED,
			Severity::WARNING,
			'denied',
			array( 'request_path' => '/verify/account?token=must-not-survive' )
		),
		'An explicit request path override still records the event.'
	);
	$suite->same(
		'/verify/account',
		$wpdb->inserted_row['request_path'],
		'Explicit request paths are centrally stripped of query strings.'
	);
	$suite->assert(
		false === strpos( json_encode( $wpdb->inserted_row ), 'must-not-survive' ),
		'Explicit request-path queries cannot leak tokens into the queue.'
	);

	$suite->assert(
		$recorder->record(
			EventType::COMMENT_MARKED_SPAM,
			Severity::WARNING,
			'detected',
			array(
				'source_ip'      => '203.0.113.30',
				'user_agent'     => null,
				'request_method' => null,
				'request_path'   => null,
			)
		),
		'Explicitly suppressed request context still records the event.'
	);
	$suite->same( null, $wpdb->inserted_row['user_agent'], 'Explicit null suppresses the current user agent.' );
	$suite->same( null, $wpdb->inserted_row['request_path'], 'Explicit null suppresses the current request path.' );
	$wpdb->throw_on_insert = true;
	$suite->assert(
		! $recorder->record( EventType::LOGIN_FAILED, Severity::WARNING, 'denied' ),
		'Recorder exceptions fail open instead of reaching the authentication request.'
	);
	$wpdb->throw_on_insert = false;
	$_SERVER = $previous_server;

	$known_account = new AccountIdentity( 12, 'known-user', 'known@example.net' );
	$sink          = new CapturingSink();
	$account_lookup = new FakeAccountLookup( array( 'known-user' => $known_account ) );
	$subscriber    = new AbuseEventSubscriber(
		$sink,
		$account_lookup,
		$email_identity
	);
	$subscriber->onLoginFailed( 'known-user', new FakeError( array( 'incorrect_password' ) ) );
	$suite->same( EventType::LOGIN_FAILED, $sink->events[0]['event_type'], 'Known-account failures use login_failed.' );
	$suite->same(
		12,
		$sink->events[0]['attributes']['wordpress_user_id'],
		'Known-account failures retain the WordPress user ID.'
	);
	$suite->same(
		'known-user',
		$sink->events[0]['attributes']['username'],
		'Known-account failures retain the canonical username.'
	);

	$subscriber->onLoginFailed( 'ghost@example.net', new FakeError( array( 'invalid_email' ) ) );
	$suite->same(
		EventType::LOGIN_UNKNOWN_ACCOUNT,
		$sink->events[1]['event_type'],
		'Unknown email attempts use login_unknown_account.'
	);
	$suite->same(
		'ghost@example.net',
		$sink->events[1]['attributes']['email'],
		'Unknown emails are passed only to the privacy transformer.'
	);
	$suite->assert(
		! isset( $sink->events[1]['attributes']['username'] ),
		'Unknown email addresses are never placed in the username field.'
	);

	$subscriber->onLoginFailed( 'ghost-user', new FakeError( array( 'invalid_username' ) ) );
	$suite->same(
		EventType::LOGIN_UNKNOWN_ACCOUNT,
		$sink->events[2]['event_type'],
		'Unknown username attempts use login_unknown_account.'
	);
	$suite->assert(
		1 === preg_match( '/^[0-9a-f]{64}$/', $sink->events[2]['attributes']['metadata']['login_identifier'] ),
		'Unknown usernames are represented by an opaque HMAC identifier.'
	);
	$suite->assert(
		! isset( $sink->events[2]['attributes']['username'] ),
		'Unknown submitted usernames are not stored verbatim.'
	);
	$suite->same(
		1,
		$account_lookup->calls,
		'Explicit unknown-account errors do not trigger redundant account lookups.'
	);
	$events_before_verification_failure = count( $sink->events );
	$subscriber->onLoginFailed( 'known-user', new FakeError( array( 'argent_sentinel_unverified' ) ) );
	$suite->same(
		$events_before_verification_failure,
		count( $sink->events ),
		'Future verification login blocks are excluded from generic failed-login capture.'
	);

	$generic_unknown_sink = new CapturingSink();
	$generic_unknown      = new AbuseEventSubscriber(
		$generic_unknown_sink,
		new FakeAccountLookup(),
		$email_identity
	);
	$generic_unknown->onLoginFailed( 'unresolved-user', new FakeError( array( 'authentication_failed' ) ) );
	$suite->same(
		EventType::LOGIN_UNKNOWN_ACCOUNT,
		$generic_unknown_sink->events[0]['event_type'],
		'A successful lookup returning no account produces unknown-account evidence.'
	);
	$suite->same(
		'not_found',
		$generic_unknown_sink->events[0]['attributes']['metadata']['account_resolution'],
		'Generic unknown-account evidence records a bounded lookup result.'
	);

	$lookup_failure_sink = new CapturingSink();
	$lookup_failure      = new AbuseEventSubscriber(
		$lookup_failure_sink,
		new ThrowingAccountLookup(),
		$email_identity
	);
	$lookup_failure->onLoginFailed( 'indeterminate-user', new FakeError( array( 'authentication_failed' ) ) );
	$suite->same(
		EventType::LOGIN_FAILED,
		$lookup_failure_sink->events[0]['event_type'],
		'An account-store failure does not create false unknown-account evidence.'
	);
	$suite->same(
		'unavailable',
		$lookup_failure_sink->events[0]['attributes']['metadata']['account_resolution'],
		'Account-store failures are represented by a bounded resolution category.'
	);

	$events_before_throwing_error = count( $sink->events );
	$subscriber->onLoginFailed( 'known-user', new ThrowingError() );
	$suite->same(
		$events_before_throwing_error,
		count( $sink->events ),
		'An authentication-error observer exception fails open without emitting misleading evidence.'
	);

	$comment = (object) array(
		'comment_ID'        => 44,
		'comment_author_IP' => '203.0.113.44',
		'comment_type'      => 'comment',
		'user_id'           => 0,
	);
	$subscriber->onCommentStatusTransition( 'spam', 'unapproved', $comment );
	$suite->same(
		EventType::COMMENT_MARKED_SPAM,
		$sink->events[3]['event_type'],
		'Spam transitions produce comment_marked_spam.'
	);
	$suite->same(
		'203.0.113.44',
		$sink->events[3]['attributes']['source_ip'],
		'Spam events use the stored comment author IP, not the moderator request IP.'
	);
	$suite->same(
		null,
		$sink->events[3]['attributes']['user_agent'],
		'Later moderation events suppress the moderator user agent.'
	);
	$suite->same(
		null,
		$sink->events[3]['attributes']['request_path'],
		'Later moderation events suppress the moderator request path.'
	);

	$subscriber->onCommentStatusTransition( 'approved', 'spam', $comment );
	$suite->same( 4, count( $sink->events ), 'Transitions away from spam are ignored.' );
	$subscriber->onCommentStatusTransition( 'spam', 'spam', $comment );
	$suite->same( 4, count( $sink->events ), 'Repeated spam status notifications are ignored.' );

	$inserted_spam                   = clone $comment;
	$inserted_spam->comment_ID       = 45;
	$inserted_spam->comment_approved = 'spam';
	$subscriber->onCommentInserted( 45, $inserted_spam );
	$suite->same(
		EventType::COMMENT_MARKED_SPAM,
		$sink->events[4]['event_type'],
		'Comments classified as spam during insertion are captured.'
	);
	$suite->same(
		'new',
		$sink->events[4]['attributes']['metadata']['previous_status'],
		'Inserted spam records new as its previous status.'
	);
	$suite->same(
		null,
		$sink->events[4]['attributes']['request_path'],
		'Initial spam insertion also omits ambiguous request context.'
	);

	$inserted_ham                   = clone $comment;
	$inserted_ham->comment_ID       = 46;
	$inserted_ham->comment_approved = '1';
	$subscriber->onCommentInserted( 46, $inserted_ham );
	$suite->same( 5, count( $sink->events ), 'Non-spam comment insertion is ignored.' );

	global $wpdb;
	$wpdb = new FakeWpdb();
	Activation::activate();
	$secret_after_first_activation = get_option( Settings::HMAC_SECRET_OPTION );
	$activated_at_first_activation = get_option( Activation::ACTIVATED_AT_OPTION );
	$schema_calls_after_activation = count( $GLOBALS['argent_test_dbdelta_sql'] );
	Activation::activate();

	$suite->same(
		Schema::VERSION,
		get_option( Schema::VERSION_OPTION ),
		'Activation records the installed schema version.'
	);
	$suite->same(
		$secret_after_first_activation,
		get_option( Settings::HMAC_SECRET_OPTION ),
		'Repeated activation does not rotate the HMAC secret.'
	);
	$suite->same(
		$activated_at_first_activation,
		get_option( Activation::ACTIVATED_AT_OPTION ),
		'Repeated activation preserves the original activation timestamp.'
	);
	$suite->same(
		$schema_calls_after_activation + 1,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'The idempotent schema migration may safely run on repeated activation.'
	);
	$suite->assert(
		false !== strpos( $GLOBALS['argent_test_dbdelta_sql'][0], 'UNIQUE KEY event_uuid (event_uuid)' ),
		'The queue schema enforces unique event UUIDs.'
	);
	$schema_calls_before_upgrade_check = count( $GLOBALS['argent_test_dbdelta_sql'] );
	Activation::maybeUpgrade();
	$suite->same(
		$schema_calls_before_upgrade_check,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'The upgrade check skips dbDelta when the schema version is current.'
	);
	delete_option( Settings::OPTION_NAME );
	delete_option( Settings::HMAC_SECRET_OPTION );
	$suite->assert(
		Activation::maybeUpgrade(),
		'A current schema repairs independently missing settings without rerunning its migration.'
	);
	$suite->assert(
		is_array( get_option( Settings::OPTION_NAME, false ) ),
		'Runtime bootstrap restores missing settings defaults.'
	);
	$repaired_hmac_secret = get_option( Settings::HMAC_SECRET_OPTION, '' );
	$suite->assert(
		is_string( $repaired_hmac_secret ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $repaired_hmac_secret ),
		'Runtime bootstrap restores a missing HMAC secret with secure randomness.'
	);
	$suite->same(
		$schema_calls_before_upgrade_check,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'Settings repair does not invoke dbDelta for a current schema.'
	);
	update_option( Settings::HMAC_SECRET_OPTION, 'too-short' );
	$suite->same(
		'',
		( new Settings() )->hmacSecret(),
		'A short configured HMAC secret is treated as unavailable.'
	);
	update_option( Settings::HMAC_SECRET_OPTION, $repaired_hmac_secret );
	update_option( Schema::VERSION_OPTION, Schema::VERSION + 1 );
	$schema_calls_before_downgrade_guard = count( $GLOBALS['argent_test_dbdelta_sql'] );
	$suite->assert(
		! Activation::maybeUpgrade(),
		'A plugin downgrade remains inactive against a newer queue schema.'
	);
	$suite->same(
		$schema_calls_before_downgrade_guard,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'A plugin downgrade never runs an older dbDelta migration.'
	);
	$downgrade_activation_rejected = false;

	try {
		Activation::activate();
	} catch ( RuntimeException $exception ) {
		$downgrade_activation_rejected = true;
	}

	$suite->assert( $downgrade_activation_rejected, 'Explicit activation rejects a newer installed schema.' );
	$suite->same(
		$schema_calls_before_downgrade_guard,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'Explicit downgrade activation leaves the newer schema untouched.'
	);
	update_option( Schema::VERSION_OPTION, Schema::VERSION );

	$failed_schema_database               = new FakeWpdb();
	$failed_schema_database->table_exists = false;
	unset( $GLOBALS['argent_test_options'][ Schema::VERSION_OPTION ] );
	$suite->assert(
		! ( new Schema() )->install( $failed_schema_database ),
		'Schema installation reports failure when the queue table is absent.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'A failed schema installation is not marked current.'
	);

	$partial_schema_database                  = new FakeWpdb();
	$partial_schema_database->schema_complete = false;
	$suite->assert(
		! ( new Schema() )->install( $partial_schema_database ),
		'Schema installation reports failure when required columns or indexes are absent.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'A partial schema installation is not marked current.'
	);

	$composite_index_database                       = new FakeWpdb();
	$composite_index_database->composite_uuid_index = true;
	$suite->assert(
		! ( new Schema() )->install( $composite_index_database ),
		'A composite index beginning with event_uuid does not satisfy UUID uniqueness verification.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'An invalid UUID index is not marked current.'
	);

	$prefix_index_database                    = new FakeWpdb();
	$prefix_index_database->prefix_uuid_index = true;
	$suite->assert(
		! ( new Schema() )->install( $prefix_index_database ),
		'A unique UUID prefix index does not satisfy full event UUID uniqueness.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'A UUID prefix index is not marked current.'
	);

	$non_incrementing_database                    = new FakeWpdb();
	$non_incrementing_database->id_auto_increment = false;
	$suite->assert(
		! ( new Schema() )->install( $non_incrementing_database ),
		'The queue is not marked current when its primary sequence cannot auto-increment.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'An unusable primary sequence is not marked current.'
	);

	$narrow_id_database          = new FakeWpdb();
	$narrow_id_database->id_type = 'tinyint unsigned';
	$suite->assert(
		! ( new Schema() )->install( $narrow_id_database ),
		'The queue is not marked current with an undersized sequence column.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'An undersized sequence column is not marked current.'
	);

	$short_uuid_database                    = new FakeWpdb();
	$short_uuid_database->event_uuid_length = 8;
	$suite->assert(
		! ( new Schema() )->install( $short_uuid_database ),
		'The queue is not marked current with a truncated event UUID column.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'A truncated event UUID column is not marked current.'
	);

	$nullable_uuid_database                      = new FakeWpdb();
	$nullable_uuid_database->event_uuid_nullable = true;
	$suite->assert(
		! ( new Schema() )->install( $nullable_uuid_database ),
		'The queue is not marked current when event UUIDs may be null.'
	);
	$suite->same(
		false,
		get_option( Schema::VERSION_OPTION, false ),
		'A nullable event UUID column is not marked current.'
	);

	$wpdb = new FakeWpdb();
	delete_option( Activation::RETRY_AFTER_OPTION );
	$GLOBALS['argent_test_failed_option_writes'] = array( Schema::VERSION_OPTION );
	$suite->assert(
		! Activation::maybeUpgrade(),
		'A schema-version write failure leaves the connector inactive.'
	);
	$GLOBALS['argent_test_failed_option_writes'] = array();
	$suite->assert(
		(int) get_option( Activation::RETRY_AFTER_OPTION, 0 ) > time(),
		'A schema-version write failure receives the same migration backoff.'
	);
	delete_option( Activation::RETRY_AFTER_OPTION );

	$wpdb               = new FakeWpdb();
	$wpdb->table_exists = false;
	delete_option( Activation::RETRY_AFTER_OPTION );
	$schema_calls_before_failed_upgrade = count( $GLOBALS['argent_test_dbdelta_sql'] );
	$suite->assert(
		! Activation::maybeUpgrade(),
		'A failed runtime migration disables the connector without breaking WordPress.'
	);
	$suite->assert(
		(int) get_option( Activation::RETRY_AFTER_OPTION, 0 ) > time(),
		'A failed runtime migration schedules a bounded retry delay.'
	);
	$schema_calls_after_failed_upgrade = count( $GLOBALS['argent_test_dbdelta_sql'] );
	$suite->same(
		$schema_calls_before_failed_upgrade + 1,
		$schema_calls_after_failed_upgrade,
		'A failed runtime migration attempts the schema once.'
	);
	$suite->assert(
		! Activation::maybeUpgrade(),
		'The connector remains fail-open during the migration retry interval.'
	);
	$suite->same(
		$schema_calls_after_failed_upgrade,
		count( $GLOBALS['argent_test_dbdelta_sql'] ),
		'Migration backoff prevents dbDelta from running on every request.'
	);

	$wpdb = new FakeWpdb();
	delete_option( Activation::RETRY_AFTER_OPTION );
	( new Schema() )->install( $wpdb );
	$wpdb->queued_count     = 7;
	$wpdb->oldest_queued_at = '2026-07-22 12:00:00';
	$diagnostic_settings    = get_option( Settings::OPTION_NAME, array() );
	$diagnostic_settings['trusted_proxy_cidrs'] = '10.0.0.0/8, 2001:db8::/32';
	$diagnostic_settings['drop_directory']      = sys_get_temp_dir();
	update_option( Settings::OPTION_NAME, $diagnostic_settings );
	$effective_settings = new Settings();
	$suite->same(
		array( '10.0.0.0/8', '2001:db8::/32' ),
		$effective_settings->trustedProxyCidrs(),
		'Stored trusted proxy CIDRs accept bounded comma-separated configuration.'
	);
	$diagnostics = ( new Diagnostics( new QueueRepository( $wpdb ), $effective_settings ) )->snapshot();
	$suite->same( 7, $diagnostics['queued_count'], 'Diagnostics report the queued event count.' );
	$suite->same(
		'2026-07-22 12:00:00',
		$diagnostics['oldest_queued_at'],
		'Diagnostics report the oldest queued event.'
	);
	$suite->same( Schema::VERSION, $diagnostics['installed_schema_version'], 'Diagnostics report schema state.' );
	$suite->assert( $diagnostics['hmac_secret_configured'], 'Diagnostics report a valid HMAC secret.' );
	$suite->assert( $diagnostics['drop_directory_exists'], 'Diagnostics report drop-directory existence.' );
	$suite->assert( $diagnostics['drop_directory_writable'], 'Diagnostics report drop-directory writability.' );

	Plugin::boot();
	$registered_hook_names = array_column( $GLOBALS['argent_test_actions'], 'hook_name' );
	$suite->assert(
		in_array( 'wp_insert_comment', $registered_hook_names, true ),
		'The composition root registers initial comment-spam capture.'
	);
	$suite->assert(
		in_array( 'transition_comment_status', $registered_hook_names, true ),
		'The composition root registers later comment-spam capture.'
	);
	$suite->assert(
		in_array( 'wp_login_failed', $registered_hook_names, true ),
		'The composition root registers failed-login capture.'
	);
	$registered_hooks = array();

	foreach ( $GLOBALS['argent_test_actions'] as $registered_action ) {
		$registered_hooks[ $registered_action['hook_name'] ] = $registered_action;
	}

	$suite->same(
		2,
		$registered_hooks['wp_insert_comment']['accepted_arguments'],
		'Initial comment-spam capture receives the full WordPress hook context.'
	);
	$suite->same(
		3,
		$registered_hooks['transition_comment_status']['accepted_arguments'],
		'Comment-status capture receives new status, old status, and comment.'
	);
	$suite->same(
		2,
		$registered_hooks['wp_login_failed']['accepted_arguments'],
		'Failed-login capture receives both the submitted identifier and WP_Error.'
	);

	fwrite( STDOUT, "All {$suite->assertions()} assertions passed.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
