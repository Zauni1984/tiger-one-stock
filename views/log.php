<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var mixed $run
 * @var array $last
 * @var array $rows
 */
$show = is_array( $run ) ? $run : ( is_array( $last ) ? $last : array() );
?>
<div class="wrap">
	<h1>Tiger One · Protokoll</h1>

	<?php if ( $show ) : ?>
		<h2>
			<?php echo ! empty( $show['dry_run'] ) ? 'Trockenlauf' : 'Letzter Lauf'; ?>
			<?php if ( ! empty( $show['started'] ) ) : ?>
				<span style="font-weight:400">· <?php echo esc_html( mysql2date( 'd.m.Y H:i', $show['started'] ) ); ?></span>
			<?php endif; ?>
		</h2>

		<?php if ( ! empty( $show['aborted'] ) ) : ?>
			<div class="notice notice-error"><p><strong>Abgebrochen — es wurde nichts geändert.</strong></p></div>
		<?php endif; ?>

		<table class="widefat" style="max-width:44em">
			<tbody>
				<tr><th>Artikel im Feed</th><td><?php echo (int) ( $show['articles'] ?? 0 ); ?></td></tr>
				<tr><th>davon im Shop</th><td><?php echo (int) ( $show['matched'] ?? 0 ); ?></td></tr>
				<tr><th>Neu im Katalog</th><td><?php echo (int) ( $show['new_art'] ?? 0 ); ?></td></tr>
				<tr><th>Geändert</th><td><?php echo (int) ( $show['updated'] ?? 0 ); ?></td></tr>
				<tr><th>Unverändert</th><td><?php echo (int) ( $show['unchanged'] ?? 0 ); ?></td></tr>
				<tr><th>Übersprungen</th><td><?php echo (int) ( $show['skipped'] ?? 0 ); ?></td></tr>
				<tr><th>Auf 0 gesetzt</th><td><?php echo (int) ( $show['to_zero'] ?? 0 ); ?></td></tr>
				<tr><th>Nicht mehr im Feed</th><td><?php echo (int) ( $show['missing'] ?? 0 ); ?></td></tr>
				<?php if ( ! empty( $show['brands'] ) ) : ?>
					<tr>
						<th>Marken</th>
						<td>
							<?php foreach ( $show['brands'] as $b ) : ?>
								<?php echo esc_html( sprintf( '%s: %d Artikel', $b['code'], $b['articles'] ) ); ?><br>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $show['errors'] ) ) : ?>
			<h3>Meldungen</h3>
			<ul style="list-style:disc;margin-left:1.5em">
				<?php foreach ( $show['errors'] as $e ) : ?>
					<li><?php echo esc_html( $e ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $show['changes'] ) ) : ?>
			<h3>Änderungen (<?php echo count( $show['changes'] ); ?>)</h3>
			<table class="widefat striped">
				<thead><tr><th>Produkt</th><th>SKU</th><th>Artikelnummer</th><th>vorher</th><th>nachher</th><th>Status</th><th>Grund</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $show['changes'], 0, 500 ) as $c ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $c['pid'] ) ); ?>"><?php echo esc_html( $c['name'] ); ?></a></td>
						<td><code><?php echo esc_html( $c['sku'] ); ?></code></td>
						<td><code><?php echo esc_html( $c['code'] ); ?></code></td>
						<td><?php echo $c['from'] === null ? '—' : (int) $c['from']; ?></td>
						<td><?php echo empty( $c['qty'] ) ? '—' : (int) $c['to']; ?></td>
						<td><?php echo esc_html( $c['status'] ); ?></td>
						<td><?php echo esc_html( $c['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h2>Letzte Einträge</h2>
	<table class="widefat striped">
		<thead><tr><th style="width:9em">Zeit</th><th style="width:5em">Stufe</th><th>Meldung</th></tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="3">Noch keine Einträge.</td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $r ) : ?>
			<tr>
				<td><?php echo esc_html( mysql2date( 'd.m. H:i:s', $r->ts ) ); ?></td>
				<td>
					<?php
					$colors = array(
						'error' => '#b32d2e',
						'warn'  => '#b26b00',
					);
					printf(
						'<span style="color:%s">%s</span>',
						esc_attr( $colors[ $r->level ] ?? '#444' ),
						esc_html( $r->level )
					);
					?>
				</td>
				<td><?php echo esc_html( $r->msg ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
