<?php
/**
 * Text-only editing of an Elementor page (available in Free).
 *
 * It can change the words in text settings (headings, paragraphs, button labels, list items) and nothing
 * else. Layout, styles and widgets cannot be added, removed or reconfigured here: that is the Plus plan's
 * wp_set_elementor. Before saving, the page is compared with the original with every text field blanked,
 * and nothing is saved unless the two are identical, so only text can have changed.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Elementor_Text {
	const MAX_EDITS   = 50;
	const MAX_REPLACE = 20;
	const MAX_LENGTH  = 20000;

	/** Setting names that hold words a visitor reads. Anything else is refused. */
	const TEXT_KEYS = array(
		'title', 'editor', 'text', 'content', 'description', 'description_text', 'title_text', 'heading', 'sub_heading', 'subtitle', 'caption',
		'button_text', 'alert_title', 'alert_description', 'testimonial_content', 'testimonial_name', 'testimonial_job', 'tab_title', 'tab_content',
		'before_text', 'after_text', 'prefix', 'suffix', 'paragraph', 'label', 'placeholder', 'name', 'job', 'quote', 'excerpt', 'header_text',
	);

	private static function bad( $message ) {
		return new WP_Error( 'wpmcp_elementor_text', $message, array( 'status' => 400 ) );
	}

	/** A v4 atomic property wraps its value: {"$$type":"string","value":"Text"}. */
	private static function is_wrapped( $value ) {
		return is_array( $value ) && isset( $value['$$type'] ) && array_key_exists( 'value', $value ) && is_string( $value['value'] );
	}

	private static function is_text( $key, $value ) {
		return in_array( $key, self::TEXT_KEYS, true ) && ( is_string( $value ) || self::is_wrapped( $value ) );
	}

	private static function read_text( $value ) {
		return self::is_wrapped( $value ) ? $value['value'] : $value;
	}

	private static function write_text( &$slot, $text ) {
		if ( self::is_wrapped( $slot ) ) { $slot['value'] = $text; } else { $slot = $text; }
	}

	private static function clean( $value ) {
		return wp_kses_post( (string) $value );
	}

	/** The page with every text field blanked, to prove the structure did not change. */
	private static function skeleton( $node ) {
		if ( ! is_array( $node ) ) { return $node; }
		$out = array();
		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && self::is_text( $key, $value ) ) {
				if ( self::is_wrapped( $value ) ) { $value['value'] = ''; $out[ $key ] = $value; } else { $out[ $key ] = ''; }
			} else {
				$out[ $key ] = self::skeleton( $value );
			}
		}
		return $out;
	}

	/** @return array|null Reference to the element with this id, or null. */
	private static function &find( &$elements, $id ) {
		$none = null;
		if ( ! is_array( $elements ) ) { return $none; }
		foreach ( $elements as $i => &$el ) {
			if ( ! is_array( $el ) ) { continue; }
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) { return $el; }
			if ( ! empty( $el['elements'] ) ) {
				$found = &self::find( $el['elements'], $id );
				if ( null !== $found ) { return $found; }
			}
		}
		return $none;
	}

	private static function shorten( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 79 ) . '…' : $text;
	}

	/** Replace text inside every text field below $node. Returns how many fields changed. */
	private static function replace_in( &$node, $find, $with ) {
		if ( ! is_array( $node ) ) { return 0; }
		$count = 0;
		foreach ( $node as $key => &$value ) {
			if ( is_string( $key ) && self::is_text( $key, $value ) ) {
				$old = self::read_text( $value );
				if ( false !== strpos( $old, $find ) ) {
					self::write_text( $value, self::clean( str_replace( $find, $with, $old ) ) );
					$count++;
				}
			} elseif ( is_array( $value ) ) {
				$count += self::replace_in( $value, $find, $with );
			}
		}
		return $count;
	}

	/** Apply one {id, field, value} edit. Returns a change record or an error. */
	private static function apply_edit( &$elements, $edit, $n ) {
		if ( ! is_array( $edit ) || ! isset( $edit['id'], $edit['field'] ) || ! is_scalar( $edit['id'] ) || ! is_string( $edit['field'] ) || ! array_key_exists( 'value', $edit ) || ! is_scalar( $edit['value'] ) ) {
			return self::bad( sprintf( 'Edit %d must look like {"id":"e41c5","field":"text","value":"New words"}.', $n ) );
		}
		if ( mb_strlen( (string) $edit['value'] ) > self::MAX_LENGTH ) { return self::bad( sprintf( 'Edit %d is longer than %d characters.', $n, self::MAX_LENGTH ) ); }
		$el = &self::find( $elements, $edit['id'] );
		if ( null === $el ) { return self::bad( sprintf( 'Edit %d: no element with id "%s". Read the page with wp_get_elementor to see the ids.', $n, (string) $edit['id'] ) ); }
		if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) { return self::bad( sprintf( 'Edit %d: element "%s" has no settings to change.', $n, (string) $edit['id'] ) ); }
		$path = explode( '.', $edit['field'] );
		$last = array_pop( $path );
		if ( ! in_array( $last, self::TEXT_KEYS, true ) ) {
			return self::bad( sprintf( 'Edit %d: "%s" is not a text setting. Free can change text only (%s). Changing anything else needs WP MCP Plus.', $n, $last, implode( ', ', array_slice( self::TEXT_KEYS, 0, 8 ) ) . ', ...' ) );
		}
		$slot = &$el['settings'];
		foreach ( $path as $segment ) {
			if ( ! is_array( $slot ) || ! array_key_exists( $segment, $slot ) ) { return self::bad( sprintf( 'Edit %d: "%s" does not exist on element "%s".', $n, $edit['field'], (string) $edit['id'] ) ); }
			$slot = &$slot[ $segment ];
		}
		if ( ! is_array( $slot ) || ! array_key_exists( $last, $slot ) || ! self::is_text( $last, $slot[ $last ] ) ) {
			return self::bad( sprintf( 'Edit %d: element "%s" has no text in "%s". Only existing text can be changed.', $n, (string) $edit['id'], $edit['field'] ) );
		}
		$old = self::read_text( $slot[ $last ] );
		self::write_text( $slot[ $last ], self::clean( $edit['value'] ) );
		return array( 'id' => (string) $edit['id'], 'field' => $edit['field'], 'from' => self::shorten( $old ), 'to' => self::shorten( self::read_text( $slot[ $last ] ) ) );
	}

	public static function edit( $id, $args ) {
		$post = get_post( (int) $id );
		if ( ! $post ) { return new WP_Error( 'wpmcp_not_found', 'Post not found.', array( 'status' => 404 ) ); }
		$edits   = isset( $args['edits'] ) ? $args['edits'] : array();
		$replace = isset( $args['replace'] ) ? $args['replace'] : array();
		if ( ! is_array( $edits ) || ! is_array( $replace ) ) { return self::bad( 'edits and replace must be lists.' ); }
		if ( ! $edits && ! $replace ) { return self::bad( 'Give edits (a list of {id, field, value}) or replace (a list of {find, replace}).' ); }
		if ( count( $edits ) > self::MAX_EDITS || count( $replace ) > self::MAX_REPLACE ) { return self::bad( sprintf( 'At most %d edits and %d replacements per call.', self::MAX_EDITS, self::MAX_REPLACE ) ); }

		$data = WPMCP_Elementor::get_data( (int) $id );
		if ( ! $data['has_elementor'] || ! $data['elements'] ) { return self::bad( 'This page has no Elementor layout. Use wp_update_content for pages that are not built with Elementor.' ); }
		$original = $data['elements'];
		$elements = $original;
		$changes  = array();

		foreach ( array_values( $edits ) as $i => $edit ) {
			$change = self::apply_edit( $elements, $edit, $i + 1 );
			if ( is_wp_error( $change ) ) { return $change; }
			$changes[] = $change;
		}
		$replaced = array();
		foreach ( array_values( $replace ) as $i => $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['find'], $rule['replace'] ) || ! is_string( $rule['find'] ) || '' === $rule['find'] || ! is_scalar( $rule['replace'] ) || mb_strlen( $rule['find'] ) > 2000 || mb_strlen( (string) $rule['replace'] ) > self::MAX_LENGTH ) {
				return self::bad( sprintf( 'Replacement %d must look like {"find":"old words","replace":"new words"}.', $i + 1 ) );
			}
			$count = self::replace_in( $elements, $rule['find'], (string) $rule['replace'] );
			if ( 0 === $count ) { return self::bad( sprintf( 'Replacement %d: "%s" was not found in any text on this page.', $i + 1, self::shorten( $rule['find'] ) ) ); }
			$replaced[] = array( 'find' => self::shorten( $rule['find'] ), 'replace' => self::shorten( $rule['replace'] ), 'fields_changed' => $count );
		}

		// Only text may differ from the original.
		if ( wp_json_encode( self::skeleton( $elements ) ) !== wp_json_encode( self::skeleton( $original ) ) ) {
			return self::bad( 'The edit would have changed more than text, so nothing was saved.' );
		}
		if ( wp_json_encode( $elements ) === wp_json_encode( $original ) ) {
			return array( 'ok' => true, 'id' => (int) $id, 'changed' => 0, 'note' => 'The text was already what you asked for. Nothing changed.' );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array( 'ok' => true, 'dry_run' => true, 'id' => (int) $id, 'edits' => $changes, 'replacements' => $replaced );
		}

		$history = WPMCP_History::begin_state( (int) $id, WPMCP_History::scope_for_elementor() );
		$result  = WPMCP_Elementor::set_data( (int) $id, $elements );
		if ( is_wp_error( $result ) ) { return $result; }
		$total = count( $changes ) + array_sum( array_column( $replaced, 'fields_changed' ) );
		WPMCP_History::finish_state( $history, 'wp_edit_elementor_text', sprintf( 'Changed %d text field(s) on an Elementor page', $total ) );
		return array( 'ok' => true, 'id' => (int) $id, 'changed' => $total, 'edits' => $changes, 'replacements' => $replaced, 'link' => get_permalink( (int) $id ), 'note' => 'Only text was changed. The layout is exactly as it was.' );
	}
}
