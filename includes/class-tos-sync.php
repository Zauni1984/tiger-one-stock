<?php
defined( 'ABSPATH' ) || exit;

/**
 * Der eigentliche Bestandslauf.
 *
 * Zwei Regeln gelten überall:
 *   1. Angefasst werden nur Produkte und Varianten, deren SKU im Tiger-One-Feed
 *      steht. Eigenbestand („HJ-…") ist für dieses Plugin unsichtbar.
 *   2. Geschrieben werden ausschließlich Bestandsmenge und Bestandsstatus.
 *      Preise, Texte, Status, Kategorien und Attribute bleiben unberührt.
 */
class TOS_Sync {

	/**
	 * @param bool $dry_run Wenn true, wird nichts geschrieben; der Bericht zeigt nur, was passieren würde.
	 */
	public static function run( $dry_run = false ) {
		$run_id = TOS_Logger::start_run();
		$s      = TOS_Settings::all();
		$report = array(
			'run_id'    => $run_id,
			'dry_run'   => $dry_run,
			'started'   => current_time( 'mysql' ),
			'brands'    => array(),
			'shape'     => '',
			'articles'  => 0,
			'matched'   => 0,
			'new_art'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'to_zero'   => 0,
			'missing'   => 0,
			'skipped'   => 0,
			'changes'   => array(),
			'errors'    => array(),
			'aborted'   => false,
		);

		TOS_Logger::info( $dry_run ? 'Trockenlauf gestartet.' : 'Abgleich gestartet.' );

		/* ---------------------------------------------------- 1. Feed holen */
		$brand_codes = TOS_Settings::brand_codes();
		if ( ! $brand_codes ) {
			return self::fail( $report, 'Es ist kein Brand Code hinterlegt. Ohne Marken-Code liefert der Stock Feed keine Daten.' );
		}
		$warehouse = TOS_Settings::credential( 'warehouse_code' );

		$articles = array();
		foreach ( $brand_codes as $bc ) {
			$res = TOS_API::brand_stock( $bc );
			if ( is_wp_error( $res ) ) {
				$msg                = sprintf( 'Brand Code %s: %s', $bc, $res->get_error_message() );
				$report['errors'][] = $msg;
				TOS_Logger::error( $msg );
				continue;
			}
			$parsed = TOS_Feed::parse( $res['payload'], $bc, $warehouse );
			if ( is_wp_error( $parsed ) ) {
				$msg                = sprintf( 'Brand Code %s: %s', $bc, $parsed->get_error_message() );
				$report['errors'][] = $msg;
				TOS_Logger::error( $msg );
				TOS_Logger::info( 'Rohantwort (gekürzt): ' . mb_substr( $res['raw'], 0, 2000 ) );
				continue;
			}
			$report['shape']    = $parsed['shape'];
			$report['brands'][] = array(
				'code'     => (string) $bc,
				'articles' => count( $parsed['articles'] ),
			);
			foreach ( $parsed['warnings'] as $w ) {
				TOS_Logger::warn( sprintf( 'Brand Code %s: %s', $bc, $w ) );
			}
			foreach ( $parsed['articles'] as $code => $a ) {
				$articles[ $code ] = $a;   // spätere Marke gewinnt bei Dubletten
			}
			TOS_Logger::info(
				sprintf(
					'Brand Code %s: %d Artikel aus dem Feed (%s, %s, HTTP %d).',
					$bc,
					count( $parsed['articles'] ),
					$parsed['shape'],
					$res['transport'],
					$res['http']
				)
			);
		}

		$report['articles'] = count( $articles );
		if ( ! $articles ) {
			return self::fail( $report, 'Der Stock Feed hat für keinen Brand Code verwertbare Artikel geliefert. Es wurde nichts geändert.' );
		}

		/* ---------------------------------------------------- 2. Notbremse: zu wenige Artikel */
		$min_rows = (int) $s['min_rows'];
		if ( $min_rows > 0 && $report['articles'] < $min_rows ) {
			return self::fail(
				$report,
				sprintf(
					'Abbruch aus Sicherheitsgründen: Der Feed enthält nur %d Artikel, erwartet werden mindestens %d. Es wurde nichts geändert.',
					$report['articles'],
					$min_rows
				)
			);
		}

		/* ---------------------------------------------------- 3. Katalog pflegen */
		if ( ! $dry_run ) {
			$cat               = TOS_Catalog::upsert_many( $articles );
			$report['new_art'] = $cat['new'];
			if ( $cat['new'] > 0 ) {
				TOS_Logger::info( sprintf( '%d neue Artikelnummern in den Katalog aufgenommen.', $cat['new'] ) );
			}
		} else {
			foreach ( $articles as $a ) {
				if ( ! TOS_Catalog::get( $a['code'] ) ) {
					++$report['new_art'];
				}
			}
		}

		/* ---------------------------------------------------- 4. Produkte über die SKU finden */
		TOS_Matcher::flush();
		$linked = TOS_Catalog::linked_codes();    // SKU (normalisiert) => Beitrags-IDs
		$known  = TOS_Matcher::catalogue_keys();  // je gesehene Artikelnummern

		$by_key = array();
		foreach ( $articles as $a ) {
			$by_key[ TOS_Matcher::key( $a['code'] ) ] = $a;
		}

		$report['matched'] = TOS_Matcher::matched_count( array_keys( $articles ) );
		TOS_Logger::info(
			sprintf(
				'%d von %d Artikelnummern haben ein Produkt oder eine Variante im Shop (Abgleich über die SKU).',
				$report['matched'],
				count( $articles )
			)
		);
		if ( $report['matched'] === 0 ) {
			$msg = 'Keine einzige Artikelnummer passt zu einer SKU im Shop. Der Katalog wurde aktualisiert, Bestand wurde nicht geändert.';
			TOS_Logger::warn( $msg );
			$report['errors'][] = $msg;
			return self::finish( $report );
		}

		/* ---------------------------------------------------- 5. Planen (erst rechnen, dann schreiben) */
		$plan = array();

		foreach ( $linked as $key => $post_ids ) {
			$has = isset( $by_key[ $key ] );

			// Steht die SKU nicht im heutigen Feed, wird sie nur angefasst, wenn sie
			// schon einmal in einem Tiger-One-Feed stand. Sonst wäre jede fremde
			// Lieferanten-SKU im Shop plötzlich ein Tiger-One-Artikel.
			if ( ! $has && ! isset( $known[ $key ] ) ) {
				continue;
			}

			foreach ( $post_ids as $pid ) {
				if ( TOS_Matcher::is_excluded( $pid ) ) {
					++$report['skipped'];
					continue;
				}
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				if ( ! $has ) {
					++$report['missing'];
					if ( $s['missing_action'] === 'ignore' ) {
						continue;
					}
					$target = 0;
					$code   = $key;
				} else {
					$code = $by_key[ $key ]['code'];
					$raw  = $by_key[ $key ]['stock'];
					if ( $raw === null ) {
						++$report['skipped'];
						continue; // keine verwertbare Bestandsangabe -> nichts anfassen
					}
					$raw = max( 0, (float) $raw - (float) $s['buffer'] );
					if ( $raw <= (float) $s['threshold'] ) {
						$raw = 0;
					}
					$target = (int) round( $raw );
				}

				$plan[] = array(
					'pid'     => $pid,
					'code'    => $code,
					'product' => $product,
					'target'  => $target,
					'missing' => ! $has,
				);
				if ( $target === 0 ) {
					++$report['to_zero'];
				}
			}
		}

		/* ---------------------------------------------------- 6. Notbremse: zu viele Nullstellungen */
		$ratio = $plan ? ( $report['to_zero'] / count( $plan ) ) * 100 : 0;
		$maxr  = (float) $s['max_zero_ratio'];
		if ( $maxr > 0 && $ratio > $maxr && count( $plan ) >= 5 ) {
			return self::fail(
				$report,
				sprintf(
					'Abbruch aus Sicherheitsgründen: %s %% der verknüpften Produkte (%d von %d) würden auf „ausverkauft" gesetzt — die Grenze liegt bei %s %%. Das deutet auf einen fehlerhaften Feed hin. Es wurde nichts geändert.',
					number_format_i18n( $ratio, 1 ),
					$report['to_zero'],
					count( $plan ),
					number_format_i18n( $maxr, 0 )
				)
			);
		}

		/* ---------------------------------------------------- 7. Schreiben */
		$with_qty      = (bool) (int) $s['write_stock_qty'];
		$variable_warn = array();
		$parents       = array();

		foreach ( $plan as $p ) {
			$product = $p['product'];
			$manages = $product->get_manage_stock();
			$before  = $manages ? (int) $product->get_stock_quantity() : null;
			$b_stat  = $product->get_stock_status();
			$target  = $p['target'];

			$d         = self::decide( $manages, $before, $b_stat, $target, $with_qty, $s['backorder_mode'] );
			$status    = $d['status'];
			$write_qty = $d['write_qty'];

			if ( ! $d['changed'] ) {
				++$report['unchanged'];
				continue;
			}

			if ( ! $with_qty && $product->is_type( 'variable' ) ) {
				$variable_warn[] = $product->get_name();
			}

			$report['changes'][] = array(
				'pid'    => $p['pid'],
				'code'   => $p['code'],
				'name'   => $product->get_name(),
				'sku'    => $product->get_sku(),
				'from'   => $before,
				'to'     => $target,
				'qty'    => $write_qty,
				'status' => $b_stat . ' → ' . $status,
				'reason' => $p['missing'] ? 'nicht mehr im Feed' : 'Bestandsmeldung',
			);
			++$report['updated'];

			if ( $dry_run ) {
				continue;
			}

			try {
				if ( $write_qty ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( $target );
					$product->set_backorders( $target > 0 ? $product->get_backorders() : self::backorders( $s['backorder_mode'] ) );
				}
				$product->set_stock_status( $status );
				$product->save();

				if ( $product->is_type( 'variation' ) ) {
					$parent = (int) $product->get_parent_id();
					if ( $parent > 0 ) {
						$parents[ $parent ] = $parent;
					}
				}
			} catch ( Exception $e ) {
				$report['errors'][] = sprintf( '#%d (%s): %s', $p['pid'], $p['code'], $e->getMessage() );
				TOS_Logger::error( sprintf( 'Produkt #%d konnte nicht gespeichert werden: %s', $p['pid'], $e->getMessage() ) );
			}
		}

		/* ---------------------------------------------------- 8. Elternprodukte nachziehen */
		if ( ! $dry_run && $parents && (int) $s['sync_variations'] === 1 ) {
			foreach ( $parents as $parent ) {
				if ( class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $parent );
					WC_Product_Variable::sync_stock_status( $parent );
				}
			}
			TOS_Logger::info( sprintf( '%d Elternprodukte nach Variantenänderung neu berechnet.', count( $parents ) ) );
		}

		if ( $variable_warn ) {
			$names = array_slice( array_unique( $variable_warn ), 0, 5 );
			$msg   = sprintf(
				'%d variable Produkte (%s%s) wurden nur im Status geändert. WooCommerce berechnet den Status variabler Produkte beim Speichern aus den Varianten neu — die Änderung hält dort womöglich nicht. Besser ist der Abgleich auf Variantenebene (die Tiger-One-SKU steht dort ohnehin).',
				count( array_unique( $variable_warn ) ),
				implode( ', ', $names ),
				count( array_unique( $variable_warn ) ) > 5 ? ' …' : ''
			);
			$report['errors'][] = $msg;
			TOS_Logger::warn( $msg );
		}

		if ( ! $dry_run && $report['updated'] > 0 ) {
			wc_delete_product_transients();
		}

		return self::finish( $report );
	}

	/**
	 * Entscheidet, was ein einzelnes Produkt braucht — absichtlich frei von
	 * WooCommerce, damit die Regel für sich nachvollziehbar und prüfbar bleibt.
	 *
	 * @param bool     $manages  Produkt verwaltet derzeit Bestand.
	 * @param int|null $before   Aktuelle Menge, null wenn kein Bestand verwaltet wird.
	 * @param string   $b_stat   Aktueller Bestandsstatus.
	 * @param int|null $target   Zielmenge; null heißt „vorrätig, Menge unbekannt".
	 * @param bool     $with_qty Ob Mengen überhaupt geschrieben werden sollen.
	 * @param string   $bo_mode  Wie „null" aussehen soll.
	 * @return array{changed:bool,status:string,write_qty:bool}
	 */
	public static function decide( $manages, $before, $b_stat, $target, $with_qty, $bo_mode ) {
		$status    = ( $target === null || $target > 0 ) ? 'instock' : self::zero_status( $bo_mode );
		$write_qty = $with_qty && $target !== null;

		$changed = $write_qty
			? ( ! $manages || $before !== $target || $b_stat !== $status )
			: ( $b_stat !== $status );

		return array(
			'changed'   => $changed,
			'status'    => $status,
			'write_qty' => $write_qty,
		);
	}

	private static function zero_status( $mode ) {
		return $mode === 'no' ? 'outofstock' : 'onbackorder';
	}

	private static function backorders( $mode ) {
		if ( $mode === 'yes' ) {
			return 'yes';
		}
		if ( $mode === 'notify' ) {
			return 'notify';
		}
		return 'no';
	}

	private static function fail( array $report, $message ) {
		$report['aborted']  = true;
		$report['errors'][] = $message;
		TOS_Logger::error( $message );
		return self::finish( $report );
	}

	private static function finish( array $report ) {
		$report['finished'] = current_time( 'mysql' );
		if ( ! $report['dry_run'] ) {
			update_option( 'tos_last_run', $report, false );
			update_option( 'tos_last_run_time', time(), false );
			TOS_Logger::info(
				sprintf(
					'Fertig. %d Artikel im Feed, %d Produkte geändert, %d unverändert, %d ohne Bestandsangabe übersprungen.',
					$report['articles'],
					$report['updated'],
					$report['unchanged'],
					$report['skipped']
				)
			);
			TOS_Logger::purge();
			TOS_Notifier::maybe_send( $report );
		}
		return $report;
	}
}
