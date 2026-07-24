<?php

namespace ArgentSentinel\WordPress\Admin;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;

final class SiteHealth {

	/** @var Diagnostics */
	private $diagnostics;

	public function __construct( Diagnostics $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debugInformation' ) );
	}

	/** @param array<string,mixed> $tests Tests. @return array<string,mixed> */
	public function tests( array $tests ): array {
		$tests['direct']['argent_sentinel_drop_directory'] = array(
			'label' => 'Argent Sentinel export directory',
			'test'  => array( $this, 'testDropDirectory' ),
		);
		$tests['direct']['argent_sentinel_export_schedule'] = array(
			'label' => 'Argent Sentinel exporter schedule',
			'test'  => array( $this, 'testSchedule' ),
		);
		$tests['direct']['argent_sentinel_queue'] = array(
			'label' => 'Argent Sentinel event queue',
			'test'  => array( $this, 'testQueue' ),
		);
		return $tests;
	}

	/** @return array<string,mixed> */
	public function testDropDirectory(): array {
		$status = $this->diagnostics->snapshot();
		if ( empty( $status['drop_directory_exists'] ) || empty( $status['drop_directory_writable'] ) ) {
			return $this->result(
				'critical',
				'Argent Sentinel cannot export events',
				'The configured local drop directory is missing or is not writable by the PHP process.',
				(string) ( $status['onboarding_command'] ?? '' )
			);
		}
		if ( empty( $status['drop_directory_outside_web_root'] ) ) {
			return $this->result(
				'recommended',
				'The Argent Sentinel drop directory may be web-accessible',
				'Move the immutable event spool outside the WordPress document root.',
				(string) ( $status['onboarding_command'] ?? '' )
			);
		}
		return $this->result( 'good', 'Argent Sentinel can write its local event spool', 'The drop directory exists, is writable, and is outside the WordPress document root.' );
	}

	/** @return array<string,mixed> */
	public function testSchedule(): array {
		$status = $this->diagnostics->snapshot();
		if ( empty( $status['next_export_at_utc'] ) ) {
			return $this->result( 'recommended', 'The Argent Sentinel export job is not scheduled', 'Reactivate the plugin or run the setup command to restore scheduled exports.' );
		}
		return $this->result( 'good', 'The Argent Sentinel export job is scheduled', 'WordPress has a future export event scheduled.' );
	}

	/** @return array<string,mixed> */
	public function testQueue(): array {
		$status = $this->diagnostics->snapshot();
		$queued = (int) ( $status['queued_events'] ?? $status['queued_count'] ?? 0 );
		if ( $queued >= 1000 ) {
			return $this->result( 'recommended', 'The Argent Sentinel queue is growing', sprintf( '%d events are waiting for export. Check the drop directory and WP-Cron.', $queued ) );
		}
		return $this->result( 'good', 'The Argent Sentinel queue is within its normal range', sprintf( '%d events are currently waiting for export.', $queued ) );
	}

	/** @param array<string,mixed> $info Debug data. @return array<string,mixed> */
	public function debugInformation( array $info ): array {
		$status = $this->diagnostics->snapshot();
		$info['argent_sentinel'] = array(
			'label'  => 'Argent Sentinel',
			'fields' => array(
				'plugin_version' => array( 'label' => 'Plugin version', 'value' => (string) $status['plugin_version'] ),
				'site_id' => array( 'label' => 'Site ID', 'value' => (string) $status['site_id'] ),
				'source_host' => array( 'label' => 'Node ID', 'value' => (string) $status['source_host'] ),
				'drop_directory' => array( 'label' => 'Drop directory', 'value' => (string) $status['drop_directory'] ),
				'drop_writable' => array( 'label' => 'Drop writable', 'value' => ! empty( $status['drop_directory_writable'] ) ? 'yes' : 'no' ),
				'request_id_available' => array( 'label' => 'Nginx request ID available', 'value' => ! empty( $status['request_id_available'] ) ? 'yes' : 'no' ),
			),
		);
		return $info;
	}

	/** @return array<string,mixed> */
	private function result( string $status, string $label, string $description, string $command = '' ): array {
		if ( '' !== $command ) {
			$description .= '<p><code>' . esc_html( $command ) . '</code></p>';
		}
		return array(
			'status'      => $status,
			'label'       => $label,
			'description' => '<p>' . $description . '</p>',
			'actions'     => '',
			'test'        => 'argent_sentinel',
		);
	}
}
