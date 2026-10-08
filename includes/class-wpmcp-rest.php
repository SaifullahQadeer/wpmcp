<?php
/**
 * REST API controller for WP MCP.
 *
 * Thin HTTP layer over WPMCP_Core. Registers routes under `wpmcp/v1`,
 * protected by the API key (WPMCP_Auth::check). The MCP endpoint shares the same
 * WPMCP_Core logic (see class-wpmcp-mcp.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_REST {

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function permission( $request ) {
		return WPMCP_Auth::check( $request );
	}

	/** Convert a Core return value (array|WP_Error) into a REST response. */
	private function respond( $result, $success_status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, $success_status );
	}

	private function body( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		return $body;
	}

	public function register_routes() {
		$perm = array( $this, 'permission' );
		$ns   = WPMCP_NAMESPACE;

		register_rest_route( $ns, '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ping' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/post-types', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_post_types' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/content/(?P<type>[a-zA-Z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_content' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_content' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $ns, '/content/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_content' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => array( 'POST', 'PUT', 'PATCH' ),
				'callback'            => array( $this, 'update_content' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_content' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $ns, '/elementor/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_elementor' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => array( 'POST', 'PUT' ),
				'callback'            => array( $this, 'set_elementor' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $ns, '/media', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_media' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_media' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $ns, '/terms/(?P<taxonomy>[a-zA-Z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_terms' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_term' ),
				'permission_callback' => $perm,
			),
		) );
	}

	/* Callbacks -> Core */

	public function ping() {
		return $this->respond( WPMCP_Core::ping() );
	}

	public function list_post_types() {
		return $this->respond( WPMCP_Core::list_post_types() );
	}

	public function list_content( $request ) {
		return $this->respond( WPMCP_Core::list_content( $request['type'], array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'status'   => $request->get_param( 'status' ),
			'search'   => $request->get_param( 'search' ),
		) ) );
	}

	public function get_content( $request ) {
		$include = $request->get_param( 'include_elementor' );
		$include = ( null === $include ) ? true : filter_var( $include, FILTER_VALIDATE_BOOLEAN );
		return $this->respond( WPMCP_Core::get_content( $request['type'], (int) $request['id'], $include ) );
	}

	public function create_content( $request ) {
		return $this->respond( WPMCP_Core::create_content( $request['type'], $this->body( $request ) ), 201 );
	}

	public function update_content( $request ) {
		return $this->respond( WPMCP_Core::update_content( $request['type'], (int) $request['id'], $this->body( $request ) ) );
	}

	public function delete_content( $request ) {
		$force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		return $this->respond( WPMCP_Core::delete_content( $request['type'], (int) $request['id'], $force ) );
	}

	public function get_elementor( $request ) {
		return $this->respond( WPMCP_Core::get_elementor( (int) $request['id'], array(
			'summary' => filter_var( $request->get_param( 'summary' ), FILTER_VALIDATE_BOOLEAN ),
			'index'   => $request->get_param( 'index' ),
			'depth'   => $request->get_param( 'depth' ),
		) ) );
	}

	public function set_elementor( $request ) {
		$body          = $this->body( $request );
		$page_settings = isset( $body['page_settings'] ) && is_array( $body['page_settings'] ) ? $body['page_settings'] : null;
		$elements      = null;
		if ( isset( $body['elements'] ) ) {
			$elements = $body['elements'];
		} elseif ( isset( $body['elementor'] ) ) {
			$elements = $body['elementor'];
		} elseif ( ! $page_settings ) {
			$elements = $body;
		}
		return $this->respond( WPMCP_Core::set_elementor( (int) $request['id'], $elements, $page_settings ) );
	}

	public function list_media( $request ) {
		return $this->respond( WPMCP_Core::list_media( array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'search'   => $request->get_param( 'search' ),
		) ) );
	}

	public function upload_media( $request ) {
		return $this->respond( WPMCP_Core::upload_media( $this->body( $request ) ), 201 );
	}

	public function list_terms( $request ) {
		return $this->respond( WPMCP_Core::list_terms( $request['taxonomy'] ) );
	}

	public function create_term( $request ) {
		return $this->respond( WPMCP_Core::create_term( $request['taxonomy'], $this->body( $request ) ), 201 );
	}
}
