=== ChoiceKit for WooCommerce ===
Contributors: farazshoaib
Tags: woocommerce, product kits, product bundles, mix and match
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers build a kit from selected WooCommerce products, with live pricing and linked cart items.

== Description ==

ChoiceKit adds choice groups to a WooCommerce product. Customers select simple products or complete variations from each group. The selected products remain separate WooCommerce cart and order lines, so their stock, tax class, and shipping properties remain available to WooCommerce.

Features:

* Minimum and maximum choices per group.
* Optional fixed or percentage discounts for a group or the whole kit.
* A group discount threshold based on the number of selected products.
* Live kit price preview and discounted price display for selected items.
* An option to let product coupons targeting the kit apply to its selected products.
* Linked quantities, stock validation, and support for the WooCommerce Cart and Checkout blocks.

The kit product is a zero-price container. The selected products carry the charge, tax, and shipping details. ChoiceKit does not send data to an external service or add tracking.

Test tax, shipping, and coupon combinations on a staging site before selling kits, especially when using third-party extensions. A product's optional customer instruction is informational; configured discount fields determine the actual price.

== Screenshots ==

1. Select products in a kit and see live discounted prices and the kit total.
2. Configure choice groups, selection limits, products, and variations.
3. Set group discounts, optional kit discounts, and product coupon behavior.
4. View the kit and its linked selected products in the WooCommerce cart.

== Installation ==

1. Install and activate WooCommerce.
2. Upload ChoiceKit and activate it from Plugins.
3. Edit a simple or variable product and open the ChoiceKit tab in Product data.
4. Enable the kit, add choice groups, choose products, and save the product.
5. Review any setup warnings and test the kit on its product page, cart, and checkout.

== Frequently Asked Questions ==

= Which products can customers select? =

Purchasable, in-stock simple products and complete variations. A ChoiceKit product cannot be selected inside another kit.

= How are shipping and tax calculated? =

WooCommerce calculates them on the selected product lines. The kit container is virtual and has no charge of its own.

= Do coupons work? =

Coupons for selected products work normally. Product coupons targeting the kit can also apply to selected products when the option is enabled for that kit. Coupon behavior from third-party extensions should be tested on the store where it will be used.

== Changelog ==

= 1.0.0 =

* Initial public release with product choice groups, kit discounts, linked cart items, and WooCommerce tax, shipping, and coupon integration.
