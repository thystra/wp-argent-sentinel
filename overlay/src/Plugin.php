<?php

namespace ArgentSentinel\WordPress;

use ArgentSentinel\WordPress\Abuse\AbuseEventSubscriber;
use ArgentSentinel\WordPress\Abuse\WordPressAccountLookup;
use ArgentSentinel\WordPress\Admin\AdminPage;
use ArgentSentinel\WordPress\Admin\SiteHealth;
use ArgentSentinel\WordPress\CLI\Command;
use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Events\EventRecorder;
use ArgentSentinel\WordPress\Events\QueueRepository;
use ArgentSentinel\WordPress\Export\BatchExporter;
use ArgentSentinel\WordPress\Export\CronExporter;
use ArgentSentinel\WordPress\Export\ExportLock;
use ArgentSentinel\WordPress\Export\ExportSettings;
use ArgentSentinel\WordPress\Http\RequestContextFactory;
use ArgentSentinel\WordPress\Network\ClientIpResolver;
use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
use ArgentSentinel\WordPress\Privacy\EmailIdentity;
use ArgentSentinel\WordPress\Settings\Settings;

final class Plugin {

	public const VERSION = '0.2.1';

	/** @var self|null */
	private static $instance;
	/** @var AbuseEventSubscriber */
	private $abuse_events;
	/** @var CronExporter */
	private $cron_exporter;
	/** @var AdminPage */
	private $admin_page;
	/** @var SiteHealth */
	private $site_health;

	private function __construct(
		AbuseEventSubscriber $abuse_events,
		CronExporter $cron_exporter,
		AdminPage $admin_page,
		SiteHealth $site_health
	) {
		$this->abuse_events = $abuse_events;
		$this->cron_exporter = $cron_exporter;
		$this->admin_page = $admin_page;
		$this->site_health = $site_health;
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
			$abuse_events = new AbuseEventSubscriber(
				$recorder,
				new WordPressAccountLookup(),
				$email_identity
			);

			$exporter = new BatchExporter(
				$queue,
				new ExportSettings( $settings ),
				new ExportLock(),
				$settings->siteId(),
				$settings->siteUrl(),
				$settings->sourceHost()
			);
			$cron = new CronExporter( $exporter );
			$diagnostics = new Diagnostics( $queue, $settings );
			$command_builder = new CommandBuilder( $settings );
			$admin_page = new AdminPage( $settings, $diagnostics, $command_builder );
			$site_health = new SiteHealth( $diagnostics );

			self::$instance = new self( $abuse_events, $cron, $admin_page, $site_health );
			self::$instance->abuse_events->register();
			self::$instance->cron_exporter->register();
			self::$instance->admin_page->register();
			self::$instance->site_health->register();

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				( new Command( $exporter, $diagnostics, $settings, $command_builder ) )->register();
			}
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
