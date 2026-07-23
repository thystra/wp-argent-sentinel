<?php

namespace ArgentSentinel\WordPress\Events;

use ArgentSentinel\WordPress\Database\Schema;

final class QueueRepository {
	/**
	 * Explicit formats prevent WordPress's global wpdb field-name mappings
	 * from coercing plugin-owned columns such as site_id.
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
		$row = $event->toDatabaseRow();
		$formats = array();

		foreach ( array_keys( $row ) as $field ) {
			if ( ! isset( self::FIELD_FORMATS[ $field ] ) ) {
				return false;
			}

			$formats[] = self::FIELD_FORMATS[ $field ];
		}

		$result = $this->wpdb->insert(
			$this->table_name,
			$row,
			$formats
		);

		return false !== $result;
	}

	/**
	 * @return array{queued_count:int,oldest_queued_at:?string}
	 */
	public function diagnostics(): array {
		$queued_count = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE export_state = 'queued'"
		);
		$oldest       = $this->wpdb->get_var(
			"SELECT MIN(recorded_at_utc) FROM {$this->table_name} WHERE export_state = 'queued'"
		);

		return array(
			'queued_count'     => $queued_count,
			'oldest_queued_at' => is_string( $oldest ) && '' !== $oldest ? $oldest : null,
		);
	}
}
