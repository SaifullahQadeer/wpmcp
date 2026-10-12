<?php
/**
 * Change history and rollback.
 *
 * Every write made through WP MCP is recorded with enough of the previous state
 * to undo it. Snapshots cover only what a call touched, so rolling back does not
 * disturb unrelated edits made by other people or plugins.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_History {
	const DB_VERSION   = '1';
	const KEEP_ROWS    = 300;
	const KEEP_DAYS    = 90;
	const MAX_SNAPSHOT = 4000000; // Bytes of JSON per entry.

	const POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' );
	const ELEMENTOR_META = array( '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode', '_elementor_version', '_elementor_template_type' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpmcp_history';
	}

	/* ----------------------------------------------------------------- */
	/* Storage                                                           */
	/* ----------------------------------------------------------------- */

	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( 'wpmcp_db_version' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			actor varchar(190) NOT NULL DEFAULT '',
			tool varchar(60) NOT NULL DEFAULT '',
			object_type varchar(30) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label text NOT NULL,
			summary text NOT NULL,
			before_data longtext NULL,
			after_hash varchar(32) NULL,
			status varchar(20) NOT NULL DEFAULT 'applied',
			rolled_back_at datetime NULL,
			rolled_back_by varchar(190) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY object_ref (object_type,object_id)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'wpmcp_db_version', self::DB_VERSION );
	}

	private static function insert( $row ) {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), $row );
		if ( $ok && 1 === wp_rand( 1, 20 ) ) { self::prune(); }
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Entries for one page of the history screen, newest first. Snapshots are not loaded. */
	public static function entries( $page = 1, $per_page = 25 ) {
		global $wpdb;
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, created_at, actor, tool, object_type, object_id, label, summary, status, rolled_back_at, rolled_back_by, (before_data IS NOT NULL) AS can_rollback FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			(int) $per_page, $offset
		), ARRAY_A );
	}

	/** A page of history for the wp_list_history tool. */
	public static function listing( $page, $per_page ) {
		$items = array();
		foreach ( self::entries( $page, $per_page ) as $row ) {
			$items[] = array(
				'id'           => (int) $row['id'],
				'time_utc'     => $row['created_at'],
				'actor'        => $row['actor'],
				'summary'      => $row['summary'],
				'item'         => $row['label'],
				'item_type'    => $row['object_type'],
				'item_id'      => (int) $row['object_id'],
				'status'       => $row['status'],
				'can_rollback' => 'applied' === $row['status'] && (bool) $row['can_rollback'],
			);
		}
		return array( 'total' => self::count(), 'page' => (int) $page, 'items' => $items );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	private static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function prune() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= (SELECT id FROM (SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d) AS keep_marker)", self::KEEP_ROWS ) );
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() );
	}

	/* ----------------------------------------------------------------- */
	/* Snapshots                                                         */
	/* ----------------------------------------------------------------- */

	/** Who is making the change: the connected app's name, "API key", or a logged-in user. */
	public static function actor() {
		if ( '' !== WPMCP_Auth::$actor ) { return WPMCP_Auth::$actor; }
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->display_name : 'System';
	}

	/** Post fields, meta, terms and thumbnail named in $scope, as plain data. */
	public static function snapshot( $post_id, $scope ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return null; }
		$snap = array();
		foreach ( (array) ( isset( $scope['fields'] ) ? $scope['fields'] : array() ) as $field ) { $snap['fields'][ $field ] = $post->$field; }
		foreach ( (array) ( isset( $scope['meta'] ) ? $scope['meta'] : array() ) as $key ) {
			$snap['meta'][ $key ] = metadata_exists( 'post', $post->ID, $key ) ? array_values( get_post_meta( $post->ID, $key, false ) ) : null;
		}
		foreach ( (array) ( isset( $scope['terms'] ) ? $scope['terms'] : array() ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$snap['terms'][ $taxonomy ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		}
		if ( ! empty( $scope['thumbnail'] ) ) { $snap['thumbnail'] = (int) get_post_thumbnail_id( $post->ID ); }
		return $snap;
	}

	private static function hash_of( $snap ) {
		$json = wp_json_encode( $snap );
		return false === $json ? null : md5( $json );
	}

	/** What an update_content call is about to change, from the arguments it received. */
	public static function scope_for_update( $body ) {
		$map    = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'parent' => 'post_parent', 'menu_order' => 'menu_order' );
		$scope  = array( 'fields' => array(), 'meta' => array(), 'terms' => array(), 'thumbnail' => array_key_exists( 'featured_media', (array) $body ) );
		foreach ( $map as $arg => $field ) { if ( array_key_exists( $arg, (array) $body ) ) { $scope['fields'][] = $field; } }
		if ( isset( $body['meta'] ) && is_array( $body['meta'] ) ) { foreach ( array_keys( $body['meta'] ) as $key ) { if ( is_string( $key ) && '' !== $key ) { $scope['meta'][] = $key; } } }
		if ( isset( $body['terms'] ) && is_array( $body['terms'] ) ) { foreach ( array_keys( $body['terms'] ) as $tax ) { if ( taxonomy_exists( $tax ) ) { $scope['terms'][] = $tax; } } }
		if ( isset( $body['elementor'] ) ) { $scope['meta'] = array_values( array_unique( array_merge( $scope['meta'], self::ELEMENTOR_META ) ) ); }
		return $scope;
	}

	public static function scope_for_elementor() {
		return array( 'fields' => array(), 'meta' => self::ELEMENTOR_META, 'terms' => array(), 'thumbnail' => false );
	}

	/** Start recording a change to an existing post. Returns context for finish(), or null if it cannot be undone. */
	public static function begin_state( $post_id, $scope ) {
		try {
			$snap = self::snapshot( $post_id, $scope );
			return null === $snap ? null : array( 'id' => (int) $post_id, 'scope' => $scope, 'before' => $snap );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Save the entry for a change that began with begin_state(). Never throws. */
	public static function finish_state( $ctx, $tool, $summary ) {
		try {
			$post = get_post( $ctx ? $ctx['id'] : 0 );
			if ( ! $ctx || ! $post ) { return 0; }
			$data = array( 'op' => 'restore_state', 'scope' => $ctx['scope'], 'state' => $ctx['before'] );
			return self::record( $tool, 'post', $post->ID, $post->post_title, $summary, $data, self::hash_of( self::snapshot( $post->ID, $ctx['scope'] ) ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Save an entry. $data is the undo recipe; null means the change cannot be rolled back. */
	public static function record( $tool, $type, $object_id, $label, $summary, $data, $after_hash = null ) {
		try {
			$json = null === $data ? null : wp_json_encode( $data );
			if ( false === $json || ( null !== $json && strlen( $json ) > self::MAX_SNAPSHOT ) ) {
				$json    = null;
				$summary .= ' (too large to roll back)';
			}
			return self::insert( array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'actor'       => mb_substr( self::actor(), 0, 190 ),
				'tool'        => $tool,
				'object_type' => $type,
				'object_id'   => (int) $object_id,
				'label'       => mb_substr( (string) $label, 0, 500 ),
				'summary'     => mb_substr( (string) $summary, 0, 1000 ),
				'before_data' => $json,
				'after_hash'  => $after_hash,
				'status'      => 'applied',
			) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Everything needed to bring a permanently deleted post back with the same ID. */
	public static function full_snapshot( $post_id ) {
		$post = get_post( (int) $post_id, ARRAY_A );
		if ( ! $post ) { return null; }
		$meta = array();
		foreach ( get_post_meta( $post['ID'] ) as $key => $values ) { $meta[ $key ] = array_map( 'maybe_unserialize', $values ); }
		$terms = array();
		foreach ( get_object_taxonomies( $post['post_type'] ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post['ID'], $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && $ids ) { $terms[ $taxonomy ] = array_map( 'intval', $ids ); }
		}
		return array( 'op' => 'recreate', 'post' => $post, 'meta' => $meta, 'terms' => $terms );
	}

	/* ----------------------------------------------------------------- */
	/* Rollback                                                          */
	/* ----------------------------------------------------------------- */

	private static function restore_state( $id, $state ) {
		if ( ! empty( $state['fields'] ) ) {
			$arr = $state['fields'];
			$arr['ID'] = (int) $id;
			$result = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		foreach ( (array) ( isset( $state['meta'] ) ? $state['meta'] : array() ) as $key => $values ) {
			delete_post_meta( $id, $key );
			if ( is_array( $values ) ) { foreach ( $values as $value ) { add_post_meta( $id, $key, wp_slash( $value ) ); } }
		}
		foreach ( (array) ( isset( $state['terms'] ) ? $state['terms'] : array() ) as $taxonomy => $ids ) {
			if ( taxonomy_exists( $taxonomy ) ) { wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), $taxonomy, false ); }
		}
		if ( array_key_exists( 'thumbnail', (array) $state ) ) {
			if ( (int) $state['thumbnail'] > 0 ) { set_post_thumbnail( $id, (int) $state['thumbnail'] ); } else { delete_post_thumbnail( $id ); }
		}
		if ( isset( $state['meta'] ) && array_key_exists( '_elementor_data', (array) $state['meta'] ) ) {
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_page_assets' );
			WPMCP_Elementor::clear_cache( $id );
		}
		return true;
	}

	private static function recreate( $data ) {
		$post = $data['post'];
		if ( get_post( $post['ID'] ) ) { return new WP_Error( 'wpmcp_exists', 'An item with that ID already exists, so it cannot be restored.' ); }
		$arr = $post;
		$arr['import_id'] = $post['ID'];
		unset( $arr['ID'] );
		$new = wp_insert_post( wp_slash( $arr ), true );
		if ( is_wp_error( $new ) ) { return $new; }
		foreach ( (array) $data['meta'] as $key => $values ) { foreach ( (array) $values as $value ) { add_post_meta( $new, $key, wp_slash( $value ) ); } }
		foreach ( (array) $data['terms'] as $taxonomy => $ids ) { if ( taxonomy_exists( $taxonomy ) ) { wp_set_object_terms( $new, array_map( 'intval', $ids ), $taxonomy, false ); } }
		return true;
	}

	/** Why an entry cannot be rolled back right now, or '' when it can. */
	public static function blocker( $row ) {
		if ( ! $row ) { return 'That history entry no longer exists.'; }
		if ( 'applied' !== $row['status'] ) { return 'This change was already rolled back.'; }
		if ( null === $row['before_data'] || '' === $row['before_data'] ) { return 'This change cannot be rolled back.'; }
		return '';
	}

	/**
	 * Undo one history entry.
	 *
	 * @param bool   $files Whether file edits may be rolled back (admin screen only).
	 * @param string $level Access level of whoever asks: undoing a change needs the level that could have made it.
	 * @return array|WP_Error {message}
	 */
	public static function rollback( $id, $force = false, $files = false, $level = 'full' ) {
		if ( ! WPMCP_Plans::at_least( 'pro' ) ) { return new WP_Error( 'wpmcp_plan', WPMCP_Plans::upgrade_text( 'pro', 'Rolling back a change' ) ); }
		$row  = self::get( $id );
		$why  = self::blocker( $row );
		if ( '' !== $why ) { return new WP_Error( 'wpmcp_no_rollback', $why ); }
		if ( ! WPMCP_Permissions::allows( $level, $row['tool'] ) ) { return new WP_Error( 'wpmcp_level', WPMCP_Permissions::refusal( $level, $row['tool'] ) ); }
		$data = json_decode( $row['before_data'], true );
		if ( ! is_array( $data ) || empty( $data['op'] ) ) { return new WP_Error( 'wpmcp_no_rollback', 'The saved undo information is damaged.' ); }
		$post_id = (int) $row['object_id'];
		$op      = $data['op'];

		if ( 'restore_state' === $op ) {
			if ( ! get_post( $post_id ) ) { return new WP_Error( 'wpmcp_gone', 'The item no longer exists.' ); }
			if ( ! $force && null !== $row['after_hash'] && self::hash_of( self::snapshot( $post_id, $data['scope'] ) ) !== $row['after_hash'] ) {
				return new WP_Error( 'wpmcp_changed_since', 'This item was edited after the change. Rolling back will overwrite those edits.' );
			}
			$result = self::restore_state( $post_id, $data['state'] );
		} elseif ( 'trash_created' === $op ) {
			$result = get_post( $post_id ) ? ( wp_trash_post( $post_id ) ? true : new WP_Error( 'wpmcp_failed', 'Could not move the item to the trash.' ) ) : new WP_Error( 'wpmcp_gone', 'The item no longer exists.' );
		} elseif ( 'untrash' === $op ) {
			$post   = get_post( $post_id );
			$result = $post && 'trash' === $post->post_status ? ( wp_untrash_post( $post_id ) ? true : new WP_Error( 'wpmcp_failed', 'Could not restore the item.' ) ) : new WP_Error( 'wpmcp_gone', 'The item is no longer in the trash.' );
		} elseif ( 'recreate' === $op ) {
			$result = self::recreate( $data );
		} elseif ( 'delete_attachment' === $op ) {
			$result = get_post( $post_id ) ? ( wp_delete_attachment( $post_id, true ) ? true : new WP_Error( 'wpmcp_failed', 'Could not delete the file.' ) ) : new WP_Error( 'wpmcp_gone', 'The file no longer exists.' );
		} elseif ( 'delete_term' === $op ) {
			$deleted = wp_delete_term( (int) $data['term_id'], $data['taxonomy'] );
			$result  = true === $deleted ? true : new WP_Error( 'wpmcp_failed', 'Could not delete the term.' );
		} else {
			$result = apply_filters( 'wpmcp_rollback_op', null, $op, $data, $row, array( 'force' => $force, 'files' => $files, 'post_id' => $post_id ) );
			if ( null === $result ) {
				return new WP_Error( 'wpmcp_no_rollback', 'This kind of change cannot be rolled back.' );
			}
		}
		if ( is_wp_error( $result ) ) { return $result; }

		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'rolled_back', 'rolled_back_at' => gmdate( 'Y-m-d H:i:s' ), 'rolled_back_by' => mb_substr( self::actor(), 0, 190 ) ), array( 'id' => (int) $id ) );
		return array( 'message' => 'Rolled back: ' . $row['summary'] );
	}
}
