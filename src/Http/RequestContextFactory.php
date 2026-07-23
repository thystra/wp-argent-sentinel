<?php

namespace ArgentSentinel\WordPress\Http;

use ArgentSentinel\WordPress\Network\ClientIpResolver;

final class RequestContextFactory {
	/** @var ClientIpResolver */
	private $ip_resolver;

	public function __construct( ClientIpResolver $ip_resolver ) {
		$this->ip_resolver = $ip_resolver;
	}

	/**
	 * @param array<string,mixed>|null $server Server variables, or null for the current request.
	 */
	public function create( ?array $server = null ): RequestContext {
		$server = null === $server ? $_SERVER : $server;
		$agent  = isset( $server['HTTP_USER_AGENT'] ) ? trim( (string) $server['HTTP_USER_AGENT'] ) : '';
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $server['REQUEST_METHOD'] ) ) : '';
		$uri    = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
		$path   = '' === $uri ? '' : parse_url( $uri, PHP_URL_PATH );
		$path   = is_string( $path ) ? $path : '';

		if ( 1 !== preg_match( '/^[A-Z]{1,16}$/', $method ) ) {
			$method = '';
		}

		return new RequestContext(
			$this->ip_resolver->resolve( $server ),
			'' === $agent ? null : substr( $agent, 0, 512 ),
			'' === $method ? null : $method,
			'' === $path ? null : substr( $path, 0, 1024 )
		);
	}
}
