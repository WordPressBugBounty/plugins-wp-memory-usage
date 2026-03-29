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
$wp_memory_usage_options_to_delete = array(
	'wpmu_threshold_alerts',    
);

foreach ( $wp_memory_usage_options_to_delete as $wp_memory_usage_option ) {
	delete_option( $wp_memory_usage_option );
}

// Multisite: remove site-level options if applicable
if ( is_multisite() ) {
	foreach ( $wp_memory_usage_options_to_delete as $wp_memory_usage_option ) {
		delete_site_option( $wp_memory_usage_option );
	}
}

// -- 2. Scheduled Cron Events --------------------------------------------------
$wp_memory_usage_cron_hooks = array(
	'wpmu_daily_digest',   // DIGEST_HOOK
	'wpmu_cleanup_hook',   // log-rotation cron
);

foreach ( $wp_memory_usage_cron_hooks as $wp_memory_usage_hook ) {
	$wp_memory_usage_timestamp = wp_next_scheduled( $wp_memory_usage_hook );
	while ( $wp_memory_usage_timestamp ) {
		wp_unschedule_event( $wp_memory_usage_timestamp, $wp_memory_usage_hook );
		$wp_memory_usage_timestamp = wp_next_scheduled( $wp_memory_usage_hook );
	}
	wp_clear_scheduled_hook( $wp_memory_usage_hook ); // safety net
}

// Remove any dynamically named interval hooks (wpmu_every_XX_min)
$wp_memory_usage_crons = _get_cron_array();
if ( is_array( $wp_memory_usage_crons ) ) {
	foreach ( $wp_memory_usage_crons as $wp_memory_usage_timestamp => $wp_memory_usage_cron_jobs ) {
		foreach ( $wp_memory_usage_cron_jobs as $wp_memory_usage_hook_name => $wp_memory_usage_events ) {
			if ( strpos( $wp_memory_usage_hook_name, 'wpmu_' ) === 0 ) {
				foreach ( $wp_memory_usage_events as $wp_memory_usage_key => $wp_memory_usage_event ) {
					wp_unschedule_event( $wp_memory_usage_timestamp, $wp_memory_usage_hook_name, $wp_memory_usage_event['args'] );
				}
			}
		}
	}
}

// -- 3. Log Files --------------------------------------------------------------
// Note: settings (settings.cgi) and all log files are stored in the
// log directory below and are removed together with it.$wp_memory_usage_log_dir = ABSPATH. '../logs/wpmu/';
if ( $wp_memory_usage_log_dir && is_dir( $wp_memory_usage_log_dir ) ) {
	// Remove directory
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	WP_Filesystem();
	$wp_filesystem->rmdir( $wp_memory_usage_log_dir, true );
}
