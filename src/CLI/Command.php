<?php

namespace ArgentSentinel\WordPress\CLI;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Export\BatchExporter;

final class Command {

	/** @var BatchExporter */
	private $exporter;

	/** @var Diagnostics */
	private $diagnostics;

	public function __construct( BatchExporter $exporter, Diagnostics $diagnostics ) {
		$this->exporter    = $exporter;
		$this->diagnostics = $diagnostics;
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
	 * Show non-secret connector and exporter diagnostics.
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json, yaml.
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void {
		$this->render( array( $this->diagnostics->snapshot() ), $assoc_args );
	}

	/**
	 * Prune successfully exported queue rows older than retention policy.
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
		$count = $this->exporter->pruneOnce( $limit );
		$this->render( array( array( 'deleted_rows' => $count ) ), $assoc_args );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<string,mixed> $assoc_args Arguments.
	 */
	private function render( array $rows, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}
}
