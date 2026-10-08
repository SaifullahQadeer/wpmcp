<?php
/** WP MCP admin screen. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WPMCP_Admin {
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_update_check' ) );
		add_action( 'wp_ajax_wpmcp_status', array( $this, 'ajax_status' ) );
		add_action( 'admin_notices', array( $this, 'update_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMCP_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/* ----------------------------------------------------------------- */
	/* Updates, menu, assets                                             */
	/* ----------------------------------------------------------------- */

	/** "Check for updates" link: look up the latest GitHub release now, then show the result where the link was clicked. */
	public function handle_update_check() {
		if ( ! isset( $_GET['wpmcp_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
		check_admin_referer( 'wpmcp_check_update' );
		$found  = ( new WPMCP_Updater() )->check_now();
		$target = isset( $_GET['wpmcp_return'] ) ? $this->tab_url( 'system' ) : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( array( 'wpmcp_update_result' => $found['result'], 'wpmcp_update_version' => rawurlencode( $found['version'] ) ), $target ) );
		exit;
	}
	public function update_notice() {
		if ( ! isset( $_GET['wpmcp_update_result'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
		$version = isset( $_GET['wpmcp_update_version'] ) ? preg_replace( '/[^0-9A-Za-z.-]/', '', wp_unslash( $_GET['wpmcp_update_version'] ) ) : '';
		$result  = sanitize_key( wp_unslash( $_GET['wpmcp_update_result'] ) );
		if ( 'available' === $result ) { $class = 'notice-warning'; $text = 'WP MCP ' . $version . ' is available. Use the Update now link on the WP MCP row of the Plugins screen.'; }
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

	/* ----------------------------------------------------------------- */
	/* Form actions                                                      */
	/* ----------------------------------------------------------------- */

	private function tabs() {
		return array( 'connect' => 'Connect', 'tools' => 'Tools', 'security' => 'Security', 'system' => 'System' );
	}
	private function tab_url( $tab ) {
		return add_query_arg( array( 'page' => 'wp-mcp', 'tab' => $tab ), admin_url( 'admin.php' ) );
	}
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect';
		return isset( $this->tabs()[ $tab ] ) ? $tab : 'connect';
	}
	/** Hidden fields every form carries: the nonce and the tab to return to. */
	private function form_fields( $action, $tab ) {
		wp_nonce_field( 'wpmcp_settings' );
		echo '<input type="hidden" name="wpmcp_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="wpmcp_tab" value="' . esc_attr( $tab ) . '" />';
	}

	public function handle_actions() {
		if ( ! isset( $_POST['wpmcp_action'] ) || ! current_user_can( 'manage_options' ) ) { return; }
		check_admin_referer( 'wpmcp_settings' );
		$action = sanitize_key( wp_unslash( $_POST['wpmcp_action'] ) );
		$tab    = isset( $_POST['wpmcp_tab'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_tab'] ) ) : 'connect';
		if ( ! isset( $this->tabs()[ $tab ] ) ) { $tab = 'connect'; }
		if ( 'regenerate' === $action ) {
			update_option( 'wpmcp_api_key', WPMCP_Auth::generate_key(), false );
			$message = 'Key rotated. Update the key in each client that uses it.';
		} elseif ( 'save' === $action ) {
			foreach ( array( 'enabled', 'oauth_enabled', 'allow_url_key', 'extensions_enabled', 'allow_install', 'allow_edit' ) as $setting ) {
				$value = isset( $_POST[ 'wpmcp_' . $setting ] ) ? '1' : '0';
				if ( is_multisite() && in_array( $setting, array( 'extensions_enabled', 'allow_install', 'allow_edit' ), true ) ) { $value = '0'; }
				update_option( 'wpmcp_' . $setting, $value );
			}
			update_option( 'wpmcp_extension_owner', get_current_user_id() );
			$message = 'Settings saved.';
		} elseif ( 'revoke' === $action ) {
			$grant   = isset( $_POST['wpmcp_grant'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_grant'] ) ) : '';
			$message = WPMCP_OAuth::revoke_grant( $grant ) ? 'App disconnected. Its access stopped immediately.' : 'That connection was already removed.';
		} else { return; }
		set_transient( 'wpmcp_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( $this->tab_url( $tab ) );
		exit;
	}

	/* ----------------------------------------------------------------- */
	/* Small building blocks                                             */
	/* ----------------------------------------------------------------- */

	private function field( $id, $label, $value, $secret = false ) {
		?>
		<label class="wpmcp-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<div class="wpmcp-copy"><input id="<?php echo esc_attr( $id ); ?>" type="<?php echo $secret ? 'password' : 'text'; ?>" readonly autocomplete="off" value="<?php echo esc_attr( $value ); ?>" />
		<?php if ( $secret ) : ?><button type="button" class="button" data-reveal="<?php echo esc_attr( $id ); ?>" aria-pressed="false">Show</button><?php endif; ?>
		<button type="button" class="button" data-copy="<?php echo esc_attr( $id ); ?>">Copy</button></div>
		<?php
	}
	private function toggle( $key, $title, $description, $default = '0' ) {
		?><label class="wpmcp-toggle"><input type="checkbox" name="wpmcp_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', get_option( 'wpmcp_' . $key, $default ) ); ?> /><span class="wpmcp-switch" aria-hidden="true"></span><span class="wpmcp-toggle-text"><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $description ); ?></small></span></label><?php
	}
	private function badge( $text, $tone = 'neutral' ) {
		return '<span class="wpmcp-badge wpmcp-badge-' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}
	private function row( $label, $value_html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers pass escaped HTML.
	}

	/** Connection count and newest connection time, so the page that started a connect can notice it finished. */
	private function connection_snapshot() {
		$grants  = WPMCP_OAuth::list_grants();
		$created = $grants ? max( array_map( 'intval', array_column( $grants, 'created' ) ) ) : 0;
		return array( 'count' => count( $grants ), 'latest' => $created );
	}
	public function ajax_status() {
		check_ajax_referer( 'wpmcp_status' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( null, 403 ); }
		wp_send_json_success( $this->connection_snapshot() );
	}

	/* ----------------------------------------------------------------- */
	/* Connect tab                                                       */
	/* ----------------------------------------------------------------- */

	/** At-a-glance status tiles. */
	private function render_stats( $enabled ) {
		$grants  = WPMCP_OAuth::list_grants();
		$last    = $grants ? max( array_map( 'intval', array_column( $grants, 'last_used' ) ) ) : 0;
		$active  = 0;
		$tools   = WPMCP_MCP::tools_spec();
		foreach ( $tools as $tool ) { list( $on ) = $this->tool_status( $tool['name'] ); if ( $on ) { $active++; } }
		$tiles = array(
			array( 'Server', $enabled ? 'Running' : 'Paused', $enabled ? 'ok' : 'warn' ),
			array( 'Connected apps', (string) count( $grants ), '' ),
			array( 'Last activity', $last ? human_time_diff( $last ) . ' ago' : 'None yet', '' ),
			array( 'Tools available', $active . ' of ' . count( $tools ), '' ),
		);
		echo '<div class="wpmcp-stats">';
		foreach ( $tiles as $tile ) { echo '<div class="wpmcp-stat"><span>' . esc_html( $tile[0] ) . '</span><strong class="' . esc_attr( $tile[2] ) . '">' . esc_html( $tile[1] ) . '</strong></div>'; }
		echo '</div>';
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
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Connect an AI app</h2><p>Pick your app and approve the connection on this site. There is no key to copy.</p></div>
		<div class="wpmcp-tools" role="group" aria-label="AI app">
			<?php foreach ( $tools as $id => $label ) : ?><button type="button" class="wpmcp-tool" data-tool="<?php echo esc_attr( $id ); ?>" aria-pressed="<?php echo 'claude' === $id ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
		</div>

		<div class="wpmcp-steps" data-steps="claude">
			<?php $snap = $this->connection_snapshot(); ?>
			<p><a class="button button-primary button-hero" id="wpmcp-connect-claude" href="<?php echo esc_url( $claude ); ?>" target="_blank" rel="noopener" data-status-url="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wpmcp_status&_wpnonce=' . wp_create_nonce( 'wpmcp_status' ) ) ); ?>" data-count="<?php echo (int) $snap['count']; ?>" data-latest="<?php echo (int) $snap['latest']; ?>">Connect with Claude</a></p>
			<p id="wpmcp-connect-status" class="wpmcp-connect-status" role="status" hidden></p>
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
			<p class="description">If nothing opens, add the server URL as a remote server in Cursor’s MCP settings.</p>
		</div>
		<div class="wpmcp-steps" data-steps="vscode" hidden>
			<p><a class="button button-primary" href="<?php echo esc_url( $vscode, $protocols ); ?>">Add to VS Code</a></p>
			<ol><li>VS Code asks to install the server. Confirm.</li><li>Start the server (<strong>MCP: List Servers</strong>), sign in, then <strong>Approve</strong> on the page this site opens.</li></ol>
			<p class="description">If nothing opens, add the server URL as an HTTP server in <code>mcp.json</code>.</p>
		</div>
		<div class="wpmcp-steps" data-steps="other" hidden>
			<p><button type="button" class="button button-primary" data-copy-open="wpmcp-endpoint">Copy URL</button></p>
			<ol><li>Add the URL as a remote MCP server or custom connector, with OAuth sign-in if the app asks.</li><li>Click <strong>Approve</strong> on the page this site opens.</li><li>If the app cannot sign in, use an API key from the Security tab.</li></ol>
		</div>
		<details class="wpmcp-manual"><summary>Server URL</summary><?php $this->field( 'wpmcp-endpoint', 'Paste this where an app asks for a server or connector URL', $url ); ?></details>
		<?php if ( ! WPMCP_OAuth::enabled() ) : ?><div class="notice notice-warning inline"><p>OAuth sign-in is off<?php echo 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ? ' because this site is not on HTTPS' : ''; ?>. Turn it on in the Security tab, or connect with an API key.</p></div><?php endif; ?>
		</section>
		<?php
	}

	/** Apps that are connected through OAuth, with a Revoke button each. */
	private function render_connected() {
		$grants = WPMCP_OAuth::list_grants();
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Connected apps</h2><p>Apps that signed in through OAuth. Revoking cuts access immediately.</p></div>
		<?php if ( ! $grants ) : ?><p class="wpmcp-empty">No apps connected yet. After you approve a connection it appears here.</p><?php else : ?>
		<table class="wpmcp-table"><thead><tr><th>App</th><th>Acts as</th><th>Connected</th><th>Last used</th><th></th></tr></thead><tbody>
		<?php foreach ( $grants as $id => $g ) : $user = get_userdata( (int) $g['user_id'] ); ?>
			<tr><td><strong><?php echo esc_html( $g['client_name'] ); ?></strong></td><td><?php echo esc_html( $user ? $user->display_name : 'Unknown user' ); ?></td><td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $g['created'] ) ); ?></td><td><?php echo esc_html( human_time_diff( (int) $g['last_used'] ) . ' ago' ); ?></td>
			<td class="wpmcp-right"><form method="post"><?php $this->form_fields( 'revoke', 'connect' ); ?><input type="hidden" name="wpmcp_grant" value="<?php echo esc_attr( $id ); ?>" /><button class="button" data-confirm="Disconnect this app? It stops working immediately.">Revoke</button></form></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php endif; ?>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tools tab                                                         */
	/* ----------------------------------------------------------------- */

	/** @return array{0:bool,1:string} whether the tool can run now, and what to turn on if not. */
	private function tool_status( $name ) {
		if ( ! in_array( $name, WPMCP_Extensions::tool_names(), true ) ) { return array( true, '' ); }
		if ( is_multisite() ) { return array( false, 'Not supported on multisite' ); }
		if ( '1' !== (string) get_option( 'wpmcp_extensions_enabled', '0' ) ) { return array( false, 'Turn on extension access' ); }
		if ( 'wp_install_extension' === $name && '1' !== (string) get_option( 'wpmcp_allow_install', '0' ) ) { return array( false, 'Turn on installation' ); }
		if ( in_array( $name, array( 'wp_edit_extension_file', 'wp_restore_extension_file' ), true ) && '1' !== (string) get_option( 'wpmcp_allow_edit', '0' ) ) { return array( false, 'Turn on code editing' ); }
		return array( true, '' );
	}

	private function render_tools() {
		$groups = array(
			'Site & content'   => array( 'wp_ping', 'wp_list_post_types', 'wp_list_content', 'wp_get_content', 'wp_create_content', 'wp_update_content', 'wp_delete_content' ),
			'Elementor'        => array( 'wp_get_elementor', 'wp_set_elementor' ),
			'Media & taxonomy' => array( 'wp_upload_media', 'wp_list_media', 'wp_list_terms', 'wp_create_term' ),
			'Plugins & themes' => WPMCP_Extensions::tool_names(),
		);
		$specs = array();
		foreach ( WPMCP_MCP::tools_spec() as $spec ) { $specs[ $spec['name'] ] = $spec; }
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Tools your AI app can use</h2><p>Every tool this server offers. Plugin and theme tools stay off until you enable them in the Security tab.</p></div>
		<?php foreach ( $groups as $group => $names ) : ?>
			<h3 class="wpmcp-group"><?php echo esc_html( $group ); ?></h3>
			<table class="wpmcp-table"><thead><tr><th>Tool</th><th>What it does</th><th>Access</th><th>Status</th></tr></thead><tbody>
			<?php foreach ( $names as $name ) :
				if ( ! isset( $specs[ $name ] ) ) { continue; }
				$spec    = $specs[ $name ];
				$parts   = preg_split( '/(?<=[.!?])\s/', (string) $spec['description'], 2 );
				$sentence = $parts[0];
				if ( false !== strpos( $name, 'delete' ) ) { $access = $this->badge( 'Deletes', 'danger' ); }
				elseif ( ! empty( $spec['annotations']['readOnlyHint'] ) ) { $access = $this->badge( 'Read only', 'neutral' ); }
				else { $access = $this->badge( 'Changes data', 'warn' ); }
				list( $on, $why ) = $this->tool_status( $name );
				?>
				<tr><td><strong><?php echo esc_html( isset( $spec['title'] ) ? $spec['title'] : $name ); ?></strong><code><?php echo esc_html( $name ); ?></code></td><td><?php echo esc_html( $sentence ); ?></td><td><?php echo $access; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes. ?></td><td><?php echo $on ? $this->badge( 'Available', 'ok' ) : $this->badge( 'Off', 'off' ) . '<small class="wpmcp-why">' . esc_html( $why ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endforeach; ?>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Security tab                                                      */
	/* ----------------------------------------------------------------- */

	private function render_security( $key ) {
		?>
		<form method="post"><?php $this->form_fields( 'save', 'security' ); ?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Access</h2><p>Changes apply to every connected app and API key.</p></div>
		<?php $this->toggle( 'enabled', 'Enable MCP server', 'Allow authenticated apps to use this site’s tools. Turn off to pause everything.', '1' ); $this->toggle( 'oauth_enabled', 'Allow sign-in with OAuth', 'Lets AI apps connect with a Connect button and your approval, with no key to copy. Requires HTTPS.', '1' ); $this->toggle( 'allow_url_key', 'Allow API keys in URLs', 'For apps that cannot send headers. Headers keep keys out of server logs, so leave this off if you can.', '1' ); ?>
		</section>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Plugins & themes</h2><p>Extension tools use the WordPress permissions of the administrator who saves these settings. They need HTTPS and a signed-in app or an API key header. Multisite is not supported.</p></div>
		<?php $this->toggle( 'extensions_enabled', 'Allow extension access', 'List installed plugins and themes and read their editable files.' ); $this->toggle( 'allow_install', 'Allow installation', 'Install from WordPress.org. Installed extensions stay inactive.' ); $this->toggle( 'allow_edit', 'Allow code editing', 'Edit existing source files. Changes can break the site, so use a backup or staging site.' ); ?>
		<div class="wpmcp-save"><button class="button button-primary button-hero">Save settings</button><span>Installation and editing also need extension access.</span></div></section>
		</form>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>API key</h2><p>For apps that cannot sign in with OAuth. Anyone with this key can use the enabled tools, so treat it like a password.</p></div>
		<div class="wpmcp-instruction"><strong>How to use it</strong><p>Send the key in the <code>x-api-key</code> header, or as <code>Authorization: Bearer YOUR_API_KEY</code>. If the app asks for an OAuth method, choose “No sign-in”.</p></div>
		<?php $this->field( 'wpmcp-key', 'API key', $key, true ); ?>
		<form method="post" class="wpmcp-rotate"><?php $this->form_fields( 'regenerate', 'security' ); ?><button class="button" data-confirm="Rotate the key? Apps using the old key stop working.">Rotate API key</button></form>
		<details><summary>Key in URL (only if an app cannot send headers)</summary><p>Content tools only, and it needs “Allow API keys in URLs” above.</p><?php $this->field( 'wpmcp-url-key', 'URL containing your key', rest_url( WPMCP_NAMESPACE . '/mcp/' . $key ), true ); ?></details>
		</section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Built-in protection</h2></div>
		<ul class="wpmcp-list"><li>Ten wrong API keys from one address in 15 minutes block that address for 15 minutes.</li><li>OAuth sign-in needs an administrator’s approval, uses PKCE, and tokens act as that administrator. Revoking an app cuts access at once.</li><li>Source-file edits are checked before saving and keep up to ten snapshots per file.</li><li>Browser requests from other sites are rejected.</li></ul>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* System tab                                                        */
	/* ----------------------------------------------------------------- */

	private function render_system( $url ) {
		$env      = WPMCP_Elementor::environment();
		$https    = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$new      = ( new WPMCP_Updater() )->cached_update();
		$check    = wp_nonce_url( admin_url( 'admin.php?page=wp-mcp&wpmcp_check_update=1&wpmcp_return=1' ), 'wpmcp_check_update' );
		$meta     = WPMCP_OAuth::server_metadata();
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Plugin</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'Version', esc_html( WPMCP_VERSION ) . ( $new ? ' ' . $this->badge( 'Update available: ' . $new, 'warn' ) : ' ' . $this->badge( 'Up to date', 'ok' ) ) . ( current_user_can( 'update_plugins' ) ? ' <a class="button button-small" href="' . esc_url( $check ) . '">Check for updates</a>' : '' ) );
		$this->row( 'Updates from', '<a href="https://github.com/SaifullahQadeer/wpmcp/releases" target="_blank" rel="noopener">GitHub releases</a>' );
		$this->row( 'Author', 'Saifullah Qadeer' );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>This site</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'WordPress', esc_html( get_bloginfo( 'version' ) ) . ( is_multisite() ? ' ' . $this->badge( 'Multisite', 'warn' ) : '' ) );
		$this->row( 'PHP', esc_html( PHP_VERSION ) );
		$this->row( 'Elementor', $env['active'] ? esc_html( $env['version'] . ' · ' . $env['generation'] ) : 'Not detected' );
		$this->row( 'HTTPS', $https ? $this->badge( 'On', 'ok' ) : $this->badge( 'Off: OAuth and extension tools need HTTPS', 'warn' ) );
		$this->row( 'Pretty permalinks', get_option( 'permalink_structure' ) ? $this->badge( 'On', 'ok' ) : $this->badge( 'Off: the REST API address needs them', 'warn' ) );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>Endpoints</h2><p>For developers and troubleshooting.</p></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'MCP server', '<code>' . esc_html( $url ) . '</code>' );
		$this->row( 'REST base', '<code>' . esc_html( rest_url( WPMCP_NAMESPACE ) ) . '</code>' );
		$this->row( 'OAuth discovery', '<code>' . esc_html( home_url( '/.well-known/oauth-authorization-server' ) ) . '</code>' );
		$this->row( 'Authorization', '<code>' . esc_html( $meta['authorization_endpoint'] ) . '</code>' );
		$this->row( 'Token', '<code>' . esc_html( $meta['token_endpoint'] ) . '</code>' );
		$this->row( 'MCP protocol', esc_html( implode( ', ', WPMCP_MCP::SUPPORTED_PROTOCOLS ) ) );
		$this->row( 'Result size limit', esc_html( number_format( (int) apply_filters( 'wpmcp_max_result_chars', WPMCP_MAX_RESULT_CHARS ) ) ) . ' characters' );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2>File safety & recovery</h2></div>
		<p>Every source-file edit is checked before saving and keeps a pre-edit snapshot in the database. WP MCP retains the latest ten snapshots per file. PHP checks catch syntax errors, missing tags and unexpected text outside PHP; JSON is validated too. These checks do not guarantee correct runtime behavior.</p>
		<p>Ask your assistant to validate with <code>dry_run: true</code>, apply one change, then check <code>wp_ping</code> and the affected page. To undo an edit, use <code>wp_list_file_backups</code> and <code>wp_restore_extension_file</code> with the current file hash.</p>
		<details><summary>If the site or connector stops responding</summary><ol>
		<li>Stop issuing edits. Keep the exact error and the path of the last changed file.</li>
		<li>If WordPress still works, use the snapshot restore tool. Snapshots cover edits made with WP MCP 2.3.0 onward, not earlier changes.</li>
		<li>If WordPress or its API cannot start, restore the affected file from a known-good hosting backup using your host’s file manager or SFTP. WordPress Recovery Mode may also provide admin access.</li>
		<li>Verify the homepage, admin and MCP connection before resuming. Keep an independent hosting backup and test code changes on staging.</li>
		</ol><p>This plugin cannot repair a server outage, database outage, or code failure that prevents it from loading. Database snapshots are not a full-site backup. Editing plugin or theme files may be overwritten by later updates.</p></details>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Page shell                                                        */
	/* ----------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$key     = (string) get_option( 'wpmcp_api_key', '' );
		$url     = rest_url( WPMCP_NAMESPACE . '/mcp' );
		$enabled = '1' === (string) get_option( 'wpmcp_enabled', '1' );
		$tab     = $this->current_tab();
		$new     = ( new WPMCP_Updater() )->cached_update();
		$notice  = get_transient( 'wpmcp_notice_' . get_current_user_id() );
		delete_transient( 'wpmcp_notice_' . get_current_user_id() );
		?>
		<div class="wrap wpmcp">
		<header class="wpmcp-header"><div><h1>WP MCP <span>v<?php echo esc_html( WPMCP_VERSION ); ?></span></h1><p>Let AI apps manage your WordPress site, with you in control.</p></div>
		<div class="wpmcp-header-side"><?php if ( $new ) : ?><a class="wpmcp-pill wpmcp-pill-warn" href="<?php echo esc_url( $this->tab_url( 'system' ) ); ?>">Update available: <?php echo esc_html( $new ); ?></a><?php endif; ?><span class="wpmcp-pill <?php echo $enabled ? 'wpmcp-pill-ok' : 'wpmcp-pill-warn'; ?>"><?php echo $enabled ? 'Server running' : 'Server paused'; ?></span></div></header>
		<nav class="wpmcp-tabs" aria-label="WP MCP sections"><?php foreach ( $this->tabs() as $id => $label ) : ?><a href="<?php echo esc_url( $this->tab_url( $id ) ); ?>"<?php echo $id === $tab ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
		<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['wpmcp_connected'] ) ) : ?><div class="notice notice-success is-dismissible"><p><strong>Claude is connected.</strong> Ask Claude to run <code>wp_ping</code> to try it.</p></div><?php endif; ?>
		<?php if ( ! is_ssl() ) : ?><div class="notice notice-warning"><p>Configure HTTPS before connecting. OAuth sign-in and extension tools need a secure request.</p></div><?php endif; ?>
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?><div class="notice notice-warning"><p>Enable pretty permalinks: the REST API address does not work with Plain permalinks.</p></div><?php endif; ?>
		<?php
		if ( 'tools' === $tab ) { $this->render_tools(); }
		elseif ( 'security' === $tab ) { $this->render_security( $key ); }
		elseif ( 'system' === $tab ) { $this->render_system( $url ); }
		else { $this->render_stats( $enabled ); $this->render_connect( $url ); $this->render_connected(); }
		?>
		<footer class="wpmcp-footer">WP MCP · By Saifullah Qadeer</footer><p id="wpmcp-feedback" class="screen-reader-text" role="status" aria-live="polite"></p></div>
		<?php
	}
}
