<?php
/**
 * Plans. Free is this plugin. Plus and Pro come from the separate WP MCP Pro add-on, which tells
 * this plugin what the site's license allows through the "wpmcp_plan" filter.
 *
 * A tool that is not listed here is part of Free. A tool listed here is refused (and not offered to
 * apps) until the site's plan is high enough, and the refusal says how to unlock it.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Plans {
	const ORDER       = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
	const UPGRADE_URL = 'https://wpmcp.co/#pricing';

	/** Tool groups that need a paid plan: label => array( plan, tool names ). */
	public static function groups() {
		return array(
			'Elementor layouts and styles' => array( 'plus', array( 'wp_set_elementor' ) ),
			'Block editor (Gutenberg)'     => array( 'plus', array( 'wp_list_block_types', 'wp_get_blocks', 'wp_set_blocks' ) ),
			'Divi'                         => array( 'plus', array( 'wp_get_divi', 'wp_set_divi' ) ),
			'Caches'                       => array( 'plus', array( 'wp_clear_cache' ) ),
			'WooCommerce'                  => array( 'pro', array(
				'wp_woo_overview', 'wp_woo_list_products', 'wp_woo_get_product', 'wp_woo_list_config', 'wp_woo_sales_summary', 'wp_woo_list_orders', 'wp_woo_get_order', 'wp_woo_list_customers',
				'wp_woo_save_product', 'wp_woo_save_variation', 'wp_woo_bulk_update', 'wp_woo_save_category', 'wp_woo_save_coupon', 'wp_woo_create_order', 'wp_woo_update_order',
				'wp_woo_save_attribute', 'wp_woo_update_settings', 'wp_woo_refund_order', 'wp_woo_delete',
			) ),
			'Advanced Custom Fields'       => array( 'pro', array( 'wp_acf_list', 'wp_acf_save_post_type', 'wp_acf_save_taxonomy', 'wp_acf_save_field_group', 'wp_acf_delete', 'wp_acf_get_values', 'wp_acf_set_values' ) ),
			'Delete, undo and settings'    => array( 'pro', array( 'wp_delete_content', 'wp_rollback', 'wp_get_settings', 'wp_update_settings' ) ),
			'Plugins & themes'             => array( 'pro', array( 'wp_list_extensions', 'wp_install_extension', 'wp_list_extension_files', 'wp_read_extension_file', 'wp_edit_extension_file', 'wp_list_file_backups', 'wp_restore_extension_file', 'wp_set_extension_active' ) ),
		);
	}

	/** What each plan includes, for the Plan screen. */
	public static function features() {
		return array(
			'free' => array( 'MCP connection and sign-in', 'Posts and pages', 'Basic media tools', 'Categories and tags', 'Change text on Elementor pages', 'Read and edit access levels' ),
			'plus' => array( 'Everything in Free', 'Full Elementor editing', 'Divi 4 and 5 editing', 'Block editor layouts', 'Clear caches', 'Standard support' ),
			'pro'  => array( 'Everything in Plus', 'WooCommerce tools', 'ACF fields and groups', 'Custom post types and taxonomies', 'Delete, undo and site settings', 'Plugin and theme tools' ),
		);
	}

	public static function label( $plan ) {
		return isset( self::ORDER[ $plan ] ) ? ucfirst( $plan ) : 'Free';
	}

	/** The plan a tool needs. Anything not listed is Free. */
	public static function plan_of( $tool ) {
		foreach ( self::groups() as $group ) {
			if ( in_array( $tool, $group[1], true ) ) { return $group[0]; }
		}
		return 'free';
	}

	/** The site's plan, as reported by the Pro add-on's license. Without the add-on it is always Free. */
	public static function current() {
		$plan = apply_filters( 'wpmcp_plan', 'free' );
		return isset( self::ORDER[ $plan ] ) ? $plan : 'free';
	}

	public static function pro_installed() {
		return (bool) apply_filters( 'wpmcp_pro_active', false );
	}

	public static function allows( $tool ) {
		return self::ORDER[ self::current() ] >= self::ORDER[ self::plan_of( $tool ) ];
	}

	/** Whether the plan reaches a level, for features that are not a single tool (custom post types, for example). */
	public static function at_least( $plan ) {
		return isset( self::ORDER[ $plan ] ) && self::ORDER[ self::current() ] >= self::ORDER[ $plan ];
	}

	/** Only the tools this plan includes. */
	public static function filter( $tools ) {
		return array_values( array_filter( $tools, function ( $tool ) { return self::allows( $tool['name'] ); } ) );
	}

	/** Why something is not available yet, and what to do. */
	public static function upgrade_text( $plan, $what ) {
		$name = 'WP MCP ' . self::label( $plan );
		if ( ! self::pro_installed() ) {
			return sprintf( '%s is part of %s. Install the WP MCP Pro add-on and activate a %s license to use it. Plans: %s', $what, $name, self::label( $plan ), self::UPGRADE_URL );
		}
		return sprintf( '%s is part of %s, and this site\'s license is %s. Ask the site owner to upgrade (WP MCP, Plan tab). Plans: %s', $what, $name, self::label( self::current() ), self::UPGRADE_URL );
	}

	public static function refusal( $tool ) {
		return self::upgrade_text( self::plan_of( $tool ), $tool );
	}
}
