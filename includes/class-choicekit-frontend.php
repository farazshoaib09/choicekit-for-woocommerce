<?php
/**
 * Frontend product builder.
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ChoiceKit_Frontend {
	/**
	 * Singleton instance.
	 *
	 * @var ChoiceKit_Frontend|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return ChoiceKit_Frontend
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_builder' ) );
		add_filter( 'woocommerce_get_price_html', array( $this, 'kit_price_html' ), 10, 2 );
		add_filter( 'woocommerce_sale_flash', array( $this, 'kit_sale_flash' ), 10, 3 );
	}

	/** A kit container has no charge of its own; selections determine the price. */
	public function kit_price_html( $price_html, $product ) {
		if ( $product instanceof WC_Product && ChoiceKit_Config::is_enabled( $product->get_id() ) ) {
			return '<span class="choicekit-selection-price-note">' . esc_html__( 'Price calculated from selected kit items', 'choicekit-for-woocommerce' ) . '</span>';
		}
		return $price_html;
	}

	/** Do not advertise a catalog sale price that is not charged for the kit container. */
	public function kit_sale_flash( $html, $post, $product ) {
		return $product instanceof WC_Product && ChoiceKit_Config::is_enabled( $product->get_id() ) ? '' : $html;
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_product() && ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'choicekit-frontend', CHOICEKIT_URL . 'assets/css/frontend.css', array(), CHOICEKIT_VERSION );

		if ( ! is_product() ) {
			return;
		}

		$product = $this->get_current_product();

		if ( ! $product || ! ChoiceKit_Config::is_enabled( $product->get_id() ) ) {
			return;
		}

		wp_enqueue_script( 'choicekit-frontend', CHOICEKIT_URL . 'assets/js/frontend.js', array( 'jquery' ), CHOICEKIT_VERSION, true );

		wp_localize_script(
			'choicekit-frontend',
			'ChoiceKitData',
			array(
				'currency' => array(
					'format_num_decimals' => wc_get_price_decimals(),
					'format_symbol'       => get_woocommerce_currency_symbol(),
					'format_decimal_sep'  => wc_get_price_decimal_separator(),
					'format_thousand_sep' => wc_get_price_thousand_separator(),
					'format'              => get_woocommerce_price_format(),
				),
				'i18n'     => array(
					'choose_required' => __( 'Please complete the required kit choices before adding to cart.', 'choicekit-for-woocommerce' ),
					'selected'        => __( 'selected', 'choicekit-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Render builder UI inside the product form.
	 *
	 * @return void
	 */
	public function render_builder() {
		$product = $this->get_current_product();

		if ( ! $product || ! ChoiceKit_Config::is_enabled( $product->get_id() ) ) {
			return;
		}

		$config = ChoiceKit_Config::get_config( $product->get_id() );

		if ( empty( $config['groups'] ) ) {
			return;
		}

		$base_price = 0;
		?>
		<div class="choicekit-builder" data-base-price="<?php echo esc_attr( $base_price ); ?>" data-discount-type="<?php echo esc_attr( $config['discount_type'] ); ?>" data-discount-amount="<?php echo esc_attr( $config['discount_amount'] ); ?>">
			<h3 class="choicekit-title"><?php esc_html_e( 'Build Your Kit', 'choicekit-for-woocommerce' ); ?></h3>
			<input type="hidden" name="choicekit_payload" class="choicekit-payload" value="">
			<?php wp_nonce_field( 'choicekit_add_to_cart', 'choicekit_cart_nonce' ); ?>

			<?php foreach ( $config['groups'] as $group ) : ?>
				<div class="choicekit-group" data-group-id="<?php echo esc_attr( $group['id'] ); ?>" data-min="<?php echo esc_attr( $group['min'] ); ?>" data-max="<?php echo esc_attr( $group['max'] ); ?>" data-discount-type="<?php echo esc_attr( $group['discount_type'] ); ?>" data-discount-amount="<?php echo esc_attr( $group['discount_amount'] ); ?>" data-discount-min-items="<?php echo esc_attr( $group['discount_min_items'] ); ?>">
					<div class="choicekit-group-heading">
						<div>
							<h4><?php echo esc_html( $group['title'] ); ?></h4>
							<?php if ( $group['description'] ) : ?>
								<p><?php echo esc_html( $group['description'] ); ?></p>
							<?php endif; ?>
							<?php $rule_label = ChoiceKit_Config::get_group_discount_label( $group ); ?>
							<?php if ( $rule_label ) : ?>
								<p class="choicekit-discount-rule"><?php echo esc_html( $rule_label ); ?></p>
							<?php endif; ?>
						</div>
						<span class="choicekit-count"><?php echo $group['max'] ? '0 / ' . esc_html( $group['max'] ) : esc_html__( '0 selected', 'choicekit-for-woocommerce' ); ?></span>
					</div>

					<div class="choicekit-options">
						<?php foreach ( $group['product_ids'] as $option_id ) : ?>
							<?php $this->render_option( $option_id, $group ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="choicekit-summary" aria-live="polite">
				<span><?php esc_html_e( 'Kit total', 'choicekit-for-woocommerce' ); ?></span>
				<strong class="choicekit-total"><?php echo wp_kses_post( wc_price( $base_price ) ); ?></strong>
			</div>
			<div class="choicekit-error" role="alert"></div>
		</div>
		<?php
	}

	/**
	 * Render one selectable kit option.
	 *
	 * @param int   $option_id Product or variation ID.
	 * @param array $group     Group data.
	 * @return void
	 */
	private function render_option( $option_id, $group ) {
		if ( ! ChoiceKit_Config::is_selectable_product( $option_id ) ) {
			return;
		}
		$option = wc_get_product( $option_id );

		$raw_price   = (float) $option->get_price();
		$price       = (float) wc_get_price_to_display( $option );
		$display_ratio = $raw_price > 0 ? $price / $raw_price : 1;
		$image_id    = $option->get_image_id();
		$image       = $image_id ? wp_get_attachment_image( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img( 'woocommerce_thumbnail' );
		$input_type  = 1 === (int) $group['max'] && (int) $group['min'] > 0 ? 'radio' : 'checkbox';
		$input_name  = 'choicekit_group_' . $group['id'];
		$parent_name = '';

		if ( $option->is_type( 'variation' ) ) {
			$parent = wc_get_product( $option->get_parent_id() );
			if ( $parent ) {
				$parent_name = $parent->get_name();
			}
		}
		?>
		<label class="choicekit-option" data-option-id="<?php echo esc_attr( $option_id ); ?>" data-price="<?php echo esc_attr( $raw_price ); ?>" data-display-ratio="<?php echo esc_attr( $display_ratio ); ?>">
			<input type="<?php echo esc_attr( $input_type ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $option_id ); ?>">
			<span class="choicekit-option-image"><?php echo wp_kses_post( $image ); ?></span>
			<span class="choicekit-option-body">
				<span class="choicekit-option-name">
					<?php
					if ( $parent_name ) {
						echo esc_html( $parent_name . ' - ' . wc_get_formatted_variation( $option, true, false, true ) );
					} else {
						echo esc_html( $option->get_name() );
					}
					?>
				</span>
				<span class="choicekit-option-price"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
			</span>
		</label>
		<?php
	}

	/**
	 * Resolve the current product without trusting the global product variable.
	 *
	 * @return WC_Product|false
	 */
	private function get_current_product() {
		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return false;
		}

		$resolved = wc_get_product( $product_id );

		return $resolved instanceof WC_Product ? $resolved : false;
	}
}
