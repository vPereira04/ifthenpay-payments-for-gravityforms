<?php

declare(strict_types=1);

/**
 * Plugin Name:       Ifthenpay | Payments for GravityForms
 * Plugin URI:        https://ifthenpay.com
 * Description:       ifthenpay Pay by Link integration for Gravity Forms.
 * Version:           1.0.0
 * Tested up to:      6.9
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-gravityforms
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_GF_VERSION', '1.0.0' );
define( 'IFTP_GF_FILE', __FILE__ );
define( 'IFTP_GF_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_GF_URL', plugin_dir_url( __FILE__ ) );
define( 'IFTP_GF_SLUG', 'iftp_gf' );

$ifthenpay_gf_dir      = plugin_dir_path( __FILE__ );
$ifthenpay_gf_autoload = $ifthenpay_gf_dir . 'vendor/autoload.php';

if ( file_exists( $ifthenpay_gf_autoload ) ) {
	require_once $ifthenpay_gf_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ) use ( $ifthenpay_gf_dir ): void {
			$prefix = 'Ifthenpay\\GravityForms\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $ifthenpay_gf_dir . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action( 'gform_loaded', [ 'Ifthenpay_GF_Bootstrap', 'load' ], 5 );

class Ifthenpay_GF_Bootstrap {

	public static function load(): void {
		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		GFForms::include_payment_addon_framework();

		require_once IFTP_GF_DIR . 'src/Addon.php';

		GFAddOn::register( \Ifthenpay\GravityForms\Addon::class );
	}
}

function ifthenpay_gf_addon(): \Ifthenpay\GravityForms\Addon {
	return \Ifthenpay\GravityForms\Addon::get_instance();
}
