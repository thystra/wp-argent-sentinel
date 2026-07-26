<?php

namespace ArgentSentinel\WordPress\Onboarding;

use ArgentSentinel\WordPress\Settings\Settings;

final class CommandBuilder {
	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function command( ?string $php_user = null, ?string $wordpress_path = null ): string {
		$user = null === $php_user || '' === trim( $php_user ) ? $this->detectedUser() : trim( $php_user );
		$path = null === $wordpress_path || '' === trim( $wordpress_path )
			? ( defined( 'ABSPATH' ) ? rtrim( ABSPATH, DIRECTORY_SEPARATOR ) : '<wordpress-path>' )
			: rtrim( $wordpress_path, DIRECTORY_SEPARATOR );

		return implode(
			" \\\n    ",
			array(
				'sudo ' . escapeshellarg( $this->helperPath() ),
				'--wordpress-path ' . escapeshellarg( $path ),
				'--site-id ' . escapeshellarg( $this->settings->siteId() ),
				'--node-id ' . escapeshellarg( $this->settings->sourceHost() ),
				'--php-user ' . escapeshellarg( $user ),
				'--open-basedir-mode prompt',
			)
		);
	}

	public function helperPath(): string {
		if ( defined( 'ARGENT_SENTINEL_ONBOARDING_HELPER' ) ) {
			$configured = constant( 'ARGENT_SENTINEL_ONBOARDING_HELPER' );
			if ( is_string( $configured ) && '' !== trim( $configured ) ) {
				return trim( $configured );
			}
		}

		foreach ( $this->candidatePaths() as $candidate ) {
			if ( is_file( $candidate ) && is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '/usr/sbin/argent-sentinel-onboard-wordpress';
	}

	public function helperExists(): bool {
		$path = $this->helperPath();

		return is_file( $path ) && is_executable( $path );
	}

	/**
	 * @return array<int,string>
	 */
	public function candidatePaths(): array {
		return array(
			'/usr/sbin/argent-sentinel-onboard-wordpress',
			'/usr/sbin/argent-sentinel-onboard-wordpress-site',
			'/usr/lib/argent-sentinel/scripts/onboard-wordpress-site.sh',
			'/home/alan/src/argent-sentinel-collector/scripts/onboard-wordpress-site.sh',
		);
	}

	public function detectedUser(): string {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$entry = posix_getpwuid( posix_geteuid() );
			if ( is_array( $entry ) && isset( $entry['name'] ) && '' !== trim( (string) $entry['name'] ) ) {
				return (string) $entry['name'];
			}
		}

		return '<php-fpm-user>';
	}
}
