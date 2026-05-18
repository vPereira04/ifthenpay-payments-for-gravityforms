<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayPayload {

	public static function build_pay_by_link_payload( array $args ): array {
		$id          = (string) ( $args['id'] ?? '' );
		$description = sanitize_text_field( $args['description'] ?? '' );

		$payload = [
			'id'          => $id,
			'amount'      => self::format_amount( $args['amount'] ?? 0 ),
			'description' => self::build_description( $id, $description ),
			'accounts'    => (string) ( $args['accounts'] ?? '' ),
			'success_url' => $args['success_url'] ?? '',
			'error_url'   => $args['error_url'] ?? '',
			'cancel_url'  => $args['cancel_url'] ?? '',
			'otp'         => 'true',
			'lang'        => self::map_locale_to_lang( (string) ( $args['locale'] ?? get_locale() ) ),
		];

		foreach ( [ 'selected_method', 'email', 'name', 'fields' ] as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$payload[ $field ] = $args[ $field ];
		}

		return $payload;
	}

	public static function map_locale_to_lang( string $locale ): string {
		return match ( substr( strtolower( $locale ), 0, 2 ) ) {
			'pt', 'es', 'fr' => substr( strtolower( $locale ), 0, 2 ),
			default           => 'en',
		};
	}

	public static function format_amount(
		float|int|string $amount,
		int $decimals = 2,
		string $thousands_separator = ''
	): string {
		if ( ! is_numeric( $amount ) ) {
			return (string) $amount;
		}

		return number_format( (float) $amount, max( 0, $decimals ), '.', $thousands_separator );
	}

	/**
	 * Build gateway return URLs for a GravityForms entry.
	 *
	 * @return array<string, string>
	 */
	public static function build_gateway_urls( int $entry_id, int $form_id, string $base_url ): array {
		$common = [ 'entry_id' => $entry_id, 'form_id' => $form_id, 'transaction_id' => '[TRANSACTIONID]', 'iftp_gf' => 1 ];

		return [
			'success_url'  => add_query_arg( array_merge( $common, [ 'iftp_gf_return' => 'success' ] ), $base_url ),
			'error_url'    => add_query_arg( array_merge( $common, [ 'iftp_gf_return' => 'error' ] ), $base_url ),
			'cancel_url'   => add_query_arg( array_merge( $common, [ 'iftp_gf_return' => 'cancel' ] ), $base_url ),
			'callback_url' => add_query_arg( [ 'iftp_gf_callback' => 1 ], home_url( '/' ) ),
		];
	}

	public static function build_payment_status_response(
		string $status,
		string $transaction_id = '',
		string $payment_method = ''
	): array {
		$response = [ 'status' => $status ];

		if ( $transaction_id !== '' ) {
			$response['transaction_id'] = $transaction_id;
		}

		if ( $payment_method !== '' ) {
			$response['payment_method'] = $payment_method;
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $methods_config
	 */
	public static function build_accounts_string( array $methods_config ): string {
		$parts = [];

		foreach ( $methods_config as $method ) {
			if ( empty( $method['enabled'] ) ) {
				continue;
			}

			$account = isset( $method['account'] ) ? trim( (string) $method['account'] ) : '';
			if ( $account === '' ) {
				continue;
			}

			$parts[] = preg_replace( '/\s*\|\s*/', '|', $account );
		}

		return implode( ';', array_values( array_filter( $parts, static fn( $v ) => is_string( $v ) && $v !== '' ) ) );
	}

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $methods_config
	 */
	public static function get_selected_method_entity( array $config, array $methods_config ): string {
		$selected_code = self::get_selected_method_code( $config, $methods_config );

		foreach ( self::get_available_methods_from_database() as $method ) {
			if ( empty( $method['Position'] ) || empty( $method['Entity'] ) ) {
				continue;
			}

			if ( (string) $method['Position'] === $selected_code ) {
				return strtoupper( (string) $method['Entity'] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $methods_config
	 */
	public static function get_selected_method_code( array $config, array $methods_config ): string {
		$map = [];

		foreach ( self::get_available_methods_from_database() as $method ) {
			if ( empty( $method['Entity'] ) || ! isset( $method['Position'] ) ) {
				continue;
			}
			$map[ strtoupper( (string) $method['Entity'] ) ] = (string) $method['Position'];
		}

		if ( empty( $map ) ) {
			return '';
		}

		$entity = '';

		if ( ! empty( $config['default_method'] ) ) {
			$entity = strtoupper( (string) $config['default_method'] );
		}

		if ( $entity !== '' && isset( $methods_config[ $entity ] ) && ! empty( $methods_config[ $entity ]['enabled'] ) ) {
			return $map[ $entity ] ?? (string) reset( $map );
		}

		$enabled = [];
		foreach ( $methods_config as $ent => $data ) {
			if ( ! empty( $data['enabled'] ) ) {
				$enabled[] = strtoupper( (string) $ent );
			}
		}

		if ( empty( $enabled ) ) {
			return (string) reset( $map );
		}

		$best     = null;
		$best_pos = PHP_INT_MAX;

		foreach ( $enabled as $ent ) {
			if ( isset( $map[ $ent ] ) ) {
				$pos = (int) $map[ $ent ];
				if ( $pos < $best_pos ) {
					$best_pos = $pos;
					$best     = $ent;
				}
			}
		}

		if ( $best !== null ) {
			return $map[ $best ];
		}

		foreach ( $map as $ent => $pos ) {
			if ( in_array( $ent, $enabled, true ) ) {
				return $pos;
			}
		}

		return (string) reset( $map );
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function get_gateway_methods_config( array $config, string $gateway_key ): array {
		if (
			$gateway_key !== '' &&
			! empty( $config['gateway_methods'][ $gateway_key ]['methods'] ) &&
			is_array( $config['gateway_methods'][ $gateway_key ]['methods'] )
		) {
			return $config['gateway_methods'][ $gateway_key ]['methods'];
		}

		if ( ! empty( $config['methods'] ) && is_array( $config['methods'] ) ) {
			return $config['methods'];
		}

		return [];
	}

	public static function generate_customer_id(): string {
		return is_user_logged_in() ? (string) get_current_user_id() : 'guest';
	}

	private static function build_description( string $id, string $description ): string {
		if ( $id === '' ) {
			return $description;
		}

		return $description !== ''
			? sprintf( 'Order #%s - %s', $id, $description )
			: sprintf( 'Order #%s', $id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_available_methods_from_database(): array {
		$catalog = get_option( 'iftp_gf_method_catalog', [] );

		if ( ! is_array( $catalog ) ) {
			return [];
		}

		$methods = [];
		foreach ( $catalog as $method ) {
			if ( ! is_array( $method ) || empty( $method['entity'] ) ) {
				continue;
			}
			$methods[] = [
				'Entity'   => strtoupper( (string) $method['entity'] ),
				'Position' => (string) ( $method['position'] ?? 0 ),
			];
		}

		return $methods;
	}

	private function __construct() {}
}
