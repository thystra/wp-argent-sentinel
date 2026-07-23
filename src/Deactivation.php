<?php

namespace ArgentSentinel\WordPress;

final class Deactivation {
	public static function deactivate(): void {
		/*
		 * Phase 1 does not schedule cron jobs. Future phases will unschedule
		 * namespaced jobs here without deleting queued events or user state.
		 */
	}
}
