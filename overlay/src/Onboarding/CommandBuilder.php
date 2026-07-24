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
				'sudo /home/alan/src/argent-sentinel-collector/scripts/onboard-wordpress-site.sh',
				'--wordpress-path ' . escapeshellarg( $path ),
				'--site-id ' . escapeshellarg( $this->settings->siteId() ),
				'--node-id ' . escapeshellarg( $this->settings->sourceHost() ),
				'--php-user ' . escapeshellarg( $user ),
			)
		);
	}

	private function detectedUser(): string {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$entry = posix_getpwuid( posix_geteuid() );
			if ( is_array( $entry ) && isset( $entry['name'] ) && '' !== trim( (string) $entry['name'] ) ) {
				return (string) $entry['name'];
			}
		}
		return '<php-fpm-user>';
	}
}
