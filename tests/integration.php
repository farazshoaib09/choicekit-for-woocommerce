<?php
/**
 * Local WooCommerce cart integration smoke test.
 *
 * Run with: php tests/integration.php
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}

require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'WooCommerce is required.' );
}

wc_load_cart();

function choicekit_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

function choicekit_test_product( $price, $stock ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'ChoiceKit test ' . wp_generate_uuid4() );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( (string) $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->save();
	return $product;
}

$created = array();
$tax_filter = null;
$old_calc_taxes = get_option( 'woocommerce_calc_taxes' );
$old_prices_include_tax = get_option( 'woocommerce_prices_include_tax' );
try {
	$parent = choicekit_test_product( 0, 20 );
	$created[] = $parent->get_id();
	$part   = choicekit_test_product( 50, 3 );
	$created[] = $part->get_id();
	update_post_meta( $parent->get_id(), ChoiceKit_Config::ENABLED_META, 'yes' );
	update_post_meta(
		$parent->get_id(),
		ChoiceKit_Config::CONFIG_META,
		wp_json_encode(
			array(
				'discount_type'   => 'fixed',
				'discount_amount' => 5,
				'groups'          => array(
					array(
						'id'              => 'one',
						'title'           => 'First',
						'min'             => 1,
						'max'             => 1,
						'product_ids'     => array( $part->get_id() ),
						'discount_type'   => 'fixed',
						'discount_amount' => 10,
					),
				),
			)
		)
	);

	$payload = wp_json_encode( array( 'groups' => array( array( 'id' => 'one', 'selected' => array( $part->get_id() ) ) ) ) );
	choicekit_test_assert( false !== strpos( wc_get_product( $parent->get_id() )->get_price_html(), 'Price calculated from selected kit items' ), 'Kit parent advertises a price that is not charged.' );
	choicekit_test_assert( ! ChoiceKit_Config::is_selectable_product( $parent->get_id() ), 'A kit was allowed as its own component.' );
	$nested_config = ChoiceKit_Config::get_config( $parent->get_id() );
	$nested_config['groups'][0]['product_ids'] = array( $parent->get_id() );
	choicekit_test_assert( ! empty( ChoiceKit_Config::get_setup_warnings( $nested_config ) ), 'Nested kit setup was not flagged.' );
	$_POST['choicekit_cart_nonce'] = wp_create_nonce( 'choicekit_add_to_cart' );
	$_POST['choicekit_payload']    = $payload;
	WC()->cart->empty_cart();
	$original_config = get_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, true );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $nested_config ) );
	$_POST['choicekit_payload'] = wp_json_encode( array( 'groups' => array( array( 'id' => 'one', 'selected' => array( $parent->get_id() ) ) ) ) );
	choicekit_test_assert( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1 ), 'Nested kit component was accepted.' );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, $original_config );
	$_POST['choicekit_payload'] = $payload;
	$duplicate_config = json_decode( $original_config, true );
	$duplicate_config['groups'][] = $duplicate_config['groups'][0];
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $duplicate_config ) );
	choicekit_test_assert( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1 ), 'Kit with duplicate group IDs was accepted.' );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, $original_config );

	choicekit_test_assert( apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 2 ), 'Valid kit rejected.' );
	$parent_key = WC()->cart->add_to_cart( $parent->get_id(), 2 );
	choicekit_test_assert( $parent_key && 2 === count( WC()->cart->get_cart() ), 'Parent and component were not both added.' );
	$component_key = '';
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( ! empty( $item['choicekit_component'] ) ) {
			$component_key = $key;
		}
	}
	choicekit_test_assert( '' !== $component_key, 'Component is missing.' );
	WC()->cart->calculate_totals();
	choicekit_test_assert( abs( (float) WC()->cart->get_cart_item( $component_key )['data']->get_price() - 35 ) < 0.001, 'Discounted unit price is wrong.' );
	choicekit_test_assert( abs( (float) WC()->cart->get_cart_contents_total() - 70 ) < 0.001, 'Two-kit product total is wrong.' );
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	WC()->customer->set_billing_country( 'US' );
	WC()->customer->set_shipping_country( 'US' );
	$tax_filter = function() {
		return array( 999999 => array( 'rate' => '10.0000', 'label' => 'ChoiceKit test tax', 'shipping' => 'yes', 'compound' => 'no' ) );
	};
	add_filter( 'woocommerce_find_rates', $tax_filter );
	WC()->cart->calculate_totals();
	choicekit_test_assert( abs( (float) WC()->cart->get_cart_contents_tax() - 7 ) < 0.001, 'Tax was not calculated on the discounted component price.' );
	remove_filter( 'woocommerce_find_rates', $tax_filter );
	$tax_filter = null;
	update_option( 'woocommerce_calc_taxes', $old_calc_taxes );
	update_option( 'woocommerce_prices_include_tax', $old_prices_include_tax );
	WC()->cart->calculate_totals();
	$original_config = get_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, true );
	$changed_config  = json_decode( $original_config, true );
	$changed_config['discount_type']              = 'none';
	$changed_config['groups'][0]['discount_type'] = 'none';
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $changed_config ) );
	WC()->cart->calculate_totals();
	choicekit_test_assert( abs( (float) WC()->cart->get_cart_contents_total() - 70 ) < 0.001, 'Existing kit discount changed after product edit.' );
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, $original_config );

	WC()->cart->set_quantity( $parent_key, 3 );
	choicekit_test_assert( 3 === WC()->cart->get_cart_item( $component_key )['quantity'], 'Component quantity did not follow parent.' );
	WC()->cart->set_quantity( $component_key, 1 );
	choicekit_test_assert( 3 === WC()->cart->get_cart_item( $component_key )['quantity'], 'Component quantity was changed independently.' );
	choicekit_test_assert( abs( (float) WC()->cart->get_cart_contents_total() - 105 ) < 0.001, 'Three-kit product total is wrong.' );
	WC()->cart->set_quantity( $parent_key, 4 );
	choicekit_test_assert( 3 === WC()->cart->get_cart_item( $parent_key )['quantity'], 'Overstock parent quantity was accepted.' );

	WC()->cart->remove_cart_item( $component_key );
	choicekit_test_assert( 0 === count( WC()->cart->get_cart() ), 'Removing a component left an orphan kit.' );

	$parent_key = WC()->cart->add_to_cart( $parent->get_id(), 1 );
	choicekit_test_assert( 2 === count( WC()->cart->get_cart() ), 'Second kit was not added.' );
	WC()->cart->remove_cart_item( $parent_key );
	choicekit_test_assert( 0 === count( WC()->cart->get_cart() ), 'Removing parent left components.' );
	WC()->cart->restore_cart_item( $parent_key );
	choicekit_test_assert( 2 === count( WC()->cart->get_cart() ), 'Undo did not restore components.' );
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( ! empty( $item['choicekit_component'] ) ) {
			unset( WC()->cart->cart_contents[ $key ] );
		}
	}
	ChoiceKit_Cart::instance()->verify_cart_integrity( WC()->cart );
	choicekit_test_assert( 0 === count( WC()->cart->get_cart() ), 'Session repair left an incomplete kit.' );
	WC()->cart->empty_cart();

	$part->set_sold_individually( true );
	$part->save();
	choicekit_test_assert( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 2 ), 'Sold individually component allowed two kits.' );
	$part->set_sold_individually( false );
	$part->save();

	$reject_part = function( $quantity, $product_id ) use ( $part ) {
		return $part->get_id() === $product_id ? 0 : $quantity;
	};
	add_filter( 'woocommerce_add_to_cart_quantity', $reject_part, 10, 2 );
	WC()->cart->add_to_cart( $parent->get_id(), 1 );
	remove_filter( 'woocommerce_add_to_cart_quantity', $reject_part, 10 );
	choicekit_test_assert( 0 === count( WC()->cart->get_cart() ), 'Failed component did not roll back the kit.' );

	unset( $_POST['choicekit_cart_nonce'], $_POST['choicekit_payload'] );
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'extensions', array( 'choicekit' => array( 'payload' => json_decode( $payload, true ) ) ) );
	$data = apply_filters(
		'woocommerce_store_api_add_to_cart_data',
		array( 'id' => $parent->get_id(), 'quantity' => 1, 'variation' => array(), 'cart_item_data' => array() ),
		$request
	);
	$data['cart_item_data'] = apply_filters( 'woocommerce_add_cart_item_data', $data['cart_item_data'], $parent->get_id(), 0, 1 );
	choicekit_test_assert( ! empty( $data['cart_item_data']['choicekit_parent'] ), 'Store API submission was not parsed.' );
	choicekit_test_assert( apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1, 0, array(), $data['cart_item_data'] ), 'Store API submission was rejected.' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_param( 'id', $parent->get_id() );
	$request->set_param( 'quantity', 1 );
	$response = rest_do_request( $request );
	choicekit_test_assert( 201 === $response->get_status(), 'Store API add failed: ' . wp_json_encode( $response->get_data() ) );
	choicekit_test_assert( 2 === count( WC()->cart->get_cart() ), 'Store API add did not include the component.' );
	foreach ( WC()->cart->get_cart() as $item ) {
		choicekit_test_assert( ! isset( $item['choicekit_submission'] ), 'Raw Store API payload was stored in cart.' );
	}
	WC()->cart->empty_cart();
	$missing_payload = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$missing_payload->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$missing_payload->set_param( 'id', $parent->get_id() );
	$missing_payload->set_param( 'quantity', 1 );
	$response = rest_do_request( $missing_payload );
	choicekit_test_assert( $response->get_status() >= 400, 'Store API accepted a kit without selections.' );
	WC()->cart->add_to_cart( $parent->get_id(), 1 );
	choicekit_test_assert( 0 === count( WC()->cart->get_cart() ), 'Programmatic add accepted a kit without selections.' );
	$bad_payload = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$bad_payload->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$bad_payload->set_param( 'id', $parent->get_id() );
	$bad_payload->set_param( 'quantity', 1 );
	$bad_payload->set_param( 'extensions', array( 'choicekit' => array( 'payload' => array( 'groups' => array( array( 'id' => array( 'bad' ), 'selected' => array( array( 1 ) ) ) ) ) ) ) );
	$response = rest_do_request( $bad_payload );
	choicekit_test_assert( $response->get_status() >= 400, 'Store API accepted malformed kit selections.' );

	$optional_config = json_decode( $original_config, true );
	$optional_config['groups'][0]['min'] = 0;
	$optional_config['groups'][0]['max'] = 0;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $optional_config ) );
	$GLOBALS['product'] = wc_get_product( $parent->get_id() );
	ob_start();
	ChoiceKit_Frontend::instance()->render_builder();
	$builder = ob_get_clean();
	choicekit_test_assert( false !== strpos( $builder, 'type="checkbox"' ), 'Unlimited group did not render checkboxes.' );
	choicekit_test_assert( false !== strpos( $builder, 'data-discount-min-items="1"' ), 'Discount threshold was not rendered in the builder.' );
	$optional_config['groups'][0]['max'] = 1;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $optional_config ) );
	ob_start();
	ChoiceKit_Frontend::instance()->render_builder();
	$builder = ob_get_clean();
	choicekit_test_assert( false !== strpos( $builder, 'type="checkbox"' ), 'Optional one-choice group did not render checkboxes.' );
	$threshold_config = json_decode( $original_config, true );
	$threshold_config['groups'][0]['min'] = 1;
	$threshold_config['groups'][0]['max'] = 2;
	$threshold_config['groups'][0]['discount_min_items'] = 2;
	update_post_meta( $parent->get_id(), ChoiceKit_Config::CONFIG_META, wp_json_encode( $threshold_config ) );
	ob_start();
	ChoiceKit_Frontend::instance()->render_builder();
	$builder = ob_get_clean();
	choicekit_test_assert( false !== strpos( $builder, 'data-discount-min-items="2"' ), 'Configured discount threshold was not rendered.' );
	choicekit_test_assert( false !== strpos( $builder, 'when 2 or more are chosen' ), 'Actual discount condition is missing from customer-facing copy.' );
	$GLOBALS['post'] = get_post( $parent->get_id() );
	ob_start();
	ChoiceKit_Admin::instance()->render_product_data_panel();
	$admin_panel = ob_get_clean();
	choicekit_test_assert( false !== strpos( $admin_panel, '[discount_min_items]' ), 'Discount threshold field is missing from the product editor.' );
	$_POST['choicekit_nonce'] = wp_create_nonce( 'choicekit_save_product' );
	$_POST[ ChoiceKit_Config::ENABLED_META ] = 'yes';
	$_POST['choicekit_discount_type'] = $threshold_config['discount_type'];
	$_POST['choicekit_discount_amount'] = $threshold_config['discount_amount'];
	$_POST['choicekit_groups'] = $threshold_config['groups'];
	$_POST['choicekit_groups'][0]['discount_min_items'] = 1;
	$original_user = get_current_user_id();
	wp_set_current_user( 0 );
	ChoiceKit_Admin::instance()->save_product_meta( $parent->get_id() );
	choicekit_test_assert( 2 === ChoiceKit_Config::get_config( $parent->get_id() )['groups'][0]['discount_min_items'], 'Unauthorized product editor modified kit settings.' );
	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	choicekit_test_assert( ! empty( $administrators ), 'An administrator is required to test product editor permissions.' );
	wp_set_current_user( $administrators[0] );
	$_POST['choicekit_nonce'] = wp_create_nonce( 'choicekit_save_product' );
	ChoiceKit_Admin::instance()->save_product_meta( $parent->get_id() );
	choicekit_test_assert( 1 === ChoiceKit_Config::get_config( $parent->get_id() )['groups'][0]['discount_min_items'], 'Discount threshold was not saved by the product editor.' );
	wp_set_current_user( $original_user );

	echo "ChoiceKit integration checks passed.\n";
} finally {
	if ( $tax_filter ) {
		remove_filter( 'woocommerce_find_rates', $tax_filter );
	}
	update_option( 'woocommerce_calc_taxes', $old_calc_taxes );
	update_option( 'woocommerce_prices_include_tax', $old_prices_include_tax );
	WC()->cart->empty_cart();
	foreach ( $created as $product_id ) {
		wp_delete_post( $product_id, true );
	}
}
