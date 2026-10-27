<?php
/**
 * Plugin Name: ChoiceKit for WooCommerce
 * Description: Create WooCommerce product kits, mix and match bundles, and custom boxes with flexible customer choices, live pricing, and stock-safe cart handling.
 * Version: 1.0.1
 * Author: Faraz Shoaib
 * Text Domain: choicekit-for-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.9.1
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CHOICEKIT_VERSION', '1.0.1' );
define( 'CHOICEKIT_FILE', __FILE__ );
define( 'CHOICEKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHOICEKIT_URL', plugin_dir_url( __FILE__ ) );

require_once CHOICEKIT_PATH . 'includes/class-choicekit-plugin.php';

add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CHOICEKIT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CHOICEKIT_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'ChoiceKit_Plugin', 'instance' ), 20 );
