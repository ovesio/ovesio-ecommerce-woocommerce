<?php
/**
 * Plugin Name: Ovesio - Ecommerce for WooCommerce
 * Plugin URI:  https://github.com/ovesio/ovesio-ecommerce-for-woocommerce
 * Description: Empowers your store with advanced AI-driven insights, stock management forecasting, and strategic consulting.
 * Version:     1.1.3
 * Author:      Ovesio
 * Author URI:  https://ovesio.com
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: ovesio-ecommerce-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 10.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WC_OVESIO_VERSION' ) ) {
	define( 'WC_OVESIO_VERSION', '1.1.3' );
}

if ( ! defined( 'WC_OVESIO_PLUGIN_DIR' ) ) {
	define( 'WC_OVESIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WC_OVESIO_PLUGIN_URL' ) ) {
	define( 'WC_OVESIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Main Class
 */
if ( ! class_exists( 'WC_Ovesio_Ecommerce' ) ) {
	include_once WC_OVESIO_PLUGIN_DIR . 'includes/class-wc-ovesio-ecommerce.php';
}

/**
 * Activation Hook
 */
register_activation_hook( __FILE__, array( 'WC_Ovesio_Ecommerce', 'activate' ) );

/**
 * Deactivation Hook
 */
register_deactivation_hook( __FILE__, array( 'WC_Ovesio_Ecommerce', 'deactivate' ) );

/**
 * HPOS Compatibility
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Initialize
 */
function wc_ovesio_init() {
    if ( class_exists( 'WC_Ovesio_Ecommerce' ) ) {
        WC_Ovesio_Ecommerce::instance();
    }
}
add_action( 'plugins_loaded', 'wc_ovesio_init' );
