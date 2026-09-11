<?php
defined( 'ABSPATH' ) || exit;

/**
 * Option storage.
 *
 * Zugangsdaten dürfen in wp-config.php stehen (Konstanten gewinnen immer) und
 * werden sonst mit den Auth-Salts der Seite verschlüsselt abgelegt.
 */
class TOS_Settings {

	const OPTION = 'tos_settings';

	public static function defaults() {
		return array(
			// Tiger-One-API.
			'api_base'           => 'https://erp.tgrventures.com',
			'warehouse_code'     => '',
			'brand_codes'        => '',      // kommagetrennt, z. B. "1021, 1044"
			'customer_code'      => '',      // nur für Bestellungen, hier nur dokumentiert
			'consumer_key'       => '',      // optional: nur falls der Feed später Auth verlangt
			'consumer_secret'    => '',
			'use_token'          => 0,       // Bearer-Token mitsenden
			'http_timeout'       => 45,

			// Optionale Artikelliste (öffentlicher Link) — nur für den Katalog.
			'list_url'           => '',
			'list_col_sku'       => 'sku',
			'list_col_name'      => 'name',
			'list_col_brand'     => 'brand',
			'list_col_price'     => 'retail_price',

			// Abgleich.
			'enabled'            => 0,
			'exclude_sku_prefix' => 'HJ-',
			'interval'           => 'tos_6h',
			'buffer'             => 0,       // Sicherheitspuffer, wird vom Lieferantenbestand abgezogen.
			'threshold'          => 0,       // Bestand <= Schwelle => ausverkauft.
			'missing_action'     => 'ignore', // ignore | zero
			'backorder_mode'     => 'notify', // no | notify | yes  -> Verhalten bei Bestand 0
			'write_stock_qty'    => 1,
			'sync_variations'    => 1,       // Elternprodukte nach Variantenänderung neu berechnen
			'max_zero_ratio'     => 30,      // Notbremse in Prozent.
			'min_rows'           => 20,      // Notbremse: Mindestzahl Artikel aus dem Feed.
			'notify_email'       => '',
			'notify_on'          => 'error', // never | error | changes
			'log_keep_days'      => 30,
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

	/** Brand codes as a clean list. */
	public static function brand_codes() {
		$raw  = (string) self::get( 'brand_codes', '' );
		$out  = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $c ) {
			$c = trim( (string) $c );
			if ( $c !== '' ) {
				$out[ $c ] = $c;
			}
		}
		return array_values( $out );
	}

	private static function constant_map() {
		return array(
			'warehouse_code'  => 'TIGERONE_WAREHOUSE_CODE',
			'brand_codes'     => 'TIGERONE_BRAND_CODES',
			'customer_code'   => 'TIGERONE_CUSTOMER_CODE',
			'consumer_key'    => 'TIGERONE_CONSUMER_KEY',
			'consumer_secret' => 'TIGERONE_CONSUMER_SECRET',
		);
	}

	/** Effective credential: wp-config constant wins over the database. */
	public static function credential( $which ) {
		$map = self::constant_map();
		if ( isset( $map[ $which ] ) && defined( $map[ $which ] ) && constant( $map[ $which ] ) !== '' ) {
			return (string) constant( $map[ $which ] );
		}
		$raw = self::get( $which, '' );
		if ( $raw === '' ) {
			return '';
		}
		return $which === 'consumer_secret' ? self::decrypt( $raw ) : (string) $raw;
	}

	public static function credential_is_constant( $which ) {
		$map = self::constant_map();
		return isset( $map[ $which ] ) && defined( $map[ $which ] ) && constant( $map[ $which ] ) !== '';
	}

	private static function key() {
		$salt = ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : '' );
		return hash( 'sha256', 'tos|' . $salt, true );
	}

	public static function encrypt( $plain ) {
		if ( $plain === '' || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return 'v1:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		if ( strpos( (string) $stored, 'v1:' ) !== 0 || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $stored;
		}
		$blob = base64_decode( substr( $stored, 3 ), true );
		if ( $blob === false || strlen( $blob ) <= 16 ) {
			return '';
		}
		$iv    = substr( $blob, 0, 16 );
		$plain = openssl_decrypt( substr( $blob, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return $plain === false ? '' : $plain;
	}
}
