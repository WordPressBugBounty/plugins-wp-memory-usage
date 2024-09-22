<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();

UNINSTALL_wpmemoryusage();

function UNINSTALL_wpmemoryusage() {
    UNINSTALL_wpmemoryusage_options();
}

function UNINSTALL_wpmemoryusage_options() {
	delete_option( "wpmemoryusage_emopt" );
	delete_option( "wpmemoryusage_settings" );
}

?>