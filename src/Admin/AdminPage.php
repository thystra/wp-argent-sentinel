<?php

namespace ArgentSentinel\WordPress\Admin;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
use ArgentSentinel\WordPress\Export\BatchExporter;
use ArgentSentinel\WordPress\Onboarding\CommandBuilder;
use ArgentSentinel\WordPress\Onboarding\Configuration;
use ArgentSentinel\WordPress\Settings\Settings;

final class AdminPage {
	private const PAGE_SLUG = 'argent-sentinel';

	/** @var Settings */
	private $settings;

	/** @var Diagnostics */
	private $diagnostics;

	/** @var CommandBuilder */
	private $command_builder;

	/** @var BatchExporter */
	private $exporter;

	public function __construct(
		Settings $settings,
		Diagnostics $diagnostics,
		CommandBuilder $command_builder,
		BatchExporter $exporter
	) {
		$this->settings        = $settings;
		$this->diagnostics     = $diagnostics;
		$this->command_builder = $command_builder;
		$this->exporter        = $exporter;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_argent_sentinel_export_now', array( $this, 'exportNow' ) );
	}

	public function menu(): void {
		add_options_page(
			'Argent Sentinel',
			'Argent Sentinel',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function save(): void {
		if ( empty( $_POST['argent_sentinel_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'argent_sentinel_save_settings' );

		try {
			( new Configuration() )->update(
				array(
					'site_id'        => isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '',
					'source_host'    => isset( $_POST['source_host'] ) ? sanitize_text_field( wp_unslash( $_POST['source_host'] ) ) : '',
					'drop_directory' => isset( $_POST['drop_directory'] ) ? sanitize_text_field( wp_unslash( $_POST['drop_directory'] ) ) : '',
				)
			);
			$redirect = add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			);
		} catch ( \Throwable $throwable ) {
			$redirect = add_query_arg(
				array(
					'page'                  => self::PAGE_SLUG,
					'argent-sentinel-error' => rawurlencode( $throwable->getMessage() ),
				),
				admin_url( 'options-general.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function exportNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to run an Argent Sentinel export.' );
		}

		check_admin_referer( 'argent_sentinel_export_now' );
		$result = $this->exporter->exportOnce()->toArray();
		$args   = array(
			'page'          => self::PAGE_SLUG,
			'export-status' => (string) $result['status'],
			'export-count'  => (string) $result['event_count'],
		);
		if ( ! empty( $result['error'] ) ) {
			$args['export-error'] = rawurlencode( substr( (string) $result['error'], 0, 500 ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->diagnostics->snapshot();
		if ( 'ready' === $status['overall_status'] ) {
			return;
		}

		$url = self::statusPageUrl();
		echo '<div class="notice notice-error"><p><strong>Argent Sentinel needs attention.</strong> ';
		echo esc_html( (string) $status['issues'] ) . ' ';
		echo '<a href="' . esc_url( $url ) . '">Open setup and status</a>.</p></div>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = $this->diagnostics->snapshot();
		$ready   = 'ready' === $status['overall_status'];
		$command = $this->command_builder->command();
		?>
		<div class="wrap argent-sentinel-wrap">
			<h1>Argent Sentinel</h1>
			<p>
				The WordPress connector records security events and writes immutable
				JSON batches to a protected local spool. The privileged host agent
				performs collection, correlation, CrowdSec enforcement, and abuse reporting.
			</p>

			<style>
				.argent-sentinel-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:18px 0}
				.argent-sentinel-card{background:#fff;border:1px solid #c3c4c7;border-left-width:4px;padding:14px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
				.argent-sentinel-card.good{border-left-color:#00a32a}
				.argent-sentinel-card.bad{border-left-color:#d63638}
				.argent-sentinel-card h2{font-size:14px;margin:0 0 7px}
				.argent-sentinel-value{font-size:20px;font-weight:600}
				.argent-sentinel-wrap textarea{font-family:monospace}
				.argent-sentinel-status-good{color:#008a20;font-weight:600}
				.argent-sentinel-status-bad{color:#b32d2e;font-weight:600}
			</style>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success inline"><p>Argent Sentinel settings saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['argent-sentinel-error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( rawurldecode( (string) $_GET['argent-sentinel-error'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['export-status'] ) ) : ?>
				<div class="notice <?php echo in_array( (string) $_GET['export-status'], array( 'success', 'empty' ), true ) ? 'notice-success' : 'notice-error'; ?> inline">
					<p>
						Export result:
						<strong><?php echo esc_html( (string) $_GET['export-status'] ); ?></strong>;
						events: <?php echo esc_html( (string) ( $_GET['export-count'] ?? '0' ) ); ?>.
						<?php if ( isset( $_GET['export-error'] ) ) : ?>
							<?php echo esc_html( rawurldecode( (string) $_GET['export-error'] ) ); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="argent-sentinel-grid">
				<div class="argent-sentinel-card <?php echo $ready ? 'good' : 'bad'; ?>">
					<h2>Connector status</h2>
					<div class="argent-sentinel-value <?php echo $ready ? 'argent-sentinel-status-good' : 'argent-sentinel-status-bad'; ?>">
						<?php echo $ready ? 'Ready' : 'Needs attention'; ?>
					</div>
					<p><?php echo $ready ? 'The local event-export path is operational.' : esc_html( (string) $status['issues'] ); ?></p>
				</div>
				<div class="argent-sentinel-card">
					<h2>Queued events</h2>
					<div class="argent-sentinel-value"><?php echo esc_html( (string) $status['queued_count'] ); ?></div>
					<p>Oldest queued: <code><?php echo esc_html( (string) ( $status['oldest_queued_at'] ?? 'none' ) ); ?></code></p>
				</div>
				<div class="argent-sentinel-card">
					<h2>Last successful export</h2>
					<div class="argent-sentinel-value"><?php echo esc_html( (string) ( $status['last_exported_at'] ?? 'none' ) ); ?></div>
					<p>Exported rows retained locally: <?php echo esc_html( (string) $status['exported_count'] ); ?></p>
				</div>
				<div class="argent-sentinel-card">
					<h2>Next scheduled export</h2>
					<div class="argent-sentinel-value"><?php echo esc_html( (string) ( $status['next_export_at_utc'] ?? 'not scheduled' ) ); ?></div>
					<p>WP-Cron performs bounded, atomic exports.</p>
				</div>
			</div>

			<?php if ( ! empty( $status['last_export_error'] ) ) : ?>
				<div class="notice notice-error inline"><p><strong>Last export error:</strong> <?php echo esc_html( (string) $status['last_export_error'] ); ?></p></div>
			<?php endif; ?>

			<h2>Quick actions</h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="argent_sentinel_export_now">
				<?php wp_nonce_field( 'argent_sentinel_export_now' ); ?>
				<button type="submit" class="button">Run export now</button>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>">Open Site Health</a>

			<h2>Site setup</h2>
			<p>
				Settings are currently loaded from
				<strong><?php echo esc_html( (string) $status['configuration_source'] ); ?></strong>.
				Constants in <code>wp-config.php</code> override option-backed values.
			</p>
			<form method="post">
				<?php wp_nonce_field( 'argent_sentinel_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="argent-site-id">Site ID</label></th>
						<td>
							<input class="regular-text" id="argent-site-id" name="site_id" value="<?php echo esc_attr( $this->settings->siteId() ); ?>">
							<p class="description">Stable identifier for this WordPress installation.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="argent-source-host">Node ID</label></th>
						<td>
							<input class="regular-text" id="argent-source-host" name="source_host" value="<?php echo esc_attr( $this->settings->sourceHost() ); ?>">
							<p class="description">Stable host identity, such as <code>nidhoggur</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="argent-drop">Local drop directory</label></th>
						<td>
							<input class="large-text code" id="argent-drop" name="drop_directory" value="<?php echo esc_attr( $this->settings->dropDirectory() ); ?>">
							<p class="description">Must be outside the web root and writable by the PHP-FPM process.</p>
						</td>
					</tr>
				</table>
				<p class="submit"><button class="button button-primary" name="argent_sentinel_save" value="1">Save setup</button></p>
			</form>

			<h2>Host provisioning command</h2>
			<?php if ( 'missing' === ( $status['onboarding_helper_state'] ?? '' ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						The host onboarding helper was not found at
						<code><?php echo esc_html( (string) $status['onboarding_helper'] ); ?></code>.
						Install the Argent Sentinel agent/server tooling or define
						<code>ARGENT_SENTINEL_ONBOARDING_HELPER</code> with its absolute path.
					</p>
				</div>
			<?php elseif ( 'unverifiable_open_basedir' === ( $status['onboarding_helper_state'] ?? '' ) ) : ?>
				<div class="notice notice-info inline">
					<p>
						<strong>The helper is outside PHP-FPM's filesystem sandbox and cannot be verified from WordPress.</strong>
						This is expected when <code>/usr/sbin</code> is excluded from
						<code>open_basedir</code>. The helper runs as root from the copied
						command; do not add <code>/usr/sbin</code> to PHP's allowed paths
						solely for this status check.
					</p>
				</div>
			<?php endif; ?>
			<?php if ( empty( $status['open_basedir_enabled'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><strong>Security hardening recommended: PHP open_basedir is disabled for this per-site pool.</strong></p>
					<p>
						The connector can operate, but the PHP worker has broader filesystem
						reach than intended by the per-user pool model. Add a reviewed
						pool-level <code>php_admin_value[open_basedir]</code>, including:
						<code><?php echo esc_html( (string) $status['open_basedir_required_entry'] ); ?></code>
					</p>
					<p><code>open_basedir</code> is defense in depth and does not replace Unix permissions, service isolation, or timely patching.</p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $status['open_basedir_enabled'] ) && empty( $status['open_basedir_allows_drop'] ) ) : ?>
				<div class="notice notice-error inline">
					<p><strong>PHP-FPM open_basedir blocks the configured drop directory.</strong></p>
					<p>
						Required entry:
						<code><?php echo esc_html( (string) $status['open_basedir_required_entry'] ); ?></code>
					</p>
					<p>
						Current effective value:
						<code><?php echo esc_html( (string) $status['open_basedir'] ); ?></code>
					</p>
					<p>
						Suggested value:
						<code><?php echo esc_html( (string) $status['open_basedir_suggested_value'] ); ?></code>
					</p>
					<p>Rerun the host provisioning command, approve the prompted PHP-FPM change, and restart the matching pool.</p>
				</div>
			<?php endif; ?>
			<p>
				Run this as root on the WordPress host. It creates the protected spool,
				checks the matching PHP-FPM pool, offers to append a missing
				<code>open_basedir</code> entry, configures these values through WP-CLI,
				checks status, and performs one export. It does not expose the HMAC
				secret or edit <code>wp-config.php</code>.
			</p>
			<textarea class="large-text code" rows="7" readonly><?php echo esc_textarea( $command ); ?></textarea>

			<h2>Operational diagnostics</h2>
			<table class="widefat striped">
				<tbody>
				<?php
				foreach (
					array(
						'plugin_version'                  => 'Plugin version',
						'expected_schema_version'         => 'Expected schema version',
						'installed_schema_version'        => 'Installed schema version',
						'site_id'                         => 'Site ID',
						'site_url'                        => 'Site URL',
						'source_host'                     => 'Node ID',
						'php_process_user'                => 'PHP process user',
						'open_basedir'                    => 'Effective open_basedir',
						'open_basedir_enabled'            => 'open_basedir enabled',
						'open_basedir_security_status'    => 'open_basedir security status',
						'open_basedir_allows_drop'        => 'open_basedir permits drop',
						'open_basedir_required_entry'     => 'Required open_basedir entry',
						'drop_directory_access_state'     => 'Drop access state',
						'drop_directory'                  => 'Drop directory',
						'drop_directory_exists'           => 'Directory exists',
						'drop_directory_writable'         => 'Directory writable',
						'drop_directory_symlink'          => 'Directory is symlink',
						'drop_directory_outside_web_root' => 'Outside web root',
						'drop_directory_owner'            => 'Directory owner',
						'drop_directory_group'            => 'Directory group',
						'drop_directory_mode'             => 'Directory mode',
						'hmac_secret_configured'          => 'HMAC secret configured',
						'request_id_available'            => 'Nginx request ID available now',
						'next_export_at_utc'               => 'Next export UTC',
						'next_prune_at_utc'                => 'Next prune UTC',
						'onboarding_helper'                => 'Onboarding helper',
						'onboarding_helper_state'          => 'Onboarding helper status',
						'onboarding_helper_verifiable'     => 'Helper visible to PHP-FPM',
						'onboarding_helper_exists'         => 'Onboarding helper executable',
					) as $key => $label
				) :
					$value = $status[ $key ] ?? null;
					if ( 'open_basedir' === $key && ( null === $value || '' === $value ) ) {
						$value = 'disabled';
					} elseif ( is_bool( $value ) ) {
						$value = $value ? 'yes' : 'no';
					} elseif ( null === $value || '' === $value ) {
						$value = 'unknown';
					}
					?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<td><code><?php echo esc_html( (string) $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			</table>

			<h2>Nginx request correlation</h2>
			<p>
				The applicable FastCGI location should pass the same request ID that is
				written to the Argent Sentinel abuse-context log:
			</p>
			<pre><code>fastcgi_param ARGENT_SENTINEL_REQUEST_ID $request_id;</code></pre>
		</div>
		<?php
	}

	public static function statusPageUrl(): string {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );
	}
}
