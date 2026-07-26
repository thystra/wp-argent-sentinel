<?php

declare(strict_types=1);

namespace {
	$GLOBALS['argent_actions'] = array();
	$GLOBALS['argent_filters'] = array();

	function add_action( string $hook, $callback ): void { $GLOBALS['argent_actions'][ $hook ] = $callback; }
	function add_filter( string $hook, $callback ): void { $GLOBALS['argent_filters'][ $hook ] = $callback; }
	function add_options_page( ...$args ): void {}
	function current_user_can( string $capability ): bool { return true; }
	function check_admin_referer( string $action ): void {}
	function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_nonce" value="' . htmlspecialchars( $action, ENT_QUOTES ) . '">'; }
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
	function add_query_arg( $args, $url = '' ): string {
		if ( is_string( $args ) ) {
			$args = array( $args => $url );
			$url = '';
		}
		return (string) $url . '?' . http_build_query( (array) $args );
	}
	function wp_safe_redirect( string $url ): void {}
	function wp_die( string $message ): void { throw new \RuntimeException( $message ); }
	function esc_url( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_html( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_textarea( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
}

namespace ArgentSentinel\WordPress\Settings {
	final class Settings {
		public function siteId(): string { return 'wolfandraven-blog'; }
		public function sourceHost(): string { return 'nidhoggur'; }
		public function dropDirectory(): string { return '/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming'; }
	}
}

namespace ArgentSentinel\WordPress\Onboarding {
	final class Configuration {
		public function update( array $values ): array { return $values; }
	}
	final class CommandBuilder {
		public function command(): string { return 'sudo /usr/sbin/argent-sentinel-onboard-wordpress --site-id wolfandraven-blog'; }
		public function helperPath(): string { return '/usr/sbin/argent-sentinel-onboard-wordpress'; }
		public function helperExists(): bool { return true; }
	}
}

namespace ArgentSentinel\WordPress\Diagnostics {
	final class Diagnostics {
		/** @var array<string,mixed> */
		public $status;
		public function __construct() {
			$this->status = array(
				'overall_status' => 'ready',
				'issues' => '',
				'plugin_version' => '0.2.3',
				'expected_schema_version' => 1,
				'installed_schema_version' => 1,
				'site_id' => 'wolfandraven-blog',
				'site_url' => 'https://wolfandraven.blog/',
				'source_host' => 'nidhoggur',
				'configuration_source' => 'WordPress options',
				'php_process_user' => 'wolfandraven',
				'open_basedir' => '/srv:/tmp:/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming',
				'open_basedir_enabled' => true,
				'open_basedir_allows_drop' => true,
				'open_basedir_required_entry' => '/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming',
				'open_basedir_suggested_value' => '/srv:/tmp:/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming',
				'drop_directory_access_state' => 'ready',
				'drop_directory' => '/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming',
				'drop_directory_exists' => true,
				'drop_directory_writable' => true,
				'drop_directory_symlink' => false,
				'drop_directory_outside_web_root' => true,
				'drop_directory_owner' => 'wolfandraven',
				'drop_directory_group' => 'sentinel',
				'drop_directory_mode' => '2770',
				'hmac_secret_configured' => true,
				'request_id_available' => true,
				'next_export_at_utc' => '2026-07-26T00:00:00+00:00',
				'next_prune_at_utc' => '2026-07-26T01:00:00+00:00',
				'onboarding_helper' => '/usr/sbin/argent-sentinel-onboard-wordpress',
				'onboarding_helper_exists' => true,
				'queued_count' => 3,
				'oldest_queued_at' => '2026-07-25 23:00:00',
				'exported_count' => 42,
				'last_exported_at' => '2026-07-25 23:55:00',
				'last_export_error' => null,
			);
		}
		public function snapshot(): array { return $this->status; }
	}
}

namespace ArgentSentinel\WordPress\Export {
	final class FakeResult {
		public function toArray(): array {
			return array( 'status' => 'empty', 'event_count' => 0, 'error' => null );
		}
	}
	final class BatchExporter {
		public function exportOnce(): FakeResult { return new FakeResult(); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Admin/AdminPage.php';
	require dirname( __DIR__ ) . '/src/Admin/SiteHealth.php';

	use ArgentSentinel\WordPress\Admin\AdminPage;
	use ArgentSentinel\WordPress\Admin\SiteHealth;
	use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
	use ArgentSentinel\WordPress\Export\BatchExporter;
	use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
	use ArgentSentinel\WordPress\Settings\Settings;

	$failed = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			++$failed;
		}
	};

	$diagnostics = new Diagnostics();
	$page = new AdminPage(
		new Settings(),
		$diagnostics,
		new CommandBuilder(),
		new BatchExporter()
	);
	$page->register();
	$assert( isset( $GLOBALS['argent_actions']['admin_menu'] ), 'Admin menu hook is registered.' );
	$assert( isset( $GLOBALS['argent_actions']['admin_notices'] ), 'Admin notice hook is registered.' );
	$assert( isset( $GLOBALS['argent_actions']['admin_post_argent_sentinel_export_now'] ), 'Manual export action is registered.' );

	ob_start();
	$page->render();
	$html = (string) ob_get_clean();
	$assert( false !== strpos( $html, 'Connector status' ), 'Status summary is rendered.' );
	$assert( false !== strpos( $html, 'Queued events' ), 'Queue status is rendered.' );
	$assert( false !== strpos( $html, 'Site setup' ), 'Setup form is rendered.' );
	$assert( false !== strpos( $html, 'Host provisioning command' ), 'Provisioning command is rendered.' );
	$assert( false !== strpos( $html, 'Operational diagnostics' ), 'Detailed diagnostics are rendered.' );
	$assert( false === strpos( $html, str_repeat( 'a', 64 ) ), 'No HMAC secret is rendered.' );

	$health = new SiteHealth( $diagnostics );
	$health->register();
	$assert( isset( $GLOBALS['argent_filters']['site_status_tests'] ), 'Site Health tests hook is registered.' );
	$tests = $health->tests( array( 'direct' => array() ) );
	$assert( isset( $tests['direct']['argent_sentinel_configuration'] ), 'Configuration health test is available.' );
	$assert( isset( $tests['direct']['argent_sentinel_drop_directory'] ), 'Drop-directory health test is available.' );
	$assert( isset( $tests['direct']['argent_sentinel_open_basedir'] ), 'open_basedir health test is available.' );
	$assert( 'good' === $health->testOpenBasedir()['status'], 'Allowed open_basedir returns good.' );
	$assert( 'good' === $health->testDropDirectory()['status'], 'Healthy drop directory returns good.' );

	$diagnostics->status['drop_directory_writable'] = false;
	$assert( 'critical' === $health->testDropDirectory()['status'], 'Unwritable drop directory returns critical.' );

	$diagnostics->status['open_basedir_allows_drop'] = false;
	$assert( 'critical' === $health->testOpenBasedir()['status'], 'Blocked open_basedir returns critical.' );

	if ( 0 !== $failed ) {
		exit( 1 );
	}
	echo "Admin and Site Health tests passed.\n";
}
