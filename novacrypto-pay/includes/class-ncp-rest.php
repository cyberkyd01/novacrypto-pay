<?php
/**
 * REST API  —  namespace ncp/v1
 *
 *   GET  /coins                     public: enabled coins
 *   GET  /rates?coin=btc&fiat=USD    public
 *   POST /payments                  api-key: create payment (headless / external apps)
 *   GET  /payments                  api-key: list
 *   GET  /payments/{uuid}           public (uuid is the secret)
 *   POST /payments/{uuid}/check     public, throttled: force blockchain re-check
 *   POST /payments/{uuid}/txid      public: customer submits a tx hash
 *   POST /payments/{uuid}/refund-address public
 *   POST /payments/{uuid}/status    api-key: admin override (paid/cancelled/refunded)
 *
 * Auth: header  X-NCP-API-Key: <key>   (or logged-in user with manage_novacrypto)
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Rest {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		if ( ! ncp()->settings->get( 'enable_rest', true ) ) {
			return;
		}
		$ns = 'ncp/v1';
		register_rest_route( $ns, '/coins', array( 'methods' => 'GET', 'callback' => array( $this, 'coins' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/rates', array( 'methods' => 'GET', 'callback' => array( $this, 'rates' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'create' ), 'permission_callback' => array( $this, 'auth' ) ),
			array( 'methods' => 'GET', 'callback' => array( $this, 'list' ), 'permission_callback' => array( $this, 'auth' ) ),
		) );
		register_rest_route( $ns, '/payments/(?P<uuid>[a-f0-9-]{36})', array( 'methods' => 'GET', 'callback' => array( $this, 'get' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments/(?P<uuid>[a-f0-9-]{36})/check', array( 'methods' => 'POST', 'callback' => array( $this, 'check' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments/(?P<uuid>[a-f0-9-]{36})/txid', array( 'methods' => 'POST', 'callback' => array( $this, 'submit_txid' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments/(?P<uuid>[a-f0-9-]{36})/refund-address', array( 'methods' => 'POST', 'callback' => array( $this, 'refund_address' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments/(?P<uuid>[a-f0-9-]{36})/status', array( 'methods' => 'POST', 'callback' => array( $this, 'set_status' ), 'permission_callback' => array( $this, 'auth' ) ) );
	}

	public function auth( $request ) {
		if ( current_user_can( 'manage_novacrypto' ) ) {
			return true;
		}
		$key = $request->get_header( 'x-ncp-api-key' );
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );
		}
		$stored = get_option( 'ncp_api_key' );
		if ( $key && $stored && hash_equals( $stored, (string) $key ) ) {
			return true;
		}
		return new WP_Error( 'ncp_forbidden', __( 'Invalid API key.', 'novacrypto-pay' ), array( 'status' => 401 ) );
	}

	private function throttle( $key, $seconds = 5 ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$k  = 'ncp_rl_' . md5( $key . $ip );
		if ( get_transient( $k ) ) {
			return new WP_Error( 'ncp_rate_limited', __( 'Too many requests.', 'novacrypto-pay' ), array( 'status' => 429 ) );
		}
		set_transient( $k, 1, $seconds );
		return true;
	}

	public function coins() {
		$out = array();
		foreach ( ncp()->coins->for_checkout() as $id => $c ) {
			$out[] = array( 'id' => $id, 'name' => $c['name'], 'symbol' => $c['symbol'], 'network' => ncp()->coins->network_label( $c ), 'family' => $c['family'], 'stable' => $c['stable'], 'icon' => $c['icon'], 'confirmations' => ncp()->coins->confirmations( $id ) );
		}
		return rest_ensure_response( $out );
	}

	public function rates( $request ) {
		$coin = sanitize_key( $request->get_param( 'coin' ) );
		$fiat = strtoupper( sanitize_text_field( $request->get_param( 'fiat' ) ?: ncp()->settings->store_currency() ) );
		$coins = $coin ? array( $coin ) : array_keys( ncp()->coins->for_checkout() );
		$out = array();
		foreach ( $coins as $id ) {
			$r = ncp()->rates->get( $id, $fiat );
			$out[ $id ] = is_wp_error( $r ) ? null : array( 'rate' => $r['rate'], 'provider' => $r['provider'], 'fiat' => $fiat );
		}
		return rest_ensure_response( $out );
	}

	public function create( $request ) {
		$p = $request->get_json_params() ?: $request->get_params();
		$payment = ncp()->payments->create(
			array(
				'source'         => 'api',
				'source_id'      => sanitize_text_field( $p['reference'] ?? '' ),
				'coin_id'        => sanitize_key( $p['coin'] ?? '' ),
				'amount_fiat'    => (float) ( $p['amount'] ?? 0 ),
				'fiat_currency'  => strtoupper( sanitize_text_field( $p['currency'] ?? ncp()->settings->store_currency() ) ),
				'customer_email' => sanitize_email( $p['email'] ?? '' ),
				'customer_name'  => sanitize_text_field( $p['name'] ?? '' ),
				'title'          => sanitize_text_field( $p['title'] ?? '' ),
				'return_url'     => esc_url_raw( $p['return_url'] ?? '' ),
				'meta'           => array( 'webhook_url' => esc_url_raw( $p['webhook_url'] ?? '' ), 'custom' => $p['metadata'] ?? null ),
				'apply_discount' => ! empty( $p['apply_discount'] ),
			)
		);
		if ( is_wp_error( $payment ) ) {
			return new WP_Error( $payment->get_error_code(), $payment->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( ncp()->payments->to_public_array( $payment ) );
	}

	public function list( $request ) {
		$res = ncp()->payments->query(
			array(
				'status'   => sanitize_key( $request->get_param( 'status' ) ),
				'coin_id'  => sanitize_key( $request->get_param( 'coin' ) ),
				'per_page' => min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
			)
		);
		return rest_ensure_response( array( 'total' => $res['total'], 'items' => array_map( array( ncp()->payments, 'to_public_array' ), $res['items'] ) ) );
	}

	private function load( $request ) {
		$payment = ncp()->payments->get_by_uuid( $request['uuid'] );
		return $payment ? $payment : new WP_Error( 'ncp_not_found', __( 'Payment not found.', 'novacrypto-pay' ), array( 'status' => 404 ) );
	}

	public function get( $request ) {
		$payment = $this->load( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		if ( in_array( $payment->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true ) ) {
			$checked = ncp()->monitor->check( $payment );
			if ( ! is_wp_error( $checked ) ) {
				$payment = $checked;
			}
		}
		return rest_ensure_response( ncp()->payments->to_public_array( $payment ) );
	}

	public function check( $request ) {
		$t = $this->throttle( 'check_' . $request['uuid'], 10 );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$payment = $this->load( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$checked = ncp()->monitor->check( $payment, true );
		return rest_ensure_response( ncp()->payments->to_public_array( is_wp_error( $checked ) ? $payment : $checked ) );
	}

	public function submit_txid( $request ) {
		if ( ! ncp()->settings->get( 'allow_txid_submit', true ) ) {
			return new WP_Error( 'ncp_disabled', __( 'Disabled.', 'novacrypto-pay' ), array( 'status' => 403 ) );
		}
		$t = $this->throttle( 'txid_' . $request['uuid'], 10 );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$payment = $this->load( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$txid = sanitize_text_field( $request->get_param( 'txid' ) );
		if ( ! NCP_Validator::txid( $txid, $payment->coin ) ) {
			return new WP_Error( 'ncp_bad_txid', __( 'That does not look like a valid transaction hash for this network.', 'novacrypto-pay' ), array( 'status' => 400 ) );
		}
		ncp()->payments->update_meta( $payment, 'customer_txid', $txid );
		ncp()->logger->info( "Customer submitted txid {$txid} for {$payment->uuid}", 'payments' );
		do_action( 'ncp_customer_txid_submitted', $payment, $txid );
		$checked = ncp()->monitor->check( $payment, true );
		return rest_ensure_response( array( 'ok' => true, 'payment' => ncp()->payments->to_public_array( is_wp_error( $checked ) ? $payment : $checked ) ) );
	}

	public function refund_address( $request ) {
		$payment = $this->load( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$addr = sanitize_text_field( $request->get_param( 'address' ) );
		$v    = NCP_Validator::validate( $addr, $payment->coin );
		if ( is_wp_error( $v ) ) {
			return new WP_Error( $v->get_error_code(), $v->get_error_message(), array( 'status' => 400 ) );
		}
		ncp()->payments->update( $payment->id, array( 'refund_address' => $addr ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function set_status( $request ) {
		$payment = $this->load( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$status = sanitize_key( $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'paid', 'cancelled', 'refunded', 'pending' ), true ) ) {
			return new WP_Error( 'ncp_bad_status', __( 'Status must be paid, cancelled, refunded or pending.', 'novacrypto-pay' ), array( 'status' => 400 ) );
		}
		ncp()->payments->set_status( $payment, $status, 'via REST API' );
		return rest_ensure_response( ncp()->payments->to_public_array( ncp()->payments->get( $payment->id ) ) );
	}
}
