<?php

$root = dirname( __DIR__ );
$required = array(
	'woo-sortillus-lite.php',
	'includes/class-woo-sortillus-lite-settings.php',
	'includes/class-woo-sortillus-lite-client.php',
	'includes/class-woo-sortillus-lite-offer-builder.php',
	'includes/class-woo-sortillus-lite-sync.php',
	'includes/class-woo-sortillus-lite-assistant.php',
	'includes/class-woo-sortillus-lite-admin.php',
	'assets/admin.js',
	'assets/widget.js',
	'tests/offer-builder-test.php',
	'tests/sync-test.php',
);

foreach ( $required as $file ) {
	if ( ! is_file( $root . '/' . $file ) ) {
		fwrite( STDERR, "Missing required file: {$file}\n" );
		exit( 1 );
	}
}

$all_php = '';
foreach ( glob( $root . '/*.php' ) as $file ) {
	$all_php .= file_get_contents( $file );
}
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	$all_php .= file_get_contents( $file );
}

$required_markers = array(
	"'variant'          => 'lite'",
	"'offers:write'",
	"'integration:read'",
	"'integration:write'",
	"'chat:write'",
	'woocommerce_new_product',
	'woocommerce_update_product',
	'woocommerce_product_set_stock_status',
	'woo_sortillus_lite_import_batch',
	'sortillus-lite/v1',
);
foreach ( $required_markers as $marker ) {
	if ( false === strpos( $all_php, $marker ) ) {
		fwrite( STDERR, "Missing required behavior marker: {$marker}\n" );
		exit( 1 );
	}
}

$forbidden_markers = array(
	'woocommerce_order_status',
	'created_product_cat',
	'created_product_tag',
	'woo_sortillus_lite_search',
	'woo_sortillus_lite_recommend',
);
foreach ( $forbidden_markers as $marker ) {
	if ( false !== strpos( $all_php, $marker ) ) {
		fwrite( STDERR, "Unexpected full-plugin feature marker: {$marker}\n" );
		exit( 1 );
	}
}

echo "Sortillus Lite package test passed.\n";
