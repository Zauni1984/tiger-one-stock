<?php
/**
 * Plugin Name: Tiger One Stock Sync
 * Plugin URI:  https://hanfjack.de/
 * Description: Hält den Warenbestand der Tiger-One-Artikel aktuell. Der Bestand kommt direkt aus dem Tiger-One-ERP (Stock Feed je Marke), der Abgleich läuft über die Artikelnummer (SKU) — auch auf Variantenebene. Zusätzlich wird ein Katalog aller gesehenen Tiger-One-Artikelnummern geführt.
 * Version:     1.0.0
 * Author:      hanfjack.de
 * Text Domain: tigerone-stock-sync
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TOS_VERSION', '1.0.0' );
define( 'TOS_FILE', __FILE__ );
define( 'TOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TOS_URL', plugin_dir_url( __FILE__ ) );

/** Meta key for an article code that differs from the product SKU. */
define( 'TOS_META_CODE', '_tigerone_code' );
/** Meta key that takes a single product out of the sync. */
define( 'TOS_META_EXCLUDE', '_tigerone_exclude' );

spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'TOS_' ) !== 0 ) {
			return;
		}
		$file = TOS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'TOS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TOS_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p><strong>Tiger One Stock Sync</strong> benötigt WooCommerce.</p></div>';
				}
			);
			return;
		}
		TOS_Install::maybe_upgrade();
		TOS_Cron::init();
		if ( is_admin() ) {
			TOS_Admin::init();
			TOS_Product_Fields::init();
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TOS_FILE, true );
		}
	}
);
