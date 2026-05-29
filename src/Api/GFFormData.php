<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Gravity Forms-specific helpers for resolving form amounts and customer data.
 */
final class GFFormData {

	/**
	 * Resolve the total payment amount from a GF form and entry using GFCommon::get_product_fields().
	 */
	public static function resolve_amount( array $form, array $entry ): float {
		if ( ! class_exists( 'GFCommon' ) ) {
			return 0.0;
		}

		$products = \GFCommon::get_product_fields( $form, $entry );

		if ( empty( $products['products'] ) ) {
			return 0.0;
		}

		$total = 0.0;

		foreach ( $products['products'] as $product ) {
			$price    = (float) str_replace( array( ',', ' ' ), '', (string) ( $product['price'] ?? 0 ) );
			$quantity = max( 1, (int) ( $product['quantity'] ?? 1 ) );

			if ( ! empty( $product['options'] ) && is_array( $product['options'] ) ) {
				foreach ( $product['options'] as $option ) {
					$price += (float) str_replace( array( ',', ' ' ), '', (string) ( $option['price'] ?? 0 ) );
				}
			}

			$total += $price * $quantity;
		}

		if ( isset( $products['shipping']['price'] ) && is_numeric( $products['shipping']['price'] ) ) {
			$total += (float) $products['shipping']['price'];
		}

		return max( 0.0, round( $total, 2 ) );
	}

	/**
	 * Get the customer's email address from the entry (first email field value found).
	 */
	public static function get_customer_email( array $form, array $entry ): string {
		foreach ( $form['fields'] as $field ) {
			if ( $field->type !== 'email' ) {
				continue;
			}

			$value = sanitize_email( (string) rgar( $entry, (string) $field->id ) );
			if ( $value !== '' ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Get the customer's full name from the entry (first name field value found).
	 */
	public static function get_customer_name( array $form, array $entry ): string {
		foreach ( $form['fields'] as $field ) {
			if ( $field->type !== 'name' ) {
				continue;
			}

			$first_id = $field->id . '.3';
			$last_id  = $field->id . '.6';

			$first = sanitize_text_field( (string) rgar( $entry, $first_id ) );
			$last  = sanitize_text_field( (string) rgar( $entry, $last_id ) );

			$full = trim( "$first $last" );
			if ( $full !== '' ) {
				return $full;
			}

			$single = sanitize_text_field( (string) rgar( $entry, (string) $field->id ) );
			if ( $single !== '' ) {
				return $single;
			}
		}

		return '';
	}

	/**
	 * Find the first field of a given GF type in the form.
	 */
	public static function find_field_by_type( array $form, string $type ): ?\GF_Field {
		foreach ( $form['fields'] as $field ) {
			if ( $field->type === $type ) {
				return $field;
			}
		}

		return null;
	}

	private function __construct() {}
}
