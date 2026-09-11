<?php
defined( 'ABSPATH' ) || exit;

class TOS_Cron {

	const HOOK = 'tos_sync_event';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'update_option_' . TOS_Settings::OPTION, array( __CLASS__, 'reschedule' ) );
	}

	public static function schedules( $s ) {
		$s['tos_1h']  = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => 'Jede Stunde (24× täglich)',
		);
		$s['tos_2h']  = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => 'Alle 2 Stunden (12× täglich)',
		);
		$s['tos_4h']  = array(
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => 'Alle 4 Stunden (6× täglich)',
		);
		$s['tos_6h']  = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Alle 6 Stunden (4× täglich)',
		);
		$s['tos_12h'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => 'Alle 12 Stunden (2× täglich)',
		);
		return $s;
	}

	public static function reschedule() {
		self::unschedule();
		if ( ! TOS_Settings::get( 'enabled', 0 ) ) {
			return;
		}
		$interval = TOS_Settings::get( 'interval', 'tos_6h' );
		if ( ! isset( self::schedules( array() )[ $interval ] ) && ! in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			$interval = 'tos_6h';
		}
		wp_schedule_event( time() + 300, $interval, self::HOOK );
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	public static function run() {
		if ( ! TOS_Settings::get( 'enabled', 0 ) ) {
			return;
		}
		if ( get_transient( 'tos_running' ) ) {
			return;
		}
		set_transient( 'tos_running', 1, 15 * MINUTE_IN_SECONDS );
		try {
			TOS_Sync::run( false );
		} finally {
			delete_transient( 'tos_running' );
		}
	}
}
