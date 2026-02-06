<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();

wp_memory_usage_UNINSTALL();

function wp_memory_usage_UNINSTALL() {
    wp_memory_usage_UNINSTALL_options();
}

function wp_memory_usage_UNINSTALL_options() {
	delete_option( "wpmemoryusage_emopt" );
	delete_option( "wpmemoryusage_settings" );
}

?>