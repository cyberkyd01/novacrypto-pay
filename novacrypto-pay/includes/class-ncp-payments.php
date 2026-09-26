<?php
/**
 * Payment records: creation, lookup, state machine.
 *
 * Statuses: pending -> detected (seen in mempool / 0 conf) -> confirming
 *           -> paid | underpaid | overpaid(=paid) | expired | cancelled | refunded
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Payments {

	const STATUSES = array( 'pending', 'detected', 'confirming', 'paid', 'underpaid', 'expired', 'cancelled', 'refunded' );

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'ncp_payments';
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'    => __( 'Awaiting payment', 'novacrypto-pay' ),
			'detected'   => __( 'Payment detected', 'novacrypto-pay' ),
			'confirming' => __( 'Confirming', 'novacrypto-pay' ),
			'paid'       => __( 'Paid', 'novacrypto-pay' ),
			'underpaid'  => __( 'Underpaid', 'novacrypto-pay' ),
			'expired'    => __( 'Expired', 'novacrypto-pay' ),
			'cancelled'  => __( 'Cancelled', 'novacrypto-pay' ),
			'refunded'   => __( 'Refunded', 'novacrypto-pay' ),
		);
		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Create a payment.
	 *
	 * @param array $args {
	 *   source, source_id, coin_id, amount_fiat, fiat_currency, customer_email,
	 *   customer_name, title, return_url, meta(array), apply_discount(bool)
	 * }
	 * @return object|WP_Error
	 */
	public function create( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'source'         => 'api',
				'source_id'      => '',
				'coin_id'        => '',
				'amount_fiat'    => 0,
				'fiat_currency'  => ncp()->settings->store_currency(),
				'customer_email' => '',
				'customer_name'  => '',
				'title'          => '',
				'return_url'     => '',
				'meta'           => array(),
				'apply_discount' => true,
				'amount_crypto'  => null, // Set to price directly in crypto (product-level crypto price).
			)
		);
		$coin = ncp()->coins->get( $args['coin_id'] );
		if ( ! $coin || ! ncp()->wallets->is_enabled( $args['coin_id'] ) ) {
			return new WP_Error( 'ncp_coin_disabled', __( 'This cryptocurrency is not available.', 'novacrypto-pay' ) );
		}
		$fiat  = (float) $args['amount_fiat'];
		$disc  = (float) ncp()->settings->get( 'crypto_discount', 0 );
		$meta  = (array) $args['meta'];
		if ( $args['apply_discount'] && $disc > 0 && ! isset( $meta['discount_applied'] ) ) {
			$meta['discount_percent'] = $disc;
			$meta['fiat_before_discount'] = $fiat;
			$fiat = round( $fiat * ( 1 - $disc / 100 ), 2 );
			$meta['discount_applied'] = true;
		}
		$min = (array) ncp()->settings->get( 'coin_min_amount', array() );
		if ( ! empty( $min[ $args['coin_id'] ] ) && $fiat < (float) $min[ $args['coin_id'] ] ) {
			/* translators: 1: amount 2: currency */
			return new WP_Error( 'ncp_min_amount', sprintf( __( 'Minimum amount for this coin is %1$s %2$s.', 'novacrypto-pay' ), $min[ $args['coin_id'] ], $args['fiat_currency'] ) );
		}

		if ( null !== $args['amount_crypto'] && (float) $args['amount_crypto'] > 0 ) {
			$amount   = (float) $args['amount_crypto'];
			$rate     = $amount > 0 ? $fiat / $amount : 0;
			$provider = 'fixed';
		} else {
			$conv = ncp()->rates->convert( $fiat, $args['coin_id'], $args['fiat_currency'] );
			if ( is_wp_error( $conv ) ) {
				return $conv;
			}
			$amount   = (float) $conv['amount'];
			$rate     = $conv['rate'];
			$provider = $conv['provider'];
		}

		$alloc = ncp()->wallets->allocate( $args['coin_id'] );
		if ( is_wp_error( $alloc ) ) {
			return $alloc;
		}
		$meta['unique_address'] = (bool) $alloc['unique'];

		// Unique-amount salt for shared addresses so concurrent payments can be told apart.
		$fixed = null !== $args['amount_crypto'] && (float) $args['amount_crypto'] > 0;
		if ( ! $fixed && ! $alloc['unique'] && ncp()->settings->get( 'unique_amounts', true ) && 'lightning' !== $coin['family'] ) {
			$amount = $this->salt_amount( $amount, $coin, $alloc['address'] );
		}
		$amount = number_format( $amount, min( 8, (int) $coin['decimals'] ), '.', '' );

		$now     = current_time( 'mysql', true );
		$window  = max( 5, (int) ncp()->settings->get( 'payment_window', 60 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $window * MINUTE_IN_SECONDS );
		$uuid    = wp_generate_uuid4();

		$row = array(
			'uuid'                   => $uuid,
			'source'                 => sanitize_key( $args['source'] ),
			'source_id'              => (string) $args['source_id'],
			'status'                 => 'pending',
			'coin_id'                => $args['coin_id'],
			'address'                => $alloc['address'],
			'address_index'          => $alloc['index'],
			'amount_crypto'          => $amount,
			'amount_fiat'            => $fiat,
			'fiat_currency'          => strtoupper( $args['fiat_currency'] ),
			'rate'                   => $rate,
			'rate_provider'          => $provider,
			'required_confirmations' => ncp()->settings->get( 'zero_conf' ) ? 0 : ncp()->coins->confirmations( $args['coin_id'] ),
			'customer_email'         => sanitize_email( $args['customer_email'] ),
			'customer_name'          => sanitize_text_field( $args['customer_name'] ),
			'title'                  => sanitize_text_field( $args['title'] ),
			'return_url'             => esc_url_raw( $args['return_url'] ),
			'meta'                   => wp_json_encode( $meta ),
			'created_at'             => $now,
			'expires_at'             => $expires,
			'updated_at'             => $now,
		);

		// Lightning: create a BOLT11 invoice now.
		if ( 'lightning' === $coin['family'] ) {
			$inv = NCP_Lightning::create_invoice( $amount, $args['title'] ?: get_bloginfo( 'name' ) . ' #' . $args['source_id'] );
			if ( is_wp_error( $inv ) ) {
				return $inv;
			}
			$row['address']            = $inv['payment_request'];
			$meta['ln_payment_hash']   = $inv['payment_hash'];
			$meta['ln_verify_url']     = $inv['verify_url'] ?? '';
			$row['meta']               = wp_json_encode( $meta );
		}

		$ok = $wpdb->insert( $this->table(), $row );
		if ( ! $ok ) {
			ncp()->logger->error( 'DB insert failed: ' . $wpdb->last_error, 'payments' );
			return new WP_Error( 'ncp_db', __( 'Could not create payment.', 'novacrypto-pay' ) );
		}
		$payment = $this->get( $wpdb->insert_id );
		ncp()->logger->info( 'Payment created ' . $uuid . ' ' . $amount . ' ' . $coin['symbol'], 'payments', array( 'source' => $args['source'], 'source_id' => $args['source_id'] ) );
		do_action( 'ncp_payment_created', $payment );
		return $payment;
	}

	/** Add a tiny deterministic-random increment in the last decimals so amounts differ per payment. */
	private function salt_amount( $amount, array $coin, $address ) {
		$dec  = min( 8, (int) $coin['decimals'] );
		$busy = $this->pending_amounts( $coin['id'], $address );
		for ( $try = 0; $try < 50; $try++ ) {
			$unit  = pow( 10, -$dec );
			$salt  = wp_rand( 1, 999 ) * $unit * ( $coin['stable'] ? 1 : ( 'utxo' === $coin['family'] ? 10 : 100 ) );
			$cand  = number_format( $amount + $salt, $dec, '.', '' );
			if ( ! in_array( $cand, $busy, true ) ) {
				return (float) $cand;
			}
		}
		return $amount;
	}

	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_uuid( $uuid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid ) ); // phpcs:ignore
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_source( $source, $source_id, $any_status = false ) {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source = %s AND source_id = %s", $source, (string) $source_id ); // phpcs:ignore
		if ( ! $any_status ) {
			$sql .= " AND status NOT IN ('cancelled','expired')";
		}
		$row = $wpdb->get_row( $sql . ' ORDER BY id DESC LIMIT 1' ); // phpcs:ignore
		return $row ? $this->hydrate( $row ) : null;
	}

	private function hydrate( $row ) {
		$row->meta   = $row->meta ? (array) json_decode( $row->meta, true ) : array();
		$row->coin   = ncp()->coins->get( $row->coin_id );
		$row->txs    = $this->transactions( $row->id );
		$row->remaining = max( 0, (float) $row->amount_crypto - (float) $row->received_crypto );
		return $row;
	}

	public function transactions( $payment_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ncp_transactions WHERE payment_id = %d ORDER BY id ASC", $payment_id ) ); // phpcs:ignore
	}

	public function update( $id, array $fields ) {
		global $wpdb;
		if ( isset( $fields['meta'] ) && is_array( $fields['meta'] ) ) {
			$fields['meta'] = wp_json_encode( $fields['meta'] );
		}
		$fields['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	public function update_meta( $payment, $key, $value ) {
		$meta         = $payment->meta;
		$meta[ $key ] = $value;
		$this->update( $payment->id, array( 'meta' => $meta ) );
		$payment->meta = $meta;
	}

	/**
	 * Transition status and fire hooks.
	 */
	public function set_status( $payment, $new, $note = '' ) {
		if ( is_numeric( $payment ) ) {
			$payment = $this->get( $payment );
		}
		if ( ! $payment || ! in_array( $new, self::STATUSES, true ) ) {
			return false;
		}
		$old = $payment->status;
		if ( $old === $new ) {
			return true;
		}
		// Terminal states are sticky unless admin forces.
		$fields = array( 'status' => $new );
		if ( 'paid' === $new ) {
			$fields['paid_at'] = current_time( 'mysql', true );
		}
		$this->update( $payment->id, $fields );
		$payment->status = $new;
		if ( isset( $fields['paid_at'] ) ) {
			$payment->paid_at = $fields['paid_at'];
		}
		ncp()->logger->info( "Payment {$payment->uuid}: {$old} -> {$new}" . ( $note ? " ({$note})" : '' ), 'payments' );
		do_action( 'ncp_payment_status_changed', $payment, $old, $new, $note );
		do_action( 'ncp_payment_' . $new, $payment, $old, $note );
		return true;
	}

	/**
	 * Record a transaction (idempotent by txid+coin). Returns true if newly added.
	 */
	public function add_transaction( $payment, $txid, $amount, $confirmations = 0, $block_time = null, $source = '' ) {
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ncp_transactions WHERE txid = %s AND coin_id = %s", $txid, $payment->coin_id ) ); // phpcs:ignore
		if ( $existing ) {
			if ( (int) $existing->payment_id !== (int) $payment->id ) {
				// Duplicate-tx protection: the same on-chain tx cannot pay two orders.
				ncp()->logger->error( "txid {$txid} already assigned to payment #{$existing->payment_id}; refusing for #{$payment->id}", 'fraud' );
				return false;
			}
			if ( (int) $existing->confirmations !== (int) $confirmations ) {
				$wpdb->update( $wpdb->prefix . 'ncp_transactions', array( 'confirmations' => (int) $confirmations ), array( 'id' => $existing->id ) );
			}
			return false;
		}
		$wpdb->insert(
			$wpdb->prefix . 'ncp_transactions',
			array(
				'payment_id'    => $payment->id,
				'txid'          => $txid,
				'coin_id'       => $payment->coin_id,
				'amount'        => number_format( (float) $amount, 12, '.', '' ),
				'confirmations' => (int) $confirmations,
				'block_time'    => $block_time ? gmdate( 'Y-m-d H:i:s', (int) $block_time ) : null,
				'source'        => $source,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		do_action( 'ncp_transaction_added', $payment, $txid, $amount, $confirmations );
		return true;
	}

	/**
	 * Recompute received amount / confirmations and move the status accordingly.
	 */
	public function evaluate( $payment ) {
		$payment = $this->get( $payment->id );
		if ( ! $payment || in_array( $payment->status, array( 'cancelled', 'refunded' ), true ) ) {
			return $payment;
		}
		$received = 0.0;
		$min_conf = PHP_INT_MAX;
		foreach ( $payment->txs as $tx ) {
			$received += (float) $tx->amount;
			$min_conf  = min( $min_conf, (int) $tx->confirmations );
		}
		if ( ! $payment->txs ) {
			$min_conf = 0;
		}
		$this->update( $payment->id, array( 'received_crypto' => number_format( $received, 12, '.', '' ), 'confirmations' => $min_conf ) );
		$payment->received_crypto = $received;
		$payment->confirmations   = $min_conf;

		if ( $received <= 0 ) {
			return $payment;
		}
		$expected  = (float) $payment->amount_crypto;
		$tolerance = (float) ncp()->settings->get( 'underpay_tolerance', 1 ) / 100;
		$enough    = $received >= $expected * ( 1 - $tolerance ) - 1e-12;
		$confirmed = $min_conf >= (int) $payment->required_confirmations;

		if ( $enough ) {
			if ( $confirmed ) {
				if ( $received > $expected * 1.001 ) {
					$this->update_meta( $payment, 'overpaid', $received - $expected );
				}
				if ( 'paid' !== $payment->status ) {
					$this->set_status( $payment, 'paid', $received . ' received, ' . $min_conf . ' conf' );
				}
			} elseif ( 'confirming' !== $payment->status ) {
				$this->set_status( $payment, 'detected' === $payment->status || 'pending' === $payment->status ? 'confirming' : $payment->status, $min_conf . '/' . $payment->required_confirmations . ' confirmations' );
			}
		} else {
			$action = ncp()->settings->get( 'underpay_action', 'hold' );
			if ( 'accept' === $action && $confirmed ) {
				$this->update_meta( $payment, 'underpaid_accepted', $expected - $received );
				$this->set_status( $payment, 'paid', 'underpaid but accepted' );
			} elseif ( 'underpaid' !== $payment->status && $confirmed ) {
				$this->set_status( $payment, 'underpaid', $received . ' of ' . $expected );
			} elseif ( in_array( $payment->status, array( 'pending' ), true ) ) {
				$this->set_status( $payment, 'detected', 'partial ' . $received . ' of ' . $expected );
			}
		}
		return $this->get( $payment->id );
	}

	public function is_expired( $payment ) {
		return strtotime( $payment->expires_at . ' UTC' ) < time();
	}

	public function extend( $payment, $minutes ) {
		$this->update( $payment->id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $minutes * 60 ) ) );
	}

	/** Refresh the crypto amount at the current rate (used when a payment link is re-opened after expiry). */
	public function requote( $payment ) {
		$conv = ncp()->rates->convert( $payment->amount_fiat, $payment->coin_id, $payment->fiat_currency );
		if ( is_wp_error( $conv ) ) {
			return $conv;
		}
		$this->update( $payment->id, array( 'amount_crypto' => $conv['amount'], 'rate' => $conv['rate'], 'rate_provider' => $conv['provider'], 'status' => 'pending', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) ncp()->settings->get( 'payment_window', 60 ) * 60 ) ) );
		return $this->get( $payment->id );
	}

	/* ---------- Queries ---------- */

	public function pending_for_check( $limit = 50 ) {
		global $wpdb;
		$min = (int) ncp()->settings->get( 'explorer_min_interval', 15 );
		return array_map(
			array( $this, 'hydrate' ),
			$wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT * FROM {$this->table()} WHERE status IN ('pending','detected','confirming','underpaid')
					 AND (last_checked_at IS NULL OR last_checked_at < %s)
					 AND created_at > %s
					 ORDER BY last_checked_at ASC LIMIT %d",
					gmdate( 'Y-m-d H:i:s', time() - $min ),
					gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
					$limit
				)
			)
		);
	}

	public function expire_stale() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'pending' AND expires_at < %s", current_time( 'mysql', true ) ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$this->set_status( $this->hydrate( $row ), 'expired', 'payment window elapsed' );
		}
		return count( $rows );
	}

	public function busy_addresses( $coin_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT address FROM {$this->table()} WHERE coin_id = %s AND status IN ('pending','detected','confirming','underpaid')", $coin_id ) ); // phpcs:ignore
	}

	public function pending_amounts( $coin_id, $address ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT amount_crypto FROM {$this->table()} WHERE coin_id = %s AND address = %s AND status IN ('pending','detected','confirming','underpaid')", $coin_id, $address ) ); // phpcs:ignore
	}

	/** An HD index from an expired/cancelled payment with zero received funds may be reused. */
	public function reusable_hd_index( $coin_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT address, address_index FROM {$this->table()} WHERE coin_id = %s AND address_index IS NOT NULL AND status IN ('expired','cancelled') AND received_crypto = 0 AND meta NOT LIKE '%%index_reused%%' ORDER BY id ASC LIMIT 1", $coin_id ) ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		// Only reuse if nothing else pending uses this address.
		$busy = $this->busy_addresses( $coin_id );
		if ( in_array( $row->address, $busy, true ) ) {
			return null;
		}
		$ids = $wpdb->get_results( $wpdb->prepare( "SELECT id, meta FROM {$this->table()} WHERE coin_id = %s AND address = %s AND status IN ('expired','cancelled')", $coin_id, $row->address ) ); // phpcs:ignore
		foreach ( $ids as $r ) {
			$m                 = $r->meta ? (array) json_decode( $r->meta, true ) : array();
			$m['index_reused'] = 1;
			$this->update( $r->id, array( 'meta' => $m ) );
		}
		return array( 'address' => $row->address, 'index' => (int) $row->address_index );
	}

	public function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'status' => '', 'coin_id' => '', 'source' => '', 'search' => '', 'from' => '', 'to' => '', 'per_page' => 20, 'page' => 1, 'orderby' => 'id', 'order' => 'DESC' ) );
		$where = array( '1=1' );
		if ( $args['status'] ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( $args['coin_id'] ) {
			$where[] = $wpdb->prepare( 'coin_id = %s', $args['coin_id'] );
		}
		if ( $args['source'] ) {
			$where[] = $wpdb->prepare( 'source = %s', $args['source'] );
		}
		if ( $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(uuid LIKE %s OR address LIKE %s OR customer_email LIKE %s OR source_id LIKE %s OR title LIKE %s)', $like, $like, $like, $like, $like );
		}
		if ( $args['from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $args['from'] . ' 00:00:00' );
		}
		if ( $args['to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $args['to'] . ' 23:59:59' );
		}
		$orderby = in_array( $args['orderby'], array( 'id', 'created_at', 'amount_fiat', 'status', 'coin_id' ), true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$w       = implode( ' AND ', $where );
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}" ); // phpcs:ignore
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", (int) $args['per_page'], $offset ) ); // phpcs:ignore
		return array( 'total' => $total, 'items' => array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/** Aggregate stats for the dashboard. */
	public function stats( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$t     = $this->table();
		return array(
			'paid_count'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status='paid' AND created_at >= %s", $since ) ), // phpcs:ignore
			'paid_fiat'    => (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount_fiat) FROM {$t} WHERE status='paid' AND created_at >= %s", $since ) ), // phpcs:ignore
			'pending'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('pending','detected','confirming')" ), // phpcs:ignore
			'underpaid'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='underpaid'" ), // phpcs:ignore
			'expired'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status='expired' AND created_at >= %s", $since ) ), // phpcs:ignore
			'by_coin'      => $wpdb->get_results( $wpdb->prepare( "SELECT coin_id, COUNT(*) c, SUM(amount_fiat) fiat, SUM(received_crypto) crypto FROM {$t} WHERE status='paid' AND created_at >= %s GROUP BY coin_id ORDER BY fiat DESC", $since ) ), // phpcs:ignore
			'by_day'       => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) c, SUM(amount_fiat) fiat FROM {$t} WHERE status='paid' AND created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC", $since ) ), // phpcs:ignore
			'conversion'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", $since ) ), // phpcs:ignore
		);
	}

	public function public_url( $payment ) {
		return NCP_Frontend::pay_url( $payment->uuid );
	}

	/** Data safe to expose to the browser / REST. */
	public function to_public_array( $payment ) {
		$coin = $payment->coin;
		return array(
			'uuid'                   => $payment->uuid,
			'status'                 => $payment->status,
			'status_label'           => self::status_label( $payment->status ),
			'coin'                   => $payment->coin_id,
			'coin_name'              => $coin ? $coin['name'] : $payment->coin_id,
			'symbol'                 => $coin ? $coin['symbol'] : '',
			'network'                => $coin ? ncp()->coins->network_label( $coin ) : '',
			'address'                => $payment->address,
			'amount_crypto'          => NCP_Coins::format_amount( $payment->amount_crypto, $coin ? $coin['decimals'] : 8 ),
			'amount_fiat'            => (float) $payment->amount_fiat,
			'fiat_currency'          => $payment->fiat_currency,
			'rate'                   => (float) $payment->rate,
			'received_crypto'        => NCP_Coins::format_amount( $payment->received_crypto, $coin ? $coin['decimals'] : 8 ),
			'remaining_crypto'       => NCP_Coins::format_amount( max( 0, $payment->amount_crypto - $payment->received_crypto ), $coin ? $coin['decimals'] : 8 ),
			'confirmations'          => (int) $payment->confirmations,
			'required_confirmations' => (int) $payment->required_confirmations,
			'expires_at'             => $payment->expires_at,
			'expires_in'             => max( 0, strtotime( $payment->expires_at . ' UTC' ) - time() ),
			'created_at'             => $payment->created_at,
			'paid_at'                => $payment->paid_at,
			'return_url'             => $payment->return_url,
			'title'                  => $payment->title,
			'payment_url'            => $this->public_url( $payment ),
			'uri'                    => NCP_Frontend::payment_uri( $payment ),
			'txs'                    => array_map(
				function ( $tx ) use ( $coin ) {
					return array(
						'txid'          => $tx->txid,
						'amount'        => NCP_Coins::format_amount( $tx->amount, $coin ? $coin['decimals'] : 8 ),
						'confirmations' => (int) $tx->confirmations,
						'url'           => $coin && $coin['explorer'] ? sprintf( $coin['explorer'], $tx->txid ) : '',
					);
				},
				$payment->txs
			),
		);
	}
}
