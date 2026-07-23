<?php

namespace ArgentSentinel\WordPress\Network;

final class ClientIpResolver {
	private const MAX_FORWARDED_HOPS = 32;
	private const MAX_HEADER_BYTES   = 8192;

	/** @var array<int,string> */
	private $trusted_proxy_cidrs;

	/** @var CidrMatcher */
	private $cidr_matcher;

	/**
	 * @param array<int,string> $trusted_proxy_cidrs Trusted immediate peers and proxy hops.
	 */
	public function __construct( array $trusted_proxy_cidrs, ?CidrMatcher $cidr_matcher = null ) {
		$this->trusted_proxy_cidrs = $trusted_proxy_cidrs;
		$this->cidr_matcher        = $cidr_matcher ?? new CidrMatcher();
	}

	/**
	 * Resolves a client IP without trusting forwarded data from an untrusted peer.
	 *
	 * @param array<string,mixed> $server Server variables.
	 */
	public function resolve( array $server ): ?string {
		$peer = isset( $server['REMOTE_ADDR'] ) ? IpAddress::normalize( (string) $server['REMOTE_ADDR'] ) : null;

		if ( null === $peer ) {
			return null;
		}

		if ( ! $this->isTrustedProxy( $peer ) ) {
			return $peer;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? trim( (string) $server['HTTP_X_FORWARDED_FOR'] ) : '';

		if ( '' === $forwarded ) {
			return $peer;
		}

		if ( strlen( $forwarded ) > self::MAX_HEADER_BYTES ) {
			$forwarded = substr( $forwarded, -self::MAX_HEADER_BYTES );
		}

		$parts          = explode( ',', $forwarded );
		$processed_hops = 0;
		$leftmost       = $peer;

		for ( $index = count( $parts ) - 1; $index >= 0; --$index ) {
			if ( $processed_hops >= self::MAX_FORWARDED_HOPS ) {
				return $peer;
			}

			$address = IpAddress::normalize( trim( $parts[ $index ] ) );

			if ( null === $address ) {
				return $peer;
			}

			$leftmost = $address;
			++$processed_hops;

			if ( ! $this->isTrustedProxy( $address ) ) {
				/*
				 * Everything to the left was supplied by this untrusted hop
				 * and is not part of the trusted proxy chain.
				 */
				return $address;
			}
		}

		return $leftmost;
	}

	private function isTrustedProxy( string $address ): bool {
		foreach ( $this->trusted_proxy_cidrs as $cidr ) {
			if ( $this->cidr_matcher->contains( $cidr, $address ) ) {
				return true;
			}
		}

		return false;
	}
}
