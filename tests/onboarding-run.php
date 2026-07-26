<?php

declare(strict_types=1);

namespace {
	$GLOBALS['argent_options'] = array(
		'argent_sentinel_settings' => array(
			'site_id' => 'old-site',
			'source_host' => 'old-host',
			'drop_directory' => '/old/drop',
			'local_retention_days' => 30,
		),
		'argent_sentinel_hmac_secret' => str_repeat( 'a', 64 ),
	);
	$GLOBALS['argent_cli_commands'] = array();
	$GLOBALS['argent_cli_rows'] = array();
	$GLOBALS['argent_export_scheduled'] = 0;
	$GLOBALS['argent_prune_scheduled'] = 0;

	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['argent_options'] ) ? $GLOBALS['argent_options'][ $name ] : $default;
	}
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['argent_options'][ $name ] = $value;
		return true;
	}
	function apply_filters( string $hook, $value ) { return $value; }
	function trailingslashit( string $value ): string { return rtrim( $value, '/' ) . '/'; }
	function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }

	define( 'ABSPATH', '/srv/example/public/' );
	define( 'ARGENT_SENTINEL_ONBOARDING_HELPER', '/usr/sbin/argent-sentinel-onboard-wordpress' );

	final class WP_CLI {
		public static function add_command( string $name, $command ): void {
			$GLOBALS['argent_cli_commands'][ $name ] = $command;
		}
		public static function error( string $message ): void {
			throw new \RuntimeException( $message );
		}
		public static function halt( int $status ): void {
			throw new \RuntimeException( 'halt:' . $status );
		}
	}
}

namespace WP_CLI\Utils {
	function format_items( string $format, array $rows, array $fields ): void {
		$GLOBALS['argent_cli_rows'][] = array(
			'format' => $format,
			'rows' => $rows,
			'fields' => $fields,
		);
	}
}

namespace ArgentSentinel\WordPress\Export {
	final class FakeResult {
		public function toArray(): array {
			return array(
				'status' => 'empty',
				'event_count' => 0,
				'batch_uuid' => null,
				'path' => null,
				'error' => null,
			);
		}
	}
	final class BatchExporter {
		public function exportOnce(): FakeResult { return new FakeResult(); }
		public function pruneOnce( int $limit ): int { return $limit; }
	}
	final class CronExporter {
		public static function scheduleNextExport(): void { ++$GLOBALS['argent_export_scheduled']; }
		public static function scheduleNextPrune(): void { ++$GLOBALS['argent_prune_scheduled']; }
	}
}

namespace ArgentSentinel\WordPress\Diagnostics {
	final class Diagnostics {
		public function snapshot(): array {
			return array(
				'overall_status' => 'ready',
				'site_id' => 'example-site',
			);
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Settings/Settings.php';
	require dirname( __DIR__ ) . '/src/Onboarding/Configuration.php';
	require dirname( __DIR__ ) . '/src/Onboarding/CommandBuilder.php';
	require dirname( __DIR__ ) . '/src/CLI/Command.php';

	use ArgentSentinel\WordPress\CLI\Command;
	use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
	use ArgentSentinel\WordPress\Export\BatchExporter;
	use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
	use ArgentSentinel\WordPress\Onboarding\Configuration;
	use ArgentSentinel\WordPress\Settings\Settings;

	$failed = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			++$failed;
		}
	};

	$result = ( new Configuration() )->update(
		array(
			'site_id' => 'Example Site!',
			'source_host' => 'nidhoggur.argentwolf.org',
			'drop_directory' => '/var/lib/argent-sentinel/drop/wordpress/example-site/incoming',
		)
	);
	$assert( 'example-site' === $result['site_id'], 'Site ID is normalized.' );
	$assert( 30 === $GLOBALS['argent_options']['argent_sentinel_settings']['local_retention_days'], 'Existing settings are preserved.' );
	$assert( str_repeat( 'a', 64 ) === $GLOBALS['argent_options']['argent_sentinel_hmac_secret'], 'HMAC secret is preserved.' );

	$threw = false;
	try {
		( new Configuration() )->update( array( 'drop_directory' => '../unsafe' ) );
	} catch ( \InvalidArgumentException $exception ) {
		$threw = true;
	}
	$assert( $threw, 'Relative traversal path is rejected.' );

	$settings = new Settings();
	$builder = new CommandBuilder( $settings );
	$command_text = $builder->command( 'example-fpm', '/srv/example/public' );
	$assert( false !== strpos( $command_text, '--site-id' ), 'Onboarding command contains site ID.' );
	$assert( false !== strpos( $command_text, 'example-fpm' ), 'Onboarding command contains PHP user.' );
	$assert( false !== strpos( $command_text, '--open-basedir-mode prompt' ), 'Onboarding command prompts for open_basedir review.' );
	$assert( '/usr/sbin/argent-sentinel-onboard-wordpress' === $builder->candidatePaths()[0], 'Packaged onboarding helper is primary.' );
	$assert( CommandBuilder::HELPER_UNVERIFIABLE_BASEDIR === $builder->helperState( '/srv/example:/tmp' ), 'A helper outside open_basedir is unverifiable, not missing.' );
	$assert( null === $builder->helperExists( '/srv/example:/tmp' ), 'Unverifiable helper existence is represented as null.' );
	$assert( false === strpos( $command_text, 'argent_sentinel_hmac_secret' ), 'Onboarding command does not expose HMAC secret.' );

	$command = new Command(
		new BatchExporter(),
		new Diagnostics(),
		$settings,
		$builder
	);
	$command->register();
	$assert( isset( $GLOBALS['argent_cli_commands']['argent-sentinel'] ), 'The argent-sentinel WP-CLI namespace is registered.' );
	$assert( method_exists( $command, 'setup' ), 'The setup subcommand exists.' );
	$assert( method_exists( $command, 'status' ), 'The status subcommand exists.' );
	$assert( method_exists( $command, 'export' ), 'The export subcommand exists.' );
	$assert( method_exists( $command, 'onboarding_command' ), 'The onboarding-command subcommand exists.' );

	$command->setup(
		array(),
		array(
			'site-id' => 'CLI Site',
			'source-host' => 'nidhoggur',
			'drop-directory' => '/var/lib/argent-sentinel/drop/wordpress/cli-site/incoming',
			'format' => 'json',
		)
	);
	$assert( 'cli-site' === $GLOBALS['argent_options']['argent_sentinel_settings']['site_id'], 'CLI setup stores normalized Site ID.' );
	$assert( 1 === $GLOBALS['argent_export_scheduled'], 'CLI setup schedules export.' );
	$assert( 1 === $GLOBALS['argent_prune_scheduled'], 'CLI setup schedules pruning.' );

	$command->status( array(), array( 'strict' => true, 'format' => 'json' ) );
	$command->onboarding_command(
		array(),
		array(
			'php-user' => 'example-fpm',
			'wordpress-path' => '/srv/example/public',
			'format' => 'json',
		)
	);
	$last = end( $GLOBALS['argent_cli_rows'] );
	$assert( false !== strpos( (string) $last['rows'][0]['command'], 'example-fpm' ), 'CLI onboarding command honors the PHP user override.' );

	if ( 0 !== $failed ) {
		exit( 1 );
	}
	echo "Onboarding and WP-CLI tests passed.\n";
}
