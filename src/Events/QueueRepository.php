<?php

namespace ArgentSentinel\WordPress\Events;

use ArgentSentinel\WordPress\Database\Schema;

final class QueueRepository {

	/**
	 * Explicit formats prevent collisions with WordPress's global wpdb field map.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_FORMATS = array(
		'event_uuid'        => '%s',
		'batch_uuid'        => '%s',
		'occurred_at_utc'   => '%s',
		'recorded_at_utc'   => '%s',
		'site_id'           => '%s',
		'site_url'          => '%s',
		'source_host'       => '%s',
		'service'           => '%s',
		'event_type'        => '%s',
		'severity'          => '%s',
		'outcome'           => '%s',
		'source_ip'         => '%s',
		'source_ip_version' => '%d',
		'username'          => '%s',
		'wordpress_user_id' => '%d',
		'email_domain'      => '%s',
		'email_identifier'  => '%s',
		'user_agent'        => '%s',
		'request_method'    => '%s',
		'request_path'      => '%s',
		'export_state'      => '%s',
		'exported_at_utc'   => '%s',
		'retry_count'       => '%d',
		'last_export_error' => '%s',
		'metadata_json'     => '%s',
	);

	/** @var object */
	private $wpdb;

	/** @var string */
	private $table_name;

	/**
	 * @param object $wpdb WordPress database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . Schema::TABLE_SUFFIX;
	}

	public function insert( Event $event ): bool {
		$row     = $event->toDatabaseRow();
		$formats = array();

		foreach ( array_keys( $row ) as $field ) {
			if ( ! isset( self::FIELD_FORMATS[ $field ] ) ) {
				return false;
			}

			$formats[] = self::FIELD_FORMATS[ $field ];
		}

		$result = $this->wpdb->insert( $this->table_name, $row, $formats );
		return false !== $result;
	}

	/**
	 * Returns queued rows in deterministic insertion order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function queuedRows( int $limit ): array {
		$limit = max( 1, min( 5000, $limit ) );
		$sql   = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE export_state = 'queued' ORDER BY id ASC LIMIT %d",
			$limit
		);
		$rows  = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Marks only still-queued rows as exported.
	 *
	 * @param array<int,int> $ids Event row IDs.
	 */
	public function markExported( array $ids, string $batch_uuid, string $exported_at_utc ): bool {
		$ids = $this->normalizeIds( $ids );
		if ( array() === $ids ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$this->table_name}
			SET batch_uuid = %s,
				export_state = 'exported',
				exported_at_utc = %s,
				last_export_error = NULL
			WHERE export_state = 'queued'
				AND id IN ({$placeholders})";
		$args         = array_merge( array( $batch_uuid, $exported_at_utc ), $ids );
		$prepared     = $this->wpdb->prepare( $sql, $args );
		$result       = $this->wpdb->query( $prepared );

		return false !== $result && (int) $result === count( $ids );
	}

	/**
	 * Records a bounded export error without changing queue state.
	 *
	 * @param array<int,int> $ids Event row IDs.
	 */
	public function markExportFailure( array $ids, string $error ): bool {
		$ids = $this->normalizeIds( $ids );
		if ( array() === $ids ) {
			return false;
		}

		$error        = substr( trim( $error ), 0, 2000 );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$this->table_name}
			SET retry_count = retry_count + 1,
				last_export_error = %s
			WHERE export_state = 'queued'
				AND id IN ({$placeholders})";
		$args         = array_merge( array( $error ), $ids );
		$result       = $this->wpdb->query( $this->wpdb->prepare( $sql, $args ) );

		return false !== $result;
	}

	/**
	 * Deletes a bounded number of successfully exported rows older than a cutoff.
	 */
	public function pruneExportedBefore( string $cutoff_utc, int $limit = 1000 ): int {
		$limit  = max( 1, min( 5000, $limit ) );
		$sql    = $this->wpdb->prepare(
			"DELETE FROM {$this->table_name}
			WHERE export_state = 'exported'
				AND exported_at_utc IS NOT NULL
				AND exported_at_utc < %s
			ORDER BY id ASC
			LIMIT %d",
			$cutoff_utc,
			$limit
		);
		$result = $this->wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		$queued_count = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE export_state = 'queued'"
		);
		$oldest       = $this->wpdb->get_var(
			"SELECT MIN(recorded_at_utc) FROM {$this->table_name} WHERE export_state = 'queued'"
		);
		$exported     = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE export_state = 'exported'"
		);
		$last_export  = $this->wpdb->get_var(
			"SELECT MAX(exported_at_utc) FROM {$this->table_name} WHERE export_state = 'exported'"
		);
		$last_error   = $this->wpdb->get_var(
			"SELECT last_export_error FROM {$this->table_name}
			WHERE last_export_error IS NOT NULL AND last_export_error <> ''
			ORDER BY id DESC LIMIT 1"
		);

		return array(
			'queued_count'       => $queued_count,
			'oldest_queued_at'   => is_string( $oldest ) && '' !== $oldest ? $oldest : null,
			'exported_count'     => $exported,
			'last_exported_at'   => is_string( $last_export ) && '' !== $last_export ? $last_export : null,
			'last_export_error'  => is_string( $last_error ) && '' !== $last_error ? $last_error : null,
		);
	}

	/**
	 * @param array<int,int> $ids Raw IDs.
	 * @return array<int,int>
	 */
	private function normalizeIds( array $ids ): array {
		$normalized = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$normalized[ $id ] = $id;
			}
		}

		return array_values( $normalized );
	}
}
