<?php
/**
 * Core WooCommerce finance matrix. Creates temporary catalog data and removes it.
 *
 * Run with: php tests/financial-matrix.php
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}

require dirname( __DIR__, 4 ) . '/wp-load.php';
wc_load_cart();

function choicekit_finance_assert( $actual, $expected, $label, $tolerance = 0.011 ) {
	if ( abs( (float) $actual - (float) $expected ) > $tolerance ) {
		throw new RuntimeException( esc_html( $label . ': expected ' . $expected . ', got ' . $actual ) );
	}
}

function choicekit_finance_product( $price, $virtual = false ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'ChoiceKit finance test ' . wp_generate_uuid4() );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( (string) $price );
	$product->set_virtual( $virtual );
	$product->set_tax_status( 'taxable' );
	$product->save();
	return $product;
}

function choicekit_finance_coupon( $type, $amount, $product_ids = array(), $excluded_ids = array() ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'choicekit-test-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );
	$coupon->set_product_ids( $product_ids );
	$coupon->set_excluded_product_ids( $excluded_ids );
	$coupon->save();
	return $coupon;
}

function choicekit_finance_config( $parts, $allow_parent_coupon ) {
	return array(
		'discount_type'   => 'fixed',
		'discount_amount' => 5,
		'coupon_targets_kit' => $allow_parent_coupon ? 'yes' : 'no',
		'groups' => array(
			array(
				'id' => 'parts',
				'title' => 'Parts',
				'min' => 2,
				'max' => 2,
				'product_ids' => $parts,
				'discount_type' => 'fixed',
				'discount_amount' => 10,
			),
		),
	);
}

function choicekit_finance_add_kit( $parent_id, $parts, $quantity = 2 ) {
	WC()->cart->empty_cart();
	$_POST['choicekit_cart_nonce'] = wp_create_nonce( 'choicekit_add_to_cart' );
	$_POST['choicekit_payload'] = wp_json_encode( array( 'groups' => array( array( 'id' => 'parts', 'selected' => $parts ) ) ) );
	if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, $quantity ) ) {
		throw new RuntimeException( 'Finance kit failed validation.' );
	}
	$key = WC()->cart->add_to_cart( $parent_id, $quantity );
	if ( ! $key || 3 !== count( WC()->cart->get_cart() ) ) {
		throw new RuntimeException( 'Finance kit did not contain parent and both components.' );
	}
	WC()->cart->calculate_totals();
	return $key;
}

$created = array();
$old_options = array();
$tax_filter = null;
$shipping_zone = null;
$shipping_instance = 0;
$shipping_rate_filter = function( $rates ) use ( &$shipping_instance ) {
	if ( ! $shipping_instance ) {
		return array();
	}
	return isset( $rates[ 'flat_rate:' . $shipping_instance ] ) ? array( 'flat_rate:' . $shipping_instance => $rates[ 'flat_rate:' . $shipping_instance ] ) : array();
};
add_filter( 'woocommerce_package_rates', $shipping_rate_filter );
$old_chosen_shipping = WC()->session->get( 'chosen_shipping_methods' );
$old_awaiting_payment = WC()->session->get( 'order_awaiting_payment' );
$old_billing_country = WC()->customer->get_billing_country();
$old_shipping_country = WC()->customer->get_shipping_country();
$created_order_id = 0;
$checks = 0;

foreach ( array( 'woocommerce_calc_taxes', 'woocommerce_calc_discounts_sequentially', 'woocommerce_prices_include_tax', 'woocommerce_price_num_decimals', 'woocommerce_tax_display_cart', 'woocommerce_shipping_tax_class', 'woocommerce_shipping_debug_mode', 'woocommerce_ship_to_countries' ) as $name ) {
	$old_options[ $name ] = get_option( $name );
}

try {
	$parent = choicekit_finance_product( 0 );
	$created[] = $parent->get_id();
	$a = choicekit_finance_product( 50 );
	$created[] = $a->get_id();
	$b = choicekit_finance_product( 20 );
	$created[] = $b->get_id();
	$parts = array( $a->get_id(), $b->get_id() );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::ENABLED_META, 'yes' );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( choicekit_finance_config( $parts, false ) ) );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_calc_discounts_sequentially', 'no' );
	update_option( 'woocommerce_shipping_debug_mode', 'yes' );
	update_option( 'woocommerce_ship_to_countries', 'all' );
	update_option( 'woocommerce_price_num_decimals', '2' );

	$parent_key = choicekit_finance_add_kit( $parent->get_id(), $parts );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 110, 'Two kits before coupons' );
	++$checks;
	$parent_item = WC()->cart->get_cart_item( $parent_key );
	if ( $parent_item['data']->needs_shipping() ) {
		throw new RuntimeException( 'Container is still shippable.' );
	}
	$packages = WC()->cart->get_shipping_packages();
	if ( 1 !== count( $packages ) || 2 !== count( $packages[0]['contents'] ) ) {
		throw new RuntimeException( 'Shipping package does not contain exactly the selected products.' );
	}
	choicekit_finance_assert( $packages[0]['contents_cost'], 110, 'Shipping package value' );
	++$checks;
	$threshold_config = choicekit_finance_config( $parts, false );
	$threshold_config['groups'][0]['min'] = 1;
	$threshold_config['groups'][0]['max'] = 2;
	$threshold_config['groups'][0]['discount_min_items'] = 2;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $threshold_config ) );
	WC()->cart->empty_cart();
	$_POST['choicekit_payload'] = wp_json_encode(
		array( 'groups' => array( array( 'id' => 'parts', 'selected' => array( $a->get_id() ) ) ) )
	);
	$threshold_key = WC()->cart->add_to_cart( $parent->get_id(), 1 );
	if ( ! $threshold_key ) {
		throw new RuntimeException( 'Valid one-item kit was rejected.' );
	}
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_cart_contents_total(), 45, 'Group discount does not activate below two selections' );
	++$checks;
	$threshold_config['groups'][0]['discount_min_items'] = 1;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $threshold_config ) );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_cart_contents_total(), 45, 'Existing kit retains its discount trigger after an edit' );
	++$checks;
	$impossible_config = ChoiceKit_Config::sanitize_config( $threshold_config );
	$impossible_config['groups'][0]['discount_min_items'] = 3;
	if ( ! ChoiceKit_Config::get_setup_warnings( $impossible_config ) ) {
		throw new RuntimeException( 'Unreachable discount trigger was not flagged in setup.' );
	}
	++$checks;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( choicekit_finance_config( $parts, false ) ) );
	$parent_key = choicekit_finance_add_kit( $parent->get_id(), $parts );
	WC()->customer->set_shipping_country( 'US' );
	$shipping_zone = new WC_Shipping_Zone( 0 );
	$shipping_instance = $shipping_zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $shipping_instance . '_settings', array( 'enabled' => 'yes', 'title' => 'ChoiceKit test shipping', 'tax_status' => 'taxable', 'cost' => '3 * [qty]' ) );
	update_option( 'woocommerce_shipping_debug_mode', 'yes' );
	WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $shipping_instance ) );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_shipping_total(), 12, 'Quantity-based shipping counts four selected units' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 122, 'Kit plus shipping total' );
	++$checks;
	$shipping_zone->delete_shipping_method( $shipping_instance );
	delete_option( 'woocommerce_flat_rate_' . $shipping_instance . '_settings' );
	$shipping_instance = 0;
	WC()->session->set( 'chosen_shipping_methods', $old_chosen_shipping );
	WC()->cart->calculate_totals();

	$percent = choicekit_finance_coupon( 'percent', 10 );
	$created[] = $percent->get_id();
	WC()->cart->apply_coupon( $percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 11, 'Global percent coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 99, 'Global percent total' );
	++$checks;
	WC()->cart->remove_coupon( $percent->get_code() );

	$fixed_cart = choicekit_finance_coupon( 'fixed_cart', 15 );
	$created[] = $fixed_cart->get_id();
	WC()->cart->apply_coupon( $fixed_cart->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 15, 'Fixed cart coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 95, 'Fixed cart total' );
	++$checks;
	WC()->cart->remove_coupon( $fixed_cart->get_code() );

	$component_coupon = choicekit_finance_coupon( 'percent', 10, array( $a->get_id() ) );
	$created[] = $component_coupon->get_id();
	WC()->cart->apply_coupon( $component_coupon->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 7.86, 'Component-targeted coupon' );
	++$checks;
	WC()->cart->remove_coupon( $component_coupon->get_code() );

	$parent_percent = choicekit_finance_coupon( 'percent', 10, array( $parent->get_id() ) );
	$created[] = $parent_percent->get_id();
	WC()->cart->apply_coupon( $parent_percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 0, 'Parent-targeted coupon disabled' );
	++$checks;
	WC()->cart->remove_coupon( $parent_percent->get_code() );

	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( choicekit_finance_config( $parts, true ) ) );
	choicekit_finance_add_kit( $parent->get_id(), $parts );
	WC()->cart->apply_coupon( $parent_percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 11, 'Parent-targeted percent coupon enabled' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 99, 'Parent-targeted percent total' );
	++$checks;
	WC()->cart->remove_coupon( $parent_percent->get_code() );

	$parent_fixed = choicekit_finance_coupon( 'fixed_product', 10, array( $parent->get_id() ) );
	$created[] = $parent_fixed->get_id();
	WC()->cart->apply_coupon( $parent_fixed->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 20, 'Parent-targeted fixed product coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 90, 'Parent-targeted fixed product total' );
	++$checks;
	WC()->cart->remove_coupon( $parent_fixed->get_code() );
	$limited = choicekit_finance_coupon( 'fixed_product', 10, array( $parent->get_id() ) );
	$limited->set_limit_usage_to_x_items( 1 );
	$limited->save();
	$created[] = $limited->get_id();
	WC()->cart->apply_coupon( $limited->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 10, 'Parent coupon limited to one kit unit' );
	++$checks;
	WC()->cart->remove_coupon( $limited->get_code() );

	$excluded = choicekit_finance_coupon( 'fixed_product', 10, array( $parent->get_id() ), array( $a->get_id() ) );
	$created[] = $excluded->get_id();
	WC()->cart->apply_coupon( $excluded->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 20, 'Excluded component coupon' );
	++$checks;
	WC()->cart->remove_coupon( $excluded->get_code() );

	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_tax_display_cart', 'excl' );
	WC()->customer->set_billing_country( 'US' );
	WC()->customer->set_shipping_country( 'US' );
	$tax_filter = function() {
		return array( 999999 => array( 'rate' => '10.0000', 'label' => 'ChoiceKit finance tax', 'shipping' => 'yes', 'compound' => 'no' ) );
	};
	add_filter( 'woocommerce_find_rates', $tax_filter );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_cart_contents_tax(), 11, 'Exclusive tax after kit discounts' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 121, 'Exclusive tax total' );
	++$checks;
	WC()->cart->apply_coupon( $parent_percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 11, 'Coupon before tax' );
	choicekit_finance_assert( WC()->cart->get_cart_contents_tax(), 9.9, 'Tax after coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 108.9, 'Coupon and tax total' );
	++$checks;
	WC()->cart->remove_coupon( $parent_percent->get_code() );

	$shipping_instance = $shipping_zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $shipping_instance . '_settings', array( 'enabled' => 'yes', 'title' => 'ChoiceKit taxed shipping', 'tax_status' => 'taxable', 'cost' => '3 * [qty]' ) );
	WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:' . $shipping_instance ) );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_shipping_total(), 12, 'Taxed shipping before coupon' );
	choicekit_finance_assert( WC()->cart->get_shipping_tax(), 1.2, 'Shipping tax before coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 134.2, 'Exclusive tax and shipping total' );
	++$checks;
	WC()->cart->apply_coupon( $parent_percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_shipping_total(), 12, 'Coupon leaves shipping cost unchanged' );
	choicekit_finance_assert( WC()->cart->get_shipping_tax(), 1.2, 'Coupon leaves shipping tax unchanged' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 122.1, 'Coupon, tax, and shipping total' );
	++$checks;
	WC()->cart->remove_coupon( $parent_percent->get_code() );
	$shipping_zone->delete_shipping_method( $shipping_instance );
	delete_option( 'woocommerce_flat_rate_' . $shipping_instance . '_settings' );
	$shipping_instance = 0;
	WC()->session->set( 'chosen_shipping_methods', $old_chosen_shipping );

	update_option( 'woocommerce_prices_include_tax', 'yes' );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_cart_contents_tax(), 10, 'Inclusive tax after kit discounts' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 110, 'Inclusive tax total' );
	++$checks;

	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_price_num_decimals', '0' );
	WC()->cart->calculate_totals();
	WC()->cart->apply_coupon( $parent_fixed->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 20, 'Zero-decimal fixed kit coupon' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 90, 'Zero-decimal total' );
	++$checks;
	WC()->cart->remove_coupon( $parent_fixed->get_code() );

	$b->set_virtual( true );
	$b->save();
	choicekit_finance_add_kit( $parent->get_id(), $parts );
	$virtual_packages = WC()->cart->get_shipping_packages();
	if ( 1 !== count( $virtual_packages ) || 1 !== count( $virtual_packages[0]['contents'] ) ) {
		throw new RuntimeException( 'Virtual selected products were included in shipping or the physical product was omitted.' );
	}
	choicekit_finance_assert( $virtual_packages[0]['contents_cost'], 78.57, 'Physical selected product shipping value in zero-decimal currency', 0.02 );
	++$checks;

	$b->set_virtual( false );
	$b->save();
	update_option( 'woocommerce_price_num_decimals', '2' );
	choicekit_finance_add_kit( $parent->get_id(), $parts );
	$second_key = WC()->cart->add_to_cart( $parent->get_id(), 1 );
	if ( ! $second_key || 6 !== count( WC()->cart->get_cart() ) ) {
		throw new RuntimeException( 'Second distinct kit did not create its component lines.' );
	}
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 165, 'Multiple kit instances total' );
	WC()->cart->apply_coupon( $parent_fixed->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 30, 'Fixed coupon across multiple kit instances' );
	++$checks;
	WC()->cart->remove_coupon( $parent_fixed->get_code() );
	WC()->cart->apply_coupon( $limited->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 10, 'Coupon limit across multiple kit instances' );
	++$checks;
	WC()->cart->remove_coupon( $limited->get_code() );
	WC()->cart->apply_coupon( $parent_fixed->get_code() );
	WC()->cart->apply_coupon( $percent->get_code() );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 46.5, 'Stacked coupons with non-sequential discounts' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 118.5, 'Non-sequential stacked coupon total' );
	++$checks;
	update_option( 'woocommerce_calc_discounts_sequentially', 'yes' );
	WC()->cart->calculate_totals();
	choicekit_finance_assert( WC()->cart->get_discount_total(), 43.5, 'Stacked coupons with sequential discounts' );
	choicekit_finance_assert( WC()->cart->get_total( 'edit' ), 121.5, 'Sequential stacked coupon total' );
	++$checks;
	WC()->session->set( 'order_awaiting_payment', null );
	$created_order_id = WC()->checkout()->create_order( array( 'billing_first_name' => 'ChoiceKit', 'billing_last_name' => 'Finance Test', 'billing_email' => 'choicekit-test@example.invalid', 'payment_method' => '' ) );
	if ( is_wp_error( $created_order_id ) || ! $created_order_id ) {
		throw new RuntimeException( 'Checkout did not create a financial test order.' );
	}
	$order = wc_get_order( $created_order_id );
	choicekit_finance_assert( $order->get_total(), 121.5, 'Checkout order total matches cart' );
	choicekit_finance_assert( $order->get_discount_total(), 43.5, 'Checkout order coupon discount matches cart' );
	if ( 6 !== count( $order->get_items( 'line_item' ) ) ) {
		throw new RuntimeException( 'Checkout order lost kit or component lines.' );
	}
	foreach ( $order->get_items( 'line_item' ) as $order_item ) {
		if ( $order_item->get_meta( __( 'ChoiceKit parent', 'choicekit-for-woocommerce' ) ) && $order_item->get_product()->needs_shipping() ) {
			throw new RuntimeException( 'Order kit container was treated as a shippable product.' );
		}
	}
	++$checks;

	echo 'ChoiceKit finance matrix passed: ' . esc_html( $checks ) . " scenarios.\n";
} finally {
	remove_filter( 'woocommerce_package_rates', $shipping_rate_filter );
	if ( $shipping_instance && $shipping_zone ) {
		$shipping_zone->delete_shipping_method( $shipping_instance );
		delete_option( 'woocommerce_flat_rate_' . $shipping_instance . '_settings' );
	}
	WC()->session->set( 'chosen_shipping_methods', $old_chosen_shipping );
	WC()->session->set( 'order_awaiting_payment', $old_awaiting_payment );
	WC()->customer->set_billing_country( $old_billing_country );
	WC()->customer->set_shipping_country( $old_shipping_country );
	if ( $created_order_id && ! is_wp_error( $created_order_id ) ) {
		$order = wc_get_order( $created_order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	if ( $tax_filter ) {
		remove_filter( 'woocommerce_find_rates', $tax_filter );
	}
	foreach ( $old_options as $name => $value ) {
		update_option( $name, $value );
	}
	WC()->cart->empty_cart();
	foreach ( $created as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}
