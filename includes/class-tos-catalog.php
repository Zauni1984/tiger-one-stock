<?php
defined( 'ABSPATH' ) || exit;

/**
 * Katalog aller je gesehenen Tiger-One-Artikelnummern.
 *
 * Der Katalog ist bewusst von den Produkten getrennt: Er wächst mit jedem Feed
 * und zeigt, welche Artikel es bei Tiger One gibt, welche davon im Shop liegen
 * und welche fehlen — ohne dass dabei irgendetwas am Shop verändert wird.
 */
class TOS_Catalog {

	public static function get( $code ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . TOS_Install::articles_table() . ' WHERE code = %s', (string) $code )
		);
	}

	/**
	 * @param array<string,array> $articles
	 * @return array{new:int,seen:int}
	 */
	public static function upsert_many( array $articles ) {
		global $wpdb;
		$table = TOS_Install::articles_table();
		$now   = current_time( 'mysql' );
		$new   = 0;

		foreach ( $articles as $a ) {
			$code = trim( (string) ( $a['code'] ?? '' ) );
			if ( $code === '' ) {
				continue;
			}
			// Ohne Formatliste behandelt wpdb alle Werte als Zeichenkette und NULL
			// als NULL — genau das Verhalten, das hier gebraucht wird.
			$data = array(
				'name'       => mb_substr( (string) ( $a['name'] ?? '' ), 0, 255 ),
				'brand'      => mb_substr( (string) ( $a['brand'] ?? '' ), 0, 191 ),
				'brand_code' => mb_substr( (string) ( $a['brand_code'] ?? '' ), 0, 64 ),
				'warehouse'  => mb_substr( (string) ( $a['warehouse'] ?? '' ), 0, 64 ),
				'stock'      => isset( $a['stock'] ) && $a['stock'] !== null ? (float) $a['stock'] : null,
				'price'      => isset( $a['price'] ) && $a['price'] !== null ? (float) $a['price'] : null,
				'raw'        => wp_json_encode( $a['raw'] ?? array() ),
				'last_seen'  => $now,
			);

			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $code ) );
			if ( $id ) {
				// Einen vorhandenen Preis nicht mit NULL überschreiben: der Feed
				// liefert Preise nur manchmal mit, die Artikelliste dagegen immer.
				if ( $data['price'] === null ) {
					unset( $data['price'] );
				}
				$wpdb->update( $table, $data, array( 'id' => $id ) );
				continue;
			}
			$data['code']       = $code;
			$data['first_seen'] = $now;
			$wpdb->insert( $table, $data );
			++$new;
		}

		return array(
			'new'  => $new,
			'seen' => count( $articles ),
		);
	}

	/** Nur die Artikel, die auch im Shop liegen: Artikelnummer => Beitrags-IDs. */
	public static function linked_codes() {
		$out = array();
		foreach ( TOS_Matcher::map() as $key => $hits ) {
			foreach ( $hits as $h ) {
				$out[ $key ][] = (int) $h['pid'];
			}
		}
		return $out;
	}

	/**
	 * @return array{rows:array,total:int}
	 */
	public static function query( $search = '', $filter = 'all', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = TOS_Install::articles_table();
		$where = array( '1=1' );
		$args  = array();
		if ( $search !== '' ) {
			$where[] = '(code LIKE %s OR name LIKE %s OR brand LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY brand ASC, code ASC';
		$rows  = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		$map = TOS_Matcher::map();
		$out = array();
		foreach ( $rows as $r ) {
			$hits     = $map[ TOS_Matcher::key( $r->code ) ] ?? array();
			$r->hits  = $hits;
			$in_shop  = ! empty( $hits );
			if ( $filter === 'linked' && ! $in_shop ) {
				continue;
			}
			if ( $filter === 'missing' && $in_shop ) {
				continue;
			}
			if ( $filter === 'zero' && ! ( $r->stock !== null && (float) $r->stock <= 0 ) ) {
				continue;
			}
			$out[] = $r;
		}

		return array(
			'rows'  => array_slice( $out, (int) $offset, (int) $limit ),
			'total' => count( $out ),
		);
	}

	public static function counts() {
		global $wpdb;
		$table = TOS_Install::articles_table();
		$all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$map   = TOS_Matcher::map();
		$codes = $wpdb->get_col( "SELECT code FROM {$table}" );
		$lnk   = 0;
		foreach ( $codes as $c ) {
			if ( isset( $map[ TOS_Matcher::key( $c ) ] ) ) {
				++$lnk;
			}
		}
		return array(
			'all'     => $all,
			'linked'  => $lnk,
			'missing' => $all - $lnk,
		);
	}
}
