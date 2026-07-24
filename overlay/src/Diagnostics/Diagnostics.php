<?php

namespace ArgentSentinel\WordPress\Diagnostics;

use ArgentSentinel\WordPress\Database\Schema;
use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Export\CronExporter;
use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
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

	/** @return array<string,mixed> */
	public function snapshot(): array {
		$drop_directory = $this->settings->dropDirectory();
		$web_root = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		$drop_real = is_dir( $drop_directory ) ? realpath( $drop_directory ) : false;
		$outside_web_root = true;
		if ( false !== $web_root && false !== $drop_real ) {
			$outside_web_root = 0 !== strpos(
				rtrim( $drop_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR,
				rtrim( $web_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR
			);
		}

		return array_merge(
			$this->queue->diagnostics(),
			array(
				'plugin_version'                    => Plugin::VERSION,
				'expected_schema_version'           => Schema::VERSION,
				'installed_schema_version'          => (int) get_option( Schema::VERSION_OPTION, 0 ),
				'site_id'                           => $this->settings->siteId(),
				'source_host'                       => $this->settings->sourceHost(),
				'hmac_secret_configured'            => '' !== $this->settings->hmacSecret(),
				'drop_directory'                    => $drop_directory,
				'drop_directory_exists'             => is_dir( $drop_directory ),
				'drop_directory_writable'           => is_dir( $drop_directory ) && is_writable( $drop_directory ),
				'drop_directory_outside_web_root'   => $outside_web_root,
				'request_id_available'              => isset( $_SERVER['ARGENT_SENTINEL_REQUEST_ID'] )
					&& 1 === preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', (string) $_SERVER['ARGENT_SENTINEL_REQUEST_ID'] ),
				'next_export_at_utc'                 => $this->nextScheduledUtc( CronExporter::EXPORT_HOOK ),
				'next_prune_at_utc'                  => $this->nextScheduledUtc( CronExporter::PRUNE_HOOK ),
				'onboarding_command'                 => ( new CommandBuilder( $this->settings ) )->command(),
				'central_delivery_managed_by_agent'  => true,
				'central_service_name'               => 'sentinel.argentwolf.org',
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
