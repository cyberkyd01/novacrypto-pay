<?php
/**
 * WooCommerce payment gateway.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_WC_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'novacrypto';
		$this->icon               = apply_filters( 'ncp_wc_icon', NCP_URL . 'assets/img/gateway.svg' );
		$this->has_fields         = true;
		$this->method_title       = __( 'NovaCrypto Pay (Cryptocurrency)', 'novacrypto-pay' );
		$this->method_description = __( 'Accept Bitcoin, Ethereum, Lightning, stablecoins and more directly to your own wallets. Configure coins under NovaCrypto Pay → Settings.', 'novacrypto-pay' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=ncp-settings' ) ) . '">' . __( 'Open NovaCrypto settings', 'novacrypto-pay' ) . '</a>';
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', ncp()->settings->get( 'title' ) );
		$this->description = $this->get_option( 'description', ncp()->settings->get( 'description' ) );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array( 'title' => __( 'Enable/Disable', 'novacrypto-pay' ), 'type' => 'checkbox', 'label' => __( 'Enable cryptocurrency payments', 'novacrypto-pay' ), 'default' => 'yes' ),
			'title'       => array( 'title' => __( 'Title', 'novacrypto-pay' ), 'type' => 'text', 'default' => ncp()->settings->get( 'title' ) ),
			'description' => array( 'title' => __( 'Description', 'novacrypto-pay' ), 'type' => 'textarea', 'default' => ncp()->settings->get( 'description' ) ),
			'order_status_paid' => array( 'title' => __( 'Order status after payment', 'novacrypto-pay' ), 'type' => 'select', 'default' => 'processing', 'options' => array( 'processing' => __( 'Processing (default)', 'novacrypto-pay' ), 'completed' => __( 'Completed', 'novacrypto-pay' ) ) ),
			'show_coins_in_title' => array( 'title' => __( 'Show coin icons next to the title', 'novacrypto-pay' ), 'type' => 'checkbox', 'default' => 'yes' ),
			'auto_cancel' => array( 'title' => __( 'Cancel unpaid orders', 'novacrypto-pay' ), 'type' => 'checkbox', 'label' => __( 'Cancel the WooCommerce order when the payment window expires with no funds received', 'novacrypto-pay' ), 'default' => 'no' ),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled || ! ncp()->settings->get( 'enabled', true ) ) {
			return false;
		}
		return count( ncp()->coins->for_checkout() ) > 0;
	}

	public function get_icon() {
		if ( 'yes' !== $this->get_option( 'show_coins_in_title', 'yes' ) ) {
			return '';
		}
		$html = '';
		$i    = 0;
		foreach ( ncp()->coins->for_checkout() as $coin ) {
			if ( $i++ >= 6 ) {
				break;
			}
			$html .= '<img src="' . esc_url( $coin['icon'] ) . '" alt="' . esc_attr( $coin['symbol'] ) . '" style="width:22px;height:22px;margin-left:4px;vertical-align:middle;border-radius:50%" onerror="this.style.display=\'none\'" />';
		}
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( wpautop( wptexturize( $this->description ) ) ) . '</p>';
		}
		$disc = (float) ncp()->settings->get( 'crypto_discount', 0 );
		if ( $disc > 0 ) {
			/* translators: %s percent */
			echo '<p class="ncp-discount-note">' . sprintf( esc_html__( 'Pay with crypto and get %s%% off.', 'novacrypto-pay' ), esc_html( $disc ) ) . '</p>';
		}
		NCP_Frontend::enqueue();
		$total = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
		echo NCP_Frontend::render_coin_picker( ncp()->coins->for_checkout(), $total * ( 1 - $disc / 100 ), get_woocommerce_currency(), 'ncp_coin', WC()->session ? WC()->session->get( 'ncp_coin' ) : '' ); // phpcs:ignore
	}

	public function validate_fields() {
		$coin = isset( $_POST['ncp_coin'] ) ? sanitize_key( wp_unslash( $_POST['ncp_coin'] ) ) : ''; // phpcs:ignore
		if ( ! $coin || ! isset( ncp()->coins->for_checkout()[ $coin ] ) ) {
			wc_add_notice( __( 'Please choose which cryptocurrency you want to pay with.', 'novacrypto-pay' ), 'error' );
			return false;
		}
		if ( WC()->session ) {
			WC()->session->set( 'ncp_coin', $coin );
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$coin  = isset( $_POST['ncp_coin'] ) ? sanitize_key( wp_unslash( $_POST['ncp_coin'] ) ) : ''; // phpcs:ignore
		if ( ! $coin && WC()->session ) {
			$coin = WC()->session->get( 'ncp_coin' );
		}
		$payment = self::create_for_order( $order, $coin );
		if ( is_wp_error( $payment ) ) {
			wc_add_notice( $payment->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_status( 'pending', __( 'Awaiting cryptocurrency payment.', 'novacrypto-pay' ) );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Create (or reuse) the crypto payment for an order.
	 *
	 * @return object|WP_Error
	 */
	public static function create_for_order( $order, $coin_id ) {
		$existing = ncp()->payments->get_by_source( 'woocommerce', $order->get_id() );
		if ( $existing && $existing->coin_id === $coin_id && ! ncp()->payments->is_expired( $existing ) && in_array( $existing->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true ) ) {
			return $existing;
		}
		if ( $existing && 'pending' === $existing->status ) {
			ncp()->payments->set_status( $existing, 'cancelled', 'customer changed coin' );
		}
		// Per-product crypto price override: if every line item defines a price in this coin, charge the sum instead of converting.
		$crypto_total = self::product_crypto_total( $order, $coin_id );
		$payment      = ncp()->payments->create(
			array(
				'source'         => 'woocommerce',
				'source_id'      => $order->get_id(),
				'coin_id'        => $coin_id,
				'amount_fiat'    => (float) $order->get_total(),
				'fiat_currency'  => $order->get_currency(),
				'customer_email' => $order->get_billing_email(),
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'title'          => sprintf( __( 'Order #%s', 'novacrypto-pay' ), $order->get_order_number() ),
				'return_url'     => $order->get_checkout_order_received_url(),
				'apply_discount' => false, // Discount handled as a WC fee at checkout (see NCP_WC_Integration).
				'amount_crypto'  => $crypto_total,
			)
		);
		if ( ! is_wp_error( $payment ) ) {
			$order->update_meta_data( '_ncp_payment_uuid', $payment->uuid );
			$order->update_meta_data( '_ncp_coin', $coin_id );
			$order->update_meta_data( '_ncp_amount_crypto', $payment->amount_crypto );
			$order->update_meta_data( '_ncp_address', $payment->address );
			$order->update_meta_data( '_ncp_rate', $payment->rate );
			$order->save();
			$order->add_order_note( sprintf( __( 'Crypto payment created: %1$s %2$s to %3$s (rate %4$s, %5$s).', 'novacrypto-pay' ), NCP_Coins::format_amount( $payment->amount_crypto ), $payment->coin['symbol'], $payment->address, $payment->rate, $payment->rate_provider ) );
		}
		return $payment;
	}

	private static function product_crypto_total( $order, $coin_id ) {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$price   = $product ? $product->get_meta( '_ncp_price_' . $coin_id, true ) : '';
			if ( '' === $price || null === $price ) {
				return null;
			}
			$total += (float) $price * $item->get_quantity();
		}
		return $total > 0 ? $total : null;
	}

	public function receipt_page( $order_id ) {
		$order   = wc_get_order( $order_id );
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order_id );
		if ( ! $payment ) {
			echo '<p>' . esc_html__( 'No crypto payment found for this order.', 'novacrypto-pay' ) . '</p>';
			return;
		}
		if ( 'expired' === $payment->status || ( ncp()->payments->is_expired( $payment ) && 'pending' === $payment->status ) ) {
			// Offer a fresh quote for the same order.
			if ( isset( $_GET['ncp_requote'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'ncp_requote_' . $order_id ) ) { // phpcs:ignore
				$new = self::create_for_order( $order, $payment->coin_id );
				if ( ! is_wp_error( $new ) ) {
					$payment = $new;
				}
			} else {
				$url = wp_nonce_url( add_query_arg( 'ncp_requote', 1, $order->get_checkout_payment_url( true ) ), 'ncp_requote_' . $order_id );
				echo '<div class="ncp-notice">' . esc_html__( 'Your previous payment request expired.', 'novacrypto-pay' ) . ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Get a new quote', 'novacrypto-pay' ) . '</a></div>';
				echo NCP_Frontend::render_payment_box( $payment ); // phpcs:ignore
				return;
			}
		}
		echo NCP_Frontend::render_payment_box( $payment ); // phpcs:ignore
	}

	public function thankyou_page( $order_id ) {
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order_id, true );
		if ( $payment && 'paid' !== $payment->status ) {
			echo NCP_Frontend::render_payment_box( $payment ); // phpcs:ignore
		} elseif ( $payment ) {
			echo '<p class="ncp-thanks">' . esc_html__( 'Your cryptocurrency payment has been confirmed. Thank you!', 'novacrypto-pay' ) . '</p>';
		}
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || $this->id !== $order->get_payment_method() || ! $order->has_status( 'pending' ) ) {
			return;
		}
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order->get_id() );
		if ( ! $payment ) {
			return;
		}
		$url = $order->get_checkout_payment_url( true );
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Complete your cryptocurrency payment:', 'novacrypto-pay' ) . ' ' . esc_url( $url ) . "\n" . esc_html( NCP_Coins::format_amount( $payment->amount_crypto ) . ' ' . $payment->coin['symbol'] . ' → ' . $payment->address ) . "\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Complete your cryptocurrency payment', 'novacrypto-pay' ) . '</h2><p>' . esc_html( NCP_Coins::format_amount( $payment->amount_crypto ) . ' ' . $payment->coin['symbol'] ) . '<br><code>' . esc_html( $payment->address ) . '</code></p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open payment page', 'novacrypto-pay' ) . '</a></p>';
	}

	/** Refunds are manual (crypto cannot be pulled back); we record it and mark the payment. */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$payment = ncp()->payments->get_by_source( 'woocommerce', $order_id, true );
		if ( ! $payment ) {
			return new WP_Error( 'ncp_refund', __( 'No crypto payment found.', 'novacrypto-pay' ) );
		}
		$order = wc_get_order( $order_id );
		$note  = sprintf( __( 'Manual crypto refund of %1$s recorded. Send funds to the customer refund address: %2$s', 'novacrypto-pay' ), wc_price( $amount ), $payment->refund_address ?: __( '(not provided)', 'novacrypto-pay' ) );
		$order->add_order_note( $note );
		ncp()->payments->update_meta( $payment, 'refund_recorded', array( 'amount' => $amount, 'reason' => $reason, 'time' => time() ) );
		if ( (float) $amount >= (float) $order->get_total() ) {
			ncp()->payments->set_status( $payment, 'refunded', $reason );
		}
		return true;
	}
}
