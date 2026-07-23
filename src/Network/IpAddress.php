<?php

namespace ArgentSentinel\WordPress\Network;

final class IpAddress {
	public static function normalize( string $address ): ?string {
		$address = trim( $address );

		if ( '' === $address || false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = @inet_pton( $address );

		if ( false === $packed ) {
			return null;
		}

		$normalized = @inet_ntop( $packed );

		return false === $normalized ? null : strtolower( $normalized );
	}

	public static function version( string $address ): ?int {
		$normalized = self::normalize( $address );

		if ( null === $normalized ) {
			return null;
		}

		return false !== filter_var( $normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? 4 : 6;
	}

	private function __construct() {
	}
}
