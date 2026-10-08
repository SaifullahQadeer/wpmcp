<?php
/**
 * Site-level helpers: clearing caches and reading and changing common settings.
 *
 * Cache clearing talks to each cache plugin through its own public function or
 * action, and only when that plugin is present, so it never fails because a
 * particular plugin is missing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Site {

	/* ----------------------------------------------------------------- */
	/* Caches                                                            */
	/* ----------------------------------------------------------------- */

	/** Page and optimization cache plugins: label => callable that purges everything, or null when absent. */
	private static function page_caches() {
		$action = function ( $hook ) {
			return has_action( $hook ) ? function () use ( $hook ) { do_action( $hook ); } : null;
		};
		return array(
			'LiteSpeed Cache'    => $action( 'litespeed_purge_all' ),
			'WP Rocket'          => function_exists( 'rocket_clean_domain' ) ? function () { rocket_clean_domain(); if ( function_exists( 'rocket_clean_minify' ) ) { rocket_clean_minify(); } } : null,
			'W3 Total Cache'     => function_exists( 'w3tc_flush_all' ) ? function () { w3tc_flush_all(); } : null,
			'WP Super Cache'     => function_exists( 'wp_cache_clear_cache' ) ? function () { wp_cache_clear_cache(); } : null,
			'WP Fastest Cache'   => function_exists( 'wpfc_clear_all_cache' ) ? function () { wpfc_clear_all_cache( true ); } : null,
			'Autoptimize'        => class_exists( 'autoptimizeCache' ) ? function () { autoptimizeCache::clearall(); } : null,
			'SiteGround Speed'   => function_exists( 'sg_cachepress_purge_cache' ) ? function () { sg_cachepress_purge_cache(); } : null,
			'Cache Enabler'      => $action( 'cache_enabler_clear_complete_cache' ),
			'Breeze'             => $action( 'breeze_clear_all_cache' ),
			'Hummingbird'        => $action( 'wphb_clear_page_cache' ),
			'Nginx Helper'       => $action( 'rt_nginx_helper_purge_all' ),
		);
	}

	/** Labels of the page cache plugins found on this site. */
	public static function detected_caches() {
		return array_keys( array_filter( self::page_caches() ) );
	}

	/** Clear Divi's generated static CSS and JavaScript, for one post or everything. */
	public static function clear_divi( $post_id = 'all' ) {
		if ( ! class_exists( 'ET_Core_PageResource' ) ) { return false; }
		try {
			ET_Core_PageResource::remove_static_resources( $post_id, 'all' );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Light, per-post purge after a layout write, so visitors see the change without a full flush. */
	public static function clear_post_caches( $post_id ) {
		try {
			if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $post_id ); }
			self::clear_divi( $post_id );
			WPMCP_Elementor::clear_cache( $post_id );
			if ( has_action( 'litespeed_purge_post' ) ) { do_action( 'litespeed_purge_post', $post_id ); }
			if ( function_exists( 'rocket_clean_post' ) ) { rocket_clean_post( $post_id ); }
			if ( function_exists( 'w3tc_flush_post' ) ) { w3tc_flush_post( $post_id ); }
		} catch ( \Throwable $e ) {
			// A cache plugin misbehaving must not fail the edit that already succeeded.
		}
	}

	/**
	 * @param string[] $targets Any of: object, transients, elementor, divi, page. Empty means all.
	 * @return array{ok:bool,results:array,note:string}
	 */
	public static function clear_cache( $targets = array() ) {
		$valid   = array( 'object', 'transients', 'elementor', 'divi', 'page' );
		$targets = array_values( array_intersect( $valid, array_map( 'strval', (array) $targets ) ) );
		$all     = array() === $targets;
		$run     = function ( $name ) use ( $targets, $all ) { return $all || in_array( $name, $targets, true ); };
		$results = array();

		if ( $run( 'object' ) ) {
			$results[] = array( 'target' => 'WordPress object cache', 'cleared' => (bool) wp_cache_flush() );
		}
		if ( $run( 'transients' ) ) {
			if ( function_exists( 'delete_expired_transients' ) ) { delete_expired_transients( true ); }
			$results[] = array( 'target' => 'Expired transients', 'cleared' => function_exists( 'delete_expired_transients' ) );
		}
		if ( $run( 'elementor' ) ) {
			$active = WPMCP_Elementor::is_active();
			if ( $active ) { WPMCP_Elementor::clear_cache(); }
			$results[] = array( 'target' => 'Elementor CSS cache', 'cleared' => $active, 'note' => $active ? '' : 'Elementor is not active' );
		}
		if ( $run( 'divi' ) ) {
			$done      = self::clear_divi();
			$results[] = array( 'target' => 'Divi static resources', 'cleared' => $done, 'note' => $done ? '' : 'Divi is not active' );
		}
		if ( $run( 'page' ) ) {
			$found = 0;
			foreach ( self::page_caches() as $label => $purge ) {
				if ( null === $purge ) { continue; }
				$found++;
				try {
					$purge();
					$results[] = array( 'target' => $label, 'cleared' => true );
				} catch ( \Throwable $e ) {
					$results[] = array( 'target' => $label, 'cleared' => false, 'note' => $e->getMessage() );
				}
			}
			if ( 0 === $found ) { $results[] = array( 'target' => 'Page cache plugins', 'cleared' => false, 'note' => 'None detected (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, SiteGround, Cache Enabler, Breeze, Hummingbird, Nginx Helper). Host-level and CDN caches are not covered.' ); }
		}
		return array(
			'ok'      => true,
			'results' => $results,
			'note'    => 'Host-level caches (for example a CDN or server cache outside WordPress) are not cleared from here.',
		);
	}

	/* ----------------------------------------------------------------- */
	/* Settings                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * The settings an AI app may read and change. Anything that can lock people
	 * out or move the site (URLs, admin email, registration, roles) is left out.
	 */
	private static function setting_rules() {
		$int_between = function ( $min, $max ) {
			return function ( $v ) use ( $min, $max ) { return is_numeric( $v ) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null; };
		};
		$choice = function ( $options ) {
			return function ( $v ) use ( $options ) { return in_array( (string) $v, $options, true ) ? (string) $v : null; };
		};
		$text = function ( $max ) {
			return function ( $v ) use ( $max ) { return is_scalar( $v ) && mb_strlen( (string) $v ) <= $max ? sanitize_text_field( (string) $v ) : null; };
		};
		return array(
			'blogname'                => array( 'Site title', $text( 150 ) ),
			'blogdescription'         => array( 'Tagline', $text( 250 ) ),
			'timezone_string'         => array( 'Timezone (for example Europe/London)', function ( $v ) { return is_string( $v ) && in_array( $v, timezone_identifiers_list(), true ) ? $v : null; } ),
			'date_format'             => array( 'Date format (PHP date format)', $text( 40 ) ),
			'time_format'             => array( 'Time format (PHP date format)', $text( 40 ) ),
			'start_of_week'           => array( 'First day of the week, 0 (Sunday) to 6', $int_between( 0, 6 ) ),
			'posts_per_page'          => array( 'Blog posts per page, 1 to 100', $int_between( 1, 100 ) ),
			'show_on_front'           => array( 'Homepage shows: posts or page', $choice( array( 'posts', 'page' ) ) ),
			'page_on_front'           => array( 'ID of the static homepage (0 for none)', function ( $v ) { $id = (int) $v; return is_numeric( $v ) && ( 0 === $id || ( get_post( $id ) && 'page' === get_post_type( $id ) ) ) ? $id : null; } ),
			'page_for_posts'          => array( 'ID of the blog posts page (0 for none)', function ( $v ) { $id = (int) $v; return is_numeric( $v ) && ( 0 === $id || ( get_post( $id ) && 'page' === get_post_type( $id ) ) ) ? $id : null; } ),
			'blog_public'             => array( 'Search engine visibility: 1 visible, 0 discouraged', $int_between( 0, 1 ) ),
			'default_comment_status'  => array( 'New posts accept comments: open or closed', $choice( array( 'open', 'closed' ) ) ),
			'default_ping_status'     => array( 'New posts accept pingbacks: open or closed', $choice( array( 'open', 'closed' ) ) ),
			'permalink_structure'     => array( 'Permalink structure, for example /%postname%/', function ( $v ) { return is_string( $v ) && '' !== $v && 0 === strpos( $v, '/' ) && strlen( $v ) <= 120 && preg_match( '#%(postname|post_id|year|monthnum|day|category|author)%#', $v ) && ! preg_match( '/[^A-Za-z0-9_%\/.\-]/', $v ) ? $v : null; } ),
		);
	}

	public static function get_settings() {
		$out = array();
		foreach ( self::setting_rules() as $key => $rule ) {
			$out[ $key ] = array( 'value' => get_option( $key ), 'about' => $rule[0] );
		}
		return array( 'settings' => $out );
	}

	/** Change several settings together: all are checked first, and none is applied if one is invalid. */
	public static function update_settings( $changes ) {
		if ( ! is_array( $changes ) || ! $changes ) { return new WP_Error( 'wpmcp_missing_arg', 'Provide "settings" as an object of setting names and new values.', array( 'status' => 400 ) ); }
		$rules  = self::setting_rules();
		$clean  = array();
		foreach ( $changes as $key => $value ) {
			if ( ! isset( $rules[ $key ] ) ) { return new WP_Error( 'wpmcp_unknown_setting', sprintf( '"%s" cannot be changed here. Allowed: %s.', $key, implode( ', ', array_keys( $rules ) ) ), array( 'status' => 400 ) ); }
			$checked = $rules[ $key ][1]( $value );
			if ( null === $checked ) { return new WP_Error( 'wpmcp_bad_setting', sprintf( 'Invalid value for %s. Expected: %s.', $key, $rules[ $key ][0] ), array( 'status' => 400 ) ); }
			$clean[ $key ] = $checked;
		}
		$before = array();
		foreach ( $clean as $key => $value ) { $before[ $key ] = get_option( $key ); }
		foreach ( $clean as $key => $value ) { update_option( $key, $value ); }
		if ( isset( $clean['permalink_structure'] ) ) { self::flush_permalinks(); }
		WPMCP_History::record( 'wp_update_settings', 'option', 0, implode( ', ', array_keys( $clean ) ), 'Changed site settings', array( 'op' => 'restore_options', 'values' => $before ) );
		return array( 'ok' => true, 'changed' => array_keys( $clean ), 'previous' => $before, 'cache_note' => 'If a visible setting does not change on the front end, clear the cache with wp_clear_cache.' );
	}

	/** Used by rollback to put old setting values back. */
	public static function restore_options( $values ) {
		$rules = self::setting_rules();
		foreach ( (array) $values as $key => $value ) {
			if ( isset( $rules[ $key ] ) ) { update_option( $key, $value ); }
		}
		if ( isset( $values['permalink_structure'] ) ) { self::flush_permalinks(); }
		return true;
	}

	private static function flush_permalinks() {
		global $wp_rewrite;
		if ( is_object( $wp_rewrite ) ) { $wp_rewrite->set_permalink_structure( get_option( 'permalink_structure' ) ); }
		flush_rewrite_rules();
	}
}
