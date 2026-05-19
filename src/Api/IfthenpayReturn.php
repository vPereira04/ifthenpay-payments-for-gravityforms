<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayReturn {

	public const TRANSACTION_ID_KEYS = [ 'iftp_gf_transaction_id', 'transaction_id', 'transactionId' ];
	public const REQUEST_ID_KEYS     = [ 'request_id', 'requestId', 'RequestId', 'RequestID' ];
	public const PAYMENT_METHOD_KEYS = [ 'payment_method', 'paymentMethod', 'PaymentMethod', 'method', 'method_type', 'Method' ];
	private const SUCCESS_RETURN_STATUSES = [ 'success', 'completed', 'paid', 'ok' ];

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
	 * Resolve the normalized payment return context from GF gateway return params.
	 *
	 * @param array<string, mixed> $return_data
	 * @return array{transaction_id: string, payment_method: string, successful: bool}
	 */
	public static function resolve_return_context( array $return_data ): array {
		$transaction_id = self::get_return_transaction_id_from_payload( $return_data );
		if ( $transaction_id === '' ) {
			$transaction_id = self::extract_transaction_id_from_request();
		}

		$payment_method = self::first_string_value( $return_data, self::PAYMENT_METHOD_KEYS );

		if ( $payment_method === '' && $transaction_id !== '' ) {
			$payment_method = self::payment_method_from_transaction_status( $transaction_id );
		}

		return [
			'transaction_id' => $transaction_id,
			'payment_method' => $payment_method,
			'successful'     => self::is_successful_return( $return_data ),
		];
	}

	/**
	 * Read and normalize the GF gateway return params from the current request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_return_data_from_request(): array {
		$query_args = wp_unslash( filter_input_array( INPUT_GET ) ?: [] );

		// Required: iftp_gf_pay status flag + iftp_gateway sentinel
		// (matches build_gateway_urls()).
		if ( empty( $query_args ) || empty( $query_args['iftp_gf_pay'] ) || empty( $query_args['iftp_gateway'] ) ) {
			return [];
		}

		$return_data = [
			'iftp_gf_pay' => sanitize_text_field( (string) $query_args['iftp_gf_pay'] ),
		];

		// `id` is the GF entry ID (passed as $payment_id to build_gateway_urls).
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
	public static function is_successful_return( array $return_data ): bool {
		if ( empty( $return_data ) ) {
			return false;
		}

		foreach ( [ 'iftp_gf_pay', 'status', 'Status', 'payment_status' ] as $key ) {
			if ( ! empty( $return_data[ $key ] ) ) {
				$status = strtolower( sanitize_text_field( (string) $return_data[ $key ] ) );
				if ( in_array( $status, self::SUCCESS_RETURN_STATUSES, true ) ) {
					return true;
				}
			}
		}

		return false;
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

	public static function payment_method_from_transaction_status( string $transaction_id ): string {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );
		if ( $transaction_id === '' ) {
			return '';
		}

		try {
			$response = IfthenpayClient::get_payment_method_by_transaction_id( $transaction_id );
			return is_array( $response ) ? self::first_string_value( $response, self::PAYMENT_METHOD_KEYS ) : '';
		} catch ( \RuntimeException ) {
			return '';
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function get_return_transaction_id_from_payload( array $payload ): string {
		return self::sanitize_transaction_id( self::first_string_value( $payload, self::TRANSACTION_ID_KEYS ) );
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private static function get_return_transaction_id_from_request( array $request ): string {
		return self::sanitize_transaction_id( self::first_string_value( $request, self::TRANSACTION_ID_KEYS ) );
	}

	private static function extract_transaction_id_from_request(): string {
		$get_data  = wp_unslash( filter_input_array( INPUT_GET ) ?: [] );
		$post_data = wp_unslash( filter_input_array( INPUT_POST ) ?: [] );

		foreach ( [ $get_data, $post_data ] as $source ) {
			foreach ( self::TRANSACTION_ID_KEYS as $key ) {
				if ( empty( $source[ $key ] ) ) {
					continue;
				}

				$value = sanitize_text_field( $source[ $key ] );
				if ( $value !== '' && ! str_contains( $value, '[' ) ) {
					return $value;
				}
			}
		}

		return '';
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
