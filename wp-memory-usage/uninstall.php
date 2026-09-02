<?php
/**
 * WP-Memory-Usage - Uninstall Script
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all plugin options, scheduled events, and log files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// -- 1. WordPress Options ------------------------------------------------------
$wpmu_options_to_delete = array(
	'wpmu_threshold_alerts',
	'wpmu_storage_backend',
);

foreach ( $wpmu_options_to_delete as $wpmu_option ) {
	delete_option( $wpmu_option );
}

// Multisite: remove site-level options if applicable
if ( is_multisite() ) {
	foreach ( $wpmu_options_to_delete as $wpmu_option ) {
		delete_site_option( $wpmu_option );
	}
}

// -- 2. Scheduled Cron Events --------------------------------------------------
$wpmu_cron_hooks = array(
	'wpmu_daily_digest',   // DIGEST_HOOK
	'wpmu_cleanup_hook',   // log-rotation cron
);

foreach ( $wpmu_cron_hooks as $wpmu_hook ) {
	$wpmu_timestamp = wp_next_scheduled( $wpmu_hook );
	while ( $wpmu_timestamp ) {
		wp_unschedule_event( $wpmu_timestamp, $wpmu_hook );
		$wpmu_timestamp = wp_next_scheduled( $wpmu_hook );
	}
	wp_clear_scheduled_hook( $wpmu_hook ); // safety net
}

// Remove any dynamically named interval hooks (wpmu_every_XX_min)
$wpmu_crons = _get_cron_array();
if ( is_array( $wpmu_crons ) ) {
	foreach ( $wpmu_crons as $wpmu_timestamp => $wpmu_cron_jobs ) {
		foreach ( $wpmu_cron_jobs as $wpmu_hook_name => $wpmu_events ) {
			if ( strpos( $wpmu_hook_name, 'wpmu_' ) === 0 ) {
				foreach ( $wpmu_events as $wpmu_key => $wpmu_event ) {
					wp_unschedule_event( $wpmu_timestamp, $wpmu_hook_name, $wpmu_event['args'] );
				}
			}
		}
	}
}

// -- 3. Database Table ---------------------------------------------------------
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpmu_log' );
// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// -- 4. Log Files --------------------------------------------------------------
// settings (settings.cgi) and all log files live in this directory.
$wpmu_log_dir = ABSPATH . '../logs/wpmu/';
if ( $wpmu_log_dir && is_dir( $wpmu_log_dir ) ) {
	// Remove directory
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	WP_Filesystem();
	$wp_filesystem->rmdir( $wpmu_log_dir, true );
}
