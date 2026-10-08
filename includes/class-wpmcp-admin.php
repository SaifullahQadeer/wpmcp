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
		add_action( 'in_admin_header', array( $this, 'suppress_notices' ), 1000 );
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
	/** Result of the last "Check for updates" click, as a notice, or ''. */
	private function update_notice_markup() {
		if ( ! isset( $_GET['wpmcp_update_result'] ) || ! current_user_can( 'update_plugins' ) ) { return ''; }
		$version = isset( $_GET['wpmcp_update_version'] ) ? preg_replace( '/[^0-9A-Za-z.-]/', '', wp_unslash( $_GET['wpmcp_update_version'] ) ) : '';
		$result  = sanitize_key( wp_unslash( $_GET['wpmcp_update_result'] ) );
		if ( 'available' === $result ) { $class = 'notice-warning'; $text = 'WP MCP ' . $version . ' is available. Use the Update now link on the WP MCP row of the Plugins screen.'; }
		elseif ( 'current' === $result ) { $class = 'notice-success'; $text = 'WP MCP is up to date (version ' . $version . ').'; }
		else { $class = 'notice-error'; $text = 'Could not reach GitHub to check for updates. Try again in a few minutes.'; }
		return '<div class="notice inline wpmcp-notice ' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p></div>';
	}
	public function update_notice() {
		echo str_replace( 'notice inline wpmcp-notice', 'notice is-dismissible', $this->update_notice_markup() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in update_notice_markup().
	}
	/** Other plugins' notices do not belong on this screen. */
	public function suppress_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_wp-mcp' !== $screen->id ) { return; }
		foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) { remove_all_actions( $hook ); }
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
		return array( 'connect' => 'Connect', 'tools' => 'Tools', 'history' => 'History', 'security' => 'Security', 'system' => 'System' );
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
			foreach ( array( 'enabled', 'oauth_enabled', 'allow_url_key', 'extensions_enabled', 'allow_install', 'allow_edit', 'allow_activate' ) as $setting ) {
				$value = isset( $_POST[ 'wpmcp_' . $setting ] ) ? '1' : '0';
				if ( is_multisite() && in_array( $setting, array( 'extensions_enabled', 'allow_install', 'allow_edit', 'allow_activate' ), true ) ) { $value = '0'; }
				update_option( 'wpmcp_' . $setting, $value );
			}
			update_option( 'wpmcp_extension_owner', get_current_user_id() );
			$message = 'Settings saved.';
		} elseif ( 'revoke' === $action ) {
			$grant   = isset( $_POST['wpmcp_grant'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_grant'] ) ) : '';
			$message = WPMCP_OAuth::revoke_grant( $grant ) ? 'App disconnected. Its access stopped immediately.' : 'That connection was already removed.';
		} elseif ( 'rollback' === $action ) {
			$id     = isset( $_POST['wpmcp_entry'] ) ? absint( wp_unslash( $_POST['wpmcp_entry'] ) ) : 0;
			$result = WPMCP_History::rollback( $id, ! empty( $_POST['wpmcp_force'] ), true );
			$extra  = array();
			if ( is_wp_error( $result ) ) {
				$type    = 'error';
				$message = $result->get_error_message();
				if ( 'wpmcp_changed_since' === $result->get_error_code() ) { $extra['wpmcp_force'] = $id; $type = 'warning'; }
			} else {
				$type    = 'success';
				$message = $result['message'];
			}
			set_transient( 'wpmcp_notice_' . get_current_user_id(), array( 'text' => $message, 'type' => $type ), 60 );
			wp_safe_redirect( add_query_arg( $extra, $this->tab_url( $tab ) ) );
			exit;
		} elseif ( 'dismiss_whatsnew' === $action ) {
			update_user_meta( get_current_user_id(), 'wpmcp_seen_version', WPMCP_VERSION );
			wp_safe_redirect( $this->tab_url( $tab ) );
			exit;
		} elseif ( 'clear_history' === $action ) {
			WPMCP_History::clear();
			$message = 'History cleared. Saved file snapshots are kept.';
		} else { return; }
		set_transient( 'wpmcp_notice_' . get_current_user_id(), array( 'text' => $message, 'type' => 'success' ), 60 );
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
		<?php if ( $secret ) : ?><button type="button" class="button wpmcp-icon-btn" data-reveal="<?php echo esc_attr( $id ); ?>" aria-pressed="false" aria-label="Show" title="Show"><span class="wpmcp-eye"><?php echo $this->icon( 'eye', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="wpmcp-eye-off"><?php echo $this->icon( 'eye-off', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></button><?php endif; ?>
		<button type="button" class="button wpmcp-icon-btn" data-copy="<?php echo esc_attr( $id ); ?>" aria-label="Copy" title="Copy"><span class="wpmcp-copy-icon"><?php echo $this->icon( 'copy', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="wpmcp-done-icon"><?php echo $this->icon( 'tick', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></button></div>
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
			array( 'system', 'Server', $enabled ? 'Running' : 'Paused', $enabled ? 'ok' : 'warn' ),
			array( 'users', 'Connected apps', (string) count( $grants ), '' ),
			array( 'activity', 'Last activity', $last ? human_time_diff( $last ) . ' ago' : 'None yet', '' ),
			array( 'layers', 'Tools available', $active . ' of ' . count( $tools ), '' ),
		);
		echo '<div class="wpmcp-stats">';
		foreach ( $tiles as $tile ) { echo '<div class="wpmcp-stat"><span class="wpmcp-stat-icon">' . $this->icon( $tile[0], 18 ) . '</span><span class="wpmcp-stat-label">' . esc_html( $tile[1] ) . '</span><strong class="' . esc_attr( $tile[3] ) . '">' . esc_html( $tile[2] ) . '</strong></div>'; } // phpcs:ignore WordPress.Security.EscapeOutput -- icon() returns static markup.
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
		<section class="wpmcp-panel" id="wpmcp-connect"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'connect' ); ?>Connect an AI app</h2><p>Pick your app and approve the connection on this site. There is no key to copy.</p></div>
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
		<?php if ( ! WPMCP_OAuth::enabled() ) : ?><div class="notice inline wpmcp-notice notice-warning"><p>OAuth sign-in is off<?php echo 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ? ' because this site is not on HTTPS' : ''; ?>. Turn it on in the Security tab, or connect with an API key.</p></div><?php endif; ?>
		</section>
		<?php
	}

	/** Apps that are connected through OAuth, with a Revoke button each. */
	private function render_connected() {
		$grants = WPMCP_OAuth::list_grants();
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'users' ); ?>Connected apps</h2><p>Apps that signed in through OAuth. Revoking cuts access immediately.</p></div>
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
		if ( 'wp_set_extension_active' === $name && '1' !== (string) get_option( 'wpmcp_allow_activate', '0' ) ) { return array( false, 'Turn on activation' ); }
		return array( true, '' );
	}

	private function render_tools() {
		$groups = array(
			'Site & content'           => array( 'wp_ping', 'wp_list_post_types', 'wp_list_content', 'wp_get_content', 'wp_create_content', 'wp_update_content', 'wp_delete_content' ),
			'Block editor (Gutenberg)' => array( 'wp_list_block_types', 'wp_get_blocks', 'wp_set_blocks' ),
			'Elementor'                => array( 'wp_get_elementor', 'wp_set_elementor' ),
			'Divi'                     => array( 'wp_get_divi', 'wp_set_divi' ),
			'Media & taxonomy'         => array( 'wp_upload_media', 'wp_list_media', 'wp_list_terms', 'wp_create_term' ),
			'Settings & cache'         => array( 'wp_get_settings', 'wp_update_settings', 'wp_clear_cache' ),
			'History'                  => array( 'wp_list_history', 'wp_rollback' ),
			'Plugins & themes'         => WPMCP_Extensions::tool_names(),
		);
		$specs = array();
		$on    = 0;
		foreach ( WPMCP_MCP::tools_spec() as $spec ) {
			$specs[ $spec['name'] ] = $spec;
			list( $enabled ) = $this->tool_status( $spec['name'] );
			if ( $enabled ) { $on++; }
		}
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'tools' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Tools</h2><p><?php echo (int) $on; ?> of <?php echo (int) count( $specs ); ?> are on. Hover a tool to see what it does. Plugin and theme tools stay off until you turn them on in Security.</p></div>
		<?php foreach ( $groups as $group => $names ) : ?>
			<h3 class="wpmcp-group"><?php echo esc_html( $group ); ?></h3>
			<ul class="wpmcp-toollist">
			<?php foreach ( $names as $name ) :
				if ( ! isset( $specs[ $name ] ) ) { continue; }
				$spec  = $specs[ $name ];
				$parts = preg_split( '/(?<=[.!?])\s/', (string) $spec['description'], 2 );
				if ( false !== strpos( $name, 'delete' ) ) { $access = $this->badge( 'Delete', 'danger' ); }
				elseif ( ! empty( $spec['annotations']['readOnlyHint'] ) ) { $access = $this->badge( 'Read', 'neutral' ); }
				else { $access = $this->badge( 'Write', 'warn' ); }
				list( $enabled, $why ) = $this->tool_status( $name );
				?>
				<li class="<?php echo $enabled ? '' : 'is-off'; ?>" title="<?php echo esc_attr( $parts[0] ); ?>"><span class="wpmcp-tool-main"><strong><?php echo esc_html( isset( $spec['title'] ) ? $spec['title'] : $name ); ?></strong><code><?php echo esc_html( $name ); ?></code></span><span class="wpmcp-tool-meta"><?php echo $enabled ? '' : '<small class="wpmcp-why">' . esc_html( $why ) . '</small>'; echo $access; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes. ?></span></li>
			<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* History tab                                                       */
	/* ----------------------------------------------------------------- */

	private function render_history() {
		$per_page = 25;
		$total    = WPMCP_History::count();
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = isset( $_GET['paged'] ) ? min( $pages, max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) ) : 1;
		$entries  = WPMCP_History::entries( $page, $per_page );
		$force    = isset( $_GET['wpmcp_force'] ) ? absint( wp_unslash( $_GET['wpmcp_force'] ) ) : 0;
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'history' ); ?>Change history</h2><p>Every change an AI app makes through WP MCP is recorded here, with what it touched. Roll back restores only that part and leaves other edits alone. The latest <?php echo (int) WPMCP_History::KEEP_ROWS; ?> changes are kept for <?php echo (int) WPMCP_History::KEEP_DAYS; ?> days.</p></div>
		<?php if ( ! $entries ) : ?><p class="wpmcp-empty">No changes recorded yet. Changes made by connected apps will appear here.</p><?php else : ?>
		<table class="wpmcp-table"><thead><tr><th>When</th><th>Who</th><th>Change</th><th>Status</th><th></th></tr></thead><tbody>
		<?php foreach ( $entries as $row ) :
			$time  = strtotime( $row['created_at'] . ' UTC' );
			$id    = (int) $row['id'];
			$link  = in_array( $row['object_type'], array( 'post', 'attachment' ), true ) && get_post( (int) $row['object_id'] ) ? get_edit_post_link( (int) $row['object_id'], 'raw' ) : '';
			$label = '' !== (string) $row['label'] ? $row['label'] : '(untitled)';
			?>
			<tr>
				<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i', $time ) ); ?>"><?php echo esc_html( human_time_diff( $time ) . ' ago' ); ?></td>
				<td><?php echo esc_html( $row['actor'] ); ?></td>
				<td><strong><?php echo esc_html( $row['summary'] ); ?></strong><br /><?php echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- both branches escape. ?></td>
				<td><?php
				if ( 'rolled_back' === $row['status'] ) { echo $this->badge( 'Rolled back', 'neutral' ) . '<small class="wpmcp-why">' . esc_html( 'by ' . $row['rolled_back_by'] ) . '</small>'; } // phpcs:ignore WordPress.Security.EscapeOutput
				elseif ( $row['can_rollback'] ) { echo $this->badge( 'Applied', 'ok' ); } // phpcs:ignore WordPress.Security.EscapeOutput
				else { echo $this->badge( 'Cannot roll back', 'off' ); } // phpcs:ignore WordPress.Security.EscapeOutput
				?></td>
				<td class="wpmcp-right"><?php if ( 'applied' === $row['status'] && $row['can_rollback'] ) : ?>
					<form method="post"><?php $this->form_fields( 'rollback', 'history' ); ?><input type="hidden" name="wpmcp_entry" value="<?php echo esc_attr( $id ); ?>" />
					<?php if ( $force === $id ) : ?><input type="hidden" name="wpmcp_force" value="1" /><button class="button button-primary" data-confirm="This item was edited after the change. Rolling back overwrites those edits. Continue?">Roll back anyway</button>
					<?php else : ?><button class="button" data-confirm="Roll back this change?">Roll back</button><?php endif; ?></form>
				<?php endif; ?></td>
			</tr>
		<?php endforeach; ?></tbody></table>
		<?php if ( $pages > 1 ) : ?><div class="wpmcp-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $this->tab_url( 'history' ) ), 'format' => '', 'current' => $page, 'total' => $pages, 'prev_text' => '‹ Newer', 'next_text' => 'Older ›' ) ) ); ?></div><?php endif; ?>
		<form method="post" class="wpmcp-rotate"><?php $this->form_fields( 'clear_history', 'history' ); ?><button class="button" data-confirm="Clear the whole history? You will no longer be able to roll these changes back.">Clear history</button></form>
		<?php endif; ?>
		</section>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Security tab                                                      */
	/* ----------------------------------------------------------------- */

	private function render_security( $key ) {
		?>
		<form method="post"><?php $this->form_fields( 'save', 'security' ); ?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'shield' ); ?>Access</h2><p>Changes apply to every connected app and API key.</p></div>
		<?php $this->toggle( 'enabled', 'Enable MCP server', 'Allow authenticated apps to use this site’s tools. Turn off to pause everything.', '1' ); $this->toggle( 'oauth_enabled', 'Allow sign-in with OAuth', 'Lets AI apps connect with a Connect button and your approval, with no key to copy. Requires HTTPS.', '1' ); $this->toggle( 'allow_url_key', 'Allow API keys in URLs', 'For apps that cannot send headers. Headers keep keys out of server logs, so leave this off if you can.', '1' ); ?>
		</section>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'layers' ); ?>Plugins & themes</h2><p>Extension tools use the WordPress permissions of the administrator who saves these settings. They need HTTPS and a signed-in app or an API key header. Multisite is not supported.</p></div>
		<?php $this->toggle( 'extensions_enabled', 'Allow extension access', 'List installed plugins and themes and read their editable files.' ); $this->toggle( 'allow_install', 'Allow installation', 'Install from WordPress.org. Installed extensions stay inactive.' ); $this->toggle( 'allow_edit', 'Allow code editing', 'Edit existing source files. Changes can break the site, so use a backup or staging site.' ); $this->toggle( 'allow_activate', 'Allow activation', 'Activate or deactivate installed plugins and switch the theme. WP MCP itself cannot be deactivated this way.' ); ?>
		<div class="wpmcp-save"><button class="button button-primary button-hero">Save settings</button><span>Installation and editing also need extension access.</span></div></section>
		</form>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'key' ); ?>API key</h2><p>For apps that cannot sign in with OAuth. Anyone with this key can use the enabled tools, so treat it like a password.</p></div>
		<div class="wpmcp-instruction"><strong>How to use it</strong><p>Send the key in the <code>x-api-key</code> header, or as <code>Authorization: Bearer YOUR_API_KEY</code>. If the app asks for an OAuth method, choose “No sign-in”.</p></div>
		<?php $this->field( 'wpmcp-key', 'API key', $key, true ); ?>
		<form method="post" class="wpmcp-rotate"><?php $this->form_fields( 'regenerate', 'security' ); ?><button class="button" data-confirm="Rotate the key? Apps using the old key stop working.">Rotate API key</button></form>
		<details><summary>Key in URL (only if an app cannot send headers)</summary><p>Content tools only, and it needs “Allow API keys in URLs” above.</p><?php $this->field( 'wpmcp-url-key', 'URL containing your key', rest_url( WPMCP_NAMESPACE . '/mcp/' . $key ), true ); ?></details>
		</section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'shield' ); ?>Built-in protection</h2></div>
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
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'bolt' ); ?>Plugin</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'Version', esc_html( WPMCP_VERSION ) . ( $new ? ' ' . $this->badge( 'Update available: ' . $new, 'warn' ) : ' ' . $this->badge( 'Up to date', 'ok' ) ) . ( current_user_can( 'update_plugins' ) ? ' <a class="button button-small" href="' . esc_url( $check ) . '">Check for updates</a>' : '' ) );
		$this->row( 'Updates from', '<a href="https://github.com/SaifullahQadeer/wpmcp/releases" target="_blank" rel="noopener">GitHub releases</a>' );
		$this->row( 'Author', 'Saifullah Qadeer' );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'system' ); ?>This site</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'WordPress', esc_html( get_bloginfo( 'version' ) ) . ( is_multisite() ? ' ' . $this->badge( 'Multisite', 'warn' ) : '' ) );
		$this->row( 'PHP', esc_html( PHP_VERSION ) );
		$this->row( 'Elementor', $env['active'] ? esc_html( $env['version'] . ' · ' . $env['generation'] ) : 'Not detected' );
		$this->row( 'HTTPS', $https ? $this->badge( 'On', 'ok' ) : $this->badge( 'Off: OAuth and extension tools need HTTPS', 'warn' ) );
		$this->row( 'Pretty permalinks', get_option( 'permalink_structure' ) ? $this->badge( 'On', 'ok' ) : $this->badge( 'Off: the REST API address needs them', 'warn' ) );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'code' ); ?>Endpoints</h2><p>For developers and troubleshooting.</p></div>
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

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'shield' ); ?>File safety & recovery</h2></div>
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

	/* ----------------------------------------------------------------- */
	/* Icons, changelog, banners and sidebar                             */
	/* ----------------------------------------------------------------- */

	/** Inline SVG icon (stroke style, inherits the text colour). */
	private function icon( $name, $size = 20 ) {
		static $paths = null;
		if ( null === $paths ) {
			$paths = array(
				'bolt'     => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
				'connect'  => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0z"/><path d="M12 18v4"/>',
				'tools'    => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
				'history'  => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/>',
				'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
				'system'   => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/>',
				'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
				'alert'    => '<path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18h.01"/>',
				'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
				'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
				'sparkles' => '<path d="m12 3 2 5 5 2-5 2-2 5-2-5-5-2 5-2z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
				'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
				'issue'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
				'code'     => '<path d="m8 8-5 4 5 4M16 8l5 4-5 4"/>',
				'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M16 7l3 3M14 9l2 2"/>',
				'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3 6"/>',
				'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
				'layers'   => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
				'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
				'eye-off'  => '<path d="M3 3l18 18"/><path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3.2 3.9M6.6 7.6A16.6 16.6 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
				'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
				'tick'     => '<path d="m5 12 5 5 9-10"/>',
			);
		}
		if ( ! isset( $paths[ $name ] ) ) { return ''; }
		return '<svg class="wpmcp-icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Recent releases, read from the Changelog section of readme.txt so the screen
	 * never drifts from what was shipped.
	 *
	 * @return array<int,array{version:string,items:string[]}>
	 */
	private function changelog( $limit = 3 ) {
		$file = WPMCP_PLUGIN_DIR . 'readme.txt';
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$pos  = strpos( $text, '== Changelog ==' );
		if ( false === $pos ) { return array(); }
		$entries = array();
		$current = null;
		foreach ( preg_split( '/\R/', substr( $text, $pos ) ) as $line ) {
			if ( preg_match( '/^= (\S+) =\s*$/', $line, $m ) ) {
				if ( null !== $current && count( $entries ) >= $limit ) { break; }
				$entries[] = array( 'version' => $m[1], 'items' => array() );
				$current   = count( $entries ) - 1;
			} elseif ( null !== $current && preg_match( '/^\* (.+)$/', $line, $m ) ) {
				$entries[ $current ]['items'][] = trim( $m[1] );
			} elseif ( null !== $current && preg_match( '/^\s+(\S.*)$/', $line, $m ) && $entries[ $current ]['items'] ) {
				$entries[ $current ]['items'][ count( $entries[ $current ]['items'] ) - 1 ] .= ' ' . trim( $m[1] );
			}
		}
		return array_slice( $entries, 0, $limit );
	}

	private function shorten( $text, $max = 120 ) {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/** One-time "what's new" banner after an update, until the administrator dismisses it. */
	private function render_whatsnew() {
		if ( get_user_meta( get_current_user_id(), 'wpmcp_seen_version', true ) === WPMCP_VERSION ) { return; }
		$log = $this->changelog( 1 );
		if ( ! $log || ! $log[0]['items'] ) { return; }
		?>
		<section class="wpmcp-banner" aria-label="What's new">
			<span class="wpmcp-banner-icon"><?php echo $this->icon( 'sparkles', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="wpmcp-banner-body"><strong>WP MCP <?php echo esc_html( WPMCP_VERSION ); ?> is installed</strong>
			<ul><?php foreach ( array_slice( $log[0]['items'], 0, 3 ) as $item ) : ?><li><?php echo esc_html( $this->shorten( $item, 150 ) ); ?></li><?php endforeach; ?></ul></div>
			<form method="post" class="wpmcp-banner-actions"><?php $this->form_fields( 'dismiss_whatsnew', 'connect' ); ?><button class="button button-primary">Got it</button><a class="button wpmcp-outline" href="https://github.com/SaifullahQadeer/wpmcp/releases" target="_blank" rel="noopener">All changes</a></form>
		</section>
		<?php
	}

	/** Right-hand column shown on every tab: update notice, changelog, health checklist, links. */
	private function render_sidebar( $enabled ) {
		$new    = ( new WPMCP_Updater() )->cached_update();
		$log    = $this->changelog( 2 );
		$https  = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$env    = WPMCP_Elementor::environment();
		$checks = array(
			array( $enabled, 'MCP server', $enabled ? 'Running' : 'Paused' ),
			array( $https, 'HTTPS', $https ? 'On' : 'Needed for sign-in' ),
			array( (bool) get_option( 'permalink_structure' ), 'Pretty permalinks', get_option( 'permalink_structure' ) ? 'On' : 'Needed for the REST API' ),
			array( WPMCP_OAuth::enabled(), 'OAuth sign-in', WPMCP_OAuth::enabled() ? 'On' : 'Off' ),
			array( ! empty( $env['active'] ), 'Elementor', ! empty( $env['active'] ) ? $env['version'] : 'Not detected' ),
		);
		if ( $new ) : ?>
			<section class="wpmcp-side-card wpmcp-side-update"><span class="wpmcp-badge wpmcp-badge-coral">Update available</span>
			<h3>Version <?php echo esc_html( $new ); ?> is ready</h3><p>You have <?php echo esc_html( WPMCP_VERSION ); ?>. Updating takes a few seconds.</p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">Update now</a></section>
		<?php endif; ?>
		<section class="wpmcp-side-card"><h3><?php echo $this->icon( 'sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> What’s new</h3>
		<?php foreach ( $log as $entry ) : ?>
			<div class="wpmcp-log"><span class="wpmcp-badge wpmcp-badge-neutral">v<?php echo esc_html( $entry['version'] ); ?></span>
			<ul><?php foreach ( array_slice( $entry['items'], 0, 3 ) as $item ) : ?><li><?php echo esc_html( $this->shorten( $item ) ); ?></li><?php endforeach; ?></ul></div>
		<?php endforeach; ?>
		<a class="wpmcp-link" href="https://github.com/SaifullahQadeer/wpmcp/releases" target="_blank" rel="noopener">All releases <?php echo $this->icon( 'external', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></section>

		<section class="wpmcp-side-card"><h3><?php echo $this->icon( 'activity', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Health</h3>
		<ul class="wpmcp-health"><?php foreach ( $checks as $check ) : ?>
			<li class="<?php echo $check[0] ? 'is-ok' : 'is-warn'; ?>"><?php echo $this->icon( $check[0] ? 'check' : 'alert', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><strong><?php echo esc_html( $check[1] ); ?></strong><small><?php echo esc_html( $check[2] ); ?></small></span></li>
		<?php endforeach; ?></ul></section>

		<section class="wpmcp-side-card"><h3><?php echo $this->icon( 'book', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Resources</h3>
		<ul class="wpmcp-links">
			<li><a href="https://github.com/SaifullahQadeer/wpmcp#readme" target="_blank" rel="noopener"><?php echo $this->icon( 'book', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Documentation</a></li>
			<li><a href="https://github.com/SaifullahQadeer/wpmcp/issues" target="_blank" rel="noopener"><?php echo $this->icon( 'issue', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Report an issue</a></li>
			<li><a href="https://github.com/SaifullahQadeer/wpmcp/releases" target="_blank" rel="noopener"><?php echo $this->icon( 'code', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Release notes</a></li>
		</ul></section>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$key     = (string) get_option( 'wpmcp_api_key', '' );
		$url     = rest_url( WPMCP_NAMESPACE . '/mcp' );
		$enabled = '1' === (string) get_option( 'wpmcp_enabled', '1' );
		$tab     = $this->current_tab();
		$new     = ( new WPMCP_Updater() )->cached_update();
		$notice  = get_transient( 'wpmcp_notice_' . get_current_user_id() );
		delete_transient( 'wpmcp_notice_' . get_current_user_id() );
		$icons   = array( 'connect' => 'connect', 'tools' => 'tools', 'history' => 'history', 'security' => 'shield', 'system' => 'system' );
		?>
		<div class="wrap wpmcp">
		<header class="wpmcp-header"><div class="wpmcp-brand"><span class="wpmcp-mark"><?php echo $this->icon( 'bolt', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><h1>WP MCP <span>v<?php echo esc_html( WPMCP_VERSION ); ?></span></h1><p>Let AI apps manage your WordPress site, with you in control.</p></div></div>
		<div class="wpmcp-header-side"><?php if ( $new ) : ?><a class="wpmcp-pill wpmcp-pill-warn" href="<?php echo esc_url( $this->tab_url( 'system' ) ); ?>">Update available: <?php echo esc_html( $new ); ?></a><?php endif; ?></div></header>
		<nav class="wpmcp-tabs" aria-label="WP MCP sections"><?php foreach ( $this->tabs() as $id => $label ) : ?><a href="<?php echo esc_url( $this->tab_url( $id ) ); ?>"<?php echo $id === $tab ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo $this->icon( $icons[ $id ], 17 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
		<?php echo $this->update_notice_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<?php if ( is_array( $notice ) ) : ?><div class="notice inline wpmcp-notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['wpmcp_connected'] ) ) : ?><div class="notice inline wpmcp-notice notice-success"><p><strong>Claude is connected.</strong> Ask Claude to run <code>wp_ping</code> to try it.</p></div><?php endif; ?>
		<?php if ( ! is_ssl() ) : ?><div class="notice inline wpmcp-notice notice-warning"><p>Configure HTTPS before connecting. OAuth sign-in and extension tools need a secure request.</p></div><?php endif; ?>
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?><div class="notice inline wpmcp-notice notice-warning"><p>Enable pretty permalinks: the REST API address does not work with Plain permalinks.</p></div><?php endif; ?>
		<?php if ( 'connect' === $tab ) { $this->render_whatsnew(); } ?>
		<div class="wpmcp-grid"><main class="wpmcp-main">
		<?php
		if ( 'tools' === $tab ) { $this->render_tools(); }
		elseif ( 'history' === $tab ) { $this->render_history(); }
		elseif ( 'security' === $tab ) { $this->render_security( $key ); }
		elseif ( 'system' === $tab ) { $this->render_system( $url ); }
		else { $this->render_stats( $enabled ); $this->render_connect( $url ); $this->render_connected(); }
		?>
		</main><aside class="wpmcp-side" aria-label="Updates and help"><?php $this->render_sidebar( $enabled ); ?></aside></div>
		<p id="wpmcp-feedback" class="screen-reader-text" role="status" aria-live="polite"></p></div>
		<?php
	}
}
