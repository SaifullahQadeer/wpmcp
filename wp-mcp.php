<?php
/**
 * Plugin Name:       WP MCP
 * Description:       Turns this WordPress site into its own remote MCP server so Claude, Gemini or any MCP client can manage pages, posts, custom post types, media, taxonomies, and Gutenberg, Divi and Elementor layouts.
 * Version:           2.11.3
 * Author:            Saifullah Qadeer
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-mcp
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Update URI:        https://github.com/SaifullahQadeer/wpmcp
 *
 * Elementor tested up to: 4.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPMCP_VERSION', '2.11.3' );
define( 'WPMCP_NAMESPACE', 'wpmcp/v1' );
define( 'WPMCP_PLUGIN_FILE', __FILE__ );
define( 'WPMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Host tool-result size cap. Claude.ai / Desktop truncate around 150,000
// characters; we stop short of that and tell the model how to ask for less.
if ( ! defined( 'WPMCP_MAX_RESULT_CHARS' ) ) {
	define( 'WPMCP_MAX_RESULT_CHARS', 140000 );
}

require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-permissions.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-auth.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-oauth.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-history.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-builders.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-site.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-file-safety.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-extensions.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-elementor.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-core.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-rest.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-mcp.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-admin.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-wpmcp-updater.php';

/**
 * On activation: generate an API key if one does not exist yet.
 */
function wpmcp_activate() {
	if ( ! get_option( 'wpmcp_api_key' ) ) {
		update_option( 'wpmcp_api_key', WPMCP_Auth::generate_key() );
		// Fresh installs: keys in URLs end up in access logs, so opt in explicitly.
		if ( false === get_option( 'wpmcp_allow_url_key', false ) ) {
			update_option( 'wpmcp_allow_url_key', '0' );
		}
	}
	// Default: allow all registered public post types unless the admin narrows it.
	if ( false === get_option( 'wpmcp_enabled', false ) ) {
		update_option( 'wpmcp_enabled', '1' );
	}
}
register_activation_hook( __FILE__, 'wpmcp_activate' );

/**
 * Bootstrap the plugin.
 */
function wpmcp_init() {
	( new WPMCP_REST() )->register_hooks();
	( new WPMCP_MCP() )->register_hooks();
	WPMCP_History::maybe_install();
	WPMCP_OAuth::register_hooks();
	( new WPMCP_Updater() )->register_hooks(); // Not admin-only: scheduled update checks run in cron.
	if ( is_admin() ) {
		( new WPMCP_Admin() )->register_hooks();
	}
}
add_action( 'plugins_loaded', 'wpmcp_init' );
