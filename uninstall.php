<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'argent_sentinel_settings', array() );

if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$table_name = $wpdb->prefix . 'argent_sentinel_events';
$drop_query = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $drop_query );

delete_option( 'argent_sentinel_settings' );
delete_option( 'argent_sentinel_hmac_secret' );
delete_option( 'argent_sentinel_schema_version' );
delete_option( 'argent_sentinel_activated_at_utc' );
delete_option( 'argent_sentinel_schema_retry_after' );
