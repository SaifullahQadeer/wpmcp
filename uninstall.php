<?php
/**
 * Runs when WP MCP is deleted from the Plugins screen. The data is kept unless the owner chose to delete it: the switch
 * under WP MCP > Security, or the question asked when the plugin is deactivated. Nothing here touches other plugins.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

if ( ! function_exists( 'wpmcp_uninstall_site' ) ) {
	/** Remove WP MCP's data from the current site, if its owner asked for that. */
	function wpmcp_uninstall_site() {
		global $wpdb;
		if ( '1' !== (string) get_option( 'wpmcp_delete_data', '0' ) ) { return; }

		foreach ( array( 'wpmcp_api_key', 'wpmcp_enabled', 'wpmcp_oauth_enabled', 'wpmcp_allow_url_key', 'wpmcp_key_level', 'wpmcp_db_version', 'wpmcp_oauth_clients', 'wpmcp_oauth_grants', 'wpmcp_delete_data' ) as $option ) {
			delete_option( $option );
		}

		// Refresh tokens, and every temporary value this plugin stored (access tokens, sign-in requests, throttles, update answers, notices).
		foreach ( array( 'wpmcp_rt_', '_transient_wpmcp_', '_transient_timeout_wpmcp_', '_site_transient_wpmcp_', '_site_transient_timeout_wpmcp_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}

		delete_metadata( 'user', 0, 'wpmcp_seen_version', '', true );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmcp_history" );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpmcp_site_id ) {
		switch_to_blog( $wpmcp_site_id );
		wpmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	wpmcp_uninstall_site();
}
