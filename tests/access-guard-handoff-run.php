<?php
/**
 * Dependency-free tests for the Access Guard external-event handoff (0.2.6).
 *
 * Fixtures are synthetic (example.test, documentation IP ranges).
 */

require_once __DIR__ . '/../src/Autoloader.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress-stub/' );
}

$GLOBALS['argent_handoff_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param callable $callback Filter callback.
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['argent_handoff_filters'][ $hook_name ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['argent_handoff_filters'][ $hook_name ] );

		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * @return bool
	 */
	function has_filter( string $hook_name ) {
		return ! empty( $GLOBALS['argent_handoff_filters'][ $hook_name ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param mixed $value Filtered value.
	 * @param mixed ...$args Additional arguments.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['argent_handoff_filters'][ $hook_name ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				list( $callback, $accepted ) = $entry;
				$value = call_user_func_array( $callback, array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
			}
		}

		return $value;
	}
}

use ArgentSentinel\WordPress\Autoloader;
use ArgentSentinel\WordPress\Events\Event;
use ArgentSentinel\WordPress\Events\EventQueue;
use ArgentSentinel\WordPress\Events\EventType;
use ArgentSentinel\WordPress\Integrations\AccessGuardHandoff;

Autoloader::register( __DIR__ . '/../src' );

final class HandoffFailure extends RuntimeException {
}

final class FakeQueue implements EventQueue {
	/** @var array<string,array<string,mixed>> */
	public $rows = array();

	/** @var bool */
	public $fail = false;

	/** @var bool */
	public $throw = false;

	public function insert( Event $event ): bool {
		if ( $this->throw ) {
			throw new RuntimeException( 'Simulated database exception.' );
		}
		if ( $this->fail || isset( $this->rows[ $event->uuid() ] ) ) {
			return false;
		}
		$this->rows[ $event->uuid() ] = $event->toDatabaseRow();

		return true;
	}

	public function hasEvent( string $event_uuid ): bool {
		return isset( $this->rows[ strtolower( $event_uuid ) ] );
	}
}

/**
 * @param mixed $expected Expected.
 * @param mixed $actual   Actual.
 */
function handoff_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new HandoffFailure( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
	}
}

function handoff_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new HandoffFailure( $message );
	}
}

/**
 * Same shape as ArgentSentinelBridge::externalEvent() in Access Guard.
 *
 * @return array<string,mixed>
 */
function access_guard_event( array $overrides = array() ): array {
	return array_replace_recursive(
		array(
			'event_uuid'  => '6f0c1d6e-2b0b-4c84-9a59-0c5f2b0e4d11',
			'occurred_at' => '2026-10-26T10:00:00-04:00',
			'recorded_at' => '2026-10-26T14:00:01+00:00',
			'event_type'  => 'registration_abuse',
			'outcome'     => 'denied',
			'source_ip'   => '198.51.100.23',
			'user_agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/124.0',
			'request'     => array(
				'method'     => 'post',
				'path'       => '/wp-login.php?action=register',
				'request_id' => 'abcdef0123456789abcdef0123456789',
			),
			'metadata'    => array(
				'producer'              => 'argentwolf-access-guard',
				'producer_version'      => '0.1.4',
				'producer_build'        => 'development',
				'producer_schema'       => 'argentwolf.access_guard.abuse.v1',
				'site_url'              => 'https://www.example.test/',
				'score'                 => 9.5,
				'confidence'            => 'high',
				'rule_ids'              => array( 'AWAG_FORM_LT_2S', 'AWAG_CAMPAIGN_TEST' ),
				'policy_action'         => 'reject',
				'policy_reason'         => 'reject_score_reached',
				'registration_phase'    => 'submit',
				'fingerprint_signature' => str_repeat( 'a', 64 ),
				'fingerprint_ua_hash'   => str_repeat( 'b', 64 ),
				'fingerprint_headers'   => array( 'accept-language' => 'en-US' ),
			),
		),
		$overrides
	);
}

$producer = array(
	'producer'         => 'argentwolf-access-guard',
	'producer_version' => '0.1.4',
	'producer_build'   => 'development',
	'producer_schema'  => 'argentwolf.access_guard.abuse.v1',
);

try {
	handoff_same( 'registration_abuse', EventType::REGISTRATION_ABUSE, 'EventType defines registration_abuse.' );
	handoff_same( 'login_failed', EventType::LOGIN_FAILED, 'Existing EventType constants are preserved.' );

	$queue   = new FakeQueue();
	$handoff = new AccessGuardHandoff(
		$queue,
		'any-site-id',
		'https://www.example.test/',
		'example-host',
		'/var/lib/argent-sentinel/drop/wordpress/any-site-id/incoming',
		'0.2.6'
	);
	handoff_assert( ! has_filter( AccessGuardHandoff::EXTERNAL_EVENT_HOOK ), 'No handoff before registration.' );
	$handoff->register();
	handoff_assert( has_filter( AccessGuardHandoff::EXTERNAL_EVENT_HOOK ), 'Handoff filter is registered.' );
	handoff_same( 3, $GLOBALS['argent_handoff_filters'][ AccessGuardHandoff::EXTERNAL_EVENT_HOOK ][10][0][1], 'Handoff filter receives three arguments.' );
	handoff_assert( has_filter( AccessGuardHandoff::CONTEXT_HOOK ), 'Context filter is registered.' );

	// Context discovery.
	$context = apply_filters( AccessGuardHandoff::CONTEXT_HOOK, null );
	handoff_same(
		array(
			'site_id'           => 'any-site-id',
			'source_host'       => 'example-host',
			'drop_directory'    => '/var/lib/argent-sentinel/drop/wordpress/any-site-id/incoming',
			'connector_version' => '0.2.6',
			'ready'             => true,
		),
		$context,
		'Context hook exposes the connector identity and spool without duplicating configuration.'
	);
	$earlier = array( 'site_id' => 'earlier' );
	handoff_same( $earlier, $handoff->context( $earlier ), 'Context hook is first-wins.' );

	// Accepted handoff.
	$ack = apply_filters( AccessGuardHandoff::EXTERNAL_EVENT_HOOK, null, access_guard_event(), $producer );
	handoff_same(
		array(
			'status'    => 'accepted',
			'reference' => 'queue:6f0c1d6e-2b0b-4c84-9a59-0c5f2b0e4d11',
			'message'   => '',
		),
		$ack,
		'A valid Access Guard event is accepted with a queue reference.'
	);
	$row = $queue->rows['6f0c1d6e-2b0b-4c84-9a59-0c5f2b0e4d11'];
	handoff_same( 'registration_abuse', $row['event_type'], 'Queued event keeps registration_abuse.' );
	handoff_same( 'denied', $row['outcome'], 'Queued event keeps denied outcome.' );
	handoff_same( 'warning', $row['severity'], 'Denied registration abuse is warning severity.' );
	handoff_same( '2026-10-26 14:00:00', $row['occurred_at_utc'], 'occurred_at is normalized to UTC.' );
	handoff_same( 'any-site-id', $row['site_id'], 'Site identity comes from the connector, not the producer.' );
	handoff_same( 'example-host', $row['source_host'], 'Source host comes from the connector.' );
	handoff_same( '198.51.100.23', $row['source_ip'], 'Full source IP is queued.' );
	handoff_same( 'POST', $row['request_method'], 'Request method is normalized.' );
	handoff_same( '/wp-login.php', $row['request_path'], 'Request path drops the query string.' );
	handoff_same( 'queued', $row['export_state'], 'Event enters the normal export queue.' );
	$metadata = json_decode( $row['metadata_json'], true );
	handoff_same( 9.5, $metadata['score'], 'Score is metadata.' );
	handoff_same( 'high', $metadata['confidence'], 'Confidence is metadata.' );
	handoff_same( array( 'AWAG_FORM_LT_2S', 'AWAG_CAMPAIGN_TEST' ), $metadata['rule_ids'], 'Rule IDs are metadata.' );
	handoff_same( 'argentwolf.access_guard.abuse.v1', $metadata['producer_schema'], 'Producer schema is metadata.' );
	handoff_same( 'reject', $metadata['policy_action'], 'Policy outcome is metadata.' );
	handoff_same( 'abcdef0123456789abcdef0123456789', $metadata['request_id'], 'Request ID is carried for collector correlation.' );
	handoff_same( str_repeat( 'a', 64 ), $metadata['fingerprint_signature'], 'Fingerprint signature is retained.' );
	handoff_assert( ! array_key_exists( 'fingerprint_headers', $metadata ), 'Raw header material is removed by the metadata sanitizer.' );
	handoff_same( AccessGuardHandoff::EXTERNAL_EVENT_HOOK, $metadata['handoff'], 'Metadata records the handoff path.' );

	// First wins.
	$prior = array( 'status' => 'accepted', 'reference' => 'other', 'message' => '' );
	handoff_same( $prior, $handoff->handleExternalEvent( $prior, access_guard_event(), $producer ), 'An earlier provider result is returned unchanged.' );
	$count = count( $queue->rows );
	$handoff->handleExternalEvent( array( 'status' => 'error' ), access_guard_event( array( 'event_uuid' => '0b1e8f3a-1c2d-4e5f-8a9b-0c1d2e3f4a5b' ) ), $producer );
	handoff_same( $count, count( $queue->rows ), 'A non-null earlier result means nothing is queued.' );

	// Idempotent producer retry.
	$retry = $handoff->handleExternalEvent( null, access_guard_event(), $producer );
	handoff_same( 'accepted', $retry['status'], 'A retried, already-queued event is accepted idempotently.' );
	handoff_same( 'queue:6f0c1d6e-2b0b-4c84-9a59-0c5f2b0e4d11', $retry['reference'], 'Retry keeps the queue reference.' );

	// Observed outcome, producer context fills missing metadata.
	$observed = access_guard_event( array( 'event_uuid' => '1a2b3c4d-5e6f-4a1b-9c2d-3e4f5a6b7c8d', 'outcome' => 'observed', 'source_ip' => '2001:DB8::1' ) );
	unset( $observed['metadata']['producer_schema'], $observed['request']['request_id'] );
	$ack = $handoff->handleExternalEvent( null, $observed, $producer );
	handoff_same( 'accepted', $ack['status'], 'Observed registration evidence is accepted.' );
	$row      = $queue->rows['1a2b3c4d-5e6f-4a1b-9c2d-3e4f5a6b7c8d'];
	$metadata = json_decode( $row['metadata_json'], true );
	handoff_same( 'notice', $row['severity'], 'Observed registration abuse is notice severity.' );
	handoff_same( '2001:db8::1', $row['source_ip'], 'IPv6 source is normalized.' );
	handoff_same( 'argentwolf.access_guard.abuse.v1', $metadata['producer_schema'], 'Producer context fills missing schema.' );
	handoff_assert( ! array_key_exists( 'request_id', $metadata ), 'Absent request ID is not invented.' );

	// Rejections.
	$rejections = array(
		'unsupported type'   => access_guard_event( array( 'event_type' => 'login_failed' ) ),
		'unsupported outcome' => access_guard_event( array( 'outcome' => 'allowed' ) ),
		'invalid uuid'       => access_guard_event( array( 'event_uuid' => 'not-a-uuid' ) ),
		'missing source ip'  => access_guard_event( array( 'source_ip' => '' ) ),
		'invalid source ip'  => access_guard_event( array( 'source_ip' => '999.1.1.1' ) ),
		'invalid time'       => access_guard_event( array( 'occurred_at' => 'yesterday-ish' ) ),
	);
	foreach ( $rejections as $label => $event ) {
		$event['event_uuid'] = 'not-a-uuid' === $event['event_uuid'] ? 'not-a-uuid' : '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f';
		$ack = $handoff->handleExternalEvent( null, $event, $producer );
		handoff_same( 'rejected', $ack['status'], "Handoff rejects {$label}." );
		handoff_same( '', $ack['reference'], "Rejected {$label} has no queue reference." );
	}
	handoff_same( 'rejected', $handoff->handleExternalEvent( null, 'string', $producer )['status'], 'Non-array events are rejected.' );
	handoff_assert( ! isset( $queue->rows['2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f'] ), 'Rejected events are never queued.' );

	// Errors.
	$failing       = new FakeQueue();
	$failing->fail = true;
	$failing_handoff = new AccessGuardHandoff( $failing, 'site', 'https://www.example.test/', 'host', '/tmp/x', '0.2.6' );
	handoff_same( 'error', $failing_handoff->handleExternalEvent( null, access_guard_event(), $producer )['status'], 'Queue write failure returns an error acknowledgement.' );
	$throwing        = new FakeQueue();
	$throwing->throw = true;
	$throwing_handoff = new AccessGuardHandoff( $throwing, 'site', 'https://www.example.test/', 'host', '/tmp/x', '0.2.6' );
	handoff_same( 'error', $throwing_handoff->handleExternalEvent( null, access_guard_event(), $producer )['status'], 'Queue exceptions are contained as an error acknowledgement.' );

	fwrite( STDOUT, "Access Guard handoff tests passed.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
