<?php

namespace ArgentSentinel\WordPress;

use ArgentSentinel\WordPress\Abuse\AbuseEventSubscriber;
use ArgentSentinel\WordPress\Abuse\WordPressAccountLookup;
use ArgentSentinel\WordPress\Events\EventRecorder;
use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Http\RequestContextFactory;
use ArgentSentinel\WordPress\Network\ClientIpResolver;
use ArgentSentinel\WordPress\Privacy\EmailIdentity;
use ArgentSentinel\WordPress\Settings\Settings;

final class Plugin {
	public const VERSION = '0.1.1';

	/** @var self|null */
	private static $instance;

	/** @var AbuseEventSubscriber */
	private $abuse_events;

	private function __construct( AbuseEventSubscriber $abuse_events ) {
		$this->abuse_events = $abuse_events;
	}

	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		try {
			if ( ! Activation::maybeUpgrade() ) {
				return;
			}

			global $wpdb;

			$settings        = new Settings();
			$ip_resolver     = new ClientIpResolver( $settings->trustedProxyCidrs() );
			$request_context = new RequestContextFactory( $ip_resolver );
			$queue           = new QueueRepository( $wpdb );
			$email_identity  = new EmailIdentity( $settings->hmacSecret() );
			$recorder        = new EventRecorder(
				$queue,
				$request_context,
				$email_identity,
				$settings->siteId(),
				$settings->siteUrl(),
				$settings->sourceHost()
			);
			$abuse_events    = new AbuseEventSubscriber(
				$recorder,
				new WordPressAccountLookup(),
				$email_identity
			);

			self::$instance = new self( $abuse_events );
			self::$instance->abuse_events->register();
		} catch ( \Throwable $throwable ) {
			self::reportBootFailure();
		}
	}

	private static function reportBootFailure(): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		try {
			do_action( 'argent_sentinel_boot_failed' );
		} catch ( \Throwable $throwable ) {
			// The connector must never make WordPress unavailable.
		}
	}
}
