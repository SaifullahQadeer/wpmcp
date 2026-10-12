<?php
/**
 * Access levels for connected apps and the API key.
 *
 * read  - look at content, layouts and history; change nothing.
 * edit  - also create and edit content, media and text on Elementor pages.
 * full  - everything the site's plan includes and that is switched on.
 *
 * A tool that is not listed for a level is refused there, so a tool added later is available only at
 * "full" until it is placed deliberately. The Pro add-on places its own tools through the
 * "wpmcp_tool_levels" filter. What the site's plan includes is a separate question: see WPMCP_Plans.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Permissions {
	const DEFAULT_APP = 'edit'; // Suggested when approving a new app.

	const READ = array(
		'wp_ping', 'wp_list_post_types', 'wp_list_content', 'wp_get_content', 'wp_get_elementor', 'wp_list_media', 'wp_list_terms', 'wp_list_history',
	);
	const EDIT = array(
		'wp_create_content', 'wp_update_content', 'wp_upload_media', 'wp_create_term', 'wp_edit_elementor_text',
	);

	/** Tools allowed at Read only, including the Pro add-on's. */
	public static function reads() {
		$extra = apply_filters( 'wpmcp_tool_levels', array( 'read' => array(), 'edit' => array() ) );
		return array_values( array_unique( array_merge( self::READ, isset( $extra['read'] ) ? (array) $extra['read'] : array() ) ) );
	}

	/** Tools that need Read and edit, including the Pro add-on's. */
	public static function edits() {
		$extra = apply_filters( 'wpmcp_tool_levels', array( 'read' => array(), 'edit' => array() ) );
		return array_values( array_unique( array_merge( self::EDIT, isset( $extra['edit'] ) ? (array) $extra['edit'] : array() ) ) );
	}

	public static function levels() {
		return apply_filters( 'wpmcp_levels', array(
			'read' => array( 'Read only', 'Can look at content, layouts and history. Cannot change anything.' ),
			'edit' => array( 'Read and edit', 'Can also create and edit content and media, and change text on Elementor pages. Cannot delete, undo, or change site settings.' ),
			'full' => array( 'Full access', 'Everything the site\'s plan includes and that is switched on.' ),
		) );
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
		if ( in_array( $tool, self::reads(), true ) ) { return true; }
		return 'edit' === $level && in_array( $tool, self::edits(), true );
	}

	/** The lowest level that can use a tool. */
	public static function minimum( $tool ) {
		if ( in_array( $tool, self::reads(), true ) ) { return 'read'; }
		return in_array( $tool, self::edits(), true ) ? 'edit' : 'full';
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
