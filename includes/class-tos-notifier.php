<?php
defined( 'ABSPATH' ) || exit;

class TOS_Notifier {

	public static function maybe_send( array $report ) {
		$when = TOS_Settings::get( 'notify_on', 'error' );
		if ( $when === 'never' ) {
			return;
		}
		$has_problem = $report['aborted'] || ! empty( $report['errors'] );
		if ( $when === 'error' && ! $has_problem ) {
			return;
		}
		if ( $when === 'changes' && ! $has_problem && empty( $report['changes'] ) ) {
			return;
		}

		$to = TOS_Settings::get( 'notify_email', '' );
		if ( $to === '' ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = $report['aborted']
			? '[Tiger One] Bestandsabgleich abgebrochen'
			: sprintf( '[Tiger One] Bestandsabgleich: %d Änderungen', count( $report['changes'] ) );

		$l   = array();
		$l[] = 'Artikel im Feed: ' . $report['articles'] . '  ·  davon im Shop: ' . $report['matched'];
		foreach ( $report['brands'] as $b ) {
			$l[] = '  · Brand Code ' . $b['code'] . ': ' . $b['articles'] . ' Artikel';
		}
		$l[] = 'Geändert: ' . $report['updated'] . '  ·  Unverändert: ' . $report['unchanged'] . '  ·  Übersprungen: ' . $report['skipped'];
		$l[] = 'Nicht mehr im Feed: ' . $report['missing'];
		$l[] = '';
		if ( $report['errors'] ) {
			$l[] = 'Meldungen:';
			foreach ( $report['errors'] as $e ) {
				$l[] = '  · ' . $e;
			}
			$l[] = '';
		}
		if ( $report['changes'] ) {
			$l[] = 'Änderungen:';
			foreach ( array_slice( $report['changes'], 0, 100 ) as $c ) {
				$l[] = empty( $c['qty'] )
					? sprintf( '  · %s (%s) nur Status: %s  [%s]', $c['name'], $c['code'], $c['status'], $c['reason'] )
					: sprintf(
						'  · %s (%s) %s → %s  [%s]',
						$c['name'],
						$c['code'],
						$c['from'] === null ? '—' : $c['from'],
						$c['to'],
						$c['reason']
					);
			}
			if ( count( $report['changes'] ) > 100 ) {
				$l[] = sprintf( '  … und %d weitere', count( $report['changes'] ) - 100 );
			}
		}
		$l[] = '';
		$l[] = admin_url( 'admin.php?page=tos-log' );

		wp_mail( $to, $subject, implode( "\n", $l ) );
	}
}
