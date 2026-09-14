<?php
defined( 'ABSPATH' ) || exit;

/**
 * Optional: eine zusätzliche Artikelliste (CSV) mit Namen, Marke und Preisen.
 *
 * Die Bestandstabelle von Tiger One führt nur Artikelnummer, Menge, Marke und
 * Lager. Wer im Katalog Klartextnamen und Preise sehen will, hinterlegt hier
 * zusätzlich die Artikelliste. Sie wird ausschließlich in den Katalog dieses
 * Plugins geschrieben — niemals in Produkte, und niemals in den Bestand.
 */
class TOS_List {

	/** @return array{articles:int,new:int,rows:int,header:array}|WP_Error */
	public static function import() {
		$url = trim( (string) TOS_Settings::get( 'list_url', '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'tos_list_url', 'Es ist keine Adresse für die Artikelliste hinterlegt.' );
		}

		// Auch hier darf ein Google-Tabellen-Link aus dem Browser stehen.
		$res = TOS_Sheet::fetch_any( $url );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$csv = TOS_Sheet::read_csv( $res['body'] );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$cols = array(
			'sku'   => TOS_Sheet::column( $csv['header'], TOS_Settings::get( 'list_col_sku', 'sku' ), TOS_Sheet::FALLBACK_SKU ),
			'name'  => TOS_Sheet::column( $csv['header'], TOS_Settings::get( 'list_col_name', 'name' ), array() ),
			'brand' => TOS_Sheet::column( $csv['header'], TOS_Settings::get( 'list_col_brand', 'brand' ), array() ),
			'price' => TOS_Sheet::column( $csv['header'], TOS_Settings::get( 'list_col_price', 'retail_price' ), array() ),
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
				'price' => $cols['price'] !== null ? TOS_Sheet::to_number( $row[ $cols['price'] ] ?? '' ) : null,
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
}
