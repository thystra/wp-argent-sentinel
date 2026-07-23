<?php
/**
 * Plugin Name: Argent Sentinel WordPress Connector
 * Description: Queues privacy-conscious WordPress security events for Argent Sentinel.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Argent Sentinel
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: argent-sentinel-wordpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';

\ArgentSentinel\WordPress\Autoloader::register( __DIR__ . '/src' );

register_activation_hook(
	__FILE__,
	array( \ArgentSentinel\WordPress\Activation::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( \ArgentSentinel\WordPress\Deactivation::class, 'deactivate' )
);

add_action(
	'plugins_loaded',
	array( \ArgentSentinel\WordPress\Plugin::class, 'boot' )
);
