<?php
/**
 * Access levels for connected apps and the API key.
 *
 * read  - look at content, layouts, settings and history; change nothing.
 * edit  - also create and edit content, layouts and media, clear caches and undo.
 * full  - everything that is switched on: deleting, settings, plugins and code.
 *
 * A tool that is not listed for a level is refused there, so a tool added later
 * is available only at "full" until it is placed deliberately.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Permissions {
	const DEFAULT_APP = 'edit'; // Suggested when approving a new app.

	const READ = array(
		'wp_ping', 'wp_list_post_types', 'wp_list_content', 'wp_get_content', 'wp_get_elementor', 'wp_list_media', 'wp_list_terms',
		'wp_list_block_types', 'wp_get_blocks', 'wp_get_divi', 'wp_get_settings', 'wp_list_history', 'wp_acf_list', 'wp_acf_get_values',
	);
	const EDIT = array(
		'wp_create_content', 'wp_update_content', 'wp_set_elementor', 'wp_set_blocks', 'wp_set_divi', 'wp_upload_media', 'wp_create_term',
		'wp_clear_cache', 'wp_rollback', 'wp_acf_set_values',
	);

	public static function levels() {
		return array(
			'read' => array( 'Read only', 'Can look at content, layouts, settings and history. Cannot change anything.' ),
			'edit' => array( 'Read and edit', 'Can also create and edit content, layouts and media, clear caches and undo changes. Cannot delete, change site settings, or touch plugins, themes or code.' ),
			'full' => array( 'Full access', 'Everything that is switched on, including deleting content, site settings and plugin and theme tools.' ),
		);
	}

	public static function valid( $level ) {
		return is_string( $level ) && isset( self::levels()[ $level ] );
	}

	public static function label( $level ) {
		return self::valid( $level ) ? self::levels()[ $level ][0] : 'Full access';
	}

	/** Anything stored before levels existed, or unrecognised, keeps full access so existing connections do not break. */
	public static function normalize( $level ) {
		return self::valid( $level ) ? $level : 'full';
	}

	public static function allows( $level, $tool ) {
		$level = self::normalize( $level );
		if ( 'full' === $level ) { return true; }
		if ( in_array( $tool, self::READ, true ) ) { return true; }
		return 'edit' === $level && in_array( $tool, self::EDIT, true );
	}

	/** The lowest level that can use a tool. */
	public static function minimum( $tool ) {
		if ( in_array( $tool, self::READ, true ) ) { return 'read'; }
		return in_array( $tool, self::EDIT, true ) ? 'edit' : 'full';
	}

	/** Only the tools a level may use, so an app is not offered what it will be refused. */
	public static function filter( $tools, $level ) {
		return array_values( array_filter( $tools, function ( $tool ) use ( $level ) { return self::allows( $level, $tool['name'] ); } ) );
	}

	/** The explanation an app receives when it asks for something its level does not allow. */
	public static function refusal( $level, $tool ) {
		$need = self::label( self::minimum( $tool ) );
		return sprintf( 'This connection has "%s" access, which does not include %s. Ask the site owner to raise its access to "%s" in the WP MCP settings.', self::label( $level ), $tool, $need );
	}
}
