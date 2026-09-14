<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bestandsquelle: das Live-Bestands-Sheet von Tiger One.
 *
 * Tiger One pflegt den Live-Bestand in einer Google-Tabelle mit den Spalten
 * SKU, Quantity, Brand, Warehouse und Warehouse Code. Diese Klasse holt die
 * Tabelle als CSV, liest sie und macht daraus dieselbe Artikelliste, mit der
 * der Abgleich ohnehin arbeitet: Artikelnummer => Menge.
 *
 * Zwei Dinge nimmt die Klasse dem Sheet ab:
 *
 *   1. Die Adresse. Wer den Link aus dem Browser kopiert, hat eine
 *      „/edit#gid=…"-Adresse. Daraus wird selbst der CSV-Export gebaut; ein
 *      fertiger CSV-Link wird unverändert benutzt.
 *   2. Doppelte Artikelnummern. Steht eine SKU mehrfach in der Tabelle (heute
 *      ein Lager, später vielleicht mehrere), werden die Mengen standardmäßig
 *      addiert statt sich gegenseitig zu überschreiben.
 *
 * Geraten wird nichts: Zeilen ohne Artikelnummer oder ohne lesbare Menge
 * werden gezählt und übersprungen, nicht geschätzt.
 */
class TOS_Sheet {

	/** Spaltennamen, die ohne Konfiguration erkannt werden. */
	const FALLBACK_SKU       = array( 'sku', 'artikelnummer', 'code', 'default_code', 'reference' );
	const FALLBACK_QTY       = array( 'quantity', 'qty', 'menge', 'bestand', 'stock', 'available' );
	const FALLBACK_BRAND     = array( 'brand', 'marke' );
	const FALLBACK_WAREHOUSE = array( 'warehousecode', 'warehouse', 'lager', 'lagercode' );

	/* ------------------------------------------------------------ Adresse */

	/**
	 * Macht aus einer Google-Tabellen-Adresse den CSV-Export.
	 *
	 * @param string $url Adresse aus dem Browser oder ein fertiger CSV-Link.
	 * @param string $gid Optionales Tabellenblatt; überschreibt die gid aus der Adresse.
	 * @return string
	 */
	public static function csv_url( $url, $gid = '' ) {
		// &amp; / &#038; entstehen, wenn ein Link aus einer Seite kopiert wurde.
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		$gid = trim( (string) $gid );
		if ( $url === '' ) {
			return '';
		}

		// gid aus der Adresse übernehmen, wenn keine eigene gesetzt ist.
		if ( $gid === '' && preg_match( '/[#?&]gid=(\d+)/', $url, $m ) ) {
			$gid = $m[1];
		}

		// Veröffentlichte Tabelle: /spreadsheets/d/e/<token>/pubhtml
		if ( preg_match( '#^(https://docs\.google\.com/spreadsheets/d/e/[^/]+)/pub#i', $url, $m ) ) {
			return $m[1] . '/pub?output=csv' . ( $gid !== '' ? '&gid=' . rawurlencode( $gid ) : '' );
		}

		// Normale Tabelle: /spreadsheets/d/<id>/…
		if ( preg_match( '#^(https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+)#i', $url, $m ) ) {
			return $m[1] . '/export?format=csv' . ( $gid !== '' ? '&gid=' . rawurlencode( $gid ) : '' );
		}

		// Alles andere (z. B. ein direkter CSV-Link) bleibt, wie es ist.
		return $url;
	}

	/**
	 * Alle Wege, die zu derselben Tabelle führen — in der Reihenfolge, in der
	 * sie probiert werden.
	 *
	 * Warum mehrere: Google beantwortet den CSV-Export mit gid je nach Tabelle
	 * und Tageslaune mit einer Weiterleitung, die für nicht angemeldete Abrufe
	 * in HTTP 400 endet. Der gviz-Weg liefert dieselben Daten und ist davon
	 * nicht betroffen; der Export ohne gid nimmt das erste Blatt. Geprüft am
	 * 14.09.2026 an der Tiger-One-Tabelle: Export mit gid = 400,
	 * gviz mit gid = 200, Export ohne gid = 200, jeweils 10.927 Zeilen.
	 *
	 * @return array<int,array{url:string,label:string}>
	 */
	public static function candidates( $url, $gid = '' ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		$gid = trim( (string) $gid );
		if ( $url === '' ) {
			return array();
		}
		if ( $gid === '' && preg_match( '/[#?&]gid=(\d+)/', $url, $m ) ) {
			$gid = $m[1];
		}

		$out = array();

		if ( preg_match( '#^(https://docs\.google\.com/spreadsheets/d/e/[^/]+)/pub#i', $url, $m ) ) {
			$base = $m[1];
			if ( $gid !== '' ) {
				$out[] = array(
					'url'   => $base . '/pub?output=csv&gid=' . rawurlencode( $gid ),
					'label' => 'veröffentlichte Tabelle, Blatt ' . $gid,
				);
			}
			$out[] = array(
				'url'   => $base . '/pub?output=csv',
				'label' => 'veröffentlichte Tabelle',
			);
			return $out;
		}

		if ( preg_match( '#^(https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+)#i', $url, $m ) ) {
			$base = $m[1];
			if ( $gid !== '' ) {
				$out[] = array(
					'url'   => $base . '/export?format=csv&gid=' . rawurlencode( $gid ),
					'label' => 'CSV-Export, Blatt ' . $gid,
				);
				$out[] = array(
					'url'   => $base . '/gviz/tq?tqx=out:csv&gid=' . rawurlencode( $gid ),
					'label' => 'gviz-Abfrage, Blatt ' . $gid,
				);
			}
			$out[] = array(
				'url'   => $base . '/export?format=csv',
				'label' => $gid !== '' ? 'CSV-Export, erstes Blatt' : 'CSV-Export',
			);
			$out[] = array(
				'url'   => $base . '/gviz/tq?tqx=out:csv',
				'label' => 'gviz-Abfrage, erstes Blatt',
			);
			return $out;
		}

		// Fremde Adresse: unverändert benutzen.
		return array(
			array(
				'url'   => $url,
				'label' => 'direkter Link',
			),
		);
	}

	/**
	 * Probiert die Wege der Reihe nach und nimmt den ersten, der CSV liefert.
	 *
	 * @return array{body:string,url:string,label:string,attempts:array<int,array{url:string,label:string,result:string}>}|WP_Error
	 */
	public static function fetch_any( $url, $gid = '' ) {
		$list = self::candidates( $url, $gid );
		if ( ! $list ) {
			return new WP_Error( 'tos_sheet_url', 'Es ist keine Adresse für das Bestands-Sheet hinterlegt.' );
		}

		$attempts = array();
		$first_err = '';
		foreach ( $list as $c ) {
			$res = self::fetch( $c['url'] );
			if ( is_wp_error( $res ) ) {
				$attempts[] = array(
					'url'    => $c['url'],
					'label'  => $c['label'],
					'result' => $res->get_error_message(),
				);
				if ( $first_err === '' ) {
					$first_err = $res->get_error_message();
				}
				continue;
			}
			$attempts[] = array(
				'url'    => $c['url'],
				'label'  => $c['label'],
				'result' => sprintf( 'gelesen (%s)', size_format( $res['bytes'] ) ),
			);
			return array(
				'body'     => $res['body'],
				'url'      => $c['url'],
				'label'    => $c['label'],
				'bytes'    => $res['bytes'],
				'attempts' => $attempts,
			);
		}

		$lines = array();
		foreach ( $attempts as $a ) {
			$lines[] = sprintf( '%s: %s', $a['label'], $a['result'] );
		}
		return new WP_Error(
			'tos_sheet_all',
			'Keiner der Wege zur Tabelle hat funktioniert. ' . implode( ' | ', $lines )
		);
	}

	/* ------------------------------------------------------------ Holen */

	/**
	 * @param string $csv_url
	 * @return array{body:string,http:int,bytes:int}|WP_Error
	 */
	public static function fetch( $csv_url ) {
		if ( trim( (string) $csv_url ) === '' ) {
			return new WP_Error( 'tos_sheet_url', 'Es ist keine Adresse für das Bestands-Sheet hinterlegt.' );
		}

		$resp = wp_remote_get(
			$csv_url,
			array(
				'timeout'     => max( 20, (int) TOS_Settings::get( 'http_timeout', 45 ) ),
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'text/csv,text/plain,*/*' ),
				'user-agent'  => 'hanfjack-tigerone-stock-sync/' . TOS_VERSION,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$http = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		if ( $http === 401 || $http === 403 ) {
			return new WP_Error(
				'tos_sheet_auth',
				'Die Tabelle ist nicht freigegeben (HTTP ' . $http . '). In Google Tabellen unter „Freigeben" den Zugriff auf „Jeder mit dem Link – Betrachter" stellen oder die Tabelle als CSV veröffentlichen.'
			);
		}
		if ( $http < 200 || $http >= 300 ) {
			return new WP_Error( 'tos_sheet_http', 'Die Tabelle ließ sich nicht laden (HTTP ' . $http . ').' );
		}
		if ( trim( $body ) === '' ) {
			return new WP_Error( 'tos_sheet_empty', 'Die Tabelle kam leer zurück.' );
		}
		if ( stripos( ltrim( $body ), '<!doctype html' ) === 0 || stripos( ltrim( $body ), '<html' ) === 0 ) {
			return new WP_Error(
				'tos_sheet_html',
				'Unter der Adresse kam eine HTML-Seite statt CSV — meist fehlt die Freigabe oder der Link zeigt auf die Bearbeitungsansicht. Der Link muss auf den CSV-Export zeigen (…/export?format=csv&gid=…).'
			);
		}

		return array(
			'body'  => $body,
			'http'  => $http,
			'bytes' => strlen( $body ),
		);
	}

	/* ------------------------------------------------------------ Lesen */

	/**
	 * Einstellungen als Optionsfeld für parse().
	 *
	 * @return array<string,mixed>
	 */
	public static function options() {
		return array(
			'col_sku'       => (string) TOS_Settings::get( 'sheet_col_sku', 'SKU' ),
			'col_qty'       => (string) TOS_Settings::get( 'sheet_col_qty', 'Quantity' ),
			'col_brand'     => (string) TOS_Settings::get( 'sheet_col_brand', 'Brand' ),
			'col_warehouse' => (string) TOS_Settings::get( 'sheet_col_warehouse', 'Warehouse Code' ),
			'col_name'      => (string) TOS_Settings::get( 'sheet_col_name', '' ),
			'col_price'     => (string) TOS_Settings::get( 'sheet_col_price', '' ),
			'warehouse'     => (string) TOS_Settings::get( 'warehouse_filter', '' ),
			'brands'        => TOS_Settings::brand_filter(),
			'duplicates'    => (string) TOS_Settings::get( 'duplicate_mode', 'sum' ),
		);
	}

	/**
	 * CSV => Artikelliste. Bewusst ohne WordPress, damit die Regeln prüfbar bleiben.
	 *
	 * @param string $body CSV-Text.
	 * @param array  $opt  Siehe options().
	 * @return array{articles:array<string,array>,stats:array,warnings:array<int,string>,header:array}|WP_Error
	 */
	public static function parse( $body, array $opt = array() ) {
		$opt = array_merge(
			array(
				'col_sku'       => 'SKU',
				'col_qty'       => 'Quantity',
				'col_brand'     => 'Brand',
				'col_warehouse' => 'Warehouse Code',
				'col_name'      => '',
				'col_price'     => '',
				'warehouse'     => '',
				'brands'        => array(),
				'duplicates'    => 'sum',
			),
			$opt
		);

		$csv = self::read_csv( $body );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$header = $csv['header'];

		$i_sku = self::column( $header, $opt['col_sku'], self::FALLBACK_SKU );
		if ( $i_sku === null ) {
			return new WP_Error(
				'tos_sheet_col',
				sprintf(
					'In der Tabelle fehlt die Spalte mit der Artikelnummer („%s"). Gefunden wurden: %s',
					$opt['col_sku'],
					implode( ', ', $header )
				)
			);
		}
		$i_qty = self::column( $header, $opt['col_qty'], self::FALLBACK_QTY );
		if ( $i_qty === null ) {
			return new WP_Error(
				'tos_sheet_col',
				sprintf(
					'In der Tabelle fehlt die Spalte mit der Menge („%s"). Gefunden wurden: %s',
					$opt['col_qty'],
					implode( ', ', $header )
				)
			);
		}
		$i_brand = self::column( $header, $opt['col_brand'], self::FALLBACK_BRAND );
		$i_wh    = self::column( $header, $opt['col_warehouse'], self::FALLBACK_WAREHOUSE );
		$i_name  = self::column( $header, $opt['col_name'], array() );
		$i_price = self::column( $header, $opt['col_price'], array() );

		$want_wh     = self::key( (string) $opt['warehouse'] );
		$want_brands = array();
		foreach ( (array) $opt['brands'] as $b ) {
			$b = self::key( (string) $b );
			if ( $b !== '' ) {
				$want_brands[ $b ] = true;
			}
		}

		$articles   = array();
		$seen_rows  = array();
		$warehouses = array();
		$stats      = array(
			'rows'          => count( $csv['rows'] ),
			'used'          => 0,
			'no_sku'        => 0,
			'no_qty'        => 0,
			'other_ware'    => 0,
			'other_brand'   => 0,
			'duplicates'    => 0,
			'zero'          => 0,
			'delimiter'     => $csv['delimiter'],
		);

		foreach ( $csv['rows'] as $row ) {
			$code = isset( $row[ $i_sku ] ) ? trim( (string) $row[ $i_sku ] ) : '';
			// „TRUE"/„FALSE" entstehen in Tabellen durch verrutschte Formeln und
			// sind keine Artikelnummern.
			if ( $code === '' || in_array( mb_strtolower( $code ), array( 'true', 'false' ), true ) ) {
				++$stats['no_sku'];
				continue;
			}

			$wh = $i_wh !== null && isset( $row[ $i_wh ] ) ? trim( (string) $row[ $i_wh ] ) : '';
			if ( $wh !== '' ) {
				$warehouses[ $wh ] = ( $warehouses[ $wh ] ?? 0 ) + 1;
			}
			if ( $want_wh !== '' && self::key( $wh ) !== $want_wh ) {
				++$stats['other_ware'];
				continue;
			}

			$brand = $i_brand !== null && isset( $row[ $i_brand ] ) ? trim( (string) $row[ $i_brand ] ) : '';
			if ( $want_brands && ! isset( $want_brands[ self::key( $brand ) ] ) ) {
				++$stats['other_brand'];
				continue;
			}

			$qty = self::to_qty( $row[ $i_qty ] ?? '' );
			if ( $qty === null ) {
				++$stats['no_qty'];
				continue; // Ohne lesbare Menge wird nichts angefasst.
			}

			$name  = $i_name !== null && isset( $row[ $i_name ] ) ? trim( (string) $row[ $i_name ] ) : '';
			$price = $i_price !== null && isset( $row[ $i_price ] ) ? self::to_number( $row[ $i_price ] ) : null;

			$key = self::key( $code );
			if ( isset( $articles[ $key ] ) ) {
				++$stats['duplicates'];
				$articles[ $key ]['stock'] = self::merge_qty( $articles[ $key ]['stock'], $qty, $opt['duplicates'] );
				if ( $articles[ $key ]['name'] === '' && $name !== '' ) {
					$articles[ $key ]['name'] = $name;
				}
				if ( $articles[ $key ]['price'] === null && $price !== null ) {
					$articles[ $key ]['price'] = $price;
				}
				$articles[ $key ]['raw']['rows'] = ( $articles[ $key ]['raw']['rows'] ?? 1 ) + 1;
				continue;
			}

			$articles[ $key ] = array(
				'code'       => $code,
				'name'       => $name,
				'brand'      => $brand,
				'brand_code' => '',
				'warehouse'  => $wh,
				'stock'      => $qty,
				'price'      => $price,
				'raw'        => array(
					'quantity'  => $row[ $i_qty ] ?? '',
					'brand'     => $brand,
					'warehouse' => $wh,
					'rows'      => 1,
				),
			);
			$seen_rows[] = $code;
		}

		if ( ! $articles ) {
			return new WP_Error( 'tos_sheet_rows', 'In der Tabelle stand keine verwertbare Zeile (Artikelnummer und Menge).' );
		}

		// Artikelnummern in der Schreibweise der Tabelle zurückgeben.
		$out = array();
		foreach ( $articles as $a ) {
			$out[ $a['code'] ] = $a;
			if ( (float) $a['stock'] <= 0 ) {
				++$stats['zero'];
			}
		}
		$stats['used']       = count( $out );
		$stats['warehouses'] = $warehouses;

		$warnings = array();
		if ( $stats['no_sku'] > 0 ) {
			$warnings[] = sprintf( '%d Zeilen ohne Artikelnummer übersprungen.', $stats['no_sku'] );
		}
		if ( $stats['no_qty'] > 0 ) {
			$warnings[] = sprintf( '%d Zeilen ohne lesbare Menge übersprungen — diese Produkte werden nicht angetastet.', $stats['no_qty'] );
		}
		if ( $stats['other_ware'] > 0 ) {
			$warnings[] = sprintf( '%d Zeilen aus anderen Lagern übersprungen (Filter: %s).', $stats['other_ware'], $opt['warehouse'] );
		}
		if ( $stats['other_brand'] > 0 ) {
			$warnings[] = sprintf( '%d Zeilen anderer Marken übersprungen (Markenfilter aktiv).', $stats['other_brand'] );
		}
		if ( $stats['duplicates'] > 0 ) {
			$warnings[] = sprintf(
				'%d doppelte Artikelnummern zusammengefasst (%s).',
				$stats['duplicates'],
				self::duplicate_label( $opt['duplicates'] )
			);
		}

		return array(
			'articles' => $out,
			'stats'    => $stats,
			'warnings' => $warnings,
			'header'   => $header,
		);
	}

	/**
	 * Holen und lesen in einem Schritt.
	 *
	 * @return array{articles:array,stats:array,warnings:array,header:array,url:string,bytes:int}|WP_Error
	 */
	public static function load() {
		$url = TOS_Settings::sheet_url();
		if ( $url === '' ) {
			return new WP_Error( 'tos_sheet_url', 'Es ist keine Adresse für das Bestands-Sheet hinterlegt.' );
		}

		$res = self::fetch_any( $url, TOS_Settings::get( 'sheet_gid', '' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$parsed = self::parse( $res['body'], self::options() );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$parsed['url']      = $res['url'];
		$parsed['label']    = $res['label'];
		$parsed['bytes']    = $res['bytes'];
		$parsed['attempts'] = $res['attempts'];
		return $parsed;
	}

	/* ------------------------------------------------------------ Hilfen */

	private static function merge_qty( $have, $add, $mode ) {
		switch ( $mode ) {
			case 'first':
				return $have;
			case 'last':
				return $add;
			case 'max':
				return max( (float) $have, (float) $add );
			case 'sum':
			default:
				return (float) $have + (float) $add;
		}
	}

	public static function duplicate_label( $mode ) {
		$map = array(
			'sum'   => 'Mengen addiert',
			'max'   => 'größte Menge',
			'first' => 'erste Zeile gewinnt',
			'last'  => 'letzte Zeile gewinnt',
		);
		return $map[ $mode ] ?? $map['sum'];
	}

	/** @return array{header:array,rows:array,delimiter:string}|WP_Error */
	public static function read_csv( $body ) {
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $body );
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $body, 'UTF-8' ) ) {
			$body = mb_convert_encoding( $body, 'UTF-8', 'Windows-1252, ISO-8859-1' );
		}

		$first = strtok( $body, "\n" );
		if ( $first === false || trim( $first ) === '' ) {
			return new WP_Error( 'tos_sheet_csv', 'Die Tabelle enthält keine Kopfzeile.' );
		}
		$best  = ',';
		$score = -1;
		foreach ( array( ',', ';', "\t", '|' ) as $d ) {
			$n = count( str_getcsv( $first, $d ) );
			if ( $n > $score ) {
				$score = $n;
				$best  = $d;
			}
		}

		$fh = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) {
			return new WP_Error( 'tos_sheet_csv', 'Die Tabelle konnte nicht zwischengespeichert werden.' );
		}
		fwrite( $fh, $body );
		rewind( $fh );

		$header = fgetcsv( $fh, 0, $best );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			return new WP_Error( 'tos_sheet_csv', 'Die Kopfzeile ließ sich nicht lesen.' );
		}
		$header = array_map(
			static function ( $h ) {
				return trim( (string) $h );
			},
			$header
		);

		$rows = array();
		while ( ( $row = fgetcsv( $fh, 0, $best ) ) !== false ) {
			if ( $row === array( null ) ) {
				continue;
			}
			$rows[] = $row;
		}
		fclose( $fh );

		return array(
			'header'    => $header,
			'rows'      => $rows,
			'delimiter' => $best,
		);
	}

	/** Spaltenindex: erst der eingestellte Name, dann die üblichen Schreibweisen. */
	public static function column( array $header, $want, array $fallbacks = array() ) {
		$want = self::key( (string) $want );
		if ( $want !== '' ) {
			foreach ( $header as $i => $h ) {
				if ( self::key( $h ) === $want ) {
					return $i;
				}
			}
		}
		foreach ( $fallbacks as $f ) {
			foreach ( $header as $i => $h ) {
				if ( self::key( $h ) === self::key( $f ) ) {
					return $i;
				}
			}
		}
		return null;
	}

	/** Vergleichsform für Spaltennamen, Lager und Marken. */
	public static function key( $s ) {
		$s = mb_strtolower( trim( (string) $s ) );
		return preg_replace( '/[^a-z0-9äöüß]/u', '', $s );
	}

	/**
	 * Menge aus der Tabelle. Aus Worten wird keine Stückzahl erfunden:
	 * „ja/verfügbar" ergibt 1, „nein/ausverkauft" ergibt 0, alles andere null.
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
		$s = str_replace( array( ' ', "\xc2\xa0", "'" ), '', $s );
		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+,\d+$/', $s ) ) {      // 1.234,5
			$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
		} elseif ( preg_match( '/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s ) ) { // 1,234.5
			$s = str_replace( ',', '', $s );
		}
		if ( preg_match( '/^-?\d+([.,]\d+)?$/', $s ) ) {
			return (float) str_replace( ',', '.', $s );
		}
		$low = mb_strtolower( $s );
		if ( preg_match( '/(out ?of ?stock|not ?available|unavailable|ausverkauft|nicht ?verf|nein|no|false)/u', $low ) ) {
			return 0.0;
		}
		if ( preg_match( '/(in ?stock|available|lieferbar|verf(ü|u)gbar|yes|ja|true)/u', $low ) ) {
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
		$s = str_replace( array( ' ', "\xc2\xa0", '€' ), '', $s );
		if ( strpos( $s, ',' ) !== false && strpos( $s, '.' ) !== false ) {
			$s = strrpos( $s, ',' ) > strrpos( $s, '.' ) ? str_replace( '.', '', $s ) : str_replace( ',', '', $s );
		}
		$s = str_replace( ',', '.', $s );
		return is_numeric( $s ) ? (float) $s : null;
	}
}
