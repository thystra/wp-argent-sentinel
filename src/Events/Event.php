<?php

namespace ArgentSentinel\WordPress\Events;

use ArgentSentinel\WordPress\Network\IpAddress;

final class Event {
	/** @var array<string,mixed> */
	private $data;

	/**
	 * @param array<string,mixed> $attributes Optional event attributes.
	 */
	public static function create(
		string $event_type,
		string $severity,
		string $outcome,
		string $site_id,
		string $site_url,
		string $source_host,
		array $attributes = array()
	): self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $event_type ) ) {
			throw new \InvalidArgumentException( 'Invalid event type.' );
		}

		if ( ! in_array( $severity, Severity::values(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid event severity.' );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $outcome ) ) {
			throw new \InvalidArgumentException( 'Invalid event outcome.' );
		}

		$event_uuid = isset( $attributes['event_uuid'] ) ? strtolower( (string) $attributes['event_uuid'] ) : Uuid::v4();

		if ( ! Uuid::isValidV4( $event_uuid ) ) {
			throw new \InvalidArgumentException( 'Invalid event UUID.' );
		}

		$source_ip = isset( $attributes['source_ip'] ) ? IpAddress::normalize( (string) $attributes['source_ip'] ) : null;
		$metadata  = isset( $attributes['metadata'] ) && is_array( $attributes['metadata'] )
			? ( new MetadataSanitizer() )->sanitize( $attributes['metadata'] )
			: array();

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'event_uuid'         => $event_uuid,
			'batch_uuid'         => null,
			'occurred_at_utc'    => self::dateTime( $attributes['occurred_at_utc'] ?? $now ),
			'recorded_at_utc'    => self::dateTime( $attributes['recorded_at_utc'] ?? $now ),
			'site_id'            => self::bounded( $site_id, 191 ),
			'site_url'           => self::bounded( $site_url, 512 ),
			'source_host'        => self::bounded( $source_host, 191 ),
			'service'            => 'wordpress',
			'event_type'         => $event_type,
			'severity'           => $severity,
			'outcome'            => $outcome,
			'source_ip'          => $source_ip,
			'source_ip_version'  => null === $source_ip ? null : IpAddress::version( $source_ip ),
			'username'           => self::nullableBounded( $attributes['username'] ?? null, 191 ),
			'wordpress_user_id'  => self::positiveIntegerOrNull( $attributes['wordpress_user_id'] ?? null ),
			'email_domain'       => self::nullableBounded( $attributes['email_domain'] ?? null, 253 ),
			'email_identifier'   => self::hashOrNull( $attributes['email_identifier'] ?? null ),
			'user_agent'         => self::nullableBounded( $attributes['user_agent'] ?? null, 512 ),
			'request_method'     => self::nullableBounded( $attributes['request_method'] ?? null, 16 ),
			'request_path'       => self::requestPathOrNull( $attributes['request_path'] ?? null ),
			'metadata'           => $metadata,
			'export_state'       => 'queued',
			'exported_at_utc'    => null,
			'retry_count'        => 0,
			'last_export_error'  => null,
		);

		if ( '' === $data['site_id'] || '' === $data['site_url'] || '' === $data['source_host'] ) {
			throw new \InvalidArgumentException( 'Event source fields cannot be empty.' );
		}

		$event       = new self();
		$event->data = $data;

		return $event;
	}

	public function uuid(): string {
		return $this->data['event_uuid'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toDatabaseRow(): array {
		$row = $this->data;
		unset( $row['metadata'] );

		$metadata_json = json_encode(
			$this->data['metadata'],
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		$row['metadata_json'] = false === $metadata_json || '[]' === $metadata_json ? '{}' : $metadata_json;

		return $row;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * @param mixed $value Date/time value.
	 */
	private static function dateTime( $value ): string {
		$value = (string) $value;

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			throw new \InvalidArgumentException( 'Event dates must be UTC MySQL timestamps.' );
		}

		return $value;
	}

	private static function bounded( string $value, int $maximum_bytes ): string {
		$value = self::validUtf8( trim( $value ) );

		if ( strlen( $value ) <= $maximum_bytes ) {
			return $value;
		}

		$value = substr( $value, 0, $maximum_bytes );

		while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	/**
	 * @param mixed $value Nullable scalar value.
	 */
	private static function nullableBounded( $value, int $maximum_bytes ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		return self::bounded( (string) $value, $maximum_bytes );
	}

	/**
	 * @param mixed $value Request path or URI.
	 */
	private static function requestPathOrNull( $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		$path = parse_url( (string) $value, PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? self::bounded( $path, 1024 ) : null;
	}

	/**
	 * @param mixed $value Integer-like value.
	 */
	private static function positiveIntegerOrNull( $value ): ?int {
		$value = (int) $value;

		return $value > 0 ? $value : null;
	}

	/**
	 * @param mixed $value Hash value.
	 */
	private static function hashOrNull( $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
			return null;
		}

		return strtolower( $value );
	}

	private static function validUtf8( string $value ): string {
		$encoded = json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( false === $encoded ) {
			return '';
		}

		$decoded = json_decode( $encoded, true );

		return is_string( $decoded ) ? $decoded : '';
	}

	private function __construct() {
	}
}
