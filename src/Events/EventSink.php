<?php

namespace ArgentSentinel\WordPress\Events;

interface EventSink {
	/**
	 * @param array<string,mixed> $attributes Event-specific fields.
	 */
	public function record( string $event_type, string $severity, string $outcome, array $attributes = array() ): bool;
}
