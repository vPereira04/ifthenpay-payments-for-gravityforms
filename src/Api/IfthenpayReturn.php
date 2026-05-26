<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayReturn {

	public const TRANSACTION_ID_KEYS = [ 'iftp_gf_transaction_id', 'transaction_id', 'transactionId' ];
	public const PAYMENT_METHOD_KEYS = [ 'payment_method', 'paymentMethod', 'PaymentMethod', 'method', 'method_type', 'Method' ];

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string> $keys
	 */
	public static function first_string_value( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return sanitize_text_field( $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Read and normalize the GF gateway return params from the current request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_return_data_from_request(): array {
		$query_args = wp_unslash( filter_input_array( INPUT_GET ) ?: [] );


		if ( empty( $query_args ) || empty( $query_args['iftp_gf_pay'] ) || empty( $query_args['iftp_gateway'] ) ) {
			return [];
		}

		$return_data = [
			'iftp_gf_pay' => sanitize_text_field( (string) $query_args['iftp_gf_pay'] ),
		];


		$entry_id = isset( $query_args['id'] ) ? absint( $query_args['id'] ) : 0;
		if ( $entry_id > 0 ) {
			$return_data['entry_id'] = $entry_id;
		}

		$transaction_id = self::get_return_transaction_id_from_request( $query_args );
		if ( $transaction_id !== '' ) {
			$return_data['transaction_id'] = $transaction_id;
		}

		$payment_method = self::first_string_value( $query_args, self::PAYMENT_METHOD_KEYS );
		if ( $payment_method !== '' ) {
			$return_data['payment_method'] = $payment_method;
		}

		return $return_data;
	}

	/**
	 * @param array<string, mixed> $return_data
	 */
	public static function get_return_status( array $return_data ): string {
		foreach ( [ 'iftp_gf_pay', 'status', 'Status', 'payment_status' ] as $key ) {
			if ( empty( $return_data[ $key ] ) ) {
				continue;
			}

			return strtolower( sanitize_text_field( (string) $return_data[ $key ] ) );
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private static function get_return_transaction_id_from_request( array $request ): string {
		return self::sanitize_transaction_id( self::first_string_value( $request, self::TRANSACTION_ID_KEYS ) );
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
