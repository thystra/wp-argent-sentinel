<?php

namespace ArgentSentinel\WordPress\Export;

final class ExportLock {

	public const OPTION_NAME = 'argent_sentinel_export_lock';

	/** @var string|null */
	private $token;

	public function acquire( int $ttl_seconds = 300 ): bool {
		$ttl_seconds = max( 30, min( 1800, $ttl_seconds ) );
		$token       = bin2hex( random_bytes( 16 ) );
		$value       = array(
			'token'      => $token,
			'expires_at' => time() + $ttl_seconds,
		);

		if ( add_option( self::OPTION_NAME, $value, '', 'no' ) ) {
			$this->token = $token;
			return true;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) >= time() ) {
			return false;
		}

		delete_option( self::OPTION_NAME );
		if ( ! add_option( self::OPTION_NAME, $value, '', 'no' ) ) {
			return false;
		}

		$this->token = $token;
		return true;
	}

	public function release(): void {
		if ( null === $this->token ) {
			return;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( is_array( $current ) && hash_equals( $this->token, (string) ( $current['token'] ?? '' ) ) ) {
			delete_option( self::OPTION_NAME );
		}

		$this->token = null;
	}
}
