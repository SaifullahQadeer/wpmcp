<?php
/** WooCommerce tool names, their specifications and the dispatch to WPMCP_Woo. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPMCP_Woo_Tools {
	public static function names() {
		return array_keys( self::definitions() );
	}

	/** Tools are offered to apps only while WooCommerce is active, so other sites see exactly the tools they always did. */
	public static function visible( $tools ) {
		if ( WPMCP_Woo::available() ) { return $tools; }
		$hidden = self::names();
		return array_values( array_filter( $tools, function ( $tool ) use ( $hidden ) { return ! in_array( $tool['name'], $hidden, true ); } ) );
	}

	public static function run( $name, $args ) {
		switch ( $name ) {
			case 'wp_woo_overview':        return WPMCP_Woo::overview();
			case 'wp_woo_list_products':   return WPMCP_Woo::list_products( $args );
			case 'wp_woo_get_product':     return isset( $args['id'] ) ? WPMCP_Woo::get_product( $args['id'] ) : self::missing( 'id' );
			case 'wp_woo_list_config':     return WPMCP_Woo::list_config( isset( $args['what'] ) ? (string) $args['what'] : '' );
			case 'wp_woo_sales_summary':   return WPMCP_Woo::sales_summary( $args );
			case 'wp_woo_list_orders':     return WPMCP_Woo::list_orders( $args );
			case 'wp_woo_get_order':       return isset( $args['id'] ) ? WPMCP_Woo::get_order( $args['id'] ) : self::missing( 'id' );
			case 'wp_woo_list_customers':  return WPMCP_Woo::list_customers( $args );
			case 'wp_woo_save_product':    return WPMCP_Woo::save_product( $args );
			case 'wp_woo_save_variation':  return WPMCP_Woo::save_variation( $args );
			case 'wp_woo_bulk_update':     return WPMCP_Woo::bulk_update( $args );
			case 'wp_woo_save_category':   return WPMCP_Woo::save_category( $args );
			case 'wp_woo_save_coupon':     return WPMCP_Woo::save_coupon( $args );
			case 'wp_woo_create_order':    return WPMCP_Woo::create_order( $args );
			case 'wp_woo_update_order':    return WPMCP_Woo::update_order( $args );
			case 'wp_woo_save_attribute':  return WPMCP_Woo::save_attribute( $args );
			case 'wp_woo_update_settings': return WPMCP_Woo::update_settings( $args );
			case 'wp_woo_refund_order':    return WPMCP_Woo::refund_order( $args );
			case 'wp_woo_delete':          return isset( $args['kind'], $args['id'] ) ? WPMCP_Woo::delete_item( (string) $args['kind'], $args['id'], ! empty( $args['force'] ) ) : self::missing( '"kind" or "id"' );
		}
		return new WP_Error( 'wpmcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	private static function missing( $what ) {
		return new WP_Error( 'wpmcp_missing_arg', 'Missing ' . ( 0 === strpos( $what, '"' ) ? $what : '"' . $what . '"' ) . '.' );
	}

	public static function tools_spec() {
		$out = array();
		foreach ( self::definitions() as $name => $d ) {
			list( $title, $props, $required, $kind, $description ) = $d;
			$out[] = array(
				'name'        => $name,
				'title'       => $title,
				'description' => $description,
				'inputSchema' => array( 'type' => 'object', 'properties' => $props ? $props : new stdClass() ) + ( $required ? array( 'required' => $required ) : array() ),
				'annotations' => array( 'title' => $title, 'readOnlyHint' => 'read' === $kind, 'destructiveHint' => 'destructive' === $kind, 'idempotentHint' => 'read' === $kind, 'openWorldHint' => false ),
			);
		}
		return $out;
	}

	/** name => [title, properties, required, kind (read, write or destructive), description] */
	private static function definitions() {
		$id       = array( 'type' => 'integer', 'description' => 'Numeric ID.' );
		$text     = array( 'type' => 'string' );
		$flag     = array( 'type' => 'boolean' );
		$dry      = array( 'type' => 'boolean', 'description' => 'Check everything and return what would be saved, without saving.' );
		$paging   = array( 'per_page' => array( 'type' => 'integer', 'description' => 'Items per page.' ), 'page' => array( 'type' => 'integer', 'description' => 'Page number, from 1.' ) );
		$address  = array( 'type' => 'object', 'description' => 'Fields: first_name, last_name, company, address_1, address_2, city, state, postcode, country (two-letter code), and for billing also email and phone.' );
		$statuses = 'pending, processing, on-hold, completed, cancelled, failed';
		$money    = array( 'type' => array( 'string', 'number' ), 'description' => 'Amount as a number or text, for example 19.90.' );

		$product = array(
			'name'               => array( 'type' => 'string', 'description' => 'Product name. Required when creating.' ),
			'slug'               => $text,
			'status'             => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ), 'description' => 'Defaults to draft when creating.' ),
			'catalog_visibility' => array( 'type' => 'string', 'enum' => array( 'visible', 'catalog', 'search', 'hidden' ) ),
			'featured'           => $flag,
			'description'        => array( 'type' => 'string', 'description' => 'Long description (HTML allowed).' ),
			'short_description'  => array( 'type' => 'string', 'description' => 'Short description shown beside the price (HTML allowed).' ),
			'purchase_note'      => $text,
			'sku'                => array( 'type' => 'string', 'description' => 'Must be unique across products. Empty clears it.' ),
			'regular_price'      => $money,
			'sale_price'         => array( 'type' => array( 'string', 'number' ), 'description' => 'Must not be higher than regular_price. Empty ends the sale.' ),
			'date_on_sale_from'  => array( 'type' => 'string', 'description' => 'Sale start, Y-m-d, or empty.' ),
			'date_on_sale_to'    => array( 'type' => 'string', 'description' => 'Sale end, Y-m-d, or empty.' ),
			'tax_status'         => array( 'type' => 'string', 'enum' => array( 'taxable', 'shipping', 'none' ) ),
			'tax_class'          => array( 'type' => 'string', 'description' => 'Tax class slug; empty for the standard class. See wp_woo_list_config what=taxes.' ),
			'manage_stock'       => array( 'type' => 'boolean', 'description' => 'Track a quantity. Needed before stock_quantity.' ),
			'stock_quantity'     => array( 'type' => 'integer' ),
			'stock_status'       => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ), 'description' => 'When manage_stock is on, WooCommerce sets this from the quantity.' ),
			'backorders'         => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ) ),
			'low_stock_amount'   => array( 'type' => array( 'integer', 'string' ) ),
			'sold_individually'  => $flag,
			'weight'             => array( 'type' => array( 'string', 'number' ) ),
			'length'             => array( 'type' => array( 'string', 'number' ) ),
			'width'              => array( 'type' => array( 'string', 'number' ) ),
			'height'             => array( 'type' => array( 'string', 'number' ) ),
			'shipping_class'     => array( 'type' => array( 'string', 'integer' ), 'description' => 'Shipping class slug or ID, empty for none.' ),
			'virtual'            => $flag,
			'downloadable'       => $flag,
			'downloads'          => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'List of {name, file}; file is a full http(s) URL, for example a media library file. Needs downloadable true.' ),
			'download_limit'     => array( 'type' => array( 'integer', 'string' ), 'description' => '-1 or empty for unlimited.' ),
			'download_expiry'    => array( 'type' => array( 'integer', 'string' ), 'description' => 'Days; -1 or empty for never.' ),
			'categories'         => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Existing product category IDs, slugs or names. Replaces the list. Create new ones with wp_woo_save_category.' ),
			'tags'               => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Product tag IDs, slugs or names; unknown names are created. Replaces the list.' ),
			'images'             => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Media library IDs. The first is the main image, the rest the gallery. Replaces them. Upload first with wp_upload_media.' ),
			'attributes'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Replaces the product\'s attributes. Each: {"name":"Color","options":["Red","Blue"],"visible":true,"variation":true}. A name that matches a global attribute (see wp_woo_list_config what=attributes) uses it, and missing values are created; otherwise it is a custom attribute for this product. variation:true is for variable products and makes the attribute selectable.' ),
			'default_attributes' => array( 'type' => 'object', 'description' => 'Variable products: the pre-selected option, e.g. {"Color":"Red"}.' ),
			'upsell_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'cross_sell_ids'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'menu_order'         => array( 'type' => 'integer' ),
			'reviews_allowed'    => $flag,
			'product_url'        => array( 'type' => 'string', 'description' => 'External products: where the button goes.' ),
			'button_text'        => array( 'type' => 'string', 'description' => 'External products: button label.' ),
			'children'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Grouped products: IDs of the products it groups.' ),
			'meta'               => array( 'type' => 'object', 'description' => 'Custom fields as name/value. Names starting with an underscore are WooCommerce\'s own and are refused. A null value deletes the field.' ),
			'acf'                => array( 'type' => 'object', 'description' => 'Advanced Custom Fields values by field name, saved through ACF (needs ACF).' ),
		);
		$variation = array(
			'id'                => array( 'type' => 'integer', 'description' => 'Variation ID to update. Omit to create.' ),
			'parent_id'         => array( 'type' => 'integer', 'description' => 'The variable product (needed when creating).' ),
			'attributes'        => array( 'type' => 'object', 'description' => 'Which option this variation is for, e.g. {"Color":"Red","Size":"M"}. Only attributes marked variation:true on the parent. An empty value means "any". Each combination can exist once.' ),
			'status'            => array( 'type' => 'string', 'enum' => array( 'publish', 'private' ), 'description' => 'private hides the variation.' ),
			'sku'               => $text,
			'regular_price'     => $money,
			'sale_price'        => array( 'type' => array( 'string', 'number' ) ),
			'date_on_sale_from' => $text,
			'date_on_sale_to'   => $text,
			'manage_stock'      => array( 'type' => array( 'boolean', 'string' ), 'description' => 'true to track its own stock, false for none, "parent" to follow the product.' ),
			'stock_quantity'    => array( 'type' => 'integer' ),
			'stock_status'      => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
			'backorders'        => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ) ),
			'low_stock_amount'  => array( 'type' => array( 'integer', 'string' ) ),
			'weight'            => array( 'type' => array( 'string', 'number' ) ),
			'length'            => array( 'type' => array( 'string', 'number' ) ),
			'width'             => array( 'type' => array( 'string', 'number' ) ),
			'height'            => array( 'type' => array( 'string', 'number' ) ),
			'shipping_class'    => array( 'type' => array( 'string', 'integer' ) ),
			'tax_class'         => array( 'type' => 'string', 'description' => '"parent" follows the product.' ),
			'virtual'           => $flag,
			'downloadable'      => $flag,
			'downloads'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			'download_limit'    => array( 'type' => array( 'integer', 'string' ) ),
			'download_expiry'   => array( 'type' => array( 'integer', 'string' ) ),
			'image'             => array( 'type' => 'integer', 'description' => 'Media library ID for this variation\'s image; 0 clears it.' ),
			'description'       => $text,
			'menu_order'        => array( 'type' => 'integer' ),
			'meta'              => array( 'type' => 'object' ),
			'dry_run'           => $dry,
		);

		return array(
			'wp_woo_overview' => array( 'WooCommerce overview', array(), array(), 'read',
				'Start here for a WooCommerce store: WooCommerce version, currency, whether orders use HPOS, product counts by status, order counts by status and the store settings that can be changed. Call it before other WooCommerce tools.' ),
			'wp_woo_list_products' => array( 'List products', array_merge( array(
				'search'       => array( 'type' => 'string', 'description' => 'Search term (name, description, SKU).' ),
				'status'       => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
				'type'         => array( 'type' => 'string', 'enum' => array( 'simple', 'variable', 'grouped', 'external' ) ),
				'category'     => array( 'type' => array( 'string', 'integer' ), 'description' => 'Product category slug or ID.' ),
				'tag'          => array( 'type' => 'string', 'description' => 'Product tag slug.' ),
				'sku'          => array( 'type' => 'string', 'description' => 'SKU or part of it.' ),
				'stock_status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'featured'     => $flag,
				'on_sale'      => array( 'type' => 'boolean', 'description' => 'true for products on sale now.' ),
				'orderby'      => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'id', 'menu_order' ) ),
				'order'        => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
			), $paging ), array(), 'read',
				'List products with price, sale price, stock and categories. Filter by search, status, type, category, tag, SKU, stock status, featured or on sale. Variable products show their variation count; open one with wp_woo_get_product.' ),
			'wp_woo_get_product' => array( 'Get a product', array( 'id' => $id ), array( 'id' ), 'read',
				'Read one product in full: prices, sale dates, stock, dimensions, tax, categories, tags, images, attributes (global and custom), downloads, linked products, custom fields, and for a variable product every variation with its own price, stock and options (up to 100).' ),
			'wp_woo_list_config' => array( 'List store setup', array( 'what' => array( 'type' => 'string', 'enum' => array( 'attributes', 'categories', 'shipping', 'payments', 'taxes', 'order_statuses', 'coupons' ), 'description' => 'Which part. Omit for all.' ) ), array(), 'read',
				'Read how the store is set up: global attributes and their values, product categories, shipping zones, methods and classes, payment gateways (without keys or settings), tax classes and rates, order statuses and coupons. Shipping, payments and taxes are read-only here.' ),
			'wp_woo_sales_summary' => array( 'Sales summary', array(
				'from'     => array( 'type' => 'string', 'description' => 'First day, Y-m-d. Default: 29 days before "to".' ),
				'to'       => array( 'type' => 'string', 'description' => 'Last day, Y-m-d. Default: today.' ),
				'statuses' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Order statuses to count. Default: processing and completed.' ),
			), array(), 'read',
				'Totals for a period: orders, items sold, gross, refunds, net, tax, shipping, discounts, average order value, sales by day and the top products. Up to one year and 2,000 orders (it says if truncated).' ),
			'wp_woo_list_orders' => array( 'List orders', array_merge( array(
				'status'      => array( 'type' => array( 'string', 'array' ), 'description' => 'One status or a list: ' . $statuses . ', refunded. Default: all.' ),
				'customer_id' => array( 'type' => 'integer', 'description' => 'Orders of this customer account.' ),
				'search'      => array( 'type' => 'string', 'description' => 'Order number, name, email, address or product name.' ),
				'from'        => array( 'type' => 'string', 'description' => 'Created on or after, Y-m-d.' ),
				'to'          => array( 'type' => 'string', 'description' => 'Created on or before, Y-m-d.' ),
			), $paging ), array(), 'read',
				'List orders, newest first: number, status, date, total, item count and customer name. Orders hold customer details, so this needs "Read and edit" access or more.' ),
			'wp_woo_get_order' => array( 'Get an order', array( 'id' => $id ), array( 'id' ), 'read',
				'Read one order in full: items, totals, refunds, coupons, shipping, billing and shipping addresses (with email and phone), payment method, customer note and the latest order notes. Needs "Read and edit" access or more.' ),
			'wp_woo_list_customers' => array( 'List customers', array_merge( array( 'search' => array( 'type' => 'string', 'description' => 'Name, username or email.' ) ), $paging ), array(), 'read',
				'List customer accounts, newest first, with order count and total spent. Needs "Read and edit" access or more.' ),
			'wp_woo_save_product' => array( 'Create or update a product', array_merge( array(
				'id'      => array( 'type' => 'integer', 'description' => 'Product ID to update. Omit to create.' ),
				'type'    => array( 'type' => 'string', 'enum' => array( 'simple', 'variable', 'grouped', 'external' ), 'description' => 'Product type (default simple). Changing it on an existing product is allowed once it has no variations.' ),
			), $product, array( 'dry_run' => $dry ) ), array(), 'destructive',
				'Create or update a WooCommerce product through WooCommerce itself, so stock, prices and lookup tables stay correct. Only the fields you send change; lists (categories, tags, images, attributes) replace the old list. Everything is checked first and nothing is saved if one field is invalid. New products default to draft. Variable products: save the product with attributes (variation:true), then add each variation with wp_woo_save_variation. Recorded in the history and can be rolled back.' ),
			'wp_woo_save_variation' => array( 'Create or update a variation', $variation, array(), 'destructive',
				'Create or update one variation of a variable product: its option combination, price, sale, stock, SKU, size and image. The parent must already list the attribute with variation:true and the option. The product\'s price range and stock status are refreshed. Recorded in the history and can be rolled back.' ),
			'wp_woo_bulk_update' => array( 'Update prices and stock in bulk', array(
				'items'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Up to 100 of {id, regular_price, sale_price, date_on_sale_from, date_on_sale_to, manage_stock, stock_quantity, stock_status, backorders, status}. id can be a product or a variation.' ),
				'dry_run' => $dry,
			), array( 'items' ), 'destructive',
				'Change prices, sales, stock or status for many products or variations in one call. Every item is checked first; if one fails to save the earlier ones are put back. One history entry covers the batch, and it can be rolled back as a whole.' ),
			'wp_woo_save_category' => array( 'Create or update a product category', array(
				'id' => array( 'type' => 'integer', 'description' => 'Category ID to update. Omit to create.' ), 'name' => $text, 'slug' => $text, 'parent' => array( 'type' => 'integer', 'description' => 'Parent category ID, 0 for top level.' ),
				'description' => $text, 'image' => array( 'type' => 'integer', 'description' => 'Media library ID; 0 clears it.' ), 'display_type' => array( 'type' => 'string', 'enum' => array( 'default', 'products', 'subcategories', 'both' ) ), 'dry_run' => $dry,
			), array(), 'destructive',
				'Create or update a product category: name, slug, parent, description, image and display type. Recorded in the history and can be rolled back. For tags use wp_create_term with taxonomy product_tag.' ),
			'wp_woo_save_coupon' => array( 'Create or update a coupon', array(
				'id' => array( 'type' => 'integer', 'description' => 'Coupon ID to update. Omit to create.' ), 'code' => array( 'type' => 'string', 'description' => 'The code customers type. Unique; stored in lowercase.' ),
				'description' => $text, 'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
				'discount_type' => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ) ), 'amount' => $money, 'date_expires' => array( 'type' => 'string', 'description' => 'Y-m-d, or empty for none.' ),
				'usage_limit' => array( 'type' => 'integer', 'description' => 'Total uses; 0 for unlimited.' ), 'usage_limit_per_user' => array( 'type' => 'integer' ), 'limit_usage_to_x_items' => array( 'type' => 'integer' ),
				'individual_use' => $flag, 'free_shipping' => $flag, 'exclude_sale_items' => $flag, 'minimum_amount' => $money, 'maximum_amount' => $money,
				'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ), 'excluded_product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'product_categories' => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ) ), 'excluded_product_categories' => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ) ),
				'email_restrictions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Only these customer emails may use it.' ), 'dry_run' => $dry,
			), array(), 'destructive',
				'Create or update a discount coupon. A new coupon needs a code and an amount. Percentages cannot exceed 100. Recorded in the history and can be rolled back.' ),
			'wp_woo_create_order' => array( 'Create an order', array(
				'line_items'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'What was bought: [{"product_id":12,"quantity":2}]. For a variable product give "variation_id". Optional "price" sets the unit price instead of the current one.' ),
				'customer_id'    => array( 'type' => 'integer', 'description' => 'Customer account ID, 0 for a guest.' ),
				'billing'        => $address, 'shipping' => array_merge( $address, array( 'description' => 'Same fields as billing, without email and phone.' ) ),
				'coupons'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Coupon codes to apply; an invalid one cancels the order.' ),
				'shipping_lines' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Shipping charges: [{"title":"Courier","total":"9.00"}].' ),
				'status'         => array( 'type' => 'string', 'description' => 'One of ' . $statuses . '. Default pending.' ),
				'payment_method' => array( 'type' => 'string', 'description' => 'Gateway ID such as cod or bacs.' ), 'payment_method_title' => $text,
				'customer_note'  => $text, 'note' => array( 'type' => 'string', 'description' => 'Private note for the store team.' ),
				'set_paid'       => array( 'type' => 'boolean', 'description' => 'Mark it paid (WooCommerce then moves it to processing or completed and reduces stock).' ),
				'dry_run'        => $dry,
			), array( 'line_items' ), 'write',
				'Create an order, as a store manager would by hand: items, customer, addresses, coupons, shipping charges and status. Totals and tax are calculated by WooCommerce. WooCommerce may email the customer for some statuses, exactly as it does in its own admin. Rolling it back moves the order to the trash.' ),
			'wp_woo_update_order' => array( 'Update an order', array(
				'id'                   => $id,
				'status'               => array( 'type' => 'string', 'description' => 'New status: ' . $statuses . '. Use wp_woo_refund_order to refund.' ),
				'billing'              => $address, 'shipping' => array_merge( $address, array( 'description' => 'Same fields as billing, without email and phone.' ) ),
				'customer_note'        => $text, 'customer_id' => array( 'type' => 'integer' ), 'payment_method' => $text, 'payment_method_title' => $text,
				'note'                 => array( 'type' => 'string', 'description' => 'Add an order note.' ),
				'note_to_customer'     => array( 'type' => 'boolean', 'description' => 'true to send the note to the customer (WooCommerce emails it). Default: a private note.' ),
				'dry_run'              => $dry,
			), array( 'id' ), 'destructive',
				'Change an order\'s status, addresses, customer note, customer or payment method, and/or add a note. Status changes run WooCommerce\'s normal rules (stock, emails). Recorded in the history; rolling back restores the old values and removes notes added, but cannot recall an email.' ),
			'wp_woo_save_attribute' => array( 'Create or update a global attribute', array(
				'id' => array( 'type' => 'integer', 'description' => 'Attribute ID to update. Omit to create.' ), 'name' => array( 'type' => 'string', 'description' => 'Label, e.g. Color.' ), 'slug' => $text,
				'type' => $text, 'order_by' => array( 'type' => 'string', 'enum' => array( 'menu_order', 'name', 'name_num', 'id' ) ), 'has_archives' => $flag, 'dry_run' => $dry,
			), array(), 'destructive',
				'Create or update a global product attribute (a shared set of values such as Color or Size). It adds a taxonomy, so it needs Full access. Add its values by listing them in "options" when saving a product. Recorded in the history and can be rolled back.' ),
			'wp_woo_update_settings' => array( 'Change store settings', array(
				'settings' => array( 'type' => 'object', 'description' => 'Names and new values, e.g. {"currency":"EUR","calc_taxes":true,"notify_low_stock_amount":5}. Names are listed by wp_woo_overview.' ), 'dry_run' => $dry,
			), array( 'settings' ), 'destructive',
				'Change common WooCommerce settings: store address, currency and price format, taxes on or off, coupons, stock management and thresholds, guest checkout, reviews and units. All values are checked first. Payment gateways, shipping, emails and keys cannot be changed here. Previous values are saved so it can be rolled back.' ),
			'wp_woo_refund_order' => array( 'Refund an order', array(
				'order_id'       => array( 'type' => 'integer' ), 'amount' => $money, 'reason' => $text,
				'line_items'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Optional, to record which lines are refunded or put back in stock: [{"item_id":5,"quantity":1,"amount":"10.00"}]. item_id comes from wp_woo_get_order.' ),
				'restock_items'  => array( 'type' => 'boolean', 'description' => 'Return the listed quantities to stock.' ),
				'refund_payment' => array( 'type' => 'boolean', 'description' => 'true to send the money back through the payment gateway. Default false: the refund is only recorded on the order.' ),
			), array( 'order_id', 'amount' ), 'destructive',
				'Refund part or all of an order. By default this only records the refund; with refund_payment true WooCommerce asks the payment gateway to return the money, which cannot be undone. A refund cannot be rolled back.' ),
			'wp_woo_delete' => array( 'Delete a WooCommerce item', array(
				'kind'  => array( 'type' => 'string', 'enum' => array( 'product', 'variation', 'coupon', 'order', 'category', 'attribute' ) ),
				'id'    => $id,
				'force' => array( 'type' => 'boolean', 'description' => 'Delete permanently instead of moving to the trash (products, variations and coupons only). Permanent deletes cannot be rolled back.' ),
			), array( 'kind', 'id' ), 'destructive',
				'Move a product, variation, coupon or order to the trash (it can be rolled back), or delete a category or attribute. Deleting an attribute removes its values from every product and cannot be undone.' ),
		);
	}
}
