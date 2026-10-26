<?php
defined( 'ABSPATH' ) || exit;

/**
 * Option storage.
 *
 * Die Bestandsquelle ist das Live-Bestands-Sheet von Tiger One. Die Adresse
 * darf in der wp-config.php stehen (TIGERONE_SHEET_URL) und hat dort Vorrang
 * vor der Datenbank — praktisch, wenn Tiger One den Link später austauscht.
 */
class TOS_Settings {

	const OPTION = 'tos_settings';

	/** Vom Lieferanten gelieferte Tabelle (Blatt „Live Stock"). */
	const DEFAULT_SHEET = 'https://docs.google.com/spreadsheets/d/18ROh2V5OHN5ynysU3Cjan0k0qlURD_kn7clQ8w5nqnU/edit?gid=732713330';

	public static function defaults() {
		return array(
			// Bestandsquelle: Google-Tabelle (CSV).
			'sheet_url'           => self::DEFAULT_SHEET,
			'sheet_gid'           => '732713330',
			'sheet_col_sku'       => 'SKU',
			'sheet_col_qty'       => 'Quantity',
			'sheet_col_brand'     => 'Brand',
			'sheet_col_warehouse' => 'Warehouse Code',
			'sheet_col_name'      => '',      // die Bestandstabelle führt keine Namen
			'sheet_col_price'     => '',      // und keine Preise
			'warehouse_filter'    => '',      // leer = alle Lager aus der Tabelle
			'brand_filter'        => '',      // leer = alle Marken; sonst kommagetrennt
			'duplicate_mode'      => 'sum',   // sum | max | first | last
			'http_timeout'        => 45,

			// Optionale Artikelliste (eigene CSV) — nur für den Katalog.
			'list_url'            => '',
			'list_col_sku'        => 'sku',
			'list_col_name'       => 'name',
			'list_col_brand'      => 'brand',
			'list_col_price'      => 'retail_price',

			// Abgleich.
			'enabled'             => 0,
			'exclude_sku_prefix'  => 'HJ-',
			'interval'            => 'tos_6h',
			'buffer'              => 0,       // Sicherheitspuffer, wird vom Lieferantenbestand abgezogen.
			'threshold'           => 0,       // Bestand <= Schwelle => ausverkauft.
			'missing_action'      => 'ignore', // ignore | zero
			'backorder_mode'      => 'notify', // no | notify | yes  -> Verhalten bei Bestand 0
			'write_stock_qty'     => 1,
			'sync_variations'     => 1,       // Elternprodukte nach Variantenänderung neu berechnen
			'max_zero_ratio'      => 30,      // Notbremse in Prozent.
			'min_rows'            => 20,      // Notbremse: Mindestzahl Artikel aus der Tabelle.
			'notify_email'        => '',
			'notify_on'           => 'error', // never | error | changes
			'log_keep_days'       => 30,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function save( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
	}

	/** Adresse der Tabelle; die Konstante gewinnt. */
	public static function sheet_url() {
		if ( defined( 'TIGERONE_SHEET_URL' ) && (string) constant( 'TIGERONE_SHEET_URL' ) !== '' ) {
			return (string) constant( 'TIGERONE_SHEET_URL' );
		}
		return trim( (string) self::get( 'sheet_url', '' ) );
	}

	public static function sheet_url_is_constant() {
		return defined( 'TIGERONE_SHEET_URL' ) && (string) constant( 'TIGERONE_SHEET_URL' ) !== '';
	}

	/** Markenfilter als saubere Liste; leer heißt „alle Marken". */
	public static function brand_filter() {
		$raw = (string) self::get( 'brand_filter', '' );
		$out = array();
		foreach ( preg_split( '/[;,\n]+/', $raw ) as $b ) {
			$b = trim( (string) $b );
			if ( $b !== '' ) {
				$out[ $b ] = $b;
			}
		}
		return array_values( $out );
	}
}
