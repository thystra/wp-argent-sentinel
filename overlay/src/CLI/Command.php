<?php

namespace ArgentSentinel\WordPress\CLI;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Export\BatchExporter;
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

	/** Export one deterministic queue batch. @when after_wp_load */
	public function export( array $args, array $assoc_args ): void {
		$result = $this->exporter->exportOnce()->toArray();
		$this->render( array( $result ), $assoc_args );
		if ( ! in_array( $result['status'], array( 'success', 'empty' ), true ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/** Show non-secret connector and exporter diagnostics. @when after_wp_load */
	public function status( array $args, array $assoc_args ): void {
		$this->render( array( $this->diagnostics->snapshot() ), $assoc_args );
	}

	/** Prune successfully exported queue rows older than retention policy. @when after_wp_load */
	public function prune( array $args, array $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 1000;
		$this->render( array( array( 'deleted_rows' => $this->exporter->pruneOnce( $limit ) ) ), $assoc_args );
	}

	/**
	 * Store option-backed onboarding values. This command does not edit wp-config.php.
	 *
	 * ## OPTIONS
	 * --site-id=<id>
	 * --source-host=<node>
	 * --drop-directory=<absolute-path>
	 * [--format=<format>]
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
			$result['wp_config_modified'] = false;
			$result['hmac_secret_preserved'] = '' !== $this->settings->hmacSecret();
			$this->render( array( $result ), $assoc_args );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/** Print the privileged host onboarding command. @when after_wp_load */
	public function onboarding_command( array $args, array $assoc_args ): void {
		$this->render(
			array( array( 'command' => $this->command_builder->command() ) ),
			$assoc_args
		);
	}

	/** @param array<int,array<string,mixed>> $rows Rows. @param array<string,mixed> $assoc_args Arguments. */
	private function render( array $rows, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}
}
