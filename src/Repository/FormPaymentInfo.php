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

	private const OPTION_PREFIX      = 'ifthenpay_gf_form_';
	private const FEED_OPTION_PREFIX = 'ifthenpay_gf_feed_';



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



	public static function get_for_feed( int $feed_id ): array {
		$data = get_option( self::FEED_OPTION_PREFIX . $feed_id, [] );
		return is_array( $data ) ? $data : [];
	}

	public static function save_for_feed( int $feed_id, array $data ): void {
		update_option( self::FEED_OPTION_PREFIX . $feed_id, $data, false );
	}

	public static function delete_for_feed( int $feed_id ): void {
		delete_option( self::FEED_OPTION_PREFIX . $feed_id );
	}



	/**
	 * Bulk-delete all per-form and per-feed snapshots.
	 */
	public static function delete_all(): void {
		global $wpdb;
		foreach ( [ self::OPTION_PREFIX, self::FEED_OPTION_PREFIX ] as $prefix ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}

	private function __construct() {}
}
