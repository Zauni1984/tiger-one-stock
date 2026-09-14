<?php
defined( 'ABSPATH' ) || exit;

class TOS_Admin {

	const CAP = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_tos_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tos_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_tos_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_tos_list', array( __CLASS__, 'handle_list' ) );
	}

	public static function menu() {
		add_menu_page( 'Tiger One', 'Tiger One', self::CAP, 'tos', array( __CLASS__, 'page_settings' ), 'dashicons-clipboard', 57 );
		add_submenu_page( 'tos', 'Einstellungen', 'Einstellungen', self::CAP, 'tos', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'tos', 'Artikelkatalog', 'Artikelkatalog', self::CAP, 'tos-catalog', array( __CLASS__, 'page_catalog' ) );
		add_submenu_page( 'tos', 'Protokoll', 'Protokoll', self::CAP, 'tos-log', array( __CLASS__, 'page_log' ) );
	}

	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
	}

	private static function back( $page, $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/* ============================================================ Speichern */

	public static function handle_save() {
		self::guard();
		check_admin_referer( 'tos_save' );
		$in  = wp_unslash( $_POST );
		$out = array();

		foreach ( array( 'sheet_gid', 'sheet_col_sku', 'sheet_col_qty', 'sheet_col_brand', 'sheet_col_warehouse', 'sheet_col_name', 'sheet_col_price', 'warehouse_filter', 'brand_filter', 'duplicate_mode', 'interval', 'missing_action', 'backorder_mode', 'notify_on', 'exclude_sku_prefix', 'list_col_sku', 'list_col_name', 'list_col_brand', 'list_col_price' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'sheet_url', 'list_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( trim( $in[ $k ] ) );
			}
		}
		if ( isset( $in['notify_email'] ) ) {
			$out['notify_email'] = sanitize_email( $in['notify_email'] );
		}
		foreach ( array( 'enabled', 'write_stock_qty', 'sync_variations' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? 1 : 0;
		}
		foreach ( array( 'buffer', 'threshold', 'max_zero_ratio', 'min_rows', 'log_keep_days', 'http_timeout' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, (float) str_replace( ',', '.', $in[ $k ] ) );
			}
		}
		if ( ! in_array( $out['duplicate_mode'] ?? 'sum', array( 'sum', 'max', 'first', 'last' ), true ) ) {
			$out['duplicate_mode'] = 'sum';
		}

		TOS_Settings::save( $out );
		TOS_Cron::reschedule();
		self::back( 'tos', array( 'saved' => 1 ) );
	}

	/* ============================================================ Verbindung testen */

	public static function handle_test() {
		self::guard();
		check_admin_referer( 'tos_test' );

		$url = TOS_Settings::sheet_url();
		$out = array( 'url' => TOS_Sheet::csv_url( $url, TOS_Settings::get( 'sheet_gid', '' ) ) );

		$sheet = TOS_Sheet::load();
		if ( is_wp_error( $sheet ) ) {
			$out['error'] = $sheet->get_error_message();
			set_transient( 'tos_test_result', $out, 900 );
			self::back( 'tos', array( 'tested' => 1 ) );
		}

		TOS_Matcher::flush();
		$out['header']     = $sheet['header'];
		$out['stats']      = $sheet['stats'];
		$out['warnings']   = $sheet['warnings'];
		$out['bytes']      = $sheet['bytes'];
		$out['articles']   = (int) $sheet['stats']['used'];
		$out['matched']    = TOS_Matcher::matched_count( array_keys( $sheet['articles'] ) );
		$out['sample']     = array_slice( $sheet['articles'], 0, 8 );
		$out['warehouses'] = (array) ( $sheet['stats']['warehouses'] ?? array() );

		set_transient( 'tos_test_result', $out, 900 );
		self::back( 'tos', array( 'tested' => 1 ) );
	}

	/* ============================================================ Artikelliste */

	public static function handle_list() {
		self::guard();
		check_admin_referer( 'tos_list' );
		$res = TOS_List::import();
		set_transient(
			'tos_list_result',
			is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : $res,
			900
		);
		self::back( 'tos', array( 'listed' => 1 ) );
	}

	/* ============================================================ Lauf */

	public static function handle_run() {
		self::guard();
		check_admin_referer( 'tos_run' );
		$dry = ! empty( $_POST['dry'] );
		set_transient( 'tos_run_result', TOS_Sync::run( $dry ), 900 );
		self::back( 'tos-log', array( 'ran' => 1 ) );
	}

	/* ============================================================ Seiten */

	public static function page_settings() {
		self::guard();
		$s    = TOS_Settings::all();
		$test = get_transient( 'tos_test_result' );
		$list = get_transient( 'tos_list_result' );
		$next = wp_next_scheduled( TOS_Cron::HOOK );
		require TOS_PATH . 'views/settings.php';
	}

	public static function page_catalog() {
		self::guard();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per    = 50;
		TOS_Matcher::flush();
		$data   = TOS_Catalog::query( $search, $filter, $per, ( $paged - 1 ) * $per );
		$counts = TOS_Catalog::counts();
		require TOS_PATH . 'views/catalog.php';
	}

	public static function page_log() {
		self::guard();
		$run  = get_transient( 'tos_run_result' );
		$last = get_option( 'tos_last_run', array() );
		$rows = TOS_Logger::recent( 200 );
		require TOS_PATH . 'views/log.php';
	}
}
