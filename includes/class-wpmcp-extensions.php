<?php
/** Opt-in plugin and theme management through WordPress core APIs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Extensions {
	public static function tool_names() {
		return array( 'wp_list_extensions', 'wp_install_extension', 'wp_list_extension_files', 'wp_read_extension_file', 'wp_edit_extension_file', 'wp_list_file_backups', 'wp_restore_extension_file' );
	}

	public static function tools_spec() {
		$kind = array( 'type' => 'string', 'enum' => array( 'plugin', 'theme' ) );
		$text = array( 'type' => 'string' );
		$base = array( 'kind' => $kind, 'extension' => array( 'type' => 'string', 'description' => 'Installed plugin entry file (e.g. akismet/akismet.php) or theme stylesheet slug.' ) );
		$defs = array(
			'wp_list_extensions' => array( 'List installed plugins or themes.', array( 'kind' => $kind ), array( 'kind' ), true ),
			'wp_install_extension' => array( 'Install a plugin or theme from WordPress.org by slug. Does not activate it or overwrite an existing installation.', array( 'kind' => $kind, 'slug' => $text ), array( 'kind', 'slug' ), false ),
			'wp_list_extension_files' => array( 'List editable files belonging to an installed extension.', $base, array( 'kind', 'extension' ), true ),
			'wp_read_extension_file' => array( 'Read an extension file and its SHA-256 hash before editing.', array_merge( $base, array( 'file' => $text ) ), array( 'kind', 'extension', 'file' ), true ),
			'wp_edit_extension_file' => array( 'Edit an existing file using the WordPress editor validation and PHP rollback checks. Requires the SHA-256 from a fresh read. Code changes can affect the entire site.', array_merge( $base, array( 'file' => $text, 'content' => $text, 'expected_sha256' => $text ) ), array( 'kind', 'extension', 'file', 'content', 'expected_sha256' ), false ),
		);
		$out = array();
		$defs['wp_edit_extension_file'][1]['dry_run'] = array( 'type' => 'boolean', 'description' => 'Validate source and current hash without writing. Use this before every edit.' );
		$defs['wp_list_file_backups'] = array( 'List up to ten pre-edit snapshots for a file, newest first. Recovery requires working WordPress and MCP.', array_merge( $base, array( 'file' => $text ) ), array( 'kind', 'extension', 'file' ), true );
		$defs['wp_restore_extension_file'] = array( 'Restore a saved file snapshot through the same validation and WordPress editor checks. Requires the current file hash; saves a snapshot before restoring. Cannot recover a WordPress bootstrap failure.', array_merge( $base, array( 'file' => $text, 'backup_id' => $text, 'expected_sha256' => $text ) ), array( 'kind', 'extension', 'file', 'backup_id', 'expected_sha256' ), false );
		foreach ( $defs as $name => $d ) {
			$out[] = array( 'name' => $name, 'title' => ucwords( str_replace( '_', ' ', substr( $name, 3 ) ) ), 'description' => $d[0] . ' Requires administrator opt-in, HTTPS, and header authentication.', 'inputSchema' => array( 'type' => 'object', 'properties' => $d[1], 'required' => $d[2], 'additionalProperties' => false ), 'annotations' => array( 'readOnlyHint' => $d[3], 'destructiveHint' => ! $d[3], 'idempotentHint' => $d[3], 'openWorldHint' => 'wp_install_extension' === $name ) );
		}
		return $out;
	}

	public static function run( $name, $args ) {
		if ( ! in_array( $name, self::tool_names(), true ) ) { return new WP_Error( 'wpmcp_tool', 'Unknown extension tool.' ); }
		if ( ! WPMCP_Auth::$header_authenticated || ! is_ssl() ) { return new WP_Error( 'wpmcp_secure_auth', 'Extension tools require HTTPS and a valid x-api-key or Bearer header.' ); }
		if ( '1' !== (string) get_option( 'wpmcp_extensions_enabled', '0' ) || is_multisite() ) { return new WP_Error( 'wpmcp_extensions_disabled', 'Enable extension access in the WP MCP admin menu. Multisite is not supported.' ); }
		$kind = isset( $args['kind'] ) ? $args['kind'] : '';
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) { return new WP_Error( 'wpmcp_kind', 'kind must be plugin or theme.' ); }
		$owner = get_user_by( 'id', (int) get_option( 'wpmcp_extension_owner', 0 ) );
		if ( ! $owner || ! user_can( $owner, 'manage_options' ) ) { return new WP_Error( 'wpmcp_owner', 'An administrator must save extension access again.' ); }
		$previous = get_current_user_id();
		wp_set_current_user( $owner->ID );
		try {
			$cap = 'plugin' === $kind ? 'activate_plugins' : 'switch_themes';
			if ( 'wp_install_extension' === $name ) { $cap = 'install_' . $kind . 's'; }
			if ( in_array( $name, array( 'wp_list_extension_files', 'wp_read_extension_file', 'wp_edit_extension_file', 'wp_list_file_backups', 'wp_restore_extension_file' ), true ) ) { $cap = 'edit_' . $kind . 's'; }
			if ( ! current_user_can( $cap ) ) { return new WP_Error( 'wpmcp_capability', 'The authorizing administrator lacks the required WordPress capability.' ); }
			if ( 'wp_install_extension' === $name && '1' !== (string) get_option( 'wpmcp_allow_install', '0' ) ) { return new WP_Error( 'wpmcp_install_disabled', 'Installation access is disabled.' ); }
			if ( in_array( $name, array( 'wp_edit_extension_file', 'wp_restore_extension_file' ), true ) && '1' !== (string) get_option( 'wpmcp_allow_edit', '0' ) ) { return new WP_Error( 'wpmcp_edit_disabled', 'File editing access is disabled.' ); }
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			return self::execute( $name, $kind, $args );
		} finally { wp_set_current_user( $previous ); }
	}

	/** Roll back a file edit from the admin screen: same checks as the tool, run as the signed-in administrator. */
	public static function admin_restore( $kind, $id, $file, $backup_id ) {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! current_user_can( 'edit_' . $kind . 's' ) ) { return new WP_Error( 'wpmcp_capability', 'You do not have permission to edit ' . $kind . ' files.' ); }
		if ( is_multisite() ) { return new WP_Error( 'wpmcp_extensions_disabled', 'Multisite is not supported.' ); }
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$result = self::execute( 'wp_restore_extension_file', $kind, array( 'kind' => $kind, 'extension' => $id, 'file' => $file, 'backup_id' => $backup_id ), true );
		return is_wp_error( $result ) ? $result : true;
	}

	private static function execute( $name, $kind, $args, $trusted = false ) {
		if ( 'wp_list_extensions' === $name ) {
			$out = array();
			if ( 'plugin' === $kind ) {
				foreach ( get_plugins() as $id => $p ) { $out[] = array( 'extension' => $id, 'name' => $p['Name'], 'version' => $p['Version'], 'active' => is_plugin_active( $id ) ); }
			} else {
				foreach ( wp_get_themes() as $id => $t ) { $out[] = array( 'extension' => $id, 'name' => $t->get( 'Name' ), 'version' => $t->get( 'Version' ), 'active' => get_stylesheet() === $id ); }
			}
			return $out;
		}
		if ( 'wp_install_extension' === $name ) {
			if ( ! wp_is_file_mod_allowed( 'wpmcp_install' ) ) { return new WP_Error( 'wpmcp_file_mods', 'WordPress file modifications are disabled.' ); }
			$slug = isset( $args['slug'] ) ? $args['slug'] : '';
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) { return new WP_Error( 'wpmcp_slug', 'Supply a WordPress.org slug.' ); }
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( 'direct' !== get_filesystem_method() ) { return new WP_Error( 'wpmcp_filesystem', 'Install through WordPress admin: this host requires filesystem credentials.' ); }
			$info = 'plugin' === $kind ? plugins_api( 'plugin_information', array( 'slug' => $slug ) ) : themes_api( 'theme_information', array( 'slug' => $slug ) );
			if ( is_wp_error( $info ) ) { return $info; }
			$url = isset( $info->download_link ) ? $info->download_link : '';
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'downloads.wordpress.org' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return new WP_Error( 'wpmcp_package', 'Only official WordPress.org downloads are supported.' ); }
			$skin = new WP_Ajax_Upgrader_Skin();
			$upgrader = 'plugin' === $kind ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
			$result = $upgrader->install( $url );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( true !== $result ) { return new WP_Error( 'wpmcp_install_failed', 'Installation failed. Check filesystem access and whether the extension is already installed.' ); }
			return array( 'installed' => true, 'slug' => $slug, 'activated' => false );
		}
		$id = isset( $args['extension'] ) ? $args['extension'] : '';
		if ( ! is_string( $id ) || '' === $id || 0 !== validate_file( $id ) ) { return new WP_Error( 'wpmcp_extension', 'Invalid extension identifier.' ); }
		if ( 'plugin' === $kind ) {
			if ( ! array_key_exists( $id, get_plugins() ) ) { return new WP_Error( 'wpmcp_extension', 'Plugin not found.' ); }
			$files = get_plugin_files( $id );
			$root = WP_PLUGIN_DIR;
		} else {
			$theme = wp_get_theme( $id );
			if ( ! $theme->exists() ) { return new WP_Error( 'wpmcp_extension', 'Theme not found.' ); }
			$files = array();
			foreach ( array( 'php', 'css', 'js', 'json', 'html', 'txt' ) as $ext ) { $files = array_merge( $files, array_keys( $theme->get_files( $ext, -1 ) ) ); }
			$root = $theme->get_stylesheet_directory();
		}
		$files = array_values( array_filter( $files, function ( $file ) { return in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), array( 'php', 'css', 'js', 'json', 'html', 'txt' ), true ); } ) );
		if ( 'wp_list_extension_files' === $name ) { return array( 'files' => $files ); }
		$file = isset( $args['file'] ) ? $args['file'] : '';
		if ( ! is_string( $file ) || 0 !== validate_file( $file ) || ! in_array( $file, $files, true ) ) { return new WP_Error( 'wpmcp_file', 'Choose a file returned by wp_list_extension_files.' ); }
		$path = realpath( $root . '/' . $file );
		$real_root = realpath( $root );
		if ( ! $path || ! $real_root || 0 !== strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $real_root ) ) ) || ! is_readable( $path ) || filesize( $path ) > 100000 ) { return new WP_Error( 'wpmcp_file', 'File is outside its root, unreadable, or larger than 100 KB.' ); }
		$content = file_get_contents( $path );
		if ( false === $content ) { return new WP_Error( 'wpmcp_read', 'Unable to read file.' ); }
		$hash = hash( 'sha256', $content );
		if ( 'wp_read_extension_file' === $name ) { return array( 'file' => $file, 'content' => $content, 'sha256' => $hash ); }
		if ( 'wp_list_file_backups' === $name ) { return WPMCP_File_Safety::listing( $kind, $id, $file ); }
		if ( 'wp_restore_extension_file' === $name ) {
			$items = WPMCP_File_Safety::snapshots( $kind, $id, $file );
			$backup_id = isset( $args['backup_id'] ) && is_string( $args['backup_id'] ) ? $args['backup_id'] : '';
			if ( ! isset( $items[$backup_id] ) ) { return new WP_Error( 'wpmcp_backup_missing', 'Snapshot not found for this exact file.' ); }
			$args['content'] = $items[$backup_id]['content'];
		}
		if ( $trusted ) { $args['expected_sha256'] = $hash; } // The administrator is acting directly, so there is no stale read to guard against.
		if ( ! isset( $args['content'], $args['expected_sha256'] ) || ! is_string( $args['content'] ) || ! is_string( $args['expected_sha256'] ) || strlen( $args['content'] ) > 100000 || ! hash_equals( $hash, $args['expected_sha256'] ) ) { return new WP_Error( 'wpmcp_conflict', 'Read the current file again and supply its SHA-256. Content must be a string no larger than 100 KB.' ); }
		if ( ! wp_is_file_mod_allowed( 'wpmcp_edit' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) { return new WP_Error( 'wpmcp_file_mods', 'WordPress file editing is disabled.' ); }
		$validation = WPMCP_File_Safety::validate( $file, $args['content'], $content );
		if ( is_wp_error( $validation ) ) { return $validation; }
		if ( ! empty( $args['dry_run'] ) ) { return array( 'validated' => true, 'written' => false, 'sha256' => $hash, 'note' => 'Static checks passed. Runtime behavior still requires staging and WordPress editor checks.' ); }
		$backup = WPMCP_File_Safety::save( $kind, $id, $file, $content );
		if ( is_wp_error( $backup ) ) { return $backup; }
		$edit = array( 'file' => $file, $kind => $id, 'newcontent' => $args['content'], 'nonce' => wp_create_nonce( 'plugin' === $kind ? 'edit-plugin_' . $file : 'edit-theme_' . $id . '_' . $file ) );
		$result = wp_edit_theme_plugin_file( $edit );
		if ( ! is_wp_error( $result ) ) {
			WPMCP_History::record( $name, 'file', 0, $kind . ' ' . $id . ': ' . $file, ( 'wp_restore_extension_file' === $name ? 'Restored ' : 'Edited ' ) . $file, array( 'op' => 'file', 'kind' => $kind, 'extension' => $id, 'file' => $file, 'backup_id' => $backup ) );
		}
		return is_wp_error( $result ) ? $result : array( 'updated' => true, 'file' => $file, 'sha256' => hash( 'sha256', $args['content'] ), 'backup_id' => $backup, 'next_step' => 'Run wp_ping and check the changed page. If behavior is wrong, read the current file hash and restore this backup.' );
	}
}
