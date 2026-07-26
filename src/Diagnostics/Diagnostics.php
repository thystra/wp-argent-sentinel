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
		$drop_directory          = $this->settings->dropDirectory();
		$open_basedir            = $this->effectiveOpenBasedir();
		$open_basedir_enabled    = '' !== trim( $open_basedir );
		$open_basedir_allows     = ! $open_basedir_enabled || $this->openBasedirAllows( $open_basedir, $drop_directory );
		$blocked_by_open_basedir = $open_basedir_enabled && ! $open_basedir_allows;
		$exists                  = ! $blocked_by_open_basedir && is_dir( $drop_directory );
		$symlink                 = ! $blocked_by_open_basedir && is_link( $drop_directory );
		$writable                = $exists && is_writable( $drop_directory );
		$outside                 = $this->outsideWebRoot( $drop_directory );
		$next_export    = $this->nextScheduledUtc( CronExporter::EXPORT_HOOK );
		$next_prune     = $this->nextScheduledUtc( CronExporter::PRUNE_HOOK );
		$schema         = (int) get_option( Schema::VERSION_OPTION, 0 );
		$builder        = new CommandBuilder( $this->settings );
		$helper_state   = $builder->helperState( $open_basedir );
		$helper_exists  = CommandBuilder::HELPER_AVAILABLE === $helper_state
			? true
			: ( CommandBuilder::HELPER_MISSING === $helper_state ? false : null );
		$issues         = array();

		if ( Schema::VERSION !== $schema ) {
			$issues[] = 'The installed event-queue schema does not match the plugin.';
		}
		if ( '' === $this->settings->hmacSecret() ) {
			$issues[] = 'The HMAC secret is missing or too short.';
		}
		if ( $blocked_by_open_basedir ) {
			$issues[] = 'PHP open_basedir does not permit access to the local drop directory.';
		} elseif ( ! $exists ) {
			$issues[] = 'The local drop directory does not exist.';
		} elseif ( $symlink ) {
			$issues[] = 'The local drop directory must not be a symbolic link.';
		} elseif ( ! $writable ) {
			$issues[] = 'The local drop directory is not writable by the PHP process.';
		}
		if ( ! $blocked_by_open_basedir && $exists && ! $outside ) {
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
				'open_basedir'                    => $open_basedir,
				'open_basedir_enabled'            => $open_basedir_enabled,
				'open_basedir_allows_drop'        => $open_basedir_allows,
				'open_basedir_required_entry'     => $drop_directory,
				'open_basedir_suggested_value'    => $this->suggestedOpenBasedir( $open_basedir, $drop_directory ),
				'drop_directory_access_state'     => $blocked_by_open_basedir ? 'blocked_by_open_basedir' : ( $exists ? ( $writable ? 'ready' : 'not_writable' ) : 'missing' ),
				'drop_directory'                  => $drop_directory,
				'drop_directory_exists'           => $exists,
				'drop_directory_writable'         => $writable,
				'drop_directory_symlink'          => $symlink,
				'drop_directory_outside_web_root' => $outside,
				'drop_directory_owner'            => $this->ownerName( $drop_directory, $blocked_by_open_basedir ),
				'drop_directory_group'            => $this->groupName( $drop_directory, $blocked_by_open_basedir ),
				'drop_directory_mode'             => $this->mode( $drop_directory, $blocked_by_open_basedir ),
				'php_process_user'                => $builder->detectedUser(),
				'request_id_available'            => $this->requestIdAvailable(),
				'next_export_at_utc'               => $next_export,
				'next_prune_at_utc'                => $next_prune,
				'onboarding_helper'                => $builder->helperPath(),
				'onboarding_helper_state'          => $helper_state,
				'onboarding_helper_exists'         => $helper_exists,
				'onboarding_helper_verifiable'     => CommandBuilder::HELPER_UNVERIFIABLE_BASEDIR !== $helper_state,
				'onboarding_command'               => $builder->command(),
				'central_delivery_managed_by_agent' => true,
				'central_service_name'             => 'sentinel.argentwolf.org',
			)
		);
	}


	private function effectiveOpenBasedir(): string {
		$value = ini_get( 'open_basedir' );
		$value = false === $value ? '' : (string) $value;

		/**
		 * Filters the effective open_basedir value for diagnostics and tests.
		 *
		 * @param string $value Effective PHP open_basedir.
		 */
		return (string) apply_filters( 'argent_sentinel_effective_open_basedir', $value );
	}

	private function openBasedirAllows( string $configured, string $required ): bool {
		$required = $this->normalizePath( $required );
		foreach ( explode( PATH_SEPARATOR, $configured ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( '.' === $entry ) {
				$current = getcwd();
				$entry   = false === $current ? '' : $current;
			}
			$entry = $this->normalizePath( $entry );
			if (
				'' !== $entry
				&& (
					$required === $entry
					|| 0 === strpos(
						$required . DIRECTORY_SEPARATOR,
						$entry . DIRECTORY_SEPARATOR
					)
				)
			) {
				return true;
			}
		}

		return false;
	}

	private function suggestedOpenBasedir( string $configured, string $required ): string {
		if ( '' === trim( $configured ) ) {
			return $required;
		}
		if ( $this->openBasedirAllows( $configured, $required ) ) {
			return $configured;
		}

		return rtrim( $configured, PATH_SEPARATOR ) . PATH_SEPARATOR . $required;
	}

	private function normalizePath( string $path ): string {
		$path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, trim( $path ) );
		$path = preg_replace(
			'#[' . preg_quote( DIRECTORY_SEPARATOR, '#' ) . ']+#',
			DIRECTORY_SEPARATOR,
			$path
		);

		return rtrim( (string) $path, DIRECTORY_SEPARATOR );
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
		$drop_path = $this->normalizePath( $drop_directory );
		$web_root  = defined( 'ABSPATH' ) ? $this->normalizePath( ABSPATH ) : '';

		if ( '' === $drop_path || '' === $web_root ) {
			return false;
		}

		return $drop_path !== $web_root
			&& 0 !== strpos(
				$drop_path . DIRECTORY_SEPARATOR,
				$web_root . DIRECTORY_SEPARATOR
			);
	}

	private function requestIdAvailable(): bool {
		return isset( $_SERVER['ARGENT_SENTINEL_REQUEST_ID'] )
			&& 1 === preg_match(
				'/^[A-Za-z0-9._:-]{8,128}$/',
				(string) $_SERVER['ARGENT_SENTINEL_REQUEST_ID']
			);
	}

	private function ownerName( string $path, bool $blocked ): ?string {
		if ( $blocked || ! is_dir( $path ) ) {
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

	private function groupName( string $path, bool $blocked ): ?string {
		if ( $blocked || ! is_dir( $path ) ) {
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

	private function mode( string $path, bool $blocked ): ?string {
		if ( $blocked || ! is_dir( $path ) ) {
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
