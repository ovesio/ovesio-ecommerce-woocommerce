<?php
/**
 * Plugin Name: Ovesio - Ecommerce Intelligence for WooCommerce
 * Plugin URI:  https://github.com/ovesio/ecommerce-intelligence-woocommerce-plugin
 * Description: Empowers your store with advanced AI-driven insights, stock management forecasting, and strategic consulting.
 * Version:     1.1.4
 * Author:      Ovesio
 * Author URI:  https://ovesio.com
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: ovesio-ecommerce-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 10.4.3
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'OVESIO_ECOMMERCE_VERSION' ) ) {
	define( 'OVESIO_ECOMMERCE_VERSION', '1.1.4' );
}

if ( ! defined( 'OVESIO_ECOMMERCE_PLUGIN_DIR' ) ) {
	define( 'OVESIO_ECOMMERCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'OVESIO_ECOMMERCE_PLUGIN_URL' ) ) {
	define( 'OVESIO_ECOMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Main Class
 */
if ( ! class_exists( 'Ovesio_Ecommerce' ) ) {
	include_once OVESIO_ECOMMERCE_PLUGIN_DIR . 'includes/class-ovesio-ecommerce.php';
}

/**
 * Activation Hook
 */
register_activation_hook( __FILE__, 'ovesio_ecommerce_activate' );

/**
 * Activation Callback
 */
function ovesio_ecommerce_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'This plugin requires WooCommerce to be installed and active.', 'ovesio-ecommerce-for-woocommerce' ) );
    }
    Ovesio_Ecommerce::activate();
}

/**
 * Deactivation Hook
 */
register_deactivation_hook( __FILE__, array( 'Ovesio_Ecommerce', 'deactivate' ) );

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
function ovesio_ecommerce_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ovesio_ecommerce_missing_wc_notice' );
		return;
	}

    if ( class_exists( 'Ovesio_Ecommerce' ) ) {
        Ovesio_Ecommerce::instance();
    }
}
add_action( 'plugins_loaded', 'ovesio_ecommerce_init' );

/**
 * Missing WooCommerce Notice
 */
function ovesio_ecommerce_missing_wc_notice() {
	?>
	<div class="error">
		<p><?php echo esc_html__( 'Ovesio Ecommerce Intelligence requires WooCommerce to be installed and active.', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
	</div>
	<?php
}
