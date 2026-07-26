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

	/**
	 * Returns non-secret operational state for WP-CLI and administrator pages.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		$drop_directory = $this->settings->dropDirectory();
		$exists         = is_dir( $drop_directory );
		$symlink        = is_link( $drop_directory );
		$writable       = $exists && is_writable( $drop_directory );
		$outside        = $this->outsideWebRoot( $drop_directory );
		$next_export    = $this->nextScheduledUtc( CronExporter::EXPORT_HOOK );
		$next_prune     = $this->nextScheduledUtc( CronExporter::PRUNE_HOOK );
		$schema         = (int) get_option( Schema::VERSION_OPTION, 0 );
		$builder        = new CommandBuilder( $this->settings );
		$issues         = array();

		if ( Schema::VERSION !== $schema ) {
			$issues[] = 'The installed event-queue schema does not match the plugin.';
		}
		if ( '' === $this->settings->hmacSecret() ) {
			$issues[] = 'The HMAC secret is missing or too short.';
		}
		if ( ! $exists ) {
			$issues[] = 'The local drop directory does not exist.';
		} elseif ( $symlink ) {
			$issues[] = 'The local drop directory must not be a symbolic link.';
		} elseif ( ! $writable ) {
			$issues[] = 'The local drop directory is not writable by the PHP process.';
		}
		if ( $exists && ! $outside ) {
			$issues[] = 'The local drop directory is inside the WordPress web root.';
		}
		if ( null === $next_export ) {
			$issues[] = 'The WordPress export job is not scheduled.';
		}

		return array_merge(
			$this->queue->diagnostics(),
			array(
				'overall_status'                  => array() === $issues ? 'ready' : 'attention',
				'issue_count'                     => count( $issues ),
				'issues'                          => implode( ' ', $issues ),
				'plugin_version'                  => Plugin::VERSION,
				'expected_schema_version'         => Schema::VERSION,
				'installed_schema_version'        => $schema,
				'site_id'                         => $this->settings->siteId(),
				'site_url'                        => $this->settings->siteUrl(),
				'source_host'                     => $this->settings->sourceHost(),
				'configuration_source'            => $this->configurationSource(),
				'hmac_secret_configured'          => '' !== $this->settings->hmacSecret(),
				'drop_directory'                  => $drop_directory,
				'drop_directory_exists'           => $exists,
				'drop_directory_writable'         => $writable,
				'drop_directory_symlink'          => $symlink,
				'drop_directory_outside_web_root' => $outside,
				'drop_directory_owner'            => $this->ownerName( $drop_directory ),
				'drop_directory_group'            => $this->groupName( $drop_directory ),
				'drop_directory_mode'             => $this->mode( $drop_directory ),
				'php_process_user'                => $builder->detectedUser(),
				'request_id_available'            => $this->requestIdAvailable(),
				'next_export_at_utc'               => $next_export,
				'next_prune_at_utc'                => $next_prune,
				'onboarding_helper'                => $builder->helperPath(),
				'onboarding_helper_exists'         => $builder->helperExists(),
				'onboarding_command'               => $builder->command(),
				'central_delivery_managed_by_agent' => true,
				'central_service_name'             => 'sentinel.argentwolf.org',
			)
		);
	}

	private function configurationSource(): string {
		$constants = 0;
		foreach ( array(
			'ARGENT_SENTINEL_SITE_ID',
			'ARGENT_SENTINEL_SOURCE_HOST',
			'ARGENT_SENTINEL_DROP_DIRECTORY',
		) as $name ) {
			if ( defined( $name ) ) {
				++$constants;
			}
		}

		if ( 0 === $constants ) {
			return 'WordPress options';
		}
		if ( 3 === $constants ) {
			return 'wp-config.php constants';
		}

		return 'mixed constants and options';
	}

	private function outsideWebRoot( string $drop_directory ): bool {
		if ( ! is_dir( $drop_directory ) ) {
			return false;
		}

		$drop_real = realpath( $drop_directory );
		$web_root  = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		if ( false === $drop_real || false === $web_root ) {
			return false;
		}

		$drop_prefix = rtrim( $drop_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$web_prefix  = rtrim( $web_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		return 0 !== strpos( $drop_prefix, $web_prefix );
	}

	private function requestIdAvailable(): bool {
		return isset( $_SERVER['ARGENT_SENTINEL_REQUEST_ID'] )
			&& 1 === preg_match(
				'/^[A-Za-z0-9._:-]{8,128}$/',
				(string) $_SERVER['ARGENT_SENTINEL_REQUEST_ID']
			);
	}

	private function ownerName( string $path ): ?string {
		if ( ! is_dir( $path ) ) {
			return null;
		}
		$id = @fileowner( $path );
		if ( false === $id ) {
			return null;
		}
		if ( function_exists( 'posix_getpwuid' ) ) {
			$entry = posix_getpwuid( (int) $id );
			if ( is_array( $entry ) && isset( $entry['name'] ) ) {
				return (string) $entry['name'];
			}
		}

		return (string) $id;
	}

	private function groupName( string $path ): ?string {
		if ( ! is_dir( $path ) ) {
			return null;
		}
		$id = @filegroup( $path );
		if ( false === $id ) {
			return null;
		}
		if ( function_exists( 'posix_getgrgid' ) ) {
			$entry = posix_getgrgid( (int) $id );
			if ( is_array( $entry ) && isset( $entry['name'] ) ) {
				return (string) $entry['name'];
			}
		}

		return (string) $id;
	}

	private function mode( string $path ): ?string {
		if ( ! is_dir( $path ) ) {
			return null;
		}
		$permissions = @fileperms( $path );

		return false === $permissions ? null : sprintf( '%04o', $permissions & 07777 );
	}

	private function nextScheduledUtc( string $hook ): ?string {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return null;
		}

		$timestamp = wp_next_scheduled( $hook );

		return false === $timestamp ? null : gmdate( 'c', (int) $timestamp );
	}
}
