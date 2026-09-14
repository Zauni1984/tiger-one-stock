=== Tiger One Stock Sync ===
Stable tag: 2.0.1
Requires PHP: 7.4
Requires at least: 6.0
WC requires at least: 8.0

Hält den Warenbestand der Tiger-One-Artikel auf hanfjack.de aktuell. Der Bestand
kommt aus dem **Live-Bestands-Sheet von Tiger One** — einer Google-Tabelle, die
als CSV gelesen wird. Der Abgleich läuft über die Artikelnummer (SKU), auch auf
Variantenebene.

== Warum Tabelle statt API (seit 2.0.0) ==

Bis 1.0.0 holte das Plugin den Bestand über den Stock Feed des Tiger-One-ERP.
Dieser Weg braucht je Marke einen Brand Code, den Tiger One nicht herausgegeben
hat — ohne Brand Code antwortet der Feed nur „Brand not found.". Tiger One
liefert den Live-Bestand stattdessen als Tabelle. Genau die ist jetzt die
Quelle; der API-Weg ist entfallen, samt Token, Consumer Key und Secret.

== Die Tabelle ==

Erwartet werden diese Spalten (Groß-/Kleinschreibung und Leerzeichen egal):

| Spalte           | Bedeutung                                  |
| ---------------- | ------------------------------------------ |
| `SKU`            | Artikelnummer — der Schlüssel zum Shop     |
| `Quantity`       | Bestand                                    |
| `Brand`          | Marke (nur Katalog und optionaler Filter)  |
| `Warehouse`      | Lagername (informativ)                     |
| `Warehouse Code` | Lagerschlüssel, z. B. `MALAGALIVE`         |

Stand 14.09.2026: 10.927 Zeilen, 10.902 Artikelnummern, ein Lager
(`MALAGALIVE`), 136 Marken.

=== Mehrere Wege zur Tabelle (seit 2.0.1) ===

Google beantwortet den CSV-Export mit Blattangabe (`export?format=csv&gid=…`)
je nach Tabelle mit einer Weiterleitung, die für nicht angemeldete Abrufe in
**HTTP 400** endet — genau das passierte mit der Tiger-One-Tabelle. Das Plugin
probiert deshalb der Reihe nach:

1. `…/export?format=csv&gid=…` — exaktes Blatt
2. `…/gviz/tq?tqx=out:csv&gid=…` — exaktes Blatt, unempfindlich gegen den Fehler
3. `…/export?format=csv` — erstes Blatt
4. `…/gviz/tq?tqx=out:csv` — erstes Blatt

Der erste Weg, der CSV liefert, wird genommen; welcher das war, steht unter
„Tabelle prüfen" und im Protokoll. Geprüft am 14.09.2026: Weg 1 = 400,
Weg 2 = 200, Weg 3 = 200, alle mit denselben 10.927 Zeilen.

In den Einstellungen genügt der Link aus dem Browser
(`https://docs.google.com/spreadsheets/d/…/edit?gid=…`) — der CSV-Export wird
daraus selbst gebaut. Ein fertiger CSV-Link oder eine veröffentlichte Tabelle
(`/pub?output=csv`) funktionieren ebenso. Die Tabelle muss für „Jeder mit dem
Link — Betrachter" lesbar sein, sonst antwortet Google mit einer HTML-Seite;
das Plugin sagt das dann genau so.

Die Adresse darf auch in der `wp-config.php` stehen und hat dort Vorrang:

    define( 'TIGERONE_SHEET_URL', 'https://docs.google.com/spreadsheets/d/…' );

== Was die Tabelle nicht liefert ==

Namen und Preise stehen nicht in der Bestandstabelle. Wer im Katalog Klartext
sehen will, hinterlegt zusätzlich die Tiger-One-Artikelliste (Abschnitt
„Artikelliste"). Sie geht ausschließlich in den Katalog dieses Plugins, nie in
Produkte und nie in den Bestand.

== Regeln beim Lesen ==

* Zeilen ohne Artikelnummer oder ohne lesbare Menge werden gezählt und
  übersprungen — geschätzt wird nichts.
* `TRUE`/`FALSE` als Artikelnummer (verrutschte Tabellenformeln) gelten nicht
  als Artikel.
* Steht eine SKU mehrfach in der Tabelle, werden die Mengen standardmäßig
  addiert (umstellbar auf größte Menge, erste oder letzte Zeile).
* Der Bestand wird **abgerundet** in den Shop geschrieben: aus 0,5 wird 0. Der
  Shop bietet damit nie mehr an, als Tiger One meldet.
* Optional lässt sich auf ein Lager (`Warehouse Code`) oder auf bestimmte
  Marken einschränken.

== Sicherheitsregeln ==

* SKUs mit dem Präfix `HJ-` (Eigenbestand) werden **nie** angefasst.
* Geschrieben werden ausschließlich Bestandsmenge und Bestandsstatus. Preise,
  Texte, Produktstatus, Kategorien und Attribute bleiben unberührt.
* Nur Artikelnummern, die mindestens einmal in einer Tiger-One-Bestandstabelle
  standen, gelten als Tiger-One-Ware. Produkte anderer Lieferanten sind
  unsichtbar.
* Zwei Notbremsen brechen den Lauf ab, ohne etwas zu ändern: zu wenige Artikel
  in der Tabelle (`min_rows`) und ein zu hoher Anteil an Nullstellungen
  (`max_zero_ratio`, Standard 30 %). Letzteres fängt eine halb geladene oder
  gefilterte Tabelle ab.
* Einzelne Produkte lassen sich am Produkt selbst dauerhaft ausnehmen
  („Vom Tiger-One-Abgleich ausnehmen").
* Ein Trockenlauf zeigt jede geplante Änderung, ohne zu schreiben.

== Varianten ==

Bei Tiger One trägt in der Regel die **Variante** die Lieferanten-Artikelnummer
(z. B. `BS-SBSF050001-10`), das Elternprodukt die Parent-SKU. Der Abgleich
berücksichtigt Produkte und Varianten gleichberechtigt und berechnet nach einer
Variantenänderung das Elternprodukt neu, damit Preisspanne und Bestandsstatus
des Elternprodukts stimmen.

== Bedienung ==

1. Link zur Tabelle eintragen (Standard ist bereits das Live-Sheet), speichern.
2. „Tabelle prüfen" — zeigt Spalten, Lager, Zeilen, wie viele Artikelnummern
   eine SKU im Shop haben, und eine Stichprobe.
3. „Trockenlauf" — listet jede Änderung, die der erste echte Lauf machen würde.
4. Erst wenn das passt: „Bestand regelmäßig abgleichen" einschalten.

Menü: **Tiger One** → Einstellungen · Artikelkatalog · Protokoll.

== Prüfen ohne WordPress ==

    php tests/sheet-parse.php                    # Regeln und Entscheidungen
    php tests/sheet-parse.php live-tabelle.csv   # echte Tabelle gegenprüfen

== Umstieg von 1.0.0 ==

Katalog, Protokoll, Ausnahmen am Produkt und alle Abgleich-Einstellungen
bleiben erhalten. Nicht mehr vorhanden sind API-Adresse, Warehouse Code als
Zugangsdatum, Brand Codes, Customer Code, Consumer Key/Secret und die
Token-Option; die alten Werte bleiben unbenutzt in der Option stehen und
richten keinen Schaden an. Neu sind Tabellenadresse, Tabellenblatt (gid),
Spaltennamen, Lager- und Markenfilter sowie die Regel für doppelte
Artikelnummern.
