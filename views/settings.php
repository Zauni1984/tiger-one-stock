<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array $s
 * @var mixed $test
 * @var mixed $list
 * @var int|false $next
 */
$wh_const = TOS_Settings::credential_is_constant( 'warehouse_code' );
$bc_const = TOS_Settings::credential_is_constant( 'brand_codes' );
$ck_const = TOS_Settings::credential_is_constant( 'consumer_key' );
$cs_const = TOS_Settings::credential_is_constant( 'consumer_secret' );
?>
<div class="wrap">
	<h1>Tiger One Stock Sync</h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['tested'] ) && is_array( $test ) ) : ?>
		<div class="notice <?php echo empty( $test['error'] ) ? 'notice-info' : 'notice-error'; ?>">
			<?php if ( ! empty( $test['error'] ) ) : ?>
				<p><strong>Verbindung fehlgeschlagen:</strong> <?php echo esc_html( $test['error'] ); ?></p>
			<?php else : ?>
				<?php foreach ( (array) $test['brands'] as $b ) : ?>
					<p>
						<strong>Brand Code <?php echo esc_html( $b['code'] ); ?>:</strong>
						<?php if ( ! empty( $b['error'] ) ) : ?>
							<span style="color:#b32d2e"><?php echo esc_html( $b['error'] ); ?></span>
						<?php else : ?>
							<?php
							printf(
								/* translators: Zusammenfassung des Verbindungstests */
								esc_html__( '%1$d Artikel erkannt (%2$s), davon %3$d mit einer SKU im Shop. HTTP %4$d über %5$s.', 'tigerone-stock-sync' ),
								(int) $b['articles'],
								esc_html( $b['shape'] ),
								(int) $b['matched'],
								(int) $b['http'],
								esc_html( $b['transport'] )
							);
							?>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $b['warnings'] ) ) : ?>
						<ul style="margin-left:1.5em">
							<?php foreach ( $b['warnings'] as $w ) : ?>
								<li><?php echo esc_html( $w ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $b['sample'] ) ) : ?>
						<table class="widefat striped" style="margin:0 0 1em">
							<thead><tr><th>Artikelnummer</th><th>Name</th><th>Bestand</th></tr></thead>
							<tbody>
							<?php foreach ( $b['sample'] as $a ) : ?>
								<tr>
									<td><code><?php echo esc_html( $a['code'] ); ?></code></td>
									<td><?php echo esc_html( $a['name'] ); ?></td>
									<td><?php echo $a['stock'] === null ? '—' : esc_html( (string) $a['stock'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<?php if ( ! empty( $b['raw'] ) ) : ?>
						<details style="margin:0 0 1em">
							<summary>Rohantwort anzeigen</summary>
							<textarea readonly rows="8" style="width:100%;font-family:monospace"><?php echo esc_textarea( $b['raw'] ); ?></textarea>
						</details>
					<?php endif; ?>
				<?php endforeach; ?>
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

		<h2>Zugang zum Tiger-One-ERP</h2>
		<p class="description" style="max-width:52em">
			Der Stock Feed braucht nach heutigem Stand kein Token — es genügen Warehouse Code und Brand Code.
			Consumer Key und Secret sind nur nötig, falls Tiger One den Feed später absichert (oder für das
			Übertragen von Bestellungen). Alle Werte dürfen auch in der <code>wp-config.php</code> stehen:
			<code>TIGERONE_WAREHOUSE_CODE</code>, <code>TIGERONE_BRAND_CODES</code>,
			<code>TIGERONE_CONSUMER_KEY</code>, <code>TIGERONE_CONSUMER_SECRET</code>. Konstanten haben Vorrang.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="api_base">API-Adresse</label></th>
				<td><input type="url" id="api_base" name="api_base" value="<?php echo esc_attr( $s['api_base'] ); ?>" class="regular-text">
					<p class="description">Standard: <code>https://erp.tgrventures.com</code></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="warehouse_code">Warehouse Code</label></th>
				<td>
					<input type="text" id="warehouse_code" name="warehouse_code" value="<?php echo esc_attr( $wh_const ? '' : $s['warehouse_code'] ); ?>" class="regular-text" <?php disabled( $wh_const ); ?>>
					<?php if ( $wh_const ) : ?><p class="description">Kommt aus der <code>wp-config.php</code>.</p><?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="brand_codes">Brand Codes</label></th>
				<td>
					<input type="text" id="brand_codes" name="brand_codes" value="<?php echo esc_attr( $bc_const ? '' : $s['brand_codes'] ); ?>" class="regular-text" <?php disabled( $bc_const ); ?>>
					<p class="description">Mehrere durch Komma trennen. Je Marke ein Code — der Feed liefert immer genau eine Marke.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="customer_code">Customer Code</label></th>
				<td><input type="text" id="customer_code" name="customer_code" value="<?php echo esc_attr( $s['customer_code'] ); ?>" class="regular-text">
					<p class="description">Wird für den Bestandsabgleich nicht gebraucht, nur für Bestellungen. Hier nur zur Dokumentation.</p></td>
			</tr>
			<tr>
				<th scope="row">Token</th>
				<td>
					<label><input type="checkbox" name="use_token" value="1" <?php checked( (int) $s['use_token'], 1 ); ?>> Bearer-Token mitsenden</label>
					<p class="description">Nur einschalten, wenn der Feed ohne Token 401/403 antwortet.</p>
					<p>
						<label for="consumer_key">Consumer Key</label><br>
						<input type="text" id="consumer_key" name="consumer_key" value="<?php echo esc_attr( $ck_const ? '' : $s['consumer_key'] ); ?>" class="regular-text" <?php disabled( $ck_const ); ?>>
					</p>
					<p>
						<label for="consumer_secret">Secret Key</label><br>
						<input type="password" id="consumer_secret" name="consumer_secret" value="" class="regular-text" autocomplete="new-password" <?php disabled( $cs_const ); ?>
							placeholder="<?php echo $s['consumer_secret'] !== '' || $cs_const ? 'gespeichert — nur zum Ändern ausfüllen' : ''; ?>">
						<?php if ( $s['consumer_secret'] !== '' && ! $cs_const ) : ?>
							<label style="margin-left:1em"><input type="checkbox" name="consumer_secret_clear" value="1"> löschen</label>
						<?php endif; ?>
					</p>
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
				<th scope="row">Artikel nicht mehr im Feed</th>
				<td>
					<select name="missing_action">
						<option value="ignore" <?php selected( $s['missing_action'], 'ignore' ); ?>>Unangetastet lassen (empfohlen)</option>
						<option value="zero" <?php selected( $s['missing_action'], 'zero' ); ?>>Auf 0 setzen</option>
					</select>
					<p class="description">Betrifft nur Artikelnummern, die schon einmal in einem Tiger-One-Feed standen.</p>
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
					<p class="description">Liefert der Feed weniger Artikel, bricht der Lauf ab, ohne etwas zu ändern.</p></td>
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
			Die öffentliche Tiger-One-Artikelliste enthält Namen, Marke und Preise, aber keinen Bestand.
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
			<button class="button">Verbindung testen</button>
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
