<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayReturn {

	/**
	 * Read and normalize the GF gateway return params from the current request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_return_data_from_request(): array {
		$query_args = wp_unslash( filter_input_array( INPUT_GET ) ?: array() );


		if ( empty( $query_args ) || empty( $query_args['iftp_gf_pay'] ) || empty( $query_args['iftp_gateway'] ) ) {
			return array();
		}

		$return_data = array(
			'iftp_gf_pay' => sanitize_text_field( (string) $query_args['iftp_gf_pay'] ),
		);


		$entry_id = isset( $query_args['id'] ) ? absint( $query_args['id'] ) : 0;
		if ( $entry_id > 0 ) {
			$return_data['entry_id'] = $entry_id;
		}

		$transaction_id = self::sanitize_transaction_id( (string) ( $query_args['transactionId'] ?? '' ) );
		if ( $transaction_id !== '' ) {
			$return_data['transaction_id'] = $transaction_id;
		}

		$payment_method = (string) ( $query_args['PaymentMethod'] ?? '' );
		if ( $payment_method !== '' ) {
			$return_data['payment_method'] = $payment_method;
		}

		return $return_data;
	}

	/**
	 * @param array<string, mixed> $return_data
	 */
	public static function get_return_status( array $return_data ): string {
		if ( empty( $return_data['iftp_gf_pay'] ) ) {
			return '';
		}
		return strtolower( sanitize_text_field( (string) $return_data['iftp_gf_pay'] ) );
	}

	private static function sanitize_transaction_id( string $transaction_id ): string {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );

		if ( $transaction_id === '' || str_contains( $transaction_id, '[' ) ) {
			return '';
		}

		return $transaction_id;
	}

	private function __construct() {}
}
