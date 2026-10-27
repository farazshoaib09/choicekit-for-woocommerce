<?php
/**
 * Cart, validation, and order integration.
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ChoiceKit_Cart {
	/**
	 * Singleton instance.
	 *
	 * @var ChoiceKit_Cart|null
	 */
	private static $instance = null;

	/** @var bool Prevent recursive quantity updates. */
	private $syncing_quantity = false;

	/** @var array Coupon code => cart item key => discount in price precision. */
	private $coupon_allocations = array();

	/**
	 * Get singleton instance.
	 *
	 * @return ChoiceKit_Cart
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
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'add_store_api_submission' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_parent_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_parent_cart_item_data' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'prepare_cart_item' ) );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'prepare_cart_item' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'add_component_items' ), 10, 6 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_class', array( $this, 'add_cart_item_class' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'filter_item_name' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'filter_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'filter_quantity_html' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'filter_item_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'filter_item_subtotal' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'price_kit_items' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'synchronize_quantity' ), 10, 4 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'verify_cart_integrity' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remove_linked_items' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'restore_linked_items' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'coupon_valid_for_component' ), 10, 4 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'allocate_kit_coupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'kit_coupon_discount_amount' ), 10, 5 );
		add_filter( 'woocommerce_coupon_get_apply_quantity', array( $this, 'kit_coupon_apply_quantity' ), 10, 4 );
		add_filter( 'woocommerce_order_item_product', array( $this, 'order_container_product' ), 10, 2 );
	}

	/** Copy a Store API extension payload into the cart request. */
	public function add_store_api_submission( $data, $request ) {
		$extensions = $request->get_param( 'extensions' );
		if ( is_array( $extensions ) && isset( $extensions['choicekit']['payload'] ) ) {
			$data['cart_item_data']['choicekit_submission'] = $extensions['choicekit']['payload'];
		}
		return $data;
	}

	/** The raw request is needed for validation, not for the stored cart item. */
	public function prepare_cart_item( $item ) {
		unset( $item['choicekit_submission'] );
		if ( ! empty( $item['choicekit_parent'] ) && ! empty( $item['data'] ) ) {
			$item['data']->set_virtual( true );
		}
		return $item;
	}

	/**
	 * Validate submitted kit selections.
	 *
	 * @param bool $passed     Current validation status.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_parent_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( ! $passed || ! ChoiceKit_Config::is_enabled( $product_id ) || ! empty( $cart_item_data['choicekit_component'] ) ) {
			return $passed;
		}

		$result = $this->parse_and_validate_submission( $product_id, $quantity, $cart_item_data );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Add parent cart item data.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_parent_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity = 1 ) {
		if ( ! ChoiceKit_Config::is_enabled( $product_id ) || ! empty( $cart_item_data['choicekit_component'] ) ) {
			return $cart_item_data;
		}

		$result = $this->parse_and_validate_submission( $product_id, $quantity, $cart_item_data );

		if ( is_wp_error( $result ) ) {
			$cart_item_data['choicekit_invalid'] = true;
			return $cart_item_data;
		}

		$bundle_id                              = wp_generate_uuid4();
		$cart_item_data['choicekit_parent']     = true;
		$cart_item_data['choicekit_bundle_id']  = $bundle_id;
		$cart_item_data['choicekit_instance']   = strtoupper( substr( str_replace( '-', '', $bundle_id ), 0, 6 ) );
		$cart_item_data['choicekit_selections'] = $result['selections'];
		$cart_item_data['choicekit_unique']     = md5( wp_json_encode( $result['selections'] ) . microtime( true ) );
		$config                                = ChoiceKit_Config::get_config( $product_id );
		$cart_item_data['choicekit_discount'] = array(
			'type'   => $config['discount_type'],
			'amount' => $config['discount_amount'],
		);
		$cart_item_data['choicekit_coupon_targets_kit'] = $config['coupon_targets_kit'];

		return $cart_item_data;
	}

	/**
	 * Add selected component products after parent product is added.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product ID.
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation ID.
	 * @param array  $variation     Variation data.
	 * @param array  $cart_item_data Cart item data.
	 * @return void
	 */
	public function add_component_items( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! empty( $cart_item_data['choicekit_invalid'] ) ) {
			WC()->cart->remove_cart_item( $cart_item_key );
			wc_add_notice( __( 'Please complete the kit selections before adding to cart.', 'choicekit-for-woocommerce' ), 'error' );
			return;
		}
		if ( empty( $cart_item_data['choicekit_parent'] ) || empty( $cart_item_data['choicekit_selections'] ) ) {
			return;
		}

		$parent      = wc_get_product( $product_id );
		$parent_name = $parent ? $parent->get_name() : __( 'Kit', 'choicekit-for-woocommerce' );

		foreach ( $cart_item_data['choicekit_selections'] as $selection ) {
			$component = wc_get_product( $selection['product_id'] );

			if ( ! $component ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				wc_add_notice( __( 'The kit could not be added because one of its items is unavailable.', 'choicekit-for-woocommerce' ), 'error' );
				return;
			}

			$component_product_id   = $component->is_type( 'variation' ) ? $component->get_parent_id() : $component->get_id();
			$component_variation_id = $component->is_type( 'variation' ) ? $component->get_id() : 0;
			$component_variation    = $component->is_type( 'variation' ) ? $component->get_variation_attributes() : array();

			$added = WC()->cart->add_to_cart(
				$component_product_id,
				max( 1, absint( $quantity ) ),
				$component_variation_id,
				$component_variation,
				array(
					'choicekit_component'             => true,
					'choicekit_base_price'            => (float) $component->get_price(),
					'choicekit_component_unique'      => wp_generate_uuid4(),
					'choicekit_parent_key'            => $cart_item_key,
					'choicekit_parent_name'           => $parent_name,
					'choicekit_bundle_id'             => $cart_item_data['choicekit_bundle_id'],
					'choicekit_instance'              => ! empty( $cart_item_data['choicekit_instance'] ) ? $cart_item_data['choicekit_instance'] : '',
					'choicekit_group_id'              => $selection['group_id'],
					'choicekit_group_title'           => $selection['group_title'],
					'choicekit_group_discount_type'   => $selection['group_discount_type'],
					'choicekit_group_discount_amount' => $selection['group_discount_amount'],
					'choicekit_group_discount_min_items' => isset( $selection['group_discount_min_items'] ) ? max( 1, absint( $selection['group_discount_min_items'] ) ) : 1,
				)
			);

			if ( ! $added ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				wc_add_notice( __( 'The kit could not be added because one of its items is unavailable.', 'choicekit-for-woocommerce' ), 'error' );
				return;
			}
		}
	}

	/**
	 * Display kit metadata in cart and checkout.
	 *
	 * @param array $item_data Cart item display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['choicekit_parent'] ) && ! empty( $cart_item['choicekit_selections'] ) ) {
			$item_data[] = array(
				'key'   => __( 'ChoiceKit', 'choicekit-for-woocommerce' ),
				'value' => sprintf(
					/* translators: %d: selected component count. */
					_n( '%d selected item', '%d selected items', count( $cart_item['choicekit_selections'] ), 'choicekit-for-woocommerce' ),
					count( $cart_item['choicekit_selections'] )
				),
			);

		}

		if ( ! empty( $cart_item['choicekit_component'] ) ) {
			if ( ! empty( $cart_item['choicekit_parent_name'] ) ) {
				$item_data[] = array(
					'key'   => __( 'Part of kit', 'choicekit-for-woocommerce' ),
					'value' => wc_clean( $cart_item['choicekit_parent_name'] ),
				);
			}

			$item_data[] = array(
				'key'   => __( 'Kit group', 'choicekit-for-woocommerce' ),
				'value' => ! empty( $cart_item['choicekit_group_title'] ) ? wc_clean( $cart_item['choicekit_group_title'] ) : __( 'Component', 'choicekit-for-woocommerce' ),
			);
		}

		return $item_data;
	}

	/**
	 * Add cart row classes for kit parent and component rows.
	 *
	 * @param string $class         Existing class list.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function add_cart_item_class( $class, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['choicekit_parent'] ) ) {
			$class .= ' choicekit-cart-parent';
		}

		if ( ! empty( $cart_item['choicekit_component'] ) ) {
			$class .= ' choicekit-cart-component';
		}

		return $class;
	}

	/**
	 * Make bundle parent and component names read as one kit block.
	 *
	 * @param string $name          Current item name HTML.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_item_name( $name, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['choicekit_parent'] ) && ! empty( $cart_item['choicekit_instance'] ) ) {
			return sprintf(
				'%1$s <span class="choicekit-instance-pill">%2$s</span>',
				$name,
				esc_html(
					sprintf(
						/* translators: %s: kit instance code. */
						__( 'Kit %s', 'choicekit-for-woocommerce' ),
						$cart_item['choicekit_instance']
					)
				)
			);
		}

		if ( ! empty( $cart_item['choicekit_component'] ) && ! empty( $cart_item['choicekit_instance'] ) ) {
			return sprintf(
				'<span class="choicekit-component-name">%1$s</span><span class="choicekit-component-context">%2$s</span>',
				$name,
				esc_html(
					sprintf(
						/* translators: %s: kit instance code. */
						__( 'Included in Kit %s', 'choicekit-for-woocommerce' ),
						$cart_item['choicekit_instance']
					)
				)
			);
		}

		return $name;
	}

	/**
	 * Hide component remove links so shoppers cannot break a kit.
	 *
	 * @param string $link          Remove link HTML.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_remove_link( $link, $cart_item_key ) {
		if ( ! WC()->cart ) {
			return $link;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );

		if ( ! empty( $cart_item['choicekit_component'] ) ) {
			return '<span class="choicekit-cart-lock" aria-label="' . esc_attr__( 'Included kit item', 'choicekit-for-woocommerce' ) . '">-</span>';
		}

		return $link;
	}

	/**
	 * Replace component quantity inputs with locked text.
	 *
	 * @param string $quantity_html Current quantity HTML.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $cart_item     Cart item.
	 * @return string
	 */
	public function filter_quantity_html( $quantity_html, $cart_item_key, $cart_item ) {
		if ( empty( $cart_item['choicekit_component'] ) ) {
			return $quantity_html;
		}

		return '<span class="choicekit-linked-qty">' . esc_html( max( 1, absint( $cart_item['quantity'] ) ) ) . ' ' . esc_html__( 'linked', 'choicekit-for-woocommerce' ) . '</span>';
	}

	/**
	 * Show parent kit as a container instead of a separately priced item.
	 *
	 * @param string $price_html    Current price HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['choicekit_parent'] ) ) {
			return '<span class="choicekit-container-price">' . esc_html__( 'Kit container', 'choicekit-for-woocommerce' ) . '</span>';
		}

		return $price_html;
	}

	/**
	 * Show parent kit subtotal as included below.
	 *
	 * @param string $subtotal_html Current subtotal HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_item_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['choicekit_parent'] ) ) {
			return '<span class="choicekit-container-price">' . esc_html__( 'Priced by selections', 'choicekit-for-woocommerce' ) . '</span>';
		}

		return $subtotal_html;
	}

	/** Price components after applying group and kit discounts to their product lines. */
	public function price_kit_items( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $parent_key => $parent ) {
			if ( empty( $parent['choicekit_parent'] ) || empty( $parent['data'] ) ) {
				continue;
			}

			$parent['data']->set_price( 0 );
			$parent['data']->set_virtual( true );
			$quantity = max( 1, absint( $parent['quantity'] ) );
			$groups   = array();
			$subtotal = 0;

			foreach ( $cart->get_cart() as $key => $item ) {
				if ( empty( $item['choicekit_component'] ) || $parent_key !== $item['choicekit_parent_key'] || empty( $item['data'] ) ) {
					continue;
				}

				$group_id = $item['choicekit_group_id'];
				if ( ! isset( $groups[ $group_id ] ) ) {
					$groups[ $group_id ] = array(
						'subtotal' => 0,
						'items'    => array(),
						'type'     => $item['choicekit_group_discount_type'],
						'amount'   => (float) $item['choicekit_group_discount_amount'],
						'min_items' => isset( $item['choicekit_group_discount_min_items'] ) ? max( 1, absint( $item['choicekit_group_discount_min_items'] ) ) : 1,
					);
				}

				$base = isset( $item['choicekit_base_price'] ) ? (float) $item['choicekit_base_price'] : (float) $item['data']->get_price();
				$groups[ $group_id ]['items'][ $key ] = $base;
				$groups[ $group_id ]['subtotal'] += $base * $quantity;
			}

			foreach ( $groups as $group ) {
				$discount = count( $group['items'] ) >= $group['min_items'] ? ChoiceKit_Config::calculate_discount(
					array(
						'discount_type'   => $group['type'],
						'discount_amount' => 'fixed' === $group['type'] ? $group['amount'] * $quantity : $group['amount'],
					),
					$group['subtotal']
				) : 0;
				$factor = $group['subtotal'] > 0 ? ( $group['subtotal'] - $discount ) / $group['subtotal'] : 1;
				foreach ( $group['items'] as $key => $base ) {
					$cart->cart_contents[ $key ]['data']->set_price( $base * $factor );
					$subtotal += $base * $factor * $quantity;
				}
			}

			if ( ! empty( $parent['choicekit_discount'] ) ) {
				$kit_discount = $parent['choicekit_discount'];
			} else {
				// Carts created before 0.1.1 did not snapshot this discount.
				$config = ChoiceKit_Config::get_config( $parent['product_id'] );
				$kit_discount = array( 'type' => $config['discount_type'], 'amount' => $config['discount_amount'] );
			}
			$discount     = ChoiceKit_Config::calculate_discount(
				array(
					'discount_type'   => $kit_discount['type'],
					'discount_amount' => 'fixed' === $kit_discount['type'] ? $kit_discount['amount'] * $quantity : $kit_discount['amount'],
				),
				$subtotal
			);
			$factor = $subtotal > 0 ? ( $subtotal - $discount ) / $subtotal : 1;
			foreach ( $groups as $group ) {
				foreach ( $group['items'] as $key => $base ) {
					$item = $cart->cart_contents[ $key ];
					$item['data']->set_price( (float) $item['data']->get_price() * $factor );
				}
			}
		}
	}

	/** Keep the quantity of every component equal to its parent. */
	public function synchronize_quantity( $key, $quantity, $old_quantity, $cart ) {
		if ( $this->syncing_quantity ) {
			return;
		}
		$this->syncing_quantity = true;
		try {
			$item = $cart->get_cart_item( $key );
			if ( ! empty( $item['choicekit_component'] ) ) {
				$parent = $cart->get_cart_item( $item['choicekit_parent_key'] );
				if ( $parent && $quantity !== $parent['quantity'] ) {
					$cart->set_quantity( $key, $parent['quantity'], false );
				}
				return;
			}

			if ( empty( $item['choicekit_parent'] ) ) {
				return;
			}

			if ( ! $this->has_component_stock( $cart, $key, $quantity ) ) {
				$cart->set_quantity( $key, $old_quantity, false );
				wc_add_notice( __( 'There is not enough stock for that kit quantity.', 'choicekit-for-woocommerce' ), 'error' );
				return;
			}

			foreach ( $cart->get_cart() as $component_key => $component ) {
				if ( ! empty( $component['choicekit_component'] ) && $key === $component['choicekit_parent_key'] && $component['quantity'] !== $quantity ) {
					$cart->set_quantity( $component_key, $quantity, false );
				}
			}
		} finally {
			$this->syncing_quantity = false;
		}
	}

	/** Remove kits with missing components after WooCommerce restores the cart session. */
	public function verify_cart_integrity( $cart ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['choicekit_parent'] ) ) {
				continue;
			}
			$actual = 0;
			foreach ( $cart->get_cart() as $component ) {
				if ( ! empty( $component['choicekit_component'] ) && $key === $component['choicekit_parent_key'] ) {
					++$actual;
				}
			}
			$expected = ! empty( $item['choicekit_selections'] ) ? count( $item['choicekit_selections'] ) : 0;
			if ( $actual !== $expected || 0 === $expected ) {
				$cart->remove_cart_item( $key );
			}
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['choicekit_component'] ) && ! $cart->get_cart_item( $item['choicekit_parent_key'] ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/** Remove an entire kit if any of its linked rows is removed. */
	public function remove_linked_items( $key, $cart ) {
		$removed = $cart->get_removed_cart_contents();
		$item    = isset( $removed[ $key ] ) ? $removed[ $key ] : array();
		if ( ! empty( $item['choicekit_component'] ) ) {
			$parent_key = $item['choicekit_parent_key'];
			if ( $cart->get_cart_item( $parent_key ) ) {
				$cart->remove_cart_item( $parent_key );
			}
			return;
		}

		if ( ! empty( $item['choicekit_parent'] ) ) {
			foreach ( $cart->get_cart() as $component_key => $component ) {
				if ( ! empty( $component['choicekit_component'] ) && $key === $component['choicekit_parent_key'] ) {
					$cart->remove_cart_item( $component_key );
				}
			}
		}
	}

	/** Restore component rows when WooCommerce's undo action restores a kit. */
	public function restore_linked_items( $key, $cart ) {
		$parent = $cart->get_cart_item( $key );
		if ( empty( $parent['choicekit_parent'] ) ) {
			return;
		}

		foreach ( $cart->get_removed_cart_contents() as $component_key => $component ) {
			if ( ! empty( $component['choicekit_component'] ) && $key === $component['choicekit_parent_key'] ) {
				$cart->restore_cart_item( $component_key );
			}
		}
	}

	/** Resolve a component's kit when a product coupon explicitly targets its parent. */
	private function get_coupon_parent( $coupon, $values ) {
		if ( ! is_array( $values ) || empty( $values['choicekit_component'] ) || empty( $values['choicekit_parent_key'] ) || ! WC()->cart ) {
			return false;
		}
		$parent = WC()->cart->get_cart_item( $values['choicekit_parent_key'] );
		if ( empty( $parent['choicekit_parent'] ) ) {
			return false;
		}
		$enabled = isset( $parent['choicekit_coupon_targets_kit'] ) ? $parent['choicekit_coupon_targets_kit'] : ChoiceKit_Config::get_config( $parent['product_id'] )['coupon_targets_kit'];
		if ( 'yes' !== $enabled || ! in_array( (int) $parent['product_id'], $coupon->get_product_ids(), true ) ) {
			return false;
		}
		return $parent;
	}

	/** Make explicitly kit-targeted product coupons eligible for its components. */
	public function coupon_valid_for_component( $valid, $product, $coupon, $values ) {
		if ( is_array( $values ) && ! empty( $values['choicekit_parent'] ) && $coupon->is_type( wc_get_product_coupon_types() ) ) {
			return false;
		}
		if ( $valid || ! $coupon->is_type( wc_get_product_coupon_types() ) || ! $product instanceof WC_Product ) {
			return $valid;
		}
		$parent = $this->get_coupon_parent( $coupon, $values );
		if ( ! $parent ) {
			return $valid;
		}
		$excluded = $coupon->get_excluded_product_ids();
		if ( in_array( (int) $parent['product_id'], $excluded, true ) || in_array( $product->get_id(), $excluded, true ) || in_array( $product->get_parent_id(), $excluded, true ) ) {
			return false;
		}
		$categories = array_merge( wc_get_product_cat_ids( $product->get_id() ), wc_get_product_cat_ids( $product->get_parent_id() ), wc_get_product_cat_ids( $parent['product_id'] ) );
		if ( array_intersect( $categories, $coupon->get_excluded_product_categories() ) ) {
			return false;
		}
		return ! $coupon->get_exclude_sale_items() || ! $product->is_on_sale();
	}

	/** Split a fixed-product coupon once per kit unit across eligible components. */
	public function allocate_kit_coupon( $items, $coupon, $discounts ) {
		if ( ! $coupon->is_type( 'fixed_product' ) ) {
			return $items;
		}
		$code = $coupon->get_code();
		$this->coupon_allocations[ $code ] = array();
		$usage_left = $coupon->get_limit_usage_to_x_items();
		$groups = array();
		foreach ( $items as $item ) {
			$parent = $this->get_coupon_parent( $coupon, $item->object );
			if ( ! $parent ) {
				continue;
			}
			$parent_key = $item->object['choicekit_parent_key'];
			if ( ! isset( $groups[ $parent_key ] ) ) {
				$groups[ $parent_key ] = array( 'quantity' => max( 1, absint( $parent['quantity'] ) ), 'items' => array() );
			}
			$groups[ $parent_key ]['items'][ $item->key ] = $discounts->get_discounted_price_in_cents( $item );
		}
		foreach ( $groups as $group ) {
			$subtotal = array_sum( $group['items'] );
			$eligible_units = null === $usage_left ? $group['quantity'] : min( $group['quantity'], max( 0, $usage_left ) );
			$remaining = min( $subtotal, (int) round( wc_add_number_precision( (float) $coupon->get_amount() ) * $eligible_units ) );
			if ( null !== $usage_left ) {
				$usage_left -= $eligible_units;
			}
			$keys = array_keys( $group['items'] );
			$last_key = end( $keys );
			foreach ( $group['items'] as $key => $price ) {
				$share = $key === $last_key ? $remaining : min( $remaining, (int) floor( $remaining * $price / max( 1, $subtotal ) ) );
				$this->coupon_allocations[ $code ][ $key ] = $share;
				$remaining -= $share;
				$subtotal -= $price;
			}
		}
		return $items;
	}

	/** The kit coupon limit counts kit units; component lines share that budget. */
	public function kit_coupon_apply_quantity( $quantity, $item, $coupon, $discounts ) {
		if ( $coupon->is_type( 'fixed_product' ) && isset( $this->coupon_allocations[ $coupon->get_code() ][ $item->key ] ) ) {
			return $item->quantity;
		}
		return $quantity;
	}

	/** Use the prepared kit share during WooCommerce's coupon calculation. */
	public function kit_coupon_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! $coupon->is_type( 'fixed_product' ) || ! is_array( $cart_item ) || empty( $cart_item['key'] ) ) {
			return $discount;
		}
		$code = $coupon->get_code();
		$key = $cart_item['key'];
		return isset( $this->coupon_allocations[ $code ][ $key ] ) ? wc_remove_number_precision( $this->coupon_allocations[ $code ][ $key ] ) : $discount;
	}

	/**
	 * Persist kit metadata to order items.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 * @return void
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['choicekit_bundle_id'] ) ) {
			$item->add_meta_data( '_choicekit_bundle_id', wc_clean( $values['choicekit_bundle_id'] ), true );
		}

		if ( ! empty( $values['choicekit_instance'] ) ) {
			$item->add_meta_data( __( 'Kit instance', 'choicekit-for-woocommerce' ), wc_clean( $values['choicekit_instance'] ), true );
		}

		if ( ! empty( $values['choicekit_parent'] ) ) {
			$item->add_meta_data( '_choicekit_parent', 'yes', true );
			$item->add_meta_data( __( 'ChoiceKit parent', 'choicekit-for-woocommerce' ), __( 'Yes', 'choicekit-for-woocommerce' ), true );
		}

		if ( ! empty( $values['choicekit_component'] ) ) {
			$item->add_meta_data( __( 'ChoiceKit component', 'choicekit-for-woocommerce' ), __( 'Yes', 'choicekit-for-woocommerce' ), true );
			if ( ! empty( $values['choicekit_parent_name'] ) ) {
				$item->add_meta_data( __( 'Part of kit', 'choicekit-for-woocommerce' ), wc_clean( $values['choicekit_parent_name'] ), true );
			}
			if ( ! empty( $values['choicekit_group_title'] ) ) {
				$item->add_meta_data( __( 'Kit group', 'choicekit-for-woocommerce' ), wc_clean( $values['choicekit_group_title'] ), true );
			}
		}
	}

	/** Keep kit containers virtual when a saved order reloads catalog products. */
	public function order_container_product( $product, $item ) {
		if ( $product instanceof WC_Product && $item instanceof WC_Order_Item_Product && 'yes' === $item->get_meta( '_choicekit_parent', true ) ) {
			$product = clone $product;
			$product->set_virtual( true );
		}
		return $product;
	}

	/**
	 * Parse and validate add-to-cart submission.
	 *
	 * @param int $product_id Parent product ID.
	 * @param int $quantity   Parent quantity.
	 * @return array|WP_Error
	 */
	private function parse_and_validate_submission( $product_id, $quantity, $cart_item_data = array() ) {
		if ( isset( $cart_item_data['choicekit_submission'] ) ) {
			// The Store API authenticates its own request; its extension contains the kit payload.
			$payload = $cart_item_data['choicekit_submission'];
			$payload = is_string( $payload ) ? json_decode( $payload, true ) : $payload;
		} else {
			if ( empty( $_POST['choicekit_cart_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['choicekit_cart_nonce'] ) ), 'choicekit_add_to_cart' ) ) {
				return new WP_Error( 'choicekit_nonce', __( 'ChoiceKit validation failed. Please refresh the page and try again.', 'choicekit-for-woocommerce' ) );
			}
			$payload_raw = isset( $_POST['choicekit_payload'] ) ? sanitize_text_field( wp_unslash( $_POST['choicekit_payload'] ) ) : '';
			$payload     = '' !== $payload_raw ? json_decode( $payload_raw, true ) : null;
		}

		if ( ! is_array( $payload ) || empty( $payload['groups'] ) || ! is_array( $payload['groups'] ) ) {
			return new WP_Error( 'choicekit_payload', __( 'Please complete the kit selections before adding to cart.', 'choicekit-for-woocommerce' ) );
		}

		$config          = ChoiceKit_Config::get_config( $product_id );
		$group_ids       = array_column( $config['groups'], 'id' );
		if ( count( $group_ids ) !== count( array_unique( $group_ids ) ) ) {
			return new WP_Error( 'choicekit_duplicate_groups', __( 'This kit has duplicate group settings. Please contact the store owner.', 'choicekit-for-woocommerce' ) );
		}
		$submitted      = array();
		$valid_choices   = array();
		$selection_items = array();

		foreach ( $payload['groups'] as $group ) {
			if ( ! is_array( $group ) || empty( $group['id'] ) || ! is_string( $group['id'] ) || ! isset( $group['selected'] ) || ! is_array( $group['selected'] ) ) {
				continue;
			}

			$selected_ids = array();
			foreach ( $group['selected'] as $id ) {
				if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
					$selected_ids[] = absint( $id );
				}
			}
			$submitted[ sanitize_key( $group['id'] ) ] = array_values( array_filter( $selected_ids ) );
		}

		foreach ( $config['groups'] as $group ) {
			$group_id = $group['id'];
			$selected = isset( $submitted[ $group_id ] ) ? array_unique( $submitted[ $group_id ] ) : array();
			$count    = count( $selected );

			if ( $count < $group['min'] || ( $group['max'] > 0 && $count > $group['max'] ) ) {
				return new WP_Error(
					'choicekit_group_count',
					sprintf(
						/* translators: 1: group title, 2: minimum, 3: maximum. */
						__( '%1$s requires between %2$d and %3$d choices.', 'choicekit-for-woocommerce' ),
						$group['title'],
						$group['min'],
						$group['max']
					)
				);
			}

			$allowed = array_map( 'absint', $group['product_ids'] );

			foreach ( $selected as $selected_product_id ) {
				if ( ! in_array( $selected_product_id, $allowed, true ) ) {
					return new WP_Error( 'choicekit_invalid_choice', __( 'One of the selected kit items is no longer available.', 'choicekit-for-woocommerce' ) );
				}

				$component = wc_get_product( $selected_product_id );
				if ( ! ChoiceKit_Config::is_selectable_product( $selected_product_id ) || ( $component->is_sold_individually() && $quantity > 1 ) ) {
					return new WP_Error( 'choicekit_stock', __( 'One of the selected kit items is out of stock or cannot be purchased.', 'choicekit-for-woocommerce' ) );
				}

				$valid_choices[]   = $selected_product_id;
				$selection_items[] = array(
					'group_id'              => $group_id,
					'group_title'           => $group['title'],
					'product_id'            => $selected_product_id,
					'group_discount_type'   => $group['discount_type'],
					'group_discount_amount' => $group['discount_amount'],
					'group_discount_min_items' => $group['discount_min_items'],
				);
			}
		}

		if ( empty( $selection_items ) || ! $this->has_selection_stock( $selection_items, $quantity ) ) {
			return new WP_Error( 'choicekit_stock', __( 'There is not enough stock for the selected kit items.', 'choicekit-for-woocommerce' ) );
		}

		return array(
			'product_ids' => $valid_choices,
			'selections'  => $selection_items,
		);
	}

	/** Check aggregate stock across all selected products and the existing cart. */
	private function has_selection_stock( $selections, $quantity, $excluded_parent_key = '' ) {
		$required = array();
		$products = array();
		$sold_individually = array();
		foreach ( $selections as $selection ) {
			$product = wc_get_product( $selection['product_id'] );
			if ( ! $product ) {
				return false;
			}
			$stock_id = $product->get_stock_managed_by_id();
			$products[ $stock_id ] = wc_get_product( $stock_id );
			if ( ! $products[ $stock_id ] ) {
				return false;
			}
			$required[ $stock_id ] = ( isset( $required[ $stock_id ] ) ? $required[ $stock_id ] : 0 ) + $quantity;
			if ( $product->is_sold_individually() ) {
				$product_id = $product->get_id();
				$sold_individually[ $product_id ] = ( isset( $sold_individually[ $product_id ] ) ? $sold_individually[ $product_id ] : 0 ) + $quantity;
			}
		}

		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( $excluded_parent_key && ! empty( $item['choicekit_parent_key'] ) && $excluded_parent_key === $item['choicekit_parent_key'] ) {
					continue;
				}
				if ( empty( $item['data'] ) ) {
					continue;
				}
				$stock_id = $item['data']->get_stock_managed_by_id();
				if ( isset( $required[ $stock_id ] ) ) {
					$required[ $stock_id ] += (float) $item['quantity'];
				}
				$product_id = $item['data']->get_id();
				if ( isset( $sold_individually[ $product_id ] ) ) {
					$sold_individually[ $product_id ] += (float) $item['quantity'];
				}
			}
		}

		foreach ( $sold_individually as $count ) {
			if ( $count > 1 ) {
				return false;
			}
		}

		foreach ( $required as $stock_id => $count ) {
			if ( ! $products[ $stock_id ]->has_enough_stock( $count ) ) {
				return false;
			}
		}
		return true;
	}

	/** Check a parent quantity update using the same aggregate stock rule. */
	private function has_component_stock( $cart, $parent_key, $quantity ) {
		$selections = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['choicekit_component'] ) && $parent_key === $item['choicekit_parent_key'] ) {
				$selections[] = array( 'product_id' => $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			}
		}
		return $this->has_selection_stock( $selections, $quantity, $parent_key );
	}
}
