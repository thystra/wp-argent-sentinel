<?php

namespace ArgentSentinel\WordPress\Export;

use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Events\Uuid;
use ArgentSentinel\WordPress\Plugin;

final class BatchExporter {

	/** @var QueueRepository */
	private $queue;

	/** @var ExportSettings */
	private $settings;

	/** @var ExportLock */
	private $lock;

	/** @var string */
	private $site_id;

	/** @var string */
	private $site_url;

	/** @var string */
	private $source_host;

	public function __construct(
		QueueRepository $queue,
		ExportSettings $settings,
		ExportLock $lock,
		string $site_id,
		string $site_url,
		string $source_host
	) {
		$this->queue       = $queue;
		$this->settings    = $settings;
		$this->lock        = $lock;
		$this->site_id     = $site_id;
		$this->site_url    = $site_url;
		$this->source_host = $source_host;
	}

	public function exportOnce(): ExportResult {
		if ( ! $this->lock->acquire() ) {
			return ExportResult::locked();
		}

		try {
			return $this->exportLocked();
		} catch ( \Throwable $throwable ) {
			return ExportResult::failure( $this->safeError( $throwable->getMessage() ) );
		} finally {
			$this->lock->release();
		}
	}

	public function pruneOnce( int $limit = 1000 ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 86400 * $this->settings->localRetentionDays() ) );
		return $this->queue->pruneExportedBefore( $cutoff, $limit );
	}

	private function exportLocked(): ExportResult {
		$directory = $this->validatedDirectory();
		$rows      = $this->queue->queuedRows( $this->settings->maxEventsPerBatch() );

		if ( array() === $rows ) {
			return ExportResult::emptyQueue();
		}

		$batch_uuid = Uuid::v4();
		$created_at = gmdate( 'c' );
		$events     = array();
		$ids        = array();
		$max_bytes  = $this->settings->maxBatchBytes();

		foreach ( $rows as $row ) {
			$event = $this->rowToEvent( $row );
			$next  = $events;
			$next[] = $event;
			$encoded = $this->encodeEnvelope( $batch_uuid, $created_at, $next );

			if ( strlen( $encoded ) > $max_bytes ) {
				if ( array() === $events ) {
					$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
					if ( $id > 0 ) {
						$this->queue->markExportFailure( array( $id ), 'Single event exceeds configured maximum batch size.' );
					}
					return ExportResult::failure( 'Single event exceeds configured maximum batch size.' );
				}
				break;
			}

			$events = $next;
			$ids[]  = (int) $row['id'];
		}

		$payload    = $this->encodeEnvelope( $batch_uuid, $created_at, $events );
		$site_slug  = preg_replace( '/[^a-z0-9_-]+/i', '-', $this->site_id );
		$site_slug  = trim( (string) $site_slug, '-' );
		$site_slug  = '' === $site_slug ? 'wordpress-site' : strtolower( $site_slug );
		$timestamp  = gmdate( 'Ymd\THis\Z' );
		$filename   = sprintf( 'wordpress-%s-%s-%s.json', $site_slug, $timestamp, $batch_uuid );
		$final_path = $directory . DIRECTORY_SEPARATOR . $filename;
		$temp_path  = $directory . DIRECTORY_SEPARATOR . '.' . $filename . '.tmp-' . bin2hex( random_bytes( 8 ) );

		try {
			$this->writeAtomic( $temp_path, $final_path, $payload );
		} catch ( \Throwable $throwable ) {
			$this->queue->markExportFailure( $ids, $this->safeError( $throwable->getMessage() ) );
			throw $throwable;
		}

		$exported_at = gmdate( 'Y-m-d H:i:s' );
		if ( ! $this->queue->markExported( $ids, $batch_uuid, $exported_at ) ) {
			return ExportResult::partial(
				count( $ids ),
				$batch_uuid,
				$final_path,
				'Batch file was written, but one or more queue rows were not marked exported. Collector deduplication by event_uuid is required.'
			);
		}

		return ExportResult::success( count( $ids ), $batch_uuid, $final_path );
	}

	private function validatedDirectory(): string {
		$configured = $this->settings->dropDirectory();
		if ( '' === $configured || DIRECTORY_SEPARATOR !== substr( $configured, 0, 1 ) ) {
			throw new \RuntimeException( 'Sentinel drop directory must be an absolute path.' );
		}
		if ( is_link( $configured ) ) {
			throw new \RuntimeException( 'Sentinel drop directory must not be a symbolic link.' );
		}
		$real = realpath( $configured );
		if ( false === $real || ! is_dir( $real ) || ! is_writable( $real ) ) {
			throw new \RuntimeException( 'Sentinel drop directory does not exist or is not writable.' );
		}

		return rtrim( $real, DIRECTORY_SEPARATOR );
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function rowToEvent( array $row ): array {
		$metadata = array();
		if ( isset( $row['metadata_json'] ) && is_string( $row['metadata_json'] ) ) {
			$decoded = json_decode( $row['metadata_json'], true );
			if ( is_array( $decoded ) ) {
				$metadata = $decoded;
			}
		}

		return array(
			'event_uuid'         => (string) ( $row['event_uuid'] ?? '' ),
			'occurred_at'        => $this->mysqlUtcToRfc3339( (string) ( $row['occurred_at_utc'] ?? '' ) ),
			'recorded_at'        => $this->mysqlUtcToRfc3339( (string) ( $row['recorded_at_utc'] ?? '' ) ),
			'event_type'         => (string) ( $row['event_type'] ?? '' ),
			'severity'           => (string) ( $row['severity'] ?? '' ),
			'outcome'            => (string) ( $row['outcome'] ?? '' ),
			'source_ip'          => $this->nullableString( $row['source_ip'] ?? null ),
			'source_ip_version'  => null === ( $row['source_ip_version'] ?? null ) ? null : (int) $row['source_ip_version'],
			'username'           => $this->nullableString( $row['username'] ?? null ),
			'wordpress_user_id'  => null === ( $row['wordpress_user_id'] ?? null ) ? null : (int) $row['wordpress_user_id'],
			'email_domain'       => $this->nullableString( $row['email_domain'] ?? null ),
			'email_identifier'   => $this->nullableString( $row['email_identifier'] ?? null ),
			'user_agent'         => $this->nullableString( $row['user_agent'] ?? null ),
			'request'            => array(
				'method'     => $this->nullableString( $row['request_method'] ?? null ),
				'path'       => $this->nullableString( $row['request_path'] ?? null ),
				'request_id' => $this->nullableString( $metadata['request_id'] ?? null ),
			),
			'metadata'           => $metadata,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $events Events.
	 */
	private function encodeEnvelope( string $batch_uuid, string $created_at, array $events ): string {
		$envelope = array(
			'schema_version' => 1,
			'batch_uuid'     => $batch_uuid,
			'created_at'     => $created_at,
			'source'         => array(
				'host'           => $this->source_host,
				'site_id'        => $this->site_id,
				'site_url'       => $this->site_url,
				'service'        => 'wordpress',
				'plugin_version' => Plugin::VERSION,
			),
			'events'         => $events,
		);
		$encoded  = json_encode(
			$envelope,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT
		);
		if ( false === $encoded ) {
			throw new \RuntimeException( 'Could not encode Sentinel event batch.' );
		}

		return $encoded . "\n";
	}

	private function writeAtomic( string $temp_path, string $final_path, string $payload ): void {
		$handle = @fopen( $temp_path, 'x+b' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not create temporary Sentinel batch file.' );
		}

		try {
			@chmod( $temp_path, 0640 );
			$length  = strlen( $payload );
			$written = 0;
			while ( $written < $length ) {
				$count = fwrite( $handle, substr( $payload, $written ) );
				if ( false === $count || 0 === $count ) {
					throw new \RuntimeException( 'Could not write complete Sentinel batch file.' );
				}
				$written += $count;
			}
			if ( ! fflush( $handle ) ) {
				throw new \RuntimeException( 'Could not flush Sentinel batch file.' );
			}
			if ( function_exists( 'fsync' ) && ! fsync( $handle ) ) {
				throw new \RuntimeException( 'Could not synchronize Sentinel batch file.' );
			}
		} finally {
			fclose( $handle );
		}

		if ( ! @rename( $temp_path, $final_path ) ) {
			@unlink( $temp_path );
			throw new \RuntimeException( 'Could not atomically publish Sentinel batch file.' );
		}
	}

	private function mysqlUtcToRfc3339( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? gmdate( 'c' ) : gmdate( 'c', $timestamp );
	}

	/** @param mixed $value Value. */
	private function nullableString( $value ): ?string {
		return null === $value || '' === (string) $value ? null : (string) $value;
	}

	private function safeError( string $message ): string {
		$message = preg_replace( '/[\r\n\t]+/', ' ', $message );
		return substr( trim( (string) $message ), 0, 2000 );
	}
}
