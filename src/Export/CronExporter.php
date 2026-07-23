<?php

namespace ArgentSentinel\WordPress\Export;

final class CronExporter {

	public const EXPORT_HOOK = 'argent_sentinel_export_queue';
	public const PRUNE_HOOK  = 'argent_sentinel_prune_exported_queue';

	/** @var BatchExporter */
	private $exporter;

	public function __construct( BatchExporter $exporter ) {
		$this->exporter = $exporter;
	}

	public function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::EXPORT_HOOK, array( $this, 'runExport' ) );
			add_action( self::PRUNE_HOOK, array( $this, 'runPrune' ) );
		}
		self::scheduleNextExport();
		self::scheduleNextPrune();
	}

	public function runExport(): void {
		try {
			$this->exporter->exportOnce();
		} catch ( \Throwable $throwable ) {
			// Export must never make a WordPress request fail.
		} finally {
			self::scheduleNextExport();
		}
	}

	public function runPrune(): void {
		try {
			$this->exporter->pruneOnce();
		} catch ( \Throwable $throwable ) {
			// Retention cleanup is best effort and retried later.
		} finally {
			self::scheduleNextPrune();
		}
	}

	public static function scheduleNextExport(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::EXPORT_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::EXPORT_HOOK );
		}
	}

	public static function scheduleNextPrune(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_single_event( time() + 3600, self::PRUNE_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::EXPORT_HOOK );
			wp_clear_scheduled_hook( self::PRUNE_HOOK );
		}
		if ( function_exists( 'delete_option' ) ) {
			delete_option( ExportLock::OPTION_NAME );
		}
	}
}
