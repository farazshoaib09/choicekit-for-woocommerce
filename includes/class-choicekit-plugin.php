<?php
/**
 * Main plugin bootstrap.
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class ChoiceKit_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var ChoiceKit_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return ChoiceKit_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_files();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		ChoiceKit_Admin::instance();
		ChoiceKit_Frontend::instance();
		ChoiceKit_Cart::instance();
	}

	/**
	 * Load plugin classes.
	 *
	 * @return void
	 */
	private function load_files() {
		require_once CHOICEKIT_PATH . 'includes/class-choicekit-config.php';
		require_once CHOICEKIT_PATH . 'includes/class-choicekit-admin.php';
		require_once CHOICEKIT_PATH . 'includes/class-choicekit-frontend.php';
		require_once CHOICEKIT_PATH . 'includes/class-choicekit-cart.php';
	}

	/**
	 * Admin notice when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'ChoiceKit for WooCommerce requires WooCommerce to be installed and active.', 'choicekit-for-woocommerce' );
		echo '</p></div>';
	}
}
