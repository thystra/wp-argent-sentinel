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

	/**
	 * @param array<string,mixed> $tests Tests.
	 * @return array<string,mixed>
	 */
	public function tests( array $tests ): array {
		$tests['direct']['argent_sentinel_configuration'] = array(
			'label' => 'Argent Sentinel configuration',
			'test'  => array( $this, 'testConfiguration' ),
		);
		$tests['direct']['argent_sentinel_drop_directory'] = array(
			'label' => 'Argent Sentinel export directory',
			'test'  => array( $this, 'testDropDirectory' ),
		);
		$tests['direct']['argent_sentinel_open_basedir'] = array(
			'label' => 'Argent Sentinel PHP open_basedir',
			'test'  => array( $this, 'testOpenBasedir' ),
		);
		$tests['direct']['argent_sentinel_export_schedule'] = array(
			'label' => 'Argent Sentinel exporter schedule',
			'test'  => array( $this, 'testSchedule' ),
		);
		$tests['direct']['argent_sentinel_queue'] = array(
			'label' => 'Argent Sentinel event queue',
			'test'  => array( $this, 'testQueue' ),
		);
		$tests['direct']['argent_sentinel_request_id'] = array(
			'label' => 'Argent Sentinel request correlation',
			'test'  => array( $this, 'testRequestId' ),
		);

		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testConfiguration(): array {
		$status = $this->diagnostics->snapshot();
		if (
			(int) $status['installed_schema_version'] !== (int) $status['expected_schema_version']
			|| empty( $status['hmac_secret_configured'] )
		) {
			return $this->result(
				'critical',
				'Argent Sentinel configuration is incomplete',
				'The event-queue schema and HMAC configuration must be healthy before security events can be exported.'
			);
		}

		return $this->result(
			'good',
			'Argent Sentinel configuration is valid',
			'The event-queue schema is current and the privacy HMAC secret is configured.'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testDropDirectory(): array {
		$status = $this->diagnostics->snapshot();
		if (
			empty( $status['drop_directory_exists'] )
			|| empty( $status['drop_directory_writable'] )
			|| ! empty( $status['drop_directory_symlink'] )
		) {
			return $this->result(
				'critical',
				'Argent Sentinel cannot export events',
				'The protected local drop directory is missing, is not writable by PHP, or is a symbolic link.'
			);
		}
		if ( empty( $status['drop_directory_outside_web_root'] ) ) {
			return $this->result(
				'recommended',
				'The Argent Sentinel drop directory may be web-accessible',
				'Move the immutable event spool outside the WordPress document root.'
			);
		}

		return $this->result(
			'good',
			'Argent Sentinel can write its protected local spool',
			'The drop directory exists, is writable, is not a symlink, and is outside the WordPress document root.'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testOpenBasedir(): array {
		$status = $this->diagnostics->snapshot();
		if (
			! empty( $status['open_basedir_enabled'] )
			&& empty( $status['open_basedir_allows_drop'] )
		) {
			return $this->result(
				'critical',
				'PHP open_basedir blocks Argent Sentinel',
				'Append the protected drop directory to the matching PHP-FPM pool open_basedir value and restart the pool.'
			);
		}

		return $this->result(
			'good',
			'PHP open_basedir permits Argent Sentinel',
			'The effective PHP filesystem restriction permits the protected event drop directory.'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testSchedule(): array {
		$status = $this->diagnostics->snapshot();
		if ( empty( $status['next_export_at_utc'] ) ) {
			return $this->result(
				'recommended',
				'The Argent Sentinel export job is not scheduled',
				'Reactivate the plugin or run the setup command to restore scheduled exports.'
			);
		}

		return $this->result(
			'good',
			'The Argent Sentinel export job is scheduled',
			'WordPress has a future bounded export event scheduled.'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testQueue(): array {
		$status = $this->diagnostics->snapshot();
		$queued = (int) ( $status['queued_count'] ?? 0 );
		if ( $queued >= 1000 || ! empty( $status['last_export_error'] ) ) {
			return $this->result(
				'recommended',
				'The Argent Sentinel queue needs review',
				sprintf(
					'%d events are waiting for export. Review the drop directory, WP-Cron, and the most recent export error.',
					$queued
				)
			);
		}

		return $this->result(
			'good',
			'The Argent Sentinel queue is within its normal range',
			sprintf( '%d events are currently waiting for export.', $queued )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function testRequestId(): array {
		$status = $this->diagnostics->snapshot();
		if ( empty( $status['request_id_available'] ) ) {
			return $this->result(
				'recommended',
				'Nginx request correlation is not visible',
				'Pass ARGENT_SENTINEL_REQUEST_ID from the applicable FastCGI location to correlate WordPress events with network tuples.'
			);
		}

		return $this->result(
			'good',
			'Nginx request correlation is available',
			'This request includes a valid server-generated Argent Sentinel request ID.'
		);
	}

	/**
	 * @param array<string,mixed> $info Debug data.
	 * @return array<string,mixed>
	 */
	public function debugInformation( array $info ): array {
		$status = $this->diagnostics->snapshot();
		$info['argent_sentinel'] = array(
			'label'  => 'Argent Sentinel',
			'fields' => array(
				'overall_status' => array( 'label' => 'Overall status', 'value' => (string) $status['overall_status'] ),
				'plugin_version' => array( 'label' => 'Plugin version', 'value' => (string) $status['plugin_version'] ),
				'schema_version' => array(
					'label' => 'Schema version',
					'value' => (string) $status['installed_schema_version'] . ' / ' . (string) $status['expected_schema_version'],
				),
				'site_id' => array( 'label' => 'Site ID', 'value' => (string) $status['site_id'] ),
				'source_host' => array( 'label' => 'Node ID', 'value' => (string) $status['source_host'] ),
				'drop_directory' => array( 'label' => 'Drop directory', 'value' => (string) $status['drop_directory'] ),
				'drop_writable' => array( 'label' => 'Drop writable', 'value' => ! empty( $status['drop_directory_writable'] ) ? 'yes' : 'no' ),
				'drop_owner_group' => array(
					'label' => 'Drop owner/group',
					'value' => (string) ( $status['drop_directory_owner'] ?? 'unknown' ) . '/' . (string) ( $status['drop_directory_group'] ?? 'unknown' ),
				),
				'drop_mode' => array( 'label' => 'Drop mode', 'value' => (string) ( $status['drop_directory_mode'] ?? 'unknown' ) ),
				'php_process_user' => array( 'label' => 'PHP process user', 'value' => (string) $status['php_process_user'] ),
				'open_basedir' => array( 'label' => 'Effective open_basedir', 'value' => (string) $status['open_basedir'] ),
				'open_basedir_allows_drop' => array( 'label' => 'open_basedir permits drop', 'value' => ! empty( $status['open_basedir_allows_drop'] ) ? 'yes' : 'no' ),
				'queued_count' => array( 'label' => 'Queued events', 'value' => (string) $status['queued_count'] ),
				'last_exported_at' => array( 'label' => 'Last export UTC', 'value' => (string) ( $status['last_exported_at'] ?? 'none' ) ),
				'next_export_at' => array( 'label' => 'Next export UTC', 'value' => (string) ( $status['next_export_at_utc'] ?? 'none' ) ),
				'request_id_available' => array( 'label' => 'Nginx request ID available', 'value' => ! empty( $status['request_id_available'] ) ? 'yes' : 'no' ),
			),
		);

		return $info;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function result( string $status, string $label, string $description ): array {
		return array(
			'status'      => $status,
			'label'       => $label,
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">Open Argent Sentinel setup and status</a></p>',
				esc_url( AdminPage::statusPageUrl() )
			),
			'test'        => 'argent_sentinel',
		);
	}
}
