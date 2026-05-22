<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Read/write the per-form payment snapshot stored as a WP option.
 * Option key: ifthenpay_gf_form_{form_id}.
 */
final class FormPaymentInfo {

	private const OPTION_PREFIX = 'ifthenpay_gf_form_';

	public static function get( int $form_id ): array {
		$data = get_option( self::OPTION_PREFIX . $form_id, [] );
		return is_array( $data ) ? $data : [];
	}

	public static function save( int $form_id, array $data ): void {
		update_option( self::OPTION_PREFIX . $form_id, $data, false );
	}

	public static function delete( int $form_id ): void {
		delete_option( self::OPTION_PREFIX . $form_id );
	}

	/**
	 * Bulk-delete all per-form snapshots (called on backoffice key disconnect).
	 */
	public static function delete_all(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);
	}

	private function __construct() {}
}
