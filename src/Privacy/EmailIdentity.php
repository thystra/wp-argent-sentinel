<?php

namespace ArgentSentinel\WordPress\Privacy;

final class EmailIdentity {
	/** @var string */
	private $secret;

	public function __construct( string $secret ) {
		$this->secret = $secret;
	}

	public static function looksLikeEmail( string $value ): bool {
		$at = strrpos( trim( $value ), '@' );

		return false !== $at && $at > 0 && $at < strlen( trim( $value ) ) - 1;
	}

	/**
	 * @return array{domain:?string,identifier:?string}
	 */
	public function describe( string $email ): array {
		$normalized = strtolower( trim( $email ) );

		if ( ! self::looksLikeEmail( $normalized ) ) {
			return array(
				'domain'     => null,
				'identifier' => null,
			);
		}

		$at     = strrpos( $normalized, '@' );
		$domain = substr( $normalized, $at + 1 );
		$domain = $this->normalizeDomain( $domain );

		return array(
			'domain'     => $domain,
			'identifier' => '' === $this->secret ? null : hash_hmac( 'sha256', $normalized, $this->secret ),
		);
	}

	public function identifyOpaqueValue( string $value ): ?string {
		$value = strtolower( trim( $value ) );

		if ( '' === $value || '' === $this->secret ) {
			return null;
		}

		return hash_hmac( 'sha256', $value, $this->secret );
	}

	private function normalizeDomain( string $domain ): ?string {
		$domain = rtrim( strtolower( trim( $domain ) ), '.' );

		if ( '' === $domain || strlen( $domain ) > 253 || 1 !== preg_match( '/^[a-z0-9.-]+$/', $domain ) ) {
			return null;
		}

		return $domain;
	}
}
