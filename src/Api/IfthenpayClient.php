<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use RuntimeException;

final class IfthenpayClient {

	private const API_BASE    = 'https://api.ifthenpay.com';
	private const MOBILE_BASE = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx';

	private string $backoffice_key;

	public function __construct( string $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}

	public static function validate_backoffice_key( string $backoffice_key ): bool {
		$backoffice_key = sanitize_text_field( $backoffice_key );

		if ( $backoffice_key === '' ) {
			return false;
		}

		$url = add_query_arg( [ 'boKey' => $backoffice_key ], self::API_BASE . '/gateway/get' );

		try {
			$data = self::request( 'GET', $url );
			return ! empty( $data );
		} catch ( RuntimeException ) {
			return false;
		}
	}

	public static function get_available_methods(): array {
		return self::request( 'GET', self::API_BASE . '/gateway/methods/available' );
	}

	/**
	 * @param array<int, array<string, mixed>> $rawMethods
	 * @return array<int, array<string, string>>
	 */
	public static function build_method_catalog_from_raw( array $rawMethods ): array {
		$catalog = [];
		foreach ( $rawMethods as $method ) {
			if ( ! is_array( $method ) || empty( $method['Entity'] ) || empty( $method['IsVisible'] ) ) {
				continue;
			}
			$catalog[] = [
				'entity'   => strtoupper( (string) $method['Entity'] ),
				'label'    => isset( $method['Method'] ) ? (string) $method['Method'] : (string) $method['Entity'],
				'logo'     => isset( $method['SmallImageUrl'] ) ? (string) $method['SmallImageUrl'] : '',
				'position' => (int) ( $method['Position'] ?? 0 ),
			];
		}
		return $catalog;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public static function get_method_catalog(): array {
		try {
			return self::build_method_catalog_from_raw( self::get_available_methods() );
		} catch ( \Throwable ) {
			return [];
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, array<string, mixed>> $available_methods
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_gateway_method_accounts( array $row, array $available_methods ): array {
		$methods = [];

		foreach ( $available_methods as $method ) {
			if ( empty( $method['IsVisible'] ) || empty( $method['Method'] ) ) {
				continue;
			}

			$key = sanitize_text_field( $method['Entity'] ?? '' );
			if ( $key === '' ) {
				continue;
			}

			$value = self::get_gateway_method_account_value( $row, $method );
			if ( $value === '' ) {
				continue;
			}

			$methods[ $key ] = [
				'method'     => $method['Method'],
				'entity'     => $key,
				'account'    => $value,
				'is_visible' => (bool) $method['IsVisible'],
			];
		}

		return $methods;
	}

	private static function get_gateway_method_account_value( array $row, array $method ): string {
		$candidates = array_filter(
			array_unique(
				array_map(
					'strval',
					[
						$method['Entity'] ?? '',
						$method['Method'] ?? '',
						strtoupper( (string) ( $method['Entity'] ?? '' ) ),
						strtoupper( (string) ( $method['Method'] ?? '' ) ),
						strtolower( (string) ( $method['Entity'] ?? '' ) ),
						strtolower( (string) ( $method['Method'] ?? '' ) ),
					]
				)
			),
			static fn( string $key ): bool => trim( $key ) !== ''
		);

		$entity = strtoupper( (string) ( $method['Entity'] ?? '' ) );
		$label  = strtoupper( (string) ( $method['Method'] ?? '' ) );

		if ( $entity === 'MB' || $label === 'MULTIBANCO' ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ( $candidates as $candidate ) {
			if ( ! array_key_exists( $candidate, $row ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $row[ $candidate ] );
			if ( trim( $value ) !== '' ) {
				return $value;
			}
		}

		return '';
	}

	public function get_gateway_keys( string $type = '' ): array {
		$args = [ 'boKey' => $this->backoffice_key ];

		$type = sanitize_text_field( $type );
		if ( $type !== '' ) {
			$args['Type'] = $type;
		}

		return self::request( 'GET', add_query_arg( $args, self::API_BASE . '/gateway/get' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $rawMethods
	 */
	public static function fetch_gateway_catalog( string $backofficeKey, array $rawMethods = [] ): array {
		$backofficeKey = trim( sanitize_text_field( $backofficeKey ) );
		if ( $backofficeKey === '' ) {
			return [];
		}

		try {
			$catalog = ( new self( $backofficeKey ) )->get_gateway_catalog( $rawMethods );
		} catch ( \Throwable ) {
			return [];
		}

		return is_array( $catalog ) ? $catalog : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $rawMethods
	 */
	public function get_gateway_catalog( array $rawMethods = [] ): array {
		$rows = $this->get_gateway_keys( '' );

		if ( empty( $rawMethods ) ) {
			try {
				$rawMethods = self::get_available_methods();
			} catch ( RuntimeException ) {
				$rawMethods = [];
			}
		}

		$catalog = [];

		foreach ( $rows as $row ) {
			if ( empty( $row['GatewayKey'] ) ) {
				continue;
			}

			$key = sanitize_text_field( $row['GatewayKey'] );
			if ( $key === '' ) {
				continue;
			}

			$alias         = sanitize_text_field( $row['Alias'] ?? '' );
			$catalog[$key] = [
				'gateway_key' => $key,
				'alias'       => $alias,
				'label'       => $alias !== '' ? $alias : $key,
				'methods'     => self::build_gateway_method_accounts( $row, $rawMethods ),
			];
		}

		return $catalog;
	}

	public function get_gateway_accounts( string $gateway_key ): array {
		$url = add_query_arg(
			[
				'backofficekey' => $this->backoffice_key,
				'gatewayKey'    => sanitize_text_field( $gateway_key ),
			],
			self::MOBILE_BASE . '/GetAccountsByGatewayKey'
		);

		return self::request( 'GET', $url );
	}

	public static function get_payment_method_by_transaction_id( string $transaction_id ): array {
		$url = add_query_arg(
			[ 'transactionId' => $transaction_id ],
			self::API_BASE . '/gateway/transaction/status/get'
		);

		return self::request( 'GET', $url );
	}

	public static function create_payment_link( string $gateway_key, array $payload ): array {
		$url = rtrim( self::API_BASE, '/' ) . '/gateway/pinpay/' . rawurlencode( $gateway_key );

		return self::request(
			'POST',
			$url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);
	}

	/**
	 * @throws RuntimeException
	 */
	private static function request(
		string $method,
		string $url,
		array $args = [],
		int $timeout = 20
	): array {
		$args = wp_parse_args( $args, [ 'timeout' => $timeout, 'sslverify' => true ] );

		$response = strtoupper( $method ) === 'POST'
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf( 'Ifthenpay API error (%s): %s', esc_html( (string) $code ), esc_html( mb_substr( $body, 0, 300 ) ) ),
				(int) $code
			);
		}

		return self::decode( $body );
	}

	/**
	 * @throws RuntimeException
	 */
	private static function decode( string $body ): array {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new RuntimeException( 'Invalid JSON response from Ifthenpay API.' );
		}

		if ( isset( $data['d'] ) ) {
			$data = is_string( $data['d'] ) ? json_decode( $data['d'], true ) : $data['d'];
		}

		if ( ! is_array( $data ) ) {
			return [ 'data' => $data ];
		}

		return $data;
	}
}
