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

	/** Transient key for the raw /gateway/methods/available response. */
	private const AVAIL_METHODS_TRANSIENT = 'iftp_gf_avail_methods';

	/** TTL for the available-methods transient: 6 hours. */
	private const AVAIL_METHODS_TTL = 21600;

	private string $backoffice_key;

	public function __construct( string $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}

	// -------------------------------------------------------------------------
	// Available methods (global catalog) — with 6-hour transient cache 
	// -------------------------------------------------------------------------

	/**
	 * Returns the raw /gateway/methods/available data, served from a 6-hour
	 * transient so we stay in sync with IsVisible changes without hammering the API.
	 */
	public static function get_cached_available_methods(): array {
		$cached = get_transient( self::AVAIL_METHODS_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		try {
			$methods = self::get_available_methods();
		} catch ( \Throwable ) {
			return [];
		}

		if ( ! empty( $methods ) ) {
			set_transient( self::AVAIL_METHODS_TRANSIENT, $methods, self::AVAIL_METHODS_TTL );
		}

		return $methods;
	}

	/** Force-expire the available-methods transient (call after reconnect). */
	public static function bust_available_methods_cache(): void {
		delete_transient( self::AVAIL_METHODS_TRANSIENT );
	}

	public static function get_available_methods(): array {
		return self::request( 'GET', self::API_BASE . '/gateway/methods/available' );
	}

	// -------------------------------------------------------------------------
	// Method catalog builder
	// -------------------------------------------------------------------------

	/**
	 * Builds the method catalog from a raw /gateway/methods/available response.
	 *
	 * Returns an associative array keyed by uppercase entity code, e.g.:
	 *   'MB' => [
	 *     'entity'                => 'MB',
	 *     'label'                 => 'MULTIBANCO',
	 *     'small_image_url'       => 'https://...',
	 *     'small_image_url_dark'  => 'https://...',
	 *     'image_url'             => 'https://...',
	 *     'position'              => 1,
	 *     'is_visible'            => true,
	 *     'allow_selected_method' => true,
	 *   ]
	 *
	 * All methods are included regardless of IsVisible so that visibility
	 * changes are reflected at render time from the live cache.
	 *
	 * @param array<int, array<string, mixed>> $rawMethods
	 * @return array<string, array<string, mixed>>
	 */
	public static function build_method_catalog_from_raw( array $rawMethods ): array {
		$catalog = [];

		foreach ( $rawMethods as $method ) {
			if ( ! is_array( $method ) || empty( $method['Entity'] ) ) {
				continue;
			}

			$entity = strtoupper( (string) $method['Entity'] );

			$catalog[ $entity ] = [
				'entity'                => $entity,
				'label'                 => (string) ( $method['Method'] ?? $entity ),
				'small_image_url'       => (string) ( $method['SmallImageUrl'] ?? '' ),
				'small_image_url_dark'  => (string) ( $method['SmallImageUrlDark'] ?? '' ),
				'image_url'             => (string) ( $method['ImageUrl'] ?? '' ),
				'position'              => (int) ( $method['Position'] ?? 0 ),
				'is_visible'            => (bool) ( $method['IsVisible'] ?? false ),
				'allow_selected_method' => (bool) ( $method['AllowSelectedMethod'] ?? false ),
			];
		}

		// Sort by position ascending.
		uasort( $catalog, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return $catalog;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_method_catalog(): array {
		try {
			return self::build_method_catalog_from_raw( self::get_available_methods() );
		} catch ( \Throwable ) {
			return [];
		}
	}

	// -------------------------------------------------------------------------
	// Gateway catalog builder
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $rawMethods
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_gateway_method_accounts( array $row, array $available_methods ): array {
		$methods = [];

		foreach ( $available_methods as $method ) {
			if ( empty( $method['Method'] ) ) {
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

			// Store the account even if IsVisible=false — visibility is checked
			// at render time against the live method catalog (refreshed every 6 h).
			$methods[ $key ] = [
				'method'  => $method['Method'],
				'entity'  => $key,
				'account' => $value,
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
			// Use cached available methods to avoid an extra API call.
			$rawMethods = self::get_cached_available_methods();
			if ( empty( $rawMethods ) ) {
				try {
					$rawMethods = self::get_available_methods();
				} catch ( RuntimeException ) {
					$rawMethods = [];
				}
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
				'tipo'        => sanitize_text_field( (string) ( $row['Tipo'] ?? '' ) ),
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

	/**
	 * Parses the raw GetAccountsByGatewayKey response into a keyed structure:
	 * [ 'ENTITY' => [ ['label' => alias, 'account' => conta], ... ], ... ]
	 * Numeric Entidade values are normalised to 'MB'.
	 */
	public static function parse_dynamic_accounts( array $raw ): array {
		$result = [];

		foreach ( $raw as $acct ) {
			if ( empty( $acct['Alias'] ) || empty( $acct['Conta'] ) ) {
				continue;
			}

			$raw_entity = (string) ( $acct['Entidade'] ?? '' );
			$entity     = ( $raw_entity !== '' && ! is_numeric( $raw_entity ) )
				? strtoupper( $raw_entity )
				: 'MB';

			if ( ! isset( $result[ $entity ] ) ) {
				$result[ $entity ] = [];
			}

			$result[ $entity ][] = [
				'label'   => sanitize_text_field( (string) $acct['Alias'] ),
				'account' => sanitize_text_field( (string) $acct['Conta'] ),
			];
		}

		return $result;
	}

	public static function get_payment_method_by_transaction_id( string $transaction_id ): array {
		$url = add_query_arg(
			[ 'transactionId' => $transaction_id ],
			self::API_BASE . '/gateway/transaction/status/get'
		);

		return self::request( 'GET', $url );
	}

	/**
	 * Verifies that a Pay-By-Link transaction has actually been paid.
	 *
	 * Hits GET /gateway/transaction/status/get?transactionId=...
	 *  • 200 OK  → returns the response body, e.g. ['TransactionId' => '…', 'PaymentMethod' => 'MBWAY']
	 *  • 404 Not Found → returns null (transaction exists but payment is NOT yet received)
	 *  • other HTTP errors → re-throws the RuntimeException so the caller can decide
	 *
	 * This is the authoritative "did the customer actually pay" check — the success-URL
	 * redirect alone is unreliable for asynchronous methods (Multibanco, Payshop) because
	 * the customer can be redirected back after merely *generating* the payment reference.
	 */
	public static function verify_transaction_paid( string $transaction_id ): ?array {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );
		if ( $transaction_id === '' ) {
			return null;
		}

		//$transaction_id = 'HWG9lQsKJeLhjYzoCa8U';

		$url = add_query_arg(
			[ 'transactionId' => $transaction_id ],
			self::API_BASE . '/gateway/transaction/status/get'
		);

		try {
			return self::request( 'GET', $url );
		} catch ( RuntimeException $e ) {
			if ( (int) $e->getCode() === 404 ) {
				return null;
			}
			throw $e;
		}
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
