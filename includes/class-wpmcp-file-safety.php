<?php
/** Preflight checks and bounded, non-autoloaded file snapshots. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WPMCP_File_Safety {
	public static function validate( $file, $content, $original = '' ) {
		if ( ! is_string( $content ) || strlen( $content ) > 100000 || false !== strpos( $content, "\0" ) ) {
			return new WP_Error( 'wpmcp_invalid_content', 'Supply text without null bytes, no larger than 100 KB.' );
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext ) {
			json_decode( $content );
			if ( JSON_ERROR_NONE !== json_last_error() ) { return new WP_Error( 'wpmcp_invalid_json', 'Invalid JSON: ' . json_last_error_msg() ); }
		}
		if ( 'php' !== $ext ) { return true; }
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return new WP_Error( 'wpmcp_tokenizer_missing', 'PHP tokenizer is required for safe PHP edits. Ask the host to enable it.' );
		}
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) || preg_match( '/&(?:lt|#0*60|#x0*3c);\?(?:php|=)/i', $content ) ) {
			return new WP_Error( 'wpmcp_php_encoding', 'PHP source contains a BOM or HTML-escaped opening tag. Send literal source code, not HTML entities or a Markdown code block.' );
		}
		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
		} catch ( Throwable $error ) {
			return new WP_Error( 'wpmcp_php_syntax', 'PHP syntax error: ' . $error->getMessage() );
		}
		$has_php = false;
		$inline = false;
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) { continue; }
			if ( in_array( $token[0], array( T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO ), true ) ) { $has_php = true; }
			if ( T_INLINE_HTML === $token[0] && '' !== trim( $token[1] ) ) { $inline = true; }
		}
		if ( ! $has_php ) { return new WP_Error( 'wpmcp_php_tag', 'A PHP file must contain a literal PHP opening tag. Refusing to write source that would be printed as text.' ); }
		$original_inline = false;
		foreach ( token_get_all( $original ) as $token ) {
			if ( is_array( $token ) && T_INLINE_HTML === $token[0] && '' !== trim( $token[1] ) ) { $original_inline = true; }
		}
		if ( $inline && ( 'functions.php' === basename( $file ) || ! $original_inline ) ) {
			return new WP_Error( 'wpmcp_php_output', 'Unexpected text outside PHP tags. This can corrupt REST responses. Keep this file PHP-only.' );
		}
		return true;
	}

	private static function option_name( $kind, $extension, $file ) {
		return 'wpmcp_backups_' . hash( 'sha256', $kind . '\n' . $extension . '\n' . $file );
	}

	public static function snapshots( $kind, $extension, $file ) {
		$items = get_option( self::option_name( $kind, $extension, $file ), array() );
		return is_array( $items ) ? $items : array();
	}

	public static function save( $kind, $extension, $file, $content ) {
		$name = self::option_name( $kind, $extension, $file );
		$items = self::snapshots( $kind, $extension, $file );
		$id = wp_generate_uuid4();
		$items[$id] = array( 'id' => $id, 'created_at' => gmdate( 'c' ), 'sha256' => hash( 'sha256', $content ), 'content' => $content );
		// Retain the latest ten versions of each edited file; never public files.
		$items = array_slice( $items, -10, null, true );
		if ( ! update_option( $name, $items, false ) ) { return new WP_Error( 'wpmcp_backup_failed', 'Could not save the pre-edit snapshot. No edit was attempted.' ); }
		return $id;
	}

	public static function listing( $kind, $extension, $file ) {
		$out = array();
		foreach ( self::snapshots( $kind, $extension, $file ) as $item ) {
			unset( $item['content'] );
			$out[] = $item;
		}
		return array( 'backups' => array_reverse( $out ), 'limit_per_file' => 10 );
	}
}
