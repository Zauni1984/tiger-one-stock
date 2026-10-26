<?php
/**
 * Prüft das Lesen der Bestandstabelle und die Entscheidungslogik ohne WordPress.
 *
 * Aufruf:  php tests/sheet-parse.php
 *          php tests/sheet-parse.php /pfad/zur/live-tabelle.csv   (echte Datei gegenprüfen)
 *
 * Die Fälle unten sind genau die, die in der Tiger-One-Tabelle vorkommen:
 * doppelte Artikelnummern, leere Mengen, Dezimalmengen, „FALSE" als Artefakt
 * und Zeilen aus einem anderen Lager.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 'Dieses Skript läuft nur auf der Kommandozeile.' );
}

define( 'ABSPATH', '/tmp/' );
define( 'TOS_VERSION', '2.0.1' );

class WP_Error {
	public $code;
	public $msg;
	public function __construct( $c = '', $m = '' ) {
		$this->code = $c;
		$this->msg  = $m;
	}
	public function get_error_message() {
		return $this->msg;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

require __DIR__ . '/../includes/class-tos-sheet.php';
require __DIR__ . '/../includes/class-tos-sync.php';

$fails = 0;
function check( $label, $got, $want ) {
	global $fails;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fails;
	}
	printf(
		"%-58s %s%s\n",
		$label,
		$ok ? 'ok' : 'FEHLER',
		$ok ? '' : sprintf( '  (erhalten: %s, erwartet: %s)', var_export( $got, true ), var_export( $want, true ) )
	);
}

/* ------------------------------------------------------------------ Adresse */

check(
	'csv_url: Browser-Link mit gid',
	TOS_Sheet::csv_url( 'https://docs.google.com/spreadsheets/d/ABC123/edit?gid=732713330#gid=732713330' ),
	'https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=732713330'
);
check(
	'csv_url: eingestellte gid gewinnt',
	TOS_Sheet::csv_url( 'https://docs.google.com/spreadsheets/d/ABC123/edit#gid=0', '42' ),
	'https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=42'
);
check(
	'csv_url: veröffentlichte Tabelle',
	TOS_Sheet::csv_url( 'https://docs.google.com/spreadsheets/d/e/2PACX-XYZ/pubhtml?gid=7' ),
	'https://docs.google.com/spreadsheets/d/e/2PACX-XYZ/pub?output=csv&gid=7'
);
check(
	'csv_url: fremder CSV-Link bleibt unverändert',
	TOS_Sheet::csv_url( 'https://example.com/bestand.csv' ),
	'https://example.com/bestand.csv'
);

$cand = TOS_Sheet::candidates( 'https://docs.google.com/spreadsheets/d/ABC123/edit?gid=42' );
check( 'candidates: vier Wege', count( $cand ), 4 );
check( 'candidates: 1. Export mit gid', $cand[0]['url'], 'https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=42' );
check( 'candidates: 2. gviz mit gid', $cand[1]['url'], 'https://docs.google.com/spreadsheets/d/ABC123/gviz/tq?tqx=out:csv&gid=42' );
check( 'candidates: 3. Export ohne gid', $cand[2]['url'], 'https://docs.google.com/spreadsheets/d/ABC123/export?format=csv' );
check(
	'candidates: &amp; im Link stört nicht',
	TOS_Sheet::candidates( 'https://docs.google.com/spreadsheets/d/ABC123/edit?format=csv&amp;gid=42' )[0]['url'],
	'https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=42'
);
check( 'candidates: fremder Link bleibt einer', count( TOS_Sheet::candidates( 'https://example.com/bestand.csv' ) ), 1 );

/* -------------------------------------------------------------------- Lesen */

$csv = "SKU,Quantity,Brand,Warehouse,Warehouse Code\n"
	. "00S-00CH-AUTO-FEM-3,10,00 Seeds,ES LIVE,MALAGALIVE\n"
	. "00S-00CH-FEM-05,0,00 Seeds,ES LIVE,MALAGALIVE\n"
	. "ATL-FOGD-AUTO-FEM-5,4,Atlas Seeds,ES LIVE,MALAGALIVE\n"
	. "ATL-FOGD-AUTO-FEM-5,3,Atlas Seeds,ES LIVE,MALAGALIVE\n"
	. "SIL-B52-FEM-3,0.5,Silent Seeds,ES LIVE,MALAGALIVE\n"
	. "FALSE,7,,ES LIVE,MALAGALIVE\n"
	. "LEER-OHNE-MENGE,,Testmarke,ES LIVE,MALAGALIVE\n"
	. "NL-WARE-1,9,Testmarke,NL LIVE,AMSTERDAMLIVE\n";

$r = TOS_Sheet::parse( $csv );
check( 'parse: Artikel erkannt', count( $r['articles'] ), 5 );
check( 'parse: Zeilen gezählt', $r['stats']['rows'], 8 );
check( 'parse: FALSE als Artikelnummer verworfen', $r['stats']['no_sku'], 1 );
check( 'parse: Zeile ohne Menge übersprungen', $r['stats']['no_qty'], 1 );
check( 'parse: Dublette addiert (4 + 3)', $r['articles']['ATL-FOGD-AUTO-FEM-5']['stock'], 7.0 );
check( 'parse: Dezimalmenge bleibt erhalten', $r['articles']['SIL-B52-FEM-3']['stock'], 0.5 );
check( 'parse: Bestand 0 gezählt', $r['stats']['zero'], 1 );
check( 'parse: Marke übernommen', $r['articles']['00S-00CH-AUTO-FEM-3']['brand'], '00 Seeds' );
check( 'parse: Lager übernommen', $r['articles']['NL-WARE-1']['warehouse'], 'AMSTERDAMLIVE' );
check( 'parse: beide Lager gesehen', count( $r['stats']['warehouses'] ), 2 );

$r2 = TOS_Sheet::parse( $csv, array( 'warehouse' => 'MALAGALIVE' ) );
check( 'Lagerfilter: fremdes Lager fliegt raus', isset( $r2['articles']['NL-WARE-1'] ), false );
check( 'Lagerfilter: Zeilen gezählt', $r2['stats']['other_ware'], 1 );

$r3 = TOS_Sheet::parse( $csv, array( 'brands' => array( 'atlas seeds' ) ) );
check( 'Markenfilter: nur die gewählte Marke', array_keys( $r3['articles'] ), array( 'ATL-FOGD-AUTO-FEM-5' ) );

$r4 = TOS_Sheet::parse( $csv, array( 'duplicates' => 'max' ) );
check( 'Dubletten: größte Menge', $r4['articles']['ATL-FOGD-AUTO-FEM-5']['stock'], 4.0 );
$r5 = TOS_Sheet::parse( $csv, array( 'duplicates' => 'last' ) );
check( 'Dubletten: letzte Zeile', $r5['articles']['ATL-FOGD-AUTO-FEM-5']['stock'], 3.0 );

// Semikolon-Tabelle mit deutschen Spaltennamen: wird ohne Konfiguration erkannt.
$de = "Artikelnummer;Menge;Marke\nX-1;12;Testmarke\nX-2;0;Testmarke\n";
$r6 = TOS_Sheet::parse( $de );
check( 'deutsche Spaltennamen erkannt', $r6['articles']['X-1']['stock'], 12.0 );
check( 'Trennzeichen erkannt', $r6['stats']['delimiter'], ';' );

// Fehlende Pflichtspalte bricht ab, statt zu raten.
$r7 = TOS_Sheet::parse( "Foo,Bar\n1,2\n" );
check( 'ohne SKU-Spalte: Abbruch', is_wp_error( $r7 ), true );

/* ------------------------------------------------------------- Mengenlesung */

check( 'Menge: „1.234,5"', TOS_Sheet::to_qty( '1.234,5' ), 1234.5 );
check( 'Menge: „1,234.5"', TOS_Sheet::to_qty( '1,234.5' ), 1234.5 );
check( 'Menge: leer bleibt null', TOS_Sheet::to_qty( '' ), null );
check( 'Menge: „ausverkauft" = 0', TOS_Sheet::to_qty( 'ausverkauft' ), 0.0 );
check( 'Menge: „lieferbar" = 1', TOS_Sheet::to_qty( 'lieferbar' ), 1.0 );
check( 'Menge: Fließtext bleibt null', TOS_Sheet::to_qty( 'bitte anfragen' ), null );

/* ----------------------------------------------------------- Entscheidungen */

$d = TOS_Sync::decide( true, 5, 'instock', 0, true, 'notify' );
check( 'Bestand 0: Status wird onbackorder', $d['status'], 'onbackorder' );
check( 'Bestand 0: Änderung nötig', $d['changed'], true );

$d = TOS_Sync::decide( true, 7, 'instock', 7, true, 'notify' );
check( 'gleiche Menge: keine Änderung', $d['changed'], false );

$d = TOS_Sync::decide( false, null, 'instock', 3, true, 'no' );
check( 'ohne Lagerverwaltung: Menge wird geschrieben', $d['write_qty'], true );

$d = TOS_Sync::decide( true, 0, 'outofstock', 2, false, 'no' );
check( 'ohne Mengenschreiben: nur Status', $d['write_qty'], false );
check( 'ohne Mengenschreiben: Status ändert sich', $d['status'], 'instock' );

/* ------------------------------------------------------- echte Datei prüfen */

if ( ! empty( $argv[1] ) ) {
	$file = $argv[1];
	if ( ! is_readable( $file ) ) {
		printf( "\nDatei nicht lesbar: %s\n", $file );
		exit( 1 );
	}
	$live = TOS_Sheet::parse( file_get_contents( $file ), array( 'warehouse' => '' ) );
	if ( is_wp_error( $live ) ) {
		printf( "\nEchte Tabelle: FEHLER %s\n", $live->get_error_message() );
		++$fails;
	} else {
		printf(
			"\nEchte Tabelle: %d Zeilen, %d Artikelnummern, %d ohne Menge, %d Dubletten, %d mit Bestand 0.\nLager: %s\n",
			$live['stats']['rows'],
			$live['stats']['used'],
			$live['stats']['no_qty'],
			$live['stats']['duplicates'],
			$live['stats']['zero'],
			implode( ', ', array_keys( $live['stats']['warehouses'] ) )
		);
		foreach ( array_slice( $live['articles'], 0, 3 ) as $a ) {
			printf( "   %-32s %6s  %s\n", $a['code'], (string) ( 0 + $a['stock'] ), $a['brand'] );
		}
	}
}

printf( "\n%s\n", $fails === 0 ? 'Alle Prüfungen bestanden.' : $fails . ' Prüfung(en) fehlgeschlagen.' );
exit( $fails === 0 ? 0 : 1 );
