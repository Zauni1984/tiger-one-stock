<?php
defined( 'ABSPATH' ) || exit;

/**
 * Optional: die öffentliche Tiger-One-Artikelliste (CSV).
 *
 * Die Liste enthält Namen, Marke und Preise, aber keinen Bestand. Sie wird
 * deshalb nur in den Katalog dieses Plugins geschrieben — niemals in Produkte.
 * Nützlich ist sie für zwei Dinge: neue Artikel erkennen, die es im Shop noch
 * nicht gibt, und Klartextnamen im Katalog, auch wenn der Stock Feed nur
 * Artikelnummern liefert.
 */
class TOS_List {

	/** @return array{articles:int,new:int,rows:int,header:array}|WP_Error */
	public static function import() {
		$url = trim( (string) TOS_Settings::get( 'list_url', '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'tos_list_url', 'Es ist keine Adresse für die Artikelliste hinterlegt.' );
		}

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => max( 20, (int) TOS_Settings::get( 'http_timeout', 45 ) ),
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'text/csv,text/plain,*/*' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'tos_list_http', 'Die Artikelliste ließ sich nicht laden (HTTP ' . $code . ').' );
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( trim( $body ) === '' ) {
			return new WP_Error( 'tos_list_empty', 'Die Artikelliste ist leer.' );
		}
		if ( stripos( ltrim( $body ), '<!doctype html' ) === 0 || stripos( ltrim( $body ), '<html' ) === 0 ) {
			return new WP_Error( 'tos_list_html', 'Unter der Adresse liegt eine HTML-Seite statt einer CSV-Datei. Bei Google-Tabellen muss der Link auf den CSV-Export zeigen (…/export?format=csv).' );
		}

		$csv = self::parse( $body );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$cols = array(
			'sku'   => self::index( $csv['header'], TOS_Settings::get( 'list_col_sku', 'sku' ) ),
			'name'  => self::index( $csv['header'], TOS_Settings::get( 'list_col_name', 'name' ) ),
			'brand' => self::index( $csv['header'], TOS_Settings::get( 'list_col_brand', 'brand' ) ),
			'price' => self::index( $csv['header'], TOS_Settings::get( 'list_col_price', 'retail_price' ) ),
		);
		if ( $cols['sku'] === null ) {
			return new WP_Error( 'tos_list_col', 'In der Liste wurde die Spalte mit der Artikelnummer nicht gefunden. Spaltennamen in den Einstellungen prüfen.' );
		}

		$articles = array();
		foreach ( $csv['rows'] as $row ) {
			$sku = isset( $row[ $cols['sku'] ] ) ? trim( (string) $row[ $cols['sku'] ] ) : '';
			if ( $sku === '' ) {
				continue;
			}
			$articles[ $sku ] = array(
				'code'  => $sku,
				'name'  => $cols['name'] !== null ? trim( (string) ( $row[ $cols['name'] ] ?? '' ) ) : '',
				'brand' => $cols['brand'] !== null ? trim( (string) ( $row[ $cols['brand'] ] ?? '' ) ) : '',
				'price' => $cols['price'] !== null ? TOS_Feed::to_number( $row[ $cols['price'] ] ?? '' ) : null,
				'raw'   => array(),
			);
		}
		if ( ! $articles ) {
			return new WP_Error( 'tos_list_rows', 'In der Liste stand keine verwertbare Artikelnummer.' );
		}

		$res = TOS_Catalog::upsert_many( $articles );
		TOS_Logger::info( sprintf( 'Artikelliste eingelesen: %d Artikel, davon %d neu im Katalog.', count( $articles ), $res['new'] ) );

		return array(
			'articles' => count( $articles ),
			'new'      => $res['new'],
			'rows'     => count( $csv['rows'] ),
			'header'   => $csv['header'],
		);
	}

	/** @return array{header:array,rows:array,delimiter:string}|WP_Error */
	public static function parse( $body ) {
		// BOM entfernen, Zeilenenden vereinheitlichen, notfalls nach UTF-8 wandeln.
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		if ( ! mb_check_encoding( $body, 'UTF-8' ) ) {
			$body = mb_convert_encoding( $body, 'UTF-8', 'Windows-1252, ISO-8859-1' );
		}

		$first = strtok( $body, "\n" );
		if ( $first === false ) {
			return new WP_Error( 'tos_csv', 'Die Datei enthält keine Zeilen.' );
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
			return new WP_Error( 'tos_csv', 'Die Datei konnte nicht gelesen werden.' );
		}
		fwrite( $fh, $body );
		rewind( $fh );

		$header = fgetcsv( $fh, 0, $best );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			return new WP_Error( 'tos_csv', 'Die Kopfzeile konnte nicht gelesen werden.' );
		}
		$header = array_map( static function ( $h ) {
			return trim( (string) $h );
		}, $header );

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

	private static function index( array $header, $want ) {
		$want = self::normalise( (string) $want );
		if ( $want === '' ) {
			return null;
		}
		foreach ( $header as $i => $h ) {
			if ( self::normalise( $h ) === $want ) {
				return $i;
			}
		}
		return null;
	}

	private static function normalise( $s ) {
		return preg_replace( '/[^a-z0-9äöüß]/u', '', mb_strtolower( trim( (string) $s ) ) );
	}
}
