<?php

namespace ArgentSentinel\WordPress\Events;

final class Severity {
	public const INFO     = 'info';
	public const NOTICE   = 'notice';
	public const WARNING  = 'warning';
	public const ERROR    = 'error';
	public const CRITICAL = 'critical';

	/**
	 * @return array<int,string>
	 */
	public static function values(): array {
		return array(
			self::INFO,
			self::NOTICE,
			self::WARNING,
			self::ERROR,
			self::CRITICAL,
		);
	}

	private function __construct() {
	}
}
