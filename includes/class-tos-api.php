<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf das Tiger-One-ERP.
 *
 * Der Stock Feed ist eine Odoo-Route: sie antwortet nur auf GET, erwartet den
 * JSON-Körper {"warehouse_code": …, "brand_code": …} und liefert einen
 * JSON-RPC-Umschlag zurück, in dem das Ergebnis selbst noch einmal als
 * JSON-Zeichenkette steckt:
 *
 *   {"jsonrpc": "2.0", "id": null, "result": "{\"error\": \"Brand not found.\"}"}
 *
 * Ein GET mit Körper lässt sich über die WordPress-HTTP-API nicht zuverlässig
 * absenden (die Requests-Bibliothek hängt Daten bei GET an die URL). Deshalb
 * geht dieser Aufruf über cURL, wenn cURL verfügbar ist, und nur im Notfall
 * über wp_remote_request().
 *
 * Ein Token braucht der Stock Feed nach aktuellem Stand nicht. Falls Tiger One
 * das ändert, lässt sich in den Einstellungen „Token mitsenden" einschalten;
 * dann wird vorher ein Bearer-Token über /oauth2/access_token geholt.
 */
class TOS_API {

	const TOKEN_OPTION = 'tos_token';

	/* ------------------------------------------------------------ Stock Feed */

	/**
	 * Rohantwort des Stock Feeds für eine Marke.
	 *
	 * @param string $brand_code
	 * @return array{payload:mixed,raw:string,transport:string,http:int}|WP_Error
	 */
	public static function brand_stock( $brand_code ) {
		if ( (string) $brand_code === '' ) {
			return new WP_Error( 'tos_cfg', 'Es ist kein Brand Code hinterlegt. Tiger One vergibt je Marke einen Code.' );
		}

		// Der Warehouse Code ist im Onboarding als zwingend beschrieben, liegt aber
		// nicht immer vor. Fehlt er, wird das Feld weggelassen statt mit einem
		// erfundenen Wert gefüllt: Dann antwortet Tiger One selbst, ob es ohne geht.
		$warehouse = TOS_Settings::credential( 'warehouse_code' );
		$url       = self::endpoint( '/get_brand_stock' );
		$body      = wp_json_encode( self::request_body( $warehouse, $brand_code ) );

		$res = self::get_with_body( $url, $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$payload = self::unwrap( $res['body'] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Manche Odoo-Routen erwarten den Körper im params-Umschlag. Wenn die
		// flache Form über fehlende Parameter klagt, wird das einmal nachgeholt.
		if ( self::complains_about_params( $payload ) ) {
			$res2 = self::get_with_body(
				$url,
				wp_json_encode( array( 'params' => self::request_body( $warehouse, $brand_code ) ) )
			);
			if ( ! is_wp_error( $res2 ) ) {
				$payload2 = self::unwrap( $res2['body'] );
				if ( ! is_wp_error( $payload2 ) && ! self::complains_about_params( $payload2 ) ) {
					$res     = $res2;
					$payload = $payload2;
				}
			}
		}

		return array(
			'payload'   => $payload,
			'raw'       => $res['body'],
			'transport' => $res['transport'],
			'http'      => $res['http'],
		);
	}

	/** Anfragekörper; ohne Warehouse Code bleibt das Feld weg. */
	private static function request_body( $warehouse, $brand_code ) {
		$body = array( 'brand_code' => is_numeric( $brand_code ) ? (int) $brand_code : (string) $brand_code );
		if ( (string) $warehouse !== '' ) {
			$body = array( 'warehouse_code' => (string) $warehouse ) + $body;
		}
		return $body;
	}

	/** Fachlicher Fehler in der Antwort, z. B. „Brand not found." */
	public static function payload_error( $payload ) {
		if ( is_array( $payload ) ) {
			foreach ( array( 'error', 'message', 'error_description' ) as $k ) {
				if ( isset( $payload[ $k ] ) && is_string( $payload[ $k ] ) && $payload[ $k ] !== '' ) {
					return $payload[ $k ];
				}
			}
			if ( isset( $payload['success'] ) && ! $payload['success'] && isset( $payload['msg'] ) ) {
				return (string) $payload['msg'];
			}
		}
		if ( is_string( $payload ) && $payload !== '' && stripos( $payload, 'error' ) !== false ) {
			return $payload;
		}
		return '';
	}

	private static function complains_about_params( $payload ) {
		$err = self::payload_error( $payload );
		return $err !== '' && preg_match( '/(param|argument|missing|required|warehouse_code|brand_code)/i', $err ) === 1;
	}

	/* ------------------------------------------------------------ Transport */

	/** @return array{body:string,http:int,transport:string}|WP_Error */
	private static function get_with_body( $url, $json_body ) {
		$timeout = max( 10, (int) TOS_Settings::get( 'http_timeout', 45 ) );
		$headers = array(
			'Accept'       => '*/*',
			'Content-Type' => 'application/json',
		);
		if ( (int) TOS_Settings::get( 'use_token', 0 ) === 1 ) {
			$token = self::token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if ( function_exists( 'curl_init' ) ) {
			$out = self::curl_get_with_body( $url, $json_body, $headers, $timeout );
			if ( ! is_wp_error( $out ) ) {
				return $out;
			}
			$fallback_reason = $out->get_error_message();
		} else {
			$fallback_reason = 'cURL ist auf diesem Server nicht verfügbar';
		}

		// Notfallweg: Die Requests-Bibliothek verschiebt GET-Daten in die URL,
		// deshalb ist das hier nur ein Versuch — aber besser als nichts.
		try {
			$resp = wp_remote_request(
				$url,
				array(
					'method'  => 'GET',
					'timeout' => $timeout,
					'headers' => $headers,
					'body'    => $json_body,
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'tos_http', sprintf( 'Die Anfrage an Tiger One ließ sich nicht absenden (%s; cURL-Weg: %s).', $e->getMessage(), $fallback_reason ) );
		}
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		return array(
			'body'      => (string) wp_remote_retrieve_body( $resp ),
			'http'      => (int) wp_remote_retrieve_response_code( $resp ),
			'transport' => 'wp_remote_request',
		);
	}

	/** @return array{body:string,http:int,transport:string}|WP_Error */
	private static function curl_get_with_body( $url, $json_body, array $headers, $timeout ) {
		$h = curl_init();
		if ( ! $h ) {
			return new WP_Error( 'tos_curl', 'cURL konnte nicht initialisiert werden.' );
		}
		$head = array();
		foreach ( $headers as $k => $v ) {
			$head[] = $k . ': ' . $v;
		}
		curl_setopt_array(
			$h,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_CUSTOMREQUEST  => 'GET',
				CURLOPT_POSTFIELDS     => $json_body,
				CURLOPT_HTTPHEADER     => $head,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_USERAGENT      => 'hanfjack-tigerone-stock-sync/' . TOS_VERSION,
			)
		);
		$body = curl_exec( $h );
		$code = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );
		$err  = curl_error( $h );
		curl_close( $h );

		if ( $body === false ) {
			return new WP_Error( 'tos_curl', $err !== '' ? $err : 'Die Verbindung zum Tiger-One-ERP ist fehlgeschlagen.' );
		}
		return array(
			'body'      => (string) $body,
			'http'      => $code,
			'transport' => 'cURL',
		);
	}

	/** JSON-RPC-Umschlag auspacken; das Ergebnis ist selbst noch JSON. */
	public static function unwrap( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return new WP_Error( 'tos_empty', 'Tiger One hat eine leere Antwort geschickt.' );
		}
		if ( stripos( $raw, '<!doctype html' ) === 0 || stripos( $raw, '<html' ) === 0 ) {
			return new WP_Error( 'tos_html', 'Tiger One hat eine HTML-Seite statt Daten geschickt — meist ein falscher Pfad oder ein abgelaufener Zugang.' );
		}
		$outer = json_decode( $raw, true );
		if ( $outer === null && json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'tos_json', 'Die Antwort von Tiger One war kein gültiges JSON: ' . mb_substr( $raw, 0, 200 ) );
		}
		if ( is_array( $outer ) && isset( $outer['error'] ) && is_array( $outer['error'] ) ) {
			$msg = $outer['error']['message'] ?? 'Unbekannter Serverfehler';
			$det = $outer['error']['data']['message'] ?? '';
			return new WP_Error( 'tos_odoo', trim( 'Tiger One meldet einen Serverfehler: ' . $msg . ' ' . $det ) );
		}
		$result = is_array( $outer ) && array_key_exists( 'result', $outer ) ? $outer['result'] : $outer;
		if ( is_string( $result ) ) {
			$inner = json_decode( $result, true );
			if ( is_array( $inner ) ) {
				return $inner;
			}
			return $result;
		}
		return $result;
	}

	private static function endpoint( $path ) {
		$base = rtrim( (string) TOS_Settings::get( 'api_base', 'https://erp.tgrventures.com' ), '/' );
		if ( $base === '' ) {
			$base = 'https://erp.tgrventures.com';
		}
		// http würde auf https umgeleitet — bei GET mit Körper ist das unnötig riskant.
		$base = preg_replace( '#^http://#i', 'https://', $base );
		return $base . $path;
	}

	/* ------------------------------------------------------------ OAuth2 */

	/**
	 * Bearer-Token, zwischengespeichert bis kurz vor Ablauf.
	 *
	 * @return string|WP_Error
	 */
	public static function token( $force = false ) {
		$cached = get_option( self::TOKEN_OPTION, array() );
		if ( ! $force && is_array( $cached ) && ! empty( $cached['token'] ) && (int) ( $cached['expires'] ?? 0 ) > time() + 60 ) {
			return (string) $cached['token'];
		}

		$key    = TOS_Settings::credential( 'consumer_key' );
		$secret = TOS_Settings::credential( 'consumer_secret' );
		if ( $key === '' || $secret === '' ) {
			return new WP_Error( 'tos_cfg', 'Für ein Token fehlen Consumer Key und Secret Key.' );
		}

		$resp = wp_remote_post(
			self::endpoint( '/oauth2/access_token' ),
			array(
				'timeout' => max( 10, (int) TOS_Settings::get( 'http_timeout', 45 ) ),
				'headers' => array( 'Accept' => '*/*' ),
				// Das Onboarding-Dokument zeigt JSON, der Server erwartet aber ein
				// Formular — JSON quittiert er mit HTTP 500.
				'body'    => array(
					'client_id'     => $key,
					'client_secret' => $secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tos_token', 'Die Token-Antwort war kein JSON (HTTP ' . wp_remote_retrieve_response_code( $resp ) . ').' );
		}
		if ( empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? 'unbekannter Fehler' );
			return new WP_Error( 'tos_token', 'Tiger One hat kein Token ausgestellt: ' . $msg );
		}

		$valid   = $data['access_token_validity'] ?? '';
		$expires = is_numeric( $valid ) ? (int) $valid : ( strtotime( (string) $valid ) ?: 0 );
		if ( $expires < time() ) {
			$expires = time() + 1800; // konservativ, wenn das Format unklar ist
		}
		update_option(
			self::TOKEN_OPTION,
			array(
				'token'   => (string) $data['access_token'],
				'expires' => $expires,
			),
			false
		);
		return (string) $data['access_token'];
	}

	public static function forget_token() {
		delete_option( self::TOKEN_OPTION );
	}
}
