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
}

namespace ArgentSentinel\WordPress\Settings {
	final class Settings {
		public const OPTION_NAME = 'argent_sentinel_settings';
		public const HMAC_SECRET_OPTION = 'argent_sentinel_hmac_secret';
		public function siteId(): string { return (string) ( \get_option( self::OPTION_NAME, array() )['site_id'] ?? 'wordpress-site' ); }
		public function sourceHost(): string { return (string) ( \get_option( self::OPTION_NAME, array() )['source_host'] ?? 'wordpress-host' ); }
		public function dropDirectory(): string { return (string) ( \get_option( self::OPTION_NAME, array() )['drop_directory'] ?? '' ); }
		public function hmacSecret(): string { return (string) \get_option( self::HMAC_SECRET_OPTION, '' ); }
	}
}

namespace ArgentSentinel\WordPress\Network {
	final class ClientIpResolver {
		public function resolve( array $server ): ?string { return isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : null; }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Onboarding/Configuration.php';
	require dirname( __DIR__ ) . '/src/Onboarding/CommandBuilder.php';
	require dirname( __DIR__ ) . '/src/Http/RequestContext.php';
	require dirname( __DIR__ ) . '/src/Http/RequestContextFactory.php';

	use ArgentSentinel\WordPress\Http\RequestContextFactory;
	use ArgentSentinel\WordPress\Network\ClientIpResolver;
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

	$factory = new RequestContextFactory( new ClientIpResolver() );
	$context = $factory->create(
		array(
			'REMOTE_ADDR' => '198.51.100.20',
			'REQUEST_METHOD' => 'POST',
			'REQUEST_URI' => '/wp-login.php?ignored=1',
			'ARGENT_SENTINEL_REQUEST_ID' => '0123456789abcdef',
		)
	);
	$assert( '0123456789abcdef' === $context->requestId(), 'Trusted FastCGI request ID is captured.' );
	$assert( '/wp-login.php' === $context->path(), 'Query string is omitted from path.' );
	$bad = $factory->create( array( 'REMOTE_ADDR' => '198.51.100.20', 'ARGENT_SENTINEL_REQUEST_ID' => 'bad id' ) );
	$assert( null === $bad->requestId(), 'Malformed request ID is rejected.' );

	$command = ( new CommandBuilder( new Settings() ) )->command( 'example-fpm', '/srv/example/public' );
	$assert( false !== strpos( $command, '--site-id' ), 'Onboarding command contains site ID.' );
	$assert( false !== strpos( $command, 'example-fpm' ), 'Onboarding command contains PHP user.' );
	$assert( false === strpos( $command, 'argent_sentinel_hmac_secret' ), 'Onboarding command does not expose HMAC secret.' );

	if ( 0 !== $failed ) {
		exit( 1 );
	}
	echo "Onboarding tests passed.\n";
}
