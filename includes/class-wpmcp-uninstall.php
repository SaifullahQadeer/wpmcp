<?php
/**
 * What happens to WP MCP's data when the plugin is deleted: kept, or deleted with it.
 *
 * WordPress runs no code of a plugin that is not active, and a plugin must be deactivated before it can be deleted, so
 * the question cannot be asked at the moment of deletion. It is asked when the plugin is deactivated (a dialog on the
 * Plugins screen) and can be changed any time with the switch under Security. uninstall.php reads the answer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Uninstall {
	const NONCE = 'wpmcp_uninstall_choice';

	public static function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wpmcp_uninstall_choice', array( __CLASS__, 'ajax_choice' ) );
	}

	/**
	 * The plugins that keep data, by plugin file: the option that holds the answer, a name, and what the data is. The Pro
	 * add-on adds its own through "wpmcp_uninstall_choices". Option names are checked here, so the request can never name one.
	 */
	public static function choices() {
		$choices = array( plugin_basename( WPMCP_PLUGIN_FILE ) => array( 'option' => 'wpmcp_delete_data', 'name' => 'WP MCP', 'data' => 'your API key, connected apps and change history' ) );
		$out     = array();
		foreach ( (array) apply_filters( 'wpmcp_uninstall_choices', $choices ) as $file => $choice ) {
			if ( ! is_string( $file ) || ! is_array( $choice ) || empty( $choice['option'] ) || ! is_string( $choice['option'] ) || ! preg_match( '/^wpmcp_[a-z_]*delete_data$/', $choice['option'] ) ) { continue; }
			$out[ $file ] = array(
				'option' => $choice['option'],
				'name'   => isset( $choice['name'] ) ? (string) $choice['name'] : $file,
				'data'   => isset( $choice['data'] ) ? (string) $choice['data'] : 'its data',
			);
		}
		return $out;
	}

	/** The dialog on the Plugins screen. */
	public static function assets( $hook ) {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'manage_options' ) ) { return; }
		$plugins = array();
		foreach ( self::choices() as $file => $choice ) {
			$plugins[ $file ] = array( 'name' => $choice['name'], 'data' => $choice['data'], 'delete' => '1' === (string) get_option( $choice['option'], '0' ) );
		}
		wp_enqueue_style( 'wpmcp-plugins-screen', WPMCP_PLUGIN_URL . 'assets/plugins-screen.css', array(), WPMCP_VERSION );
		wp_enqueue_script( 'wpmcp-plugins-screen', WPMCP_PLUGIN_URL . 'assets/plugins-screen.js', array(), WPMCP_VERSION, true );
		wp_add_inline_script( 'wpmcp-plugins-screen', 'window.wpmcpUninstall = ' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ), 'plugins' => $plugins ) ) . ';', 'before' );
	}

	/** Save the answer. Needs the nonce and an administrator; only a plugin in the list above can be named. */
	public static function ajax_choice() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 ); }
		$file    = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$choices = self::choices();
		if ( ! isset( $choices[ $file ] ) ) { wp_send_json_error( array( 'message' => 'Unknown plugin.' ), 400 ); }
		$delete = isset( $_POST['choice'] ) && 'delete' === sanitize_key( wp_unslash( $_POST['choice'] ) );
		update_option( $choices[ $file ]['option'], $delete ? '1' : '0', false );
		wp_send_json_success( array( 'delete' => $delete ) );
	}
}
