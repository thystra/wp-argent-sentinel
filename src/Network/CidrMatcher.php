<?php

namespace ArgentSentinel\WordPress\Network;

final class CidrMatcher {
	public function contains( string $cidr, string $address ): bool {
		$address = IpAddress::normalize( $address );

		if ( null === $address ) {
			return false;
		}

		$parts   = explode( '/', trim( $cidr ), 2 );
		$network = IpAddress::normalize( $parts[0] );

		if ( null === $network ) {
			return false;
		}

		$network_binary = @inet_pton( $network );
		$address_binary = @inet_pton( $address );

		if (
			false === $network_binary
			|| false === $address_binary
			|| strlen( $network_binary ) !== strlen( $address_binary )
		) {
			return false;
		}

		$maximum_prefix = 4 === strlen( $network_binary ) ? 32 : 128;
		$prefix         = isset( $parts[1] ) && '' !== $parts[1]
			? filter_var( $parts[1], FILTER_VALIDATE_INT )
			: $maximum_prefix;

		if ( false === $prefix || $prefix < 0 || $prefix > $maximum_prefix ) {
			return false;
		}

		$whole_bytes = intdiv( $prefix, 8 );
		$remaining   = $prefix % 8;

		if ( $whole_bytes > 0 && substr( $network_binary, 0, $whole_bytes ) !== substr( $address_binary, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $remaining ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining ) ) & 0xff;

		return ( ord( $network_binary[ $whole_bytes ] ) & $mask ) === ( ord( $address_binary[ $whole_bytes ] ) & $mask );
	}
}
