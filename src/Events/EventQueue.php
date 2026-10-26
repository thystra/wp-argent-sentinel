<?php

namespace ArgentSentinel\WordPress\Events;

/**
 * Durable local event queue boundary used by in-process producers.
 */
interface EventQueue {
	public function insert( Event $event ): bool;

	public function hasEvent( string $event_uuid ): bool;
}
