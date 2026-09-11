=== Tiger One Stock Sync ===
Stable tag: 1.0.0
Requires PHP: 7.4
Requires at least: 6.0
WC requires at least: 8.0

Hält den Warenbestand der Tiger-One-Artikel auf hanfjack.de aktuell. Der Bestand
kommt direkt aus dem Tiger-One-ERP (Stock Feed je Marke), der Abgleich läuft über
die Artikelnummer (SKU) — auch auf Variantenebene.

== Was Tiger One dafür herausgeben muss ==

Zwingend für den Bestandsabgleich:

* **Warehouse Code** — das Lager, aus dem der Bestand gemeldet wird.
* **Brand Code** je Marke — der Feed liefert immer genau eine Marke pro Abruf.
  Für unser Sortiment sind das die Codes von Seedsman, Sweet Seeds, Sensi Seeds,
  Amsterdam Genetics, Buddha Seeds, Serious Seeds, TerpyZ, Pyramid, Ripper,
  Silent Seeds, Nirvana und Fast Buds.

Nicht nötig für den Bestand, nur für das Übertragen von Bestellungen:

* **Customer Code**, **Consumer Key**, **Secret Key**.

Alle Werte dürfen in der `wp-config.php` stehen und haben dort Vorrang vor der
Datenbank:

    define( 'TIGERONE_WAREHOUSE_CODE', '…' );
    define( 'TIGERONE_BRAND_CODES',    '1021,1044' );
    define( 'TIGERONE_CONSUMER_KEY',   '…' );
    define( 'TIGERONE_CONSUMER_SECRET','…' );

== Stand der Schnittstelle (geprüft am 11.09.2026) ==

Das Onboarding-Dokument (Dropshipping Onboarding v3.1) beschreibt den Stock Feed
knapp. Nachgeprüft gilt:

* Der Feed ist eine Odoo-Route und antwortet **nur auf GET mit JSON-Körper**.
  Ein POST wird mit HTTP 405 abgelehnt.
* `http://` wird auf `https://` umgeleitet — das Plugin ruft direkt `https://` auf.
* **Ein Token ist nicht nötig.** Der Feed antwortet ohne Authorization-Header.
* Die Antwort ist ein JSON-RPC-Umschlag, in dem das Ergebnis noch einmal als
  JSON-Zeichenkette steckt:

      {"jsonrpc": "2.0", "id": null, "result": "{\"error\": \"Brand not found.\"}"}

* Das Token-Endpunkt-Beispiel im Dokument ist falsch: `/oauth2/access_token`
  erwartet ein **Formular**, nicht JSON. Mit JSON antwortet der Server HTTP 500,
  mit Formulardaten korrekt `{"error": "Unuthorized_client", …}`.
* Wie die Antwort bei einem **gültigen** Brand Code aufgebaut ist, ist nicht
  dokumentiert. Das Plugin erkennt die üblichen Formen selbst (Liste von
  Objekten, Liste unter `data`/`stock`/`products`, flache Zuordnung
  Artikelnummer => Menge) und zeigt unter „Verbindung testen" immer die
  Rohantwort — damit lässt sich die Zuordnung beim ersten echten Abruf in
  Minuten nachziehen.

== Sicherheitsregeln ==

* SKUs mit dem Präfix `HJ-` (Eigenbestand) werden **nie** angefasst.
* Geschrieben werden ausschließlich Bestandsmenge und Bestandsstatus. Preise,
  Texte, Produktstatus, Kategorien und Attribute bleiben unberührt.
* Nur Artikelnummern, die mindestens einmal in einem Tiger-One-Feed standen,
  gelten als Tiger-One-Ware. Produkte anderer Lieferanten sind unsichtbar.
* Zwei Notbremsen brechen den Lauf ab, ohne etwas zu ändern: zu wenige Artikel
  im Feed (`min_rows`) und ein zu hoher Anteil an Nullstellungen
  (`max_zero_ratio`, Standard 30 %).
* Einzelne Produkte lassen sich am Produkt selbst dauerhaft ausnehmen
  („Vom Tiger-One-Abgleich ausnehmen").
* Ein Trockenlauf zeigt jede geplante Änderung, ohne zu schreiben.

== Varianten ==

Bei Tiger One trägt in der Regel die **Variante** die Lieferanten-Artikelnummer
(z. B. `BS-SBSF050001-10`), das Elternprodukt die Parent-SKU. Der Abgleich
berücksichtigt Produkte und Varianten gleichberechtigt und berechnet nach einer
Variantenänderung das Elternprodukt neu, damit Preisspanne und Bestandsstatus
des Elternprodukts stimmen.

== Artikelliste (optional) ==

Die öffentliche Tiger-One-Artikelliste enthält Namen, Marke und Preise, aber
**keinen Bestand**. Sie wird deshalb nur in den Katalog dieses Plugins
geschrieben, nie in Produkte. Nützlich für zwei Dinge: neue Artikel erkennen, die
es im Shop noch nicht gibt, und Klartextnamen im Katalog, auch wenn der Feed nur
Artikelnummern liefert.

== Bedienung ==

1. Warehouse Code und Brand Codes eintragen, speichern.
2. „Verbindung testen" — zeigt je Marke, wie viele Artikel erkannt wurden, wie
   viele davon eine SKU im Shop haben, und die Rohantwort.
3. „Trockenlauf" — listet jede Änderung, die der erste echte Lauf machen würde.
4. Erst wenn das passt: „Bestand regelmäßig abgleichen" einschalten.

Menü: **Tiger One** → Einstellungen · Artikelkatalog · Protokoll.
