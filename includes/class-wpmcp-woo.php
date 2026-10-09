<?php
/**
 * WooCommerce support.
 *
 * Everything goes through WooCommerce's own objects and functions (WC_Product, WC_Order, WC_Coupon,
 * wc_create_order, wc_create_refund, wc_create_attribute ...), so stock, prices, lookup tables, caches and
 * order emails behave exactly as they do in the WooCommerce admin. Nothing here writes WooCommerce's tables
 * or private meta directly. Input is checked completely before anything is saved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Woo {
	const TYPES    = array( 'simple', 'variable', 'grouped', 'external' );
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );
	const ADDRESS  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
	const ORDER_FIELDS = array( 'status', 'billing', 'shipping', 'customer_note', 'customer_id', 'payment_method', 'payment_method_title' );
	const OWNED    = array( 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon' );
	// Order of application matters: stock management before quantity, attributes before defaults.
	const ORDER_OF_PROPS = array(
		'name', 'slug', 'status', 'catalog_visibility', 'featured', 'description', 'short_description', 'purchase_note', 'sku', 'regular_price', 'sale_price',
		'date_on_sale_from', 'date_on_sale_to', 'tax_status', 'tax_class', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount',
		'sold_individually', 'weight', 'length', 'width', 'height', 'shipping_class_id', 'virtual', 'downloadable', 'downloads', 'download_limit', 'download_expiry',
		'category_ids', 'tag_ids', 'image_id', 'gallery_image_ids', 'attributes', 'default_attributes', 'upsell_ids', 'cross_sell_ids', 'menu_order',
		'reviews_allowed', 'product_url', 'button_text', 'children', 'meta',
	);

	/* ----------------------------------------------------------------- */
	/* Detection                                                         */
	/* ----------------------------------------------------------------- */

	public static function available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && function_exists( 'wc_get_order' ) && function_exists( 'wc_create_order' );
	}

	/** True for post types WooCommerce owns: writes go through the WooCommerce tools so its stock, price and order rules apply. */
	public static function owns_type( $type ) {
		return self::available() && in_array( $type, self::OWNED, true );
	}

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public static function status() {
		if ( ! self::available() ) { return array( 'active' => false ); }
		return array(
			'active'         => true,
			'version'        => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'currency'       => (string) get_option( 'woocommerce_currency', '' ),
			'orders_storage' => self::hpos() ? 'custom tables (HPOS)' : 'posts',
			'taxes'          => function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : ( 'yes' === get_option( 'woocommerce_calc_taxes', 'no' ) ),
			'coupons'        => function_exists( 'wc_coupons_enabled' ) ? (bool) wc_coupons_enabled() : ( 'no' !== get_option( 'woocommerce_enable_coupons', 'yes' ) ),
		);
	}

	private static function missing() {
		return new WP_Error( 'wpmcp_woo_missing', 'WooCommerce is not active on this site. Install and activate WooCommerce, then try again.', array( 'status' => 400 ) );
	}

	private static function bad( $message ) {
		return new WP_Error( 'wpmcp_woo_invalid', $message, array( 'status' => 400 ) );
	}

	/** Run a WooCommerce operation; an exception from WooCommerce becomes a plain error instead of a crash. */
	private static function guarded( $fn ) {
		if ( ! self::available() ) { return self::missing(); }
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			return self::bad( $e->getMessage() );
		}
	}

	private static function record( $tool, $type, $id, $label, $summary, $data, $hash = null ) {
		return WPMCP_History::record( $tool, $type, (int) $id, $label, $summary, $data, $hash );
	}

	private static function iso( $date ) {
		return $date ? $date->date( 'c' ) : '';
	}

	/* ----------------------------------------------------------------- */
	/* Input checks                                                      */
	/* ----------------------------------------------------------------- */

	private static function number( $key, $value, $allow_empty = true ) {
		if ( '' === $value || null === $value ) { return $allow_empty ? '' : self::bad( "$key needs a number." ); }
		if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 99999999 ) { return self::bad( "$key must be a number, zero or more." ); }
		return wc_format_decimal( $value );
	}

	private static function whole( $key, $value, $min, $max ) {
		if ( ! is_numeric( $value ) || (int) $value != $value || (int) $value < $min || (int) $value > $max ) { return self::bad( "$key must be a whole number from $min to $max." ); }
		return (int) $value;
	}

	private static function flag( $key, $value ) {
		$b = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $b ? self::bad( "$key must be true or false." ) : $b;
	}

	private static function choice( $key, $value, $options ) {
		return is_string( $value ) && in_array( $value, $options, true ) ? $value : self::bad( sprintf( '%s must be one of: %s.', $key, implode( ', ', $options ) ) );
	}

	private static function day( $key, $value ) {
		if ( '' === $value || null === $value ) { return ''; }
		$d = is_string( $value ) ? DateTime::createFromFormat( '!Y-m-d', $value ) : false;
		return $d && $d->format( 'Y-m-d' ) === $value ? $value : self::bad( "$key must be a date like 2026-12-31, or empty to clear it." );
	}

	private static function text( $key, $value, $max ) {
		if ( ! is_scalar( $value ) ) { return self::bad( "$key must be text." ); }
		$text = sanitize_text_field( (string) $value );
		return mb_strlen( $text ) <= $max ? $text : self::bad( "$key is longer than $max characters." );
	}

	/** Text that may span lines, such as an order note. */
	private static function multiline( $key, $value, $max ) {
		if ( ! is_scalar( $value ) ) { return self::bad( "$key must be text." ); }
		$text = sanitize_textarea_field( (string) $value );
		return mb_strlen( $text ) <= $max ? $text : self::bad( "$key is longer than $max characters." );
	}

	private static function html( $key, $value ) {
		if ( ! is_scalar( $value ) ) { return self::bad( "$key must be text." ); }
		return mb_strlen( (string) $value ) <= 200000 ? wp_kses_post( (string) $value ) : self::bad( "$key is too long." );
	}

	private static function id_list( $key, $value, $post_check = true ) {
		if ( ! is_array( $value ) ) { return self::bad( "$key must be a list of IDs." ); }
		$out = array();
		foreach ( $value as $id ) {
			if ( ! is_numeric( $id ) || (int) $id < 1 ) { return self::bad( "$key must contain only product IDs." ); }
			if ( $post_check && ! wc_get_product( (int) $id ) ) { return self::bad( sprintf( '%s: no product with ID %d.', $key, (int) $id ) ); }
			$out[] = (int) $id;
		}
		return array_values( array_unique( $out ) );
	}

	/** Term IDs from IDs, slugs or names. Unknown terms are created only when $create is true. */
	private static function term_ids( $key, $list, $taxonomy, $create ) {
		if ( ! is_array( $list ) ) { return self::bad( "$key must be a list." ); }
		if ( ! taxonomy_exists( $taxonomy ) ) { return self::bad( "$key: the $taxonomy taxonomy does not exist on this site." ); }
		$ids = array();
		foreach ( $list as $item ) {
			$term = null;
			if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				$term = get_term( (int) $item, $taxonomy );
			} elseif ( is_string( $item ) && '' !== trim( $item ) ) {
				$term = get_term_by( 'slug', sanitize_title( $item ), $taxonomy );
				if ( ! $term ) { $term = get_term_by( 'name', $item, $taxonomy ); }
			} else {
				return self::bad( "$key must contain IDs, slugs or names." );
			}
			if ( ! $term || is_wp_error( $term ) ) {
				if ( $create && is_string( $item ) && '' !== trim( $item ) && ! ctype_digit( trim( $item ) ) ) {
					$made = wp_insert_term( sanitize_text_field( $item ), $taxonomy );
					if ( is_wp_error( $made ) ) { return self::bad( sprintf( '%s: could not create "%s" (%s).', $key, $item, $made->get_error_message() ) ); }
					$ids[] = (int) $made['term_id'];
					continue;
				}
				return self::bad( sprintf( '%s: "%s" does not exist.%s', $key, (string) $item, $create ? '' : ' Create it first with wp_woo_save_category.' ) );
			}
			$ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( $ids ) );
	}

	private static function attachment_ids( $key, $list ) {
		if ( ! is_array( $list ) ) { return self::bad( "$key must be a list of media IDs." ); }
		$out = array();
		foreach ( $list as $id ) {
			if ( ! is_numeric( $id ) || (int) $id < 1 || 'attachment' !== get_post_type( (int) $id ) ) { return self::bad( sprintf( '%s: %s is not a media library item. Upload it first with wp_upload_media.', $key, is_scalar( $id ) ? (string) $id : 'that value' ) ); }
			$out[] = (int) $id;
		}
		return $out;
	}

	private static function shipping_class_id( $value ) {
		if ( '' === $value || null === $value || 0 === $value || '0' === $value ) { return 0; }
		$term = ( is_int( $value ) || ctype_digit( (string) $value ) ) ? get_term( (int) $value, 'product_shipping_class' ) : get_term_by( 'slug', sanitize_title( (string) $value ), 'product_shipping_class' );
		return $term && ! is_wp_error( $term ) ? (int) $term->term_id : self::bad( 'shipping_class does not exist. See wp_woo_list_config with what=shipping.' );
	}

	private static function tax_class( $value, $variation ) {
		if ( ! is_string( $value ) ) { return self::bad( 'tax_class must be text.' ); }
		if ( '' === $value || ( $variation && 'parent' === $value ) ) { return $value; }
		$slugs = class_exists( 'WC_Tax' ) ? WC_Tax::get_tax_class_slugs() : array();
		return in_array( $value, $slugs, true ) ? $value : self::bad( sprintf( 'tax_class must be empty (standard)%s or one of: %s.', $variation ? ', "parent"' : '', implode( ', ', $slugs ) ) );
	}

	private static function download_list( $value ) {
		if ( ! is_array( $value ) ) { return self::bad( 'downloads must be a list of {name, file}.' ); }
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array( 'baseurl' => '' );
		$out     = array();
		foreach ( array_values( $value ) as $d ) {
			$file = is_array( $d ) && isset( $d['file'] ) && is_string( $d['file'] ) ? trim( $d['file'] ) : '';
			if ( '' === $file || strlen( $file ) > 2000 || ! preg_match( '#^https?://#i', $file ) || false !== strpos( $file, '..' ) || false !== strpos( $file, '[' ) ) { return self::bad( 'Each download needs a "file" that is a full http(s) URL, for example a file in the media library. Server paths and shortcodes are refused.' ); }
			$name  = isset( $d['name'] ) ? self::text( 'download name', $d['name'], 200 ) : '';
			if ( is_wp_error( $name ) ) { return $name; }
			$out[] = array( 'name' => '' !== $name ? $name : wp_basename( $file ), 'file' => esc_url_raw( $file ) );
		}
		return $out;
	}

	private static function public_meta( $value ) {
		if ( ! is_array( $value ) ) { return self::bad( 'meta must be an object of custom field names and values.' ); }
		foreach ( $value as $k => $v ) {
			if ( ! is_string( $k ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.\- ]{0,190}$/', $k ) || 'total_sales' === $k || 0 === strpos( $k, 'attribute_' ) ) { return self::bad( sprintf( 'meta key "%s" is not allowed. Keys must not start with an underscore (those belong to WooCommerce), and total_sales and attribute_* are also WooCommerce internals.', (string) $k ) ); }
			if ( ! is_scalar( $v ) && null !== $v && ! is_array( $v ) ) { return self::bad( "meta $k must be text, a number, a list or empty." ); }
		}
		return $value;
	}

	/* ----------------------------------------------------------------- */
	/* Attributes                                                        */
	/* ----------------------------------------------------------------- */

	private static function global_attribute( $spec ) {
		$wanted = array();
		foreach ( array( 'taxonomy', 'slug', 'name' ) as $field ) { if ( isset( $spec[ $field ] ) && is_string( $spec[ $field ] ) ) { $wanted[] = mb_strtolower( trim( $spec[ $field ] ) ); } }
		foreach ( (array) wc_get_attribute_taxonomies() as $tax ) {
			$id_match = isset( $spec['id'] ) && (int) $spec['id'] === (int) $tax->attribute_id;
			if ( $id_match || in_array( mb_strtolower( 'pa_' . $tax->attribute_name ), $wanted, true ) || in_array( mb_strtolower( $tax->attribute_name ), $wanted, true ) || in_array( mb_strtolower( $tax->attribute_label ), $wanted, true ) ) { return $tax; }
		}
		return null;
	}

	/** Attribute key a product stores variation values under: the taxonomy for global attributes, the sanitized name for custom ones. */
	private static function attribute_key( $attribute ) {
		return $attribute['id'] ? $attribute['name'] : sanitize_title( $attribute['name'] );
	}

	private static function clean_attributes( $list, $type ) {
		if ( ! is_array( $list ) ) { return self::bad( 'attributes must be a list.' ); }
		$out  = array();
		$seen = array();
		foreach ( array_values( $list ) as $i => $spec ) {
			if ( ! is_array( $spec ) ) { return self::bad( 'Each attribute must be an object like {"name":"Color","options":["Red","Blue"]}.' ); }
			$options = isset( $spec['options'] ) ? $spec['options'] : array();
			if ( ! is_array( $options ) || ! $options ) { return self::bad( 'Each attribute needs "options", a list of values.' ); }
			$global = self::global_attribute( $spec );
			if ( ! $global && ( ! isset( $spec['name'] ) || ! is_string( $spec['name'] ) || '' === trim( $spec['name'] ) ) ) { return self::bad( sprintf( 'Attribute %d needs a "name" (or the "taxonomy" of a global attribute such as pa_color).', $i + 1 ) ); }
			if ( ! $global && ( isset( $spec['id'] ) || isset( $spec['taxonomy'] ) ) ) { return self::bad( 'No global attribute matches that id or taxonomy. See wp_woo_list_config with what=attributes.' ); }
			if ( $global ) {
				$taxonomy = wc_attribute_taxonomy_name( $global->attribute_name );
				$ids      = self::term_ids( 'attribute ' . $global->attribute_label, $options, $taxonomy, true );
				if ( is_wp_error( $ids ) ) { return $ids; }
				$entry = array( 'id' => (int) $global->attribute_id, 'name' => $taxonomy, 'options' => $ids );
			} else {
				$name = self::text( 'attribute name', $spec['name'], 100 );
				if ( is_wp_error( $name ) ) { return $name; }
				$values = array();
				foreach ( $options as $option ) {
					$value = is_scalar( $option ) ? sanitize_text_field( (string) $option ) : '';
					if ( '' === $value || false !== strpos( $value, '|' ) ) { return self::bad( sprintf( 'Attribute "%s" has an empty option or one containing "|".', $name ) ); }
					$values[] = $value;
				}
				$entry = array( 'id' => 0, 'name' => $name, 'options' => array_values( array_unique( $values ) ) );
			}
			$key = self::attribute_key( $entry );
			if ( isset( $seen[ $key ] ) ) { return self::bad( sprintf( 'The attribute "%s" is listed twice.', $entry['name'] ) ); }
			$seen[ $key ] = true;
			$variation    = isset( $spec['variation'] ) ? self::flag( 'attribute variation', $spec['variation'] ) : false;
			$visible      = isset( $spec['visible'] ) ? self::flag( 'attribute visible', $spec['visible'] ) : true;
			if ( is_wp_error( $variation ) ) { return $variation; }
			if ( is_wp_error( $visible ) ) { return $visible; }
			if ( $variation && 'variable' !== $type ) { return self::bad( 'variation=true only applies to variable products.' ); }
			$entry['position']  = isset( $spec['position'] ) ? (int) $spec['position'] : $i;
			$entry['visible']   = $visible;
			$entry['variation'] = $variation;
			$out[]              = $entry;
		}
		return $out;
	}

	/** Check a {attribute: value} map against a product's attributes. $used_for_variations limits it to those flagged for variations. */
	private static function check_attribute_values( $map, $attributes, $what, $strict_variation ) {
		if ( ! is_array( $map ) ) { return self::bad( "$what must be an object of attribute names and values." ); }
		$by_key = array();
		foreach ( $attributes as $a ) {
			if ( $strict_variation && empty( $a['variation'] ) ) { continue; }
			$by_key[ self::attribute_key( $a ) ] = $a;
			$by_key[ mb_strtolower( $a['name'] ) ] = $a;
			if ( $a['id'] ) { $by_key[ mb_strtolower( preg_replace( '/^pa_/', '', $a['name'] ) ) ] = $a; $label = wc_attribute_label( $a['name'] ); $by_key[ mb_strtolower( $label ) ] = $a; }
		}
		$out = array();
		foreach ( $map as $name => $value ) {
			$lookup = mb_strtolower( (string) $name );
			if ( ! isset( $by_key[ $lookup ] ) && ! isset( $by_key[ sanitize_title( (string) $name ) ] ) ) { return self::bad( sprintf( '%s: "%s" is not %s of this product.', $what, (string) $name, $strict_variation ? 'an attribute used for variations' : 'an attribute' ) ); }
			$attr = isset( $by_key[ $lookup ] ) ? $by_key[ $lookup ] : $by_key[ sanitize_title( (string) $name ) ];
			$key  = self::attribute_key( $attr );
			if ( '' === $value || null === $value ) { $out[ $key ] = ''; continue; } // Empty means "any".
			if ( ! is_scalar( $value ) ) { return self::bad( "$what: the value for $name must be text." ); }
			if ( $attr['id'] ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $value ), $attr['name'] );
				if ( ! $term ) { $term = get_term_by( 'name', (string) $value, $attr['name'] ); }
				if ( ! $term || ! in_array( (int) $term->term_id, array_map( 'intval', $attr['options'] ), true ) ) { return self::bad( sprintf( '%s: "%s" is not one of the options of %s.', $what, (string) $value, $name ) ); }
				$out[ $key ] = $term->slug;
			} else {
				if ( ! in_array( (string) $value, array_map( 'strval', $attr['options'] ), true ) ) { return self::bad( sprintf( '%s: "%s" is not one of the options of %s (%s).', $what, (string) $value, $name, implode( ', ', $attr['options'] ) ) ); }
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/** A product's attributes in the same shape clean_attributes() returns. */
	private static function attributes_of( $product ) {
		$out = array();
		foreach ( $product->get_attributes( 'edit' ) as $a ) {
			$out[] = array( 'id' => (int) $a->get_id(), 'name' => $a->get_name(), 'options' => $a->get_options(), 'position' => (int) $a->get_position(), 'visible' => (bool) $a->get_visible(), 'variation' => (bool) $a->get_variation() );
		}
		return $out;
	}

	/* ----------------------------------------------------------------- */
	/* Reading and writing one property                                  */
	/* ----------------------------------------------------------------- */

	private static function read_prop( $p, $key ) {
		switch ( $key ) {
			case 'date_on_sale_from':
				$d = $p->get_date_on_sale_from( 'edit' ); return $d ? $d->date( 'Y-m-d' ) : '';
			case 'date_on_sale_to':
				$d = $p->get_date_on_sale_to( 'edit' ); return $d ? $d->date( 'Y-m-d' ) : '';
			case 'downloads':
				$out = array();
				foreach ( $p->get_downloads( 'edit' ) as $d ) { $out[] = array( 'name' => $d->get_name(), 'file' => $d->get_file() ); }
				return $out;
			case 'attributes':
				return $p->is_type( 'variation' ) ? (array) $p->get_attributes( 'edit' ) : self::attributes_of( $p );
			case 'children':
				return array_map( 'intval', (array) $p->get_children( 'edit' ) );
			default:
				return $p->{ 'get_' . $key }( 'edit' );
		}
	}

	private static function write_prop( $p, $key, $value ) {
		switch ( $key ) {
			case 'name': case 'slug': case 'description': case 'short_description':
				$p->{ 'set_' . $key }( wp_slash( $value ) ); break; // The post APIs underneath unslash their input.
			case 'downloads':
				$list = array();
				foreach ( $value as $d ) {
					$download = new WC_Product_Download();
					$download->set_id( md5( $d['file'] ) );
					$download->set_name( $d['name'] );
					$download->set_file( $d['file'] );
					$list[ $download->get_id() ] = $download;
				}
				$p->set_downloads( $list ); break;
			case 'attributes':
				if ( $p->is_type( 'variation' ) ) { $p->set_attributes( $value ); break; }
				$list = array();
				foreach ( $value as $a ) {
					$attr = new WC_Product_Attribute();
					$attr->set_id( $a['id'] );
					$attr->set_name( $a['name'] );
					$attr->set_options( $a['options'] );
					$attr->set_position( $a['position'] );
					$attr->set_visible( $a['visible'] );
					$attr->set_variation( $a['variation'] );
					$list[] = $attr;
				}
				$p->set_attributes( $list ); break;
			case 'meta':
				foreach ( $value as $k => $v ) { if ( null === $v ) { $p->delete_meta_data( $k ); } else { $p->update_meta_data( $k, $v ); } }
				break;
			default:
				$p->{ 'set_' . $key }( $value );
		}
	}

	/** The named properties of a product, as plain data (the undo snapshot, and the shape clean_* returns). */
	private static function snapshot( $p, $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( 'meta' === $key ) { continue; }
			try {
				$out[ $key ] = self::read_prop( $p, $key );
			} catch ( \Error $e ) {
				continue; // The old type may not have the property (product_url on a simple product).
			}
		}
		return $out;
	}

	private static function snapshot_meta( $p, $names ) {
		$out = array();
		foreach ( $names as $name ) { $out[ $name ] = metadata_exists( 'post', $p->get_id(), $name ) ? $p->get_meta( $name, true, 'edit' ) : null; }
		return $out;
	}

	private static function apply_props( $p, $clean ) {
		foreach ( self::ORDER_OF_PROPS as $key ) {
			if ( array_key_exists( $key, $clean ) ) { self::write_prop( $p, $key, $clean[ $key ] ); }
		}
	}

	private static function state_of( $p, $clean ) {
		$keys = array_keys( $clean );
		$data = self::snapshot( $p, array_diff( $keys, array( 'meta', 'type' ) ) );
		if ( isset( $clean['meta'] ) ) { $data['meta'] = self::snapshot_meta( $p, array_keys( $clean['meta'] ) ); }
		return $data;
	}

	/* ----------------------------------------------------------------- */
	/* Product input                                                     */
	/* ----------------------------------------------------------------- */

	private static function product_keys( $variation ) {
		if ( $variation ) {
			return array( 'status', 'description', 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'tax_class', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount', 'weight', 'length', 'width', 'height', 'shipping_class', 'virtual', 'downloadable', 'downloads', 'download_limit', 'download_expiry', 'image', 'attributes', 'menu_order', 'meta' );
		}
		return array( 'name', 'slug', 'status', 'catalog_visibility', 'featured', 'description', 'short_description', 'purchase_note', 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'tax_status', 'tax_class', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount', 'sold_individually', 'weight', 'length', 'width', 'height', 'shipping_class', 'virtual', 'downloadable', 'downloads', 'download_limit', 'download_expiry', 'categories', 'tags', 'images', 'attributes', 'default_attributes', 'upsell_ids', 'cross_sell_ids', 'menu_order', 'reviews_allowed', 'product_url', 'button_text', 'children', 'meta', 'acf' );
	}

	/**
	 * Check a product or variation description completely and return it as clean properties, or an error.
	 * $type is simple, variable, grouped, external or variation. $existing is the product being changed, if any.
	 */
	private static function clean_input( $in, $type, $existing ) {
		$variation = 'variation' === $type;
		$allowed   = self::product_keys( $variation );
		foreach ( array_keys( $in ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { return self::bad( sprintf( '"%s" is not a field of a %s. Allowed: %s.', $key, $variation ? 'variation' : 'product', implode( ', ', $allowed ) ) ); }
		}
		$out = array();
		foreach ( $in as $key => $value ) {
			switch ( $key ) {
				case 'name':
					$v = self::text( $key, $value, 200 ); if ( ! is_wp_error( $v ) && '' === $v ) { $v = self::bad( 'name cannot be empty.' ); } break;
				case 'slug':        $v = is_string( $value ) ? sanitize_title( $value ) : self::bad( 'slug must be text.' ); break;
				case 'status':      $v = self::choice( $key, $value, $variation ? array( 'publish', 'private' ) : self::STATUSES ); break;
				case 'catalog_visibility': $v = self::choice( $key, $value, array( 'visible', 'catalog', 'search', 'hidden' ) ); break;
				case 'tax_status':  $v = self::choice( $key, $value, array( 'taxable', 'shipping', 'none' ) ); break;
				case 'stock_status': $v = self::choice( $key, $value, array( 'instock', 'outofstock', 'onbackorder' ) ); break;
				case 'backorders':  $v = self::choice( $key, $value, array( 'no', 'notify', 'yes' ) ); break;
				case 'featured': case 'sold_individually': case 'virtual': case 'downloadable': case 'reviews_allowed':
					$v = self::flag( $key, $value ); break;
				case 'manage_stock': $v = $variation && 'parent' === $value ? 'parent' : self::flag( $key, $value ); break;
				case 'description': case 'short_description': case 'purchase_note':
					$v = self::html( $key, $value ); break;
				case 'sku':
					$v = self::text( $key, $value, 100 );
					if ( ! is_wp_error( $v ) && '' !== $v ) {
						$owner = (int) wc_get_product_id_by_sku( $v );
						if ( $owner && ( ! $existing || $owner !== (int) $existing->get_id() ) ) { $v = self::bad( sprintf( 'The SKU "%s" is already used by product %d.', $v, $owner ) ); }
					}
					break;
				case 'regular_price': case 'sale_price': case 'weight': case 'length': case 'width': case 'height':
					$v = self::number( $key, $value ); break;
				case 'stock_quantity': $v = self::whole( $key, $value, -1000000, 1000000 ); break;
				case 'low_stock_amount': $v = '' === $value || null === $value ? '' : self::whole( $key, $value, 0, 1000000 ); break;
				case 'download_limit': case 'download_expiry': $v = '' === $value || null === $value ? -1 : self::whole( $key, $value, -1, 100000 ); break;
				case 'menu_order':  $v = self::whole( $key, $value, -1000000, 1000000 ); break;
				case 'date_on_sale_from': case 'date_on_sale_to': $v = self::day( $key, $value ); break;
				case 'tax_class':   $v = self::tax_class( $value, $variation ); break;
				case 'shipping_class': $v = self::shipping_class_id( $value ); $key = 'shipping_class_id'; break;
				case 'downloads':   $v = self::download_list( $value ); break;
				case 'upsell_ids': case 'cross_sell_ids': $v = self::id_list( $key, $value ); break;
				case 'children':    $v = self::id_list( $key, $value ); break;
				case 'product_url':
					$v = is_string( $value ) && preg_match( '#^https?://#i', $value ) && strlen( $value ) <= 2000 ? esc_url_raw( $value ) : self::bad( 'product_url must be a full http(s) address.' ); break;
				case 'button_text': $v = self::text( $key, $value, 100 ); break;
				case 'categories':  $v = self::term_ids( $key, $value, 'product_cat', false ); $key = 'category_ids'; break;
				case 'tags':        $v = self::term_ids( $key, $value, 'product_tag', true ); $key = 'tag_ids'; break;
				case 'images':
					$v = self::attachment_ids( $key, $value );
					if ( ! is_wp_error( $v ) ) { $out['image_id'] = $v ? $v[0] : 0; $v = array_slice( $v, 1 ); $key = 'gallery_image_ids'; }
					break;
				case 'image':
					$v = '' === $value || 0 === $value || null === $value ? array( 0 ) : self::attachment_ids( $key, array( $value ) );
					if ( ! is_wp_error( $v ) ) { $v = $v[0]; $key = 'image_id'; }
					break;
				case 'meta':        $v = self::public_meta( $value ); break;
				case 'acf':         $v = is_array( $value ) ? $value : self::bad( 'acf must be an object of field names and values.' ); break;
				case 'attributes':
					$v = $variation ? ( is_array( $value ) ? $value : self::bad( 'attributes must be an object of attribute names and values.' ) ) : self::clean_attributes( $value, $type ); break;
				case 'default_attributes':
					$v = $value; break; // Checked below against the final attributes.
				default:
					$v = self::bad( "$key is not supported." );
			}
			if ( is_wp_error( $v ) ) { return $v; }
			$out[ $key ] = $v;
		}
		return self::check_together( $out, $type, $existing );
	}

	/** Rules that involve more than one field, judged on the result (the new values over the existing ones). */
	private static function check_together( $out, $type, $existing ) {
		$final = function ( $key, $default ) use ( $out, $existing ) {
			if ( array_key_exists( $key, $out ) ) { return $out[ $key ]; }
			return $existing ? self::read_prop( $existing, $key ) : $default;
		};
		if ( in_array( $type, array( 'variable', 'grouped' ), true ) ) {
			foreach ( array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ) as $price_key ) {
				if ( array_key_exists( $price_key, $out ) ) { return self::bad( sprintf( 'A %s product has no price of its own. %s', $type, 'variable' === $type ? 'Set prices on its variations with wp_woo_save_variation.' : 'Its price comes from the products it groups.' ) ); }
			}
		}
		if ( 'external' !== $type && ( isset( $out['product_url'] ) || isset( $out['button_text'] ) ) ) { return self::bad( 'product_url and button_text apply to external products only.' ); }
		if ( 'grouped' !== $type && isset( $out['children'] ) ) { return self::bad( 'children applies to grouped products only.' ); }
		if ( array_key_exists( 'downloads', $out ) && $out['downloads'] && ! $final( 'downloadable', false ) ) { return self::bad( 'Turn on downloadable to attach downloads.' ); }
		if ( array_key_exists( 'stock_quantity', $out ) && true !== $final( 'manage_stock', false ) && 'parent' !== $final( 'manage_stock', false ) ) { return self::bad( 'Turn on manage_stock to set a stock quantity. Without it use stock_status.' ); }
		if ( 'parent' === $final( 'manage_stock', false ) && array_key_exists( 'stock_quantity', $out ) ) { return self::bad( 'This variation follows its parent\'s stock. Set manage_stock to true to give it its own quantity.' ); }
		if ( array_key_exists( 'sale_price', $out ) || array_key_exists( 'regular_price', $out ) ) {
			$regular = (string) $final( 'regular_price', '' );
			$sale    = (string) $final( 'sale_price', '' );
			if ( '' !== $sale && '' === $regular ) { return self::bad( 'A sale price needs a regular price.' ); }
			if ( '' !== $sale && (float) $sale > (float) $regular ) { return self::bad( sprintf( 'The sale price (%s) is higher than the regular price (%s).', $sale, $regular ) ); }
		}
		$from = (string) $final( 'date_on_sale_from', '' );
		$to   = (string) $final( 'date_on_sale_to', '' );
		if ( ( array_key_exists( 'date_on_sale_from', $out ) || array_key_exists( 'date_on_sale_to', $out ) ) && '' !== $from && '' !== $to && $from > $to ) { return self::bad( 'The sale starts after it ends.' ); }
		if ( 'variation' !== $type && ( isset( $out['attributes'] ) || isset( $out['default_attributes'] ) ) ) {
			$attributes = isset( $out['attributes'] ) ? $out['attributes'] : ( $existing ? self::attributes_of( $existing ) : array() );
			if ( isset( $out['attributes'] ) && 'variable' === $type && $existing ) {
				// Removing an attribute that variations rely on would orphan them.
				$now = array();
				foreach ( $out['attributes'] as $a ) { if ( $a['variation'] ) { $now[ self::attribute_key( $a ) ] = true; } }
				foreach ( self::attributes_of( $existing ) as $old ) { if ( $old['variation'] && ! isset( $now[ self::attribute_key( $old ) ] ) && $existing->get_children() ) { return self::bad( sprintf( 'The attribute "%s" is used by existing variations. Delete the variations first, or keep the attribute.', $old['name'] ) ); } }
			}
			if ( isset( $out['default_attributes'] ) ) {
				if ( 'variable' !== $type ) { return self::bad( 'default_attributes applies to variable products only.' ); }
				$checked = self::check_attribute_values( $out['default_attributes'], $attributes, 'default_attributes', true );
				if ( is_wp_error( $checked ) ) { return $checked; }
				$out['default_attributes'] = $checked;
			}
		}
		return $out;
	}

	/* ----------------------------------------------------------------- */
	/* Showing products                                                  */
	/* ----------------------------------------------------------------- */

	private static function names( $ids, $taxonomy ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$term = get_term( (int) $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) { $out[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ); }
		}
		return $out;
	}

	private static function image( $id ) {
		return $id ? array( 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( (int) $id ) ) : null;
	}

	private static function row( $p ) {
		$row = array(
			'id' => $p->get_id(), 'type' => $p->get_type(), 'name' => $p->get_name(), 'status' => $p->get_status(), 'sku' => $p->get_sku(),
			'price' => $p->get_price(), 'regular_price' => $p->get_regular_price(), 'sale_price' => $p->get_sale_price(), 'on_sale' => (bool) $p->is_on_sale(),
			'stock_status' => $p->get_stock_status(), 'stock_quantity' => $p->get_stock_quantity(), 'categories' => array_column( self::names( $p->get_category_ids(), 'product_cat' ), 'name' ),
			'image_id' => (int) $p->get_image_id(), 'link' => get_permalink( $p->get_id() ),
		);
		if ( $p->is_type( 'variable' ) ) { $row['variations'] = count( $p->get_children() ); }
		return $row;
	}

	private static function describe_variation( $v ) {
		return array(
			'id' => $v->get_id(), 'parent_id' => $v->get_parent_id(), 'status' => $v->get_status(), 'sku' => $v->get_sku(), 'attributes' => (array) $v->get_attributes( 'edit' ),
			'price' => $v->get_price(), 'regular_price' => $v->get_regular_price( 'edit' ), 'sale_price' => $v->get_sale_price( 'edit' ), 'on_sale' => (bool) $v->is_on_sale(),
			'date_on_sale_from' => self::read_prop( $v, 'date_on_sale_from' ), 'date_on_sale_to' => self::read_prop( $v, 'date_on_sale_to' ),
			'manage_stock' => $v->get_manage_stock( 'edit' ), 'stock_quantity' => $v->get_stock_quantity( 'edit' ), 'stock_status' => $v->get_stock_status( 'edit' ), 'backorders' => $v->get_backorders( 'edit' ),
			'weight' => $v->get_weight( 'edit' ), 'length' => $v->get_length( 'edit' ), 'width' => $v->get_width( 'edit' ), 'height' => $v->get_height( 'edit' ),
			'tax_class' => $v->get_tax_class( 'edit' ), 'virtual' => (bool) $v->get_virtual( 'edit' ), 'downloadable' => (bool) $v->get_downloadable( 'edit' ),
			'image' => self::image( $v->get_image_id( 'edit' ) ), 'description' => $v->get_description( 'edit' ), 'menu_order' => (int) $v->get_menu_order( 'edit' ),
		);
	}

	private static function describe_product( $p ) {
		$row = self::row( $p );
		unset( $row['image_id'], $row['categories'] );
		$row += array(
			'slug' => $p->get_slug(), 'catalog_visibility' => $p->get_catalog_visibility(), 'featured' => (bool) $p->get_featured(),
			'description' => $p->get_description( 'edit' ), 'short_description' => $p->get_short_description( 'edit' ), 'purchase_note' => $p->get_purchase_note( 'edit' ),
			'date_on_sale_from' => self::read_prop( $p, 'date_on_sale_from' ), 'date_on_sale_to' => self::read_prop( $p, 'date_on_sale_to' ),
			'tax_status' => $p->get_tax_status( 'edit' ), 'tax_class' => $p->get_tax_class( 'edit' ),
			'manage_stock' => (bool) $p->get_manage_stock( 'edit' ), 'backorders' => $p->get_backorders( 'edit' ), 'low_stock_amount' => $p->get_low_stock_amount( 'edit' ), 'sold_individually' => (bool) $p->get_sold_individually( 'edit' ),
			'weight' => $p->get_weight( 'edit' ), 'length' => $p->get_length( 'edit' ), 'width' => $p->get_width( 'edit' ), 'height' => $p->get_height( 'edit' ),
			'shipping_class_id' => (int) $p->get_shipping_class_id( 'edit' ), 'virtual' => (bool) $p->get_virtual( 'edit' ), 'downloadable' => (bool) $p->get_downloadable( 'edit' ),
			'downloads' => self::read_prop( $p, 'downloads' ), 'categories' => self::names( $p->get_category_ids( 'edit' ), 'product_cat' ), 'tags' => self::names( $p->get_tag_ids( 'edit' ), 'product_tag' ),
			'image' => self::image( $p->get_image_id( 'edit' ) ), 'gallery' => array_values( array_filter( array_map( array( __CLASS__, 'image' ), $p->get_gallery_image_ids( 'edit' ) ) ) ),
			'upsell_ids' => array_map( 'intval', $p->get_upsell_ids( 'edit' ) ), 'cross_sell_ids' => array_map( 'intval', $p->get_cross_sell_ids( 'edit' ) ),
			'menu_order' => (int) $p->get_menu_order( 'edit' ), 'reviews_allowed' => (bool) $p->get_reviews_allowed( 'edit' ),
			'total_sales' => (int) $p->get_total_sales( 'edit' ), 'average_rating' => $p->get_average_rating( 'edit' ), 'review_count' => (int) $p->get_review_count( 'edit' ),
		);
		$attributes = array();
		foreach ( self::attributes_of( $p ) as $a ) {
			$names = $a['id'] ? array_column( self::names( $a['options'], $a['name'] ), 'name' ) : $a['options'];
			$attributes[] = array( 'name' => $a['id'] ? wc_attribute_label( $a['name'] ) : $a['name'], 'taxonomy' => $a['id'] ? $a['name'] : '', 'global' => (bool) $a['id'], 'options' => $names, 'visible' => $a['visible'], 'variation' => $a['variation'], 'position' => $a['position'] );
		}
		$row['attributes'] = $attributes;
		if ( $p->is_type( 'external' ) ) { $row['product_url'] = $p->get_product_url( 'edit' ); $row['button_text'] = $p->get_button_text( 'edit' ); }
		if ( $p->is_type( 'grouped' ) ) { $row['children'] = array_map( 'intval', $p->get_children( 'edit' ) ); }
		if ( $p->is_type( 'variable' ) ) {
			$row['default_attributes'] = (array) $p->get_default_attributes( 'edit' );
			$row['variations']         = array();
			foreach ( array_slice( $p->get_children(), 0, 100 ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) { $row['variations'][] = self::describe_variation( $v ); }
			}
		}
		$meta = array();
		foreach ( $p->get_meta_data() as $m ) { $k = $m->key; if ( '_' !== substr( $k, 0, 1 ) ) { $meta[ $k ] = $m->value; } }
		if ( $meta ) { $row['meta'] = $meta; }
		$row['link'] = get_permalink( $p->get_id() );
		return $row;
	}

	/* ----------------------------------------------------------------- */
	/* Product tools                                                     */
	/* ----------------------------------------------------------------- */

	public static function overview() {
		return self::guarded( function () {
			$counts = array();
			foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
				$q = wc_get_products( array( 'status' => $status, 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
				$counts[ $status ] = (int) $q->total;
			}
			$orders = array();
			foreach ( wc_get_order_statuses() as $slug => $label ) {
				$orders[ $label ] = (int) wc_orders_count( preg_replace( '/^wc-/', '', $slug ) );
			}
			$settings = array();
			foreach ( self::setting_rules() as $name => $rule ) { $settings[ $name ] = array( 'value' => self::read_setting( $name, $rule ), 'about' => $rule[0] ); }
			return array(
				'woocommerce' => self::status(),
				'products'    => $counts,
				'orders'      => $orders,
				'settings'    => $settings,
				'note'        => 'Change settings with wp_woo_update_settings (Full access). Payment gateways, shipping zones and tax rates are shown with wp_woo_list_config and are not changed from here.',
			);
		} );
	}

	public static function list_products( $a ) {
		return self::guarded( function () use ( $a ) {
			$per_page = isset( $a['per_page'] ) ? max( 1, min( 100, (int) $a['per_page'] ) ) : 20;
			$page     = isset( $a['page'] ) ? max( 1, (int) $a['page'] ) : 1;
			$q        = array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'status' => array( 'publish', 'draft', 'pending', 'private' ), 'type' => array_merge( self::TYPES ) );
			if ( ! empty( $a['status'] ) ) {
				if ( ! in_array( $a['status'], self::STATUSES, true ) ) { return self::bad( 'status must be one of: ' . implode( ', ', self::STATUSES ) . '.' ); }
				$q['status'] = $a['status'];
			}
			if ( ! empty( $a['type'] ) ) {
				if ( ! in_array( $a['type'], self::TYPES, true ) ) { return self::bad( 'type must be one of: ' . implode( ', ', self::TYPES ) . '.' ); }
				$q['type'] = $a['type'];
			}
			if ( ! empty( $a['category'] ) ) {
				$term = ( is_int( $a['category'] ) || ctype_digit( (string) $a['category'] ) ) ? get_term( (int) $a['category'], 'product_cat' ) : get_term_by( 'slug', sanitize_title( (string) $a['category'] ), 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) { return self::bad( 'No product category matches that id or slug. See wp_list_terms with taxonomy product_cat.' ); }
				$q['category'] = array( $term->slug );
			}
			if ( ! empty( $a['tag'] ) ) { $q['tag'] = array( sanitize_title( (string) $a['tag'] ) ); }
			if ( ! empty( $a['sku'] ) ) { $q['sku'] = sanitize_text_field( (string) $a['sku'] ); }
			if ( ! empty( $a['stock_status'] ) ) { $q['stock_status'] = self::choice( 'stock_status', $a['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ) ); if ( is_wp_error( $q['stock_status'] ) ) { return $q['stock_status']; } }
			if ( array_key_exists( 'featured', $a ) ) { $q['featured'] = (bool) filter_var( $a['featured'], FILTER_VALIDATE_BOOLEAN ); }
			if ( ! empty( $a['on_sale'] ) ) { $q['include'] = array_merge( array( 0 ), array_map( 'intval', (array) wc_get_product_ids_on_sale() ) ); }
			$orderby = isset( $a['orderby'] ) ? (string) $a['orderby'] : 'date';
			if ( ! in_array( $orderby, array( 'date', 'modified', 'title', 'id', 'menu_order' ), true ) ) { return self::bad( 'orderby must be date, modified, title, id or menu_order.' ); }
			$q['orderby'] = 'id' === $orderby ? 'ID' : $orderby; // WP_Query underneath knows ID, not id.
			$q['order']   = isset( $a['order'] ) && 'asc' === strtolower( (string) $a['order'] ) ? 'ASC' : 'DESC';
			if ( ! empty( $a['search'] ) ) {
				$ids = WC_Data_Store::load( 'product' )->search_products( sanitize_text_field( (string) $a['search'] ), '', false, true, 200 );
				if ( ! $ids ) { return array( 'total' => 0, 'total_pages' => 0, 'page' => $page, 'per_page' => $per_page, 'count' => 0, 'items' => array() ); }
				$q['include'] = isset( $q['include'] ) ? array_values( array_intersect( $q['include'], $ids ) ) : array_map( 'intval', $ids );
				if ( ! $q['include'] ) { $q['include'] = array( 0 ); }
			}
			$res   = wc_get_products( $q );
			$items = array();
			foreach ( $res->products as $p ) { $items[] = self::row( $p ); }
			return array( 'total' => (int) $res->total, 'total_pages' => (int) $res->max_num_pages, 'page' => $page, 'per_page' => $per_page, 'count' => count( $items ), 'items' => $items );
		} );
	}

	public static function get_product( $id ) {
		return self::guarded( function () use ( $id ) {
			$p = wc_get_product( (int) $id );
			if ( ! $p || ! in_array( $p->get_type(), array_merge( self::TYPES ), true ) ) { return new WP_Error( 'wpmcp_not_found', 'No product with that ID. Variations are shown inside their parent product.', array( 'status' => 404 ) ); }
			return self::describe_product( $p );
		} );
	}

	/** Make sure the product type of an existing product matches, switching it the way the WooCommerce editor does. */
	private static function with_type( $p, $type ) {
		if ( $p->get_type() === $type ) { return $p; }
		wp_set_object_terms( $p->get_id(), $type, 'product_type' );
		$class = WC_Product_Factory::get_product_classname( $p->get_id(), $type );
		return new $class( $p->get_id() );
	}

	public static function save_product( $a ) {
		return self::guarded( function () use ( $a ) {
			$id   = isset( $a['id'] ) ? (int) $a['id'] : 0;
			$in   = array_diff_key( $a, array( 'id' => 1, 'type' => 1, 'dry_run' => 1 ) );
			$new  = ! $id;
			$p    = null;
			if ( $id ) {
				$p = wc_get_product( $id );
				if ( ! $p || ! in_array( $p->get_type(), self::TYPES, true ) ) { return new WP_Error( 'wpmcp_not_found', 'No product with that ID.', array( 'status' => 404 ) ); }
			}
			$type = isset( $a['type'] ) ? (string) $a['type'] : ( $p ? $p->get_type() : 'simple' );
			if ( ! in_array( $type, self::TYPES, true ) ) { return self::bad( 'type must be one of: ' . implode( ', ', self::TYPES ) . '.' ); }
			if ( $new && ( ! isset( $in['name'] ) || '' === trim( (string) $in['name'] ) ) ) { return self::bad( 'A new product needs a name.' ); }
			$changing = $p && $p->get_type() !== $type;
			if ( $changing && $p->is_type( 'variable' ) && $p->get_children() ) { return self::bad( 'This product has variations. Delete them before changing its type.' ); }
			$clean = self::clean_input( $in, $type, $p );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( $new && ! isset( $clean['status'] ) ) { $clean['status'] = 'draft'; }
			$acf = null;
			if ( isset( $clean['acf'] ) ) { $acf = $clean['acf']; unset( $clean['acf'] ); }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => $new, 'type' => $type, 'product' => $clean, 'acf' => $acf ); }

			$before = null;
			if ( $new ) {
				$class = WC_Product_Factory::get_product_classname( 0, $type );
				$p     = new $class();
			} else {
				$before = array( 'type' => $p->get_type() ) + self::state_of( $p, $clean );
				$p      = self::with_type( $p, $type );
			}
			self::apply_props( $p, $clean );
			$saved = $p->save();
			$p     = wc_get_product( $saved );
			$summary = $new ? sprintf( 'Created %s product', $type ) : 'Updated product (' . implode( ', ', array_keys( $clean ) ) . ')';
			self::record(
				'wp_woo_save_product', 'product', $saved, $p->get_name(), $summary,
				$new ? array( 'op' => 'woo_trash_created' ) : array( 'op' => 'woo_props', 'id' => $saved, 'state' => $before, 'keys' => array_keys( $clean ) ),
				$new ? null : md5( (string) wp_json_encode( self::state_of( $p, $clean ) ) )
			);
			$result = array( 'ok' => true, 'created' => $new, 'product' => self::describe_product( $p ) );
			if ( null !== $acf && class_exists( 'WPMCP_ACF' ) ) {
				$r = WPMCP_ACF::set_values( $saved, $acf );
				$result['acf'] = is_wp_error( $r ) ? array( 'ok' => false, 'error' => $r->get_error_message() ) : $r;
			}
			if ( $new && 'variable' === $type ) { $result['next'] = 'Add the variations with wp_woo_save_variation (parent_id ' . $saved . '). Mark the attributes used for variations with "variation": true.'; }
			return $result;
		} );
	}

	/** A variation's own state is its properties; the combination of attribute values must be unique within its parent. */
	public static function save_variation( $a ) {
		return self::guarded( function () use ( $a ) {
			$id     = isset( $a['id'] ) ? (int) $a['id'] : 0;
			$parent = isset( $a['parent_id'] ) ? (int) $a['parent_id'] : 0;
			$v      = null;
			if ( $id ) {
				$v = wc_get_product( $id );
				if ( ! $v || ! $v->is_type( 'variation' ) ) { return new WP_Error( 'wpmcp_not_found', 'No variation with that ID.', array( 'status' => 404 ) ); }
				$parent = (int) $v->get_parent_id();
			}
			$pp = $parent ? wc_get_product( $parent ) : null;
			if ( ! $pp || ! $pp->is_type( 'variable' ) ) { return self::bad( $id ? 'The parent of this variation is not a variable product.' : 'Give "parent_id": the ID of a variable product.' ); }
			$in    = array_diff_key( $a, array( 'id' => 1, 'parent_id' => 1, 'dry_run' => 1 ) );
			$clean = self::clean_input( $in, 'variation', $v );
			if ( is_wp_error( $clean ) ) { return $clean; }
			$attrs = self::attributes_of( $pp );
			if ( isset( $clean['attributes'] ) ) {
				$map = self::check_attribute_values( $clean['attributes'], $attrs, 'attributes', true );
				if ( is_wp_error( $map ) ) { return $map; }
				$clean['attributes'] = $map;
			} elseif ( ! $v ) {
				return self::bad( 'A new variation needs "attributes", for example {"Color":"Red","Size":"M"}.' );
			}
			$combo = isset( $clean['attributes'] ) ? $clean['attributes'] : (array) $v->get_attributes( 'edit' );
			foreach ( $pp->get_children() as $sibling_id ) {
				$sibling = wc_get_product( $sibling_id );
				if ( $sibling && (int) $sibling_id !== $id && array_filter( (array) $sibling->get_attributes( 'edit' ), 'strlen' ) == array_filter( $combo, 'strlen' ) ) { return self::bad( sprintf( 'Variation %d already has that combination of attributes.', $sibling_id ) ); }
			}
			if ( ! $v && ! isset( $clean['status'] ) ) { $clean['status'] = 'publish'; }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $v, 'variation' => $clean ); }

			$before = $v ? self::state_of( $v, $clean ) : null;
			if ( ! $v ) { $v = new WC_Product_Variation(); $v->set_parent_id( $parent ); }
			self::apply_props( $v, $clean );
			$saved = $v->save();
			WC_Product_Variable::sync( $parent );
			wc_delete_product_transients( $parent );
			$v = wc_get_product( $saved );
			self::record(
				'wp_woo_save_variation', 'product', $saved, $pp->get_name() . ' (variation)', ( $before ? 'Updated variation (' . implode( ', ', array_keys( $clean ) ) . ')' : 'Created variation' ) . ' of ' . $pp->get_name(),
				$before ? array( 'op' => 'woo_props', 'id' => $saved, 'state' => $before, 'keys' => array_keys( $clean ) ) : array( 'op' => 'woo_trash_created' ),
				$before ? md5( (string) wp_json_encode( self::state_of( $v, $clean ) ) ) : null
			);
			return array( 'ok' => true, 'created' => ! $before, 'variation' => self::describe_variation( $v ), 'parent_price' => wc_get_product( $parent )->get_price() );
		} );
	}

	/** Prices and stock for many products or variations at once. All are checked first; if one fails to save, the ones already changed are put back. */
	public static function bulk_update( $a ) {
		return self::guarded( function () use ( $a ) {
			$items = isset( $a['items'] ) && is_array( $a['items'] ) ? array_values( $a['items'] ) : array();
			if ( ! $items || count( $items ) > 100 ) { return self::bad( 'items must be a list of 1 to 100 entries like {"id":12,"regular_price":"19.90","stock_quantity":40}.' ); }
			$allowed = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'status' );
			$plan    = array();
			$seen    = array();
			foreach ( $items as $i => $item ) {
				$id = is_array( $item ) && isset( $item['id'] ) ? (int) $item['id'] : 0;
				$p  = $id ? wc_get_product( $id ) : null;
				if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variable', 'grouped', 'external', 'variation' ), true ) ) { return self::bad( sprintf( 'Item %d: no product or variation with ID %d.', $i + 1, $id ) ); }
				if ( isset( $seen[ $id ] ) ) { return self::bad( sprintf( 'Item %d: ID %d is listed twice.', $i + 1, $id ) ); }
				$seen[ $id ] = true;
				$in = array_diff_key( $item, array( 'id' => 1 ) );
				if ( ! $in ) { return self::bad( sprintf( 'Item %d (ID %d) changes nothing.', $i + 1, $id ) ); }
				$bad = array_diff( array_keys( $in ), $allowed );
				if ( $bad ) { return self::bad( sprintf( 'Item %d: %s cannot be changed in bulk. Allowed: %s. Use wp_woo_save_product for the rest.', $i + 1, implode( ', ', $bad ), implode( ', ', $allowed ) ) ); }
				$clean = self::clean_input( $in, $p->get_type(), $p );
				if ( is_wp_error( $clean ) ) { return self::bad( sprintf( 'Item %d (ID %d): %s', $i + 1, $id, $clean->get_error_message() ) ); }
				$plan[] = array( $p, $clean );
			}
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'count' => count( $plan ), 'changes' => array_map( function ( $row ) { return array( 'id' => $row[0]->get_id(), 'name' => $row[0]->get_name() ) + $row[1]; }, $plan ) ); }
			$done = array();
			try {
				foreach ( $plan as $row ) {
					list( $p, $clean ) = $row;
					$entry  = array( 'id' => $p->get_id(), 'state' => self::state_of( $p, $clean ), 'keys' => array_keys( $clean ) );
					self::apply_props( $p, $clean );
					$p->save();
					$done[] = $entry;
				}
			} catch ( \Throwable $e ) {
				foreach ( array_reverse( $done ) as $entry ) { self::restore_props( $entry['id'], $entry['state'], null, true ); }
				return self::bad( sprintf( 'Stopped at ID %d: %s. Nothing was changed.', isset( $p ) ? $p->get_id() : 0, $e->getMessage() ) );
			}
			$after = array();
			foreach ( $done as $entry ) { $after[ $entry['id'] ] = self::state_of( wc_get_product( $entry['id'] ), array_flip( $entry['keys'] ) ); }
			self::record( 'wp_woo_bulk_update', 'product', 0, count( $done ) . ' products', sprintf( 'Bulk updated %d products or variations', count( $done ) ), array( 'op' => 'woo_bulk', 'items' => $done ), md5( (string) wp_json_encode( $after ) ) );
			return array( 'ok' => true, 'updated' => count( $done ), 'ids' => array_column( $done, 'id' ) );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Categories                                                        */
	/* ----------------------------------------------------------------- */

	public static function save_category( $a ) {
		return self::guarded( function () use ( $a ) {
			$id   = isset( $a['id'] ) ? (int) $a['id'] : 0;
			$term = $id ? get_term( $id, 'product_cat' ) : null;
			if ( $id && ( ! $term || is_wp_error( $term ) ) ) { return new WP_Error( 'wpmcp_not_found', 'No product category with that ID.', array( 'status' => 404 ) ); }
			$name = isset( $a['name'] ) ? self::text( 'name', $a['name'], 200 ) : '';
			if ( is_wp_error( $name ) ) { return $name; }
			if ( ! $term && '' === $name ) { return self::bad( 'A new category needs a name.' ); }
			$args = array();
			if ( '' !== $name ) { $args['name'] = $name; }
			if ( isset( $a['slug'] ) ) { $args['slug'] = sanitize_title( (string) $a['slug'] ); }
			if ( isset( $a['description'] ) ) { $args['description'] = self::html( 'description', $a['description'] ); if ( is_wp_error( $args['description'] ) ) { return $args['description']; } }
			if ( isset( $a['parent'] ) ) {
				$parent = (int) $a['parent'];
				if ( $parent ) {
					$pt = get_term( $parent, 'product_cat' );
					if ( ! $pt || is_wp_error( $pt ) ) { return self::bad( 'parent is not a product category.' ); }
					if ( $id && ( $parent === $id || in_array( $id, array_map( 'intval', get_ancestors( $parent, 'product_cat', 'taxonomy' ) ), true ) ) ) { return self::bad( 'A category cannot be placed inside itself.' ); }
				}
				$args['parent'] = $parent;
			}
			$meta = array();
			if ( isset( $a['image'] ) ) { $img = '' === $a['image'] || 0 === $a['image'] ? array( 0 ) : self::attachment_ids( 'image', array( $a['image'] ) ); if ( is_wp_error( $img ) ) { return $img; } $meta['thumbnail_id'] = $img[0]; }
			if ( isset( $a['display_type'] ) ) { $dt = self::choice( 'display_type', $a['display_type'], array( 'default', 'products', 'subcategories', 'both' ) ); if ( is_wp_error( $dt ) ) { return $dt; } $meta['display_type'] = 'default' === $dt ? '' : $dt; }
			if ( ! $args && ! $meta ) { return self::bad( 'Nothing to change.' ); }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $term, 'category' => $args + $meta ); }

			$before = null;
			if ( $term ) {
				$before = array( 'term' => array( 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent ), 'meta' => array() );
				foreach ( array_keys( $meta ) as $mk ) { $before['meta'][ $mk ] = get_term_meta( $id, $mk, true ); }
				if ( $args ) { $r = wp_update_term( $id, 'product_cat', $args ); if ( is_wp_error( $r ) ) { return self::bad( $r->get_error_message() ); } }
			} else {
				$r = wp_insert_term( $args['name'], 'product_cat', array_diff_key( $args, array( 'name' => 1 ) ) );
				if ( is_wp_error( $r ) ) { return self::bad( $r->get_error_message() ); }
				$id = (int) $r['term_id'];
			}
			foreach ( $meta as $mk => $mv ) { update_term_meta( $id, $mk, $mv ); }
			$now = get_term( $id, 'product_cat' );
			self::record( 'wp_woo_save_category', 'term', $id, $now->name, ( $before ? 'Updated' : 'Created' ) . ' product category ' . $now->name, $before ? array( 'op' => 'woo_term', 'id' => $id, 'before' => $before ) : array( 'op' => 'delete_term', 'term_id' => $id, 'taxonomy' => 'product_cat' ) );
			return array( 'ok' => true, 'created' => ! $before, 'category' => array( 'id' => $id, 'name' => $now->name, 'slug' => $now->slug, 'parent' => (int) $now->parent, 'image_id' => (int) get_term_meta( $id, 'thumbnail_id', true ) ) );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Coupons                                                           */
	/* ----------------------------------------------------------------- */

	private static function describe_coupon( $c ) {
		$exp = $c->get_date_expires( 'edit' );
		return array(
			'id' => $c->get_id(), 'code' => $c->get_code(), 'status' => get_post_status( $c->get_id() ), 'description' => $c->get_description( 'edit' ), 'discount_type' => $c->get_discount_type( 'edit' ), 'amount' => $c->get_amount( 'edit' ),
			'date_expires' => $exp ? $exp->date( 'Y-m-d' ) : '', 'usage_count' => (int) $c->get_usage_count( 'edit' ), 'usage_limit' => (int) $c->get_usage_limit( 'edit' ), 'usage_limit_per_user' => (int) $c->get_usage_limit_per_user( 'edit' ),
			'limit_usage_to_x_items' => $c->get_limit_usage_to_x_items( 'edit' ), 'individual_use' => (bool) $c->get_individual_use( 'edit' ), 'free_shipping' => (bool) $c->get_free_shipping( 'edit' ), 'exclude_sale_items' => (bool) $c->get_exclude_sale_items( 'edit' ),
			'minimum_amount' => $c->get_minimum_amount( 'edit' ), 'maximum_amount' => $c->get_maximum_amount( 'edit' ), 'product_ids' => array_map( 'intval', $c->get_product_ids( 'edit' ) ), 'excluded_product_ids' => array_map( 'intval', $c->get_excluded_product_ids( 'edit' ) ),
			'product_categories' => array_map( 'intval', $c->get_product_categories( 'edit' ) ), 'excluded_product_categories' => array_map( 'intval', $c->get_excluded_product_categories( 'edit' ) ), 'email_restrictions' => $c->get_email_restrictions( 'edit' ),
		);
	}

	private static function coupon_state( $c, $keys ) {
		$all = self::describe_coupon( $c );
		return array_intersect_key( $all, array_flip( $keys ) );
	}

	private static function clean_coupon( $in, $existing ) {
		$allowed = array( 'code', 'description', 'status', 'discount_type', 'amount', 'date_expires', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'individual_use', 'free_shipping', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions' );
		$bad     = array_diff( array_keys( $in ), $allowed );
		if ( $bad ) { return self::bad( sprintf( '%s cannot be set on a coupon. Allowed: %s.', implode( ', ', $bad ), implode( ', ', $allowed ) ) ); }
		$out = array();
		foreach ( $in as $key => $value ) {
			switch ( $key ) {
				case 'code':
					$v = is_string( $value ) ? wc_format_coupon_code( $value ) : '';
					if ( '' === $v || mb_strlen( $v ) > 100 ) { $v = self::bad( 'code must be 1 to 100 characters.' ); break; }
					$owner = (int) wc_get_coupon_id_by_code( $v, $existing ? $existing->get_id() : 0 );
					if ( $owner ) { $v = self::bad( sprintf( 'The code "%s" is already used by coupon %d.', $v, $owner ) ); }
					break;
				case 'description': $v = self::text( $key, $value, 500 ); break;
				case 'status':      $v = self::choice( $key, $value, array( 'publish', 'draft', 'pending', 'private' ) ); break;
				case 'discount_type': $v = self::choice( $key, $value, array_keys( wc_get_coupon_types() ) ); break;
				case 'amount':      $v = self::number( $key, $value, false ); break;
				case 'minimum_amount': case 'maximum_amount': $v = self::number( $key, $value ); break;
				case 'date_expires': $v = self::day( $key, $value ); break;
				case 'usage_limit': case 'usage_limit_per_user': case 'limit_usage_to_x_items': $v = '' === $value || null === $value ? 0 : self::whole( $key, $value, 0, 10000000 ); break;
				case 'individual_use': case 'free_shipping': case 'exclude_sale_items': $v = self::flag( $key, $value ); break;
				case 'product_ids': case 'excluded_product_ids': $v = self::id_list( $key, $value ); break;
				case 'product_categories': case 'excluded_product_categories': $v = self::term_ids( $key, $value, 'product_cat', false ); break;
				case 'email_restrictions':
					if ( ! is_array( $value ) ) { $v = self::bad( 'email_restrictions must be a list of email addresses.' ); break; }
					$v = array();
					foreach ( $value as $email ) { $e = is_string( $email ) ? sanitize_email( trim( $email ) ) : ''; if ( '' === $e || ! is_email( $e ) ) { $v = self::bad( 'email_restrictions has an invalid address.' ); break; } $v[] = $e; }
					break;
				default: $v = self::bad( "$key is not supported." );
			}
			if ( is_wp_error( $v ) ) { return $v; }
			$out[ $key ] = $v;
		}
		$type   = isset( $out['discount_type'] ) ? $out['discount_type'] : ( $existing ? $existing->get_discount_type( 'edit' ) : 'fixed_cart' );
		$amount = isset( $out['amount'] ) ? $out['amount'] : ( $existing ? $existing->get_amount( 'edit' ) : null );
		if ( 'percent' === $type && null !== $amount && (float) $amount > 100 ) { return self::bad( 'A percentage discount cannot be more than 100.' ); }
		return $out;
	}

	public static function save_coupon( $a ) {
		return self::guarded( function () use ( $a ) {
			$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
			$c  = null;
			if ( $id ) {
				$c = new WC_Coupon( $id );
				if ( ! $c->get_id() || 'shop_coupon' !== get_post_type( $id ) ) { return new WP_Error( 'wpmcp_not_found', 'No coupon with that ID.', array( 'status' => 404 ) ); }
			}
			$in    = array_diff_key( $a, array( 'id' => 1, 'dry_run' => 1 ) );
			if ( ! $c && ( ! isset( $in['code'] ) || ! isset( $in['amount'] ) ) ) { return self::bad( 'A new coupon needs a "code" and an "amount".' ); }
			$clean = self::clean_coupon( $in, $c );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $c, 'coupon' => $clean ); }
			$before = $c ? self::coupon_state( $c, array_keys( $clean ) ) : null;
			if ( ! $c ) { $c = new WC_Coupon(); if ( ! isset( $clean['status'] ) ) { $clean['status'] = 'publish'; } }
			foreach ( $clean as $key => $value ) { $c->{ 'set_' . $key }( $value ); }
			$saved = $c->save();
			$c     = new WC_Coupon( $saved );
			self::record(
				'wp_woo_save_coupon', 'coupon', $saved, $c->get_code(), ( $before ? 'Updated' : 'Created' ) . ' coupon ' . $c->get_code(),
				$before ? array( 'op' => 'woo_coupon', 'id' => $saved, 'state' => $before ) : array( 'op' => 'woo_trash_created' ),
				$before ? md5( (string) wp_json_encode( self::coupon_state( $c, array_keys( $before ) ) ) ) : null
			);
			return array( 'ok' => true, 'created' => ! $before, 'coupon' => self::describe_coupon( $c ) );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Orders                                                            */
	/* ----------------------------------------------------------------- */

	private static function order_statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) { $out[ preg_replace( '/^wc-/', '', $slug ) ] = $label; }
		return $out;
	}

	private static function address_of( $o, $type ) {
		$a = (array) $o->get_address( $type );
		return array_intersect_key( $a, array_flip( 'billing' === $type ? self::ADDRESS : array_diff( self::ADDRESS, array( 'email' ) ) ) );
	}

	private static function order_row( $o ) {
		$date = $o->get_date_created();
		return array(
			'id' => $o->get_id(), 'number' => (string) $o->get_order_number(), 'status' => $o->get_status(), 'date_created' => self::iso( $date ), 'total' => $o->get_total(), 'currency' => $o->get_currency(),
			'items' => (int) $o->get_item_count(), 'customer_id' => (int) $o->get_customer_id(), 'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), 'payment_method' => $o->get_payment_method_title(),
		);
	}

	private static function describe_order( $o ) {
		$items = array();
		foreach ( $o->get_items() as $item_id => $item ) {
			$prod    = $item->get_product();
			$items[] = array( 'item_id' => (int) $item_id, 'product_id' => (int) $item->get_product_id(), 'variation_id' => (int) $item->get_variation_id(), 'name' => $item->get_name(), 'sku' => $prod ? $prod->get_sku() : '', 'quantity' => (float) $item->get_quantity(), 'subtotal' => $item->get_subtotal(), 'total' => $item->get_total(), 'tax' => $item->get_total_tax() );
		}
		$shipping = array();
		foreach ( $o->get_items( 'shipping' ) as $s ) { $shipping[] = array( 'method' => $s->get_method_id(), 'title' => $s->get_method_title(), 'total' => $s->get_total() ); }
		$fees = array();
		foreach ( $o->get_items( 'fee' ) as $f ) { $fees[] = array( 'name' => $f->get_name(), 'total' => $f->get_total() ); }
		$coupons = array();
		foreach ( $o->get_items( 'coupon' ) as $c ) { $coupons[] = array( 'code' => $c->get_code(), 'discount' => $c->get_discount() ); }
		$refunds = array();
		foreach ( $o->get_refunds() as $r ) { $refunds[] = array( 'id' => $r->get_id(), 'amount' => $r->get_amount(), 'reason' => $r->get_reason(), 'date' => self::iso( $r->get_date_created() ) ); }
		$notes = array();
		foreach ( (array) wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 20 ) ) as $n ) { $notes[] = array( 'id' => (int) $n->id, 'note' => $n->content, 'to_customer' => (bool) $n->customer_note, 'date' => self::iso( $n->date_created ) ); }
		return self::order_row( $o ) + array(
			'status_label' => wc_get_order_status_name( $o->get_status() ), 'created_via' => $o->get_created_via(), 'date_paid' => self::iso( $o->get_date_paid() ), 'date_completed' => self::iso( $o->get_date_completed() ),
			'totals' => array( 'subtotal' => $o->get_subtotal(), 'discount' => $o->get_discount_total(), 'shipping' => $o->get_shipping_total(), 'tax' => $o->get_total_tax(), 'total' => $o->get_total(), 'refunded' => $o->get_total_refunded(), 'refundable' => $o->get_remaining_refund_amount() ),
			'payment_method' => $o->get_payment_method(), 'payment_method_title' => $o->get_payment_method_title(), 'transaction_id' => $o->get_transaction_id(), 'customer_note' => $o->get_customer_note(),
			'billing' => self::address_of( $o, 'billing' ), 'shipping_address' => self::address_of( $o, 'shipping' ),
			'line_items' => $items, 'shipping_lines' => $shipping, 'fees' => $fees, 'coupons' => $coupons, 'refunds' => $refunds, 'notes' => $notes,
		);
	}

	private static function order_or_error( $id ) {
		$o = $id ? wc_get_order( (int) $id ) : null;
		return $o && $o instanceof WC_Order ? $o : new WP_Error( 'wpmcp_not_found', 'No order with that ID.', array( 'status' => 404 ) );
	}

	private static function range( $a ) {
		$from = isset( $a['from'] ) ? self::day( 'from', $a['from'] ) : '';
		$to   = isset( $a['to'] ) ? self::day( 'to', $a['to'] ) : '';
		if ( is_wp_error( $from ) ) { return $from; }
		if ( is_wp_error( $to ) ) { return $to; }
		return array( $from, $to );
	}

	public static function list_orders( $a ) {
		return self::guarded( function () use ( $a ) {
			$per_page = isset( $a['per_page'] ) ? max( 1, min( 50, (int) $a['per_page'] ) ) : 20;
			$page     = isset( $a['page'] ) ? max( 1, (int) $a['page'] ) : 1;
			$range    = self::range( $a );
			if ( is_wp_error( $range ) ) { return $range; }
			$known    = self::order_statuses();
			$status   = array();
			if ( ! empty( $a['status'] ) && 'any' !== $a['status'] ) {
				foreach ( (array) $a['status'] as $s ) {
					$s = preg_replace( '/^wc-/', '', (string) $s );
					if ( ! isset( $known[ $s ] ) ) { return self::bad( sprintf( 'Unknown order status "%s". Use: %s.', $s, implode( ', ', array_keys( $known ) ) ) ); }
					$status[] = 'wc-' . $s;
				}
			}
			$q = array( 'type' => 'shop_order', 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC' );
			if ( $status ) { $q['status'] = $status; }
			if ( ! empty( $a['customer_id'] ) ) { $q['customer_id'] = (int) $a['customer_id']; }
			if ( '' !== $range[0] || '' !== $range[1] ) { $q['date_created'] = ( '' !== $range[0] ? $range[0] : '1970-01-01' ) . '...' . ( '' !== $range[1] ? $range[1] : gmdate( 'Y-m-d' ) ); }
			if ( ! empty( $a['search'] ) ) {
				// Search finds order IDs; the other filters are applied to those orders here.
				$wanted = array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, $status );
				$low    = '' !== $range[0] ? strtotime( $range[0] . ' 00:00:00 UTC' ) : 0;
				$high   = '' !== $range[1] ? strtotime( $range[1] . ' 23:59:59 UTC' ) : PHP_INT_MAX;
				$hits   = array();
				foreach ( array_slice( array_map( 'intval', (array) wc_order_search( sanitize_text_field( (string) $a['search'] ) ) ), 0, 200 ) as $oid ) {
					$o = wc_get_order( $oid );
					if ( ! $o instanceof WC_Order || 'shop_order' !== $o->get_type() ) { continue; }
					if ( $wanted && ! in_array( $o->get_status(), $wanted, true ) ) { continue; }
					if ( ! empty( $a['customer_id'] ) && (int) $o->get_customer_id() !== (int) $a['customer_id'] ) { continue; }
					$ts = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
					if ( $ts < $low || $ts > $high ) { continue; }
					$hits[] = $o;
				}
				$items = array();
				foreach ( array_slice( $hits, ( $page - 1 ) * $per_page, $per_page ) as $o ) { $items[] = self::order_row( $o ); }
				return array( 'total' => count( $hits ), 'total_pages' => (int) ceil( count( $hits ) / $per_page ), 'page' => $page, 'per_page' => $per_page, 'count' => count( $items ), 'items' => $items );
			}
			$res   = wc_get_orders( $q );
			$items = array();
			foreach ( $res->orders as $o ) { if ( $o instanceof WC_Order ) { $items[] = self::order_row( $o ); } }
			return array( 'total' => (int) $res->total, 'total_pages' => (int) $res->max_num_pages, 'page' => $page, 'per_page' => $per_page, 'count' => count( $items ), 'items' => $items );
		} );
	}

	public static function get_order( $id ) {
		return self::guarded( function () use ( $id ) {
			$o = self::order_or_error( $id );
			return is_wp_error( $o ) ? $o : self::describe_order( $o );
		} );
	}

	public static function list_customers( $a ) {
		return self::guarded( function () use ( $a ) {
			$per_page = isset( $a['per_page'] ) ? max( 1, min( 50, (int) $a['per_page'] ) ) : 20;
			$page     = isset( $a['page'] ) ? max( 1, (int) $a['page'] ) : 1;
			$args     = array( 'role' => 'customer', 'number' => $per_page, 'paged' => $page, 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true );
			if ( ! empty( $a['search'] ) ) { $args['search'] = '*' . esc_attr( sanitize_text_field( (string) $a['search'] ) ) . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' ); }
			$query = new WP_User_Query( $args );
			$items = array();
			foreach ( $query->get_results() as $u ) {
				$items[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'registered' => $u->user_registered, 'orders' => (int) wc_get_customer_order_count( $u->ID ), 'total_spent' => (string) wc_get_customer_total_spent( $u->ID ) );
			}
			return array( 'total' => (int) $query->get_total(), 'page' => $page, 'per_page' => $per_page, 'count' => count( $items ), 'items' => $items );
		} );
	}

	public static function sales_summary( $a ) {
		return self::guarded( function () use ( $a ) {
			$range = self::range( $a );
			if ( is_wp_error( $range ) ) { return $range; }
			$to   = '' !== $range[1] ? $range[1] : gmdate( 'Y-m-d' );
			$from = '' !== $range[0] ? $range[0] : gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) );
			if ( $from > $to ) { return self::bad( 'from is after to.' ); }
			if ( ( strtotime( $to ) - strtotime( $from ) ) > 366 * DAY_IN_SECONDS ) { return self::bad( 'Pick a range of one year or less.' ); }
			$known    = self::order_statuses();
			$statuses = isset( $a['statuses'] ) ? (array) $a['statuses'] : array( 'processing', 'completed' );
			$q        = array();
			foreach ( $statuses as $s ) {
				$s = preg_replace( '/^wc-/', '', (string) $s );
				if ( ! isset( $known[ $s ] ) ) { return self::bad( sprintf( 'Unknown order status "%s". Use: %s.', $s, implode( ', ', array_keys( $known ) ) ) ); }
				$q[] = 'wc-' . $s;
			}
			$limit  = 2000;
			$orders = wc_get_orders( array( 'type' => 'shop_order', 'status' => $q, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'date_created' => $from . '...' . $to ) );
			$sum    = array( 'orders' => 0, 'gross' => 0.0, 'refunded' => 0.0, 'tax' => 0.0, 'shipping' => 0.0, 'discounts' => 0.0, 'items' => 0 );
			$days   = array();
			$top    = array();
			foreach ( $orders as $o ) {
				if ( ! $o instanceof WC_Order ) { continue; }
				$sum['orders']++;
				$sum['gross']    += (float) $o->get_total();
				$sum['refunded'] += (float) $o->get_total_refunded();
				$sum['tax']      += (float) $o->get_total_tax();
				$sum['shipping'] += (float) $o->get_shipping_total();
				$sum['discounts'] += (float) $o->get_discount_total();
				$sum['items']    += (int) $o->get_item_count();
				$day = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : '';
				if ( ! isset( $days[ $day ] ) ) { $days[ $day ] = array( 'orders' => 0, 'gross' => 0.0 ); }
				$days[ $day ]['orders']++;
				$days[ $day ]['gross'] += (float) $o->get_total();
				foreach ( $o->get_items() as $item ) {
					$key = (int) $item->get_product_id();
					if ( ! isset( $top[ $key ] ) ) { $top[ $key ] = array( 'product_id' => $key, 'name' => $item->get_name(), 'quantity' => 0, 'revenue' => 0.0 ); }
					$top[ $key ]['quantity'] += (float) $item->get_quantity();
					$top[ $key ]['revenue']  += (float) $item->get_total();
				}
			}
			ksort( $days );
			usort( $top, function ( $x, $y ) { return $y['revenue'] <=> $x['revenue']; } );
			$round = function ( $v ) { return round( $v, 2 ); };
			$by_day = array();
			foreach ( $days as $d => $row ) { $by_day[] = array( 'date' => $d, 'orders' => $row['orders'], 'gross' => $round( $row['gross'] ) ); }
			return array(
				'from' => $from, 'to' => $to, 'currency' => get_option( 'woocommerce_currency', '' ), 'statuses' => array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, $q ),
				'orders' => $sum['orders'], 'items_sold' => $sum['items'], 'gross' => $round( $sum['gross'] ), 'refunded' => $round( $sum['refunded'] ), 'net' => $round( $sum['gross'] - $sum['refunded'] ),
				'tax' => $round( $sum['tax'] ), 'shipping' => $round( $sum['shipping'] ), 'discounts' => $round( $sum['discounts'] ), 'average_order' => $sum['orders'] ? $round( $sum['gross'] / $sum['orders'] ) : 0,
				'by_day' => $by_day, 'top_products' => array_map( function ( $row ) use ( $round ) { $row['revenue'] = $round( $row['revenue'] ); return $row; }, array_slice( $top, 0, 10 ) ),
				'truncated' => count( $orders ) >= $limit, 'note' => 'Orders are counted by the dates and statuses given. Totals include tax and shipping; net is gross minus refunds.',
			);
		} );
	}

	/** Check an address object: only known fields, short text, a valid email and country. */
	private static function clean_address( $key, $in, $with_email ) {
		if ( ! is_array( $in ) ) { return self::bad( "$key must be an object." ); }
		$allowed = $with_email ? self::ADDRESS : array_diff( self::ADDRESS, array( 'email' ) );
		$out     = array();
		foreach ( $in as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) ) { return self::bad( sprintf( '%s.%s is not a field. Allowed: %s.', $key, (string) $field, implode( ', ', $allowed ) ) ); }
			$text = self::text( "$key.$field", $value, 200 );
			if ( is_wp_error( $text ) ) { return $text; }
			if ( 'email' === $field && '' !== $text && ! is_email( $text ) ) { return self::bad( "$key.email is not a valid email address." ); }
			if ( 'country' === $field && '' !== $text && ( ! function_exists( 'WC' ) || ! isset( WC()->countries->get_countries()[ strtoupper( $text ) ] ) ) ) { return self::bad( "$key.country must be a two-letter country code such as US or GB." ); }
			$out[ $field ] = 'country' === $field ? strtoupper( $text ) : $text;
		}
		return $out;
	}

	private static function clean_order_fields( $a ) {
		$out = array();
		if ( isset( $a['status'] ) ) {
			$known = self::order_statuses();
			$s     = preg_replace( '/^wc-/', '', (string) $a['status'] );
			if ( ! isset( $known[ $s ] ) ) { return self::bad( sprintf( 'Unknown order status "%s". Use: %s.', $s, implode( ', ', array_keys( $known ) ) ) ); }
			if ( 'refunded' === $s ) { return self::bad( 'To refund an order use wp_woo_refund_order. It records the refund; the status follows.' ); }
			$out['status'] = $s;
		}
		foreach ( array( 'billing' => true, 'shipping' => false ) as $type => $email ) {
			if ( isset( $a[ $type ] ) ) { $addr = self::clean_address( $type, $a[ $type ], $email ); if ( is_wp_error( $addr ) ) { return $addr; } $out[ $type ] = $addr; }
		}
		if ( isset( $a['customer_note'] ) ) { $n = self::multiline( 'customer_note', $a['customer_note'], 1000 ); if ( is_wp_error( $n ) ) { return $n; } $out['customer_note'] = $n; }
		if ( isset( $a['customer_id'] ) ) {
			$cid = (int) $a['customer_id'];
			if ( $cid && ! get_userdata( $cid ) ) { return self::bad( 'customer_id is not a user on this site.' ); }
			$out['customer_id'] = $cid;
		}
		if ( isset( $a['payment_method'] ) ) { $m = self::text( 'payment_method', $a['payment_method'], 60 ); if ( is_wp_error( $m ) ) { return $m; } $out['payment_method'] = $m; }
		if ( isset( $a['payment_method_title'] ) ) { $m = self::text( 'payment_method_title', $a['payment_method_title'], 100 ); if ( is_wp_error( $m ) ) { return $m; } $out['payment_method_title'] = $m; }
		return $out;
	}

	private static function order_state( $o, $keys ) {
		$all = array(
			'status' => $o->get_status(), 'billing' => self::address_of( $o, 'billing' ), 'shipping' => self::address_of( $o, 'shipping' ), 'customer_note' => $o->get_customer_note(),
			'customer_id' => (int) $o->get_customer_id(), 'payment_method' => $o->get_payment_method(), 'payment_method_title' => $o->get_payment_method_title(),
		);
		return array_intersect_key( $all, array_flip( $keys ) );
	}

	private static function apply_order_fields( $o, $clean ) {
		foreach ( array( 'billing', 'shipping' ) as $type ) { if ( isset( $clean[ $type ] ) ) { $o->set_address( array_merge( self::address_of( $o, $type ), $clean[ $type ] ), $type ); } }
		if ( isset( $clean['customer_note'] ) ) { $o->set_customer_note( $clean['customer_note'] ); }
		if ( isset( $clean['customer_id'] ) ) { $o->set_customer_id( $clean['customer_id'] ); }
		if ( isset( $clean['payment_method'] ) ) { $o->set_payment_method( $clean['payment_method'] ); }
		if ( isset( $clean['payment_method_title'] ) ) { $o->set_payment_method_title( $clean['payment_method_title'] ); }
	}

	public static function update_order( $a ) {
		return self::guarded( function () use ( $a ) {
			$o = self::order_or_error( isset( $a['id'] ) ? $a['id'] : 0 );
			if ( is_wp_error( $o ) ) { return $o; }
			$fields = array_diff_key( $a, array( 'id' => 1, 'note' => 1, 'note_to_customer' => 1, 'dry_run' => 1 ) );
			$extra  = array_diff( array_keys( $fields ), self::ORDER_FIELDS );
			if ( $extra ) { return self::bad( sprintf( '%s cannot be changed on an order. Allowed: %s, note, note_to_customer.', implode( ', ', $extra ), implode( ', ', self::ORDER_FIELDS ) ) ); }
			$clean = self::clean_order_fields( $fields );
			if ( is_wp_error( $clean ) ) { return $clean; }
			$note = isset( $a['note'] ) ? self::multiline( 'note', $a['note'], 2000 ) : '';
			if ( is_wp_error( $note ) ) { return $note; }
			$to_customer = ! empty( $a['note_to_customer'] );
			if ( $to_customer && '' === $note ) { return self::bad( 'note_to_customer needs a "note".' ); }
			if ( ! $clean && '' === $note ) { return self::bad( 'Nothing to change. Give a status, address, customer_note, customer_id, payment method or a note.' ); }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'order' => $clean, 'note' => $note, 'note_to_customer' => $to_customer ); }

			$keys   = array_keys( $clean );
			$before = self::order_state( $o, $keys );
			$status = isset( $clean['status'] ) ? $clean['status'] : '';
			unset( $clean['status'] );
			self::apply_order_fields( $o, $clean );
			$o->save();
			$emails = false;
			if ( '' !== $status && $status !== $o->get_status() ) {
				$o->update_status( $status, 'Status changed through WP MCP.', true );
				$emails = in_array( $status, array( 'processing', 'completed', 'on-hold', 'cancelled', 'failed' ), true );
			}
			$notes = array();
			if ( '' !== $note ) { $nid = $o->add_order_note( $note, $to_customer ? 1 : 0, false ); if ( $nid ) { $notes[] = (int) $nid; } if ( $to_customer ) { $emails = true; } }
			$o = wc_get_order( $o->get_id() );
			self::record( 'wp_woo_update_order', 'order', $o->get_id(), 'Order #' . $o->get_order_number(), 'Updated order (' . implode( ', ', array_merge( $keys, $notes ? array( 'note' ) : array() ) ) . ')', array( 'op' => 'woo_order', 'id' => $o->get_id(), 'state' => $before, 'keys' => $keys, 'notes' => $notes ), md5( (string) wp_json_encode( self::order_state( $o, $keys ) ) ) );
			$result = array( 'ok' => true, 'order' => self::order_row( $o ), 'note_ids' => $notes );
			if ( $emails ) { $result['customer_emails'] = 'WooCommerce may have emailed the customer, as it does when a store manager makes this change. An email cannot be recalled by rolling back.'; }
			return $result;
		} );
	}

	public static function create_order( $a ) {
		return self::guarded( function () use ( $a ) {
			$fields = array_diff_key( $a, array( 'line_items' => 1, 'coupons' => 1, 'shipping_lines' => 1, 'set_paid' => 1, 'note' => 1, 'dry_run' => 1 ) );
			$extra  = array_diff( array_keys( $fields ), self::ORDER_FIELDS );
			if ( $extra ) { return self::bad( sprintf( '%s is not a field of a new order. Allowed: %s, line_items, coupons, shipping_lines, set_paid, note.', implode( ', ', $extra ), implode( ', ', self::ORDER_FIELDS ) ) ); }
			$clean = self::clean_order_fields( $fields );
			if ( is_wp_error( $clean ) ) { return $clean; }
			$lines = isset( $a['line_items'] ) && is_array( $a['line_items'] ) ? array_values( $a['line_items'] ) : array();
			if ( ! $lines || count( $lines ) > 100 ) { return self::bad( 'line_items must list 1 to 100 products like {"product_id":12,"quantity":2}.' ); }
			$plan = array();
			foreach ( $lines as $i => $line ) {
				$pid = is_array( $line ) && isset( $line['variation_id'] ) && $line['variation_id'] ? (int) $line['variation_id'] : ( is_array( $line ) && isset( $line['product_id'] ) ? (int) $line['product_id'] : 0 );
				$p   = $pid ? wc_get_product( $pid ) : null;
				if ( ! $p ) { return self::bad( sprintf( 'Line %d: no product with ID %d.', $i + 1, $pid ) ); }
				if ( $p->is_type( 'variable' ) ) { return self::bad( sprintf( 'Line %d: "%s" is a variable product. Give the variation_id of the one to order.', $i + 1, $p->get_name() ) ); }
				if ( ! in_array( $p->get_type(), array( 'simple', 'variation', 'external' ), true ) ) { return self::bad( sprintf( 'Line %d: a %s product cannot be ordered directly.', $i + 1, $p->get_type() ) ); }
				$qty = isset( $line['quantity'] ) ? self::whole( 'quantity', $line['quantity'], 1, 100000 ) : 1;
				if ( is_wp_error( $qty ) ) { return $qty; }
				$args = array();
				if ( isset( $line['price'] ) ) { $price = self::number( 'price', $line['price'], false ); if ( is_wp_error( $price ) ) { return $price; } $args = array( 'subtotal' => (float) $price * $qty, 'total' => (float) $price * $qty ); }
				$plan[] = array( $p, $qty, $args );
			}
			$codes = array();
			foreach ( (array) ( isset( $a['coupons'] ) ? $a['coupons'] : array() ) as $code ) { $c = is_string( $code ) ? wc_format_coupon_code( $code ) : ''; if ( '' === $c ) { return self::bad( 'coupons must be a list of coupon codes.' ); } $codes[] = $c; }
			$ship = array();
			foreach ( (array) ( isset( $a['shipping_lines'] ) ? $a['shipping_lines'] : array() ) as $line ) {
				$title = is_array( $line ) && isset( $line['title'] ) ? self::text( 'shipping title', $line['title'], 100 ) : self::bad( 'Each shipping line needs a title and a total.' );
				if ( is_wp_error( $title ) ) { return $title; }
				$total = isset( $line['total'] ) ? self::number( 'shipping total', $line['total'], false ) : self::bad( 'Each shipping line needs a title and a total.' );
				if ( is_wp_error( $total ) ) { return $total; }
				$ship[] = array( $title, $total );
			}
			$status = isset( $clean['status'] ) ? $clean['status'] : 'pending';
			$note   = isset( $a['note'] ) ? self::multiline( 'note', $a['note'], 2000 ) : '';
			if ( is_wp_error( $note ) ) { return $note; }
			$paid   = ! empty( $a['set_paid'] );
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'status' => $status, 'lines' => count( $plan ), 'coupons' => $codes, 'shipping_lines' => count( $ship ), 'order' => $clean ); }

			$order = wc_create_order( array( 'status' => 'pending', 'customer_id' => isset( $clean['customer_id'] ) ? $clean['customer_id'] : 0, 'created_via' => 'wp-mcp' ) );
			if ( is_wp_error( $order ) ) { return self::bad( $order->get_error_message() ); }
			try {
				foreach ( array( 'billing', 'shipping' ) as $type ) { if ( isset( $clean[ $type ] ) ) { $order->set_address( $clean[ $type ], $type ); } }
				foreach ( $plan as $row ) { $order->add_product( $row[0], $row[1], $row[2] ); }
				foreach ( $ship as $row ) {
					$item = new WC_Order_Item_Shipping();
					$item->set_method_title( $row[0] );
					$item->set_method_id( 'flat_rate' );
					$item->set_total( $row[1] );
					$order->add_item( $item );
				}
				foreach ( array( 'customer_note', 'payment_method', 'payment_method_title' ) as $f ) { if ( isset( $clean[ $f ] ) ) { $order->{ 'set_' . $f }( $clean[ $f ] ); } }
				$order->save();
				foreach ( $codes as $code ) {
					$r = $order->apply_coupon( $code );
					if ( is_wp_error( $r ) ) { throw new \Exception( sprintf( 'Coupon %s: %s', $code, $r->get_error_message() ) ); }
				}
				$order->calculate_totals( true );
				$order->save();
				if ( 'pending' !== $status ) { $order->update_status( $status, 'Created through WP MCP.', true ); } else { $order->add_order_note( 'Created through WP MCP.' ); }
				if ( '' !== $note ) { $order->add_order_note( $note, 0, false ); }
				if ( $paid ) { $order->payment_complete(); }
			} catch ( \Throwable $e ) {
				$order->delete( true );
				return self::bad( 'The order was not created: ' . $e->getMessage() );
			}
			$order = wc_get_order( $order->get_id() );
			self::record( 'wp_woo_create_order', 'order', $order->get_id(), 'Order #' . $order->get_order_number(), 'Created order with ' . count( $plan ) . ' line(s)', array( 'op' => 'woo_trash_created' ) );
			$result = array( 'ok' => true, 'order' => self::describe_order( $order ) );
			if ( $paid || in_array( $status, array( 'processing', 'completed', 'on-hold' ), true ) ) { $result['customer_emails'] = 'WooCommerce may have emailed the customer, as it does for any order created by a store manager. Stock was reduced if the status calls for it.'; }
			return $result;
		} );
	}

	public static function refund_order( $a ) {
		return self::guarded( function () use ( $a ) {
			$o = self::order_or_error( isset( $a['order_id'] ) ? $a['order_id'] : 0 );
			if ( is_wp_error( $o ) ) { return $o; }
			$amount = isset( $a['amount'] ) ? self::number( 'amount', $a['amount'], false ) : self::bad( 'Give the "amount" to refund.' );
			if ( is_wp_error( $amount ) ) { return $amount; }
			if ( (float) $amount <= 0 ) { return self::bad( 'amount must be more than zero.' ); }
			$left = (float) $o->get_remaining_refund_amount();
			if ( (float) $amount > $left ) { return self::bad( sprintf( 'Only %s is left to refund on this order.', wc_format_decimal( $left ) ) ); }
			$reason = isset( $a['reason'] ) ? self::text( 'reason', $a['reason'], 500 ) : '';
			if ( is_wp_error( $reason ) ) { return $reason; }
			$lines = array();
			foreach ( (array) ( isset( $a['line_items'] ) ? $a['line_items'] : array() ) as $line ) {
				$item_id = is_array( $line ) && isset( $line['item_id'] ) ? (int) $line['item_id'] : 0;
				if ( ! $item_id || ! $o->get_item( $item_id ) ) { return self::bad( sprintf( 'item_id %d is not a line of this order. See wp_woo_get_order.', $item_id ) ); }
				$qty   = isset( $line['quantity'] ) ? self::whole( 'quantity', $line['quantity'], 0, 100000 ) : 0;
				$total = isset( $line['amount'] ) ? self::number( 'line amount', $line['amount'], false ) : '0';
				if ( is_wp_error( $qty ) ) { return $qty; }
				if ( is_wp_error( $total ) ) { return $total; }
				$lines[ $item_id ] = array( 'qty' => $qty, 'refund_total' => $total );
			}
			$to_gateway = ! empty( $a['refund_payment'] );
			$restock    = ! empty( $a['restock_items'] );
			if ( $restock && ! $lines ) { return self::bad( 'restock_items needs line_items to say which products to put back.' ); }
			$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => $amount, 'reason' => '' !== $reason ? $reason : null, 'line_items' => $lines, 'refund_payment' => $to_gateway, 'restock_items' => $restock ) );
			if ( is_wp_error( $refund ) ) { return self::bad( $refund->get_error_message() ); }
			$o = wc_get_order( $o->get_id() );
			self::record( 'wp_woo_refund_order', 'order', $o->get_id(), 'Order #' . $o->get_order_number(), sprintf( 'Refunded %s on order #%s%s (cannot be rolled back)', $amount, $o->get_order_number(), $to_gateway ? ' and sent it to the payment gateway' : '' ), null );
			return array( 'ok' => true, 'refund_id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'sent_to_gateway' => $to_gateway, 'remaining_refundable' => $o->get_remaining_refund_amount(), 'order_status' => $o->get_status(), 'note' => $to_gateway ? 'The money was returned through the payment gateway. This cannot be undone.' : 'The refund is recorded on the order only. No money was sent: refund the customer through your payment provider, or repeat with refund_payment true.' );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Attributes (global)                                               */
	/* ----------------------------------------------------------------- */

	public static function save_attribute( $a ) {
		return self::guarded( function () use ( $a ) {
			$id       = isset( $a['id'] ) ? (int) $a['id'] : 0;
			$existing = $id ? wc_get_attribute( $id ) : null;
			if ( $id && ! $existing ) { return new WP_Error( 'wpmcp_not_found', 'No global attribute with that ID. See wp_woo_list_config with what=attributes.', array( 'status' => 404 ) ); }
			$name = isset( $a['name'] ) ? self::text( 'name', $a['name'], 100 ) : '';
			if ( is_wp_error( $name ) ) { return $name; }
			if ( ! $existing && '' === $name ) { return self::bad( 'A new attribute needs a name, for example "Color".' ); }
			$args = array(
				'name'         => '' !== $name ? $name : $existing->name,
				'slug'         => isset( $a['slug'] ) ? sanitize_title( (string) $a['slug'] ) : ( $existing ? preg_replace( '/^pa_/', '', $existing->slug ) : '' ),
				'type'         => isset( $a['type'] ) ? self::choice( 'type', $a['type'], array_keys( wc_get_attribute_types() ) ) : ( $existing ? $existing->type : 'select' ),
				'order_by'     => isset( $a['order_by'] ) ? self::choice( 'order_by', $a['order_by'], array( 'menu_order', 'name', 'name_num', 'id' ) ) : ( $existing ? $existing->order_by : 'menu_order' ),
				'has_archives' => isset( $a['has_archives'] ) ? self::flag( 'has_archives', $a['has_archives'] ) : ( $existing ? (bool) $existing->has_archives : false ),
			);
			foreach ( $args as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_create' => ! $existing, 'attribute' => $args ); }
			if ( $existing ) {
				$before = array( 'name' => $existing->name, 'slug' => preg_replace( '/^pa_/', '', $existing->slug ), 'type' => $existing->type, 'order_by' => $existing->order_by, 'has_archives' => (bool) $existing->has_archives );
				$saved  = wc_update_attribute( $id, $args );
			} else {
				$saved  = wc_create_attribute( $args );
			}
			if ( is_wp_error( $saved ) ) { return self::bad( $saved->get_error_message() ); }
			$id  = (int) $saved;
			$now = wc_get_attribute( $id );
			self::record( 'wp_woo_save_attribute', 'woo', $id, $now->name, ( $existing ? 'Updated' : 'Created' ) . ' global attribute ' . $now->name, $existing ? array( 'op' => 'woo_attribute', 'id' => $id, 'before' => $before ) : array( 'op' => 'woo_attribute_delete', 'id' => $id ) );
			return array( 'ok' => true, 'created' => ! $existing, 'attribute' => array( 'id' => $id, 'name' => $now->name, 'slug' => $now->slug, 'type' => $now->type, 'order_by' => $now->order_by, 'has_archives' => (bool) $now->has_archives ), 'next' => 'Add values by listing them in "options" when you save a product with this attribute, or with wp_create_term (taxonomy ' . $now->slug . ') on the next request.' );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Deleting                                                          */
	/* ----------------------------------------------------------------- */

	public static function delete_item( $kind, $id, $force ) {
		return self::guarded( function () use ( $kind, $id, $force ) {
			$id = (int) $id;
			if ( 'attribute' === $kind ) {
				$attr = wc_get_attribute( $id );
				if ( ! $attr ) { return new WP_Error( 'wpmcp_not_found', 'No global attribute with that ID.', array( 'status' => 404 ) ); }
				if ( ! wc_delete_attribute( $id ) ) { return self::bad( 'WooCommerce could not delete the attribute.' ); }
				self::record( 'wp_woo_delete', 'woo', $id, $attr->name, 'Deleted global attribute ' . $attr->name . ' and its values (cannot be rolled back)', null );
				return array( 'ok' => true, 'deleted' => $id, 'kind' => 'attribute', 'note' => 'The attribute and its values are gone. This cannot be rolled back.' );
			}
			if ( 'category' === $kind ) {
				$term = get_term( $id, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) { return new WP_Error( 'wpmcp_not_found', 'No product category with that ID.', array( 'status' => 404 ) ); }
				if ( (int) get_option( 'default_product_cat', 0 ) === $id ) { return self::bad( 'This is the default product category and cannot be deleted.' ); }
				$before = array( 'term' => array( 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent ), 'meta' => array( 'thumbnail_id' => get_term_meta( $id, 'thumbnail_id', true ), 'display_type' => get_term_meta( $id, 'display_type', true ) ) );
				$r = wp_delete_term( $id, 'product_cat' );
				if ( true !== $r ) { return self::bad( is_wp_error( $r ) ? $r->get_error_message() : 'Could not delete the category.' ); }
				self::record( 'wp_woo_delete', 'term', $id, $term->name, 'Deleted product category ' . $term->name . ' (products stay, uncategorised)', array( 'op' => 'woo_term_recreate', 'before' => $before ) );
				return array( 'ok' => true, 'deleted' => $id, 'kind' => 'category', 'note' => 'Products in it were moved to the default category. Roll back from History to bring the category back (products are not re-assigned).' );
			}
			if ( 'coupon' === $kind ) {
				$c = new WC_Coupon( $id );
				if ( ! $c->get_id() || 'shop_coupon' !== get_post_type( $id ) ) { return new WP_Error( 'wpmcp_not_found', 'No coupon with that ID.', array( 'status' => 404 ) ); }
				$code = $c->get_code();
				$c->delete( (bool) $force );
				self::record( 'wp_woo_delete', 'coupon', $id, $code, $force ? 'Deleted coupon ' . $code . ' permanently (cannot be rolled back)' : 'Moved coupon ' . $code . ' to the trash', $force ? null : array( 'op' => 'woo_untrash', 'id' => $id ) );
				return array( 'ok' => true, 'deleted' => $id, 'kind' => 'coupon', 'permanent' => (bool) $force );
			}
			if ( 'order' === $kind ) {
				$o = self::order_or_error( $id );
				if ( is_wp_error( $o ) ) { return $o; }
				if ( $force ) { return self::bad( 'Orders are only moved to the trash from here (force is not supported). Delete permanently from the WooCommerce screen if you must.' ); }
				$label = 'Order #' . $o->get_order_number();
				$o->delete( false );
				self::record( 'wp_woo_delete', 'order', $id, $label, 'Moved ' . $label . ' to the trash', array( 'op' => 'woo_untrash', 'id' => $id ) );
				return array( 'ok' => true, 'deleted' => $id, 'kind' => 'order', 'permanent' => false );
			}
			if ( 'product' === $kind || 'variation' === $kind ) {
				$p = wc_get_product( $id );
				if ( ! $p || ( 'variation' === $kind ) !== $p->is_type( 'variation' ) || ( 'product' === $kind && ! in_array( $p->get_type(), self::TYPES, true ) ) ) { return new WP_Error( 'wpmcp_not_found', sprintf( 'No %s with that ID.', $kind ), array( 'status' => 404 ) ); }
				$label  = $p->get_name();
				$parent = (int) $p->get_parent_id();
				$kids   = array_map( 'intval', $p->is_type( 'variable' ) ? $p->get_children() : array() ); // Only these come back on rollback, not variations trashed earlier.
				$p->delete( (bool) $force );
				if ( $parent ) { WC_Product_Variable::sync( $parent ); wc_delete_product_transients( $parent ); }
				self::record( 'wp_woo_delete', 'product', $id, $label, $force ? 'Deleted ' . $kind . ' ' . $label . ' permanently (cannot be rolled back)' : 'Moved ' . $kind . ' ' . $label . ' to the trash', $force ? null : array( 'op' => 'woo_untrash', 'id' => $id, 'parent' => $parent, 'children' => $kids ) );
				return array( 'ok' => true, 'deleted' => $id, 'kind' => $kind, 'permanent' => (bool) $force );
			}
			return self::bad( 'kind must be product, variation, coupon, order, category or attribute.' );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Store configuration                                               */
	/* ----------------------------------------------------------------- */

	public static function list_config( $what ) {
		return self::guarded( function () use ( $what ) {
			$all = array( 'attributes', 'categories', 'shipping', 'payments', 'taxes', 'order_statuses', 'coupons' );
			if ( '' !== $what && ! in_array( $what, $all, true ) ) { return self::bad( 'what must be one of: ' . implode( ', ', $all ) . '.' ); }
			$out = array();
			foreach ( $all as $part ) {
				if ( '' !== $what && $part !== $what ) { continue; }
				$out[ $part ] = call_user_func( array( __CLASS__, 'config_' . $part ) );
			}
			return $out;
		} );
	}

	private static function config_attributes() {
		$rows = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $t ) {
			$taxonomy = wc_attribute_taxonomy_name( $t->attribute_name );
			$terms    = array();
			if ( taxonomy_exists( $taxonomy ) ) {
				foreach ( (array) get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200 ) ) as $term ) { $terms[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count ); }
			}
			$rows[] = array( 'id' => (int) $t->attribute_id, 'name' => $t->attribute_label, 'slug' => $t->attribute_name, 'taxonomy' => $taxonomy, 'type' => $t->attribute_type, 'order_by' => $t->attribute_orderby, 'has_archives' => (bool) $t->attribute_public, 'terms' => $terms );
		}
		return $rows;
	}

	private static function config_categories() {
		$rows = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ) ) as $t ) {
			$rows[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count, 'image_id' => (int) get_term_meta( $t->term_id, 'thumbnail_id', true ) );
		}
		return $rows;
	}

	private static function method_rows( $methods ) {
		$rows = array();
		foreach ( (array) $methods as $m ) {
			$row = array( 'id' => $m->id, 'instance_id' => (int) $m->get_instance_id(), 'title' => $m->get_title(), 'enabled' => 'yes' === $m->enabled );
			if ( 'flat_rate' === $m->id ) { $row['cost'] = $m->get_option( 'cost' ); }
			$rows[] = $row;
		}
		return $rows;
	}

	private static function config_shipping() {
		$zones = array();
		foreach ( (array) WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = array( 'id' => (int) $zone['id'], 'name' => $zone['zone_name'], 'locations' => array_map( function ( $l ) { return $l->type . ':' . $l->code; }, (array) $zone['zone_locations'] ), 'methods' => self::method_rows( $zone['shipping_methods'] ) );
		}
		$rest = new WC_Shipping_Zone( 0 );
		$zones[] = array( 'id' => 0, 'name' => 'Locations not covered by your other zones', 'locations' => array(), 'methods' => self::method_rows( $rest->get_shipping_methods() ) );
		$classes = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) ) as $t ) { $classes[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ); }
		return array( 'zones' => $zones, 'classes' => $classes, 'note' => 'Shipping zones and methods are read-only here.' );
	}

	private static function config_payments() {
		$rows = array();
		foreach ( (array) WC()->payment_gateways()->payment_gateways() as $g ) {
			$rows[] = array( 'id' => $g->id, 'title' => $g->get_title(), 'enabled' => 'yes' === $g->enabled, 'refunds' => (bool) $g->supports( 'refunds' ) );
		}
		return array( 'gateways' => $rows, 'note' => 'Payment gateways are shown without their settings or keys, and are not changed from here.' );
	}

	private static function config_taxes() {
		$classes = array( '' => 'Standard' );
		foreach ( (array) WC_Tax::get_tax_classes() as $name ) { $classes[ sanitize_title( $name ) ] = $name; }
		$rows = array();
		foreach ( $classes as $slug => $name ) {
			$rates = array();
			foreach ( (array) WC_Tax::get_rates_for_tax_class( $slug ) as $r ) { $rates[] = array( 'id' => (int) $r->tax_rate_id, 'country' => $r->tax_rate_country, 'state' => $r->tax_rate_state, 'rate' => $r->tax_rate, 'name' => $r->tax_rate_name, 'priority' => (int) $r->tax_rate_priority, 'compound' => (bool) $r->tax_rate_compound, 'shipping' => (bool) $r->tax_rate_shipping ); }
			$rows[] = array( 'slug' => $slug, 'name' => $name, 'rates' => $rates );
		}
		return array( 'enabled' => (bool) wc_tax_enabled(), 'prices_include_tax' => 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ), 'classes' => $rows, 'note' => 'Tax rates are read-only here.' );
	}

	private static function config_order_statuses() {
		return self::order_statuses();
	}

	private static function config_coupons() {
		$rows = array();
		foreach ( (array) get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC' ) ) as $post ) {
			$c      = new WC_Coupon( $post->ID );
			$rows[] = array_intersect_key( self::describe_coupon( $c ), array_flip( array( 'id', 'code', 'status', 'discount_type', 'amount', 'date_expires', 'usage_count', 'usage_limit' ) ) );
		}
		return array( 'coupons' => $rows, 'enabled' => (bool) wc_coupons_enabled() );
	}

	/* ----------------------------------------------------------------- */
	/* Store settings                                                    */
	/* ----------------------------------------------------------------- */

	/** The WooCommerce settings an app may read and change. Keys, gateways, emails, URLs and checkout pages are left out. */
	private static function setting_rules() {
		$text   = function ( $max ) { return function ( $v ) use ( $max ) { return is_scalar( $v ) && mb_strlen( (string) $v ) <= $max ? sanitize_text_field( (string) $v ) : null; }; };
		$bool   = function ( $v ) { $b = filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); return null === $b ? null : ( $b ? 'yes' : 'no' ); };
		$choice = function ( $options ) { return function ( $v ) use ( $options ) { return in_array( (string) $v, $options, true ) ? (string) $v : null; }; };
		$whole  = function ( $min, $max ) { return function ( $v ) use ( $min, $max ) { return is_numeric( $v ) && (int) $v == $v && (int) $v >= $min && (int) $v <= $max ? (string) (int) $v : null; }; };
		return array(
			'store_address'          => array( 'Store street address', $text( 100 ), 'text' ),
			'store_address_2'        => array( 'Store address line 2', $text( 100 ), 'text' ),
			'store_city'             => array( 'Store city', $text( 100 ), 'text' ),
			'store_postcode'         => array( 'Store postcode', $text( 20 ), 'text' ),
			'default_country'        => array( 'Store country, a code like US or GB, or US:CA with a state', function ( $v ) { if ( ! is_string( $v ) || ! function_exists( 'WC' ) ) { return null; } $parts = explode( ':', strtoupper( $v ) ); $countries = WC()->countries->get_countries(); if ( ! isset( $countries[ $parts[0] ] ) ) { return null; } if ( isset( $parts[1] ) ) { $states = WC()->countries->get_states( $parts[0] ); if ( ! $states || ! isset( $states[ $parts[1] ] ) ) { return null; } } return implode( ':', $parts ); }, 'text' ),
			'currency'               => array( 'Currency code, for example USD', function ( $v ) { return is_string( $v ) && function_exists( 'get_woocommerce_currencies' ) && isset( get_woocommerce_currencies()[ strtoupper( $v ) ] ) ? strtoupper( $v ) : null; }, 'text' ),
			'currency_pos'           => array( 'Currency symbol position: left, right, left_space, right_space', $choice( array( 'left', 'right', 'left_space', 'right_space' ) ), 'text' ),
			'price_thousand_sep'     => array( 'Thousands separator (up to 4 characters)', $text( 4 ), 'text' ),
			'price_decimal_sep'      => array( 'Decimal separator (up to 4 characters, not empty)', function ( $v ) { return is_string( $v ) && '' !== $v && mb_strlen( $v ) <= 4 ? sanitize_text_field( $v ) : null; }, 'text' ),
			'price_num_decimals'     => array( 'Number of decimals, 0 to 8', $whole( 0, 8 ), 'text' ),
			'calc_taxes'             => array( 'Calculate taxes: true or false', $bool, 'bool' ),
			'prices_include_tax'     => array( 'Prices are entered including tax: true or false', $bool, 'bool' ),
			'enable_coupons'         => array( 'Allow coupons: true or false', $bool, 'bool' ),
			'manage_stock'           => array( 'Stock management: true or false', $bool, 'bool' ),
			'hold_stock_minutes'     => array( 'Hold stock for unpaid orders, in minutes (0 for never release), 0 to 100000', $whole( 0, 100000 ), 'text' ),
			'notify_low_stock_amount' => array( 'Low stock threshold, 0 to 100000', $whole( 0, 100000 ), 'text' ),
			'notify_no_stock_amount' => array( 'Out of stock threshold, 0 to 100000', $whole( 0, 100000 ), 'text' ),
			'hide_out_of_stock_items' => array( 'Hide out of stock items: true or false', $bool, 'bool' ),
			'enable_guest_checkout'  => array( 'Allow checkout without an account: true or false', $bool, 'bool' ),
			'enable_reviews'         => array( 'Allow product reviews: true or false', $bool, 'bool' ),
			'weight_unit'            => array( 'Weight unit: kg, g, lbs, oz', $choice( array( 'kg', 'g', 'lbs', 'oz' ) ), 'text' ),
			'dimension_unit'         => array( 'Dimension unit: m, cm, mm, in, yd', $choice( array( 'm', 'cm', 'mm', 'in', 'yd' ) ), 'text' ),
		);
	}

	private static function read_setting( $name, $rule ) {
		$value = get_option( 'woocommerce_' . $name );
		return 'bool' === $rule[2] ? 'yes' === $value : $value;
	}

	public static function update_settings( $a ) {
		return self::guarded( function () use ( $a ) {
			$changes = isset( $a['settings'] ) ? $a['settings'] : null;
			if ( ! is_array( $changes ) || ! $changes ) { return self::bad( 'Provide "settings" as an object of setting names and new values. See wp_woo_overview for the names.' ); }
			$rules = self::setting_rules();
			$clean = array();
			foreach ( $changes as $name => $value ) {
				if ( ! isset( $rules[ $name ] ) ) { return self::bad( sprintf( '"%s" cannot be changed here. Allowed: %s.', (string) $name, implode( ', ', array_keys( $rules ) ) ) ); }
				$checked = $rules[ $name ][1]( $value );
				if ( null === $checked ) { return self::bad( sprintf( 'Invalid value for %s. Expected: %s.', $name, $rules[ $name ][0] ) ); }
				$clean[ 'woocommerce_' . $name ] = $checked;
			}
			if ( ! empty( $a['dry_run'] ) ) { return array( 'ok' => true, 'dry_run' => true, 'would_set' => $clean ); }
			$before = array();
			foreach ( $clean as $option => $value ) { $before[ $option ] = get_option( $option ); }
			foreach ( $clean as $option => $value ) { update_option( $option, $value ); }
			self::record( 'wp_woo_update_settings', 'option', 0, implode( ', ', array_map( function ( $k ) { return substr( $k, 12 ); }, array_keys( $clean ) ) ), 'Changed WooCommerce settings', array( 'op' => 'woo_options', 'values' => $before ) );
			return array( 'ok' => true, 'changed' => array_map( function ( $k ) { return substr( $k, 12 ); }, array_keys( $clean ) ), 'previous' => $before );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Undo                                                              */
	/* ----------------------------------------------------------------- */

	/** Put a product's or variation's properties back, unless it was edited since (judged by a hash of what the change touched). */
	public static function restore_props( $id, $state, $after_hash, $force, $keys = null ) {
		if ( ! self::available() ) { return self::missing(); }
		return self::guarded( function () use ( $id, $state, $after_hash, $force, $keys ) {
			$p = wc_get_product( (int) $id );
			if ( ! $p ) { return new WP_Error( 'wpmcp_gone', 'The product no longer exists.' ); }
			$check = array_flip( array_diff( $keys ? $keys : array_keys( $state ), array( 'type', 'meta' ) ) ); // The keys the change touched, so a type change (whose old state lacks some) still compares like with like.
			if ( isset( $state['meta'] ) ) { $check['meta'] = $state['meta']; }
			if ( ! $force && null !== $after_hash && md5( (string) wp_json_encode( self::state_of( $p, $check ) ) ) !== $after_hash ) {
				return new WP_Error( 'wpmcp_changed_since', 'This product was edited after the change. Rolling back will overwrite those edits.' );
			}
			if ( isset( $state['type'] ) ) { $p = self::with_type( $p, $state['type'] ); }
			self::apply_props( $p, array_diff_key( $state, array( 'type' => 1 ) ) );
			$p->save();
			if ( $p->is_type( 'variation' ) ) { WC_Product_Variable::sync( $p->get_parent_id() ); wc_delete_product_transients( $p->get_parent_id() ); }
			return true;
		} );
	}

	public static function restore_bulk( $items, $after_hash, $force ) {
		if ( ! self::available() ) { return self::missing(); }
		if ( ! $force && null !== $after_hash ) {
			$now = array();
			foreach ( $items as $entry ) { $p = wc_get_product( (int) $entry['id'] ); if ( ! $p ) { return new WP_Error( 'wpmcp_gone', 'A product in this change no longer exists.' ); } $now[ $entry['id'] ] = self::state_of( $p, array_flip( $entry['keys'] ) ); }
			if ( md5( (string) wp_json_encode( $now ) ) !== $after_hash ) { return new WP_Error( 'wpmcp_changed_since', 'One of these products was edited after the change. Rolling back will overwrite those edits.' ); }
		}
		foreach ( $items as $entry ) {
			$r = self::restore_props( (int) $entry['id'], $entry['state'], null, true );
			if ( is_wp_error( $r ) ) { return $r; }
		}
		return true;
	}

	public static function restore_coupon( $id, $state, $after_hash, $force ) {
		return self::guarded( function () use ( $id, $state, $after_hash, $force ) {
			$c = new WC_Coupon( (int) $id );
			if ( ! $c->get_id() ) { return new WP_Error( 'wpmcp_gone', 'The coupon no longer exists.' ); }
			if ( ! $force && null !== $after_hash && md5( (string) wp_json_encode( self::coupon_state( $c, array_keys( $state ) ) ) ) !== $after_hash ) { return new WP_Error( 'wpmcp_changed_since', 'This coupon was edited after the change. Rolling back will overwrite those edits.' ); }
			foreach ( $state as $key => $value ) { if ( 'id' !== $key ) { $c->{ 'set_' . $key }( $value ); } }
			$c->save();
			return true;
		} );
	}

	public static function restore_order( $id, $state, $keys, $notes, $after_hash, $force ) {
		return self::guarded( function () use ( $id, $state, $keys, $notes, $after_hash, $force ) {
			$o = self::order_or_error( $id );
			if ( is_wp_error( $o ) ) { return new WP_Error( 'wpmcp_gone', 'The order no longer exists.' ); }
			if ( ! $force && null !== $after_hash && md5( (string) wp_json_encode( self::order_state( $o, $keys ) ) ) !== $after_hash ) { return new WP_Error( 'wpmcp_changed_since', 'This order was edited after the change. Rolling back will overwrite those edits.' ); }
			$status = isset( $state['status'] ) ? $state['status'] : '';
			unset( $state['status'] );
			self::apply_order_fields( $o, $state );
			$o->save();
			if ( '' !== $status && $status !== $o->get_status() ) { $o->update_status( $status, 'Status put back by a WP MCP rollback.', true ); }
			foreach ( (array) $notes as $nid ) { wc_delete_order_note( (int) $nid ); }
			return true;
		} );
	}

	/** Undo a created product, variation, coupon or order by moving it to the trash. */
	public static function undo_create( $id ) {
		return self::guarded( function () use ( $id ) {
			$id = (int) $id;
			$o  = wc_get_order( $id );
			if ( $o instanceof WC_Order ) { $o->delete( false ); return true; }
			$c = 'shop_coupon' === get_post_type( $id ) ? new WC_Coupon( $id ) : null;
			if ( $c && $c->get_id() ) { $c->delete( false ); return true; }
			$p = wc_get_product( $id );
			if ( ! $p ) { return new WP_Error( 'wpmcp_gone', 'It no longer exists.' ); }
			$parent = (int) $p->get_parent_id();
			$p->delete( false );
			if ( $parent ) { WC_Product_Variable::sync( $parent ); wc_delete_product_transients( $parent ); }
			return true;
		} );
	}

	/** Bring a trashed product, variation, coupon or order back. */
	public static function untrash( $id, $children = array() ) {
		return self::guarded( function () use ( $id, $children ) {
			$id    = (int) $id;
			$store = null;
			$o     = wc_get_order( $id );
			if ( $o instanceof WC_Order ) {
				$store = $o->get_data_store();
				if ( 'trash' !== $o->get_status() ) { return new WP_Error( 'wpmcp_gone', 'The order is no longer in the trash.' ); }
				if ( self::hpos() ) { return $store->untrash_order( $o ) ? true : new WP_Error( 'wpmcp_failed', 'Could not restore the order.' ); } // Orders in custom tables are not posts, so wp_untrash_post cannot bring them back.
			}
			$post = get_post( $id );
			if ( ! $post || 'trash' !== $post->post_status ) { return new WP_Error( 'wpmcp_gone', 'It is no longer in the trash.' ); }
			if ( ! wp_untrash_post( $id ) ) { return new WP_Error( 'wpmcp_failed', 'Could not restore it.' ); }
			if ( 'product_variation' === $post->post_type ) { WC_Product_Variable::sync( (int) $post->post_parent ); wc_delete_product_transients( (int) $post->post_parent ); }
			elseif ( 'product' === $post->post_type ) {
				foreach ( (array) get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'trash', 'numberposts' => 200 ) ) as $child ) { if ( in_array( (int) $child->ID, array_map( 'intval', (array) $children ), true ) ) { wp_untrash_post( $child->ID ); } }
				WC_Product_Variable::sync( $id );
				wc_delete_product_transients( $id );
			}
			return true;
		} );
	}

	public static function restore_term( $id, $before ) {
		if ( ! self::available() ) { return self::missing(); }
		$r = wp_update_term( (int) $id, 'product_cat', $before['term'] );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) $before['meta'] as $k => $v ) { if ( '' === $v || null === $v || false === $v ) { delete_term_meta( (int) $id, $k ); } else { update_term_meta( (int) $id, $k, $v ); } }
		return true;
	}

	public static function recreate_term( $before ) {
		if ( ! self::available() ) { return self::missing(); }
		$t = $before['term'];
		$r = wp_insert_term( $t['name'], 'product_cat', array( 'slug' => $t['slug'], 'description' => $t['description'], 'parent' => $t['parent'] ) );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) $before['meta'] as $k => $v ) { if ( '' !== $v && null !== $v && false !== $v ) { update_term_meta( (int) $r['term_id'], $k, $v ); } }
		return true;
	}

	public static function restore_attribute( $id, $before ) {
		if ( ! self::available() ) { return self::missing(); }
		$r = wc_update_attribute( (int) $id, array( 'name' => $before['name'], 'slug' => $before['slug'], 'type' => $before['type'], 'order_by' => $before['order_by'], 'has_archives' => $before['has_archives'] ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public static function undo_attribute_create( $id ) {
		if ( ! self::available() ) { return self::missing(); }
		return wc_delete_attribute( (int) $id ) ? true : new WP_Error( 'wpmcp_gone', 'It no longer exists.' );
	}

	public static function restore_options( $values ) {
		$rules = self::setting_rules();
		foreach ( (array) $values as $option => $value ) {
			$name = 0 === strpos( (string) $option, 'woocommerce_' ) ? substr( $option, 12 ) : '';
			if ( isset( $rules[ $name ] ) ) { update_option( $option, $value ); }
		}
		return true;
	}
}
