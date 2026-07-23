<?php

namespace ArgentSentinel\WordPress;

use ArgentSentinel\WordPress\Export\CronExporter;

final class Deactivation {

	public static function deactivate(): void {
		CronExporter::unschedule();
	}
}
