<?php
/**
 * Admin product editor integration.
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ChoiceKit_Admin {
	/**
	 * Singleton instance.
	 *
	 * @var ChoiceKit_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return ChoiceKit_Admin
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
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add ChoiceKit product data tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['choicekit'] = array(
			'label'    => __( 'ChoiceKit', 'choicekit-for-woocommerce' ),
			'target'   => 'choicekit_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Enqueue admin assets on product edit screens.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'choicekit-admin', CHOICEKIT_URL . 'assets/css/admin.css', array(), CHOICEKIT_VERSION );
		wp_enqueue_script( 'choicekit-admin', CHOICEKIT_URL . 'assets/js/admin.js', array( 'jquery', 'wp-util', 'selectWoo', 'wc-enhanced-select' ), CHOICEKIT_VERSION, true );
	}

	/**
	 * Render the product data panel.
	 *
	 * @return void
	 */
	public function render_product_data_panel() {
		global $post;

		$product_id = $post ? $post->ID : 0;
		$config     = ChoiceKit_Config::get_config( $product_id );
		$enabled    = ChoiceKit_Config::is_enabled( $product_id );
		$warnings   = $enabled ? ChoiceKit_Config::get_setup_warnings( $config ) : array();
		?>
		<div id="choicekit_product_data" class="panel woocommerce_options_panel hidden">
			<div class="choicekit-admin" data-next-index="<?php echo esc_attr( count( $config['groups'] ) ); ?>">
				<div class="choicekit-hero">
					<div>
						<span class="choicekit-eyebrow"><?php esc_html_e( 'ChoiceKit Builder', 'choicekit-for-woocommerce' ); ?></span>
						<h3><?php esc_html_e( 'Create a guided product kit', 'choicekit-for-woocommerce' ); ?></h3>
						<p><?php esc_html_e( 'Build customer-selectable kits using real WooCommerce products and variations.', 'choicekit-for-woocommerce' ); ?></p>
					</div>
					<label class="choicekit-switch">
						<input type="checkbox" id="<?php echo esc_attr( ChoiceKit_Config::ENABLED_META ); ?>" name="<?php echo esc_attr( ChoiceKit_Config::ENABLED_META ); ?>" value="yes" <?php checked( $enabled ); ?>>
						<span class="choicekit-switch-track" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Enable kit', 'choicekit-for-woocommerce' ); ?></span>
					</label>
				</div>
				<?php if ( $warnings ) : ?>
					<div class="notice notice-warning inline choicekit-setup-warnings" role="alert">
						<p><strong><?php esc_html_e( 'Kit setup needs attention', 'choicekit-for-woocommerce' ); ?></strong></p>
						<ul><?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul>
					</div>
				<?php endif; ?>

				<div class="choicekit-toolbar">
					<div>
						<h3><?php esc_html_e( 'Kit Groups', 'choicekit-for-woocommerce' ); ?></h3>
						<p><?php esc_html_e( 'Groups become steps or sections on the product page.', 'choicekit-for-woocommerce' ); ?></p>
					</div>
					<button type="button" class="button button-primary choicekit-add-group"><?php esc_html_e( 'Add Group', 'choicekit-for-woocommerce' ); ?></button>
				</div>

				<div class="choicekit-groups">
					<?php foreach ( $config['groups'] as $index => $group ) : ?>
						<?php $this->render_group_row( $index, $group ); ?>
					<?php endforeach; ?>
				</div>

				<div class="choicekit-empty">
					<h4><?php esc_html_e( 'No kit groups yet', 'choicekit-for-woocommerce' ); ?></h4>
					<p><?php esc_html_e( 'Add a group to define what customers can choose in this kit.', 'choicekit-for-woocommerce' ); ?></p>
					<button type="button" class="button button-primary choicekit-add-group"><?php esc_html_e( 'Create First Group', 'choicekit-for-woocommerce' ); ?></button>
				</div>

				<div class="choicekit-card choicekit-discount">
					<div class="choicekit-card-header">
						<div>
							<h3><?php esc_html_e( 'Kit Discount', 'choicekit-for-woocommerce' ); ?></h3>
							<p><?php esc_html_e( 'Apply an optional discount across selected kit components.', 'choicekit-for-woocommerce' ); ?></p>
						</div>
					</div>
					<div class="choicekit-field-grid choicekit-discount-grid">
						<div class="choicekit-field">
							<span><?php esc_html_e( 'Discount type', 'choicekit-for-woocommerce' ); ?></span>
							<div class="choicekit-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Discount type', 'choicekit-for-woocommerce' ); ?>">
								<label>
									<input type="radio" name="choicekit_discount_type" value="none" <?php checked( $config['discount_type'], 'none' ); ?>>
									<span><?php esc_html_e( 'None', 'choicekit-for-woocommerce' ); ?></span>
								</label>
								<label>
									<input type="radio" name="choicekit_discount_type" value="fixed" <?php checked( $config['discount_type'], 'fixed' ); ?>>
									<span><?php esc_html_e( 'Fixed', 'choicekit-for-woocommerce' ); ?></span>
								</label>
								<label>
									<input type="radio" name="choicekit_discount_type" value="percent" <?php checked( $config['discount_type'], 'percent' ); ?>>
									<span><?php esc_html_e( 'Percent', 'choicekit-for-woocommerce' ); ?></span>
								</label>
							</div>
						</div>
						<label class="choicekit-field">
							<span><?php esc_html_e( 'Discount amount', 'choicekit-for-woocommerce' ); ?></span>
							<input type="number" min="0" step="0.01" id="choicekit_discount_amount" name="choicekit_discount_amount" value="<?php echo esc_attr( $config['discount_amount'] ); ?>">
						</label>
					</div>
				</div>
			</div>

			<div class="choicekit-card">
				<h3><?php esc_html_e( 'Product coupons', 'choicekit-for-woocommerce' ); ?></h3>
				<label>
					<input type="checkbox" name="choicekit_coupon_targets_kit" value="yes" <?php checked( $config['coupon_targets_kit'], 'yes' ); ?>>
					<?php esc_html_e( 'Allow product coupons targeting this kit to discount its selected products', 'choicekit-for-woocommerce' ); ?>
				</label>
				<p><?php esc_html_e( 'A fixed product coupon is shared across the selected products once per kit. Coupons targeting selected products directly continue to work normally.', 'choicekit-for-woocommerce' ); ?></p>
			</div>

			<?php wp_nonce_field( 'choicekit_save_product', 'choicekit_nonce' ); ?>
		</div>

		<script type="text/html" id="tmpl-choicekit-group-row">
			<?php
			$this->render_group_row(
				'{{data.index}}',
				array(
					'id'              => '',
					'title'           => '',
					'description'     => '',
					'min'             => 0,
					'max'             => 1,
					'product_ids'     => array(),
					'discount_type'   => 'none',
					'discount_amount' => 0,
					'discount_min_items' => 1,
				)
			);
			?>
		</script>
		<?php
	}

	/**
	 * Render one group row.
	 *
	 * @param int|string $index Group index.
	 * @param array      $group Group data.
	 * @return void
	 */
	private function render_group_row( $index, $group ) {
		$field_prefix = 'choicekit_groups[' . $index . ']';
		?>
		<div class="choicekit-card choicekit-group">
			<div class="choicekit-card-header">
				<div>
					<span class="choicekit-card-kicker"><?php esc_html_e( 'Customer choice group', 'choicekit-for-woocommerce' ); ?></span>
					<h3><?php echo esc_html( $group['title'] ? $group['title'] : __( 'New group', 'choicekit-for-woocommerce' ) ); ?></h3>
				</div>
				<button type="button" class="button choicekit-remove-group"><?php esc_html_e( 'Remove', 'choicekit-for-woocommerce' ); ?></button>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>[id]" value="<?php echo esc_attr( $group['id'] ); ?>">
			<div class="choicekit-field-grid">
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Group title', 'choicekit-for-woocommerce' ); ?></span>
					<input type="text" class="choicekit-group-title-input" name="<?php echo esc_attr( $field_prefix ); ?>[title]" value="<?php echo esc_attr( $group['title'] ); ?>" placeholder="<?php esc_attr_e( 'Choose your items', 'choicekit-for-woocommerce' ); ?>">
				</label>
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Customer instruction', 'choicekit-for-woocommerce' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $field_prefix ); ?>[description]" value="<?php echo esc_attr( $group['description'] ); ?>" placeholder="<?php esc_attr_e( 'Example: Pick any 3 products for your box', 'choicekit-for-woocommerce' ); ?>">
					<small><?php esc_html_e( 'Informational text only. The discount fields below control the actual price.', 'choicekit-for-woocommerce' ); ?></small>
				</label>
			</div>
			<div class="choicekit-rule-grid">
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Minimum choices', 'choicekit-for-woocommerce' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( $field_prefix ); ?>[min]" value="<?php echo esc_attr( $group['min'] ); ?>">
				</label>
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Maximum choices', 'choicekit-for-woocommerce' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( $field_prefix ); ?>[max]" value="<?php echo esc_attr( $group['max'] ); ?>">
					<small><?php esc_html_e( 'Use 0 for no limit.', 'choicekit-for-woocommerce' ); ?></small>
				</label>
			</div>
			<div class="choicekit-field-grid choicekit-group-discount-grid">
				<div class="choicekit-field">
					<span><?php esc_html_e( 'Group discount', 'choicekit-for-woocommerce' ); ?></span>
					<div class="choicekit-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Group discount type', 'choicekit-for-woocommerce' ); ?>">
						<label>
							<input type="radio" name="<?php echo esc_attr( $field_prefix ); ?>[discount_type]" value="none" <?php checked( $group['discount_type'], 'none' ); ?>>
							<span><?php esc_html_e( 'None', 'choicekit-for-woocommerce' ); ?></span>
						</label>
						<label>
							<input type="radio" name="<?php echo esc_attr( $field_prefix ); ?>[discount_type]" value="fixed" <?php checked( $group['discount_type'], 'fixed' ); ?>>
							<span><?php esc_html_e( 'Fixed', 'choicekit-for-woocommerce' ); ?></span>
						</label>
						<label>
							<input type="radio" name="<?php echo esc_attr( $field_prefix ); ?>[discount_type]" value="percent" <?php checked( $group['discount_type'], 'percent' ); ?>>
							<span><?php esc_html_e( 'Percent', 'choicekit-for-woocommerce' ); ?></span>
						</label>
					</div>
				</div>
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Group discount amount', 'choicekit-for-woocommerce' ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $field_prefix ); ?>[discount_amount]" value="<?php echo esc_attr( $group['discount_amount'] ); ?>">
				</label>
				<label class="choicekit-field">
					<span><?php esc_html_e( 'Discount starts at', 'choicekit-for-woocommerce' ); ?></span>
					<input type="number" min="1" step="1" name="<?php echo esc_attr( $field_prefix ); ?>[discount_min_items]" value="<?php echo esc_attr( isset( $group['discount_min_items'] ) ? $group['discount_min_items'] : max( 1, $group['min'] ) ); ?>">
					<small><?php esc_html_e( 'Number of selected items in this group.', 'choicekit-for-woocommerce' ); ?></small>
				</label>
			</div>
			<div class="choicekit-field choicekit-products-field">
				<label for="choicekit_group_products_<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Selectable products and variations', 'choicekit-for-woocommerce' ); ?></label>
				<select id="choicekit_group_products_<?php echo esc_attr( $index ); ?>" class="wc-product-search" multiple="multiple" name="<?php echo esc_attr( $field_prefix ); ?>[product_ids][]" data-placeholder="<?php esc_attr_e( 'Search products and variations', 'choicekit-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations">
					<?php
					foreach ( $group['product_ids'] as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( ! $product ) {
							continue;
						}

						echo '<option value="' . esc_attr( $product_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
					}
					?>
						</select>
			</div>
		</div>
		<?php
	}

	/**
	 * Save product-level kit configuration.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public function save_product_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['choicekit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['choicekit_nonce'] ) ), 'choicekit_save_product' ) ) {
			return;
		}

		$enabled = isset( $_POST[ ChoiceKit_Config::ENABLED_META ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, ChoiceKit_Config::ENABLED_META, $enabled );

		$groups = isset( $_POST['choicekit_groups'] ) && is_array( $_POST['choicekit_groups'] ) ? map_deep( wp_unslash( $_POST['choicekit_groups'] ), 'sanitize_text_field' ) : array();

		$config = array(
			'discount_type'   => isset( $_POST['choicekit_discount_type'] ) ? sanitize_key( wp_unslash( $_POST['choicekit_discount_type'] ) ) : 'none',
			'discount_amount' => isset( $_POST['choicekit_discount_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['choicekit_discount_amount'] ) ) : 0,
			'coupon_targets_kit' => isset( $_POST['choicekit_coupon_targets_kit'] ) ? 'yes' : 'no',
			'groups'          => $groups,
		);

		$config = ChoiceKit_Config::sanitize_config( $config );
		update_post_meta( $post_id, ChoiceKit_Config::CONFIG_META, wp_json_encode( $config ) );
	}
}
