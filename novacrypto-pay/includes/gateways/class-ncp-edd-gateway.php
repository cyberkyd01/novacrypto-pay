<?php
/**
 * Easy Digital Downloads gateway.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_EDD_Gateway {

	const ID = 'novacrypto';

	public function __construct() {
		add_filter( 'edd_payment_gateways', array( $this, 'register' ) );
		add_action( 'edd_' . self::ID . '_cc_form', array( $this, 'fields' ) );
		add_action( 'edd_gateway_' . self::ID, array( $this, 'process' ) );
		add_action( 'ncp_payment_status_changed', array( $this, 'sync' ), 10, 3 );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );
	}

	public function register( $gateways ) {
		$gateways[ self::ID ] = array(
			'admin_label'    => __( 'NovaCrypto Pay (Cryptocurrency)', 'novacrypto-pay' ),
			'checkout_label' => ncp()->settings->get( 'title' ),
			'supports'       => array(),
		);
		return $gateways;
	}

	public function settings_section( $sections ) {
		$sections[ self::ID ] = __( 'NovaCrypto Pay', 'novacrypto-pay' );
		return $sections;
	}

	public function settings( $settings ) {
		$settings[ self::ID ] = array(
			array( 'id' => 'ncp_edd_info', 'name' => __( 'Configuration', 'novacrypto-pay' ), 'desc' => sprintf( __( 'Coins, wallets and options are managed under <a href="%s">NovaCrypto Pay → Settings</a>.', 'novacrypto-pay' ), admin_url( 'admin.php?page=ncp-settings' ) ), 'type' => 'descriptive_text' ),
		);
		return $settings;
	}

	public function fields() {
		NCP_Frontend::enqueue();
		$total = function_exists( 'edd_get_cart_total' ) ? (float) edd_get_cart_total() : 0;
		echo '<fieldset><legend>' . esc_html__( 'Choose cryptocurrency', 'novacrypto-pay' ) . '</legend>';
		echo NCP_Frontend::render_coin_picker( ncp()->coins->for_checkout(), $total, edd_get_currency(), 'ncp_coin' ); // phpcs:ignore
		echo '</fieldset>';
	}

	public function process( $purchase_data ) {
		$coin = isset( $_POST['ncp_coin'] ) ? sanitize_key( wp_unslash( $_POST['ncp_coin'] ) ) : ''; // phpcs:ignore
		if ( ! $coin ) {
			edd_set_error( 'ncp_coin', __( 'Please choose a cryptocurrency.', 'novacrypto-pay' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
		}
		$payment_id = edd_insert_payment(
			array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => edd_get_currency(),
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'gateway'      => self::ID,
				'status'       => 'pending',
			)
		);
		if ( ! $payment_id ) {
			edd_set_error( 'ncp_payment', __( 'Could not create order.', 'novacrypto-pay' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
		}
		$payment = ncp()->payments->create(
			array(
				'source'         => 'edd',
				'source_id'      => $payment_id,
				'coin_id'        => $coin,
				'amount_fiat'    => (float) $purchase_data['price'],
				'fiat_currency'  => edd_get_currency(),
				'customer_email' => $purchase_data['user_email'],
				'customer_name'  => trim( ( $purchase_data['user_info']['first_name'] ?? '' ) . ' ' . ( $purchase_data['user_info']['last_name'] ?? '' ) ),
				'title'          => sprintf( __( 'Order #%s', 'novacrypto-pay' ), $payment_id ),
				'return_url'     => add_query_arg( 'payment_key', $purchase_data['purchase_key'], edd_get_success_page_uri() ),
				'apply_discount' => true,
			)
		);
		if ( is_wp_error( $payment ) ) {
			edd_set_error( 'ncp_payment', $payment->get_error_message() );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
		}
		edd_update_payment_meta( $payment_id, '_ncp_payment_uuid', $payment->uuid );
		edd_empty_cart();
		wp_safe_redirect( NCP_Frontend::pay_url( $payment->uuid ) );
		exit;
	}

	public function sync( $payment, $old, $new ) {
		if ( 'edd' !== $payment->source ) {
			return;
		}
		$id = (int) $payment->source_id;
		if ( 'paid' === $new ) {
			edd_update_payment_status( $id, 'publish' );
			edd_insert_payment_note( $id, sprintf( __( 'Crypto payment confirmed: %1$s %2$s', 'novacrypto-pay' ), NCP_Coins::format_amount( $payment->received_crypto ), $payment->coin['symbol'] ?? '' ) );
		} elseif ( 'expired' === $new ) {
			edd_update_payment_status( $id, 'abandoned' );
		} elseif ( 'refunded' === $new ) {
			edd_update_payment_status( $id, 'refunded' );
		} elseif ( in_array( $new, array( 'detected', 'confirming', 'underpaid' ), true ) ) {
			edd_insert_payment_note( $id, sprintf( __( 'Crypto payment %1$s: %2$s received', 'novacrypto-pay' ), $new, NCP_Coins::format_amount( $payment->received_crypto ) ) );
		}
	}
}
