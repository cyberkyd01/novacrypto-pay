<?php
/**
 * Payment monitor: cron + on-demand checks, matching explorer results to payments.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Monitor {

	/** @var NCP_Explorers */
	public $explorers;

	public function __construct() {
		$this->explorers = new NCP_Explorers();
		add_action( 'ncp_cron_check_payments', array( $this, 'cron_check' ) );
		add_action( 'ncp_cron_maintenance', array( $this, 'maintenance' ) );
	}

	public function cron_check() {
		if ( get_transient( 'ncp_cron_lock' ) ) {
			return;
		}
		set_transient( 'ncp_cron_lock', 1, 50 );
		ncp()->payments->expire_stale();
		$batch = ncp()->payments->pending_for_check( 40 );
		foreach ( $batch as $payment ) {
			$this->check( $payment );
		}
		// Late payments on expired invoices (last 24h), throttled to every 10 minutes.
		$this->check_late_payments();
		ncp()->notifications->process_webhook_queue();
		delete_transient( 'ncp_cron_lock' );
	}

	private function check_late_payments() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ncp_payments WHERE status = 'expired' AND received_crypto = 0 AND expires_at > %s AND (last_checked_at IS NULL OR last_checked_at < %s) ORDER BY last_checked_at ASC LIMIT 10",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - 600 )
			)
		);
		foreach ( $rows as $r ) {
			$this->check( ncp()->payments->get( $r->id ) );
		}
	}

	public function maintenance() {
		ncp()->logger->prune( 30 );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ncp_webhook_queue WHERE attempts >= 8 AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/**
	 * Check a single payment against the blockchain.
	 *
	 * @param object $payment
	 * @param bool   $force Ignore the min-interval throttle.
	 * @return object|WP_Error Updated payment.
	 */
	public function check( $payment, $force = false ) {
		if ( ! $payment ) {
			return new WP_Error( 'ncp_not_found', 'payment not found' );
		}
		if ( in_array( $payment->status, array( 'paid', 'cancelled', 'refunded' ), true ) ) {
			return $payment;
		}
		$min = (int) ncp()->settings->get( 'explorer_min_interval', 15 );
		if ( ! $force && $payment->last_checked_at && strtotime( $payment->last_checked_at . ' UTC' ) > time() - $min ) {
			return $payment;
		}
		ncp()->payments->update( $payment->id, array( 'last_checked_at' => current_time( 'mysql', true ) ) );

		if ( 'pending' === $payment->status && ncp()->payments->is_expired( $payment ) ) {
			ncp()->payments->set_status( $payment, 'expired', 'payment window elapsed' );
			$payment = ncp()->payments->get( $payment->id );
		}

		$coin = $payment->coin;
		if ( ! $coin ) {
			return new WP_Error( 'ncp_no_coin', 'coin no longer exists' );
		}
		$txs = $this->explorers->fetch( $coin, $payment->address, $payment );
		if ( is_wp_error( $txs ) ) {
			ncp()->logger->error( 'Explorer error for ' . $payment->uuid . ': ' . $txs->get_error_message(), 'monitor' );
			return $txs;
		}
		// A customer-submitted tx hash is verified directly (works even when list explorers lag).
		if ( ! empty( $payment->meta['customer_txid'] ) && ! in_array( $payment->meta['customer_txid'], wp_list_pluck( $payment->txs, 'txid' ), true ) ) {
			$direct = $this->explorers->lookup_tx( $coin, $payment->meta['customer_txid'], $payment->address );
			if ( is_array( $direct ) && ! empty( $direct['txid'] ) ) {
				$txs[] = $direct;
			}
		}
		$matched = $this->match( $payment, $txs );
		foreach ( $matched as $tx ) {
			ncp()->payments->add_transaction( $payment, $tx['txid'], $tx['amount'], $tx['confirmations'], $tx['time'], 'explorer' );
		}
		$was_expired = 'expired' === $payment->status;
		$updated     = ncp()->payments->evaluate( $payment );
		if ( $was_expired && (float) $updated->received_crypto > 0 ) {
			ncp()->payments->update_meta( $updated, 'late_payment', true );
			if ( 'expired' === $updated->status ) {
				// Funds arrived after expiry but not enough/confirmed: surface it.
				ncp()->payments->set_status( $updated, (float) $updated->received_crypto >= (float) $updated->amount_crypto * 0.99 ? 'confirming' : 'underpaid', 'late payment' );
				$updated = ncp()->payments->get( $updated->id );
			}
		}
		return $updated;
	}

	/**
	 * Decide which explorer transactions belong to this payment.
	 */
	private function match( $payment, array $txs ) {
		$created = strtotime( $payment->created_at . ' UTC' ) - 900;
		$unique  = ! empty( $payment->meta['unique_address'] );
		$expected = (float) $payment->amount_crypto;
		$known   = wp_list_pluck( $payment->txs, 'txid' );
		$out     = array();
		foreach ( $txs as $tx ) {
			if ( in_array( $tx['txid'], $known, true ) ) {
				$out[] = $tx; // Keep updating confirmations.
				continue;
			}
			if ( ! empty( $tx['time'] ) && $tx['time'] < $created ) {
				continue; // Predates this payment.
			}
			if ( $unique ) {
				$out[] = $tx;
				continue;
			}
			// Shared address: match by amount. Among all pending payments on this address the one
			// whose expected amount is closest to the tx amount wins (salted amounts keep this unambiguous).
			$lo = $expected * ( 1 - (float) ncp()->settings->get( 'underpay_tolerance', 1 ) / 100 ) - 1e-9;
			$hi = $expected * 1.10 + 1e-9;
			if ( $tx['amount'] < $lo || $tx['amount'] > $hi ) {
				continue;
			}
			$mine  = abs( $tx['amount'] - $expected );
			$other = false;
			foreach ( ncp()->payments->pending_amounts( $payment->coin_id, $payment->address ) as $amt ) {
				if ( abs( (float) $amt - $expected ) > 1e-12 && abs( $tx['amount'] - (float) $amt ) < $mine ) {
					$other = true;
					break;
				}
			}
			if ( ! $other ) {
				$out[] = $tx;
			}
		}
		return apply_filters( 'ncp_match_transactions', $out, $payment, $txs );
	}
}
