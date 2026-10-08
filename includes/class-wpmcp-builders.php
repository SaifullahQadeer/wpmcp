<?php
/**
 * Page builders: the block editor (Gutenberg) and Divi.
 *
 * Both are edited as text, which is what a language model handles best: block
 * markup for Gutenberg, shortcodes for Divi 4. Writes are validated first so a
 * malformed layout is refused instead of saved, and every write goes through the
 * change history so it can be rolled back.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Builders {
	const DIVI_SECTIONS = array( 'et_pb_section', 'et_pb_fullwidth_section', 'et_pb_specialty_section' );
	const DIVI_META     = array( '_et_pb_use_builder', '_et_pb_built_for_post_type', '_et_pb_old_content' );

	/* ----------------------------------------------------------------- */
	/* Detection                                                         */
	/* ----------------------------------------------------------------- */

	public static function divi_info() {
		$theme   = wp_get_theme();
		$parent  = $theme->parent() ? $theme->parent() : $theme;
		$is_theme = 'Divi' === $parent->get_template();
		$active  = defined( 'ET_BUILDER_VERSION' ) || $is_theme;
		$version = defined( 'ET_BUILDER_VERSION' ) ? (string) ET_BUILDER_VERSION : ( $is_theme ? (string) $parent->get( 'Version' ) : '' );
		return array(
			'active'     => $active,
			'version'    => $version,
			'source'     => $active ? ( $is_theme ? 'theme' : 'plugin' ) : null,
			'generation' => $active ? ( (int) $version >= 5 ? 'v5-blocks' : 'v4-shortcodes' ) : null,
		);
	}

	/** Which editor built this post: elementor, divi, gutenberg or classic. */
	public static function builder_for( $post_id ) {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) { return 'elementor'; }
		if ( 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) { return 'divi'; }
		$post = get_post( $post_id );
		return $post && has_blocks( $post->post_content ) ? 'gutenberg' : 'classic';
	}

	/** Everything wp_ping reports about editors. */
	public static function environment() {
		$theme = wp_get_theme();
		return array(
			'gutenberg' => array( 'active' => true, 'block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ),
			'divi'      => self::divi_info(),
			'theme'     => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
		);
	}

	private static function not_found() {
		return new WP_Error( 'wpmcp_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	private static function excerpt( $html, $max = 90 ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/** Rewrite a post's content through the history, so the change can be rolled back. */
	private static function save_content( $post_id, $content, $meta, $tool, $summary ) {
		$scope   = array( 'fields' => array( 'post_content' ), 'meta' => $meta, 'terms' => array(), 'thumbnail' => false );
		$history = WPMCP_History::begin_state( $post_id, $scope );
		$result  = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ), true );
		if ( is_wp_error( $result ) ) { return $result; }
		return $history ? array( $history, $tool, $summary ) : array( null, $tool, $summary );
	}

	private static function finish_save( $saved ) {
		if ( $saved[0] ) { WPMCP_History::finish_state( $saved[0], $saved[1], $saved[2] ); }
	}

	/* ----------------------------------------------------------------- */
	/* Gutenberg                                                         */
	/* ----------------------------------------------------------------- */

	public static function list_block_types( $search = '', $limit = 60 ) {
		$all = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$out = array();
		foreach ( $all as $name => $type ) {
			if ( '' !== $search && false === stripos( $name . ' ' . (string) $type->title, $search ) ) { continue; }
			$out[] = array(
				'name'       => $name,
				'title'      => (string) $type->title,
				'category'   => (string) $type->category,
				'attributes' => array_slice( array_keys( (array) $type->attributes ), 0, 25 ),
			);
			if ( count( $out ) >= $limit ) { break; }
		}
		return array( 'registered' => count( $all ), 'shown' => count( $out ), 'blocks' => $out );
	}

	/** Top-level blocks of a post, without the blank-line filler between them. */
	private static function top_blocks( $content ) {
		return array_values( array_filter( parse_blocks( $content ), function ( $b ) { return null !== $b['blockName'] || '' !== trim( $b['innerHTML'] ); } ) );
	}

	private static function describe( $block, $depth ) {
		$out = array( 'name' => $block['blockName'] ? $block['blockName'] : 'core/freeform', 'attrs' => (object) $block['attrs'], 'text' => self::excerpt( $block['innerHTML'] ) );
		if ( $block['innerBlocks'] ) {
			$out['inner_blocks'] = $depth > 0 ? array_map( function ( $b ) use ( $depth ) { return self::describe( $b, $depth - 1 ); }, $block['innerBlocks'] ) : count( $block['innerBlocks'] );
		}
		return $out;
	}

	public static function get_blocks( $post_id, $args = array() ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return self::not_found(); }
		$top  = self::top_blocks( $post->post_content );
		$base = array( 'id' => $post->ID, 'builder' => self::builder_for( $post->ID ), 'top_level_blocks' => count( $top ) );

		if ( ! empty( $args['summary'] ) ) {
			$rows = array();
			foreach ( $top as $i => $block ) {
				$rows[] = array(
					'index'        => $i,
					'name'         => $block['blockName'] ? $block['blockName'] : 'core/freeform',
					'attrs'        => array_slice( array_keys( $block['attrs'] ), 0, 8 ),
					'text'         => self::excerpt( serialize_block( $block ) ),
					'inner_blocks' => count( $block['innerBlocks'] ),
					'markup_chars' => strlen( serialize_block( $block ) ),
				);
			}
			return $base + array( 'view' => 'summary', 'blocks' => $rows );
		}
		if ( isset( $args['index'] ) && '' !== $args['index'] ) {
			$i = (int) $args['index'];
			if ( ! isset( $top[ $i ] ) ) { return new WP_Error( 'wpmcp_bad_index', sprintf( 'Block index %d does not exist. This post has %d top-level blocks.', $i, count( $top ) ), array( 'status' => 400 ) ); }
			$depth = isset( $args['depth'] ) ? max( 0, (int) $args['depth'] ) : 3;
			return $base + array( 'view' => 'block', 'index' => $i, 'markup' => serialize_block( $top[ $i ] ), 'structure' => self::describe( $top[ $i ], $depth ) );
		}
		return $base + array( 'view' => 'full', 'markup' => $post->post_content );
	}

	/**
	 * Check block markup before it is saved: every block comment must be closed in
	 * order and its attributes must be valid JSON. Unbalanced markup would show as
	 * "unexpected or invalid content" in the editor.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_blocks( $markup ) {
		preg_match_all( '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(\{.*?\}))?\s*(\/)?-->/s', $markup, $m, PREG_SET_ORDER );
		$stack = array();
		foreach ( $m as $tag ) {
			$closing = '/' === $tag[1];
			$self    = isset( $tag[4] ) && '/' === $tag[4];
			if ( ! empty( $tag[3] ) && null === json_decode( $tag[3], true ) ) {
				return new WP_Error( 'wpmcp_bad_block_attrs', sprintf( 'The attributes of a %s block are not valid JSON.', $tag[2] ), array( 'status' => 400 ) );
			}
			if ( $self ) { continue; }
			if ( ! $closing ) { $stack[] = $tag[2]; continue; }
			if ( ! $stack || array_pop( $stack ) !== $tag[2] ) {
				return new WP_Error( 'wpmcp_unbalanced_blocks', sprintf( 'The closing comment for wp:%s does not match an open block. Close blocks in the reverse order they were opened.', $tag[2] ), array( 'status' => 400 ) );
			}
		}
		if ( $stack ) { return new WP_Error( 'wpmcp_unbalanced_blocks', sprintf( 'The block wp:%s is never closed.', end( $stack ) ), array( 'status' => 400 ) ); }
		return true;
	}

	/** Combine existing and new top-level items by mode. Shared by Gutenberg and Divi. */
	private static function combine( $existing, $incoming, $mode, $index ) {
		if ( 'append' === $mode ) { return array_merge( $existing, $incoming ); }
		if ( 'prepend' === $mode ) { return array_merge( $incoming, $existing ); }
		if ( 'insert' === $mode ) {
			if ( $index < 0 || $index > count( $existing ) ) { return new WP_Error( 'wpmcp_bad_index', sprintf( 'Insert index %d is out of range (0-%d).', $index, count( $existing ) ), array( 'status' => 400 ) ); }
			return array_merge( array_slice( $existing, 0, $index ), $incoming, array_slice( $existing, $index ) );
		}
		if ( 'replace_item' === $mode ) {
			if ( ! isset( $existing[ $index ] ) ) { return new WP_Error( 'wpmcp_bad_index', sprintf( 'Index %d does not exist. There are %d top-level items.', $index, count( $existing ) ), array( 'status' => 400 ) ); }
			return array_merge( array_slice( $existing, 0, $index ), $incoming, array_slice( $existing, $index + 1 ) );
		}
		return $incoming;
	}

	public static function set_blocks( $post_id, $markup, $args = array() ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return self::not_found(); }
		if ( ! is_string( $markup ) || '' === trim( $markup ) ) { return new WP_Error( 'wpmcp_missing_arg', 'Provide "content" as block markup.', array( 'status' => 400 ) ); }
		$builder = self::builder_for( $post->ID );
		if ( in_array( $builder, array( 'elementor', 'divi' ), true ) && empty( $args['force'] ) ) {
			return new WP_Error( 'wpmcp_wrong_builder', sprintf( 'This page is built with %s. Use %s, or pass force=true to overwrite it with blocks.', ucfirst( $builder ), 'elementor' === $builder ? 'wp_set_elementor' : 'wp_set_divi' ), array( 'status' => 400 ) );
		}
		$valid = self::validate_blocks( $markup );
		if ( is_wp_error( $valid ) ) { return $valid; }

		$mode  = isset( $args['mode'] ) ? (string) $args['mode'] : 'replace';
		$mode  = 'replace_block' === $mode ? 'replace_item' : $mode;
		if ( ! in_array( $mode, array( 'replace', 'append', 'prepend', 'insert', 'replace_item' ), true ) ) { return new WP_Error( 'wpmcp_bad_mode', 'mode must be replace, append, prepend, insert or replace_block.', array( 'status' => 400 ) ); }
		$index = isset( $args['index'] ) ? (int) $args['index'] : 0;

		$incoming = self::top_blocks( $markup );
		$combined = self::combine( self::top_blocks( $post->post_content ), $incoming, $mode, $index );
		if ( is_wp_error( $combined ) ) { return $combined; }
		$content  = implode( "\n\n", array_map( 'serialize_block', $combined ) );

		$warnings = array();
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( $incoming as $block ) {
			if ( $block['blockName'] && ! $registry->is_registered( $block['blockName'] ) ) { $warnings[] = sprintf( 'Block %s is not registered on this site, so the editor will show it as missing.', $block['blockName'] ); }
			if ( null === $block['blockName'] ) { $warnings[] = 'Some content is outside any block and will appear as a Classic block.'; }
		}

		$saved = self::save_content( $post->ID, $content, array(), 'wp_set_blocks', sprintf( 'Changed the block content (%s)', $mode ) );
		if ( is_wp_error( $saved ) ) { return $saved; }
		self::finish_save( $saved );
		WPMCP_Site::clear_post_caches( $post->ID );
		return array( 'ok' => true, 'id' => $post->ID, 'mode' => $mode, 'top_level_blocks' => count( $combined ), 'warnings' => array_values( array_unique( $warnings ) ), 'link' => get_permalink( $post->ID ) );
	}

	/* ----------------------------------------------------------------- */
	/* Divi 4 (shortcodes)                                               */
	/* ----------------------------------------------------------------- */

	/**
	 * Split Divi shortcode content into its top-level sections and check nesting.
	 *
	 * @return array{sections:array<int,array{tag:string,raw:string}>,stray:string}|WP_Error
	 */
	public static function divi_sections( $content ) {
		preg_match_all( '/\[(\/?)(et_pb_[a-z0-9_]+)(?:\s[^\]]*?)?(\/)?\]/i', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$stack = array();
		$sections = array();
		$cursor = 0;
		$stray  = '';
		foreach ( $m as $tag ) {
			$whole   = $tag[0][0];
			$pos     = $tag[0][1];
			$closing = '' !== $tag[1][0];
			$name    = strtolower( $tag[2][0] );
			$self    = isset( $tag[3] ) && '/' === $tag[3][0];
			if ( $self ) { continue; }
			if ( ! $closing ) {
				if ( ! $stack ) {
					if ( ! in_array( $name, self::DIVI_SECTIONS, true ) ) { return new WP_Error( 'wpmcp_divi_structure', sprintf( 'Top-level [%s] found. Divi layouts must start with a section ([et_pb_section]).', $name ), array( 'status' => 400 ) ); }
					$stray .= substr( $content, $cursor, $pos - $cursor );
					$start = $pos;
				}
				$stack[] = $name;
				continue;
			}
			if ( ! $stack || array_pop( $stack ) !== $name ) { return new WP_Error( 'wpmcp_divi_unbalanced', sprintf( 'The closing [/%s] does not match an open shortcode. Close them in the reverse order they were opened.', $name ), array( 'status' => 400 ) ); }
			if ( ! $stack ) {
				$end        = $pos + strlen( $whole );
				$sections[] = array( 'tag' => $name, 'raw' => substr( $content, $start, $end - $start ) );
				$cursor     = $end;
			}
		}
		if ( $stack ) { return new WP_Error( 'wpmcp_divi_unbalanced', sprintf( 'The shortcode [%s] is never closed.', end( $stack ) ), array( 'status' => 400 ) ); }
		return array( 'sections' => $sections, 'stray' => trim( $stray . substr( $content, $cursor ) ) );
	}

	private static function divi_gate( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return self::not_found(); }
		$info = self::divi_info();
		if ( $info['active'] && 'v5-blocks' === $info['generation'] ) {
			return new WP_Error( 'wpmcp_divi5', 'This site runs Divi 5, which stores layouts as blocks. Use wp_get_blocks and wp_set_blocks instead.', array( 'status' => 400 ) );
		}
		return $post;
	}

	public static function get_divi( $post_id, $args = array() ) {
		$post = self::divi_gate( $post_id );
		if ( is_wp_error( $post ) ) { return $post; }
		$parsed = self::divi_sections( $post->post_content );
		$base   = array( 'id' => $post->ID, 'builder' => self::builder_for( $post->ID ), 'divi' => self::divi_info() );
		if ( is_wp_error( $parsed ) ) {
			return $base + array( 'view' => 'unparsed', 'problem' => $parsed->get_error_message(), 'content' => $post->post_content );
		}
		$sections = $parsed['sections'];
		if ( ! empty( $args['summary'] ) ) {
			$rows = array();
			foreach ( $sections as $i => $section ) {
				$rows[] = array(
					'index'   => $i,
					'type'    => $section['tag'],
					'rows'    => preg_match_all( '/\[et_pb_row[\s\]_]/i', $section['raw'] ) ?: 0,
					'modules' => max( 0, preg_match_all( '/\[(et_pb_(?!section|fullwidth_section|specialty_section|row|row_inner|column|column_inner)[a-z0-9_]+)[\s\]]/i', $section['raw'] ) ),
					'text'    => self::excerpt( preg_replace( '/\[[^\]]*\]/', ' ', $section['raw'] ) ),
					'chars'   => strlen( $section['raw'] ),
				);
			}
			return $base + array( 'view' => 'summary', 'sections' => $rows, 'stray_content' => '' !== $parsed['stray'] );
		}
		if ( isset( $args['index'] ) && '' !== $args['index'] ) {
			$i = (int) $args['index'];
			if ( ! isset( $sections[ $i ] ) ) { return new WP_Error( 'wpmcp_bad_index', sprintf( 'Section index %d does not exist. This page has %d sections.', $i, count( $sections ) ), array( 'status' => 400 ) ); }
			return $base + array( 'view' => 'section', 'index' => $i, 'content' => $sections[ $i ]['raw'] );
		}
		return $base + array( 'view' => 'full', 'sections_count' => count( $sections ), 'content' => $post->post_content );
	}

	public static function set_divi( $post_id, $content, $args = array() ) {
		$post = self::divi_gate( $post_id );
		if ( is_wp_error( $post ) ) { return $post; }
		if ( ! is_string( $content ) || '' === trim( $content ) ) { return new WP_Error( 'wpmcp_missing_arg', 'Provide "content" as Divi shortcodes.', array( 'status' => 400 ) ); }
		if ( 'elementor' === self::builder_for( $post->ID ) && empty( $args['force'] ) ) {
			return new WP_Error( 'wpmcp_wrong_builder', 'This page is built with Elementor. Use wp_set_elementor, or pass force=true to overwrite it with Divi.', array( 'status' => 400 ) );
		}
		$incoming = self::divi_sections( $content );
		if ( is_wp_error( $incoming ) ) { return $incoming; }
		if ( ! $incoming['sections'] ) { return new WP_Error( 'wpmcp_divi_structure', 'No [et_pb_section] found in the content.', array( 'status' => 400 ) ); }
		$existing = self::divi_sections( $post->post_content );
		$current  = is_wp_error( $existing ) || 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) ? array() : $existing['sections'];

		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'replace';
		$mode = 'replace_section' === $mode ? 'replace_item' : $mode;
		if ( ! in_array( $mode, array( 'replace', 'append', 'prepend', 'insert', 'replace_item' ), true ) ) { return new WP_Error( 'wpmcp_bad_mode', 'mode must be replace, append, prepend, insert or replace_section.', array( 'status' => 400 ) ); }
		$index = isset( $args['index'] ) ? (int) $args['index'] : 0;
		$combined = self::combine( array_column( $current, 'raw' ), array_column( $incoming['sections'], 'raw' ), $mode, $index );
		if ( is_wp_error( $combined ) ) { return $combined; }

		$saved = self::save_content( $post->ID, implode( "\n", $combined ), self::DIVI_META, 'wp_set_divi', sprintf( 'Changed the Divi layout (%s)', $mode ) );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( '' === (string) get_post_meta( $post->ID, '_et_pb_old_content', true ) && 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			update_post_meta( $post->ID, '_et_pb_old_content', wp_slash( $post->post_content ) ); // What Divi offers back if the builder is switched off.
		}
		update_post_meta( $post->ID, '_et_pb_use_builder', 'on' );
		if ( '' === (string) get_post_meta( $post->ID, '_et_pb_built_for_post_type', true ) ) { update_post_meta( $post->ID, '_et_pb_built_for_post_type', $post->post_type ); }
		self::finish_save( $saved );
		WPMCP_Site::clear_post_caches( $post->ID );
		$warnings = '' !== $incoming['stray'] ? array( 'Text outside any section was ignored.' ) : array();
		return array( 'ok' => true, 'id' => $post->ID, 'mode' => $mode, 'sections' => count( $combined ), 'warnings' => $warnings, 'link' => get_permalink( $post->ID ) );
	}
}
