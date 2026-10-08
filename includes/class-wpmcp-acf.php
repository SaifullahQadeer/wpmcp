<?php
/**
 * Advanced Custom Fields support, for the free plugin and ACF Pro.
 *
 * Everything goes through ACF's own public functions, so ACF registers post
 * types and taxonomies itself, refreshes the permalink rules, and saves fields
 * in the format its editor expects. A feature that needs Pro (repeaters, flexible
 * content, options pages) is used only when ACF reports it is available.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_ACF {
	const RESERVED_POST_TYPES = array(
		'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
		'action', 'author', 'order', 'theme',
	);
	const RESERVED_TAXONOMIES = array(
		'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
		'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'day', 'feed', 'hour', 'm', 'minute', 'monthnum', 'more',
		'name', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post_type', 'posts',
		'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'status', 'subpost', 'tag', 'taxonomy', 'tb', 'term',
		'terms', 'theme', 'title', 'type', 'w', 'year',
	);
	const SUPPORTS = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' );
	const FORBIDDEN_FIELD_KEYS = array( 'ID', 'parent', 'menu_order', '_valid', '_name', 'prefix', 'id', 'class', 'value', '_prepare', 'parent_layout' );

	/* ----------------------------------------------------------------- */
	/* Detection                                                         */
	/* ----------------------------------------------------------------- */

	public static function available() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_import_field_group' ) && function_exists( 'acf_get_fields' );
	}

	private static function setting( $name, $default = true ) {
		return function_exists( 'acf_get_setting' ) ? (bool) acf_get_setting( $name ) : $default;
	}

	public static function can_post_types() { return self::available() && function_exists( 'acf_update_post_type' ) && self::setting( 'enable_post_types' ); }
	public static function can_taxonomies() { return self::available() && function_exists( 'acf_update_taxonomy' ) && self::setting( 'enable_post_types' ); }
	public static function is_pro() { return function_exists( 'acf_is_pro' ) && acf_is_pro(); }

	public static function status() {
		if ( ! self::available() ) { return array( 'active' => false ); }
		$version = defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : ( function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'version' ) : '' );
		return array(
			'active'                => true,
			'version'               => $version,
			'edition'               => self::is_pro() ? 'pro' : 'free',
			'can_create_post_types' => self::can_post_types(),
			'can_create_taxonomies' => self::can_taxonomies(),
			'repeater_and_flexible' => self::is_pro(),
		);
	}

	private static function missing() {
		return new WP_Error( 'wpmcp_acf_missing', 'Advanced Custom Fields is not active on this site. Install and activate ACF (the free plugin or ACF Pro), then try again.', array( 'status' => 400 ) );
	}

	private static function bad( $message ) {
		return new WP_Error( 'wpmcp_acf_invalid', $message, array( 'status' => 400 ) );
	}

	private static function new_key( $prefix ) {
		return $prefix . substr( md5( uniqid( '', true ) ), 0, 13 );
	}

	private static function strip( $data ) {
		unset( $data['_valid'], $data['local'], $data['_acf_changed'] );
		return $data;
	}

	/* ----------------------------------------------------------------- */
	/* Reading                                                           */
	/* ----------------------------------------------------------------- */

	private static function rules_text( $location ) {
		$groups = array();
		foreach ( (array) $location as $group ) {
			$rules = array();
			foreach ( (array) $group as $rule ) { $rules[] = ( isset( $rule['param'] ) ? $rule['param'] : '?' ) . ' ' . ( isset( $rule['operator'] ) ? $rule['operator'] : '==' ) . ' ' . ( isset( $rule['value'] ) ? $rule['value'] : '' ); }
			if ( $rules ) { $groups[] = implode( ' and ', $rules ); }
		}
		return implode( ' or ', $groups );
	}

	private static function describe_fields( $fields ) {
		$out = array();
		foreach ( (array) $fields as $field ) {
			$row = array( 'key' => $field['key'], 'name' => $field['name'], 'label' => $field['label'], 'type' => $field['type'], 'required' => ! empty( $field['required'] ) );
			if ( ! empty( $field['choices'] ) ) { $row['choices'] = $field['choices']; }
			if ( ! empty( $field['sub_fields'] ) ) { $row['sub_fields'] = self::describe_fields( $field['sub_fields'] ); }
			if ( ! empty( $field['layouts'] ) ) {
				$row['layouts'] = array();
				foreach ( $field['layouts'] as $layout ) { $row['layouts'][] = array( 'key' => $layout['key'], 'name' => $layout['name'], 'label' => $layout['label'], 'sub_fields' => self::describe_fields( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() ) ); }
			}
			$out[] = $row;
		}
		return $out;
	}

	/** A field group in the format ACF itself exports and imports. */
	public static function export_group( $key ) {
		$group = acf_get_field_group( $key );
		if ( ! $group ) { return null; }
		$group['fields'] = acf_get_fields( $group );
		return self::strip( acf_prepare_field_group_for_export( $group ) );
	}

	public static function list_items( $what = '', $key = '', $include_fields = false ) {
		if ( ! self::available() ) { return self::missing(); }
		$what = (string) $what;
		if ( '' !== $key ) { return self::get_item( $what, $key ); }
		$out = array( 'acf' => self::status() );
		$all = '' === $what;

		if ( $all || 'field_groups' === $what ) {
			$rows = array();
			foreach ( acf_get_field_groups() as $group ) {
				$row = array( 'key' => $group['key'], 'title' => $group['title'], 'active' => ! empty( $group['active'] ), 'shown_when' => self::rules_text( $group['location'] ), 'fields_count' => function_exists( 'acf_get_field_count' ) ? (int) acf_get_field_count( $group ) : count( (array) acf_get_fields( $group ) ) );
				if ( $include_fields ) { $row['fields'] = self::describe_fields( acf_get_fields( $group ) ); }
				$rows[] = $row;
			}
			$out['field_groups'] = $rows;
		}
		if ( $all || 'post_types' === $what ) {
			$rows = array();
			if ( function_exists( 'acf_get_acf_post_types' ) ) {
				foreach ( acf_get_acf_post_types() as $pt ) {
					$rows[] = array( 'key' => $pt['key'], 'post_type' => $pt['post_type'], 'title' => $pt['title'], 'active' => ! empty( $pt['active'] ), 'registered' => post_type_exists( $pt['post_type'] ), 'public' => ! empty( $pt['public'] ), 'hierarchical' => ! empty( $pt['hierarchical'] ), 'has_archive' => ! empty( $pt['has_archive'] ), 'supports' => $pt['supports'], 'taxonomies' => $pt['taxonomies'], 'show_in_rest' => ! empty( $pt['show_in_rest'] ) );
				}
			}
			$out['post_types'] = $rows;
		}
		if ( $all || 'taxonomies' === $what ) {
			$rows = array();
			if ( function_exists( 'acf_get_acf_taxonomies' ) ) {
				foreach ( acf_get_acf_taxonomies() as $tx ) {
					$rows[] = array( 'key' => $tx['key'], 'taxonomy' => $tx['taxonomy'], 'title' => $tx['title'], 'active' => ! empty( $tx['active'] ), 'registered' => taxonomy_exists( $tx['taxonomy'] ), 'object_type' => $tx['object_type'], 'hierarchical' => ! empty( $tx['hierarchical'] ), 'show_in_rest' => ! empty( $tx['show_in_rest'] ) );
				}
			}
			$out['taxonomies'] = $rows;
		}
		if ( $all || 'options_pages' === $what ) {
			$rows = array();
			if ( function_exists( 'acf_get_options_pages' ) ) {
				foreach ( (array) acf_get_options_pages() as $page ) { $rows[] = array( 'title' => isset( $page['page_title'] ) ? $page['page_title'] : '', 'menu_slug' => isset( $page['menu_slug'] ) ? $page['menu_slug'] : '', 'post_id' => isset( $page['post_id'] ) ? $page['post_id'] : 'options' ); }
			}
			$out['options_pages'] = $rows;
			$out['options_pages_note'] = self::is_pro() ? 'Read and write values on an options page with wp_acf_get_values and wp_acf_set_values using id "option" (or the page\'s post_id).' : 'Options pages need ACF Pro.';
		}
		if ( $all || 'field_types' === $what ) {
			$available = function_exists( 'acf_get_field_types' ) ? array_keys( (array) acf_get_field_types() ) : array();
			$pro       = function_exists( 'acf_get_pro_field_types' ) ? array_keys( (array) acf_get_pro_field_types() ) : array();
			$out['field_types'] = array( 'available' => array_values( $available ), 'needs_pro' => array_values( array_diff( $pro, $available ) ) );
		}
		return $out;
	}

	private static function get_item( $what, $key ) {
		if ( 'field_groups' === $what ) { $g = self::export_group( $key ); return $g ? $g : self::bad( 'No field group with that key.' ); }
		if ( 'post_types' === $what && function_exists( 'acf_get_post_type' ) ) { $p = acf_get_post_type( $key ); return $p ? self::strip( $p ) : self::bad( 'No ACF post type with that key.' ); }
		if ( 'taxonomies' === $what && function_exists( 'acf_get_taxonomy' ) ) { $t = acf_get_taxonomy( $key ); return $t ? self::strip( $t ) : self::bad( 'No ACF taxonomy with that key.' ); }
		return self::bad( 'To read one item, set "what" to field_groups, post_types or taxonomies together with its key.' );
	}

	/* ----------------------------------------------------------------- */
	/* Shared setting helpers                                            */
	/* ----------------------------------------------------------------- */

	private static function labels_post_type( $s, $p ) {
		$sl = mb_strtolower( $s ); $pl = mb_strtolower( $p );
		return array(
			'name' => $p, 'singular_name' => $s, 'menu_name' => $p, 'all_items' => "All $p", 'add_new' => 'Add New', 'add_new_item' => "Add New $s",
			'edit_item' => "Edit $s", 'new_item' => "New $s", 'view_item' => "View $s", 'view_items' => "View $p", 'search_items' => "Search $p",
			'not_found' => "No $pl found", 'not_found_in_trash' => "No $pl found in Trash", 'parent_item_colon' => "Parent $s:", 'archives' => "$s Archives",
			'attributes' => "$s Attributes", 'featured_image' => 'Featured image', 'set_featured_image' => 'Set featured image', 'remove_featured_image' => 'Remove featured image',
			'use_featured_image' => 'Use as featured image', 'insert_into_item' => "Insert into $sl", 'uploaded_to_this_item' => "Uploaded to this $sl",
			'filter_items_list' => "Filter $pl list", 'items_list_navigation' => "$p list navigation", 'items_list' => "$p list", 'item_published' => "$s published.",
			'item_published_privately' => "$s published privately.", 'item_reverted_to_draft' => "$s reverted to draft.", 'item_scheduled' => "$s scheduled.", 'item_updated' => "$s updated.",
		);
	}

	private static function labels_taxonomy( $s, $p ) {
		$pl = mb_strtolower( $p );
		return array(
			'name' => $p, 'singular_name' => $s, 'menu_name' => $p, 'search_items' => "Search $p", 'popular_items' => "Popular $p", 'all_items' => "All $p",
			'parent_item' => "Parent $s", 'parent_item_colon' => "Parent $s:", 'edit_item' => "Edit $s", 'view_item' => "View $s", 'update_item' => "Update $s",
			'add_new_item' => "Add New $s", 'new_item_name' => "New $s Name", 'separate_items_with_commas' => "Separate $pl with commas", 'add_or_remove_items' => "Add or remove $pl",
			'choose_from_most_used' => "Choose from the most used $pl", 'not_found' => "No $pl found", 'no_terms' => "No $pl", 'items_list_navigation' => "$p list navigation",
			'items_list' => "$p list", 'most_used' => 'Most Used', 'back_to_items' => "← Go to $pl",
		);
	}

	/** Check the settings an app may pass for a post type or taxonomy and return them in ACF's shape. */
	private static function clean_settings( $in, $kind ) {
		if ( ! is_array( $in ) ) { return array(); }
		$bools   = array( 'public', 'hierarchical', 'exclude_from_search', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_admin_bar', 'show_in_nav_menus', 'show_in_rest', 'has_archive', 'can_export', 'delete_with_user', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column', 'active' );
		$strings = array( 'description', 'rest_base', 'admin_menu_parent', 'has_archive_slug', 'enter_title_here', 'query_var_name' );
		$allowed = array_merge( $bools, $strings, array( 'supports', 'taxonomies', 'object_type', 'menu_position', 'menu_icon', 'rewrite', 'query_var', 'labels', 'menu_order' ) );
		$out     = array();
		foreach ( $in as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) ) { return self::bad( sprintf( '"%s" is not a setting that can be set here. Allowed: %s.', $name, implode( ', ', $allowed ) ) ); }
			if ( in_array( $name, $bools, true ) ) { $out[ $name ] = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
			elseif ( in_array( $name, $strings, true ) ) { $out[ $name ] = sanitize_text_field( (string) $value ); }
			elseif ( 'menu_position' === $name || 'menu_order' === $name ) { $out[ $name ] = (int) $value; }
			elseif ( 'supports' === $name ) {
				$bad = array_diff( (array) $value, self::SUPPORTS );
				if ( $bad ) { return self::bad( sprintf( 'Unknown supports value(s): %s. Allowed: %s.', implode( ', ', $bad ), implode( ', ', self::SUPPORTS ) ) ); }
				$out['supports'] = array_values( array_unique( array_map( 'strval', (array) $value ) ) );
			} elseif ( 'taxonomies' === $name ) {
				foreach ( (array) $value as $tax ) { if ( ! taxonomy_exists( $tax ) ) { return self::bad( sprintf( 'Taxonomy "%s" does not exist yet. Create it first.', $tax ) ); } }
				$out['taxonomies'] = array_values( array_map( 'strval', (array) $value ) );
			} elseif ( 'object_type' === $name ) {
				foreach ( (array) $value as $pt ) { if ( ! post_type_exists( $pt ) ) { return self::bad( sprintf( 'Post type "%s" does not exist yet. Create it first.', $pt ) ); } }
				$out['object_type'] = array_values( array_map( 'strval', (array) $value ) );
			} elseif ( 'menu_icon' === $name ) {
				$icon = is_string( $value ) ? trim( $value ) : '';
				if ( '' !== $icon && ! preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) && ! preg_match( '#^https?://#i', $icon ) ) { return self::bad( 'menu_icon must be a dashicon name such as dashicons-book or an https image URL.' ); }
				$out['menu_icon'] = $icon;
			} elseif ( 'rewrite' === $name ) {
				$rw = array();
				foreach ( (array) $value as $k => $v ) {
					if ( 'slug' === $k ) { $rw['slug'] = sanitize_title( (string) $v ); }
					elseif ( in_array( $k, array( 'with_front', 'feeds', 'pages', 'rewrite_hierarchical' ), true ) ) { $rw[ $k ] = (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN ); }
					elseif ( 'permalink_rewrite' === $k && in_array( $v, array( 'post_type_key', 'taxonomy_key', 'custom_permalink', 'no_permalink' ), true ) ) { $rw[ $k ] = $v; }
					else { return self::bad( 'rewrite accepts slug, with_front, feeds, pages, rewrite_hierarchical and permalink_rewrite.' ); }
				}
				if ( ! empty( $rw['slug'] ) && empty( $rw['permalink_rewrite'] ) ) { $rw['permalink_rewrite'] = 'custom_permalink'; }
				$out['rewrite'] = $rw;
			} elseif ( 'query_var' === $name ) {
				if ( ! in_array( $value, array( 'post_type_key', 'taxonomy_key', 'custom_query_var', 'none' ), true ) ) { return self::bad( 'query_var must be post_type_key, taxonomy_key, custom_query_var or none.' ); }
				$out['query_var'] = $value;
			} elseif ( 'labels' === $name ) {
				$out['labels'] = array_map( 'sanitize_text_field', array_filter( (array) $value, 'is_scalar' ) );
			}
		}
		return $out;
	}

	/** Merge new settings into an ACF array, deep for the nested rewrite and labels groups. */
	private static function merge( $base, $new ) {
		foreach ( $new as $k => $v ) { $base[ $k ] = ( in_array( $k, array( 'rewrite', 'labels' ), true ) && is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) ? array_merge( $base[ $k ], $v ) : $v; }
		return $base;
	}

	private static function find_by( $items, $field, $value ) {
		foreach ( (array) $items as $item ) { if ( isset( $item[ $field ] ) && $item[ $field ] === $value ) { return $item; } }
		return null;
	}

	/* ----------------------------------------------------------------- */
	/* Post types and taxonomies                                         */
	/* ----------------------------------------------------------------- */

	public static function save_post_type( $a ) {
		if ( ! self::available() ) { return self::missing(); }
		if ( ! self::can_post_types() ) { return self::bad( 'ACF custom post types need ACF 6.1 or newer, and the "enable_post_types" setting must be on.' ); }
		$slug = isset( $a['post_type'] ) ? (string) $a['post_type'] : '';
		if ( ! preg_match( '/^[a-z0-9_-]{1,20}$/', $slug ) ) { return self::bad( 'post_type must be 1 to 20 characters: lowercase letters, numbers, underscores or dashes.' ); }
		if ( in_array( $slug, self::RESERVED_POST_TYPES, true ) || 0 === strpos( $slug, 'acf-' ) || 0 === strpos( $slug, 'wp_' ) ) { return self::bad( sprintf( '"%s" is reserved by WordPress or ACF. Pick another post_type.', $slug ) ); }

		$existing = self::find_by( acf_get_acf_post_types(), 'post_type', $slug );
		if ( ! $existing && post_type_exists( $slug ) ) { return self::bad( sprintf( 'A post type "%s" is already registered by WordPress or another plugin, not by ACF. Choose a different name.', $slug ) ); }
		$settings = self::clean_settings( isset( $a['settings'] ) ? $a['settings'] : array(), 'post_type' );
		if ( is_wp_error( $settings ) ) { return $settings; }
		if ( isset( $a['active'] ) ) { $settings['active'] = (bool) filter_var( $a['active'], FILTER_VALIDATE_BOOLEAN ); }
		if ( isset( $settings['object_type'] ) ) { return self::bad( 'object_type belongs to taxonomies. Use "taxonomies" to attach taxonomies to a post type.' ); }

		$singular = isset( $a['singular'] ) ? sanitize_text_field( (string) $a['singular'] ) : '';
		$plural   = isset( $a['plural'] ) ? sanitize_text_field( (string) $a['plural'] ) : '';
		if ( ! $existing && ( '' === $singular || '' === $plural ) ) { return self::bad( 'Provide "singular" and "plural" display names, for example Book and Books.' ); }

		if ( $existing ) {
			$before = self::strip( $existing );
			$cfg    = $existing;
			if ( '' !== $singular || '' !== $plural ) {
				$cfg['labels'] = self::merge( $cfg['labels'], self::labels_post_type( '' !== $singular ? $singular : $cfg['labels']['singular_name'], '' !== $plural ? $plural : $cfg['labels']['name'] ) );
				if ( '' !== $plural ) { $cfg['title'] = $plural; }
			}
		} else {
			$before = null;
			$defaults = function_exists( 'acf_validate_post_type' ) ? acf_validate_post_type( array() ) : array();
			$cfg    = array_merge( $defaults, array( 'ID' => 0, 'key' => self::new_key( 'post_type_' ), 'title' => $plural, 'post_type' => $slug, 'active' => true, 'advanced_configuration' => true, 'labels' => self::labels_post_type( $singular, $plural ) ) );
			unset( $cfg['_valid'] );
		}
		$cfg = self::merge( $cfg, $settings );
		$cfg['register_meta_box_cb'] = isset( $existing['register_meta_box_cb'] ) ? $existing['register_meta_box_cb'] : ''; // A callback name is code to run, so it is never set from here.
		if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $existing, 'config' => self::strip( $cfg ) ); }

		$saved = acf_update_post_type( $cfg );
		if ( empty( $saved['ID'] ) ) { return self::bad( 'ACF did not save the post type.' ); }
		WPMCP_History::record(
			'wp_acf_save_post_type', 'acf', (int) $saved['ID'], $saved['title'], ( $existing ? 'Updated' : 'Created' ) . ' ACF post type ' . $slug,
			$existing ? array( 'op' => 'acf_restore', 'kind' => 'post_type', 'data' => $before ) : array( 'op' => 'acf_delete', 'kind' => 'post_type', 'key' => $saved['key'] )
		);
		return array(
			'ok' => true, 'created' => ! $existing, 'key' => $saved['key'], 'post_type' => $slug, 'registered' => post_type_exists( $slug ),
			'rest_base' => ! empty( $saved['rest_base'] ) ? $saved['rest_base'] : $slug, 'admin_url' => admin_url( 'edit.php?post_type=' . $slug ),
			'next' => 'Add fields with wp_acf_save_field_group (location: post_type ' . $slug . '), then create items with wp_create_content type "' . $slug . '".',
		);
	}

	public static function save_taxonomy( $a ) {
		if ( ! self::available() ) { return self::missing(); }
		if ( ! self::can_taxonomies() ) { return self::bad( 'ACF taxonomies need ACF 6.1 or newer, and the "enable_post_types" setting must be on.' ); }
		$slug = isset( $a['taxonomy'] ) ? (string) $a['taxonomy'] : '';
		if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/', $slug ) ) { return self::bad( 'taxonomy must be 1 to 32 characters: lowercase letters, numbers, underscores or dashes.' ); }
		if ( in_array( $slug, self::RESERVED_TAXONOMIES, true ) || 0 === strpos( $slug, 'acf-' ) ) { return self::bad( sprintf( '"%s" is reserved by WordPress or ACF. Pick another taxonomy name.', $slug ) ); }

		$existing = self::find_by( acf_get_acf_taxonomies(), 'taxonomy', $slug );
		if ( ! $existing && taxonomy_exists( $slug ) ) { return self::bad( sprintf( 'A taxonomy "%s" is already registered by WordPress or another plugin, not by ACF. Choose a different name.', $slug ) ); }
		$settings = self::clean_settings( isset( $a['settings'] ) ? $a['settings'] : array(), 'taxonomy' );
		if ( is_wp_error( $settings ) ) { return $settings; }
		if ( isset( $a['active'] ) ) { $settings['active'] = (bool) filter_var( $a['active'], FILTER_VALIDATE_BOOLEAN ); }
		if ( isset( $a['post_types'] ) ) {
			$types = self::clean_settings( array( 'object_type' => (array) $a['post_types'] ), 'taxonomy' );
			if ( is_wp_error( $types ) ) { return $types; }
			$settings['object_type'] = $types['object_type'];
		}
		unset( $settings['taxonomies'], $settings['supports'] );

		$singular = isset( $a['singular'] ) ? sanitize_text_field( (string) $a['singular'] ) : '';
		$plural   = isset( $a['plural'] ) ? sanitize_text_field( (string) $a['plural'] ) : '';
		if ( ! $existing && ( '' === $singular || '' === $plural ) ) { return self::bad( 'Provide "singular" and "plural" display names, for example Genre and Genres.' ); }
		if ( ! $existing && empty( $settings['object_type'] ) ) { return self::bad( 'Provide "post_types": the post types this taxonomy applies to, for example ["post"] or ["book"].' ); }

		if ( $existing ) {
			$before = self::strip( $existing );
			$cfg    = $existing;
			if ( '' !== $singular || '' !== $plural ) {
				$cfg['labels'] = self::merge( $cfg['labels'], self::labels_taxonomy( '' !== $singular ? $singular : $cfg['labels']['singular_name'], '' !== $plural ? $plural : $cfg['labels']['name'] ) );
				if ( '' !== $plural ) { $cfg['title'] = $plural; }
			}
		} else {
			$before   = null;
			$defaults = function_exists( 'acf_validate_taxonomy' ) ? acf_validate_taxonomy( array() ) : array();
			$cfg      = array_merge( $defaults, array( 'ID' => 0, 'key' => self::new_key( 'taxonomy_' ), 'title' => $plural, 'taxonomy' => $slug, 'active' => true, 'advanced_configuration' => true, 'labels' => self::labels_taxonomy( $singular, $plural ) ) );
			unset( $cfg['_valid'] );
		}
		$cfg = self::merge( $cfg, $settings );
		if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $existing, 'config' => self::strip( $cfg ) ); }

		$saved = acf_update_taxonomy( $cfg );
		if ( empty( $saved['ID'] ) ) { return self::bad( 'ACF did not save the taxonomy.' ); }
		WPMCP_History::record(
			'wp_acf_save_taxonomy', 'acf', (int) $saved['ID'], $saved['title'], ( $existing ? 'Updated' : 'Created' ) . ' ACF taxonomy ' . $slug,
			$existing ? array( 'op' => 'acf_restore', 'kind' => 'taxonomy', 'data' => $before ) : array( 'op' => 'acf_delete', 'kind' => 'taxonomy', 'key' => $saved['key'] )
		);
		return array( 'ok' => true, 'created' => ! $existing, 'key' => $saved['key'], 'taxonomy' => $slug, 'registered' => taxonomy_exists( $slug ), 'post_types' => $saved['object_type'], 'next' => 'Assign terms with the terms field of wp_create_content, or create terms with wp_create_term.' );
	}

	/* ----------------------------------------------------------------- */
	/* Field groups                                                      */
	/* ----------------------------------------------------------------- */

	private static function field_type_error( $type ) {
		if ( function_exists( 'acf_get_field_type' ) && acf_get_field_type( $type ) ) { return null; }
		$pro = function_exists( 'acf_get_pro_field_types' ) ? array_keys( (array) acf_get_pro_field_types() ) : array();
		if ( in_array( $type, $pro, true ) ) { return self::bad( sprintf( 'The "%s" field type needs ACF Pro, which is not active on this site.', $type ) ); }
		$known = function_exists( 'acf_get_field_types' ) ? implode( ', ', array_keys( (array) acf_get_field_types() ) ) : '';
		return self::bad( sprintf( 'Unknown field type "%s". Available: %s.', $type, $known ) );
	}

	/** Give every field a key and a valid name, check its type, and tidy options, recursing into sub fields and layouts. */
	private static function normalize_fields( $fields, $where = 'fields' ) {
		if ( ! is_array( $fields ) ) { return self::bad( "$where must be a list of fields." ); }
		$out   = array();
		$names = array();
		foreach ( array_values( $fields ) as $i => $field ) {
			if ( ! is_array( $field ) ) { return self::bad( "Each entry in $where must be an object describing one field." ); }
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$err  = self::field_type_error( $type );
			if ( $err ) { return $err; }
			$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
			$name  = isset( $field['name'] ) ? (string) $field['name'] : str_replace( '-', '_', sanitize_title( $label ) );
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $name ) ) { return self::bad( sprintf( 'Field %d in %s needs a "name" of lowercase letters, numbers and underscores (or a label to make one from).', $i + 1, $where ) ); }
			if ( isset( $names[ $name ] ) ) { return self::bad( sprintf( 'Two fields in %s are named "%s". Names must be unique at each level.', $where, $name ) ); }
			$names[ $name ] = true;
			$key = isset( $field['key'] ) ? (string) $field['key'] : '';
			if ( '' !== $key && ! preg_match( '/^field_[a-z0-9]+$/', $key ) ) { return self::bad( sprintf( 'Field key "%s" must look like field_abc123.', $key ) ); }
			$clean = array_diff_key( $field, array_flip( self::FORBIDDEN_FIELD_KEYS ) );
			$clean = array_merge( $clean, array( 'key' => '' !== $key ? $key : self::new_key( 'field_' ), 'label' => '' !== $label ? $label : $name, 'name' => $name, 'type' => $type ) );
			if ( isset( $clean['choices'] ) && is_array( $clean['choices'] ) && array_values( $clean['choices'] ) === $clean['choices'] ) { $clean['choices'] = array_combine( array_map( 'strval', $clean['choices'] ), array_map( 'strval', $clean['choices'] ) ); } // A plain list becomes value => label.
			if ( isset( $clean['sub_fields'] ) ) {
				$clean['sub_fields'] = self::normalize_fields( $clean['sub_fields'], "$name sub_fields" );
				if ( is_wp_error( $clean['sub_fields'] ) ) { return $clean['sub_fields']; }
			}
			if ( isset( $clean['layouts'] ) ) {
				if ( ! is_array( $clean['layouts'] ) ) { return self::bad( "$name layouts must be a list." ); }
				$layouts = array();
				foreach ( array_values( $clean['layouts'] ) as $layout ) {
					if ( ! is_array( $layout ) || empty( $layout['name'] ) ) { return self::bad( "Each layout of $name needs a name." ); }
					$lkey = isset( $layout['key'] ) && preg_match( '/^layout_[a-z0-9]+$/', (string) $layout['key'] ) ? (string) $layout['key'] : self::new_key( 'layout_' );
					$subs = self::normalize_fields( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array(), $name . ' layout ' . $layout['name'] );
					if ( is_wp_error( $subs ) ) { return $subs; }
					$layouts[ $lkey ] = array( 'key' => $lkey, 'name' => (string) $layout['name'], 'label' => isset( $layout['label'] ) ? (string) $layout['label'] : (string) $layout['name'], 'display' => isset( $layout['display'] ) ? (string) $layout['display'] : 'block', 'sub_fields' => $subs, 'min' => isset( $layout['min'] ) ? $layout['min'] : '', 'max' => isset( $layout['max'] ) ? $layout['max'] : '' );
				}
				$clean['layouts'] = $layouts;
			}
			$out[] = $clean;
		}
		return $out;
	}

	private static function clean_location( $a ) {
		$location = isset( $a['location'] ) ? $a['location'] : null;
		if ( null === $location && ! empty( $a['post_type'] ) ) {
			$location = array();
			foreach ( (array) $a['post_type'] as $pt ) { $location[] = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => (string) $pt ) ); }
		}
		if ( null === $location ) { return null; }
		if ( ! is_array( $location ) || ! $location ) { return self::bad( 'location must be a list of rule groups: [[{"param":"post_type","operator":"==","value":"book"}]], or use "post_type": "book".' ); }
		foreach ( $location as $group ) {
			if ( ! is_array( $group ) || ! $group ) { return self::bad( 'Each location group must be a list of rules.' ); }
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) || ! isset( $rule['value'] ) || ! in_array( isset( $rule['operator'] ) ? $rule['operator'] : '==', array( '==', '!=' ), true ) ) { return self::bad( 'Each location rule needs param and value, and an operator of == or !=.' ); }
			}
		}
		return array_values( array_map( function ( $group ) { return array_values( array_map( function ( $rule ) { return array( 'param' => (string) $rule['param'], 'operator' => isset( $rule['operator'] ) ? $rule['operator'] : '==', 'value' => (string) $rule['value'] ); }, $group ) ); }, $location ) );
	}

	public static function save_field_group( $a ) {
		if ( ! self::available() ) { return self::missing(); }
		$key      = isset( $a['key'] ) ? (string) $a['key'] : '';
		if ( '' !== $key && ! preg_match( '/^group_[a-z0-9]+$/', $key ) ) { return self::bad( 'A field group key must look like group_abc123.' ); }
		$existing = '' !== $key ? acf_get_field_group( $key ) : null;
		$title    = isset( $a['title'] ) ? sanitize_text_field( (string) $a['title'] ) : '';
		if ( ! $existing && '' === $title ) { return self::bad( 'Provide a "title" for the field group.' ); }

		$location = self::clean_location( $a );
		if ( is_wp_error( $location ) ) { return $location; }
		if ( ! $existing && null === $location ) { return self::bad( 'Say where the fields appear: "post_type": "book", or a "location" list.' ); }

		$before = $existing ? self::export_group( $existing['key'] ) : null;
		if ( isset( $a['fields'] ) ) {
			$fields = self::normalize_fields( $a['fields'] );
			if ( is_wp_error( $fields ) ) { return $fields; }
		} elseif ( $existing ) {
			$fields = $before['fields'];
		} else {
			return self::bad( 'Provide "fields": a list of field definitions with name, label and type.' );
		}
		if ( ! $existing && ! $fields ) { return self::bad( 'A new field group needs at least one field.' ); }

		$group = $existing ? $before : array( 'key' => '' !== $key ? $key : self::new_key( 'group_' ) );
		$group['key']    = $existing ? $existing['key'] : $group['key'];
		$group['title']  = '' !== $title ? $title : $group['title'];
		$group['fields'] = $fields;
		if ( null !== $location ) { $group['location'] = $location; }
		foreach ( array( 'position' => array( 'acf_after_title', 'normal', 'side' ), 'style' => array( 'default', 'seamless' ), 'label_placement' => array( 'top', 'left' ), 'instruction_placement' => array( 'label', 'field' ) ) as $setting => $choices ) {
			if ( isset( $a[ $setting ] ) ) {
				if ( ! in_array( $a[ $setting ], $choices, true ) ) { return self::bad( sprintf( '%s must be one of: %s.', $setting, implode( ', ', $choices ) ) ); }
				$group[ $setting ] = $a[ $setting ];
			}
		}
		foreach ( array( 'active', 'show_in_rest' ) as $flag ) { if ( isset( $a[ $flag ] ) ) { $group[ $flag ] = (bool) filter_var( $a[ $flag ], FILTER_VALIDATE_BOOLEAN ); } }
		if ( isset( $a['hide_on_screen'] ) ) { $group['hide_on_screen'] = array_values( array_map( 'strval', (array) $a['hide_on_screen'] ) ); }
		if ( isset( $a['description'] ) ) { $group['description'] = sanitize_text_field( (string) $a['description'] ); }
		if ( $existing ) { $group['ID'] = $existing['ID']; }
		if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $existing, 'group' => $group ); }

		$saved = acf_import_field_group( $group );
		if ( empty( $saved['ID'] ) ) { return self::bad( 'ACF did not save the field group.' ); }
		WPMCP_History::record(
			'wp_acf_save_field_group', 'acf', (int) $saved['ID'], $saved['title'], ( $existing ? 'Updated' : 'Created' ) . ' ACF field group',
			$existing ? array( 'op' => 'acf_restore', 'kind' => 'field_group', 'data' => $before ) : array( 'op' => 'acf_delete', 'kind' => 'field_group', 'key' => $saved['key'] )
		);
		return array( 'ok' => true, 'created' => ! $existing, 'key' => $saved['key'], 'title' => $saved['title'], 'fields' => self::describe_fields( acf_get_fields( $saved['key'] ) ), 'shown_when' => self::rules_text( isset( $saved['location'] ) ? $saved['location'] : array() ), 'next' => 'Set values with wp_acf_set_values, or pass "acf" when creating content.' );
	}

	/* ----------------------------------------------------------------- */
	/* Deleting and undoing                                              */
	/* ----------------------------------------------------------------- */

	private static function kind_info( $kind ) {
		$map = array(
			'post_type'   => array( 'get' => 'acf_get_post_type', 'update' => 'acf_update_post_type', 'delete' => 'acf_delete_post_type', 'title' => 'post type' ),
			'taxonomy'    => array( 'get' => 'acf_get_taxonomy', 'update' => 'acf_update_taxonomy', 'delete' => 'acf_delete_taxonomy', 'title' => 'taxonomy' ),
			'field_group' => array( 'get' => 'acf_get_field_group', 'update' => null, 'delete' => 'acf_delete_field_group', 'title' => 'field group' ),
		);
		return isset( $map[ $kind ] ) ? $map[ $kind ] : null;
	}

	public static function delete_item( $kind, $key ) {
		if ( ! self::available() ) { return self::missing(); }
		$info = self::kind_info( $kind );
		if ( ! $info || ! function_exists( $info['delete'] ) || ! function_exists( $info['get'] ) ) { return self::bad( 'kind must be post_type, taxonomy or field_group, and ACF must support it.' ); }
		$item = call_user_func( $info['get'], $key );
		if ( ! $item ) { return self::bad( sprintf( 'No ACF %s with that key.', $info['title'] ) ); }
		$before = 'field_group' === $kind ? self::export_group( $item['key'] ) : self::strip( $item );
		if ( ! call_user_func( $info['delete'], $item['key'] ) ) { return self::bad( 'ACF could not delete it.' ); }
		$label = isset( $item['title'] ) ? $item['title'] : $key;
		WPMCP_History::record( 'wp_acf_delete', 'acf', (int) $item['ID'], $label, 'Deleted ACF ' . $info['title'] . ' ' . $label, array( 'op' => 'acf_restore', 'kind' => $kind, 'data' => $before ) );
		return array( 'ok' => true, 'deleted' => $item['key'], 'note' => 'Content already saved with it stays in the database. Roll this back from History to restore the definition.' );
	}

	/** Undo a created definition. */
	public static function undo_create( $kind, $key ) {
		$info = self::kind_info( $kind );
		if ( ! self::available() || ! $info || ! function_exists( $info['delete'] ) ) { return self::missing(); }
		return call_user_func( $info['delete'], $key ) ? true : new WP_Error( 'wpmcp_gone', 'It no longer exists.' );
	}

	/** Put a saved definition back, as an update when it still exists or as a new one when it does not. */
	public static function restore( $kind, $data ) {
		if ( ! self::available() || ! is_array( $data ) ) { return self::missing(); }
		if ( 'field_group' === $kind ) {
			$current = acf_get_field_group( $data['key'] );
			$data['ID'] = $current ? $current['ID'] : 0;
			$saved = acf_import_field_group( $data );
			return empty( $saved['ID'] ) ? new WP_Error( 'wpmcp_failed', 'ACF did not restore the field group.' ) : true;
		}
		$info = self::kind_info( $kind );
		if ( ! $info || ! function_exists( $info['update'] ) ) { return self::missing(); }
		$current    = call_user_func( $info['get'], $data['key'] );
		$data['ID'] = $current ? $current['ID'] : 0;
		$saved      = call_user_func( $info['update'], $data );
		return empty( $saved['ID'] ) ? new WP_Error( 'wpmcp_failed', 'ACF did not restore it.' ) : true;
	}

	/* ----------------------------------------------------------------- */
	/* Values                                                            */
	/* ----------------------------------------------------------------- */

	/** A post ID, "option" for options pages, user_N, or taxonomy_N. */
	private static function target( $id ) {
		if ( is_numeric( $id ) ) { return get_post( (int) $id ) ? (int) $id : self::bad( 'No post with that ID.' ); }
		$id = is_string( $id ) ? trim( $id ) : '';
		if ( 'options' === $id ) { $id = 'option'; }
		return preg_match( '/^(option|user_\d+|[a-z0-9_-]+_\d+)$/', $id ) ? $id : self::bad( 'id must be a post ID, "option" for an options page, user_{id}, or {taxonomy}_{term id}.' );
	}

	private static function raw_values( $target, $names ) {
		$out = array();
		foreach ( $names as $name ) { $v = get_field( $name, $target, false ); $out[ $name ] = ( null === $v || false === $v ) ? null : $v; }
		return $out;
	}

	public static function get_values( $id, $format = false ) {
		if ( ! self::available() || ! function_exists( 'get_fields' ) ) { return self::missing(); }
		$target = self::target( $id );
		if ( is_wp_error( $target ) ) { return $target; }
		$values = get_fields( $target, (bool) $format );
		return array( 'id' => $target, 'formatted' => (bool) $format, 'count' => is_array( $values ) ? count( $values ) : 0, 'values' => is_array( $values ) ? $values : (object) array() );
	}

	/**
	 * Set field values through ACF so the hidden field references, repeater rows
	 * and relationships are stored the way ACF expects. Every name must be an ACF
	 * field that applies to the target; anything else is refused.
	 */
	public static function set_values( $id, $values ) {
		if ( ! self::available() || ! function_exists( 'update_field' ) ) { return self::missing(); }
		$target = self::target( $id );
		if ( is_wp_error( $target ) ) { return $target; }
		if ( ! is_array( $values ) || ! $values ) { return self::bad( 'Provide "values" as an object of field names and values.' ); }
		foreach ( $values as $name => $value ) {
			if ( ! is_string( $name ) || ! get_field_object( $name, $target, false, false ) ) {
				return self::bad( sprintf( 'No ACF field "%s" applies to this item. Check the field name with wp_acf_list (field_groups, include_fields) and that the field group\'s location matches.', (string) $name ) );
			}
		}
		$names  = array_map( 'strval', array_keys( $values ) );
		$before = self::raw_values( $target, $names );
		$failed = array();
		foreach ( $values as $name => $value ) { if ( ! update_field( $name, $value, $target ) ) { $failed[] = $name; } }
		$after = self::raw_values( $target, $names );
		$label = is_int( $target ) ? get_the_title( $target ) : (string) $target;
		WPMCP_History::record( 'wp_acf_set_values', 'acf', is_int( $target ) ? $target : 0, $label, 'Set ACF fields: ' . implode( ', ', $names ), array( 'op' => 'acf_values', 'target' => $target, 'before' => $before ), md5( (string) wp_json_encode( $after ) ) );
		$result = array( 'ok' => ! $failed, 'id' => $target, 'set' => array_values( array_diff( $names, $failed ) ) );
		if ( $failed ) { $result['not_saved'] = $failed; $result['note'] = 'ACF reported no change for these (the value may be the same as before, or in the wrong format for the field type).'; }
		return $result;
	}

	/** Undo a value change, unless the values were edited again since. */
	public static function restore_values( $data, $after_hash, $force ) {
		if ( ! self::available() ) { return self::missing(); }
		$target = $data['target'];
		$names  = array_map( 'strval', array_keys( $data['before'] ) );
		if ( ! $force && null !== $after_hash && md5( (string) wp_json_encode( self::raw_values( $target, $names ) ) ) !== $after_hash ) {
			return new WP_Error( 'wpmcp_changed_since', 'These fields were edited after the change. Rolling back will overwrite those edits.' );
		}
		foreach ( $data['before'] as $name => $value ) { if ( null === $value ) { delete_field( $name, $target ); } else { update_field( $name, $value, $target ); } }
		return true;
	}
}
