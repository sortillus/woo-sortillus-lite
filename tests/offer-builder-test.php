<?php

define( 'ABSPATH', __DIR__ );

$test_product = null;

function absint( $value ) { return abs( (int) $value ); }
function wc_get_product( $product_id ) { global $test_product; return $test_product; }
function get_post_status( $product_id ) { return 'publish'; }
function get_permalink( $product_id ) { return 'https://shop.example/product/' . (int) $product_id; }
function get_woocommerce_currency() { return 'EUR'; }
function wp_get_attachment_image_url( $id, $size ) { return $id ? 'https://shop.example/image-' . $id . '.jpg' : false; }
function get_post_modified_time() { return '2026-08-01T10:00:00+00:00'; }
function get_option( $key, $default = '' ) { return $default; }
function get_the_terms() { return array(); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_post_meta() { return ''; }
function wc_get_product_terms() { return array(); }
function wc_attribute_label( $name ) { return $name; }

final class Fake_Woo_Product {
	public $type = 'simple';
	public $price = '19.99';
	public $regular_price = '24.99';
	public $stock_status = 'instock';
	public $in_stock = true;
	public $backorders = 'no';

	public function get_id() { return 42; }
	public function get_type() { return $this->type; }
	public function is_type( $type ) { return $this->type === $type; }
	public function get_name() { return 'Test product'; }
	public function get_description() { return '<p>Long description</p>'; }
	public function get_short_description() { return 'Short description'; }
	public function get_stock_status() { return $this->stock_status; }
	public function get_backorders() { return $this->backorders; }
	public function is_in_stock() { return $this->in_stock; }
	public function get_price() { return $this->price; }
	public function get_variation_price() { return '15.00'; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return 7; }
	public function is_virtual() { return false; }
	public function is_downloadable() { return false; }
	public function get_image_id() { return 9; }
	public function get_gallery_image_ids() { return array( 10 ); }
	public function get_sku() { return 'SKU-42'; }
	public function get_regular_price() { return $this->regular_price; }
	public function get_attribute( $name ) { return 'pa_brand' === $name ? 'Example Brand' : ''; }
	public function get_attributes() { return array(); }
	public function get_weight() { return '1.5'; }
	public function get_length() { return '10'; }
	public function get_width() { return '8'; }
	public function get_height() { return '4'; }
}

require dirname( __DIR__ ) . '/includes/class-woo-sortillus-lite-offer-builder.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$builder      = new Woo_Sortillus_Lite_Offer_Builder();
$test_product = new Fake_Woo_Product();
$offer        = $builder->build( 42 );
assert_same( '42', $offer['external_offer_id'], 'Offer identity must use the WooCommerce product ID' );
assert_same( '19.99', $offer['price'], 'Simple product price must be preserved as a string' );
assert_same( true, $offer['available'], 'In-stock product must be available' );
assert_same( 2, $offer['availability'], 'In-stock availability code must be 2' );
assert_same( 'SKU-42', $offer['sku'], 'SKU must be mapped' );
assert_same( 'Example Brand', $offer['brand'], 'Brand must be mapped' );

$test_product->type         = 'variable';
$test_product->price        = '';
$test_product->stock_status = 'onbackorder';
$test_product->in_stock     = false;
$test_product->backorders   = 'yes';
$offer                      = $builder->build( 42 );
assert_same( '15.00', $offer['price'], 'Variable parent must use its minimum variation price' );
assert_same( true, $offer['available'], 'Backorder product must remain available' );
assert_same( 1, $offer['availability'], 'Backorder availability code must be 1' );
assert_same( null, $offer['available_items'], 'Backorders must not report zero available items' );

$test_product->type         = 'simple';
$test_product->price        = '19.99';
$test_product->stock_status = 'outofstock';
$test_product->in_stock     = false;
$test_product->backorders   = 'no';
$offer                      = $builder->build( 42 );
assert_same( false, $offer['available'], 'Out-of-stock product must be unavailable' );
assert_same( 0, $offer['availability'], 'Out-of-stock availability code must be 0' );

echo "Sortillus Lite offer builder test passed.\n";

