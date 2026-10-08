<?php
/**
 * Elementor helper for WP MCP.
 *
 * Elementor stores a page layout as a JSON string in the `_elementor_data`
 * post meta and its page-level options in `_elementor_page_settings`. This
 * class reads and writes both, and reports which editor generation the site
 * runs (v3 classic widgets vs v4 atomic elements) because the two use
 * different JSON shapes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Elementor {

	/**
	 * Is Elementor active on this site?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\\Elementor\\Plugin' );
	}

	/**
	 * Which Elementor version and editor generation is running.
	 *
	 * Elementor 4.0 introduced the "atomic" editor, whose elements (e-div,
	 * e-heading, ...) carry a different data shape from classic v3 widgets.
	 * Anything writing _elementor_data needs to know which one to emit.
	 *
	 * @return array
	 */
	public static function environment() {
		$version = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
		$pro     = defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '';
		$major   = $version ? (int) explode( '.', $version )[0] : 0;

		$generation = 'unknown';
		if ( $major >= 4 ) {
			$generation = 'v4-atomic';
		} elseif ( $major === 3 ) {
			$generation = 'v3-classic';
		}

		// Containers (flexbox/grid) replaced section+column in Elementor 3.6+.
		$containers = ( $major > 3 ) || ( $version && version_compare( $version, '3.6.0', '>=' ) );

		return array(
			'active'              => self::is_active(),
			'version'             => $version,
			'pro_version'         => $pro,
			'generation'          => $generation,
			'supports_containers' => (bool) $containers,
			'note'                => 'v4-atomic' === $generation
				? 'Elementor 4 atomic editor: use atomic element types (e-div, e-heading, ...). Classic v3 widget JSON still renders but is legacy.'
				: 'Elementor 3 classic editor: use container/widget JSON. Prefer "container" over the deprecated section+column pair.',
		);
	}

	/**
	 * Get the Elementor data (decoded array) for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_data( $post_id ) {
		$raw      = get_post_meta( $post_id, '_elementor_data', true );
		$elements = array();
		$has      = false;

		if ( ! empty( $raw ) ) {
			$has = true;
			if ( is_string( $raw ) ) {
				$decoded  = json_decode( $raw, true );
				$elements = is_array( $decoded ) ? $decoded : array();
			} elseif ( is_array( $raw ) ) {
				$elements = $raw;
				$raw      = wp_json_encode( $raw );
			}
		}

		return array(
			'has_elementor'  => $has,
			'edit_mode'      => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'elements'       => $elements,
			'elements_count' => count( $elements ),
			'raw'            => is_string( $raw ) ? $raw : '',
			'raw_size'       => is_string( $raw ) ? strlen( $raw ) : 0,
		);
	}

	/**
	 * Page-level Elementor settings (_elementor_page_settings).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_page_settings( $post_id ) {
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Write page-level Elementor settings.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $settings Settings array.
	 * @return void
	 */
	public static function set_page_settings( $post_id, $settings ) {
		if ( ! is_array( $settings ) ) {
			return;
		}
		update_post_meta( $post_id, '_elementor_page_settings', $settings );
	}

	/**
	 * Build a compact outline of an element tree: type, id and child counts,
	 * without the settings payload. Lets a client see a 400 KB page in a few KB.
	 *
	 * @param array $elements Elements array.
	 * @param int   $depth    How deep to descend (default 3).
	 * @return array
	 */
	public static function outline( $elements, $depth = 3 ) {
		$out = array();
		if ( ! is_array( $elements ) ) {
			return $out;
		}
		foreach ( array_values( $elements ) as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$children = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			$node     = array(
				'index'    => $i,
				'id'       => isset( $el['id'] ) ? $el['id'] : null,
				'elType'   => isset( $el['elType'] ) ? $el['elType'] : null,
				'children' => count( $children ),
			);
			if ( isset( $el['widgetType'] ) ) {
				$node['widgetType'] = $el['widgetType'];
			}
			$label = self::label_for( $el );
			if ( '' !== $label ) {
				$node['label'] = $label;
			}
			if ( $depth > 1 && $children ) {
				$node['items'] = self::outline( $children, $depth - 1 );
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Best-effort human label for an element, so an outline is readable.
	 *
	 * @param array $el Element.
	 * @return string
	 */
	private static function label_for( $el ) {
		$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
		foreach ( array( '_title', 'title', 'title_text', 'heading', 'text', 'editor', 'button_text' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$text = wp_strip_all_tags( $settings[ $key ] );
				$text = trim( preg_replace( '/\s+/', ' ', $text ) );
				if ( '' !== $text ) {
					return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 60 ) : substr( $text, 0, 60 );
				}
			}
		}
		return '';
	}

	/**
	 * Trim a tree to a maximum depth, replacing deeper children with a count.
	 *
	 * @param array $elements Elements.
	 * @param int   $depth    Remaining depth.
	 * @return array
	 */
	public static function limit_depth( $elements, $depth ) {
		if ( ! is_array( $elements ) ) {
			return array();
		}
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}
			$children = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			if ( $depth <= 1 && $children ) {
				$el['elements']            = array();
				$el['__children_omitted__'] = count( $children );
			} elseif ( $children ) {
				$el['elements'] = self::limit_depth( $children, $depth - 1 );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Write Elementor data to a post.
	 *
	 * @param int          $post_id  Post ID.
	 * @param array|string $elements Elements as an array or JSON string.
	 * @return true|WP_Error
	 */
	public static function set_data( $post_id, $elements ) {
		if ( is_string( $elements ) ) {
			$decoded = json_decode( $elements, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'wpmcp_bad_elementor_json',
					'The provided Elementor data is not valid JSON: ' . json_last_error_msg(),
					array( 'status' => 400 )
				);
			}
			$elements = $decoded;
		}

		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'wpmcp_bad_elementor_data',
				'Elementor data must be a JSON array of elements.',
				array( 'status' => 400 )
			);
		}

		// Primary path: write raw meta directly. This works even for API-key
		// requests where there is no logged-in user (Elementor's own
		// document->save() silently refuses to persist without an editable
		// current user, so we do NOT rely on it here).
		$json = wp_json_encode( $elements );
		if ( false === $json ) {
			return new WP_Error(
				'wpmcp_encode_failed',
				'The Elementor data could not be encoded as JSON: ' . json_last_error_msg(),
				array( 'status' => 400 )
			);
		}

		// wp_slash so escaped unicode survives update_post_meta.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( '' === (string) get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		}

		// Force CSS and the per-page asset map to regenerate on next view.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		self::regenerate_css( $post_id );

		return true;
	}

	/**
	 * Ask Elementor to (re)generate CSS for a post, if Elementor is present.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function regenerate_css( $post_id ) {
		if ( ! self::is_active() || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}
		try {
			// Per-post CSS file.
			if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
				$css = new \Elementor\Core\Files\CSS\Post( (int) $post_id );
				$css->update();
			}
			// And clear the global cache so front-end picks up the change.
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) ) {
				$plugin->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// CSS will still regenerate on the next front-end render.
		}
	}

	/**
	 * Clear Elementor's CSS cache for a post (or whole site).
	 *
	 * @param int|null $post_id Optional post ID.
	 * @return void
	 */
	public static function clear_cache( $post_id = null ) {
		if ( ! self::is_active() || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) ) {
				$plugin->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// no-op
		}
	}
}
