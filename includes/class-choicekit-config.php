<?php
/**
 * Kit configuration helpers.
 *
 * @package ChoiceKitForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ChoiceKit_Config {
	const ENABLED_META = '_choicekit_enabled';
	const CONFIG_META  = '_choicekit_config';

	/**
	 * Check whether a product has ChoiceKit enabled.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::ENABLED_META, true );
	}

	/**
	 * Get sanitized kit configuration.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_config( $product_id ) {
		$raw = get_post_meta( $product_id, self::CONFIG_META, true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return self::sanitize_config( $raw );
	}

	/**
	 * Sanitize a full config payload.
	 *
	 * @param array $config Raw config.
	 * @return array
	 */
	public static function sanitize_config( $config ) {
		$clean = array(
			'discount_type'   => isset( $config['discount_type'] ) ? sanitize_key( $config['discount_type'] ) : 'none',
			'discount_amount' => isset( $config['discount_amount'] ) ? max( 0, (float) wc_format_decimal( $config['discount_amount'] ) ) : 0,
			'coupon_targets_kit' => isset( $config['coupon_targets_kit'] ) && 'yes' === $config['coupon_targets_kit'] ? 'yes' : 'no',
			'groups'          => array(),
		);

		if ( ! in_array( $clean['discount_type'], array( 'none', 'fixed', 'percent' ), true ) ) {
			$clean['discount_type'] = 'none';
		}

		if ( empty( $config['groups'] ) || ! is_array( $config['groups'] ) ) {
			return $clean;
		}

		foreach ( $config['groups'] as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$product_ids = isset( $group['product_ids'] ) && is_array( $group['product_ids'] ) ? $group['product_ids'] : array();
			$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

			$min = isset( $group['min'] ) ? max( 0, absint( $group['min'] ) ) : 0;
			$max = isset( $group['max'] ) ? max( 0, absint( $group['max'] ) ) : 1;

			if ( $max > 0 && $min > $max ) {
				$min = $max;
			}

			$discount_type = isset( $group['discount_type'] ) ? sanitize_key( $group['discount_type'] ) : 'none';
			if ( ! in_array( $discount_type, array( 'none', 'fixed', 'percent' ), true ) ) {
				$discount_type = 'none';
			}

			$clean['groups'][] = array(
				'id'              => ! empty( $group['id'] ) ? sanitize_key( $group['id'] ) : uniqid( 'group_' ),
				'title'           => ! empty( $group['title'] ) ? sanitize_text_field( $group['title'] ) : __( 'Choose items', 'choicekit-for-woocommerce' ),
				'description'     => ! empty( $group['description'] ) ? sanitize_text_field( $group['description'] ) : '',
				'min'             => $min,
				'max'             => $max,
				'product_ids'     => $product_ids,
				'discount_type'   => $discount_type,
				'discount_amount' => isset( $group['discount_amount'] ) ? max( 0, (float) wc_format_decimal( $group['discount_amount'] ) ) : 0,
				'discount_min_items' => isset( $group['discount_min_items'] ) ? max( 1, absint( $group['discount_min_items'] ) ) : max( 1, $min ),
			);
		}

		return $clean;
	}

	/** Whether a catalog product can currently be selected as a kit component. */
	public static function is_selectable_product( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product
			&& ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) )
			&& ! self::is_enabled( $product->get_id() )
			&& ! ( $product->is_type( 'variation' ) && self::is_enabled( $product->get_parent_id() ) )
			&& $product->is_purchasable()
			&& $product->is_in_stock()
			&& ! ( $product->is_type( 'variation' ) && in_array( '', $product->get_variation_attributes(), true ) );
	}

	/** Report saved kit settings that cannot produce the advertised choice or discount. */
	public static function get_setup_warnings( $config ) {
		$warnings = array();
		if ( empty( $config['groups'] ) ) {
			return array( __( 'Add at least one choice group before selling this kit.', 'choicekit-for-woocommerce' ) );
		}
		$ids = array();
		foreach ( $config['groups'] as $group ) {
			$title = $group['title'];
			if ( in_array( $group['id'], $ids, true ) ) {
				/* translators: %s: kit group title. */
				$warnings[] = sprintf( __( 'Group "%s" has a duplicate group ID. Recreate one of the groups.', 'choicekit-for-woocommerce' ), $title );
			}
			$ids[] = $group['id'];
			$available = count( array_filter( $group['product_ids'], array( __CLASS__, 'is_selectable_product' ) ) );
			if ( 0 === $available ) {
				/* translators: %s: kit group title. */
				$warnings[] = sprintf( __( 'Group "%s" has no purchasable, in-stock products.', 'choicekit-for-woocommerce' ), $title );
			} elseif ( $group['min'] > $available ) {
				/* translators: 1: kit group title, 2: required choice count, 3: available product count. */
				$warnings[] = sprintf( __( 'Group "%1$s" requires %2$d choices but only %3$d are available.', 'choicekit-for-woocommerce' ), $title, $group['min'], $available );
			}
			if ( $group['max'] > 0 && $group['max'] > $available ) {
				/* translators: 1: kit group title, 2: maximum choice count, 3: available product count. */
				$warnings[] = sprintf( __( 'Group "%1$s" allows %2$d choices but only %3$d are available.', 'choicekit-for-woocommerce' ), $title, $group['max'], $available );
			}
			if ( 'none' !== $group['discount_type'] && $group['discount_amount'] > 0 && ( $group['discount_min_items'] > $available || ( $group['max'] > 0 && $group['discount_min_items'] > $group['max'] ) ) ) {
				/* translators: %s: kit group title. */
				$warnings[] = sprintf( __( 'The discount in group "%s" cannot activate at its current item threshold.', 'choicekit-for-woocommerce' ), $title );
			}
		}
		return $warnings;
	}

	/** Human-readable statement of the actual group price rule. */
	public static function get_group_discount_label( $group ) {
		if ( 'none' === $group['discount_type'] || $group['discount_amount'] <= 0 ) {
			return '';
		}
		if ( 'percent' === $group['discount_type'] ) {
			return sprintf(
				/* translators: 1: percentage, 2: selected item threshold. */
				__( '%1$s%% off selected items when %2$d or more are chosen.', 'choicekit-for-woocommerce' ),
				wc_format_localized_decimal( min( 100, $group['discount_amount'] ) ),
				$group['discount_min_items']
			);
		}
		return sprintf(
			/* translators: 1: currency amount, 2: selected item threshold. */
			__( '%1$s off selected items when %2$d or more are chosen.', 'choicekit-for-woocommerce' ),
			html_entity_decode( wp_strip_all_tags( wc_price( $group['discount_amount'] ) ), ENT_QUOTES, 'UTF-8' ),
			$group['discount_min_items']
		);
	}

	/**
	 * Calculate a selected-items discount.
	 *
	 * @param array $config         Kit config.
	 * @param float $component_sum  Component subtotal.
	 * @return float
	 */
	public static function calculate_discount( $config, $component_sum ) {
		if ( empty( $config['discount_type'] ) || 'none' === $config['discount_type'] ) {
			return 0;
		}

		$amount = isset( $config['discount_amount'] ) ? (float) $config['discount_amount'] : 0;

		if ( $amount <= 0 || $component_sum <= 0 ) {
			return 0;
		}

		if ( 'percent' === $config['discount_type'] ) {
			return min( $component_sum, $component_sum * min( 100, $amount ) / 100 );
		}

		return min( $component_sum, $amount );
	}
}
