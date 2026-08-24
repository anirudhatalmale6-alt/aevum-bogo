<?php
/**
 * Seeds the local test store with the same shape as aevumbio.shop,
 * in its POST-merge state, so the BOGO plugin can be exercised end to end.
 * Run with: wp eval-file seed.php
 */

if ( ! function_exists( 'wc_get_product' ) ) {
	WP_CLI::error( 'WooCommerce is not loaded.' );
}

update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_coupons', 'yes' );
update_option( 'woocommerce_cart_redirect_after_add', 'no' );

// A payment method we can actually complete an order with.
$gateways = get_option( 'woocommerce_cod_settings', array() );
$gateways['enabled'] = 'yes';
$gateways['title']   = 'Cash on delivery';
update_option( 'woocommerce_cod_settings', $gateways );

// Make sure the shop pages exist.
if ( ! get_option( 'woocommerce_cart_page_id' ) ) {
	WC_Install::create_pages();
}

/** Creates or updates a simple product. */
function seed_simple( $name, $slug, $price ) {
	$existing = get_page_by_path( $slug, OBJECT, 'product' );
	$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$id = $product->save();
	WP_CLI::log( sprintf( 'simple   %-28s #%d  $%s', $name, $id, $price ) );
	return $id;
}

/** Creates or updates a variable product on the global pa_size attribute. */
function seed_variable( $name, $slug, $sizes ) {
	// Global attribute, mirroring how Retatrutide is already built on the live store.
	$taxonomy = 'pa_size';
	$attr_id  = wc_attribute_taxonomy_id_by_name( 'size' );
	if ( ! $attr_id ) {
		$attr_id = wc_create_attribute( array(
			'name'         => 'Size',
			'slug'         => 'size',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		) );
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false, 'public' => false ) );
	}
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false, 'public' => false ) );
	}

	foreach ( array_keys( $sizes ) as $size ) {
		if ( ! term_exists( $size, $taxonomy ) ) {
			wp_insert_term( $size, $taxonomy );
		}
	}

	$existing = get_page_by_path( $slug, OBJECT, 'product' );
	$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Variable();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );

	$attribute = new WC_Product_Attribute();
	$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
	$attribute->set_name( $taxonomy );
	$attribute->set_options( array_keys( $sizes ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$product->set_attributes( array( $attribute ) );

	$parent_id = $product->save();
	wp_set_object_terms( $parent_id, array_keys( $sizes ), $taxonomy );

	$ids = array();
	foreach ( $sizes as $size => $price ) {
		$slug_term = get_term_by( 'name', $size, $taxonomy );
		$variation = null;

		foreach ( wc_get_product( $parent_id )->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child && $child->get_attribute( $taxonomy ) === $size ) {
				$variation = $child;
				break;
			}
		}
		if ( ! $variation ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
		}
		$variation->set_attributes( array( $taxonomy => $slug_term ? $slug_term->slug : sanitize_title( $size ) ) );
		$variation->set_regular_price( (string) $price );
		$variation->set_status( 'publish' );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$vid = $variation->save();
		$ids[ $size ] = $vid;
		WP_CLI::log( sprintf( '  variation %-24s #%d  $%s', $name . ' ' . $size, $vid, $price ) );
	}

	WC_Product_Variable::sync( $parent_id );
	WP_CLI::log( sprintf( 'variable %-28s #%d', $name, $parent_id ) );
	return array( 'parent' => $parent_id, 'variations' => $ids );
}

$tesa   = seed_simple( 'Tesamorelin 10mg', 'tesamorelin-10mg', 80 );
$nad    = seed_simple( 'NAD+ 500mg', 'nad-500mg', 70 );           // deliberately NOT in the offer
$ghk    = seed_variable( 'GHK-CU', 'ghk-cu', array( '50mg' => 55, '100mg' => 70 ) );
$reta   = seed_variable( 'Retatrutide', 'retatrutide', array( '10mg' => 90, '20mg' => 165 ) );
$bpc    = seed_variable( 'BPC-157/ TB-500', 'bpc-157-tb-500-10mg', array( '10mg' => 75, '20mg' => 90 ) );
$bac    = seed_variable( 'Bacteriostatic Water', 'bacteriostatic-water-3ml', array( '3ML' => 7, '10ML' => 15 ) );

// Configure the offer exactly as the client specified.
update_option( 'aevum_bogo_code', 'laborday' );
update_option( 'aevum_bogo_cap', 1 );
update_option( 'aevum_bogo_items', array_values( array_merge(
	array( $tesa ),
	array_values( $ghk['variations'] ),
	array_values( $reta['variations'] )
) ) );

WP_CLI::success( 'Seeded. Eligible IDs: ' . implode( ', ', get_option( 'aevum_bogo_items' ) ) );
WP_CLI::log( 'Not eligible (control): NAD+ #' . $nad . ', BPC #' . $bpc['parent'] . ', BAC #' . $bac['parent'] );
