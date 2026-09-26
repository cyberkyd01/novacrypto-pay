<?php
/**
 * Lightning Network backends: LNbits and Lightning Address (LUD-16 + LUD-21 verify).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Lightning {

	/**
	 * @param float  $amount_btc
	 * @param string $memo
	 * @return array|WP_Error [ payment_request, payment_hash, verify_url ]
	 */
	public static function create_invoice( $amount_btc, $memo = '' ) {
		$sats    = (int) round( (float) $amount_btc * 1e8 );
		$backend = ncp()->settings->get( 'lightning_backend', 'none' );
		if ( $sats < 1 ) {
			return new WP_Error( 'ncp_ln_amount', __( 'Amount too small for Lightning.', 'novacrypto-pay' ) );
		}
		if ( 'lnbits' === $backend ) {
			$url = rtrim( ncp()->settings->get( 'lnbits_url' ), '/' ) . '/api/v1/payments';
			$res = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json', 'X-Api-Key' => ncp()->settings->get( 'lnbits_invoice_key' ) ),
					'body'    => wp_json_encode( array( 'out' => false, 'amount' => $sats, 'memo' => mb_substr( $memo, 0, 100 ), 'unit' => 'sat' ) ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( empty( $j['payment_request'] ) ) {
				return new WP_Error( 'ncp_ln', __( 'LNbits did not return an invoice: ', 'novacrypto-pay' ) . substr( wp_remote_retrieve_body( $res ), 0, 200 ) );
			}
			return array( 'payment_request' => $j['payment_request'], 'payment_hash' => $j['payment_hash'] ?? '', 'verify_url' => '' );
		}
		if ( 'lnaddress' === $backend ) {
			$addr = trim( ncp()->settings->get( 'lightning_address' ) );
			if ( ! preg_match( '/^([^@\s]+)@([^@\s]+)$/', $addr, $m ) ) {
				return new WP_Error( 'ncp_ln', __( 'Invalid Lightning address.', 'novacrypto-pay' ) );
			}
			$meta = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://' . $m[2] . '/.well-known/lnurlp/' . $m[1], array( 'timeout' => 12 ) ) ), true );
			if ( empty( $meta['callback'] ) ) {
				return new WP_Error( 'ncp_ln', __( 'Lightning address lookup failed.', 'novacrypto-pay' ) );
			}
			$msat = $sats * 1000;
			if ( ( isset( $meta['minSendable'] ) && $msat < $meta['minSendable'] ) || ( isset( $meta['maxSendable'] ) && $msat > $meta['maxSendable'] ) ) {
				return new WP_Error( 'ncp_ln', __( 'Amount outside the Lightning address limits.', 'novacrypto-pay' ) );
			}
			$cb  = $meta['callback'] . ( false === strpos( $meta['callback'], '?' ) ? '?' : '&' ) . 'amount=' . $msat . '&comment=' . rawurlencode( mb_substr( $memo, 0, (int) ( $meta['commentAllowed'] ?? 0 ) ) );
			$inv = json_decode( wp_remote_retrieve_body( wp_remote_get( $cb, array( 'timeout' => 12 ) ) ), true );
			if ( empty( $inv['pr'] ) ) {
				return new WP_Error( 'ncp_ln', __( 'Lightning address did not return an invoice.', 'novacrypto-pay' ) );
			}
			if ( empty( $inv['verify'] ) ) {
				return new WP_Error( 'ncp_ln', __( 'This Lightning address provider does not support payment verification (LUD-21). Use LNbits instead.', 'novacrypto-pay' ) );
			}
			return array( 'payment_request' => $inv['pr'], 'payment_hash' => '', 'verify_url' => $inv['verify'] );
		}
		return new WP_Error( 'ncp_ln', __( 'Lightning is not configured.', 'novacrypto-pay' ) );
	}

	/** @return array|WP_Error [ 'paid' => bool ] */
	public static function check( $payment ) {
		$backend = ncp()->settings->get( 'lightning_backend', 'none' );
		if ( 'lnbits' === $backend && ! empty( $payment->meta['ln_payment_hash'] ) ) {
			$url = rtrim( ncp()->settings->get( 'lnbits_url' ), '/' ) . '/api/v1/payments/' . rawurlencode( $payment->meta['ln_payment_hash'] );
			$res = wp_remote_get( $url, array( 'timeout' => 12, 'headers' => array( 'X-Api-Key' => ncp()->settings->get( 'lnbits_invoice_key' ) ) ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			return array( 'paid' => ! empty( $j['paid'] ) );
		}
		if ( ! empty( $payment->meta['ln_verify_url'] ) ) {
			$res = wp_remote_get( $payment->meta['ln_verify_url'], array( 'timeout' => 12 ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			return array( 'paid' => ! empty( $j['settled'] ) );
		}
		return new WP_Error( 'ncp_ln', 'no verification method' );
	}
}
