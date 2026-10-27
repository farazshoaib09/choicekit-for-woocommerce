# ChoiceKit financial testing

Run on the local WordPress/WooCommerce test installation:

```powershell
php tests/financial-matrix.php
php tests/integration.php
```

The finance matrix creates temporary products, coupons, a shipping method, and an order. Its `finally` block deletes them and restores the WooCommerce options it changes.

## Covered calculations

- Kit and group discounts with two selected products and quantity two.
- Group discount thresholds, including selections below the threshold, price snapshots after a rule edit, and unreachable rule warnings.
- Shipping packages containing selected physical products and excluding the kit container and selected virtual products.
- A real flat-rate formula based on selected-product quantity, with and without product coupons.
- Exclusive and inclusive 10% product tax, plus taxable shipping.
- Global percentage and fixed-cart coupons; coupons targeting a selected product.
- Kit-parent product coupons disabled and enabled through the per-kit setting; percentage and fixed-product amounts.
- Fixed-product coupon allocation across components, exclusions, per-item usage limits, and multiple kit instances.
- Stacked fixed-product and percentage coupons with sequential discounts both off and on.
- Two-decimal and zero-decimal currencies.
- Checkout order total, discount, line count, and order-item shipping status.

The integration test also covers add-to-cart validation, nested-kit rejection, stock and quantity synchronization, rollback, cart repair, Store API additions, malformed submissions, and saved kit price snapshots.

## Compatibility boundary

These tests use WooCommerce core tax and shipping logic in one local installation. They do not establish correctness for every country-specific tax table, compound rate, third-party shipping/tax/coupon extension, payment gateway, currency switcher, or refund flow. Those combinations need staging tests with the store's actual configuration before release.

## Release packaging

Exclude this `tests/` directory from the WordPress.org release ZIP and SVN trunk. Include only the plugin entry file, `includes/`, `assets/`, and `readme.txt`. The PHP test scripts also reject non-CLI execution to prevent web requests from creating test products or orders in a development installation.
