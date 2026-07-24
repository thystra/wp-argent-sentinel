<?php

namespace ArgentSentinel\WordPress\Http;

final class RequestContext {

	/** @var string|null */
	private $source_ip;
	/** @var string|null */
	private $user_agent;
	/** @var string|null */
	private $method;
	/** @var string|null */
	private $path;
	/** @var string|null */
	private $request_id;

	public function __construct(
		?string $source_ip,
		?string $user_agent,
		?string $method,
		?string $path,
		?string $request_id = null
	) {
		$this->source_ip  = $source_ip;
		$this->user_agent = $user_agent;
		$this->method     = $method;
		$this->path       = $path;
		$this->request_id = $request_id;
	}

	public function sourceIp(): ?string {
		return $this->source_ip;
	}

	public function userAgent(): ?string {
		return $this->user_agent;
	}

	public function method(): ?string {
		return $this->method;
	}

	public function path(): ?string {
		return $this->path;
	}

	public function requestId(): ?string {
		return $this->request_id;
	}
}
