<?php

namespace ArgentSentinel\WordPress\Onboarding;

use ArgentSentinel\WordPress\Settings\Settings;

final class CommandBuilder {
	public const HELPER_AVAILABLE              = 'available';
	public const HELPER_MISSING                = 'missing';
	public const HELPER_UNVERIFIABLE_BASEDIR   = 'unverifiable_open_basedir';

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
			if ( self::HELPER_AVAILABLE === $this->pathState( $candidate ) ) {
				return $candidate;
			}
		}

		// The package-owned path remains the generated command even when the
		// unprivileged PHP process cannot stat /usr/sbin through open_basedir.
		return $this->candidatePaths()[0];
	}

	public function helperState( ?string $open_basedir = null ): string {
		return $this->pathState( $this->helperPath(), $open_basedir );
	}

	public function helperExists( ?string $open_basedir = null ): ?bool {
		$state = $this->helperState( $open_basedir );
		if ( self::HELPER_UNVERIFIABLE_BASEDIR === $state ) {
			return null;
		}

		return self::HELPER_AVAILABLE === $state;
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

	private function pathState( string $path, ?string $open_basedir = null ): string {
		$open_basedir = null === $open_basedir ? $this->effectiveOpenBasedir() : $open_basedir;
		if (
			'' !== trim( $open_basedir )
			&& ! $this->openBasedirAllowsPath( $open_basedir, $path )
		) {
			return self::HELPER_UNVERIFIABLE_BASEDIR;
		}

		return @is_file( $path ) && @is_executable( $path )
			? self::HELPER_AVAILABLE
			: self::HELPER_MISSING;
	}

	private function effectiveOpenBasedir(): string {
		$value = ini_get( 'open_basedir' );
		$value = false === $value ? '' : (string) $value;

		/**
		 * Filters the effective open_basedir used for helper visibility.
		 *
		 * @param string $value Effective PHP open_basedir.
		 */
		return (string) apply_filters( 'argent_sentinel_effective_open_basedir', $value );
	}

	private function openBasedirAllowsPath( string $configured, string $required ): bool {
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

	private function normalizePath( string $path ): string {
		$path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, trim( $path ) );
		$path = preg_replace(
			'#[' . preg_quote( DIRECTORY_SEPARATOR, '#' ) . ']+#',
			DIRECTORY_SEPARATOR,
			$path
		);

		return rtrim( (string) $path, DIRECTORY_SEPARATOR );
	}
}
