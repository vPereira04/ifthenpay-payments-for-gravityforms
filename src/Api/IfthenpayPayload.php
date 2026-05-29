<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayPayload {

	public static function build_pay_by_link_payload( array $entry, array $submission_data, array $form_info ): array {
		if ( empty( $entry['id'] ) || empty( $submission_data['payment_amount'] ) || empty( $form_info['pay_methods'] ) ) {
			throw new \InvalidArgumentException( 'Missing required payload data: entry id, payment amount or pay methods.' );
		}

		$urls = self::build_gateway_urls( $entry['id'] );
		['accounts' => $accounts, 'selected_method' => $selected_method] = self::build_accounts_string(
			$form_info['pay_methods'],
			strtoupper( $form_info['default_method'] ?? '' )
		);

		return array(
			'id'              => $entry['id'],
			'amount'          => (string) $submission_data['payment_amount'],
			'description'     => self::build_description( $entry['id'], sanitize_text_field( $form_info['pay_description'] ?? '' ) ),
			'lang'            => self::map_locale_to_lang( get_locale() ),
			'expiredate'      => self::default_expiredate( max( 1, (int) ( $form_info['expire_days'] ?? 1 ) ) ),
			'accounts'        => $accounts,
			'success_url'     => $urls['success_url'],
			'error_url'       => $urls['error_url'],
			'cancel_url'      => $urls['cancel_url'],
			'btnCloseLabel'   => 'TEST PAYED',
			'btnCloseUrl'     => 'https://localhost/aWZ0cF90Jm9fZ2Zf?callback=iftp_gf&ref=' . $entry['id'] . '&apk=VVFURy04NTU3Mzc=&val=' . (string) $submission_data['payment_amount'],
			'selected_method' => $selected_method,
			'otp'             => 'true',
		);
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

	/**
	 * Build gateway return URLs for a GravityForms entry.
	 *
	 * Adds query args directly to the page URL where the form was embedded —
	 * the gateway substitutes `[TRANSACTIONID]` with the real id on redirect.
	 *
	 * @return array{success_url:string, error_url:string, cancel_url:string}
	 */
	private static function build_gateway_urls( string $entry_id ): array {
		$base_url = home_url( '/' );

		$common = array(
			'id'             => $entry_id,
			'transaction_id' => '[TRANSACTIONID]',
			'iftp_gateway'   => 1,
		);

		return array(
			'success_url' => add_query_arg( array_merge( array( 'iftp_gf_pay' => 'success' ), $common ), $base_url ),
			'error_url'   => add_query_arg( array_merge( array( 'iftp_gf_pay' => 'error' ), $common ), $base_url ),
			'cancel_url'  => add_query_arg( array_merge( array( 'iftp_gf_pay' => 'cancel' ), $common ), $base_url ),
		);
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
	 * Returns the CDN fallback logo URL for a payment method entity.
	 * Used when the API-provided image_url is absent.
	 */
	public static function fallback_logo_url( string $entity ): string {
		return 'https://gateway.ifthenpay.com/plugins/logotipos/small/' . strtolower( $entity ) . '.png';
	}

	/**
	 * @return array{accounts: string, selected_method: string}
	 */
	private static function build_accounts_string( array $pay_methods, string $default_method ): array {
		$parts             = array();
		$selected_position = 0;

		foreach ( $pay_methods as $method ) {
			if ( empty( $method['is_active'] ) ) {
				continue;
			}
			$entity   = strtoupper( (string) ( $method['entity'] ?? '' ) );
			$acct     = trim( (string) ( $method['account'] ?? '' ) );
			$position = abs( (int) ( $method['position'] ?? 0 ) );

			if ( $entity === '' || $acct === '' || $position === 0 ) {
				continue;
			}
			$parts[] = preg_replace( '/\s*\|\s*/', '|', $acct );

			if ( $entity === $default_method ) {
				$selected_position = $position;
			}
		}

		return array(
			'accounts'        => implode( ';', $parts ),
			'selected_method' => $selected_position > 0 ? (string) $selected_position : '',
		);
	}

	private function __construct() {}
}
