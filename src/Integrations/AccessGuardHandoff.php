<?php

namespace ArgentSentinel\WordPress\Integrations;

use ArgentSentinel\WordPress\Events\Event;
use ArgentSentinel\WordPress\Events\EventQueue;
use ArgentSentinel\WordPress\Events\EventType;
use ArgentSentinel\WordPress\Events\Severity;
use ArgentSentinel\WordPress\Events\Uuid;
use ArgentSentinel\WordPress\Network\IpAddress;

/**
 * Versioned in-process handoff for ArgentWolf Access Guard.
 *
 * Access Guard owns the registration decision and per-site bridge enablement.
 * This connector only accepts already-normalized Sentinel WordPress events,
 * writes them to its normal durable queue, and lets the ordinary batch exporter
 * deliver them. Nothing here is site-specific: the queued event inherits this
 * installation's configured Site ID, site URL, and source host.
 *
 * Contract: wp-plugin-argentwolf-accessguard docs/SENTINEL-INTEGRATION.md.
 */
final class AccessGuardHandoff {
	public const EXTERNAL_EVENT_HOOK = 'argentwolf_access_guard_sentinel_external_event_v1';
	public const CONTEXT_HOOK        = 'argentwolf_access_guard_sentinel_context_v1';

	/**
	 * External event types accepted through the v1 hook.
	 *
	 * @var array<int,string>
	 */
	private const ACCEPTED_EVENT_TYPES = array( EventType::REGISTRATION_ABUSE );

	/**
	 * Outcome => queue severity.
	 *
	 * @var array<string,string>
	 */
	private const OUTCOME_SEVERITY = array(
		'denied'   => Severity::WARNING,
		'observed' => Severity::NOTICE,
	);

	/** @var EventQueue */
	private $queue;

	/** @var string */
	private $site_id;

	/** @var string */
	private $site_url;

	/** @var string */
	private $source_host;

	/** @var string */
	private $drop_directory;

	/** @var string */
	private $connector_version;

	public function __construct(
		EventQueue $queue,
		string $site_id,
		string $site_url,
		string $source_host,
		string $drop_directory,
		string $connector_version
	) {
		$this->queue             = $queue;
		$this->site_id           = $site_id;
		$this->site_url          = $site_url;
		$this->source_host       = $source_host;
		$this->drop_directory    = $drop_directory;
		$this->connector_version = $connector_version;
	}

	public function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( self::EXTERNAL_EVENT_HOOK, array( $this, 'handleExternalEvent' ), 10, 3 );
		add_filter( self::CONTEXT_HOOK, array( $this, 'context' ), 10, 1 );
	}

	/**
	 * Publishes this connector's effective Sentinel identity and spool path.
	 *
	 * First wins: an earlier provider's array is returned unchanged.
	 *
	 * @param mixed $current Value from earlier filter callbacks (null by default).
	 *
	 * @return mixed
	 */
	public function context( $current = null ) {
		if ( is_array( $current ) ) {
			return $current;
		}

		return array(
			'site_id'           => $this->site_id,
			'source_host'       => $this->source_host,
			'drop_directory'    => $this->drop_directory,
			'connector_version' => $this->connector_version,
			'ready'             => true,
		);
	}

	/**
	 * Queues one normalized external event and returns an acknowledgement.
	 *
	 * @param mixed $result   Acknowledgement from an earlier provider, or null.
	 * @param mixed $event    Normalized Sentinel WordPress event.
	 * @param mixed $producer Producer context (name, version, build, schema).
	 *
	 * @return mixed
	 */
	public function handleExternalEvent( $result, $event = array(), $producer = array() ) {
		if ( null !== $result ) {
			return $result;
		}

		try {
			if ( ! is_array( $event ) ) {
				return self::ack( 'rejected', '', 'Event must be an array.' );
			}

			$producer = is_array( $producer ) ? $producer : array();
			$event_type = strtolower( trim( (string) ( $event['event_type'] ?? '' ) ) );
			if ( ! in_array( $event_type, self::ACCEPTED_EVENT_TYPES, true ) ) {
				return self::ack( 'rejected', '', 'Unsupported external event type.' );
			}

			$outcome = strtolower( trim( (string) ( $event['outcome'] ?? '' ) ) );
			if ( ! isset( self::OUTCOME_SEVERITY[ $outcome ] ) ) {
				return self::ack( 'rejected', '', 'Unsupported external event outcome.' );
			}

			$event_uuid = strtolower( trim( (string) ( $event['event_uuid'] ?? '' ) ) );
			if ( ! Uuid::isValidV4( $event_uuid ) ) {
				return self::ack( 'rejected', '', 'External event requires a UUIDv4 event_uuid.' );
			}

			$source_ip = IpAddress::normalize( (string) ( $event['source_ip'] ?? '' ) );
			if ( null === $source_ip ) {
				return self::ack( 'rejected', '', 'External event requires a valid source_ip.' );
			}

			$occurred_at = self::utcTimestamp( $event['occurred_at'] ?? null );
			if ( null === $occurred_at ) {
				return self::ack( 'rejected', '', 'External event requires a valid occurred_at timestamp.' );
			}

			$request  = isset( $event['request'] ) && is_array( $event['request'] ) ? $event['request'] : array();
			$metadata = $this->metadata( $event, $producer, $request );

			$queued = Event::create(
				$event_type,
				self::OUTCOME_SEVERITY[ $outcome ],
				$outcome,
				$this->site_id,
				$this->site_url,
				$this->source_host,
				array(
					'event_uuid'      => $event_uuid,
					'occurred_at_utc' => $occurred_at,
					'recorded_at_utc' => gmdate( 'Y-m-d H:i:s' ),
					'source_ip'       => $source_ip,
					'user_agent'      => $event['user_agent'] ?? null,
					'request_method'  => isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : null,
					'request_path'    => $request['path'] ?? null,
					'metadata'        => $metadata,
				)
			);

			if ( $this->queue->insert( $queued ) ) {
				return self::ack( 'accepted', 'queue:' . $event_uuid, '' );
			}

			// The queue enforces event_uuid uniqueness; a producer retry of an
			// already-queued event is an idempotent success.
			if ( $this->queue->hasEvent( $event_uuid ) ) {
				return self::ack( 'accepted', 'queue:' . $event_uuid, 'Event was already queued.' );
			}

			return self::ack( 'error', '', 'Event could not be written to the Argent Sentinel queue.' );
		} catch ( \Throwable $throwable ) {
			// Delivery problems must never affect the producer's own decision.
			return self::ack( 'error', '', 'Argent Sentinel connector could not queue the event.' );
		}
	}

	/**
	 * @param array<string,mixed> $event    External event.
	 * @param array<string,mixed> $producer Producer context.
	 * @param array<string,mixed> $request  Request block.
	 *
	 * @return array<string,mixed>
	 */
	private function metadata( array $event, array $producer, array $request ): array {
		$metadata = isset( $event['metadata'] ) && is_array( $event['metadata'] ) ? $event['metadata'] : array();

		foreach ( array( 'producer', 'producer_version', 'producer_build', 'producer_schema' ) as $key ) {
			if ( ! isset( $metadata[ $key ] ) && isset( $producer[ $key ] ) && is_scalar( $producer[ $key ] ) ) {
				$metadata[ $key ] = (string) $producer[ $key ];
			}
		}

		$request_id = isset( $request['request_id'] ) ? trim( (string) $request['request_id'] ) : '';
		if ( 1 === preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $request_id ) ) {
			$metadata['request_id'] = $request_id;
		} else {
			unset( $metadata['request_id'] );
		}

		$metadata['handoff'] = self::EXTERNAL_EVENT_HOOK;

		return $metadata;
	}

	/**
	 * @param mixed $value RFC 3339 / ISO 8601 timestamp.
	 */
	private static function utcTimestamp( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable( trim( $value ) );
		} catch ( \Exception $exception ) {
			return null;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @return array<string,string>
	 */
	private static function ack( string $status, string $reference, string $message ): array {
		return array(
			'status'    => $status,
			'reference' => $reference,
			'message'   => $message,
		);
	}
}
