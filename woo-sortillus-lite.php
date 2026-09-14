<?php
/**
 * Plugin Name: Sortillus Lite for WooCommerce
 * Plugin URI: https://sortillus.com
 * Description: Connects WooCommerce to Sortillus, imports product offers, and adds the hosted shopping assistant.
 * Version: 1.1.0
 * Author: Sortillus
 * Author URI: https://sortillus.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: woo-sortillus-lite
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOO_SORTILLUS_LITE_VERSION', '1.1.0' );
define( 'WOO_SORTILLUS_LITE_FILE', __FILE__ );
define( 'WOO_SORTILLUS_LITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_SORTILLUS_LITE_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_SORTILLUS_LITE_API_ORIGIN', 'https://data.sortillus.com' );
define( 'WOO_SORTILLUS_LITE_WIDGET_URL', 'https://admin.sortillus.com/shop-assistant/v1/widget.js' );

require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-crypto.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-settings.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-client.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-offer-builder.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-sync.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-assistant.php';
require_once WOO_SORTILLUS_LITE_DIR . 'includes/class-woo-sortillus-lite-admin.php';

function woo_sortillus_lite_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Sortillus Lite requires WooCommerce to be installed and active.', 'woo-sortillus-lite' ) );
	}

	if ( ! get_option( Woo_Sortillus_Lite_Settings::OPTION_EXTERNAL_ID ) ) {
		add_option( Woo_Sortillus_Lite_Settings::OPTION_EXTERNAL_ID, wp_generate_uuid4(), '', false );
	}
	if ( get_option( Woo_Sortillus_Lite_Settings::OPTION_ASSISTANT_ENABLED, null ) === null ) {
		add_option( Woo_Sortillus_Lite_Settings::OPTION_ASSISTANT_ENABLED, '0', '', false );
	}
}
register_activation_hook( __FILE__, 'woo_sortillus_lite_activate' );

function woo_sortillus_lite_conflict_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__(
		'Sortillus Lite is paused because the full Woo Sortillus plugin is active. Deactivate one plugin to avoid duplicate product synchronization.',
		'woo-sortillus-lite'
	) . '</p></div>';
}

function woo_sortillus_lite_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__(
		'Sortillus Lite requires WooCommerce to be active.',
		'woo-sortillus-lite'
	) . '</p></div>';
}

function woo_sortillus_lite_bootstrap() {
	if ( defined( 'WOO_SORTILLUS_FILE' ) || function_exists( 'woo_sortillus_bootstrap' ) ) {
		add_action( 'admin_notices', 'woo_sortillus_lite_conflict_notice' );
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo_sortillus_lite_missing_woocommerce_notice' );
		return;
	}

	$settings  = new Woo_Sortillus_Lite_Settings();
	$client    = new Woo_Sortillus_Lite_Client( $settings );
	$builder   = new Woo_Sortillus_Lite_Offer_Builder();
	$sync      = new Woo_Sortillus_Lite_Sync( $settings, $client, $builder );
	$assistant = new Woo_Sortillus_Lite_Assistant( $settings, $client );
	$admin     = new Woo_Sortillus_Lite_Admin( $settings, $client, $sync );

	$sync->init();
	$assistant->init();
	$admin->init();
}
add_action( 'plugins_loaded', 'woo_sortillus_lite_bootstrap', 20 );

function woo_sortillus_lite_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=woo-sortillus-lite' ) ) . '">' .
		esc_html__( 'Settings', 'woo-sortillus-lite' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woo_sortillus_lite_action_links' );

