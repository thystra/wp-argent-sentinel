<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['argent_export_test_options'] = array();

function get_option( string $name, $default = false ) {
	return $GLOBALS['argent_export_test_options'][ $name ] ?? $default;
}

function add_option( string $name, $value, string $deprecated = '', string $autoload = 'yes' ): bool {
	if ( array_key_exists( $name, $GLOBALS['argent_export_test_options'] ) ) {
		return false;
	}
	$GLOBALS['argent_export_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['argent_export_test_options'][ $name ] );
	return true;
}

require_once __DIR__ . '/../src/Autoloader.php';
\ArgentSentinel\WordPress\Autoloader::register( __DIR__ . '/../src' );

use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Export\BatchExporter;
use ArgentSentinel\WordPress\Export\ExportLock;
use ArgentSentinel\WordPress\Export\ExportSettings;
use ArgentSentinel\WordPress\Settings\Settings;

final class ExportTestWpdb {
	public $prefix = 'wp_';
	public $rows = array();
	public $marked_ids = array();

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = preg_replace_callback(
				'/%[ds]/',
				static function ( array $match ) use ( $arg ): string {
					return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}

	public function get_results( string $query, string $output ): array {
		return $this->rows;
	}

	public function query( string $query ) {
		if ( false !== strpos( $query, "export_state = 'exported'" ) ) {
			preg_match_all( '/\b(\d+)\b/', substr( $query, (int) strrpos( $query, 'IN (' ) ), $matches );
			$this->marked_ids = array_map( 'intval', $matches[1] );
			return count( $this->rows );
		}
		return 0;
	}

	public function get_var( string $query ) {
		return null;
	}
}

function export_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$temp = sys_get_temp_dir() . '/argent-sentinel-export-' . bin2hex( random_bytes( 6 ) );
mkdir( $temp, 0770, true );

define( 'ARGENT_SENTINEL_DROP_DIRECTORY', $temp );
$GLOBALS['argent_export_test_options'][ Settings::OPTION_NAME ] = array(
	'max_events_per_batch' => 500,
	'max_batch_bytes'      => 5242880,
	'local_retention_days' => 30,
);

$wpdb       = new ExportTestWpdb();
$base       = array(
	'batch_uuid'         => null,
	'occurred_at_utc'    => '2026-07-23 17:15:16',
	'recorded_at_utc'    => '2026-07-23 17:15:16',
	'site_id'            => 'wolfandraven-blog',
	'site_url'           => 'https://www.wolfandraven.blog/',
	'source_host'        => 'nidhoggur',
	'service'            => 'wordpress',
	'event_type'         => 'login_failed',
	'severity'           => 'warning',
	'outcome'            => 'denied',
	'source_ip'          => '198.199.90.202',
	'source_ip_version'  => 4,
	'username'           => 'alan',
	'wordpress_user_id'  => 1,
	'email_domain'       => null,
	'email_identifier'   => null,
	'user_agent'         => 'Unit Test',
	'request_method'     => 'POST',
	'request_path'       => '/wp-login.php',
	'metadata_json'      => '{"account_resolution":"found"}',
	'export_state'       => 'queued',
	'exported_at_utc'    => null,
	'retry_count'        => 0,
	'last_export_error'  => null,
);
$wpdb->rows = array(
	array_merge( $base, array( 'id' => 1, 'event_uuid' => '11111111-1111-4111-8111-111111111111' ) ),
	array_merge( $base, array( 'id' => 2, 'event_uuid' => '22222222-2222-4222-8222-222222222222', 'username' => 'meagan', 'wordpress_user_id' => 3 ) ),
);

$settings = new Settings();
$exporter = new BatchExporter(
	new QueueRepository( $wpdb ),
	new ExportSettings( $settings ),
	new ExportLock(),
	'wolfandraven-blog',
	'https://www.wolfandraven.blog/',
	'nidhoggur'
);
$result   = $exporter->exportOnce()->toArray();

export_test_assert( 'success' === $result['status'], 'Exporter reports success.' );
export_test_assert( 2 === $result['event_count'], 'Exporter includes both queued events.' );
export_test_assert( is_string( $result['path'] ) && is_file( $result['path'] ), 'Exporter atomically publishes a JSON file.' );
$decoded = json_decode( (string) file_get_contents( $result['path'] ), true );
export_test_assert( is_array( $decoded ), 'Exported batch is valid JSON.' );
export_test_assert( 1 === $decoded['schema_version'], 'Batch schema version is one.' );
export_test_assert( 'wolfandraven-blog' === $decoded['source']['site_id'], 'Batch preserves site identity.' );
export_test_assert( 2 === count( $decoded['events'] ), 'Batch contains two events.' );
export_test_assert( '198.199.90.202' === $decoded['events'][0]['source_ip'], 'Batch preserves normalized source IP.' );
export_test_assert( array( 1, 2 ) === $wpdb->marked_ids, 'Exporter marks exactly the emitted rows exported.' );

foreach ( glob( $temp . '/*' ) ?: array() as $file ) {
	unlink( $file );
}
rmdir( $temp );

echo "Argent Sentinel exporter tests passed.\n";
