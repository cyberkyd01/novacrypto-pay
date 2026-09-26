<?php
/**
 * WooCommerce glue: order status sync, crypto discount fee, admin order box,
 * per-product crypto prices, order list column.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_WC_Integration {

	public function __construct() {
		add_action( 'ncp_payment_status_changed', array( $this, 'sync_order' ), 10, 4 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'discount_fee' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'remember_method' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'woocommerce_product_options_pricing', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_filter( 'woocommerce_gateway_title', array( $this, 'gateway_title' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details' ) );
	}

	public function sync_order( $payment, $old, $new, $note ) {
		if ( 'woocommerce' !== $payment->source ) {
			return;
		}
		$order = wc_get_order( (int) $payment->source_id );
		if ( ! $order ) {
			return;
		}
		$gw     = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways()['novacrypto'] ?? null : null;
		$symbol = $payment->coin['symbol'] ?? '';
		switch ( $new ) {
			case 'detected':
			case 'confirming':
				$order->add_order_note( sprintf( __( 'Crypto payment detected: %1$s %2$s received (%3$d/%4$d confirmations).', 'novacrypto-pay' ), NCP_Coins::format_amount( $payment->received_crypto ), $symbol, $payment->confirmations, $payment->required_confirmations ) );
				if ( $order->has_status( 'pending' ) ) {
					$order->update_status( 'on-hold', __( 'Crypto payment seen on-chain, awaiting confirmations.', 'novacrypto-pay' ) );
				}
				break;
			case 'paid':
				if ( ! $order->is_paid() ) {
					$order->set_transaction_id( $payment->txs ? $payment->txs[0]->txid : '' );
					$order->payment_complete( $payment->txs ? $payment->txs[0]->txid : '' );
					$order->add_order_note( sprintf( __( 'Crypto payment confirmed: %1$s %2$s.', 'novacrypto-pay' ), NCP_Coins::format_amount( $payment->received_crypto ), $symbol ) );
					$target = $gw ? $gw->get_option( 'order_status_paid', 'processing' ) : 'processing';
					if ( 'completed' === $target && ! $order->has_status( 'completed' ) ) {
						$order->update_status( 'completed' );
					}
				}
				break;
			case 'underpaid':
				$order->update_status( 'on-hold', sprintf( __( 'Crypto payment UNDERPAID: received %1$s of %2$s %3$s. Waiting for the remainder or manual review.', 'novacrypto-pay' ), NCP_Coins::format_amount( $payment->received_crypto ), NCP_Coins::format_amount( $payment->amount_crypto ), $symbol ) );
				break;
			case 'expired':
				$order->add_order_note( __( 'Crypto payment window expired without payment.', 'novacrypto-pay' ) );
				if ( $gw && 'yes' === $gw->get_option( 'auto_cancel', 'no' ) && $order->has_status( 'pending' ) ) {
					$order->update_status( 'cancelled', __( 'Cancelled: crypto payment expired.', 'novacrypto-pay' ) );
				}
				break;
			case 'cancelled':
				if ( $order->has_status( 'pending' ) && false === strpos( $note, 'changed coin' ) ) {
					$order->update_status( 'cancelled', __( 'Crypto payment cancelled.', 'novacrypto-pay' ) );
				}
				break;
			case 'refunded':
				$order->add_order_note( __( 'Crypto payment marked refunded.', 'novacrypto-pay' ) );
				break;
		}
	}

	/** Remember the chosen payment method during checkout AJAX so the discount fee can apply. */
	public function remember_method( $post_data ) {
		parse_str( $post_data, $data );
		if ( WC()->session && isset( $data['payment_method'] ) ) {
			WC()->session->set( 'chosen_payment_method', sanitize_key( $data['payment_method'] ) );
		}
	}

	public function discount_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$disc = (float) ncp()->settings->get( 'crypto_discount', 0 );
		if ( $disc <= 0 || ! WC()->session || 'novacrypto' !== WC()->session->get( 'chosen_payment_method' ) ) {
			return;
		}
		$subtotal = (float) $cart->get_subtotal() + (float) $cart->get_shipping_total();
		$cart->add_fee( sprintf( __( 'Crypto discount (%s%%)', 'novacrypto-pay' ), $disc ), -1 * round( $subtotal * $disc / 100, 2 ), false );
	}

	public function gateway_title( $title, $id ) {
		return $title;
	}

	public function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'ncp_order_box', __( 'Crypto payment', 'novacrypto-pay' ), array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || 'novacrypto' !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'Not a crypto order.', 'novacrypto-pay' ) . '</p>';
			return;
		}
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order->get_id(), true );
		if ( ! $payment ) {
			echo '<p>' . esc_html__( 'No payment record.', 'novacrypto-pay' ) . '</p>';
			return;
		}
		$d = ncp()->payments->to_public_array( $payment );
		echo '<p><span class="ncp-badge ncp-badge-' . esc_attr( $payment->status ) . '">' . esc_html( $d['status_label'] ) . '</span></p>';
		echo '<p><strong>' . esc_html( $d['amount_crypto'] . ' ' . $d['symbol'] ) . '</strong> · ' . esc_html( $d['network'] ) . '<br><code style="word-break:break-all">' . esc_html( $payment->address ) . '</code></p>';
		echo '<p>' . esc_html__( 'Received:', 'novacrypto-pay' ) . ' ' . esc_html( $d['received_crypto'] ) . ' · ' . esc_html__( 'Conf:', 'novacrypto-pay' ) . ' ' . (int) $d['confirmations'] . '/' . (int) $d['required_confirmations'] . '</p>';
		if ( $payment->refund_address ) {
			echo '<p>' . esc_html__( 'Refund address:', 'novacrypto-pay' ) . '<br><code style="word-break:break-all">' . esc_html( $payment->refund_address ) . '</code></p>';
		}
		foreach ( $d['txs'] as $tx ) {
			echo '<p style="word-break:break-all">' . ( $tx['url'] ? '<a href="' . esc_url( $tx['url'] ) . '" target="_blank">' . esc_html( substr( $tx['txid'], 0, 20 ) ) . '…</a>' : esc_html( $tx['txid'] ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ncp-payments&action=view&id=' . $payment->id ) ) . '">' . esc_html__( 'Manage payment', 'novacrypto-pay' ) . '</a></p>';
	}

	public function product_fields() {
		$coins = ncp()->coins->enabled();
		if ( ! $coins ) {
			return;
		}
		echo '<div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Fixed crypto prices (optional)', 'novacrypto-pay' ) . '</strong><br><span class="description">' . esc_html__( 'If set, customers paying with this coin are charged this exact amount instead of the converted fiat price.', 'novacrypto-pay' ) . '</span></p>';
		foreach ( $coins as $id => $c ) {
			woocommerce_wp_text_input(
				array(
					'id'          => '_ncp_price_' . $id,
					'label'       => $c['name'] . ' (' . $c['symbol'] . ')',
					'data_type'   => 'decimal',
					'desc_tip'    => false,
				)
			);
		}
		echo '</div>';
	}

	public function save_product_fields( $post_id ) {
		$product = wc_get_product( $post_id );
		foreach ( ncp()->coins->enabled() as $id => $c ) {
			$key = '_ncp_price_' . $id;
			$val = isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore
			if ( '' === $val ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, wc_format_decimal( $val ) );
			}
		}
		$product->save();
	}

	public function order_details( $order ) {
		if ( 'novacrypto' !== $order->get_payment_method() ) {
			return;
		}
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order->get_id(), true );
		if ( ! $payment || 'paid' === $payment->status ) {
			return;
		}
		if ( in_array( $payment->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true ) && ! is_checkout_pay_page() ) {
			echo '<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url( true ) ) . '">' . esc_html__( 'Complete crypto payment', 'novacrypto-pay' ) . '</a></p>';
		}
	}
}
