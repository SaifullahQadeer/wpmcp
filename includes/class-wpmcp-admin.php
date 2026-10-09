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

	/** "Check for updates" link: look up the latest release now, then show the result where the link was clicked. */
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
		else { $class = 'notice-error'; $text = 'Could not reach the update server to check for updates. Try again in a few minutes.'; }
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
		return array( 'connect' => 'Connect', 'tools' => 'Tools', 'history' => 'History', 'security' => 'Security', 'plan' => 'Plan', 'system' => 'System' );
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
			foreach ( array( 'enabled', 'oauth_enabled', 'allow_url_key' ) as $setting ) {
				update_option( 'wpmcp_' . $setting, isset( $_POST[ 'wpmcp_' . $setting ] ) ? '1' : '0' );
			}
			do_action( 'wpmcp_save_settings' ); // The Pro add-on saves its own switches here. The nonce and capability were checked above.
			$key_level = isset( $_POST['wpmcp_key_level'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_key_level'] ) ) : 'full';
			update_option( 'wpmcp_key_level', WPMCP_Permissions::valid( $key_level ) ? $key_level : 'full' );
			$message = 'Settings saved.';
		} elseif ( 'set_level' === $action ) {
			$grant   = isset( $_POST['wpmcp_grant'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_grant'] ) ) : '';
			$level   = isset( $_POST['wpmcp_level'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_level'] ) ) : '';
			$message = WPMCP_OAuth::set_grant_level( $grant, $level ) ? 'Access changed to "' . WPMCP_Permissions::label( $level ) . '". It applies on the app\'s next request.' : 'Could not change that connection.';
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
	public function toggle( $key, $title, $description, $default = '0' ) {
		?><label class="wpmcp-toggle"><input type="checkbox" name="wpmcp_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', get_option( 'wpmcp_' . $key, $default ) ); ?> /><span class="wpmcp-switch" aria-hidden="true"></span><span class="wpmcp-toggle-text"><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $description ); ?></small></span></label><?php
	}
	public function badge( $text, $tone = 'neutral' ) {
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
		$tools   = $this->tool_inventory();
		$tiles = array(
			array( 'system', 'Server', $enabled ? 'Running' : 'Paused', $enabled ? 'ok' : 'warn' ),
			array( 'users', 'Connected apps', (string) count( $grants ), '' ),
			array( 'activity', 'Last activity', $last ? human_time_diff( $last ) . ' ago' : 'None yet', '' ),
			array( 'layers', 'Tools available', $tools['available'] . ' of ' . $tools['total'], '' ),
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
		<table class="wpmcp-table"><thead><tr><th>App</th><th>Acts as</th><th>Access</th><th>Connected</th><th>Last used</th><th></th></tr></thead><tbody>
		<?php foreach ( $grants as $id => $g ) : $user = get_userdata( (int) $g['user_id'] ); ?>
			<tr><td><strong><?php echo esc_html( $g['client_name'] ); ?></strong></td><td><?php echo esc_html( $user ? $user->display_name : 'Unknown user' ); ?></td>
			<td><form method="post" class="wpmcp-level-form"><?php $this->form_fields( 'set_level', 'connect' ); ?><input type="hidden" name="wpmcp_grant" value="<?php echo esc_attr( $id ); ?>" /><select name="wpmcp_level" data-autosubmit aria-label="Access level for <?php echo esc_attr( $g['client_name'] ); ?>"><?php foreach ( WPMCP_Permissions::levels() as $level_id => $info ) : ?><option value="<?php echo esc_attr( $level_id ); ?>"<?php echo WPMCP_Permissions::normalize( isset( $g['level'] ) ? $g['level'] : 'full' ) === $level_id ? ' selected' : ''; ?>><?php echo esc_html( $info[0] ); ?></option><?php endforeach; ?></select></form></td>
			<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $g['created'] ) ); ?></td><td><?php echo esc_html( human_time_diff( (int) $g['last_used'] ) . ' ago' ); ?></td>
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
	/** @return array{0:bool,1:string} whether the tool can run now, and what to turn on or buy if not. */
	private function tool_status( $name ) {
		if ( ! WPMCP_Plans::allows( $name ) ) { return array( false, WPMCP_Plans::label( WPMCP_Plans::plan_of( $name ) ) . ' plan' ); }
		return apply_filters( 'wpmcp_tool_status', array( true, '' ), $name );
	}

	/** Every tool the screen should count: those offered to apps, plus the ones this plan locks that have no code installed. */
	private function tool_inventory() {
		$all    = WPMCP_MCP::tools_spec();
		$offer  = apply_filters( 'wpmcp_offered_tools', $all );
		$known  = array_column( $all, 'name' );
		$locked = array();
		foreach ( WPMCP_Plans::groups() as $group ) {
			foreach ( $group[1] as $tool ) { if ( ! in_array( $tool, $known, true ) ) { $locked[] = $tool; } }
		}
		$available = 0;
		foreach ( $offer as $spec ) { list( $on ) = $this->tool_status( $spec['name'] ); if ( $on ) { $available++; } }
		return array( 'offered' => $offer, 'locked' => $locked, 'available' => $available, 'total' => count( $offer ) + count( $locked ) );
	}

	private function render_tools() {
		$groups = array(
			'Site & content'   => array( 'wp_ping', 'wp_list_post_types', 'wp_list_content', 'wp_get_content', 'wp_create_content', 'wp_update_content' ),
			'Elementor'        => array( 'wp_get_elementor', 'wp_edit_elementor_text' ),
			'Media & taxonomy' => array( 'wp_upload_media', 'wp_list_media', 'wp_list_terms', 'wp_create_term' ),
			'History'          => array( 'wp_list_history' ),
		);
		foreach ( WPMCP_Plans::groups() as $label => $group ) { $groups[ $label ] = $group[1]; }
		$inv   = $this->tool_inventory();
		$specs = array();
		foreach ( $inv['offered'] as $spec ) { $specs[ $spec['name'] ] = $spec; }
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'tools' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Tools</h2><p><?php echo (int) $inv['available']; ?> of <?php echo (int) $inv['total']; ?> are on. Hover a tool to see what it does. Each app’s access level (Read only, Read and edit, Full access) decides which of these it can use, and your plan decides which exist: <?php echo esc_html( WPMCP_Plans::label( WPMCP_Plans::current() ) ); ?> right now. Locked tools are in the Plus and Pro plans (see the Plan tab).</p></div>
		<?php foreach ( $groups as $group => $names ) :
			$shown = array_filter( $names, function ( $tool ) use ( $specs, $inv ) { return isset( $specs[ $tool ] ) || in_array( $tool, $inv['locked'], true ); } );
			if ( ! $shown ) { continue; } // A group whose tools are not offered (WooCommerce while it is off) is not shown.
			?>
			<h3 class="wpmcp-group"><?php echo esc_html( $group ); ?></h3>
			<ul class="wpmcp-toollist">
			<?php foreach ( $shown as $name ) :
				list( $enabled, $why ) = $this->tool_status( $name );
				if ( isset( $specs[ $name ] ) ) {
					$spec  = $specs[ $name ];
					$parts = preg_split( '/(?<=[.!?])\s/', (string) $spec['description'], 2 );
					$title = isset( $spec['title'] ) ? $spec['title'] : $name;
					$tip   = $parts[0];
					if ( false !== strpos( $name, 'delete' ) ) { $access = $this->badge( 'Delete', 'danger' ); }
					elseif ( ! empty( $spec['annotations']['readOnlyHint'] ) ) { $access = $this->badge( 'Read', 'neutral' ); }
					else { $access = $this->badge( 'Write', 'warn' ); }
				} else {
					$title  = ucfirst( str_replace( '_', ' ', substr( $name, 3 ) ) );
					$tip    = 'Part of the ' . WPMCP_Plans::label( WPMCP_Plans::plan_of( $name ) ) . ' plan.';
					$access = '';
				}
				if ( ! WPMCP_Plans::allows( $name ) ) { $access = $this->badge( WPMCP_Plans::label( WPMCP_Plans::plan_of( $name ) ), 'coral' ); $why = ''; }
				?>
				<li class="<?php echo $enabled ? '' : 'is-off'; ?>" title="<?php echo esc_attr( $tip ); ?>"><span class="wpmcp-tool-main"><strong><?php echo esc_html( $title ); ?></strong><code><?php echo esc_html( $name ); ?></code></span><span class="wpmcp-tool-meta"><?php echo $enabled || '' === $why ? '' : '<small class="wpmcp-why">' . esc_html( $why ) . '</small>'; echo $access; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes. ?></span></li>
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
				<td class="wpmcp-right"><?php if ( 'applied' === $row['status'] && $row['can_rollback'] && ! WPMCP_Plans::at_least( 'pro' ) ) : ?>
						<?php echo $this->badge( 'Pro', 'coral' ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes. ?><small class="wpmcp-why">Rolling back is in the Pro plan</small>
					<?php elseif ( 'applied' === $row['status'] && $row['can_rollback'] ) : ?>
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
		<div class="wpmcp-select-row"><label for="wpmcp_key_level"><strong>Access for the API key</strong><small>What an app using the API key may do. Apps that sign in with OAuth get their own access level when you approve them.</small></label><select id="wpmcp_key_level" name="wpmcp_key_level"><?php foreach ( WPMCP_Permissions::levels() as $level_id => $info ) : ?><option value="<?php echo esc_attr( $level_id ); ?>"<?php echo WPMCP_Permissions::normalize( get_option( 'wpmcp_key_level', 'full' ) ) === $level_id ? ' selected' : ''; ?>><?php echo esc_html( $info[0] ); ?></option><?php endforeach; ?></select></div>
		</section>
		<?php do_action( 'wpmcp_security_panels', $this ); ?>
		<div class="wpmcp-save"><button class="button button-primary button-hero">Save settings</button></div>
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
		$new      = ( new WPMCP_Updater() )->cached_update();
		$check    = wp_nonce_url( admin_url( 'admin.php?page=wp-mcp&wpmcp_check_update=1&wpmcp_return=1' ), 'wpmcp_check_update' );
		$meta     = WPMCP_OAuth::server_metadata();
		$this->render_health();
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'bolt' ); ?>Plugin</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'Version', esc_html( WPMCP_VERSION ) . ( $new ? ' ' . $this->badge( 'Update available: ' . $new, 'warn' ) : ' ' . $this->badge( 'Up to date', 'ok' ) ) . ( current_user_can( 'update_plugins' ) ? ' <a class="button button-small" href="' . esc_url( $check ) . '">Check for updates</a>' : '' ) );
		$this->row( 'Updates from', esc_html( wp_parse_url( WPMCP_Updater::server(), PHP_URL_HOST ) ) );
		$this->row( 'Author', 'Saifullah Qadeer' );
		?>
		</tbody></table></section>

		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'system' ); ?>This site</h2></div>
		<table class="wpmcp-kv"><tbody>
		<?php
		$this->row( 'WordPress', esc_html( get_bloginfo( 'version' ) ) . ( is_multisite() ? ' ' . $this->badge( 'Multisite', 'warn' ) : '' ) );
		$this->row( 'PHP', esc_html( PHP_VERSION ) );
		$this->row( 'Elementor', $env['active'] ? esc_html( $env['version'] . ' · ' . $env['generation'] ) : 'Not detected' );
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

		<?php do_action( 'wpmcp_system_panels', $this ); ?>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Page shell                                                        */
	/* ----------------------------------------------------------------- */

	/** Setup checks. State is ok, warn (needs attention) or info (optional, not installed). */
	/** Setup checks. State is ok, warn (needs attention) or info (optional, not installed). The Pro add-on adds its own through "wpmcp_health_checks". */
	private function health_checks() {
		$enabled = '1' === (string) get_option( 'wpmcp_enabled', '1' );
		$https   = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$pretty  = (bool) get_option( 'permalink_structure' );
		$el      = WPMCP_Elementor::environment();
		$checks  = array(
			array( $enabled ? 'ok' : 'warn', 'MCP server', $enabled ? 'Running' : 'Paused. Turn it on in Security.' ),
			array( $https ? 'ok' : 'warn', 'HTTPS', $https ? 'On' : 'Off. Sign-in needs it.' ),
			array( $pretty ? 'ok' : 'warn', 'Pretty permalinks', $pretty ? 'On' : 'Off. The REST API address needs them.' ),
			array( WPMCP_OAuth::enabled() ? 'ok' : 'warn', 'OAuth sign-in', WPMCP_OAuth::enabled() ? 'On' : 'Off. Apps need an API key instead.' ),
			array( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'warn', 'PHP', PHP_VERSION ),
			array( ! empty( $el['active'] ) ? 'ok' : 'info', 'Elementor', ! empty( $el['active'] ) ? $el['version'] . ' · ' . $el['generation'] : 'Not installed' ),
			array( WPMCP_Plans::pro_installed() ? 'ok' : 'info', 'WP MCP Pro add-on', WPMCP_Plans::pro_installed() ? WPMCP_Plans::label( WPMCP_Plans::current() ) . ' plan' : 'Not installed. Free plan.' ),
		);
		return apply_filters( 'wpmcp_health_checks', $checks );
	}

	private function render_health() {
		$checks = $this->health_checks();
		$warn   = count( array_filter( $checks, function ( $c ) { return 'warn' === $c[0]; } ) );
		$icons  = array( 'ok' => 'check', 'warn' => 'alert', 'info' => 'issue' );
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Health <?php echo $warn ? $this->badge( $warn . ' to fix', 'warn' ) : $this->badge( 'All good', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2></div>
		<ul class="wpmcp-health is-grid"><?php foreach ( $checks as $check ) : ?>
			<li class="is-<?php echo esc_attr( $check[0] ); ?>"><?php echo $this->icon( $icons[ $check[0] ], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><strong><?php echo esc_html( $check[1] ); ?></strong><small><?php echo esc_html( $check[2] ); ?></small></span></li>
		<?php endforeach; ?></ul></section>
		<?php
	}

	/** What WP MCP works with, and which of it is installed on this site. */
	/** What WP MCP works with, and which of it is installed on this site. The Pro add-on adds tiles for what its plan covers. */
	private function render_compat() {
		$el      = WPMCP_Elementor::environment();
		$el_on   = ! empty( $el['active'] );
		$el_v4   = $el_on && 'v4-atomic' === $el['generation'];
		$plus    = WPMCP_Plans::at_least( 'plus' );
		$pro     = WPMCP_Plans::at_least( 'pro' );
		$layouts = $plus ? 'Containers, sections and widgets, plus page settings.' : 'Read layouts and change their text.';
		$tiles   = array(
			array( 'tools', 'Elementor 3 (classic)', $layouts, $el_on && ! $el_v4, $el_on && ! $el_v4 ? $el['version'] : '' ),
			array( 'sparkles', 'Elementor 4 (atomic)', $plus ? 'Atomic editor layouts in the v4 data shape.' : 'Read atomic layouts and change their text.', $el_v4, $el_v4 ? $el['version'] : '' ),
			array( 'book', 'Posts and pages', 'Content, media, categories and tags.', true, 'Active' ),
		);
		if ( ! $plus ) {
			$tiles[] = array( 'layers', 'Block editor and Divi', 'Gutenberg, Divi 4 and Divi 5 layouts, and clearing caches.', false, '', 'Plus' );
		}
		if ( ! $pro ) {
			$tiles[] = array( 'cart', 'WooCommerce', 'Products, orders, coupons, stock and store settings.', false, '', 'Pro' );
			$tiles[] = array( 'key', 'Advanced Custom Fields', 'Fields, groups, custom post types and taxonomies.', false, '', 'Pro' );
			$tiles[] = array( 'system', 'Site tools', 'Delete, undo, settings, plugins and themes.', false, '', 'Pro' );
		}
		$tiles = apply_filters( 'wpmcp_compat_tiles', $tiles );
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Works with</h2><p>Page builders and tools your AI app can work with. Green means it is active on this site; Ready means it is supported once you install it; Plus and Pro mark what needs a paid plan.</p></div>
		<div class="wpmcp-compat-grid"><?php foreach ( $tiles as $tile ) : ?>
			<div class="wpmcp-compat"><span class="wpmcp-compat-icon"><?php echo $this->icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php echo esc_html( $tile[1] ); ?></strong><small><?php echo esc_html( $tile[2] ); ?></small></div>
			<?php echo ! empty( $tile[5] ) ? $this->badge( $tile[5], 'coral' ) : ( $tile[3] ? $this->badge( '' !== $tile[4] ? $tile[4] : 'Installed', 'ok' ) : $this->badge( 'Ready', 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<?php endforeach; ?></div></section>
		<?php
	}

	/** Plan tab: what each plan includes, where this site stands, and how to unlock more. */
	private function render_plan() {
		$plan     = WPMCP_Plans::current();
		$features = WPMCP_Plans::features();
		$blurbs   = array( 'free' => 'Start using WordPress with AI.', 'plus' => 'AI-powered website design.', 'pro' => 'Complete WordPress AI management.' );
		?>
		<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'sparkles' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Your plan <?php echo $this->badge( WPMCP_Plans::label( $plan ), 'free' === $plan ? 'neutral' : 'coral' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2><p><?php echo 'free' === $plan ? 'You are on the free plan. Plus and Pro add page-builder, WooCommerce and ACF support through the WP MCP Pro add-on.' : 'Your license unlocks everything in the ' . esc_html( WPMCP_Plans::label( $plan ) ) . ' plan.'; ?></p></div>
		<div class="wpmcp-plans">
		<?php foreach ( array( 'free', 'plus', 'pro' ) as $id ) : ?>
			<div class="wpmcp-plan-card<?php echo $id === $plan ? ' is-current' : ''; ?>">
				<h3><?php echo esc_html( WPMCP_Plans::label( $id ) ); ?><?php echo $id === $plan ? ' ' . $this->badge( 'Your plan', 'ok' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></h3>
				<p><?php echo esc_html( $blurbs[ $id ] ); ?></p>
				<ul class="wpmcp-checks"><?php foreach ( $features[ $id ] as $feature ) : ?><li><?php echo $this->icon( 'tick', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $feature ); ?></span></li><?php endforeach; ?></ul>
			</div>
		<?php endforeach; ?>
		</div>
		<p class="wpmcp-plan-link"><a class="wpmcp-link" href="<?php echo esc_url( WPMCP_Plans::UPGRADE_URL ); ?>" target="_blank" rel="noopener">See plans and pricing <?php echo $this->icon( 'external', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></p>
		</section>
		<?php
		if ( WPMCP_Plans::pro_installed() ) {
			do_action( 'wpmcp_plan_panels', $this );
		} else {
			?>
			<section class="wpmcp-panel"><div class="wpmcp-panel-head"><h2><?php echo $this->icon( 'key' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Unlock Plus or Pro</h2><p>Paid plans come from the WP MCP Pro add-on, a separate plugin that works with this one.</p></div>
			<ol class="wpmcp-list"><li>Get a Plus or Pro license and the WP MCP Pro add-on from the plans page.</li><li>Install and activate the add-on on this site, next to WP MCP.</li><li>Come back to this tab and enter your license key.</li></ol></section>
			<?php
		}
	}

	/* ----------------------------------------------------------------- */
	/* Icons, changelog, banners and sidebar                             */
	/* ----------------------------------------------------------------- */

	/** Inline SVG icon (stroke style, inherits the text colour). */
	public function icon( $name, $size = 20 ) {
		static $paths = null;
		if ( null === $paths ) {
			$paths = array(
				'bolt'     => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
				'cart'     => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/>',
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

	/** Right-hand column shown on every tab: update notice, changelog, links. */
	private function render_sidebar( $enabled ) {
		$new    = ( new WPMCP_Updater() )->cached_update();
		$log    = $this->changelog( 2 );
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
		$icons   = array( 'connect' => 'connect', 'tools' => 'tools', 'history' => 'history', 'security' => 'shield', 'plan' => 'sparkles', 'system' => 'system' );
		?>
		<div class="wrap wpmcp">
		<header class="wpmcp-header"><div class="wpmcp-brand"><img class="wpmcp-logo" src="<?php echo esc_url( WPMCP_PLUGIN_URL . 'assets/brand/wp-mcp-icon.png' ); ?>" width="254" height="36" alt="" /><div><h1>WP MCP <span>v<?php echo esc_html( WPMCP_VERSION ); ?></span></h1><p>Let AI apps manage your WordPress site, with you in control.</p></div></div>
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
		elseif ( 'plan' === $tab ) { $this->render_plan(); }
		elseif ( 'system' === $tab ) { $this->render_system( $url ); }
		else { $this->render_stats( $enabled ); $this->render_connect( $url ); $this->render_connected(); $this->render_compat(); }
		?>
		</main><aside class="wpmcp-side" aria-label="Updates and help"><?php $this->render_sidebar( $enabled ); ?></aside></div>
		<p id="wpmcp-feedback" class="screen-reader-text" role="status" aria-live="polite"></p></div>
		<?php
	}
}
