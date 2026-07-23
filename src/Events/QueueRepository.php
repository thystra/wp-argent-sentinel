<?php

namespace ArgentSentinel\WordPress\Events;

use ArgentSentinel\WordPress\Database\Schema;

final class QueueRepository {
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
		$result = $this->wpdb->insert(
			$this->table_name,
			$event->toDatabaseRow()
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
