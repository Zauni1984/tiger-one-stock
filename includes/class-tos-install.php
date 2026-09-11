<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database tables and lifecycle.
 */
class TOS_Install {

	const DB_VERSION = '1';

	public static function articles_table() {
		global $wpdb;
		return $wpdb->prefix . 'tigerone_articles';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'tigerone_log';
	}

	public static function activate() {
		self::create_tables();
		update_option( 'tos_db_version', self::DB_VERSION );
		TOS_Cron::reschedule();
	}

	public static function deactivate() {
		TOS_Cron::unschedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tos_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'tos_db_version', self::DB_VERSION );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$a       = self::articles_table();
		$l       = self::log_table();

		dbDelta(
			"CREATE TABLE {$a} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(191) NOT NULL,
				name VARCHAR(255) NOT NULL DEFAULT '',
				brand VARCHAR(191) NOT NULL DEFAULT '',
				brand_code VARCHAR(64) NOT NULL DEFAULT '',
				warehouse VARCHAR(64) NOT NULL DEFAULT '',
				stock DECIMAL(14,3) DEFAULT NULL,
				price DECIMAL(14,4) DEFAULT NULL,
				raw LONGTEXT NULL,
				first_seen DATETIME NULL,
				last_seen DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY brand_code (brand_code),
				KEY last_seen (last_seen)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$l} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id VARCHAR(32) NOT NULL DEFAULT '',
				ts DATETIME NULL,
				level VARCHAR(10) NOT NULL DEFAULT 'info',
				msg TEXT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY ts (ts)
			) {$charset};"
		);
	}
}
