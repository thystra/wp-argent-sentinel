<?php

namespace ArgentSentinel\WordPress\Admin;

use ArgentSentinel\WordPress\Diagnostics\Diagnostics;
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

	public function __construct( Settings $settings, Diagnostics $diagnostics, CommandBuilder $command_builder ) {
		$this->settings        = $settings;
		$this->diagnostics     = $diagnostics;
		$this->command_builder = $command_builder;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
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
			$redirect = add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'options-general.php' ) );
		} catch ( \Throwable $throwable ) {
			$redirect = add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'argent-sentinel-error' => rawurlencode( $throwable->getMessage() ) ),
				admin_url( 'options-general.php' )
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = $this->diagnostics->snapshot();
		if ( ! empty( $status['drop_directory_exists'] ) && ! empty( $status['drop_directory_writable'] ) ) {
			return;
		}
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );
		echo '<div class="notice notice-error"><p><strong>Argent Sentinel cannot export security events.</strong> ';
		echo 'The configured target directory is missing or is not writable by the PHP process. ';
		echo '<a href="' . esc_url( $url ) . '">Open setup and diagnostics</a>.</p></div>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status  = $this->diagnostics->snapshot();
		$command = $this->command_builder->command();
		?>
		<div class="wrap">
			<h1>Argent Sentinel</h1>
			<p>The WordPress connector writes immutable event batches to a local spool. A privileged host agent handles collection, correlation, CrowdSec, and future delivery to <code>sentinel.argentwolf.org</code>.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success inline"><p>Argent Sentinel settings saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['argent-sentinel-error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( rawurldecode( (string) $_GET['argent-sentinel-error'] ) ); ?></p></div>
			<?php endif; ?>

			<h2>Site setup</h2>
			<form method="post">
				<?php wp_nonce_field( 'argent_sentinel_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="argent-site-id">Site ID</label></th><td><input class="regular-text" id="argent-site-id" name="site_id" value="<?php echo esc_attr( $this->settings->siteId() ); ?>"><p class="description">Stable identifier for this WordPress installation.</p></td></tr>
					<tr><th scope="row"><label for="argent-source-host">Node ID</label></th><td><input class="regular-text" id="argent-source-host" name="source_host" value="<?php echo esc_attr( $this->settings->sourceHost() ); ?>"><p class="description">Keep this as the stable host identity, such as <code>nidhoggur</code>; central service location is managed by the host agent.</p></td></tr>
					<tr><th scope="row"><label for="argent-drop">Local drop directory</label></th><td><input class="large-text code" id="argent-drop" name="drop_directory" value="<?php echo esc_attr( $this->settings->dropDirectory() ); ?>"><p class="description">Must be outside the web root and writable by the PHP-FPM user.</p></td></tr>
				</table>
				<p class="submit"><button class="button button-primary" name="argent_sentinel_save" value="1">Save setup</button></p>
			</form>

			<h2>Host provisioning command</h2>
			<p>Run this as root on the WordPress host. It creates permissions, configures these option-backed values through WP-CLI, and tests one export. It does not edit <code>wp-config.php</code>.</p>
			<textarea class="large-text code" rows="7" readonly><?php echo esc_textarea( $command ); ?></textarea>

			<h2>Diagnostics</h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( array(
				'site_id' => 'Site ID',
				'source_host' => 'Node ID',
				'drop_directory' => 'Drop directory',
				'drop_directory_exists' => 'Directory exists',
				'drop_directory_writable' => 'Directory writable',
				'drop_directory_outside_web_root' => 'Outside web root',
				'hmac_secret_configured' => 'HMAC secret configured',
				'request_id_available' => 'Nginx request ID available now',
				'next_export_at_utc' => 'Next export UTC',
				'next_prune_at_utc' => 'Next prune UTC',
			) as $key => $label ) : ?>
				<tr><th><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( is_bool( $status[ $key ] ?? null ) ? ( $status[ $key ] ? 'yes' : 'no' ) : (string) ( $status[ $key ] ?? 'unknown' ) ); ?></code></td></tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2>Nginx request correlation</h2>
			<p>Add the following to the applicable FastCGI location so WordPress receives the same server-generated request ID written by the <code>abuse_context</code> log:</p>
			<pre><code>fastcgi_param ARGENT_SENTINEL_REQUEST_ID $request_id;</code></pre>
		</div>
		<?php
	}
}
