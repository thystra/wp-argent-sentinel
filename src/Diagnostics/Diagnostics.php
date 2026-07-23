<?php

namespace ArgentSentinel\WordPress\Diagnostics;

use ArgentSentinel\WordPress\Database\Schema;
use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Export\CronExporter;
use ArgentSentinel\WordPress\Plugin;
use ArgentSentinel\WordPress\Settings\Settings;

final class Diagnostics {

	/** @var QueueRepository */
	private $queue;

	/** @var Settings */
	private $settings;

	public function __construct( QueueRepository $queue, Settings $settings ) {
		$this->queue    = $queue;
		$this->settings = $settings;
	}

	/**
	 * Returns non-secret operational state for WP-CLI and a future admin screen.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		$drop_directory = $this->settings->dropDirectory();

		return array_merge(
			$this->queue->diagnostics(),
			array(
				'plugin_version'          => Plugin::VERSION,
				'expected_schema_version' => Schema::VERSION,
				'installed_schema_version' => (int) get_option( Schema::VERSION_OPTION, 0 ),
				'site_id'                 => $this->settings->siteId(),
				'source_host'             => $this->settings->sourceHost(),
				'hmac_secret_configured'  => '' !== $this->settings->hmacSecret(),
				'drop_directory'          => $drop_directory,
				'drop_directory_exists'   => is_dir( $drop_directory ),
				'drop_directory_writable' => is_dir( $drop_directory ) && is_writable( $drop_directory ),
				'next_export_at_utc'       => $this->nextScheduledUtc( CronExporter::EXPORT_HOOK ),
				'next_prune_at_utc'        => $this->nextScheduledUtc( CronExporter::PRUNE_HOOK ),
			)
		);
	}

	private function nextScheduledUtc( string $hook ): ?string {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return null;
		}

		$timestamp = wp_next_scheduled( $hook );
		return false === $timestamp ? null : gmdate( 'c', (int) $timestamp );
	}
}
