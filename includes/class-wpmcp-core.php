<?php
/**
 * Core business logic for WP MCP.
 *
 * All content/media/Elementor operations live here as static methods so they
 * can be shared by BOTH the REST controller and the embedded MCP server.
 * Each method returns a plain array on success or a WP_Error on failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Core {

	/* ----------------------------------------------------------------- */
	/* Formatting helpers                                                */
	/* ----------------------------------------------------------------- */

	public static function format_post( $post, $include_body = false, $include_elementor = true ) {
		$data = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'link'           => get_permalink( $post ),
			'edit_link'      => get_edit_post_link( $post->ID, 'raw' ),
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'author'         => (int) $post->post_author,
			'date'           => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'builder'        => WPMCP_Builders::builder_for( $post->ID ),
		);

		if ( $include_body ) {
			$data['content'] = $post->post_content;
			$data['excerpt'] = $post->post_excerpt;
			$data['meta']    = self::get_public_meta( $post->ID );

			$el = WPMCP_Elementor::get_data( $post->ID );
			if ( $include_elementor ) {
				$data['elementor'] = $el;
			} else {
				// Summary only. A full Elementor tree can blow past the host's
				// tool-result size cap on its own.
				$data['elementor'] = array(
					'has_elementor'  => $el['has_elementor'],
					'edit_mode'      => $el['edit_mode'],
					'elements_count' => $el['elements_count'],
					'raw_size'       => $el['raw_size'],
					'note'           => 'Omitted. Read it with wp_get_elementor (start with summary=true).',
				);
			}
		}

		return $data;
	}

	public static function get_public_meta( $post_id ) {
		$all = get_post_meta( $post_id );
		$out = array();
		foreach ( $all as $key => $values ) {
			if ( in_array( $key, array( '_elementor_data', '_elementor_css' ), true ) ) {
				continue;
			}
			if ( '_' === substr( $key, 0, 1 ) ) {
				continue;
			}
			$out[ $key ] = ( 1 === count( $values ) ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return $out;
	}

	private static function apply_meta( $post_id, $meta ) {
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	private static function apply_post_extras( $post_id, $body ) {
		if ( isset( $body['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $body['featured_media'] );
		}
		if ( isset( $body['meta'] ) && is_array( $body['meta'] ) ) {
			self::apply_meta( $post_id, $body['meta'] );
		}
		if ( isset( $body['terms'] ) && is_array( $body['terms'] ) ) {
			foreach ( $body['terms'] as $taxonomy => $terms ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					wp_set_object_terms( $post_id, $terms, $taxonomy, false );
				}
			}
		}
		if ( isset( $body['elementor'] ) ) {
			$elements = $body['elementor'];
			if ( is_array( $elements ) && isset( $elements['elements'] ) ) {
				$elements = $elements['elements'];
			}
			WPMCP_Elementor::set_data( $post_id, $elements );
		}
	}

	private static function validate_type( $type ) {
		if ( ! post_type_exists( $type ) ) {
			return new WP_Error(
				'wpmcp_unknown_type',
				sprintf( 'Post type "%s" does not exist. Use list_post_types to see valid values.', $type ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/* ----------------------------------------------------------------- */
	/* Operations                                                        */
	/* ----------------------------------------------------------------- */

	public static function ping() {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $obj ) {
			$types[] = array(
				'slug'  => $slug,
				'label' => $obj->labels->name,
			);
		}
		return array(
			'ok'                 => true,
			'plugin_version'     => WPMCP_VERSION,
			'site_name'          => get_bloginfo( 'name' ),
			'site_url'           => home_url(),
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'elementor_active'   => WPMCP_Elementor::is_active(),
			'elementor'          => WPMCP_Elementor::environment(),
			'editors'            => WPMCP_Builders::environment(),
			'cache_plugins'      => WPMCP_Site::detected_caches(),
			'max_result_chars'   => (int) apply_filters( 'wpmcp_max_result_chars', WPMCP_MAX_RESULT_CHARS ),
			'post_types'         => $types,
		);
	}

	public static function list_post_types() {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $slug => $obj ) {
			$out[] = array(
				'slug'         => $slug,
				'label'        => $obj->labels->name,
				'public'       => (bool) $obj->public,
				'show_ui'      => (bool) $obj->show_ui,
				'hierarchical' => (bool) $obj->hierarchical,
				'taxonomies'   => get_object_taxonomies( $slug ),
			);
		}
		return array( 'post_types' => $out );
	}

	public static function list_content( $type, $args = array() ) {
		$valid = self::validate_type( $type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$status   = ! empty( $args['status'] ) ? $args['status'] : 'any';
		$search   = $args['search'] ?? '';

		$q = array(
			'post_type'      => $type,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => ( 'any' === $status ) ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $search ) ) {
			$q['s'] = sanitize_text_field( $search );
		}

		$query = new WP_Query( $q );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::format_post( $post, false );
		}

		return array(
			'type'        => $type,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'count'       => count( $items ),
			'items'       => $items,
		);
	}

	public static function get_content( $type, $id, $include_elementor = true ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'wpmcp_not_found', 'Item not found for that type/id.', array( 'status' => 404 ) );
		}
		return self::format_post( $post, true, $include_elementor );
	}

	public static function create_content( $type, $body ) {
		$valid = self::validate_type( $type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$body = is_array( $body ) ? $body : array();

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => isset( $body['title'] ) ? wp_strip_all_tags( $body['title'] ) : '',
			'post_content' => isset( $body['content'] ) ? $body['content'] : '',
			'post_excerpt' => isset( $body['excerpt'] ) ? $body['excerpt'] : '',
			'post_status'  => isset( $body['status'] ) ? sanitize_key( $body['status'] ) : 'draft',
		);
		if ( isset( $body['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $body['slug'] );
		}
		if ( isset( $body['parent'] ) ) {
			$postarr['post_parent'] = (int) $body['parent'];
		}
		if ( isset( $body['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $body['menu_order'];
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true ); // Expects slashed data; without this backslashes in content are lost.
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		self::apply_post_extras( $post_id, $body );
		WPMCP_History::record( 'wp_create_content', 'post', $post_id, $postarr['post_title'], sprintf( 'Created %s', $type ), array( 'op' => 'trash_created' ) );

		return array(
			'ok'   => true,
			'post' => self::format_post( get_post( $post_id ), true ),
		);
	}

	public static function update_content( $type, $id, $body ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'wpmcp_not_found', 'Item not found for that type/id.', array( 'status' => 404 ) );
		}
		$body = is_array( $body ) ? $body : array();

		$postarr = array( 'ID' => (int) $id );
		if ( array_key_exists( 'title', $body ) ) {
			$postarr['post_title'] = wp_strip_all_tags( $body['title'] );
		}
		if ( array_key_exists( 'content', $body ) ) {
			$postarr['post_content'] = $body['content'];
		}
		if ( array_key_exists( 'excerpt', $body ) ) {
			$postarr['post_excerpt'] = $body['excerpt'];
		}
		if ( array_key_exists( 'status', $body ) ) {
			$postarr['post_status'] = sanitize_key( $body['status'] );
		}
		if ( array_key_exists( 'slug', $body ) ) {
			$postarr['post_name'] = sanitize_title( $body['slug'] );
		}
		if ( array_key_exists( 'parent', $body ) ) {
			$postarr['post_parent'] = (int) $body['parent'];
		}
		if ( array_key_exists( 'menu_order', $body ) ) {
			$postarr['menu_order'] = (int) $body['menu_order'];
		}

		$history = WPMCP_History::begin_state( (int) $id, WPMCP_History::scope_for_update( $body ) );
		$result  = wp_update_post( wp_slash( $postarr ), true ); // Expects slashed data; without this backslashes in content are lost.
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::apply_post_extras( (int) $id, $body );
		$changed = array_values( array_intersect( array_keys( $body ), array( 'title', 'content', 'excerpt', 'status', 'slug', 'parent', 'menu_order', 'featured_media', 'meta', 'terms', 'elementor' ) ) );
		WPMCP_History::finish_state( $history, 'wp_update_content', sprintf( 'Updated %s (%s)', $type, implode( ', ', $changed ) ) );

		return array(
			'ok'   => true,
			'post' => self::format_post( get_post( (int) $id ), true ),
		);
	}

	public static function delete_content( $type, $id, $force = false ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'wpmcp_not_found', 'Item not found for that type/id.', array( 'status' => 404 ) );
		}
		$snapshot = WPMCP_History::full_snapshot( (int) $id );
		$result   = wp_delete_post( (int) $id, (bool) $force );
		if ( ! $result ) {
			return new WP_Error( 'wpmcp_delete_failed', 'Could not delete the item.', array( 'status' => 500 ) );
		}
		$trashed = (bool) get_post( (int) $id ); // Still there means it went to the trash and can simply be restored.
		WPMCP_History::record( 'wp_delete_content', 'post', (int) $id, $post->post_title, $trashed ? sprintf( 'Moved %s to the trash', $type ) : sprintf( 'Deleted %s permanently', $type ), $trashed ? array( 'op' => 'untrash' ) : $snapshot );
		return array(
			'ok'      => true,
			'deleted' => (int) $id,
			'forced'  => (bool) $force,
		);
	}

	/**
	 * Read an Elementor layout.
	 *
	 * Supports three ways of asking for less than the whole tree, because a
	 * single builder page routinely exceeds a client's tool-result size cap:
	 *   summary=true  -> outline only (types, ids, labels, child counts)
	 *   index=N       -> one top-level section, in full
	 *   depth=N       -> whole tree, cut off below level N
	 *
	 * @param int   $id   Post ID.
	 * @param array $args Optional arguments.
	 * @return array|WP_Error
	 */
	public static function get_elementor( $id, $args = array() ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return new WP_Error( 'wpmcp_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$args     = is_array( $args ) ? $args : array();
		$data     = WPMCP_Elementor::get_data( (int) $id );
		$elements = $data['elements'];

		$out = array(
			'id'               => (int) $id,
			'title'            => get_the_title( $post ),
			'link'             => get_permalink( $post ),
			'elementor_active' => WPMCP_Elementor::is_active(),
			'elementor'        => WPMCP_Elementor::environment(),
			'has_elementor'    => $data['has_elementor'],
			'elements_count'   => $data['elements_count'],
			'raw_size'         => $data['raw_size'],
			'page_settings'    => WPMCP_Elementor::get_page_settings( (int) $id ),
		);

		// Outline only. Start at the requested depth and step back until the
		// outline itself fits comfortably inside the client's result budget --
		// a 40-section page with 40 widgets each still overflows at depth 3.
		if ( ! empty( $args['summary'] ) ) {
			$limit  = (int) apply_filters( 'wpmcp_max_result_chars', WPMCP_MAX_RESULT_CHARS );
			$budget = (int) ( $limit * 0.6 );
			$depth  = isset( $args['depth'] ) ? max( 1, (int) $args['depth'] ) : 2;

			$outline = WPMCP_Elementor::outline( $elements, $depth );
			while ( $depth > 1 && strlen( (string) wp_json_encode( $outline ) ) > $budget ) {
				$depth--;
				$outline = WPMCP_Elementor::outline( $elements, $depth );
			}

			$out['view']    = 'summary';
			$out['depth']   = $depth;
			$out['outline'] = $outline;
			$out['note']    = 'Outline only, ' . $depth . ' level(s) deep. Fetch a section in full with index=N, or go one level deeper with summary=true and depth=' . ( $depth + 1 ) . '.';
			return $out;
		}

		// A single top-level section.
		if ( isset( $args['index'] ) && '' !== $args['index'] ) {
			$index = (int) $args['index'];
			if ( ! isset( $elements[ $index ] ) ) {
				return new WP_Error(
					'wpmcp_bad_index',
					sprintf( 'No top-level element at index %d. This page has %d (valid: 0-%d).', $index, count( $elements ), max( 0, count( $elements ) - 1 ) ),
					array( 'status' => 400 )
				);
			}
			$section = array( $elements[ $index ] );
			if ( ! empty( $args['depth'] ) ) {
				$section = WPMCP_Elementor::limit_depth( $section, max( 1, (int) $args['depth'] ) );
			}
			$out['view']     = 'section';
			$out['index']    = $index;
			$out['elements'] = $section;
			return $out;
		}

		// Whole tree, optionally depth-limited.
		if ( ! empty( $args['depth'] ) ) {
			$out['view']     = 'depth-limited';
			$out['depth']    = max( 1, (int) $args['depth'] );
			$out['elements'] = WPMCP_Elementor::limit_depth( $elements, $out['depth'] );
			return $out;
		}

		$out['view']     = 'full';
		$out['elements'] = $elements;
		return $out;
	}

	/**
	 * Write an Elementor layout and/or its page settings.
	 *
	 * "elements" replaces the whole layout unless the first item is a marker:
	 *   {"elType":"__append__"}                 append the rest
	 *   {"elType":"__replace__","index":N}      swap top-level section N
	 *   {"elType":"__insert__","index":N}       insert before section N
	 *
	 * @param int         $id            Post ID.
	 * @param array|null  $elements      Elements, or null to only touch settings.
	 * @param array|null  $page_settings Optional _elementor_page_settings.
	 * @return array|WP_Error
	 */
	public static function set_elementor( $id, $elements, $page_settings = null ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return new WP_Error( 'wpmcp_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$mode    = 'replace-all';
		$history = WPMCP_History::begin_state( (int) $id, WPMCP_History::scope_for_elementor() );

		if ( null !== $elements ) {
			$marker = ( is_array( $elements ) && isset( $elements[0] ) && is_array( $elements[0] ) && isset( $elements[0]['elType'] ) )
				? (string) $elements[0]['elType']
				: '';

			if ( in_array( $marker, array( '__append__', '__replace__', '__insert__' ), true ) ) {
				$existing = WPMCP_Elementor::get_data( (int) $id );
				$existing = ( isset( $existing['elements'] ) && is_array( $existing['elements'] ) ) ? $existing['elements'] : array();
				$incoming = array_values( array_slice( $elements, 1 ) );
				$index    = isset( $elements[0]['index'] ) ? (int) $elements[0]['index'] : 0;

				if ( '__append__' === $marker ) {
					$mode     = 'append';
					$elements = array_merge( $existing, $incoming );
				} elseif ( '__replace__' === $marker ) {
					if ( ! isset( $existing[ $index ] ) ) {
						return new WP_Error(
							'wpmcp_bad_index',
							sprintf( '__replace__ index %d does not exist. This page has %d top-level elements.', $index, count( $existing ) ),
							array( 'status' => 400 )
						);
					}
					$mode     = 'replace-section';
					$elements = array_merge(
						array_slice( $existing, 0, $index ),
						$incoming,
						array_slice( $existing, $index + 1 )
					);
				} else {
					if ( $index < 0 || $index > count( $existing ) ) {
						return new WP_Error(
							'wpmcp_bad_index',
							sprintf( '__insert__ index %d is out of range (0-%d).', $index, count( $existing ) ),
							array( 'status' => 400 )
						);
					}
					$mode     = 'insert';
					$elements = array_merge(
						array_slice( $existing, 0, $index ),
						$incoming,
						array_slice( $existing, $index )
					);
				}
			}

			$result = WPMCP_Elementor::set_data( (int) $id, $elements );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_array( $page_settings ) ) {
			WPMCP_Elementor::set_page_settings( (int) $id, $page_settings );
			WPMCP_Elementor::clear_cache( (int) $id );
		}

		WPMCP_History::finish_state( $history, 'wp_set_elementor', null === $elements ? 'Changed Elementor page settings' : sprintf( 'Changed the Elementor layout (%s)', $mode ) );
		$data = WPMCP_Elementor::get_data( (int) $id );
		return array(
			'ok'                    => true,
			'id'                    => (int) $id,
			'mode'                  => null === $elements ? 'page-settings-only' : $mode,
			'elements_count'        => $data['elements_count'],
			'raw_size'              => $data['raw_size'],
			'page_settings_updated' => is_array( $page_settings ),
			'link'                  => get_permalink( (int) $id ),
		);
	}

	public static function list_media( $args = array() ) {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$search   = $args['search'] ?? '';

		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);
		if ( ! empty( $search ) ) {
			$q['s'] = sanitize_text_field( $search );
		}

		$query = new WP_Query( $q );
		$items = array();
		foreach ( $query->posts as $att ) {
			$items[] = array(
				'id'        => $att->ID,
				'title'     => get_the_title( $att ),
				'url'       => wp_get_attachment_url( $att->ID ),
				'mime_type' => $att->post_mime_type,
				'alt'       => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
			);
		}
		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'count'       => count( $items ),
			'items'       => $items,
		);
	}

	public static function upload_media( $body ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$body     = is_array( $body ) ? $body : array();
		$tmp      = '';
		$filename = isset( $body['filename'] ) ? sanitize_file_name( $body['filename'] ) : '';

		if ( ! empty( $body['url'] ) ) {
			$url = esc_url_raw( $body['url'] );
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'wpmcp_download_failed', 'Could not download the URL: ' . $tmp->get_error_message(), array( 'status' => 400 ) );
			}
			if ( '' === $filename ) {
				$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			}
		} elseif ( ! empty( $body['base64'] ) ) {
			if ( '' === $filename ) {
				return new WP_Error( 'wpmcp_no_filename', 'A "filename" is required when uploading base64 data.', array( 'status' => 400 ) );
			}
			$raw = $body['base64'];
			if ( false !== strpos( $raw, ',' ) && stripos( $raw, 'base64' ) !== false ) {
				$raw = substr( $raw, strpos( $raw, ',' ) + 1 );
			}
			$decoded = base64_decode( $raw, true );
			if ( false === $decoded ) {
				return new WP_Error( 'wpmcp_bad_base64', 'The base64 data could not be decoded.', array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( $filename );
			file_put_contents( $tmp, $decoded );
		} else {
			return new WP_Error( 'wpmcp_no_media', 'Provide either "url" or "base64" (+ filename).', array( 'status' => 400 ) );
		}

		$file_array = array(
			'name'     => $filename ? $filename : 'upload.bin',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : null );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new WP_Error( 'wpmcp_upload_failed', 'Upload failed: ' . $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		if ( isset( $body['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $body['alt'] ) );
		}
		WPMCP_History::record( 'wp_upload_media', 'attachment', $attachment_id, get_the_title( $attachment_id ), 'Uploaded ' . $file_array['name'], array( 'op' => 'delete_attachment' ) );

		return array(
			'ok'        => true,
			'id'        => $attachment_id,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'mime_type' => get_post_mime_type( $attachment_id ),
		);
	}

	public static function list_terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wpmcp_unknown_taxonomy', 'Taxonomy does not exist.', array( 'status' => 404 ) );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
		}
		return array(
			'taxonomy' => $taxonomy,
			'terms'    => $out,
		);
	}

	public static function create_term( $taxonomy, $body ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'wpmcp_unknown_taxonomy', 'Taxonomy does not exist.', array( 'status' => 404 ) );
		}
		$body = is_array( $body ) ? $body : array();
		$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'wpmcp_no_name', 'A term "name" is required.', array( 'status' => 400 ) );
		}
		$args = array();
		if ( isset( $body['slug'] ) ) {
			$args['slug'] = sanitize_title( $body['slug'] );
		}
		if ( isset( $body['parent'] ) ) {
			$args['parent'] = (int) $body['parent'];
		}
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		WPMCP_History::record( 'wp_create_term', 'term', (int) $result['term_id'], $name, sprintf( 'Created %s term', $taxonomy ), array( 'op' => 'delete_term', 'taxonomy' => $taxonomy, 'term_id' => (int) $result['term_id'] ) );
		return array(
			'ok'   => true,
			'id'   => $result['term_id'],
			'name' => $name,
		);
	}
}
