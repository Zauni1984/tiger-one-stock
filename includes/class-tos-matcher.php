<?php
defined( 'ABSPATH' ) || exit;

/**
 * Findet die Shop-Produkte zu einer Tiger-One-Artikelnummer.
 *
 * Abgeglichen wird über die SKU — Produkte und Varianten gleichberechtigt, denn
 * bei Tiger One trägt in der Regel die Variante die Lieferanten-Artikelnummer
 * (z. B. "BS-SBSF050001-10"), während das Elternprodukt die Parent-SKU trägt.
 *
 * Eigenbestand trägt eine "HJ-…"-SKU und wird grundsätzlich übersprungen. Diese
 * Sperre ist hart: Eine Lieferantenliste kann eigenen Bestand nie umstellen.
 */
class TOS_Matcher {

	/** @var array<string,array<int,array{pid:int,parent:int,type:string,via:string,sku:string}>>|null */
	private static $cache = null;

	public static function flush() {
		self::$cache = null;
	}

	/** Artikelnummer (normalisiert) => Liste der passenden Beiträge. */
	public static function map() {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		global $wpdb;
		$map    = array();
		$prefix = trim( (string) TOS_Settings::get( 'exclude_sku_prefix', 'HJ-' ) );

		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value AS sku, p.post_type, p.post_parent
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku'
			   AND pm.meta_value <> ''
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')"
		);
		foreach ( $rows as $r ) {
			$sku = trim( (string) $r->sku );
			if ( $sku === '' ) {
				continue;
			}
			if ( $prefix !== '' && stripos( $sku, $prefix ) === 0 ) {
				continue; // Eigenbestand — nie über den Lieferanten steuern.
			}
			$map[ self::key( $sku ) ][] = array(
				'pid'    => (int) $r->post_id,
				'parent' => (int) $r->post_parent,
				'type'   => (string) $r->post_type,
				'via'    => 'sku',
				'sku'    => $sku,
			);
		}

		// Ausnahme: abweichende Artikelnummer direkt am Produkt hinterlegt.
		$overrides = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS code, p.post_type, p.post_parent
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value <> ''
				   AND p.post_status NOT IN ('trash','auto-draft')",
				TOS_META_CODE
			)
		);
		foreach ( $overrides as $r ) {
			$map[ self::key( $r->code ) ][] = array(
				'pid'    => (int) $r->post_id,
				'parent' => (int) $r->post_parent,
				'type'   => (string) $r->post_type,
				'via'    => 'meta',
				'sku'    => (string) $r->code,
			);
		}

		self::$cache = $map;
		return $map;
	}

	public static function for_code( $code ) {
		$map = self::map();
		return $map[ self::key( $code ) ] ?? array();
	}

	public static function matched_count( array $codes ) {
		$map = self::map();
		$n   = 0;
		foreach ( $codes as $c ) {
			if ( isset( $map[ self::key( $c ) ] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Alle je gesehenen Tiger-One-Artikelnummern.
	 *
	 * Daran erkennt der Abgleich Tiger-One-Ware: Nur eine Artikelnummer, die
	 * mindestens einmal in einem Feed stand, gilt als Tiger-One-Artikel. Fällt
	 * sie später aus dem Feed, lässt sich das erkennen, ohne Produkte anderer
	 * Lieferanten anzufassen.
	 */
	public static function catalogue_keys() {
		global $wpdb;
		$rows = $wpdb->get_col( 'SELECT code FROM ' . TOS_Install::articles_table() );
		$out  = array();
		foreach ( $rows as $c ) {
			$out[ self::key( $c ) ] = true;
		}
		return $out;
	}

	public static function is_excluded( $pid ) {
		return get_post_meta( $pid, TOS_META_EXCLUDE, true ) === 'yes';
	}

	public static function key( $s ) {
		return mb_strtolower( trim( (string) $s ) );
	}
}
