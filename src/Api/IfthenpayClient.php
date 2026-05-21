<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use RuntimeException;

/**
 * Thin HTTP client for the ifthenpay API.
 *
 * This plugin only ever performs FOUR live API calls in normal operation:
 *  1. validate_backoffice_key()   — on Connect, ONE time.
 *  2. get_gateway_keys('GravityForms') — every time the Feed Settings page renders.
 *  3. get_available_methods()     — every time the Feed Settings page renders.
 *  4. create_payment_link()       — when the customer clicks Submit on a form.
 *  5. verify_transaction_paid()   — when the customer returns from the gateway.
 *
 * No catalogs are cached. The per-form snapshot saved in
 * `ifthenpay_gf_form_{form_id}` is the only persistent piece of gateway data
 * the rest of the plugin reads from.
 */
final class IfthenpayClient {

	private const API_BASE = 'https://api.ifthenpay.com';

	private string $backoffice_key;

	public function __construct( string $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}



	/**
	 * One-shot key-validity probe used by the Connect button.
	 * Treats any non-empty 2xx response as valid. Errors → false.
	 */
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



	/**
	 * Returns the gateway-key rows for this backoffice key, scoped to the
	 * "GravityForms" gateway type. Each row contains the gateway alias and the
	 * per-method account columns (Multibanco, MBWAY, CCARD, etc.).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( string $type = 'GravityForms' ): array {
		$args = [ 'boKey' => $this->backoffice_key ];

		$type = sanitize_text_field( $type );
		if ( $type !== '' ) {
			$args['type'] = $type;
		}

		return self::request( 'GET', add_query_arg( $args, self::API_BASE . '/gateway/get' ) );
	}

	/**
	 * Returns the list of all payment methods supported by ifthenpay.
	 * The caller is responsible for filtering by IsVisible.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_available_methods(): array {
		return self::request( 'GET', self::API_BASE . '/gateway/methods/available' );
	}



	/**
	 * POSTs the Pay-By-Link payload and returns the gateway response
	 * (PinpayUrl, PinCode, etc.).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
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
	 * Authoritative "did the customer actually pay" check.
	 *
	 * Hits GET /gateway/transaction/status/get?transactionId=...
	 *  • 200 OK  → returns the body, e.g. ['TransactionId' => '…', 'PaymentMethod' => '...']
	 *  • 404 Not Found → returns null (reference exists but payment NOT received yet)
	 *  • other HTTP errors → re-throws RuntimeException
	 *
	 * The success-URL redirect alone is unreliable for asynchronous methods
	 * (Multibanco, Payshop) because the customer can be redirected back after
	 * merely *generating* the reference.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify_transaction_paid( string $transaction_id ): ?array {
		$transaction_id = sanitize_text_field( trim( $transaction_id ) );
		if ( $transaction_id === '' ) {
			return null;
		}

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



	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
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
	 * @return array<string, mixed>
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
