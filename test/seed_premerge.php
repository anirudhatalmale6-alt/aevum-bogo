<?php
/**
 * Puts the local test store into the SAME shape aevumbio.shop is in today,
 * so the migration can be rehearsed before it is run for real.
 * Run with: wp eval-file seed_premerge.php
 */

wp_delete_post( 0, true ); // no-op, keeps linters quiet about unused includes

foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'any' ) ) as $p ) {
	$p->delete( true );
}

function pre_simple( $name, $slug, $price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_stock_status( 'instock' );
	$id = $product->save();
	WP_CLI::log( sprintf( 'simple  %-28s #%-5d $%s   /product/%s/', $name, $id, $price, $slug ) );
	return $id;
}

// Exactly the live line-up, all simple except Retatrutide.
$ids = array();
$ids['ghk50']  = pre_simple( 'GHK-CU 50mg', 'ghk-cu-50mg', 55 );
$ids['ghk100'] = pre_simple( 'GHK-CU 100mg', 'ghk-cu-100mg', 70 );
$ids['tesa']   = pre_simple( 'Tesamorelin 10mg', 'tesamorelin-10mg', 80 );
$ids['bac']    = pre_simple( 'Bacteriostatic Water 3ML', 'bacteriostatic-water-3ml', 7 );
$ids['bpc']    = pre_simple( 'BPC-157/ TB-500 10mg', 'bpc-157-tb-500-10mg', 75 );
$ids['nad']    = pre_simple( 'NAD+ 500mg', 'nad-500mg', 70 );
$ids['klow']   = pre_simple( 'KLOW 80mg', 'klow-80mg', 120 );

// Retatrutide is already variable on a global Size attribute, as on the live store.
if ( ! wc_attribute_taxonomy_id_by_name( 'size' ) ) {
	wc_create_attribute( array( 'name' => 'Size', 'slug' => 'size', 'type' => 'select', 'order_by' => 'menu_order' ) );
	delete_transient( 'wc_attribute_taxonomies' );
	WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
}
if ( ! taxonomy_exists( 'pa_size' ) ) {
	register_taxonomy( 'pa_size', 'product', array( 'hierarchical' => false, 'public' => false ) );
}
foreach ( array( '10mg', '20mg' ) as $term ) {
	if ( ! term_exists( $term, 'pa_size' ) ) {
		wp_insert_term( $term, 'pa_size' );
	}
}

$reta = new WC_Product_Variable();
$reta->set_name( 'Retatrutide' );
$reta->set_slug( 'retatrutide' );
$reta->set_status( 'publish' );
$attr = new WC_Product_Attribute();
$attr->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
$attr->set_name( 'pa_size' );
$attr->set_options( array( '10mg', '20mg' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$reta->set_attributes( array( $attr ) );
$reta_id = $reta->save();
wp_set_object_terms( $reta_id, array( '10mg', '20mg' ), 'pa_size' );

foreach ( array( '10mg' => 90, '20mg' => 165 ) as $size => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $reta_id );
	$v->set_attributes( array( 'pa_size' => sanitize_title( $size ) ) );
	$v->set_regular_price( (string) $price );
	$v->set_status( 'publish' );
	$v->set_stock_status( 'instock' );
	$vid = $v->save();
	WP_CLI::log( sprintf( '  variation Retatrutide %-14s #%-5d $%s', $size, $vid, $price ) );
}
WC_Product_Variable::sync( $reta_id );
WP_CLI::log( sprintf( 'variable %-28s #%d  /product/retatrutide/', 'Retatrutide', $reta_id ) );

// Clear any offer selection so the slug-based defaults are what gets exercised.
delete_option( 'aevum_bogo_items' );

WP_CLI::success( 'Store is now in its pre-merge state.' );
