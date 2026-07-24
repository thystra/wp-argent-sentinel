<?php

namespace ArgentSentinel\WordPress\Events;

use ArgentSentinel\WordPress\Privacy\EmailIdentity;
use ArgentSentinel\WordPress\Http\RequestContextFactory;

final class EventRecorder implements EventSink {

	/** @var QueueRepository */
	private $queue;
	/** @var RequestContextFactory */
	private $request_context_factory;
	/** @var EmailIdentity */
	private $email_identity;
	/** @var string */
	private $site_id;
	/** @var string */
	private $site_url;
	/** @var string */
	private $source_host;

	public function __construct(
		QueueRepository $queue,
		RequestContextFactory $request_context_factory,
		EmailIdentity $email_identity,
		string $site_id,
		string $site_url,
		string $source_host
	) {
		$this->queue                   = $queue;
		$this->request_context_factory = $request_context_factory;
		$this->email_identity          = $email_identity;
		$this->site_id                 = $site_id;
		$this->site_url                = $site_url;
		$this->source_host             = $source_host;
	}

	/**
	 * @param array<string,mixed> $attributes Event-specific fields.
	 */
	public function record( string $event_type, string $severity, string $outcome, array $attributes = array() ): bool {
		try {
			$request  = $this->request_context_factory->create();
			$email    = isset( $attributes['email'] )
				? $this->email_identity->describe( (string) $attributes['email'] )
				: array( 'domain' => null, 'identifier' => null );
			$metadata = isset( $attributes['metadata'] ) && is_array( $attributes['metadata'] )
				? $attributes['metadata']
				: array();

			// This value is injected by Nginx/FastCGI, not read from an HTTP header.
			if ( null !== $request->requestId() && ! array_key_exists( 'request_id', $metadata ) ) {
				$metadata['request_id'] = $request->requestId();
			}

			$event_attributes = array(
				'source_ip'          => array_key_exists( 'source_ip', $attributes ) ? $attributes['source_ip'] : $request->sourceIp(),
				'username'           => $attributes['username'] ?? null,
				'wordpress_user_id'  => $attributes['wordpress_user_id'] ?? null,
				'email_domain'       => $email['domain'],
				'email_identifier'   => $email['identifier'],
				'user_agent'         => array_key_exists( 'user_agent', $attributes ) ? $attributes['user_agent'] : $request->userAgent(),
				'request_method'     => array_key_exists( 'request_method', $attributes ) ? $attributes['request_method'] : $request->method(),
				'request_path'       => array_key_exists( 'request_path', $attributes ) ? $attributes['request_path'] : $request->path(),
				'metadata'           => $metadata,
			);

			return $this->queue->insert(
				Event::create(
					$event_type,
					$severity,
					$outcome,
					$this->site_id,
					$this->site_url,
					$this->source_host,
					$event_attributes
				)
			);
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}
}
