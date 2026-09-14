<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array $s
 * @var mixed $test
 * @var mixed $list
 * @var int|false $next
 */
$url_const = TOS_Settings::sheet_url_is_constant();
$csv_url   = TOS_Sheet::csv_url( TOS_Settings::sheet_url(), $s['sheet_gid'] );
?>
<div class="wrap">
	<h1>Tiger One Stock Sync</h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['tested'] ) && is_array( $test ) ) : ?>
		<div class="notice <?php echo empty( $test['error'] ) ? 'notice-info' : 'notice-error'; ?>">
			<?php if ( ! empty( $test['error'] ) ) : ?>
				<p><strong>Die Tabelle ließ sich nicht lesen:</strong> <?php echo esc_html( $test['error'] ); ?></p>
				<?php if ( ! empty( $test['url'] ) ) : ?>
					<p>Verwendete Adresse: <code><?php echo esc_html( $test['url'] ); ?></code></p>
				<?php endif; ?>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: Zusammenfassung des Tabellen-Tests */
						esc_html__( '%1$d Zeilen gelesen, %2$d Artikelnummern übernommen, davon %3$d mit einer SKU im Shop. %4$d Artikel stehen auf 0.', 'tigerone-stock-sync' ),
						(int) ( $test['stats']['rows'] ?? 0 ),
						(int) $test['articles'],
						(int) $test['matched'],
						(int) ( $test['stats']['zero'] ?? 0 )
					);
					?>
				</p>
				<p>
					Adresse: <code><?php echo esc_html( $test['url'] ); ?></code><br>
					Spalten: <code><?php echo esc_html( implode( ' | ', (array) $test['header'] ) ); ?></code>
					<?php if ( ! empty( $test['warehouses'] ) ) : ?>
						<br>Lager in der Tabelle:
						<?php
						$parts = array();
						foreach ( (array) $test['warehouses'] as $code => $n ) {
							$parts[] = $code . ' (' . (int) $n . ')';
						}
						echo esc_html( implode( ', ', $parts ) );
						?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $test['warnings'] ) ) : ?>
					<ul style="margin-left:1.5em">
						<?php foreach ( $test['warnings'] as $w ) : ?>
							<li><?php echo esc_html( $w ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $test['sample'] ) ) : ?>
					<table class="widefat striped" style="margin:0 0 1em;max-width:60em">
						<thead><tr><th>Artikelnummer</th><th>Marke</th><th>Lager</th><th>Bestand</th><th>im Shop</th></tr></thead>
						<tbody>
						<?php foreach ( $test['sample'] as $a ) : ?>
							<tr>
								<td><code><?php echo esc_html( $a['code'] ); ?></code></td>
								<td><?php echo esc_html( $a['brand'] ); ?></td>
								<td><?php echo esc_html( $a['warehouse'] ); ?></td>
								<td><?php echo $a['stock'] === null ? '—' : esc_html( (string) ( 0 + $a['stock'] ) ); ?></td>
								<td><?php echo TOS_Matcher::for_code( $a['code'] ) ? 'ja' : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['listed'] ) && is_array( $list ) ) : ?>
		<div class="notice <?php echo empty( $list['error'] ) ? 'notice-success' : 'notice-error'; ?>">
			<p>
				<?php if ( ! empty( $list['error'] ) ) : ?>
					<strong>Artikelliste:</strong> <?php echo esc_html( $list['error'] ); ?>
				<?php else : ?>
					<?php
					printf(
						esc_html__( 'Artikelliste eingelesen: %1$d Zeilen, %2$d Artikel, davon %3$d neu im Katalog.', 'tigerone-stock-sync' ),
						(int) $list['rows'],
						(int) $list['articles'],
						(int) $list['new']
					);
					?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<p>
		<?php if ( $next ) : ?>
			Nächster automatischer Lauf: <strong><?php echo esc_html( wp_date( 'd.m.Y H:i', $next ) ); ?></strong>
		<?php else : ?>
			Es ist derzeit kein automatischer Lauf geplant.
		<?php endif; ?>
		<?php
		$last_time = (int) get_option( 'tos_last_run_time', 0 );
		if ( $last_time ) :
			?>
			· Letzter Lauf: <?php echo esc_html( wp_date( 'd.m.Y H:i', $last_time ) ); ?>
			(<a href="<?php echo esc_url( admin_url( 'admin.php?page=tos-log' ) ); ?>">Protokoll</a>)
		<?php endif; ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'tos_save' ); ?>
		<input type="hidden" name="action" value="tos_save">

		<h2>Bestandstabelle</h2>
		<p class="description" style="max-width:52em">
			Der Bestand kommt aus dem Live-Bestands-Sheet von Tiger One — einer Google-Tabelle mit den
			Spalten <code>SKU</code>, <code>Quantity</code>, <code>Brand</code>, <code>Warehouse</code> und
			<code>Warehouse Code</code>. Der Link aus dem Browser genügt; der CSV-Export wird daraus selbst
			gebaut. Die Tabelle muss für „Jeder mit dem Link" lesbar oder als CSV veröffentlicht sein.
			Die Adresse darf auch in der <code>wp-config.php</code> stehen
			(<code>TIGERONE_SHEET_URL</code>) — die Konstante hat Vorrang.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sheet_url">Adresse der Tabelle</label></th>
				<td>
					<input type="url" id="sheet_url" name="sheet_url" value="<?php echo esc_attr( $url_const ? '' : $s['sheet_url'] ); ?>" class="large-text" <?php disabled( $url_const ); ?>
						placeholder="https://docs.google.com/spreadsheets/d/…/edit?gid=…">
					<?php if ( $url_const ) : ?><p class="description">Kommt aus der <code>wp-config.php</code>.</p><?php endif; ?>
					<p class="description">Abgerufen wird: <code><?php echo esc_html( $csv_url !== '' ? $csv_url : '—' ); ?></code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sheet_gid">Tabellenblatt (gid)</label></th>
				<td><input type="text" id="sheet_gid" name="sheet_gid" value="<?php echo esc_attr( $s['sheet_gid'] ); ?>" class="small-text">
					<p class="description">Die Zahl hinter <code>gid=</code>. Leer lassen, wenn der Link schon auf das richtige Blatt zeigt.</p></td>
			</tr>
			<tr>
				<th scope="row">Spalten</th>
				<td>
					<label>Artikelnummer <input type="text" name="sheet_col_sku" value="<?php echo esc_attr( $s['sheet_col_sku'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Menge <input type="text" name="sheet_col_qty" value="<?php echo esc_attr( $s['sheet_col_qty'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Marke <input type="text" name="sheet_col_brand" value="<?php echo esc_attr( $s['sheet_col_brand'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Lager <input type="text" name="sheet_col_warehouse" value="<?php echo esc_attr( $s['sheet_col_warehouse'] ); ?>" class="small-text"></label>
					<p class="description">
						Groß- und Kleinschreibung sowie Leerzeichen sind egal. Fehlt der eingetragene Name,
						werden die üblichen Schreibweisen (SKU, Quantity, Menge, Bestand …) selbst erkannt.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="warehouse_filter">Nur ein Lager</label></th>
				<td><input type="text" id="warehouse_filter" name="warehouse_filter" value="<?php echo esc_attr( $s['warehouse_filter'] ); ?>" class="regular-text" placeholder="z. B. MALAGALIVE">
					<p class="description">Leer = alle Zeilen der Tabelle. Mit Eintrag zählt nur dieses Lager — sinnvoll, sobald Tiger One mehrere Lager in eine Tabelle schreibt.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="brand_filter">Nur bestimmte Marken</label></th>
				<td><input type="text" id="brand_filter" name="brand_filter" value="<?php echo esc_attr( $s['brand_filter'] ); ?>" class="large-text" placeholder="leer = alle Marken">
					<p class="description">Kommagetrennt, genau wie in der Spalte „Brand". Der Abgleich läuft ohnehin nur über SKUs, die es im Shop gibt — der Filter ist eine zusätzliche Bremse.</p></td>
			</tr>
			<tr>
				<th scope="row">Doppelte Artikelnummern</th>
				<td>
					<select name="duplicate_mode">
						<option value="sum" <?php selected( $s['duplicate_mode'], 'sum' ); ?>>Mengen addieren (empfohlen)</option>
						<option value="max" <?php selected( $s['duplicate_mode'], 'max' ); ?>>Größte Menge nehmen</option>
						<option value="first" <?php selected( $s['duplicate_mode'], 'first' ); ?>>Erste Zeile gewinnt</option>
						<option value="last" <?php selected( $s['duplicate_mode'], 'last' ); ?>>Letzte Zeile gewinnt</option>
					</select>
					<p class="description">Steht eine SKU mehrfach in der Tabelle (mehrere Lager oder Chargen), gilt diese Regel.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="http_timeout">Zeitlimit</label></th>
				<td><input type="number" id="http_timeout" name="http_timeout" value="<?php echo esc_attr( (int) $s['http_timeout'] ); ?>" min="10" max="300" class="small-text"> Sekunden</td>
			</tr>
		</table>

		<h2>Abgleich</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Automatik</th>
				<td>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $s['enabled'], 1 ); ?>> Bestand regelmäßig abgleichen</label>
					<p>
						<select name="interval">
							<?php
							$intervals = array(
								'tos_1h'  => 'Jede Stunde',
								'tos_2h'  => 'Alle 2 Stunden',
								'tos_4h'  => 'Alle 4 Stunden',
								'tos_6h'  => 'Alle 6 Stunden',
								'tos_12h' => 'Alle 12 Stunden',
								'daily'   => 'Einmal täglich',
							);
							foreach ( $intervals as $k => $label ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $s['interval'], false ), esc_html( $label ) );
							}
							?>
						</select>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Mengen</th>
				<td>
					<label><input type="checkbox" name="write_stock_qty" value="1" <?php checked( (int) $s['write_stock_qty'], 1 ); ?>> Bestandsmenge mitschreiben (Lagerverwaltung einschalten)</label><br>
					<label><input type="checkbox" name="sync_variations" value="1" <?php checked( (int) $s['sync_variations'], 1 ); ?>> Elternprodukte nach Variantenänderungen neu berechnen</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="buffer">Sicherheitspuffer</label></th>
				<td><input type="number" id="buffer" name="buffer" value="<?php echo esc_attr( (float) $s['buffer'] ); ?>" min="0" step="1" class="small-text">
					<p class="description">Wird vom gemeldeten Bestand abgezogen, bevor er in den Shop geschrieben wird.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="threshold">Schwelle „ausverkauft"</label></th>
				<td><input type="number" id="threshold" name="threshold" value="<?php echo esc_attr( (float) $s['threshold'] ); ?>" min="0" step="1" class="small-text">
					<p class="description">Bestand kleiner oder gleich diesem Wert gilt als ausverkauft.</p></td>
			</tr>
			<tr>
				<th scope="row">Bei Bestand 0</th>
				<td>
					<select name="backorder_mode">
						<option value="notify" <?php selected( $s['backorder_mode'], 'notify' ); ?>>Lieferbar auf Nachfrage (onbackorder)</option>
						<option value="yes" <?php selected( $s['backorder_mode'], 'yes' ); ?>>Nachbestellung erlauben</option>
						<option value="no" <?php selected( $s['backorder_mode'], 'no' ); ?>>Ausverkauft (outofstock)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">Artikel nicht mehr in der Tabelle</th>
				<td>
					<select name="missing_action">
						<option value="ignore" <?php selected( $s['missing_action'], 'ignore' ); ?>>Unangetastet lassen (empfohlen)</option>
						<option value="zero" <?php selected( $s['missing_action'], 'zero' ); ?>>Auf 0 setzen</option>
					</select>
					<p class="description">Betrifft nur Artikelnummern, die schon einmal in einer Tiger-One-Bestandstabelle standen.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="exclude_sku_prefix">Eigenbestand-Präfix</label></th>
				<td><input type="text" id="exclude_sku_prefix" name="exclude_sku_prefix" value="<?php echo esc_attr( $s['exclude_sku_prefix'] ); ?>" class="small-text">
					<p class="description">SKUs mit diesem Präfix werden nie angefasst. Standard: <code>HJ-</code></p></td>
			</tr>
		</table>

		<h2>Notbremsen</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="min_rows">Mindestzahl Artikel</label></th>
				<td><input type="number" id="min_rows" name="min_rows" value="<?php echo esc_attr( (int) $s['min_rows'] ); ?>" min="0" class="small-text">
					<p class="description">Enthält die Tabelle weniger Artikel, bricht der Lauf ab, ohne etwas zu ändern.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="max_zero_ratio">Höchstanteil Nullstellungen</label></th>
				<td><input type="number" id="max_zero_ratio" name="max_zero_ratio" value="<?php echo esc_attr( (float) $s['max_zero_ratio'] ); ?>" min="0" max="100" class="small-text"> %
					<p class="description">Würden mehr Produkte auf „ausverkauft" gesetzt, bricht der Lauf ab.</p></td>
			</tr>
		</table>

		<h2>Benachrichtigung und Protokoll</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="notify_email">E-Mail</label></th>
				<td><input type="email" id="notify_email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Wann</th>
				<td>
					<select name="notify_on">
						<option value="error" <?php selected( $s['notify_on'], 'error' ); ?>>Nur bei Problemen</option>
						<option value="changes" <?php selected( $s['notify_on'], 'changes' ); ?>>Bei jeder Änderung</option>
						<option value="never" <?php selected( $s['notify_on'], 'never' ); ?>>Nie</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="log_keep_days">Protokoll aufbewahren</label></th>
				<td><input type="number" id="log_keep_days" name="log_keep_days" value="<?php echo esc_attr( (int) $s['log_keep_days'] ); ?>" min="1" class="small-text"> Tage</td>
			</tr>
		</table>

		<h2>Artikelliste (optional)</h2>
		<p class="description" style="max-width:52em">
			Die Bestandstabelle führt keine Namen und keine Preise. Wer im Katalog Klartext sehen will,
			hinterlegt hier zusätzlich die Tiger-One-Artikelliste.
			Sie wird ausschließlich in den Katalog dieses Plugins geschrieben — an Produkten ändert sie nichts.
			Bei Google-Tabellen muss der Link auf den CSV-Export zeigen.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="list_url">Adresse der Liste</label></th>
				<td><input type="url" id="list_url" name="list_url" value="<?php echo esc_attr( $s['list_url'] ); ?>" class="large-text" placeholder="https://docs.google.com/spreadsheets/d/…/export?format=csv"></td>
			</tr>
			<tr>
				<th scope="row">Spalten</th>
				<td>
					<label>Artikelnummer <input type="text" name="list_col_sku" value="<?php echo esc_attr( $s['list_col_sku'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Name <input type="text" name="list_col_name" value="<?php echo esc_attr( $s['list_col_name'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Marke <input type="text" name="list_col_brand" value="<?php echo esc_attr( $s['list_col_brand'] ); ?>" class="small-text"></label>
					<label style="margin-left:1em">Preis <input type="text" name="list_col_price" value="<?php echo esc_attr( $s['list_col_price'] ); ?>" class="small-text"></label>
				</td>
			</tr>
		</table>

		<?php submit_button( 'Einstellungen speichern' ); ?>
	</form>

	<hr>

	<h2>Prüfen und laufen lassen</h2>
	<div style="display:flex;gap:.6em;flex-wrap:wrap;align-items:center">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'tos_test' ); ?>
			<input type="hidden" name="action" value="tos_test">
			<button class="button">Tabelle prüfen</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'tos_run' ); ?>
			<input type="hidden" name="action" value="tos_run">
			<input type="hidden" name="dry" value="1">
			<button class="button">Trockenlauf (ändert nichts)</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'tos_run' ); ?>
			<input type="hidden" name="action" value="tos_run">
			<button class="button button-primary" onclick="return confirm('Bestand jetzt wirklich abgleichen?');">Jetzt abgleichen</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'tos_list' ); ?>
			<input type="hidden" name="action" value="tos_list">
			<button class="button">Artikelliste einlesen</button>
		</form>
	</div>
</div>
