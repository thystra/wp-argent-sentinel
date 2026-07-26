<?php

namespace ArgentSentinel\WordPress\CLI;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Export\BatchExporter;
use ArgentSentinel\WordPress\Export\CronExporter;
use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
use ArgentSentinel\WordPress\Onboarding\Configuration;
use ArgentSentinel\WordPress\Settings\Settings;

final class Command {
	/** @var BatchExporter */
	private $exporter;

	/** @var Diagnostics */
	private $diagnostics;

	/** @var Settings */
	private $settings;

	/** @var CommandBuilder */
	private $command_builder;

	public function __construct(
		BatchExporter $exporter,
		Diagnostics $diagnostics,
		Settings $settings,
		CommandBuilder $command_builder
	) {
		$this->exporter        = $exporter;
		$this->diagnostics     = $diagnostics;
		$this->settings        = $settings;
		$this->command_builder = $command_builder;
	}

	public function register(): void {
		\WP_CLI::add_command( 'argent-sentinel', $this );
	}

	/**
	 * Export one deterministic queue batch.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @when after_wp_load
	 */
	public function export( array $args, array $assoc_args ): void {
		$result = $this->exporter->exportOnce()->toArray();
		$this->render( array( $result ), $assoc_args );
		if ( ! in_array( $result['status'], array( 'success', 'empty' ), true ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Show non-secret connector, queue, spool, schedule, and onboarding status.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Exit nonzero when the connector needs attention.
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = $this->diagnostics->snapshot();
		$this->render( array( $status ), $assoc_args );
		if ( isset( $assoc_args['strict'] ) && 'ready' !== $status['overall_status'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Prune successfully exported queue rows older than retention policy.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to delete. Default 1000, maximum 5000.
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @when after_wp_load
	 */
	public function prune( array $args, array $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 1000;
		$this->render(
			array( array( 'deleted_rows' => $this->exporter->pruneOnce( $limit ) ) ),
			$assoc_args
		);
	}

	/**
	 * Store option-backed onboarding values without modifying wp-config.php.
	 *
	 * ## OPTIONS
	 *
	 * --site-id=<id>
	 * : Stable identifier for this WordPress site.
	 *
	 * --source-host=<node>
	 * : Stable Argent Sentinel node identifier.
	 *
	 * --drop-directory=<absolute-path>
	 * : Protected local spool directory outside the web root.
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @when after_wp_load
	 */
	public function setup( array $args, array $assoc_args ): void {
		foreach ( array( 'site-id', 'source-host', 'drop-directory' ) as $required ) {
			if ( empty( $assoc_args[ $required ] ) ) {
				\WP_CLI::error( '--' . $required . ' is required.' );
			}
		}

		try {
			$result = ( new Configuration() )->update(
				array(
					'site_id'        => (string) $assoc_args['site-id'],
					'source_host'    => (string) $assoc_args['source-host'],
					'drop_directory' => (string) $assoc_args['drop-directory'],
				)
			);
			$this->settings->refresh();
			CronExporter::scheduleNextExport();
			CronExporter::scheduleNextPrune();
			$result['wp_config_modified']    = false;
			$result['hmac_secret_preserved'] = '' !== $this->settings->hmacSecret();
			$result['status_command']        = 'wp argent-sentinel status --strict --format=json';
			$this->render( array( $result ), $assoc_args );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/**
	 * Print the privileged host onboarding command.
	 *
	 * ## OPTIONS
	 *
	 * [--php-user=<user>]
	 * : Override the detected PHP-FPM user.
	 *
	 * [--wordpress-path=<path>]
	 * : Override the detected WordPress path.
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @subcommand onboarding-command
	 * @when after_wp_load
	 */
	public function onboarding_command( array $args, array $assoc_args ): void {
		$php_user = isset( $assoc_args['php-user'] ) ? (string) $assoc_args['php-user'] : null;
		$wp_path  = isset( $assoc_args['wordpress-path'] ) ? (string) $assoc_args['wordpress-path'] : null;
		$this->render(
			array(
				array(
					'helper'        => $this->command_builder->helperPath(),
					'helper_exists' => $this->command_builder->helperExists(),
					'command'       => $this->command_builder->command( $php_user, $wp_path ),
				),
			),
			$assoc_args
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<string,mixed> $assoc_args Arguments.
	 */
	private function render( array $rows, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$fields = array() === $rows ? array() : array_keys( $rows[0] );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
