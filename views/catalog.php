<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array $data
 * @var array $counts
 * @var string $search
 * @var string $filter
 * @var int $paged
 * @var int $per
 */
$pages = max( 1, (int) ceil( $data['total'] / $per ) );
?>
<div class="wrap">
	<h1>Tiger-One-Artikelkatalog</h1>
	<p>
		<?php
		printf(
			esc_html__( '%1$d Artikelnummern bekannt · %2$d im Shop vorhanden · %3$d fehlen im Shop', 'tigerone-stock-sync' ),
			(int) $counts['all'],
			(int) $counts['linked'],
			(int) $counts['missing']
		);
		?>
	</p>

	<form method="get">
		<input type="hidden" name="page" value="tos-catalog">
		<p class="search-box">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Artikelnummer, Name oder Marke">
			<select name="filter">
				<option value="all" <?php selected( $filter, 'all' ); ?>>alle</option>
				<option value="linked" <?php selected( $filter, 'linked' ); ?>>im Shop vorhanden</option>
				<option value="missing" <?php selected( $filter, 'missing' ); ?>>fehlt im Shop</option>
				<option value="zero" <?php selected( $filter, 'zero' ); ?>>Bestand 0</option>
			</select>
			<button class="button">Filtern</button>
		</p>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>Artikelnummer</th>
				<th>Name</th>
				<th>Marke</th>
				<th>Bestand</th>
				<th>Preis</th>
				<th>Im Shop</th>
				<th>Zuletzt gesehen</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $data['rows'] ) : ?>
			<tr><td colspan="7">Keine Einträge. Der Katalog füllt sich mit dem ersten Abgleich oder über „Artikelliste einlesen".</td></tr>
		<?php endif; ?>
		<?php foreach ( $data['rows'] as $r ) : ?>
			<tr>
				<td><code><?php echo esc_html( $r->code ); ?></code></td>
				<td><?php echo esc_html( $r->name ); ?></td>
				<td><?php echo esc_html( $r->brand ); ?></td>
				<td><?php echo $r->stock === null ? '—' : esc_html( (string) (float) $r->stock ); ?></td>
				<td><?php echo $r->price === null ? '—' : esc_html( number_format_i18n( (float) $r->price, 2 ) ); ?></td>
				<td>
					<?php if ( empty( $r->hits ) ) : ?>
						<span style="color:#b32d2e">—</span>
					<?php else : ?>
						<?php foreach ( $r->hits as $h ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $h['type'] === 'product_variation' ? $h['parent'] : $h['pid'] ) ); ?>">
								#<?php echo (int) $h['pid']; ?></a><?php echo $h['type'] === 'product_variation' ? ' (Variante)' : ''; ?>
							<?php if ( $h['via'] === 'meta' ) : ?><em>(Feld)</em><?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $r->last_seen ? mysql2date( 'd.m.Y H:i', $r->last_seen ) : '—' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<p class="tablenav-pages" style="margin-top:1em">
			<?php
			echo paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			);
			?>
		</p>
	<?php endif; ?>
</div>
