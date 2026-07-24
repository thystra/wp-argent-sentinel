<?php

namespace ArgentSentinel\WordPress\Onboarding;

use ArgentSentinel\WordPress\Settings\Settings;

final class Configuration {

	/**
	 * Update option-backed settings without modifying wp-config.php.
	 * Existing unrecognized settings and the separately stored HMAC secret are preserved.
	 *
	 * @param array<string,mixed> $values Proposed values.
	 * @return array<string,string> Effective non-secret values.
	 */
	public function update( array $values ): array {
		$stored = get_option( Settings::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( array_key_exists( 'site_id', $values ) ) {
			$stored['site_id'] = $this->identifier( (string) $values['site_id'], 'wordpress-site' );
		}
		if ( array_key_exists( 'source_host', $values ) ) {
			$stored['source_host'] = $this->host( (string) $values['source_host'] );
		}
		if ( array_key_exists( 'drop_directory', $values ) ) {
			$stored['drop_directory'] = $this->absolutePath( (string) $values['drop_directory'] );
		}

		update_option( Settings::OPTION_NAME, $stored, false );
		$settings = new Settings();

		return array(
			'site_id'        => $settings->siteId(),
			'source_host'    => $settings->sourceHost(),
			'drop_directory' => $settings->dropDirectory(),
		);
	}

	private function identifier( string $value, string $fallback ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9._-]+/', '-', $value );
		$value = trim( (string) $value, '-._' );
		return '' === $value ? $fallback : substr( $value, 0, 128 );
	}

	private function host( string $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $value );
		$value = trim( (string) $value, '-' );
		return '' === $value ? 'wordpress-host' : substr( $value, 0, 191 );
	}

	private function absolutePath( string $value ): string {
		$value = trim( str_replace( "\0", '', $value ) );
		if ( '' === $value || DIRECTORY_SEPARATOR !== substr( $value, 0, 1 ) || false !== strpos( $value, '..' ) ) {
			throw new \InvalidArgumentException( 'The Sentinel drop directory must be a normalized absolute path.' );
		}
		return rtrim( preg_replace( '#/+#', '/', $value ), DIRECTORY_SEPARATOR );
	}
}
