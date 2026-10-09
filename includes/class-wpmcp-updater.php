<?php
/**
 * Shows updates in the WordPress Plugins screen. The release information and the package come from the WP MCP
 * update server (updates.wpmcp.co), which reads the releases from the project's repository. Nothing identifying
 * is sent: the request carries no site address, no key and no user data, only what any web request carries.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Updater {
	const SERVER = 'https://updates.wpmcp.co';
	const SLUG   = 'wpmcp';
	const CACHE  = 'wpmcp_update_release';

	private $basename;
	private $slug;

	public function __construct() {
		$this->basename = plugin_basename( WPMCP_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );
	}

	/** The update server's address. WPMCP_UPDATE_URL in wp-config.php can point it elsewhere (a staging server). */
	public static function server() {
		return rtrim( defined( 'WPMCP_UPDATE_URL' ) && is_string( WPMCP_UPDATE_URL ) && '' !== WPMCP_UPDATE_URL ? WPMCP_UPDATE_URL : self::SERVER, '/' );
	}

	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
	}

	/**
	 * Latest release as reported by the update server, cached.
	 *
	 * @return array|null
	 */
	private function release() {
		// "Check Again" on Dashboard -> Updates bypasses the cache.
		if ( is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { delete_site_transient( self::CACHE ); }
		$cached = get_site_transient( self::CACHE );
		if ( false !== $cached ) { return $cached ? $cached : null; }

		$server   = self::server();
		$response = wp_remote_get( $server . '/check/' . self::SLUG, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'WP-MCP-Updater' ) );
		$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		$version = is_array( $data ) && ! empty( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '';
		if ( '' === $version || ! preg_match( '/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version ) ) {
			set_site_transient( self::CACHE, array(), 15 * MINUTE_IN_SECONDS ); // Back off briefly, not on every page load.
			return null;
		}

		// The package must come from the update server itself, never from somewhere the answer names.
		$package = isset( $data['package'] ) && is_string( $data['package'] ) && 0 === strpos( $data['package'], $server . '/download/' ) ? esc_url_raw( $data['package'] ) : '';

		$release = array(
			'version'   => $version,
			'package'   => $package,
			'url'       => isset( $data['homepage'] ) && is_string( $data['homepage'] ) && '' !== $data['homepage'] ? esc_url_raw( $data['homepage'] ) : 'https://wpmcp.co',
			'notes'     => isset( $data['notes'] ) && is_string( $data['notes'] ) ? $data['notes'] : '',
			'published' => isset( $data['published'] ) && is_string( $data['published'] ) ? $data['published'] : '',
		);
		set_site_transient( self::CACHE, $release, HOUR_IN_SECONDS );
		return $release;
	}

	/** Newer version already found by an earlier check, or ''. Never makes a network request. */
	public function cached_update() {
		$cached = get_site_transient( self::CACHE );
		return is_array( $cached ) && ! empty( $cached['version'] ) && version_compare( $cached['version'], WPMCP_VERSION, '>' ) ? $cached['version'] : '';
	}

	/**
	 * Look up the latest release now, ignoring the cache, and refresh WordPress's update list.
	 *
	 * @return array{result:string,version:string} result is available, current or error.
	 */
	public function check_now() {
		delete_site_transient( self::CACHE );
		$release = $this->release();
		if ( ! $release ) { return array( 'result' => 'error', 'version' => '' ); }
		if ( ! version_compare( $release['version'], WPMCP_VERSION, '>' ) ) { return array( 'result' => 'current', 'version' => WPMCP_VERSION ); }
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		return array( 'result' => 'available', 'version' => $release['version'] );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) { return $transient; }
		$release = $this->release();
		if ( ! $release || '' === $release['package'] || ! version_compare( $release['version'], WPMCP_VERSION, '>' ) ) { return $transient; }
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) { $transient->response = array(); }
		$transient->response[ $this->basename ] = (object) array(
			'id'          => 'updates.wpmcp.co/' . $this->slug,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);
		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->slug !== $args->slug ) { return $result; }
		$release = $this->release();
		if ( ! $release ) { return $result; }
		return (object) array(
			'name'          => 'WP MCP',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => 'Saifullah Qadeer',
			'homepage'      => $release['url'],
			'requires'      => '5.6',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Turns this WordPress site into its own remote MCP server.',
				'changelog'   => '' !== $release['notes'] ? wp_kses_post( nl2br( esc_html( $release['notes'] ) ) ) : 'See the release notes on wpmcp.co.',
			),
		);
	}

	/** The server already names the folder after the plugin; this only guards against a package that does not. */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || $this->basename !== $hook_extra['plugin'] || ! $wp_filesystem ) { return $source; }
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . basename( WPMCP_PLUGIN_FILE ) ) ) {
			return new WP_Error( 'wpmcp_bad_package', 'The downloaded package does not contain ' . basename( WPMCP_PLUGIN_FILE ) . '.' );
		}
		$wanted = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === $wanted ) { return $source; }
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $wanted, true ) ) {
			return new WP_Error( 'wpmcp_rename_failed', 'Could not prepare the update package.' );
		}
		return trailingslashit( $wanted );
	}

	public function clear_cache() {
		delete_site_transient( self::CACHE );
	}
}
