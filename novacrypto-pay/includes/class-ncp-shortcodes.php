<?php
/**
 * Shortcodes (all also available as Gutenberg blocks and work in Elementor / any builder):
 *
 *  [ncp_pay amount="25" currency="USD" title="Consulting call" button="Pay with crypto" coins="btc,eth" ref="abc" return_url=""]
 *  [ncp_donate title="Support us" presets="5,10,25" min="1" currency="USD" coins=""]
 *  [ncp_payment uuid="..."]              – embeds an existing payment box
 *  [ncp_link slug="..."]                 – embeds a payment link form
 *  [ncp_price amount="100" currency="USD" coin="btc"]  – live conversion text
 *  [ncp_wallets coins="" show_qr="1"]    – static "send to these wallets" box
 *  [ncp_status uuid="..."]               – status badge only
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Shortcodes {

	public function __construct() {
		add_shortcode( 'ncp_pay', array( $this, 'pay' ) );
		add_shortcode( 'ncp_donate', array( $this, 'donate' ) );
		add_shortcode( 'ncp_payment', array( $this, 'payment' ) );
		add_shortcode( 'ncp_link', array( $this, 'link' ) );
		add_shortcode( 'ncp_price', array( $this, 'price' ) );
		add_shortcode( 'ncp_wallets', array( $this, 'wallets' ) );
		add_shortcode( 'ncp_status', array( $this, 'status' ) );
	}

	private function coins_from_attr( $attr ) {
		$ids = array_filter( array_map( 'sanitize_key', explode( ',', (string) $attr ) ) );
		return ncp()->coins->for_checkout( $ids );
	}

	public function pay( $atts ) {
		$a = shortcode_atts(
			array(
				'amount'     => '0',
				'currency'   => ncp()->settings->store_currency(),
				'title'      => '',
				'button'     => __( 'Pay with crypto', 'novacrypto-pay' ),
				'coins'      => '',
				'ref'        => '',
				'return_url' => '',
				'inline'     => '1',
			),
			$atts,
			'ncp_pay'
		);
		NCP_Frontend::enqueue();
		$coins = $this->coins_from_attr( $a['coins'] );
		if ( ! $coins ) {
			return $this->no_coins();
		}
		$args = array(
			'amount'   => (string) $a['amount'],
			'currency' => strtoupper( $a['currency'] ),
			'title'    => $a['title'],
			'custom'   => '0',
			'ref'      => $a['ref'],
		);
		$sig  = NCP_Frontend::sign( $args );
		ob_start();
		include NCP_DIR . 'templates/pay-form.php';
		return ob_get_clean();
	}

	public function donate( $atts ) {
		$a = shortcode_atts(
			array(
				'title'      => __( 'Support us with crypto', 'novacrypto-pay' ),
				'presets'    => '5,10,25,50',
				'min'        => '1',
				'currency'   => ncp()->settings->store_currency(),
				'coins'      => '',
				'button'     => __( 'Donate', 'novacrypto-pay' ),
				'ref'        => 'donation',
				'return_url' => '',
				'inline'     => '1',
			),
			$atts,
			'ncp_donate'
		);
		NCP_Frontend::enqueue();
		$coins = $this->coins_from_attr( $a['coins'] );
		if ( ! $coins ) {
			return $this->no_coins();
		}
		$args = array( 'amount' => '', 'currency' => strtoupper( $a['currency'] ), 'title' => $a['title'], 'custom' => '1', 'ref' => $a['ref'] );
		$sig  = '';
		$a['amount'] = '';
		$a['custom'] = true;
		ob_start();
		include NCP_DIR . 'templates/pay-form.php';
		return ob_get_clean();
	}

	public function payment( $atts ) {
		$a       = shortcode_atts( array( 'uuid' => '' ), $atts, 'ncp_payment' );
		$uuid    = $a['uuid'] ?: ( isset( $_GET['ncp_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['ncp_uuid'] ) ) : '' ); // phpcs:ignore
		$payment = $uuid ? ncp()->payments->get_by_uuid( $uuid ) : null;
		if ( ! $payment ) {
			return '<div class="ncp-notice ncp-notice-error">' . esc_html__( 'Payment not found.', 'novacrypto-pay' ) . '</div>';
		}
		return NCP_Frontend::render_payment_box( $payment );
	}

	public function link( $atts ) {
		$a    = shortcode_atts( array( 'slug' => '' ), $atts, 'ncp_link' );
		$link = ncp()->links->get_by_slug( $a['slug'] );
		if ( ! $link ) {
			return '<div class="ncp-notice ncp-notice-error">' . esc_html__( 'Payment link not found.', 'novacrypto-pay' ) . '</div>';
		}
		return NCP_Frontend::render_link_form( $link, array( 'inline' => true ) );
	}

	public function price( $atts ) {
		$a    = shortcode_atts( array( 'amount' => '0', 'currency' => ncp()->settings->store_currency(), 'coin' => 'btc', 'decimals' => '' ), $atts, 'ncp_price' );
		$coin = ncp()->coins->get( sanitize_key( $a['coin'] ) );
		if ( ! $coin ) {
			return '';
		}
		$conv = ncp()->rates->convert( (float) $a['amount'], $coin['id'], $a['currency'] );
		if ( is_wp_error( $conv ) ) {
			return '<span class="ncp-price ncp-price-error">—</span>';
		}
		return '<span class="ncp-price" data-coin="' . esc_attr( $coin['id'] ) . '">' . esc_html( NCP_Coins::format_amount( $conv['amount'], '' !== $a['decimals'] ? (int) $a['decimals'] : $coin['decimals'] ) . ' ' . $coin['symbol'] ) . '</span>';
	}

	public function wallets( $atts ) {
		$a = shortcode_atts( array( 'coins' => '', 'show_qr' => '1', 'title' => __( 'We accept cryptocurrency', 'novacrypto-pay' ) ), $atts, 'ncp_wallets' );
		NCP_Frontend::enqueue();
		$coins = $this->coins_from_attr( $a['coins'] );
		ob_start();
		include NCP_DIR . 'templates/wallets.php';
		return ob_get_clean();
	}

	public function status( $atts ) {
		$a       = shortcode_atts( array( 'uuid' => '' ), $atts, 'ncp_status' );
		$payment = $a['uuid'] ? ncp()->payments->get_by_uuid( $a['uuid'] ) : null;
		if ( ! $payment ) {
			return '';
		}
		return '<span class="ncp-badge ncp-badge-' . esc_attr( $payment->status ) . '">' . esc_html( NCP_Payments::status_label( $payment->status ) ) . '</span>';
	}

	private function no_coins() {
		if ( current_user_can( 'manage_novacrypto' ) ) {
			return '<div class="ncp-notice ncp-notice-error">' . esc_html__( 'NovaCrypto Pay: no coins are enabled. Add a wallet under NovaCrypto Pay → Coins & Wallets.', 'novacrypto-pay' ) . '</div>';
		}
		return '';
	}
}
