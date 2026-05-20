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
			'lang'        => self::map_locale_to_lang( (string) ( $args['locale'] ?? get_locale() ) ),
			'expiredate'  => self::default_expiredate(),
			'accounts'    => (string) ( $args['accounts'] ?? '' ),
			'success_url' => $args['success_url'] ?? '',
			'error_url'   => $args['error_url'] ?? '',
			'cancel_url'  => $args['cancel_url'] ?? '',
			'otp'         => 'true',
		];

		foreach ( [ 'selected_method', 'email', 'name', 'fields' ] as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$payload[ $field ] = $args[ $field ];
		}

		return $payload;
	}

	/**
	 * Default Pay-By-Link expiry: 24 hours from now, formatted as YYYYMMDD.
	 */
	public static function default_expiredate( int $days_from_now = 1 ): string {
		return gmdate( 'Ymd', time() + ( max( 1, $days_from_now ) * DAY_IN_SECONDS ) );
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
	 * Adds query args directly to the page URL where the form was embedded —
	 * the gateway substitutes `[TRANSACTIONID]` with the real id on redirect.
	 *
	 * @return array{success_url:string, error_url:string, cancel_url:string}
	 */
	public static function build_gateway_urls( int $payment_id, string $base_url ): array {
		$common = [
			'id'             => $payment_id,
			'transaction_id' => '[TRANSACTIONID]',
			'iftp_gateway'   => 1,
		];

		return [
			'success_url' => add_query_arg( array_merge( [ 'iftp_gf_pay' => 'success' ], $common ), $base_url ),
			'error_url'   => add_query_arg( array_merge( [ 'iftp_gf_pay' => 'error'   ], $common ), $base_url ),
			'cancel_url'  => add_query_arg( array_merge( [ 'iftp_gf_pay' => 'cancel'  ], $common ), $base_url ),
		];
	}

	private static function build_description( string $id, string $description ): string {
		if ( $id === '' ) {
			return $description;
		}

		return $description !== ''
			? sprintf( 'Order #%s - %s', $id, $description )
			: sprintf( 'Order #%s', $id );
	}

	private function __construct() {}
}
