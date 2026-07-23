<?php

namespace ArgentSentinel\WordPress\Settings;

final class Settings {
	public const OPTION_NAME        = 'argent_sentinel_settings';
	public const HMAC_SECRET_OPTION = 'argent_sentinel_hmac_secret';
	private const MINIMUM_HMAC_SECRET_BYTES = 32;

	/** @var array<string,mixed>|null */
	private $values;

	public static function installDefaults(): void {
		$settings = new self();

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $settings->defaults(), '', 'no' );
		}

		if ( false === get_option( self::HMAC_SECRET_OPTION, false ) ) {
			try {
				$secret = bin2hex( random_bytes( 32 ) );
				add_option( self::HMAC_SECRET_OPTION, $secret, '', 'no' );
			} catch ( \Exception $exception ) {
				/*
				 * Do not fall back to a weak secret. Diagnostics will report a
				 * missing HMAC secret, and email identifiers will be omitted.
				 */
			}
		}
	}

	public function siteId(): string {
		$constant = $this->stringConstant( 'ARGENT_SENTINEL_SITE_ID' );
		$value    = null !== $constant ? $constant : (string) $this->get( 'site_id' );

		return self::normalizeIdentifier( $value, 'wordpress-site' );
	}

	public function siteUrl(): string {
		return trailingslashit( home_url( '/' ) );
	}

	public function sourceHost(): string {
		$constant = $this->stringConstant( 'ARGENT_SENTINEL_SOURCE_HOST' );
		$value    = null !== $constant ? $constant : (string) $this->get( 'source_host' );
		$value    = preg_replace( '/[^a-zA-Z0-9._-]/', '-', $value );
		$value    = substr( trim( (string) $value, '-' ), 0, 191 );

		return '' === $value ? 'wordpress-host' : $value;
	}

	/**
	 * @return array<int,string>
	 */
	public function trustedProxyCidrs(): array {
		$constant = $this->stringConstant( 'ARGENT_SENTINEL_TRUSTED_PROXY_CIDRS' );
		$value    = null !== $constant ? $constant : $this->get( 'trusted_proxy_cidrs' );

		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $cidr ): string {
						return trim( (string) $cidr );
					},
					$value
				)
			)
		);
	}

	public function hmacSecret(): string {
		$constant = $this->stringConstant( 'ARGENT_SENTINEL_HMAC_SECRET' );

		if ( null !== $constant ) {
			return strlen( $constant ) >= self::MINIMUM_HMAC_SECRET_BYTES ? $constant : '';
		}

		$value = get_option( self::HMAC_SECRET_OPTION, '' );

		return is_string( $value ) && strlen( $value ) >= self::MINIMUM_HMAC_SECRET_BYTES ? $value : '';
	}

	public function dropDirectory(): string {
		$constant = $this->stringConstant( 'ARGENT_SENTINEL_DROP_DIRECTORY' );

		return null !== $constant ? $constant : (string) $this->get( 'drop_directory' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		$site_id = self::normalizeIdentifier( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'wordpress-site' );
		$host    = gethostname();

		if ( false === $host || '' === $host ) {
			$host = 'wordpress-host';
		}

		return array(
			'site_id'                => $site_id,
			'source_host'            => self::normalizeIdentifier( $host, 'wordpress-host' ),
			'drop_directory'         => '/var/lib/argent-sentinel/drop/wordpress/' . $site_id . '/incoming',
			'trusted_proxy_cidrs'    => array(),
			'max_events_per_batch'   => 500,
			'max_batch_bytes'        => 5242880,
			'local_retention_days'   => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * @return mixed
	 */
	private function get( string $key ) {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION_NAME, array() );
			$stored       = is_array( $stored ) ? $stored : array();
			$this->values = array_merge( $this->defaults(), $stored );

			/**
			 * Filters effective Argent Sentinel settings.
			 *
			 * @param array<string,mixed> $settings Effective settings.
			 */
			$filtered     = apply_filters( 'argent_sentinel_settings', $this->values );
			$this->values = is_array( $filtered ) ? $filtered : $this->values;
		}

		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : null;
	}

	private function stringConstant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	private static function normalizeIdentifier( string $value, string $fallback ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value = trim( (string) $value, '-' );

		return '' === $value ? $fallback : substr( $value, 0, 191 );
	}
}
