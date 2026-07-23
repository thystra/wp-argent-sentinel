<?php

namespace ArgentSentinel\WordPress;

use ArgentSentinel\WordPress\Database\Schema;
use ArgentSentinel\WordPress\Export\CronExporter;
use ArgentSentinel\WordPress\Settings\Settings;

final class Activation {

	public const ACTIVATED_AT_OPTION = 'argent_sentinel_activated_at_utc';
	public const RETRY_AFTER_OPTION  = 'argent_sentinel_schema_retry_after';

	private const RETRY_DELAY = 300;

	public static function activate(): void {
		global $wpdb;

		if ( (int) get_option( Schema::VERSION_OPTION, 0 ) > Schema::VERSION ) {
			throw new \RuntimeException( 'Argent Sentinel data belongs to a newer plugin schema.' );
		}

		if ( ! ( new Schema() )->install( $wpdb ) ) {
			throw new \RuntimeException( 'Argent Sentinel could not create or verify its event queue.' );
		}

		Settings::installDefaults();
		if ( false === get_option( self::ACTIVATED_AT_OPTION, false ) ) {
			add_option( self::ACTIVATED_AT_OPTION, gmdate( 'c' ), '', 'no' );
		}

		CronExporter::scheduleNextExport();
		CronExporter::scheduleNextPrune();
		delete_option( self::RETRY_AFTER_OPTION );
	}

	public static function maybeUpgrade(): bool {
		try {
			Settings::installDefaults();
		} catch ( \Throwable $throwable ) {
			return false;
		}

		$installed_version = (int) get_option( Schema::VERSION_OPTION, 0 );
		if ( $installed_version > Schema::VERSION ) {
			return false;
		}
		if ( Schema::VERSION === $installed_version ) {
			return true;
		}

		$retry_after = (int) get_option( self::RETRY_AFTER_OPTION, 0 );
		if ( $retry_after > time() ) {
			return false;
		}

		try {
			self::activate();
		} catch ( \Throwable $throwable ) {
			update_option( self::RETRY_AFTER_OPTION, time() + self::RETRY_DELAY, false );
			return false;
		}

		if ( Schema::VERSION !== (int) get_option( Schema::VERSION_OPTION, 0 ) ) {
			update_option( self::RETRY_AFTER_OPTION, time() + self::RETRY_DELAY, false );
			return false;
		}

		return true;
	}
}
