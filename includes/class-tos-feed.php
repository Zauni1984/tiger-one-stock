<?php
defined( 'ABSPATH' ) || exit;

/**
 * Macht aus der Antwort des Stock Feeds eine einheitliche Artikelliste.
 *
 * Das Onboarding-Dokument beschreibt nur die Anfrage, nicht die Antwort. Deshalb
 * erkennt diese Klasse die üblichen Formen selbst: eine Liste von Objekten, ein
 * Objekt mit einer Liste darin (data/stock/products/…) oder eine flache Zuordnung
 * Artikelnummer => Menge. Erkannt wird nur, was eindeutig ist — ist die Antwort
 * unklar, bricht der Lauf mit einer Meldung ab, statt zu raten.
 */
class TOS_Feed {

	private static $code_keys  = array( 'code', 'sku', 'default_code', 'product_code', 'article_code', 'item_code', 'product_sku', 'reference', 'internal_reference', 'product_default_code' );
	private static $qty_keys   = array( 'qty', 'quantity', 'qty_available', 'available_qty', 'available_quantity', 'free_qty', 'stock', 'stock_qty', 'on_hand', 'onhand', 'available', 'virtual_available', 'qty_on_hand', 'availability', 'stock_status', 'in_stock', 'is_in_stock' );
	private static $name_keys  = array( 'name', 'product_name', 'product', 'description', 'display_name' );
	private static $brand_keys = array( 'brand', 'brand_name', 'marke' );
	private static $price_keys = array( 'price', 'list_price', 'sale_price', 'unit_price', 'retail_price' );

	/**
	 * @param mixed $payload Ausgepackte Antwort.
	 * @return array{articles:array<string,array>,shape:string,warnings:array<int,string>}|WP_Error
	 */
	public static function parse( $payload, $brand_code = '', $warehouse = '' ) {
		$err = TOS_API::payload_error( $payload );
		if ( $err !== '' ) {
			return new WP_Error( 'tos_feed_error', sprintf( 'Tiger One meldet für Brand Code %s: %s', $brand_code, $err ) );
		}

		$rows  = self::find_rows( $payload );
		$shape = $rows['shape'];
		if ( ! $rows['rows'] ) {
			return new WP_Error(
				'tos_feed_shape',
				'In der Antwort von Tiger One war keine Artikelliste zu erkennen. Die Rohantwort steht im Protokoll — damit lässt sich die Zuordnung nachziehen.'
			);
		}

		$articles = array();
		$warnings = array();
		$no_code  = 0;
		$no_qty   = 0;

		foreach ( $rows['rows'] as $key => $row ) {
			if ( ! is_array( $row ) ) {
				// Flache Form: Artikelnummer => Menge.
				$code = (string) $key;
				$qty  = self::to_qty( $row );
				$row  = array( 'value' => $row );
			} else {
				$row  = self::flatten( $row );
				$code = self::pick( $row, self::$code_keys );
				$qty  = self::pick_qty( $row );
			}
			$code = trim( (string) $code );
			if ( $code === '' ) {
				++$no_code;
				continue;
			}
			if ( $qty === null ) {
				++$no_qty;
			}
			$articles[ $code ] = array(
				'code'       => $code,
				'name'       => is_array( $row ) ? (string) self::pick( $row, self::$name_keys ) : '',
				'brand'      => is_array( $row ) ? (string) self::pick( $row, self::$brand_keys ) : '',
				'brand_code' => (string) $brand_code,
				'warehouse'  => (string) $warehouse,
				'stock'      => $qty,
				'price'      => is_array( $row ) ? self::to_number( self::pick( $row, self::$price_keys ) ) : null,
				'raw'        => $row,
			);
		}

		if ( $no_code > 0 ) {
			$warnings[] = sprintf( '%d Zeilen ohne erkennbare Artikelnummer übersprungen.', $no_code );
		}
		if ( $no_qty > 0 ) {
			$warnings[] = sprintf( '%d Artikel ohne verwertbare Bestandsangabe — diese Produkte werden nicht angetastet.', $no_qty );
		}
		if ( ! $articles ) {
			return new WP_Error( 'tos_feed_empty', 'Die Antwort enthielt Zeilen, aber keine einzige Artikelnummer.' );
		}

		return array(
			'articles' => $articles,
			'shape'    => $shape,
			'warnings' => $warnings,
		);
	}

	/** @return array{rows:array,shape:string} */
	public static function find_rows( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array(
				'rows'  => array(),
				'shape' => 'unbekannt',
			);
		}

		// a) Liste von Objekten.
		if ( self::is_list_of_rows( $payload ) ) {
			return array(
				'rows'  => $payload,
				'shape' => 'Liste von Artikeln',
			);
		}

		// b) Objekt mit einer Liste darin.
		foreach ( array( 'data', 'stock', 'stocks', 'products', 'items', 'result', 'lines', 'brand_stock' ) as $k ) {
			if ( isset( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) {
				if ( self::is_list_of_rows( $payload[ $k ] ) ) {
					return array(
						'rows'  => $payload[ $k ],
						'shape' => 'Liste unter „' . $k . '"',
					);
				}
				if ( self::is_code_map( $payload[ $k ] ) ) {
					return array(
						'rows'  => $payload[ $k ],
						'shape' => 'Artikelnummer => Menge unter „' . $k . '"',
					);
				}
			}
		}

		// c) Irgendein Schlüssel, unter dem eine Liste von Objekten liegt.
		foreach ( $payload as $k => $v ) {
			if ( is_array( $v ) && self::is_list_of_rows( $v ) ) {
				return array(
					'rows'  => $v,
					'shape' => 'Liste unter „' . $k . '"',
				);
			}
		}

		// d) Flache Zuordnung Artikelnummer => Menge.
		if ( self::is_code_map( $payload ) ) {
			return array(
				'rows'  => $payload,
				'shape' => 'Artikelnummer => Menge',
			);
		}

		// e) Ein einzelnes Artikelobjekt.
		if ( self::looks_like_row( $payload ) ) {
			return array(
				'rows'  => array( $payload ),
				'shape' => 'einzelner Artikel',
			);
		}

		return array(
			'rows'  => array(),
			'shape' => 'unbekannt',
		);
	}

	private static function is_list_of_rows( $v ) {
		if ( ! is_array( $v ) || ! $v ) {
			return false;
		}
		if ( array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			return false;
		}
		foreach ( array_slice( $v, 0, 5 ) as $row ) {
			if ( is_array( $row ) && self::looks_like_row( $row ) ) {
				return true;
			}
		}
		return false;
	}

	private static function looks_like_row( $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}
		$row = self::flatten( $row );
		return self::pick( $row, self::$code_keys ) !== '';
	}

	/**
	 * Flache Zuordnung Artikelnummer => Menge.
	 *
	 * Bewusst streng: mindestens fünf Einträge, keine verschachtelten Werte und
	 * überwiegend Zahlen als Wert. Sonst würde ein Kopfobjekt wie
	 * {"warehouse_code": "…", "brand_code": 1021} als Artikelliste durchgehen.
	 */
	private static function is_code_map( $v ) {
		if ( ! is_array( $v ) || count( $v ) < 5 ) {
			return false;
		}
		if ( array_keys( $v ) === range( 0, count( $v ) - 1 ) ) {
			return false;
		}
		$numeric = 0;
		foreach ( $v as $k => $val ) {
			if ( is_array( $val ) || (string) $k === '' ) {
				return false;
			}
			if ( is_numeric( $val ) || is_bool( $val ) ) {
				++$numeric;
			}
		}
		return $numeric >= (int) ceil( count( $v ) * 0.6 );
	}

	/** Verschachtelte Objekte eine Ebene flach ziehen (product.code => code). */
	private static function flatten( array $row ) {
		$out = array();
		foreach ( $row as $k => $v ) {
			$lk = is_string( $k ) ? strtolower( $k ) : $k;
			if ( is_array( $v ) ) {
				foreach ( $v as $k2 => $v2 ) {
					if ( is_scalar( $v2 ) || $v2 === null ) {
						$out[ strtolower( (string) $k2 ) ] = $v2;
						$out[ $lk . '_' . strtolower( (string) $k2 ) ] = $v2;
					}
				}
				continue;
			}
			$out[ $lk ] = $v;
		}
		return $out;
	}

	private static function pick( array $row, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && ! is_array( $row[ $k ] ) && trim( (string) $row[ $k ] ) !== '' ) {
				return (string) $row[ $k ];
			}
		}
		return '';
	}

	/** @return float|null */
	private static function pick_qty( array $row ) {
		foreach ( self::$qty_keys as $k ) {
			if ( array_key_exists( $k, $row ) && ! is_array( $row[ $k ] ) ) {
				$q = self::to_qty( $row[ $k ] );
				if ( $q !== null ) {
					return $q;
				}
			}
		}
		return null;
	}

	/**
	 * Menge aus Zahl oder Wort. Worte ergeben bewusst nur 0 oder 1 — eine
	 * Stückzahl wird daraus nicht erfunden.
	 *
	 * @return float|null
	 */
	public static function to_qty( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 1.0 : 0.0;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = trim( (string) $v );
		if ( $s === '' ) {
			return null;
		}
		if ( preg_match( '/^-?\d+([.,]\d+)?$/', $s ) ) {
			return (float) str_replace( ',', '.', $s );
		}
		$low = mb_strtolower( $s );
		if ( preg_match( '/(out of stock|not available|unavailable|ausverkauft|nicht verf)/u', $low ) ) {
			return 0.0;
		}
		if ( preg_match( '/(in stock|available|lieferbar|verf(ü|u)gbar|yes|ja|true)/u', $low ) ) {
			return 1.0;
		}
		return null;
	}

	/** @return float|null */
	public static function to_number( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = trim( (string) $v );
		if ( $s === '' ) {
			return null;
		}
		$s = str_replace( array( ' ', "\xc2\xa0" ), '', $s );
		if ( strpos( $s, ',' ) !== false && strpos( $s, '.' ) !== false ) {
			$s = strrpos( $s, ',' ) > strrpos( $s, '.' ) ? str_replace( '.', '', $s ) : str_replace( ',', '', $s );
		}
		$s = str_replace( ',', '.', $s );
		return is_numeric( $s ) ? (float) $s : null;
	}
}
