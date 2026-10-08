<?php
/** Shows updates from GitHub releases in the WordPress Plugins screen. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Updater {
	const REPO  = 'SaifullahQadeer/wpmcp';
	const CACHE = 'wpmcp_github_release';

	private $basename;
	private $slug;

	public function __construct() {
		$this->basename = plugin_basename( WPMCP_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );
	}

	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'authorize_download' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
	}

	/**
	 * Latest published (non-draft, non-prerelease) GitHub release, cached.
	 *
	 * Set WPMCP_GITHUB_TOKEN in wp-config.php to read a private repository.
	 *
	 * @return array|null
	 */
	private function release() {
		// "Check Again" on Dashboard -> Updates bypasses the cache.
		if ( is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { delete_site_transient( self::CACHE ); }
		$cached = get_site_transient( self::CACHE );
		if ( false !== $cached ) { return $cached ? $cached : null; }

		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-MCP-Updater' );
		if ( defined( 'WPMCP_GITHUB_TOKEN' ) && WPMCP_GITHUB_TOKEN ) { $headers['Authorization'] = 'Bearer ' . WPMCP_GITHUB_TOKEN; }
		$response = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => $headers ) );
		$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		$tag = is_array( $data ) && ! empty( $data['tag_name'] ) && is_string( $data['tag_name'] ) ? $data['tag_name'] : '';
		if ( '' === $tag || ! preg_match( '/^[vV]?(\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?)$/', $tag, $m ) ) {
			set_site_transient( self::CACHE, array(), 15 * MINUTE_IN_SECONDS ); // Back off briefly, not on every page load.
			return null;
		}

		// Prefer an attached .zip asset; fall back to GitHub's source zip.
		$package = isset( $data['zipball_url'] ) ? $data['zipball_url'] : '';
		if ( ! defined( 'WPMCP_GITHUB_TOKEN' ) && ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) { $package = $asset['browser_download_url']; break; }
			}
		}

		$release = array(
			'version'   => $m[1],
			'package'   => esc_url_raw( $package ),
			'url'       => isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : 'https://github.com/' . self::REPO,
			'notes'     => isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
		set_site_transient( self::CACHE, $release, HOUR_IN_SECONDS );
		return $release;
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
			'id'          => 'github.com/' . self::REPO,
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
				'changelog'   => '' !== $release['notes'] ? wp_kses_post( nl2br( esc_html( $release['notes'] ) ) ) : 'See the GitHub release page.',
			),
		);
	}

	/** GitHub's source zip unpacks to owner-repo-hash/; WordPress needs the plugin folder name. */
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

	/** Send the token only to GitHub's API host, and only when one is configured. */
	public function authorize_download( $args, $url ) {
		if ( defined( 'WPMCP_GITHUB_TOKEN' ) && WPMCP_GITHUB_TOKEN && 'api.github.com' === wp_parse_url( $url, PHP_URL_HOST ) && 0 === strpos( $url, 'https://api.github.com/repos/' . self::REPO . '/' ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . WPMCP_GITHUB_TOKEN;
		}
		return $args;
	}

	public function clear_cache() {
		delete_site_transient( self::CACHE );
	}
}
