<?php
/** WP MCP settings. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WPMCP_Admin {
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_update_check' ) );
		add_action( 'admin_notices', array( $this, 'update_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMCP_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}
	/** "Check for updates" link on the Plugins screen: look up the latest GitHub release now. */
	public function handle_update_check() {
		if ( ! isset( $_GET['wpmcp_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
		check_admin_referer( 'wpmcp_check_update' );
		$found = ( new WPMCP_Updater() )->check_now();
		wp_safe_redirect( add_query_arg( array( 'wpmcp_update_result' => $found['result'], 'wpmcp_update_version' => rawurlencode( $found['version'] ) ), admin_url( 'plugins.php' ) ) );
		exit;
	}
	public function update_notice() {
		if ( ! isset( $_GET['wpmcp_update_result'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
		$version = isset( $_GET['wpmcp_update_version'] ) ? preg_replace( '/[^0-9A-Za-z.-]/', '', wp_unslash( $_GET['wpmcp_update_version'] ) ) : '';
		$result  = sanitize_key( wp_unslash( $_GET['wpmcp_update_result'] ) );
		if ( 'available' === $result ) { $class = 'notice-warning'; $text = 'WP MCP ' . $version . ' is available. Use the Update now link on the WP MCP row below.'; }
		elseif ( 'current' === $result ) { $class = 'notice-success'; $text = 'WP MCP is up to date (version ' . $version . ').'; }
		else { $class = 'notice-error'; $text = 'Could not reach GitHub to check for updates. Try again in a few minutes.'; }
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
	public function add_menu() { add_menu_page( 'WP MCP', 'WP MCP', 'manage_options', 'wp-mcp', array( $this, 'render_page' ), 'dashicons-rest-api', 80 ); }
	/** Put a Settings link before Deactivate on the Plugins screen. */
	public function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) { return $links; }
		$check = current_user_can( 'update_plugins' ) ? '<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?wpmcp_check_update=1' ), 'wpmcp_check_update' ) ) . '">Check for updates</a>' : '';
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp' ) ) . '">Settings</a>' );
		if ( $check ) { array_splice( $links, 1, 0, $check ); }
		return $links;
	}
	public function assets( $hook ) {
		if ( 'toplevel_page_wp-mcp' !== $hook ) { return; }
		wp_enqueue_style( 'wpmcp-admin', WPMCP_PLUGIN_URL . 'assets/admin.css', array(), WPMCP_VERSION );
		wp_enqueue_script( 'wpmcp-admin', WPMCP_PLUGIN_URL . 'assets/admin.js', array(), WPMCP_VERSION, true );
	}
	public function handle_actions() {
		if ( ! isset( $_POST['wpmcp_action'] ) || ! current_user_can( 'manage_options' ) ) { return; }
		check_admin_referer( 'wpmcp_settings' );
		$action = sanitize_key( wp_unslash( $_POST['wpmcp_action'] ) );
		if ( 'regenerate' === $action ) {
			update_option( 'wpmcp_api_key', WPMCP_Auth::generate_key(), false );
			$message = 'Key rotated. Update the key in each connected client.';
		} elseif ( 'save' === $action ) {
			foreach ( array( 'enabled', 'oauth_enabled', 'allow_url_key', 'extensions_enabled', 'allow_install', 'allow_edit' ) as $setting ) {
				$value = isset( $_POST[ 'wpmcp_' . $setting ] ) ? '1' : '0';
				if ( is_multisite() && in_array( $setting, array( 'extensions_enabled', 'allow_install', 'allow_edit' ), true ) ) { $value = '0'; }
				update_option( 'wpmcp_' . $setting, $value );
			}
			update_option( 'wpmcp_extension_owner', get_current_user_id() );
			$message = 'Connection and permissions saved.';
		} elseif ( 'revoke' === $action ) {
			$grant   = isset( $_POST['wpmcp_grant'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_grant'] ) ) : '';
			$message = WPMCP_OAuth::revoke_grant( $grant ) ? 'App disconnected. Its access stopped immediately.' : 'That connection was already removed.';
		} else { return; }
		set_transient( 'wpmcp_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp' ) );
		exit;
	}
	private function field( $id, $label, $value, $secret = false ) {
		?>
		<label class="wpmcp-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<div class="wpmcp-copy"><input id="<?php echo esc_attr( $id ); ?>" type="<?php echo $secret ? 'password' : 'text'; ?>" readonly autocomplete="off" value="<?php echo esc_attr( $value ); ?>" />
		<?php if ( $secret ) : ?><button type="button" class="button" data-reveal="<?php echo esc_attr( $id ); ?>" aria-pressed="false">Show</button><?php endif; ?>
		<button type="button" class="button" data-copy="<?php echo esc_attr( $id ); ?>">Copy</button></div>
		<?php
	}
	private function toggle( $key, $title, $description, $default = '0' ) {
		?><label class="wpmcp-toggle"><input type="checkbox" name="wpmcp_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', get_option( 'wpmcp_' . $key, $default ) ); ?> /><span><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $description ); ?></small></span></label><?php
	}
	/** Tool picker: choose an AI app, then follow its one-click steps. */
	private function render_connect( $url ) {
		$name   = 'wordpress';
		$cursor = 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( $name ) . '&config=' . rawurlencode( base64_encode( wp_json_encode( array( 'url' => $url ) ) ) );
		$vscode = 'vscode:mcp/install?' . rawurlencode( wp_json_encode( array( 'name' => $name, 'type' => 'http', 'url' => $url ) ) );
		$label_name   = rawurlencode( get_bloginfo( 'name' ) . ' (WP MCP)' );
		$claude_query = '?modal=add-custom-connector&connectorName=' . $label_name . '&connectorUrl=' . rawurlencode( $url );
		$claude       = 'https://claude.ai/customize/connectors' . $claude_query; // Documented install link: Anthropic "Directory connectors vs custom connectors".
		$claude_admin = 'https://claude.ai/admin-settings/connectors' . $claude_query;
		$tools  = array(
			'claude'  => 'Claude',
			'chatgpt' => 'ChatGPT',
			'code'    => 'Claude Code',
			'cursor'  => 'Cursor',
			'vscode'  => 'VS Code',
			'other'   => 'Other app',
		);
		$protocols = array( 'https', 'cursor', 'vscode' );
		?>
		<section class="wpmcp-panel"><h2>Connect your AI app</h2><p>Pick your app. You approve the connection on this site, so there is no key to copy.</p>
		<div class="wpmcp-tools" role="group" aria-label="AI app">
			<?php foreach ( $tools as $id => $label ) : ?><button type="button" class="wpmcp-tool" data-tool="<?php echo esc_attr( $id ); ?>" aria-pressed="<?php echo 'claude' === $id ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
		</div>

		<div class="wpmcp-steps" data-steps="claude">
			<p><a class="button button-primary button-hero" href="<?php echo esc_url( $claude ); ?>" target="_blank" rel="noopener">Connect with Claude</a></p>
			<p>Claude opens with this site already filled in. Confirm it, click <strong>Connect</strong>, then <strong>Approve</strong> on the page this site shows. Works for claude.ai and Claude Desktop.</p>
			<p class="description">On a Claude Team or Enterprise plan? An organization owner adds it <a href="<?php echo esc_url( $claude_admin ); ?>" target="_blank" rel="noopener">here</a>, then members click Connect.</p>
		</div>
		<div class="wpmcp-steps" data-steps="chatgpt" hidden>
			<p><button type="button" class="button button-primary" data-copy-open="wpmcp-endpoint" data-open="https://chatgpt.com/">Copy URL and open ChatGPT</button></p>
			<ol><li>Open <strong>Settings → Connectors</strong>. Custom connectors may need <strong>Developer mode</strong> under Advanced. Menu names change between versions.</li><li>Create a connector, paste the URL and choose OAuth.</li><li>Click <strong>Approve</strong> on the page this site opens.</li></ol>
		</div>
		<div class="wpmcp-steps" data-steps="code" hidden>
			<?php $this->field( 'wpmcp-cmd', 'Run this in your terminal', 'claude mcp add --transport http ' . $name . ' ' . $url ); ?>
			<ol><li>In Claude Code run <code>/mcp</code>, choose <strong><?php echo esc_html( $name ); ?></strong> and select <strong>Authenticate</strong>.</li><li>Click <strong>Approve</strong> on the page this site opens.</li></ol>
		</div>
		<div class="wpmcp-steps" data-steps="cursor" hidden>
			<p><a class="button button-primary" href="<?php echo esc_url( $cursor, $protocols ); ?>">Add to Cursor</a></p>
			<ol><li>Cursor asks to install the server. Confirm.</li><li>Choose <strong>Connect</strong> on the server, then <strong>Approve</strong> on the page this site opens.</li></ol>
			<p class="description">If nothing opens, add the Server URL above as a remote server in Cursor’s MCP settings.</p>
		</div>
		<div class="wpmcp-steps" data-steps="vscode" hidden>
			<p><a class="button button-primary" href="<?php echo esc_url( $vscode, $protocols ); ?>">Add to VS Code</a></p>
			<ol><li>VS Code asks to install the server. Confirm.</li><li>Start the server (<strong>MCP: List Servers</strong>), sign in, then <strong>Approve</strong> on the page this site opens.</li></ol>
			<p class="description">If nothing opens, add the Server URL above as an HTTP server in <code>mcp.json</code>.</p>
		</div>
		<div class="wpmcp-steps" data-steps="other" hidden>
			<p><button type="button" class="button button-primary" data-copy-open="wpmcp-endpoint">Copy URL</button></p>
			<ol><li>Add the URL as a remote MCP server or custom connector, with OAuth sign-in if the app asks.</li><li>Click <strong>Approve</strong> on the page this site opens.</li><li>If the app cannot sign in, use an API key under Advanced below.</li></ol>
		</div>
		<details class="wpmcp-manual"><summary>Manual setup: show the server URL</summary><?php $this->field( 'wpmcp-endpoint', 'Server URL', $url ); ?></details>
		<?php if ( ! WPMCP_OAuth::enabled() ) : ?><div class="notice notice-warning inline"><p>OAuth sign-in is off<?php echo 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ? ' because this site is not on HTTPS' : ''; ?>. Turn it on under Access &amp; permissions, or connect with an API key under Advanced.</p></div><?php endif; ?>
		</section>
		<?php
	}

	/** Apps that are connected through OAuth, with a Revoke button each. */
	private function render_connected() {
		$grants = WPMCP_OAuth::list_grants();
		?>
		<section class="wpmcp-panel"><h2>Connected apps</h2>
		<?php if ( ! $grants ) : ?><p>No apps connected yet. After you approve a connection it appears here.</p><?php else : ?>
		<table class="widefat striped wpmcp-table"><thead><tr><th>App</th><th>Acts as</th><th>Connected</th><th>Last used</th><th></th></tr></thead><tbody>
		<?php foreach ( $grants as $id => $g ) : $user = get_userdata( (int) $g['user_id'] ); ?>
			<tr><td><?php echo esc_html( $g['client_name'] ); ?></td><td><?php echo esc_html( $user ? $user->display_name : 'Unknown user' ); ?></td><td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $g['created'] ) ); ?></td><td><?php echo esc_html( human_time_diff( (int) $g['last_used'] ) . ' ago' ); ?></td>
			<td><form method="post"><?php wp_nonce_field( 'wpmcp_settings' ); ?><input type="hidden" name="wpmcp_action" value="revoke" /><input type="hidden" name="wpmcp_grant" value="<?php echo esc_attr( $id ); ?>" /><button class="button" data-confirm="Disconnect this app? It stops working immediately.">Revoke</button></form></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php endif; ?>
		</section>
		<?php
	}
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$key = (string) get_option( 'wpmcp_api_key', '' );
		$url = rest_url( WPMCP_NAMESPACE . '/mcp' );
		$env = WPMCP_Elementor::environment();
		$enabled = '1' === (string) get_option( 'wpmcp_enabled', '1' );
		$notice = get_transient( 'wpmcp_notice_' . get_current_user_id() );
		delete_transient( 'wpmcp_notice_' . get_current_user_id() );
		?>
		<div class="wrap wpmcp">
		<header class="wpmcp-header"><div><h1>WP MCP <span>v<?php echo esc_html( WPMCP_VERSION ); ?></span></h1><p>Connect your AI assistant. Stay in control of your WordPress site.</p></div><span class="wpmcp-status"><?php echo $enabled ? 'Server enabled' : 'Server paused'; ?></span></header>
		<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
		<?php if ( ! is_ssl() ) : ?><div class="notice notice-warning"><p>Configure HTTPS before connecting. Extension tools require a secure request.</p></div><?php endif; ?>
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?><div class="notice notice-warning"><p>Enable pretty permalinks before using a key in the URL.</p></div><?php endif; ?>
		<div class="wpmcp-layout"><main>
		<?php $this->render_connect( $url ); $this->render_connected(); ?>
		<section class="wpmcp-panel"><h2>Advanced: connect with an API key</h2><p>Only for apps that cannot sign in with OAuth. Anyone with this key can use the enabled tools.</p>
		<details><summary>Show API key options</summary>
		<div class="wpmcp-instruction"><strong>Request header</strong><p>Set the header name to <code>x-api-key</code> and paste the key below as its value. Choose “No sign-in” if your client asks for an OAuth method. Or send <code>Authorization: Bearer YOUR_API_KEY</code>.</p></div>
		<?php $this->field( 'wpmcp-key', 'API key', $key, true ); ?>
		<form method="post" class="wpmcp-rotate"><?php wp_nonce_field( 'wpmcp_settings' ); ?><input type="hidden" name="wpmcp_action" value="regenerate" /><button class="button" data-confirm="Rotate the key? Connected clients will need the new key.">Rotate API key</button></form>
		<p><strong>A client that cannot send headers</strong> can use this URL (content tools only; enable URL keys below).</p><?php $this->field( 'wpmcp-url-key', 'URL containing your key', rest_url( WPMCP_NAMESPACE . '/mcp/' . $key ), true ); ?></details></section>
		<form method="post"><input type="hidden" name="wpmcp_action" value="save" /><?php wp_nonce_field( 'wpmcp_settings' ); ?>
		<section class="wpmcp-panel"><h2>Access & permissions</h2><p>Changes apply to every connected app and API key.</p>
		<?php $this->toggle( 'enabled', 'Enable MCP server', 'Allow authenticated clients to use this site’s tools.', '1' ); $this->toggle( 'oauth_enabled', 'Allow sign-in with OAuth', 'Lets AI apps connect with a Connect button and your approval, with no key to copy. Requires HTTPS.', '1' ); $this->toggle( 'allow_url_key', 'Allow API keys in URLs', 'Compatibility for clients without headers. Headers keep keys out of URL logs.', '1' ); ?>
		<h3>Plugins & themes</h3><p>Extension access uses the WordPress permissions of the administrator who saves these settings. HTTPS and header authentication are required. Multisite is not supported.</p>
		<?php $this->toggle( 'extensions_enabled', 'Allow extension access', 'List installed plugins and themes and read their editable files.' ); $this->toggle( 'allow_install', 'Allow installation', 'Install from WordPress.org. Installed extensions remain inactive.' ); $this->toggle( 'allow_edit', 'Allow code editing', 'Edit existing source files. Changes can break the site; use a backup or staging site.' ); ?>
		<div class="wpmcp-save"><button class="button button-primary button-hero">Save settings</button><span>Installation and editing also require extension access.</span></div></section></form>
		</main><aside>
		<section class="wpmcp-panel"><h2>Site environment</h2><dl><dt>WordPress</dt><dd><?php echo esc_html( get_bloginfo( 'version' ) ); ?></dd><dt>PHP</dt><dd><?php echo esc_html( PHP_VERSION ); ?></dd><dt>Elementor</dt><dd><?php echo $env['active'] ? esc_html( $env['version'] . ' · ' . $env['generation'] ) : 'Not detected'; ?></dd><dt>Authentication</dt><dd>API key / Bearer token</dd><dt>Available tools</dt><dd><?php echo esc_html( count( WPMCP_MCP::tools_spec() ) ); ?> tools; extension access is opt-in</dd></dl></section>
		<section class="wpmcp-panel"><h2>What you can manage</h2><ul><li>Posts, pages & custom content</li><li>Elementor layouts & settings</li><li>Media, categories & tags</li><li>Plugin & theme installation</li><li>Plugin & theme source files</li></ul><p>WordPress file-editing restrictions are respected. Read a file before editing; its hash protects against stale changes.</p></section>
		<section class="wpmcp-panel"><h2>Developer details</h2><p>REST base</p><code class="wpmcp-break"><?php echo esc_html( rest_url( WPMCP_NAMESPACE ) ); ?></code><p>MCP protocol</p><code><?php echo esc_html( WPMCP_MCP::PREFERRED_PROTOCOL ); ?></code></section>
		</aside></div>
		<section class="wpmcp-panel"><h2>File safety & recovery</h2>
		<p>Every source-file edit is checked before saving and keeps a pre-edit snapshot in the database. WP MCP retains the latest ten snapshots per file. PHP checks catch syntax errors, missing tags and unexpected text outside PHP; JSON is validated too. These checks do not guarantee correct runtime behavior.</p>
		<p>Ask your assistant to validate with <code>dry_run: true</code>, apply one change, then check <code>wp_ping</code> and the affected page. To undo an edit, use <code>wp_list_file_backups</code> and <code>wp_restore_extension_file</code> with the current file hash.</p>
		<details><summary>If the site or connector stops responding</summary><ol>
		<li>Stop issuing edits. Keep the exact error and the path of the last changed file.</li>
		<li>If WordPress still works, use the snapshot restore tool. Snapshots cover edits made with WP MCP 2.3.0 onward, not earlier changes.</li>
		<li>If WordPress or its API cannot start, restore the affected file from a known-good hosting backup using your host’s file manager or SFTP. WordPress Recovery Mode may also provide admin access.</li>
		<li>Verify the homepage, admin and MCP connection before resuming. Keep an independent hosting backup and test code changes on staging.</li>
		</ol><p>This plugin cannot repair a server outage, database outage, or code failure that prevents it from loading. Database snapshots are not a full-site backup. Editing plugin/theme files may be overwritten by later updates.</p></details>
		</section>
		<footer class="wpmcp-footer">WP MCP · By Saifullah Qadeer</footer><p id="wpmcp-feedback" class="screen-reader-text" role="status" aria-live="polite"></p></div>
		<?php
	}
}
