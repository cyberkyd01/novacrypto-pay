<?php
/**
 * WP-CLI commands: wp ncp <command>
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_CLI {

	/**
	 * Run the payment monitor once (same as the cron job).
	 *
	 * ## EXAMPLES
	 *     wp ncp check
	 */
	public function check() {
		$batch = ncp()->payments->pending_for_check( 200 );
		WP_CLI::log( sprintf( 'Checking %d payment(s)…', count( $batch ) ) );
		foreach ( $batch as $p ) {
			$r = ncp()->monitor->check( $p, true );
			WP_CLI::log( sprintf( '#%d %s %s -> %s', $p->id, $p->coin_id, $p->status, is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : $r->status ) );
		}
		ncp()->notifications->process_webhook_queue();
		WP_CLI::success( 'Done.' );
	}

	/** Expire stale pending payments. */
	public function expire() {
		WP_CLI::success( ncp()->payments->expire_stale() . ' expired.' );
	}

	/** Print live rates for enabled coins. */
	public function rates() {
		$rows = array();
		foreach ( ncp()->coins->enabled() as $id => $c ) {
			$r      = ncp()->rates->get( $id );
			$rows[] = array( 'coin' => $id, 'rate' => is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : $r['rate'], 'provider' => is_wp_error( $r ) ? '' : $r['provider'] );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'coin', 'rate', 'provider' ) );
	}

	/**
	 * Preview HD addresses.
	 *
	 * <coin> [<start>] [<count>]
	 */
	public function derive( $args ) {
		list( $coin_id ) = $args;
		$start = (int) ( $args[1] ?? 0 );
		$count = (int) ( $args[2] ?? 5 );
		$w     = ncp()->wallets->get( $coin_id );
		$coin  = ncp()->coins->get( $coin_id );
		if ( ! $coin || ! $w['xpub'] ) {
			WP_CLI::error( 'No xpub configured for ' . $coin_id );
		}
		$hint = in_array( $coin['network'], array( 'btc', 'ltc', 'doge' ), true ) ? $coin['network'] : 'evm';
		for ( $i = $start; $i < $start + $count; $i++ ) {
			$a = NCP_BIP32::address_at( $w['xpub'], $i, (int) $w['hd_change'], $hint, $w['xpub_script'] );
			WP_CLI::log( $i . "\t" . ( is_wp_error( $a ) ? $a->get_error_message() : $a ) );
		}
	}

	/**
	 * Show a payment.
	 *
	 * <uuid>
	 */
	public function payment( $args ) {
		$p = ncp()->payments->get_by_uuid( $args[0] );
		if ( ! $p ) {
			WP_CLI::error( 'Not found' );
		}
		WP_CLI::log( wp_json_encode( ncp()->payments->to_public_array( $p ), JSON_PRETTY_PRINT ) );
	}
}
