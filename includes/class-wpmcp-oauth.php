<?php
/**
 * OAuth 2.1 authorization server so MCP clients can connect without a pasted key.
 *
 * Flow: the client discovers this server from the 401 challenge or the
 * /.well-known documents, registers itself (RFC 7591), sends the user to the
 * consent page in wp-admin, then exchanges the code (PKCE S256) for tokens.
 * Only administrators can approve; tokens act as the approving administrator.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_OAuth {
	const SCOPE         = 'wpmcp';
	const CODE_TTL      = 300;
	const REQUEST_TTL   = 600;
	const ACCESS_TTL    = 3600;
	const REFRESH_IDLE  = 7776000; // 90 days without use.
	const MAX_CLIENTS   = 100;
	const MAX_FAILURES  = 30;
	const PAGE          = 'wpmcp-authorize';

	/* ----------------------------------------------------------------- */
	/* Wiring                                                            */
	/* ----------------------------------------------------------------- */

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'serve_well_known' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'add_authorize_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'authorize_assets' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'authorize_suppress_notices' ), 1000 );
	}

	/** On unless disabled, and only on HTTPS sites because tokens travel in headers. */
	public static function enabled() {
		return '1' === (string) get_option( 'wpmcp_oauth_enabled', '1' ) && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	public static function issuer() { return untrailingslashit( home_url() ); }
	public static function resource_url() { return rest_url( WPMCP_NAMESPACE . '/mcp' ); }
	public static function authorize_url() { return admin_url( 'admin.php?page=' . self::PAGE ); }

	public static function server_metadata() {
		return array(
			'issuer'                                    => self::issuer(),
			'authorization_endpoint'                    => self::authorize_url(),
			'token_endpoint'                            => rest_url( WPMCP_NAMESPACE . '/oauth/token' ),
			'registration_endpoint'                     => rest_url( WPMCP_NAMESPACE . '/oauth/register' ),
			'revocation_endpoint'                       => rest_url( WPMCP_NAMESPACE . '/oauth/revoke' ),
			'response_types_supported'                  => array( 'code' ),
			'grant_types_supported'                     => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'          => array( 'S256' ),
			'token_endpoint_auth_methods_supported'     => array( 'none' ),
			'scopes_supported'                          => array( self::SCOPE ),
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	public static function resource_metadata() {
		return array(
			'resource'                 => self::resource_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( self::SCOPE ),
			'resource_name'            => 'WP MCP: ' . get_bloginfo( 'name' ),
		);
	}

	/** Answer the discovery documents at the site root, with or without a path suffix. */
	public static function serve_well_known() {
		if ( ! self::enabled() || ! isset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] ) || ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) { return; }
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( ! is_string( $path ) || false === strpos( $path, '/.well-known/oauth-' ) ) { return; }
		if ( preg_match( '#/\.well-known/oauth-authorization-server(?:/|$)#', $path ) ) { $doc = self::server_metadata(); }
		elseif ( preg_match( '#/\.well-known/oauth-protected-resource(?:/|$)#', $path ) ) { $doc = self::resource_metadata(); }
		else { return; }
		self::no_cache();
		header( 'Access-Control-Allow-Origin: *' );
		wp_send_json( $doc );
	}

	/** Header that tells a client without valid credentials where to start OAuth. */
	public static function send_challenge( $error = '' ) {
		if ( headers_sent() ) { return; }
		$value = 'Bearer realm="WP MCP", resource_metadata="' . esc_url_raw( rest_url( WPMCP_NAMESPACE . '/oauth/resource' ) ) . '"';
		if ( '' !== $error ) { $value .= ', error="' . $error . '"'; }
		header( 'WWW-Authenticate: ' . $value );
	}

	/* ----------------------------------------------------------------- */
	/* Storage helpers                                                   */
	/* ----------------------------------------------------------------- */

	private static function random( $bytes = 24 ) { return bin2hex( random_bytes( $bytes ) ); }
	private static function digest( $value ) { return hash( 'sha256', (string) $value ); }

	private static function clients() { $c = get_option( 'wpmcp_oauth_clients', array() ); return is_array( $c ) ? $c : array(); }
	private static function grants() { $g = get_option( 'wpmcp_oauth_grants', array() ); return is_array( $g ) ? $g : array(); }
	private static function save_clients( $c ) { update_option( 'wpmcp_oauth_clients', $c, false ); }
	private static function save_grants( $g ) { update_option( 'wpmcp_oauth_grants', $g, false ); }

	private static function throttle_key() { return 'wpmcp_oauth_fail_' . substr( WPMCP_Auth::fail_key(), -32 ); }
	private static function throttled() { return (int) get_transient( self::throttle_key() ) >= self::MAX_FAILURES; }
	private static function note_failure() { set_transient( self::throttle_key(), (int) get_transient( self::throttle_key() ) + 1, 900 ); }

	/* ----------------------------------------------------------------- */
	/* REST endpoints (register, token, revoke, metadata)                */
	/* ----------------------------------------------------------------- */

	public static function register_routes() {
		$ns = WPMCP_NAMESPACE . '/oauth';
		$any = '__return_true';
		register_rest_route( $ns, '/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_register' ), 'permission_callback' => $any ) );
		register_rest_route( $ns, '/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_token' ), 'permission_callback' => $any ) );
		register_rest_route( $ns, '/revoke', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_revoke' ), 'permission_callback' => $any ) );
		register_rest_route( $ns, '/resource', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_resource' ), 'permission_callback' => $any ) );
		register_rest_route( $ns, '/server', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_server' ), 'permission_callback' => $any ) );
	}

	private static function json( $status, $data ) {
		self::no_cache();
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' ); // LiteSpeed ignores Cache-Control on REST responses and kept discovery data for a week.
		return $response;
	}

	/** Sign-in responses are never stored by a page cache: they depend on who asks and when. */
	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } // WP Rocket, W3 Total Cache, WP Super Cache and others honour this.
		do_action( 'litespeed_control_set_nocache', 'WP MCP sign-in' );
		nocache_headers();
		if ( ! headers_sent() ) { header( 'X-LiteSpeed-Cache-Control: no-cache' ); }
	}

	private static function oauth_error( $status, $code, $description ) {
		return array( $status, array( 'error' => $code, 'error_description' => $description ) );
	}

	public static function rest_resource() { return self::enabled() ? self::json( 200, self::resource_metadata() ) : self::json( 404, array( 'error' => 'not_found' ) ); }
	public static function rest_server() { return self::enabled() ? self::json( 200, self::server_metadata() ) : self::json( 404, array( 'error' => 'not_found' ) ); }

	public static function rest_register( $request ) {
		$body = $request->get_json_params();
		list( $status, $data ) = self::do_register( is_array( $body ) ? $body : array() );
		return self::json( $status, $data );
	}

	public static function rest_token( $request ) {
		$params = $request->get_body_params();
		if ( empty( $params ) ) { $params = (array) $request->get_json_params(); }
		$basic = $request->get_header( 'authorization' );
		if ( empty( $params['client_id'] ) && is_string( $basic ) && 0 === stripos( $basic, 'Basic ' ) ) {
			$pair = explode( ':', (string) base64_decode( substr( $basic, 6 ), true ), 2 );
			if ( ! empty( $pair[0] ) ) { $params['client_id'] = rawurldecode( $pair[0] ); }
		}
		list( $status, $data ) = self::do_token( $params );
		return self::json( $status, $data );
	}

	public static function rest_revoke( $request ) {
		$params = $request->get_body_params();
		if ( ! empty( $params['token'] ) && is_string( $params['token'] ) ) { self::revoke_token( $params['token'] ); }
		return self::json( 200, new stdClass() );
	}

	/* ----------------------------------------------------------------- */
	/* Dynamic client registration (RFC 7591)                            */
	/* ----------------------------------------------------------------- */

	/** A redirect URI must be https, plain http on loopback, or a custom app scheme. No fragments. */
	public static function valid_redirect_uri( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 500 || false !== strpos( $uri, '#' ) || preg_match( '/[\x00-\x20\\\\]/', $uri ) ) { return false; }
		$parts = wp_parse_url( $uri );
		if ( ! $parts || empty( $parts['scheme'] ) ) { return false; }
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) { return ! empty( $parts['host'] ) && empty( $parts['user'] ); }
		if ( 'http' === $scheme ) { return ! empty( $parts['host'] ) && in_array( $parts['host'], array( '127.0.0.1', 'localhost', '[::1]', '::1' ), true ); }
		if ( in_array( $scheme, array( 'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss' ), true ) ) { return false; }
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme ) && false !== strpos( $uri, '://' );
	}

	public static function do_register( $body ) {
		if ( ! self::enabled() ) { return self::oauth_error( 403, 'access_denied', 'OAuth is disabled on this site.' ); }
		$rate = 'wpmcp_oauth_reg_' . substr( WPMCP_Auth::fail_key(), -32 );
		if ( (int) get_transient( $rate ) >= 20 ) { return self::oauth_error( 429, 'temporarily_unavailable', 'Too many registrations. Try again later.' ); }
		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_values( $body['redirect_uris'] ) : array();
		if ( ! $uris || count( $uris ) > 10 ) { return self::oauth_error( 400, 'invalid_redirect_uri', 'Provide 1 to 10 redirect_uris.' ); }
		foreach ( $uris as $uri ) {
			if ( ! self::valid_redirect_uri( $uri ) ) { return self::oauth_error( 400, 'invalid_redirect_uri', 'A redirect URI must use https, loopback http, or an app scheme, with no fragment.' ); }
		}
		$name = isset( $body['client_name'] ) && is_string( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : '';
		$name = '' === $name ? 'MCP client' : mb_substr( $name, 0, 100 );

		$clients = self::clients();
		$in_use  = array_column( self::grants(), 'client_id' );
		foreach ( $clients as $id => $client ) { // Drop stale registrations that never connected.
			if ( ! in_array( $id, $in_use, true ) && $client['created'] < time() - 30 * DAY_IN_SECONDS ) { unset( $clients[ $id ] ); }
		}
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			foreach ( $clients as $id => $client ) { if ( ! in_array( $id, $in_use, true ) && count( $clients ) >= self::MAX_CLIENTS ) { unset( $clients[ $id ] ); } }
			if ( count( $clients ) >= self::MAX_CLIENTS ) { return self::oauth_error( 429, 'temporarily_unavailable', 'Too many connected apps. Revoke one first.' ); }
		}
		$client_id = 'wpmcp_c_' . self::random( 16 );
		$clients[ $client_id ] = array( 'name' => $name, 'redirect_uris' => $uris, 'created' => time() );
		self::save_clients( $clients );
		set_transient( $rate, (int) get_transient( $rate ) + 1, HOUR_IN_SECONDS );

		return array( 201, array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'scope'                      => self::SCOPE,
		) );
	}

	/* ----------------------------------------------------------------- */
	/* Authorization (consent page in wp-admin)                          */
	/* ----------------------------------------------------------------- */

	public static function add_authorize_page() {
		add_submenu_page( 'options.php', 'Authorize connection', 'Authorize connection', 'manage_options', self::PAGE, array( __CLASS__, 'render_authorize' ) );
	}

	/** The approval page uses the same stylesheet as the WP MCP screen. */
	public static function authorize_assets( $hook ) {
		if ( 'admin_page_' . self::PAGE !== $hook ) { return; }
		wp_enqueue_style( 'wpmcp-admin', WPMCP_PLUGIN_URL . 'assets/admin.css', array(), WPMCP_VERSION );
	}

	/** Other plugins' notices do not belong on the approval page. */
	public static function authorize_suppress_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'admin_page_' . self::PAGE !== $screen->id ) { return; }
		foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) { remove_all_actions( $hook ); }
	}

	/**
	 * Validate an authorization request. A bad client_id or redirect_uri is never
	 * redirected to: the result has no redirect_uri so the page shows the error.
	 *
	 * @return array{error?:string,message?:string,client?:array,redirect_uri?:string,client_id?:string,state?:string,challenge?:string}
	 */
	public static function check_authorize_params( $q ) {
		$client_id = isset( $q['client_id'] ) && is_string( $q['client_id'] ) ? $q['client_id'] : '';
		$clients   = self::clients();
		if ( ! isset( $clients[ $client_id ] ) ) { return array( 'fatal' => 'This app is not registered with the site. Remove the connector and add it again.' ); }
		$redirect = isset( $q['redirect_uri'] ) && is_string( $q['redirect_uri'] ) ? $q['redirect_uri'] : '';
		if ( ! in_array( $redirect, $clients[ $client_id ]['redirect_uris'], true ) ) { return array( 'fatal' => 'The return address does not match the one this app registered.' ); }
		$out = array( 'client' => $clients[ $client_id ], 'client_id' => $client_id, 'redirect_uri' => $redirect, 'state' => isset( $q['state'] ) && is_string( $q['state'] ) ? substr( $q['state'], 0, 500 ) : '' );
		if ( ! isset( $q['response_type'] ) || 'code' !== $q['response_type'] ) { return $out + array( 'error' => 'unsupported_response_type', 'message' => 'Only response_type=code is supported.' ); }
		$challenge = isset( $q['code_challenge'] ) && is_string( $q['code_challenge'] ) ? $q['code_challenge'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) || ! isset( $q['code_challenge_method'] ) || 'S256' !== $q['code_challenge_method'] ) {
			return $out + array( 'error' => 'invalid_request', 'message' => 'PKCE with code_challenge_method=S256 is required.' );
		}
		if ( ! empty( $q['resource'] ) && ( ! is_string( $q['resource'] ) || wp_parse_url( $q['resource'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return $out + array( 'error' => 'invalid_target', 'message' => 'The requested resource is not this site.' );
		}
		$out['challenge'] = $challenge;
		return $out;
	}

	/** True when the return address belongs to an app people commonly connect from. */
	public static function is_known_redirect( $uri ) {
		$parts = wp_parse_url( (string) $uri );
		if ( ! $parts || empty( $parts['scheme'] ) ) { return false; }
		if ( in_array( strtolower( $parts['scheme'] ), array( 'cursor', 'vscode', 'vscode-insiders' ), true ) ) { return true; }
		return ! empty( $parts['host'] ) && self::is_known_host( $parts['host'] );
	}

	/** Hosts of AI apps people commonly connect from, plus this machine for local tools. */
	public static function is_known_host( $host ) {
		$host = strtolower( $host );
		if ( in_array( $host, array( '127.0.0.1', 'localhost', '[::1]', '::1' ), true ) ) { return true; }
		foreach ( (array) apply_filters( 'wpmcp_known_oauth_hosts', array( 'claude.ai', 'claude.com', 'anthropic.com', 'chatgpt.com', 'openai.com', 'cursor.com', 'cursor.sh', 'vscode.dev', 'perplexity.ai', 'mistral.ai' ) ) as $known ) {
			if ( $host === $known || ( strlen( $host ) > strlen( $known ) && '.' . $known === substr( $host, -strlen( $known ) - 1 ) ) ) { return true; }
		}
		return false;
	}

	/** Build the URL the browser returns to, adding params to any existing query. */
	public static function redirect_with( $uri, $params ) {
		$params['iss'] = self::issuer();
		return $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . http_build_query( array_filter( $params, 'strlen' ), '', '&', PHP_QUERY_RFC3986 );
	}

	/** Store the validated request so the consent form cannot be tampered with. */
	public static function create_auth_request( $checked, $user_id ) {
		$id = self::random( 16 );
		set_transient( 'wpmcp_authreq_' . $id, array(
			'client_id' => $checked['client_id'], 'redirect_uri' => $checked['redirect_uri'], 'state' => $checked['state'],
			'challenge' => $checked['challenge'], 'user_id' => (int) $user_id,
		), self::REQUEST_TTL );
		return $id;
	}

	/** Turn an approved request into a one-time code and the redirect URL. */
	public static function approve( $request_id, $user_id, $level = 'edit' ) {
		$req = get_transient( 'wpmcp_authreq_' . $request_id );
		delete_transient( 'wpmcp_authreq_' . $request_id );
		if ( ! is_array( $req ) || (int) $req['user_id'] !== (int) $user_id ) { return false; }
		$code = self::random( 24 );
		set_transient( 'wpmcp_code_' . self::digest( $code ), array(
			'client_id' => $req['client_id'], 'redirect_uri' => $req['redirect_uri'], 'user_id' => (int) $user_id, 'challenge' => $req['challenge'],
			'level' => WPMCP_Permissions::valid( $level ) ? $level : 'edit',
		), self::CODE_TTL );
		return self::redirect_with( $req['redirect_uri'], array( 'code' => $code, 'state' => $req['state'] ) );
	}

	public static function deny( $request_id, $user_id ) {
		$req = get_transient( 'wpmcp_authreq_' . $request_id );
		delete_transient( 'wpmcp_authreq_' . $request_id );
		if ( ! is_array( $req ) || (int) $req['user_id'] !== (int) $user_id ) { return false; }
		return self::redirect_with( $req['redirect_uri'], array( 'error' => 'access_denied', 'state' => $req['state'] ) );
	}

	public static function handle_decision() {
		if ( ! isset( $_GET['page'], $_POST['wpmcp_decision'], $_POST['wpmcp_request'] ) || self::PAGE !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { return; }
		check_admin_referer( 'wpmcp_authorize' );
		$id      = sanitize_key( wp_unslash( $_POST['wpmcp_request'] ) );
		$approve = 'approve' === $_POST['wpmcp_decision'];
		$level   = isset( $_POST['wpmcp_level'] ) ? sanitize_key( wp_unslash( $_POST['wpmcp_level'] ) ) : 'edit';
		$url     = $approve ? self::approve( $id, get_current_user_id(), $level ) : self::deny( $id, get_current_user_id() );
		if ( ! $url ) { wp_die( 'This connection request expired. Start the connection again from your AI app.', 'Request expired', array( 'response' => 400 ) ); }
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- redirect_uri was matched against the client's registered list.
		exit;
	}

	/** Page frame: brand header, then the card. Close with </div>. */
	private static function authorize_open() {
		echo '<div class="wrap wpmcp wpmcp-consent"><header class="wpmcp-header"><div class="wpmcp-brand"><img class="wpmcp-logo" src="' . esc_url( WPMCP_PLUGIN_URL . 'assets/brand/wpmcp-icon.png' ) . '" width="254" height="36" alt="" /><div><h1>Connect an AI app</h1><p>' . esc_html( get_bloginfo( 'name' ) ) . '</p></div></div></header>';
	}

	private static function authorize_notice( $type, $html ) {
		echo '<div class="notice inline wpmcp-notice notice-' . esc_attr( $type ) . '"><p>' . $html . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers pass escaped markup.
	}

	public static function render_authorize() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$checked = self::check_authorize_params( wp_unslash( $_GET ) );
		$ui      = new WPMCP_Admin();
		self::authorize_open();
		if ( ! self::enabled() ) { self::authorize_notice( 'error', 'OAuth sign-in is turned off for this site.' ); echo '</div>'; return; }
		if ( isset( $checked['fatal'] ) ) { self::authorize_notice( 'error', esc_html( $checked['fatal'] ) ); echo '</div>'; return; }
		if ( isset( $checked['error'] ) ) {
			self::authorize_notice( 'error', esc_html( $checked['message'] ) );
			echo '<p><a class="button" href="' . esc_url( self::redirect_with( $checked['redirect_uri'], array( 'error' => $checked['error'], 'state' => $checked['state'] ) ) ) . '">Return to the app</a></p></div>';
			return;
		}
		$user    = wp_get_current_user();
		$request = self::create_auth_request( $checked, $user->ID );
		$host    = wp_parse_url( $checked['redirect_uri'], PHP_URL_HOST );
		$icons   = array( 'read' => 'eye', 'edit' => 'tools', 'full' => 'key' );
		?>
		<section class="wpmcp-panel wpmcp-consent-card">
			<div class="wpmcp-panel-head">
				<h2><?php echo $ui->icon( 'connect' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static icon map. ?><span><?php echo esc_html( $checked['client']['name'] ); ?> wants to connect</span></h2>
				<p>It will act as <strong><?php echo esc_html( $user->display_name ); ?></strong>. You can change its access later in WP MCP.</p>
			</div>
			<?php if ( ! self::is_known_redirect( $checked['redirect_uri'] ) ) : ?>
				<div class="notice inline wpmcp-notice notice-warning"><p><strong>Unrecognized app.</strong> It will send you to <code><?php echo esc_html( $host ? $host : $checked['redirect_uri'] ); ?></code>, which is not a well-known AI service. Anyone can register an app under any name, so approve only if you started this connection yourself.</p></div>
			<?php else : ?>
				<p class="wpmcp-consent-note"><?php echo $ui->icon( 'shield', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput -- static icon map. ?><span>The app returns you to <code><?php echo esc_html( $host ? $host : wp_parse_url( $checked['redirect_uri'], PHP_URL_SCHEME ) . '://' ); ?></code>. The name above is chosen by the app itself. Approve only if you just started this connection.</span></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ); ?>">
				<?php wp_nonce_field( 'wpmcp_authorize' ); ?>
				<input type="hidden" name="wpmcp_request" value="<?php echo esc_attr( $request ); ?>" />
				<fieldset class="wpmcp-levels"><legend>What may it do?</legend>
				<?php foreach ( WPMCP_Permissions::levels() as $level_key => $info ) : ?>
					<label class="wpmcp-level"><input type="radio" name="wpmcp_level" value="<?php echo esc_attr( $level_key ); ?>"<?php echo WPMCP_Permissions::DEFAULT_APP === $level_key ? ' checked' : ''; ?> />
						<span class="wpmcp-level-icon"><?php echo $ui->icon( isset( $icons[ $level_key ] ) ? $icons[ $level_key ] : 'shield', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput -- static icon map. ?></span>
						<span class="wpmcp-level-text"><strong><?php echo esc_html( $info[0] ); ?></strong><small><?php echo esc_html( $info[1] ); ?></small></span>
						<span class="wpmcp-level-dot" aria-hidden="true"></span></label>
				<?php endforeach; ?>
				</fieldset>
				<div class="wpmcp-actions">
					<button class="button button-primary button-hero" name="wpmcp_decision" value="approve">Approve</button>
					<button class="button button-hero" name="wpmcp_decision" value="deny">Cancel</button>
				</div>
			</form>
		</section></div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Token endpoint                                                    */
	/* ----------------------------------------------------------------- */

	private static function pkce_matches( $verifier, $challenge ) {
		if ( ! is_string( $verifier ) || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) { return false; }
		return hash_equals( $challenge, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
	}

	public static function do_token( $p ) {
		if ( ! self::enabled() ) { return self::oauth_error( 403, 'access_denied', 'OAuth is disabled on this site.' ); }
		if ( self::throttled() ) { return self::oauth_error( 429, 'temporarily_unavailable', 'Too many failed attempts. Try again later.' ); }
		$client_id = isset( $p['client_id'] ) && is_string( $p['client_id'] ) ? $p['client_id'] : '';
		$clients   = self::clients();
		if ( ! isset( $clients[ $client_id ] ) ) { self::note_failure(); return self::oauth_error( 401, 'invalid_client', 'Unknown client. Register again.' ); }
		$type = isset( $p['grant_type'] ) && is_string( $p['grant_type'] ) ? $p['grant_type'] : '';

		if ( 'authorization_code' === $type ) {
			$code = isset( $p['code'] ) && is_string( $p['code'] ) ? $p['code'] : '';
			$key  = 'wpmcp_code_' . self::digest( $code );
			$data = get_transient( $key );
			delete_transient( $key ); // One use, even if the rest fails.
			if ( ! is_array( $data ) || $data['client_id'] !== $client_id
				|| ! isset( $p['redirect_uri'] ) || ! is_string( $p['redirect_uri'] ) || $data['redirect_uri'] !== $p['redirect_uri']
				|| ! self::pkce_matches( isset( $p['code_verifier'] ) ? $p['code_verifier'] : '', $data['challenge'] ) ) {
				self::note_failure();
				return self::oauth_error( 400, 'invalid_grant', 'The code is invalid, expired, or does not match the request.' );
			}
			$grant_id = self::random( 12 );
			return array( 200, self::issue_tokens( $grant_id, $client_id, $clients[ $client_id ]['name'], (int) $data['user_id'], true, isset( $data['level'] ) ? $data['level'] : 'edit' ) );
		}

		if ( 'refresh_token' === $type ) {
			$refresh = isset( $p['refresh_token'] ) && is_string( $p['refresh_token'] ) ? $p['refresh_token'] : '';
			$rt_key  = 'wpmcp_rt_' . self::digest( $refresh );
			$grant_id = get_option( $rt_key, '' );
			$grants  = self::grants();
			$grant   = is_string( $grant_id ) && isset( $grants[ $grant_id ] ) ? $grants[ $grant_id ] : null;
			if ( ! $grant || $grant['client_id'] !== $client_id || $grant['last_used'] < time() - self::REFRESH_IDLE ) {
				self::note_failure();
				return self::oauth_error( 400, 'invalid_grant', 'The refresh token is invalid or expired. Connect again.' );
			}
			delete_option( $rt_key );
			return array( 200, self::issue_tokens( $grant_id, $client_id, $grant['client_name'], (int) $grant['user_id'], false ) );
		}

		return self::oauth_error( 400, 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	/** Create (or rotate) tokens for a grant. */
	private static function issue_tokens( $grant_id, $client_id, $client_name, $user_id, $new, $level = 'full' ) {
		$grants  = self::grants();
		$access  = 'wpmcp_at_' . self::random( 24 );
		$refresh = 'wpmcp_rt_' . self::random( 24 );
		set_transient( 'wpmcp_at_' . self::digest( $access ), array( 'grant' => $grant_id, 'user_id' => $user_id ), self::ACCESS_TTL );
		update_option( 'wpmcp_rt_' . self::digest( $refresh ), $grant_id, false );
		$grants[ $grant_id ] = array(
			'client_id'    => $client_id,
			'client_name'  => $client_name,
			'user_id'      => $user_id,
			'created'      => $new ? time() : $grants[ $grant_id ]['created'],
			'last_used'    => time(),
			'level'        => $new ? WPMCP_Permissions::normalize( $level ) : ( isset( $grants[ $grant_id ]['level'] ) ? $grants[ $grant_id ]['level'] : 'full' ),
			'refresh_key'  => 'wpmcp_rt_' . self::digest( $refresh ),
		);
		self::save_grants( $grants );
		return array( 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => self::SCOPE );
	}

	/* ----------------------------------------------------------------- */
	/* Using and revoking tokens                                         */
	/* ----------------------------------------------------------------- */

	/** @return array|false {user_id, grant} when the access token is live and its grant not revoked. */
	public static function validate_access_token( $token ) {
		if ( ! self::enabled() ) { return false; }
		$data = get_transient( 'wpmcp_at_' . self::digest( $token ) );
		if ( ! is_array( $data ) ) { return false; }
		$grants = self::grants();
		if ( ! isset( $grants[ $data['grant'] ] ) ) { return false; }
		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) { return false; }
		if ( $grants[ $data['grant'] ]['last_used'] < time() - 600 ) {
			$grants[ $data['grant'] ]['last_used'] = time();
			self::save_grants( $grants );
		}
		return array( 'user_id' => (int) $user->ID, 'grant' => $data['grant'], 'name' => (string) $grants[ $data['grant'] ]['client_name'], 'level' => WPMCP_Permissions::normalize( isset( $grants[ $data['grant'] ]['level'] ) ? $grants[ $data['grant'] ]['level'] : 'full' ) );
	}

	public static function revoke_token( $token ) {
		$grants = self::grants();
		$id     = '';
		if ( 0 === strpos( $token, 'wpmcp_rt_' ) ) { $id = (string) get_option( 'wpmcp_rt_' . self::digest( $token ), '' ); }
		elseif ( 0 === strpos( $token, 'wpmcp_at_' ) ) { $d = get_transient( 'wpmcp_at_' . self::digest( $token ) ); $id = is_array( $d ) ? (string) $d['grant'] : ''; }
		if ( '' !== $id && isset( $grants[ $id ] ) ) { self::revoke_grant( $id ); }
	}

	/** Connected apps, newest first, for the admin screen. */
	public static function list_grants() {
		$grants = self::grants();
		uasort( $grants, function ( $a, $b ) { return $b['last_used'] <=> $a['last_used']; } );
		return $grants;
	}

	/** Change what a connected app may do. Takes effect on its next request. */
	public static function set_grant_level( $grant_id, $level ) {
		$grants = self::grants();
		if ( ! isset( $grants[ $grant_id ] ) || ! WPMCP_Permissions::valid( $level ) ) { return false; }
		$grants[ $grant_id ]['level'] = $level;
		self::save_grants( $grants );
		return true;
	}

	public static function revoke_grant( $grant_id ) {
		$grants = self::grants();
		if ( ! isset( $grants[ $grant_id ] ) ) { return false; }
		if ( ! empty( $grants[ $grant_id ]['refresh_key'] ) ) { delete_option( $grants[ $grant_id ]['refresh_key'] ); }
		unset( $grants[ $grant_id ] );
		self::save_grants( $grants ); // Access tokens die at once: they require a live grant.
		return true;
	}
}
