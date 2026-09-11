<?php
/**
 * Prüft die Erkennung der Feed-Formen und die Entscheidungslogik ohne WordPress.
 *
 * Aufruf:  php tests/feed-shapes.php        (mit TOS_ALL=1 werden alle Artikel gezeigt)
 *
 * Warum das hier liegt: Tiger One dokumentiert die Antwort des Stock Feeds nicht.
 * Sobald die erste echte Antwort vorliegt, gehört sie als weiterer Fall in diese
 * Datei — dann ist sofort sichtbar, ob die Erkennung sie trifft.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 'Dieses Skript läuft nur auf der Kommandozeile.' );
}

define( 'ABSPATH', '/tmp/' );
define( 'TOS_VERSION', '1.0.0' );
class WP_Error {
	public $code; public $msg;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$dir = __DIR__ . '/../includes/';
require $dir . 'class-tos-api.php';
require $dir . 'class-tos-feed.php';

function show( $label, $payload ) {
	$r = TOS_Feed::parse( $payload, '1021', 'WH1' );
	if ( is_wp_error( $r ) ) {
		printf( "%-34s FEHLER: %s\n", $label, mb_substr( $r->get_error_message(), 0, 90 ) );
		return;
	}
	$first = reset( $r['articles'] );
	if ( getenv( 'TOS_ALL' ) ) {
		foreach ( $r['articles'] as $a ) {
			printf( "      %-30s %s\n", $a['code'], $a['stock'] === null ? 'null' : $a['stock'] );
		}
	}
	printf(
		"%-34s %2d Artikel | Form: %-34s | erste: %s = %s%s\n",
		$label,
		count( $r['articles'] ),
		$r['shape'],
		$first['code'],
		$first['stock'] === null ? 'null' : $first['stock'],
		$r['warnings'] ? ' | ' . implode( '; ', $r['warnings'] ) : ''
	);
}

// 1. Fehlermeldung des Servers (real beobachtet)
show( 'Brand not found', TOS_API::unwrap( '{"jsonrpc": "2.0", "id": null, "result": "{\"error\": \"Brand not found.\"}"}' ) );

// 2. Liste von Objekten mit code/qty
show( 'Liste code/qty', array(
	array( 'code' => 'BS-BSBAAKF-3', 'name' => 'Buddha AK Auto 3', 'qty' => 12 ),
	array( 'code' => 'BS-BSBAAKF-10', 'name' => 'Buddha AK Auto 10', 'qty' => 0 ),
) );

// 3. Odoo-typisch: default_code / qty_available, verschachtelt
show( 'verschachtelt product.*', array(
	array( 'product' => array( 'default_code' => 'SRSAK', 'name' => 'AK47 Regular' ), 'qty_available' => 7.0 ),
	array( 'product' => array( 'default_code' => 'SRSBG', 'name' => 'Bubble Gum Regular' ), 'qty_available' => 0 ),
) );

// 4. Liste unter data
show( 'Liste unter data', array( 'success' => true, 'data' => array(
	array( 'sku' => 'SS-AMPI-MIX-AUTO-FEM-4', 'stock' => '15' ),
	array( 'sku' => 'SS-BDXLA-Fem-4', 'stock' => 'out of stock' ),
) ) );

// 5. Flache Zuordnung Artikelnummer => Menge
show( 'flache Zuordnung', array(
	'BS-BSBAAKF-3' => 4, 'BS-BSBAAKF-10' => 0, 'BS-BSBAF-50' => 2, 'BS-BSBACF-3' => 9, 'BS-BSBCF-3' => 1,
) );

// 6. Kopfobjekt ohne Artikel — darf NICHT als Artikelliste gelten
show( 'Kopfobjekt ohne Artikel', array(
	'warehouse_code' => 'WH1', 'brand_code' => 1021, 'total' => 50, 'currency' => 'EUR', 'updated' => '2026-09-11',
) );

// 7. Worte statt Zahlen
show( 'Worte statt Zahlen', array(
	array( 'code' => 'A-1', 'availability' => 'In stock' ),
	array( 'code' => 'A-2', 'availability' => 'Out of stock' ),
) );

// 8. Artikel ohne Bestandsangabe
show( 'ohne Bestandsfeld', array(
	array( 'code' => 'A-1', 'name' => 'Nur Name' ),
	array( 'code' => 'A-2', 'name' => 'Auch nur Name' ),
) );

// 9. Odoo-Serverfehler
$u = TOS_API::unwrap( '{"jsonrpc":"2.0","id":null,"error":{"code":200,"message":"Odoo Server Error","data":{"message":"KeyError: brand"}}}' );
printf( "%-34s %s\n", 'Odoo-Serverfehler', is_wp_error( $u ) ? 'FEHLER erkannt: ' . $u->get_error_message() : 'NICHT erkannt' );

// 10. Doppelt verpacktes Ergebnis mit echter Liste
$u2 = TOS_API::unwrap( '{"jsonrpc": "2.0", "id": null, "result": "[{\"code\": \"X-1\", \"qty\": 3}, {\"code\": \"X-2\", \"qty\": 0}]"}' );
show( 'result als JSON-String', $u2 );

// 11. decide(): Entscheidungslogik
require $dir . 'class-tos-sync.php';
$cases = array(
	array( false, null, 'instock',     5, true,  'notify' ),
	array( true,  5,    'instock',     5, true,  'notify' ),
	array( true,  5,    'instock',     0, true,  'notify' ),
	array( true,  0,    'onbackorder', 0, true,  'no' ),
	array( false, null, 'instock',     0, false, 'no' ),
	array( false, null, 'outofstock',  3, false, 'notify' ),
);
echo "\ndecide(manages, vorher, Status, Ziel, Mengen, Modus) => geändert/Status/Menge\n";
foreach ( $cases as $c ) {
	$d = TOS_Sync::decide( $c[0], $c[1], $c[2], $c[3], $c[4], $c[5] );
	printf(
		"  %-5s %-4s %-12s Ziel %-2s %-5s %-6s => %-5s %-12s %s\n",
		$c[0] ? 'ja' : 'nein',
		$c[1] === null ? '—' : $c[1],
		$c[2],
		$c[3],
		$c[4] ? 'Menge' : 'Status',
		$c[5],
		$d['changed'] ? 'JA' : 'nein',
		$d['status'],
		$d['write_qty'] ? 'Menge' : '—'
	);
}
