<?php
/**
 * Embedded MCP server for WP MCP.
 *
 * Exposes a remote MCP endpoint (Streamable HTTP, JSON responses) directly from
 * WordPress, so an MCP client (Claude on the web / Cowork / Desktop, Claude Code,
 * Gemini, etc.) can connect to the site by URL with NO local software running.
 *
 * Endpoint:  {site}/wp-json/wpmcp/v1/mcp            (key via header)
 *            {site}/wp-json/wpmcp/v1/mcp/{API_KEY}  (key in the path)
 *
 * Protocol support (v2.0.0):
 *   - 2026-07-28  stateless revision (server/discover, resultType, ttlMs/cacheScope,
 *                 per-request _meta, Mcp-Method / Mcp-Name headers)
 *   - 2025-11-25  (Claude's current maximum)
 *   - 2025-06-18 / 2025-03-26 legacy clients
 *
 * Version is negotiated properly instead of echoing whatever the client sent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_MCP {

	/** Newest revision we actually implement. */
	const LATEST_PROTOCOL = '2026-07-28';

	/**
	 * What we answer with when the client asks for something we do not know.
	 * Kept at 2025-11-25 because that is the newest revision Claude's connector
	 * infrastructure currently negotiates.
	 */
	const PREFERRED_PROTOCOL = '2025-11-25';

	/** Newest first. */
	const SUPPORTED_PROTOCOLS = array(
		'2026-07-28',
		'2025-11-25',
		'2025-06-18',
		'2025-03-26',
	);

	/** Protocol revision negotiated for the request currently being handled. */
	private $proto = self::PREFERRED_PROTOCOL;

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns = WPMCP_NAMESPACE;

		$post = array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'authorize' ),
		);
		$get = array(
			// Clients probe GET for an SSE stream; we run in JSON mode only.
			'methods'             => 'GET',
			'callback'            => array( $this, 'no_sse' ),
			'permission_callback' => array( $this, 'authorize' ),
		);
		$delete = array(
			// Session termination. We are stateless, so this is always a no-op OK.
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'end_session' ),
			'permission_callback' => array( $this, 'authorize' ),
		);

		// Key in the URL path (backwards compatible with v1.x connector URLs).
		register_rest_route( $ns, '/mcp/(?P<key>[A-Za-z0-9_\-]+)', array( $post, $get, $delete ) );

		// Clean URL: key travels in the x-api-key / Authorization header instead.
		register_rest_route( $ns, '/mcp', array( $post, $get, $delete ) );
	}

	/* ----------------------------------------------------------------- */
	/* Transport security + auth                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Authorize the request.
	 *
	 * Accepts the key from the URL path, the x-api-key header, the X-WPMCP-Key
	 * header, or an Authorization: Bearer header. Also enforces the Origin
	 * check the 2025-11-25 revision requires for Streamable HTTP.
	 */
	public function authorize( $request ) {
		$origin_check = $this->check_origin( $request );
		if ( is_wp_error( $origin_check ) ) {
			return $origin_check;
		}

		$proto_check = $this->check_protocol_header( $request );
		if ( is_wp_error( $proto_check ) ) {
			return $proto_check;
		}

		$result = WPMCP_Auth::check( $request );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'wpmcp_rate_limited', 'wpmcp_missing_key', 'wpmcp_invalid_token' ), true ) ) {
			$result->add_data( array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * DNS-rebinding protection. Server-to-server clients (Claude) send no Origin,
	 * which is allowed. A browser Origin must match this site unless allow-listed.
	 *
	 * @return true|WP_Error
	 */
	private function check_origin( $request ) {
		$origin = $request->get_header( 'origin' );
		if ( empty( $origin ) ) {
			return true; // No Origin header: not a browser request.
		}

		$allowed = apply_filters( 'wpmcp_allowed_origins', array( home_url(), site_url() ) );
		$host    = wp_parse_url( $origin, PHP_URL_HOST );
		foreach ( (array) $allowed as $candidate ) {
			if ( $host && $host === wp_parse_url( $candidate, PHP_URL_HOST ) ) {
				return true;
			}
		}

		return new WP_Error( 'wpmcp_bad_origin', 'Origin not allowed.', array( 'status' => 403 ) );
	}

	/**
	 * The MCP-Protocol-Version header (2025-06-18+) must name a revision we speak.
	 *
	 * @return true|WP_Error
	 */
	private function check_protocol_header( $request ) {
		$header = $request->get_header( 'mcp_protocol_version' );
		if ( empty( $header ) ) {
			$header = $request->get_header( 'MCP-Protocol-Version' );
		}
		if ( empty( $header ) ) {
			return true;
		}
		if ( ! in_array( (string) $header, self::SUPPORTED_PROTOCOLS, true ) ) {
			return new WP_Error(
				'wpmcp_bad_protocol',
				'Unsupported MCP-Protocol-Version: ' . $header . '. Supported: ' . implode( ', ', self::SUPPORTED_PROTOCOLS ) . '.',
				array( 'status' => 400 )
			);
		}
		return true;
	}

	public function no_sse() {
		return new WP_Error( 'wpmcp_no_sse', 'This MCP endpoint uses JSON responses (POST only).', array( 'status' => 405 ) );
	}

	public function end_session() {
		// Stateless server: nothing to tear down.
		return new WP_REST_Response( null, 204 );
	}

	/* ----------------------------------------------------------------- */
	/* Protocol negotiation                                               */
	/* ----------------------------------------------------------------- */

	/**
	 * Pick the revision to speak for this request.
	 */
	private function negotiate( $request, $body ) {
		$asked = '';

		$header = $request->get_header( 'mcp_protocol_version' );
		if ( empty( $header ) ) {
			$header = $request->get_header( 'MCP-Protocol-Version' );
		}
		if ( ! empty( $header ) ) {
			$asked = (string) $header;
		}

		// 2026-07-28 is stateless: the revision rides in _meta on every request.
		if ( '' === $asked && is_array( $body ) ) {
			$messages = isset( $body['method'] ) ? array( $body ) : $body;
			foreach ( (array) $messages as $msg ) {
				if ( ! is_array( $msg ) ) {
					continue;
				}
				$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
				if ( isset( $params['protocolVersion'] ) ) {
					$asked = (string) $params['protocolVersion'];
					break;
				}
				if ( isset( $params['_meta'] ) && is_array( $params['_meta'] ) ) {
					foreach ( $params['_meta'] as $mk => $mv ) {
						if ( is_string( $mv ) && false !== stripos( (string) $mk, 'protocol' ) ) {
							$asked = $mv;
							break 2;
						}
					}
				}
			}
		}

		$this->proto = in_array( $asked, self::SUPPORTED_PROTOCOLS, true ) ? $asked : self::PREFERRED_PROTOCOL;
		return $this->proto;
	}

	/** True when the negotiated revision is the stateless 2026-07-28 one or newer. */
	private function is_stateless_rev() {
		return version_compare( str_replace( '-', '.', $this->proto ), '2026.07.28', '>=' );
	}

	/* ----------------------------------------------------------------- */
	/* Request handling                                                   */
	/* ----------------------------------------------------------------- */

	public function handle( $request ) {
		$body = $request->get_json_params();
		if ( null === $body ) {
			$raw  = $request->get_body();
			$body = json_decode( $raw, true );
		}

		if ( ! is_array( $body ) ) {
			return $this->rpc_error_response( null, -32700, 'Parse error: invalid JSON.' );
		}

		$this->negotiate( $request, $body );

		// Batching was removed from the spec in 2025-06-18; still accepted for
		// older clients that send it.
		$is_batch = array_keys( $body ) === range( 0, count( $body ) - 1 ) && ( isset( $body[0] ) && is_array( $body[0] ) );
		$messages = $is_batch ? $body : array( $body );

		$responses = array();
		foreach ( $messages as $msg ) {
			$res = $this->dispatch( $msg );
			if ( null !== $res ) {
				$responses[] = $res;
			}
		}

		// Only notifications -> 202 Accepted, no body.
		if ( empty( $responses ) ) {
			$empty = new WP_REST_Response( null, 202 );
			$empty->header( 'MCP-Protocol-Version', $this->proto );
			return $empty;
		}

		$payload  = $is_batch ? $responses : $responses[0];
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'MCP-Protocol-Version', $this->proto );
		return $response;
	}

	/**
	 * Dispatch a single JSON-RPC message. Returns a response array, or null for
	 * notifications (no id).
	 */
	private function dispatch( $msg ) {
		if ( ! is_array( $msg ) || ! isset( $msg['method'] ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request.' );
		}

		$method = (string) $msg['method'];
		$id     = isset( $msg['id'] ) ? $msg['id'] : null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		$is_notification = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result( $id, array(
					'protocolVersion' => $this->proto,
					'capabilities'    => array(
						'tools' => array( 'listChanged' => false ),
					),
					'serverInfo'      => $this->server_info(),
					'instructions'    => $this->instructions(),
				) );

			// 2026-07-28 stateless discovery.
			case 'server/discover':
				return $this->rpc_result( $id, array(
					'protocolVersions' => self::SUPPORTED_PROTOCOLS,
					'capabilities'     => array(
						'tools' => array( 'listChanged' => false ),
					),
					'serverInfo'       => $this->server_info(),
					'instructions'     => $this->instructions(),
				) );

			case 'tools/list':
				$result = array( 'tools' => WPMCP_Permissions::filter( self::tools_spec(), WPMCP_Auth::$level ) );
				if ( $this->is_stateless_rev() ) {
					$result['ttlMs']      = 300000;
					$result['cacheScope'] = 'private';
				}
				return $this->rpc_result( $id, $result );

			case 'tools/call':
				return $this->handle_tool_call( $id, $params );

			case 'ping':
				return $this->rpc_result( $id, (object) array() );

			case 'prompts/list':
				return $this->rpc_result( $id, array( 'prompts' => array() ) );

			case 'resources/list':
				return $this->rpc_result( $id, array( 'resources' => array() ) );

			case 'resources/templates/list':
				return $this->rpc_result( $id, array( 'resourceTemplates' => array() ) );

			default:
				if ( 0 === strpos( $method, 'notifications/' ) || $is_notification ) {
					return null; // Accept and ignore notifications.
				}
				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private function server_info() {
		return array(
			'name'        => 'wp-mcp',
			'title'       => 'WordPress: ' . get_bloginfo( 'name' ),
			'version'     => WPMCP_VERSION,
			'description' => 'Manage pages, posts, media, taxonomies and Elementor layouts on ' . home_url() . '.',
			'websiteUrl'  => home_url(),
		);
	}

	private function instructions() {
		return "This server controls one WordPress site.\n"
			. "Before editing code: read the existing file, preserve literal PHP tags (never HTML-encode source), run wp_edit_extension_file with dry_run=true, then apply one change at a time using the current hash. Save the returned backup_id, run wp_ping, and check the affected page. Backups can be listed and restored while WordPress still works. Stop issuing edits if responses become malformed; bootstrap failures require hosting backup, SFTP, or file-manager recovery. Never claim this connector can repair every outage.\n"
			. "Call wp_ping first: it reports the Elementor version and editor generation (v3 classic vs v4 atomic), which decides the JSON shape wp_set_elementor expects.\n"
			. "Every change you make is recorded. If the user asks to undo something, call wp_list_history to find it and wp_rollback to reverse it; if wp_rollback reports the item was edited since, tell the user before using force.\n"
			. "Check a page's \"builder\" (from wp_get_content or wp_ping) before editing its layout: gutenberg pages use wp_get_blocks and wp_set_blocks (block markup, checked before saving), elementor pages use wp_get_elementor and wp_set_elementor, divi pages use wp_get_divi and wp_set_divi, and classic pages use wp_update_content. Do not mix editors on one page. For Advanced Custom Fields call wp_acf_list first: create post types and taxonomies with wp_acf_save_post_type and wp_acf_save_taxonomy, fields with wp_acf_save_field_group, and set values with wp_acf_set_values or the \"acf\" argument of wp_create_content (never write ACF values as plain meta). After changing layouts, settings or plugins, call wp_clear_cache if the front end does not show the change.\n"
			. "Elementor layouts can be very large. Read them with wp_get_elementor using summary=true first, then fetch one section at a time with index=N. Write them back section by section using the __append__ or __replace__ markers rather than resending the whole tree.";
	}

	/**
	 * Execute a tool call against WPMCP_Core.
	 */
	private function handle_tool_call( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( ! WPMCP_Permissions::allows( WPMCP_Auth::$level, $name ) ) {
			return $this->rpc_result( $id, $this->tool_error( WPMCP_Permissions::refusal( WPMCP_Auth::$level, $name ) ) );
		}

		$result = $this->run_tool( $name, $args );

		if ( is_wp_error( $result ) ) {
			return $this->rpc_result( $id, $this->tool_error( $result->get_error_message() ) );
		}

		return $this->rpc_result( $id, $this->tool_success( $name, $result ) );
	}

	/**
	 * Build a successful tool result, guarding the host's tool-result size cap.
	 * Claude.ai / Desktop truncate at roughly 150,000 characters, and a single
	 * Elementor page can easily exceed that.
	 */
	private function tool_success( $name, $result ) {
		$limit = (int) apply_filters( 'wpmcp_max_result_chars', WPMCP_MAX_RESULT_CHARS );

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$text  = wp_json_encode( $result, $flags | JSON_PRETTY_PRINT );

		// Pretty printing costs 30-40% on deep Elementor trees; drop it when big.
		if ( strlen( $text ) > 20000 ) {
			$text = wp_json_encode( $result, $flags );
		}

		if ( strlen( $text ) > $limit ) {
			return $this->tool_error( sprintf(
				'The result is %s characters, over this client\'s ~%s character limit, so it was not returned. Ask for less: %s',
				number_format( strlen( $text ) ),
				number_format( $limit ),
				$this->narrowing_hint( $name )
			) );
		}

		$payload = array(
			'content' => array( array( 'type' => 'text', 'text' => $text ) ),
		);

		// Structured output (2025-06-18+). Only when small, so the two copies
		// together stay inside the host's cap.
		if ( strlen( $text ) < 40000 && is_array( $result ) ) {
			$payload['structuredContent'] = $result;
		}

		if ( $this->is_stateless_rev() ) {
			$payload['resultType'] = 'complete';
		}

		return $payload;
	}

	private function narrowing_hint( $name ) {
		switch ( $name ) {
			case 'wp_get_elementor':
				return 'call wp_get_elementor again with summary=true to see the section outline, then with index=N (and optionally depth=N) for one section at a time.';
			case 'wp_get_content':
				return 'call wp_get_content with include_elementor=false, then use wp_get_elementor with summary=true for the layout.';
			case 'wp_get_blocks':
				return 'call wp_get_blocks with summary=true for the outline, then index=N for one top-level block.';
			case 'wp_get_divi':
				return 'call wp_get_divi with summary=true for the outline, then index=N for one section.';
			case 'wp_list_content':
			case 'wp_list_media':
				return 'lower per_page and page through the results.';
			default:
				return 'request fewer or smaller items.';
		}
	}

	private function tool_error( $message ) {
		$payload = array(
			'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $message ) ),
			'isError' => true,
		);
		if ( $this->is_stateless_rev() ) {
			$payload['resultType'] = 'complete';
		}
		return $payload;
	}

	/**
	 * Map a tool name + args to a WPMCP_Core operation.
	 *
	 * @return array|WP_Error
	 */
	private function run_tool( $name, $args ) {
		if ( in_array( $name, WPMCP_Extensions::tool_names(), true ) ) {
			return WPMCP_Extensions::run( $name, $args );
		}
		switch ( $name ) {
			case 'wp_ping':
				return WPMCP_Core::ping();

			case 'wp_list_post_types':
				return WPMCP_Core::list_post_types();

			case 'wp_list_content':
				if ( empty( $args['type'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "type".' );
				}
				return WPMCP_Core::list_content( $args['type'], $args );

			case 'wp_get_content':
				if ( empty( $args['type'] ) || ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "type" or "id".' );
				}
				$include_elementor = ! array_key_exists( 'include_elementor', $args ) || ! empty( $args['include_elementor'] );
				return WPMCP_Core::get_content( $args['type'], (int) $args['id'], $include_elementor );

			case 'wp_create_content':
				if ( empty( $args['type'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "type".' );
				}
				$body = $args;
				unset( $body['type'] );
				return WPMCP_Core::create_content( $args['type'], $body );

			case 'wp_update_content':
				if ( empty( $args['type'] ) || ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "type" or "id".' );
				}
				$body = $args;
				unset( $body['type'], $body['id'] );
				return WPMCP_Core::update_content( $args['type'], (int) $args['id'], $body );

			case 'wp_delete_content':
				if ( empty( $args['type'] ) || ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "type" or "id".' );
				}
				return WPMCP_Core::delete_content( $args['type'], (int) $args['id'], ! empty( $args['force'] ) );

			case 'wp_get_elementor':
				if ( ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );
				}
				return WPMCP_Core::get_elementor( (int) $args['id'], $args );

			case 'wp_set_elementor':
				if ( ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );
				}
				if ( ! isset( $args['elements'] ) && ! isset( $args['page_settings'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Provide "elements", "page_settings", or both.' );
				}
				return WPMCP_Core::set_elementor(
					(int) $args['id'],
					isset( $args['elements'] ) ? $args['elements'] : null,
					isset( $args['page_settings'] ) ? $args['page_settings'] : null
				);

			case 'wp_upload_media':
				return WPMCP_Core::upload_media( $args );

			case 'wp_list_media':
				return WPMCP_Core::list_media( $args );

			case 'wp_list_terms':
				if ( empty( $args['taxonomy'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "taxonomy".' );
				}
				return WPMCP_Core::list_terms( $args['taxonomy'] );

			case 'wp_create_term':
				if ( empty( $args['taxonomy'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "taxonomy".' );
				}
				$body = $args;
				unset( $body['taxonomy'] );
				return WPMCP_Core::create_term( $args['taxonomy'], $body );

			case 'wp_list_block_types':
				return WPMCP_Builders::list_block_types( isset( $args['search'] ) ? (string) $args['search'] : '', isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 60 );

			case 'wp_get_blocks':
				return isset( $args['id'] ) ? WPMCP_Builders::get_blocks( (int) $args['id'], $args ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );

			case 'wp_set_blocks':
				return isset( $args['id'], $args['content'] ) ? WPMCP_Builders::set_blocks( (int) $args['id'], $args['content'], $args ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id" or "content".' );

			case 'wp_get_divi':
				return isset( $args['id'] ) ? WPMCP_Builders::get_divi( (int) $args['id'], $args ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );

			case 'wp_set_divi':
				return isset( $args['id'], $args['content'] ) ? WPMCP_Builders::set_divi( (int) $args['id'], $args['content'], $args ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id" or "content".' );

			case 'wp_clear_cache':
				return WPMCP_Site::clear_cache( isset( $args['targets'] ) ? (array) $args['targets'] : array() );

			case 'wp_get_settings':
				return WPMCP_Site::get_settings();

			case 'wp_update_settings':
				return WPMCP_Site::update_settings( isset( $args['settings'] ) ? $args['settings'] : null );

			case 'wp_acf_list':
				return WPMCP_ACF::list_items( isset( $args['what'] ) ? $args['what'] : '', isset( $args['key'] ) ? (string) $args['key'] : '', ! empty( $args['include_fields'] ) );

			case 'wp_acf_save_post_type':
				return WPMCP_ACF::save_post_type( $args );

			case 'wp_acf_save_taxonomy':
				return WPMCP_ACF::save_taxonomy( $args );

			case 'wp_acf_save_field_group':
				return WPMCP_ACF::save_field_group( $args );

			case 'wp_acf_delete':
				return isset( $args['kind'], $args['key'] ) ? WPMCP_ACF::delete_item( (string) $args['kind'], (string) $args['key'] ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "kind" or "key".' );

			case 'wp_acf_get_values':
				return isset( $args['id'] ) ? WPMCP_ACF::get_values( $args['id'], ! empty( $args['format'] ) ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );

			case 'wp_acf_set_values':
				return isset( $args['id'], $args['values'] ) ? WPMCP_ACF::set_values( $args['id'], $args['values'] ) : new WP_Error( 'wpmcp_missing_arg', 'Missing "id" or "values".' );

			case 'wp_list_history':
				$per_page = isset( $args['per_page'] ) ? max( 1, min( 50, (int) $args['per_page'] ) ) : 20;
				$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
				return WPMCP_History::listing( $page, $per_page );

			case 'wp_rollback':
				if ( ! isset( $args['id'] ) ) {
					return new WP_Error( 'wpmcp_missing_arg', 'Missing "id".' );
				}
				return WPMCP_History::rollback( (int) $args['id'], ! empty( $args['force'] ), false, WPMCP_Auth::$level );

			default:
				return new WP_Error( 'wpmcp_unknown_tool', 'Unknown tool: ' . $name );
		}
	}

	/* ----------------------------------------------------------------- */
	/* JSON-RPC helpers                                                  */
	/* ----------------------------------------------------------------- */

	private function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private function rpc_error_response( $id, $code, $message ) {
		return new WP_REST_Response( $this->rpc_error( $id, $code, $message ), 200 );
	}

	/* ----------------------------------------------------------------- */
	/* Tool specifications (JSON Schema 2020-12)                         */
	/* ----------------------------------------------------------------- */

	private static function obj( $properties, $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? new stdClass() : $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}
		return $schema;
	}

	/**
	 * Behaviour hints. Clients (and the Claude connector review) use these to
	 * decide what needs confirmation before running.
	 */
	private static function ann( $title, $read_only, $destructive = false, $idempotent = false ) {
		return array(
			'title'           => $title,
			'readOnlyHint'    => (bool) $read_only,
			'destructiveHint' => (bool) $destructive,
			'idempotentHint'  => (bool) $idempotent,
			'openWorldHint'   => false,
		);
	}

	private static function content_body_props() {
		return array(
			'title'          => array( 'type' => 'string', 'description' => 'Title of the item.' ),
			'content'        => array( 'type' => 'string', 'description' => 'Main content (HTML). For Elementor pages leave empty and use "elementor".' ),
			'excerpt'        => array( 'type' => 'string', 'description' => 'Optional excerpt.' ),
			'status'         => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'description' => 'Publish status (default draft on create).' ),
			'slug'           => array( 'type' => 'string', 'description' => 'URL slug.' ),
			'parent'         => array( 'type' => 'integer', 'description' => 'Parent post ID (hierarchical types).' ),
			'menu_order'     => array( 'type' => 'integer', 'description' => 'Sort position.' ),
			'featured_media' => array( 'type' => 'integer', 'description' => 'Attachment ID for featured image.' ),
			'meta'           => array( 'type' => 'object', 'description' => 'Custom fields as key/value.' ),
			'terms'          => array( 'type' => 'object', 'description' => 'Taxonomy terms, e.g. {"category":[3]}.' ),
			'acf'            => array( 'type' => 'object', 'description' => 'Advanced Custom Fields values by field name, saved through ACF, e.g. {"price":29,"author":"Ann"}. Needs ACF and a field group that applies to this item. Use this instead of meta for ACF fields.' ),
			'elementor'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Elementor page structure (the _elementor_data array).' ),
		);
	}

	public static function tools_spec() {
		return array_merge( self::content_tools_spec(), WPMCP_Extensions::tools_spec() );
	}

	private static function content_tools_spec() {
		$type_prop = array( 'type' => 'string', 'description' => 'Post type slug: "page", "post", or a custom post type.' );
		$id_prop   = array( 'type' => 'integer', 'description' => 'Numeric item ID.' );

		$create_props = array_merge( array( 'type' => $type_prop ), self::content_body_props() );
		$update_props = array_merge( array( 'type' => $type_prop, 'id' => $id_prop ), self::content_body_props() );

		return array(
			array(
				'name'        => 'wp_ping',
				'title'       => 'Check WordPress connection',
				'description' => 'Check the connection to this WordPress site. Returns site name, WP version, PHP version, Elementor version and editor generation (v3 classic or v4 atomic), and the post types. Call this first: the Elementor generation decides the JSON shape wp_set_elementor expects.',
				'inputSchema' => self::obj( array() ),
				'annotations' => self::ann( 'Check WordPress connection', true, false, true ),
			),
			array(
				'name'        => 'wp_list_post_types',
				'title'       => 'List post types',
				'description' => 'List all registered post types (page, post, custom post types) with labels and taxonomies.',
				'inputSchema' => self::obj( array() ),
				'annotations' => self::ann( 'List post types', true, false, true ),
			),
			array(
				'name'        => 'wp_list_content',
				'title'       => 'List pages or posts',
				'description' => 'List items of a post type (newest first) with pagination and search. Returns id, title, slug, status, link.',
				'inputSchema' => self::obj( array(
					'type'     => $type_prop,
					'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 20).' ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
					'status'   => array( 'type' => 'string', 'description' => 'Filter by status; default "any".' ),
					'search'   => array( 'type' => 'string', 'description' => 'Search term.' ),
				), array( 'type' ) ),
				'annotations' => self::ann( 'List pages or posts', true, false, true ),
			),
			array(
				'name'        => 'wp_get_content',
				'title'       => 'Get a page or post',
				'description' => 'Get a single item by type and id, including content and meta. Elementor data is included by default; set include_elementor=false on large builder pages and read the layout with wp_get_elementor instead.',
				'inputSchema' => self::obj( array(
					'type'              => $type_prop,
					'id'                => $id_prop,
					'include_elementor' => array( 'type' => 'boolean', 'description' => 'Include the full Elementor tree (default true). Set false for large pages.' ),
				), array( 'type', 'id' ) ),
				'annotations' => self::ann( 'Get a page or post', true, false, true ),
			),
			array(
				'name'        => 'wp_create_content',
				'title'       => 'Create a page or post',
				'description' => 'Create a page, post or custom post type item. Supports title, content, status, slug, parent, meta, terms, featured image and Elementor JSON in one call. Defaults to draft.',
				'inputSchema' => self::obj( $create_props, array( 'type' ) ),
				'annotations' => self::ann( 'Create a page or post', false, false, false ),
			),
			array(
				'name'        => 'wp_update_content',
				'title'       => 'Update a page or post',
				'description' => 'Update an item by type and id. Only provided fields change. Same fields as create.',
				'inputSchema' => self::obj( $update_props, array( 'type', 'id' ) ),
				'annotations' => self::ann( 'Update a page or post', false, false, true ),
			),
			array(
				'name'        => 'wp_delete_content',
				'title'       => 'Delete a page or post',
				'description' => 'Delete an item by type and id. Moves it to Trash unless force=true, which deletes it permanently and cannot be undone.',
				'inputSchema' => self::obj( array(
					'type'  => $type_prop,
					'id'    => $id_prop,
					'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of Trash. Irreversible.' ),
				), array( 'type', 'id' ) ),
				'annotations' => self::ann( 'Delete a page or post', false, true, true ),
			),
			array(
				'name'        => 'wp_get_elementor',
				'title'       => 'Read an Elementor layout',
				'description' => 'Read the Elementor structure (_elementor_data) and page settings for a page/post. Elementor trees are large, so start with summary=true for a section outline, then pass index=N to fetch one top-level section in full, or depth=N to cut the tree off below a level.',
				'inputSchema' => self::obj( array(
					'id'      => $id_prop,
					'summary' => array( 'type' => 'boolean', 'description' => 'Return only an outline (element type, widget type, id, child counts) instead of full settings. Use this first on any page you have not seen.' ),
					'index'   => array( 'type' => 'integer', 'description' => 'Zero-based index of a single top-level section to return in full.' ),
					'depth'   => array( 'type' => 'integer', 'description' => 'Maximum tree depth to return; deeper children are replaced with a count placeholder.' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Read an Elementor layout', true, false, true ),
			),
			array(
				'name'        => 'wp_set_elementor',
				'title'       => 'Write an Elementor layout',
				'description' => 'Write the Elementor structure of a page/post and regenerate its CSS. By default "elements" REPLACES the whole layout. To build a page section by section without resending the tree, make the first array item a marker: {"elType":"__append__"} appends the rest, {"elType":"__replace__","index":N} swaps top-level section N, {"elType":"__insert__","index":N} inserts before section N. Match the JSON shape to the site: classic v3 widgets, or v4 atomic elements (check wp_ping first).',
				'inputSchema' => self::obj( array(
					'id'            => $id_prop,
					'elements'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Top-level Elementor elements, optionally led by an __append__ / __replace__ / __insert__ marker.' ),
					'page_settings' => array( 'type' => 'object', 'description' => 'Optional Elementor page settings (_elementor_page_settings), e.g. layout, padding, background.' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Write an Elementor layout', false, true, true ),
			),
			array(
				'name'        => 'wp_upload_media',
				'title'       => 'Upload media',
				'description' => 'Add an image/file to the media library by URL (download) or base64 bytes. Returns attachment id and URL.',
				'inputSchema' => self::obj( array(
					'url'      => array( 'type' => 'string', 'description' => 'Public URL to download.' ),
					'base64'   => array( 'type' => 'string', 'description' => 'Base64 file bytes (data: URI accepted). Requires filename.' ),
					'filename' => array( 'type' => 'string', 'description' => 'File name (required for base64).' ),
					'title'    => array( 'type' => 'string', 'description' => 'Optional media title.' ),
					'alt'      => array( 'type' => 'string', 'description' => 'Optional alt text.' ),
				) ),
				'annotations' => self::ann( 'Upload media', false, false, false ),
			),
			array(
				'name'        => 'wp_list_media',
				'title'       => 'List media',
				'description' => 'List media library items with pagination and optional search.',
				'inputSchema' => self::obj( array(
					'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 20).' ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number.' ),
					'search'   => array( 'type' => 'string', 'description' => 'Search term.' ),
				) ),
				'annotations' => self::ann( 'List media', true, false, true ),
			),
			array(
				'name'        => 'wp_list_terms',
				'title'       => 'List taxonomy terms',
				'description' => 'List terms of a taxonomy (e.g. category, post_tag, or a custom taxonomy).',
				'inputSchema' => self::obj( array(
					'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug, e.g. "category".' ),
				), array( 'taxonomy' ) ),
				'annotations' => self::ann( 'List taxonomy terms', true, false, true ),
			),
			array(
				'name'        => 'wp_create_term',
				'title'       => 'Create a taxonomy term',
				'description' => 'Create a new term (category/tag) in a taxonomy. Assign terms via the "terms" field on create/update content.',
				'inputSchema' => self::obj( array(
					'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug.' ),
					'name'     => array( 'type' => 'string', 'description' => 'Term name.' ),
					'slug'     => array( 'type' => 'string', 'description' => 'Optional slug.' ),
					'parent'   => array( 'type' => 'integer', 'description' => 'Optional parent term id.' ),
				), array( 'taxonomy', 'name' ) ),
				'annotations' => self::ann( 'Create a taxonomy term', false, false, false ),
			),
			array(
				'name'        => 'wp_list_history',
				'title'       => 'List change history',
				'description' => 'List recent changes made through WP MCP, newest first, with the id to pass to wp_rollback. Shows what changed, who changed it, and whether it can still be rolled back.',
				'inputSchema' => self::obj( array(
					'per_page' => array( 'type' => 'integer', 'description' => 'Entries per page (1-50, default 20).' ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number.' ),
				) ),
				'annotations' => self::ann( 'List change history', true, false, true ),
			),
			array(
				'name'        => 'wp_rollback',
				'title'       => 'Roll back a change',
				'description' => 'Undo one change from wp_list_history: restores the previous content, Elementor layout, terms and meta, trashes something that was created, or restores something that was deleted. If the item was edited after the change, this refuses unless force is true, so tell the user first. File edits are rolled back with wp_restore_extension_file.',
				'inputSchema' => self::obj( array(
					'id'    => array( 'type' => 'integer', 'description' => 'History entry id from wp_list_history.' ),
					'force' => array( 'type' => 'boolean', 'description' => 'Roll back even though the item was edited after the change. Only after the user agrees to overwrite those edits.' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Roll back a change', false, true, false ),
			),
			array(
				'name'        => 'wp_list_block_types',
				'title'       => 'List block types',
				'description' => 'List the Gutenberg blocks registered on this site (name, title, category, attribute names). Use it to check a block exists before writing markup that uses it.',
				'inputSchema' => self::obj( array(
					'search' => array( 'type' => 'string', 'description' => 'Filter by name or title, e.g. "button".' ),
					'limit'  => array( 'type' => 'integer', 'description' => 'Maximum blocks to return (default 60).' ),
				) ),
				'annotations' => self::ann( 'List block types', true, false, true ),
			),
			array(
				'name'        => 'wp_get_blocks',
				'title'       => 'Read block editor content',
				'description' => 'Read a post or page built with the block editor (Gutenberg) as block markup. Start with summary=true for a numbered outline of the top-level blocks, then read one with index=N. Without arguments it returns the whole markup, which can be large. Check "builder" in the result: elementor and divi pages have their own tools.',
				'inputSchema' => self::obj( array(
					'id'      => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					'summary' => array( 'type' => 'boolean', 'description' => 'Return an outline of the top-level blocks only.' ),
					'index'   => array( 'type' => 'integer', 'description' => 'Return one top-level block (markup and structure).' ),
					'depth'   => array( 'type' => 'integer', 'description' => 'How many levels of inner blocks to describe with index (default 3).' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Read block editor content', true, false, true ),
			),
			array(
				'name'        => 'wp_set_blocks',
				'title'       => 'Write block editor content',
				'description' => 'Write block markup (for example <!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->) to a post or page. The markup is checked first: every block must be closed in order and its attributes must be valid JSON, otherwise nothing is saved. mode: replace (default, the whole content), append, prepend, insert (before index), or replace_block (the top-level block at index). Registered-block warnings are returned. Pages built with Elementor or Divi are refused unless force=true.',
				'inputSchema' => self::obj( array(
					'id'      => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					'content' => array( 'type' => 'string', 'description' => 'Block markup.' ),
					'mode'    => array( 'type' => 'string', 'enum' => array( 'replace', 'append', 'prepend', 'insert', 'replace_block' ), 'description' => 'How to combine with the existing content (default replace).' ),
					'index'   => array( 'type' => 'integer', 'description' => 'Top-level block position for insert and replace_block.' ),
					'force'   => array( 'type' => 'boolean', 'description' => 'Write blocks over an Elementor or Divi page.' ),
				), array( 'id', 'content' ) ),
				'annotations' => self::ann( 'Write block editor content', false, true, false ),
			),
			array(
				'name'        => 'wp_get_divi',
				'title'       => 'Read a Divi layout',
				'description' => 'Read a page built with the Divi Builder (Divi 4 shortcodes). Start with summary=true for a numbered outline of the sections (rows, modules, text), then read one with index=N. Without arguments it returns the whole shortcode content. On Divi 5 sites use wp_get_blocks instead.',
				'inputSchema' => self::obj( array(
					'id'      => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					'summary' => array( 'type' => 'boolean', 'description' => 'Return an outline of the sections only.' ),
					'index'   => array( 'type' => 'integer', 'description' => 'Return one section.' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Read a Divi layout', true, false, true ),
			),
			array(
				'name'        => 'wp_set_divi',
				'title'       => 'Write a Divi layout',
				'description' => 'Write a Divi 4 layout as shortcodes ([et_pb_section][et_pb_row][et_pb_column][et_pb_text]...[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]). Shortcodes must be nested and closed correctly and start with a section, otherwise nothing is saved. mode: replace (default), append, prepend, insert (before index) or replace_section (at index). Turns the Divi Builder on for the page and clears Divi\'s cached CSS. Elementor pages are refused unless force=true.',
				'inputSchema' => self::obj( array(
					'id'      => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					'content' => array( 'type' => 'string', 'description' => 'Divi shortcode content.' ),
					'mode'    => array( 'type' => 'string', 'enum' => array( 'replace', 'append', 'prepend', 'insert', 'replace_section' ), 'description' => 'How to combine with the existing layout (default replace).' ),
					'index'   => array( 'type' => 'integer', 'description' => 'Section position for insert and replace_section.' ),
					'force'   => array( 'type' => 'boolean', 'description' => 'Write over an Elementor page.' ),
				), array( 'id', 'content' ) ),
				'annotations' => self::ann( 'Write a Divi layout', false, true, false ),
			),
			array(
				'name'        => 'wp_clear_cache',
				'title'       => 'Clear caches',
				'description' => 'Clear caches so changes show on the front end: the WordPress object cache, expired transients, Elementor CSS, Divi static resources, and the page cache plugin if one is installed (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, SiteGround, Cache Enabler, Breeze, Hummingbird, Nginx Helper). Reports what was cleared. Host-level and CDN caches are outside WordPress and are not cleared.',
				'inputSchema' => self::obj( array(
					'targets' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'object', 'transients', 'elementor', 'divi', 'page' ) ), 'description' => 'Which caches to clear. Omit for all.' ),
				) ),
				'annotations' => self::ann( 'Clear caches', false, false, true ),
			),
			array(
				'name'        => 'wp_get_settings',
				'title'       => 'Read site settings',
				'description' => 'Read common site settings: title, tagline, timezone, date and time format, posts per page, homepage and posts page, search engine visibility, default comment status and permalink structure.',
				'inputSchema' => self::obj( array() ),
				'annotations' => self::ann( 'Read site settings', true, false, true ),
			),
			array(
				'name'        => 'wp_acf_list',
				'title'       => 'List ACF setup',
				'description' => 'Read what Advanced Custom Fields (free or Pro) has on this site: field groups (with where they appear, and their fields when include_fields is true), ACF post types, ACF taxonomies, options pages (Pro) and the field types available. With "what" and "key" it returns one full definition. Call this before creating fields or setting values.',
				'inputSchema' => self::obj( array(
					'what'           => array( 'type' => 'string', 'enum' => array( 'field_groups', 'post_types', 'taxonomies', 'options_pages', 'field_types' ), 'description' => 'Which part to read. Omit for a summary of everything.' ),
					'key'            => array( 'type' => 'string', 'description' => 'With "what" of field_groups, post_types or taxonomies: the item\'s key, to read its full definition.' ),
					'include_fields' => array( 'type' => 'boolean', 'description' => 'Include each group\'s fields (names, labels, types, choices, sub fields).' ),
				) ),
				'annotations' => self::ann( 'List ACF setup', true, false, true ),
			),
			array(
				'name'        => 'wp_acf_save_post_type',
				'title'       => 'Create or update an ACF post type',
				'description' => 'Create or update a custom post type through ACF, so it appears in ACF and is registered at once. Give post_type (the slug, up to 20 characters), singular and plural names, and optional settings (public, hierarchical, supports, taxonomies, has_archive, show_in_rest, menu_icon, menu_position, rewrite and more). Labels are generated from the names. An existing ACF post type with the same slug is updated. Use dry_run to preview. Then add fields with wp_acf_save_field_group and create items with wp_create_content.',
				'inputSchema' => self::obj( array(
					'post_type' => array( 'type' => 'string', 'description' => 'Slug, e.g. "book". Lowercase letters, numbers, underscores, dashes; up to 20 characters.' ),
					'singular'  => array( 'type' => 'string', 'description' => 'Singular name, e.g. "Book". Needed when creating.' ),
					'plural'    => array( 'type' => 'string', 'description' => 'Plural name, e.g. "Books". Needed when creating.' ),
					'settings'  => array( 'type' => 'object', 'description' => 'Optional: description, public, hierarchical, supports (title, editor, thumbnail, excerpt, custom-fields, comments, revisions, page-attributes...), taxonomies, has_archive, has_archive_slug, show_in_rest, rest_base, show_in_menu, menu_icon (dashicons-book), menu_position, rewrite {slug, with_front}, labels (overrides).' ),
					'active'    => array( 'type' => 'boolean', 'description' => 'false turns the post type off without deleting it.' ),
					'dry_run'   => array( 'type' => 'boolean', 'description' => 'Return the configuration without saving.' ),
				), array( 'post_type' ) ),
				'annotations' => self::ann( 'Create or update an ACF post type', false, true, false ),
			),
			array(
				'name'        => 'wp_acf_save_taxonomy',
				'title'       => 'Create or update an ACF taxonomy',
				'description' => 'Create or update a custom taxonomy through ACF. Give taxonomy (the slug, up to 32 characters), singular and plural names, and post_types it applies to. Optional settings include hierarchical, show_in_rest, show_admin_column, rewrite. Names that WordPress reserves are refused. Use dry_run to preview.',
				'inputSchema' => self::obj( array(
					'taxonomy'   => array( 'type' => 'string', 'description' => 'Slug, e.g. "genre".' ),
					'singular'   => array( 'type' => 'string', 'description' => 'Singular name, e.g. "Genre".' ),
					'plural'     => array( 'type' => 'string', 'description' => 'Plural name, e.g. "Genres".' ),
					'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Post types it applies to, e.g. ["book"]. They must already exist.' ),
					'settings'   => array( 'type' => 'object', 'description' => 'Optional: description, public, hierarchical, show_ui, show_in_menu, show_in_nav_menus, show_in_rest, rest_base, show_tagcloud, show_in_quick_edit, show_admin_column, rewrite {slug, with_front, rewrite_hierarchical}, labels.' ),
					'active'     => array( 'type' => 'boolean', 'description' => 'false turns the taxonomy off without deleting it.' ),
					'dry_run'    => array( 'type' => 'boolean', 'description' => 'Return the configuration without saving.' ),
				), array( 'taxonomy' ) ),
				'annotations' => self::ann( 'Create or update an ACF taxonomy', false, true, false ),
			),
			array(
				'name'        => 'wp_acf_save_field_group',
				'title'       => 'Create or update an ACF field group',
				'description' => 'Create or update an ACF field group with its fields. fields is a list of {name, label, type, ...} using ACF field settings (required, instructions, default_value, choices for select/radio/checkbox, min/max, return_format, etc.); repeater and group fields take sub_fields, flexible_content takes layouts [{name, label, sub_fields}]. Repeater, flexible content, gallery and clone need ACF Pro and are refused on the free plugin. Keys are generated. Say where the fields appear with "post_type": "book" or a location list. For an existing group (pass its key) the fields you send replace its fields, so read it first with wp_acf_list. Use dry_run to preview.',
				'inputSchema' => self::obj( array(
					'key'        => array( 'type' => 'string', 'description' => 'Existing group key (group_...) to update. Omit to create.' ),
					'title'      => array( 'type' => 'string', 'description' => 'Group title.' ),
					'post_type'  => array( 'type' => 'string', 'description' => 'Shortcut location: show on this post type. A list of post types is also accepted.' ),
					'location'   => array( 'type' => 'array', 'description' => 'ACF location rule groups: [[{"param":"post_type","operator":"==","value":"book"}]]. Groups are OR, rules inside a group are AND.' ),
					'fields'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Field definitions.' ),
					'position'   => array( 'type' => 'string', 'enum' => array( 'acf_after_title', 'normal', 'side' ) ),
					'style'      => array( 'type' => 'string', 'enum' => array( 'default', 'seamless' ) ),
					'label_placement' => array( 'type' => 'string', 'enum' => array( 'top', 'left' ) ),
					'instruction_placement' => array( 'type' => 'string', 'enum' => array( 'label', 'field' ) ),
					'show_in_rest' => array( 'type' => 'boolean', 'description' => 'Expose the fields in the REST API.' ),
					'active'     => array( 'type' => 'boolean' ),
					'description' => array( 'type' => 'string' ),
					'dry_run'    => array( 'type' => 'boolean', 'description' => 'Return the group without saving.' ),
				) ),
				'annotations' => self::ann( 'Create or update an ACF field group', false, true, false ),
			),
			array(
				'name'        => 'wp_acf_delete',
				'title'       => 'Delete an ACF definition',
				'description' => 'Delete an ACF post type, taxonomy or field group by key. Content already saved stays in the database; only the definition goes. It is recorded in the history and can be rolled back.',
				'inputSchema' => self::obj( array(
					'kind' => array( 'type' => 'string', 'enum' => array( 'post_type', 'taxonomy', 'field_group' ) ),
					'key'  => array( 'type' => 'string', 'description' => 'The item\'s ACF key, from wp_acf_list.' ),
				), array( 'kind', 'key' ) ),
				'annotations' => self::ann( 'Delete an ACF definition', false, true, false ),
			),
			array(
				'name'        => 'wp_acf_get_values',
				'title'       => 'Read ACF field values',
				'description' => 'Read the ACF field values of a post (numeric id), an options page (id "option"), a user (user_5) or a term (category_3). By default values are raw (image fields give an attachment ID); set format=true for ACF\'s formatted output.',
				'inputSchema' => self::obj( array(
					'id'     => array( 'type' => array( 'integer', 'string' ), 'description' => 'Post ID, "option", user_{id} or {taxonomy}_{term id}.' ),
					'format' => array( 'type' => 'boolean', 'description' => 'Return formatted values.' ),
				), array( 'id' ) ),
				'annotations' => self::ann( 'Read ACF field values', true, false, true ),
			),
			array(
				'name'        => 'wp_acf_set_values',
				'title'       => 'Set ACF field values',
				'description' => 'Set ACF field values through ACF itself, so repeaters, relationships and the hidden field references are saved correctly. values is {field_name: value}. Every name must be an ACF field that applies to the target, otherwise nothing is saved. Formats: text and numbers as is; true_false as true/false; image/file as an attachment ID; relationship, post_object and taxonomy as IDs; date_picker as Ymd (20261231); repeater as a list of row objects keyed by sub field name. Recorded in the history and can be rolled back.',
				'inputSchema' => self::obj( array(
					'id'     => array( 'type' => array( 'integer', 'string' ), 'description' => 'Post ID, "option", user_{id} or {taxonomy}_{term id}.' ),
					'values' => array( 'type' => 'object', 'description' => 'Field names and their new values.' ),
				), array( 'id', 'values' ) ),
				'annotations' => self::ann( 'Set ACF field values', false, false, true ),
			),
			array(
				'name'        => 'wp_update_settings',
				'title'       => 'Change site settings',
				'description' => 'Change common site settings (see wp_get_settings for the names). All values are checked first and nothing is changed if one is invalid. Site and home URLs, the admin email, registration and user roles cannot be changed here. The previous values are saved so the change can be rolled back.',
				'inputSchema' => self::obj( array(
					'settings' => array( 'type' => 'object', 'description' => 'Setting names and new values, e.g. {"blogname":"My site","posts_per_page":12}.' ),
				), array( 'settings' ) ),
				'annotations' => self::ann( 'Change site settings', false, true, false ),
			),
		);
	}
}
