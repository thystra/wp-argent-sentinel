<?php

namespace ArgentSentinel\WordPress\Export;

use ArgentSentinel\WordPress\Settings\Settings;

final class ExportSettings {

	/** @var Settings */
	private $settings;

	/** @var array<string,mixed> */
	private $values;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$values         = get_option( Settings::OPTION_NAME, array() );
		$this->values   = is_array( $values ) ? $values : array();
	}

	public function dropDirectory(): string {
		return rtrim( trim( $this->settings->dropDirectory() ), DIRECTORY_SEPARATOR );
	}

	public function maxEventsPerBatch(): int {
		return $this->boundedInteger( 'max_events_per_batch', 500, 1, 5000 );
	}

	public function maxBatchBytes(): int {
		return $this->boundedInteger( 'max_batch_bytes', 5242880, 65536, 20971520 );
	}

	public function localRetentionDays(): int {
		return $this->boundedInteger( 'local_retention_days', 30, 1, 3650 );
	}

	private function boundedInteger( string $key, int $default, int $minimum, int $maximum ): int {
		$value = isset( $this->values[ $key ] ) ? (int) $this->values[ $key ] : $default;
		return max( $minimum, min( $maximum, $value ) );
	}
}
