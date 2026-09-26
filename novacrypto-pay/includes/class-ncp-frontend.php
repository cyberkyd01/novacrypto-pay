<?php
/**
 * Frontend: hosted pay pages, link pages, assets, AJAX and payment URIs.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Frontend {

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_ncp_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_ncp_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_ncp_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_nopriv_ncp_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ncp_quote', array( $this, 'ajax_quote' ) );
		add_action( 'wp_ajax_nopriv_ncp_quote', array( $this, 'ajax_quote' ) );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^ncp-pay/([a-f0-9-]{36})/?$', 'index.php?ncp_pay=$matches[1]', 'top' );
		add_rewrite_rule( '^ncp-link/([a-z0-9-]+)/?$', 'index.php?ncp_link=$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'ncp_pay';
		$vars[] = 'ncp_link';
		return $vars;
	}

	public static function pay_url( $uuid ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/ncp-pay/' . $uuid . '/' );
		}
		return add_query_arg( 'ncp_pay', $uuid, home_url( '/' ) );
	}

	public static function link_url( $slug ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/ncp-link/' . $slug . '/' );
		}
		return add_query_arg( 'ncp_link', $slug, home_url( '/' ) );
	}

	/* ---------- Assets ---------- */

	public function register_assets() {
		wp_register_script( 'ncp-qrcode', NCP_URL . 'assets/js/qrcode.min.js', array(), '1.4.4', true );
		wp_register_script( 'ncp-frontend', NCP_URL . 'assets/js/frontend.js', array( 'ncp-qrcode' ), NCP_VERSION, true );
		wp_register_style( 'ncp-frontend', NCP_URL . 'assets/css/frontend.css', array(), NCP_VERSION );
		wp_localize_script(
			'ncp-frontend',
			'ncpData',
			array(
				'ajax'         => admin_url( 'admin-ajax.php' ),
				'rest'         => esc_url_raw( rest_url( 'ncp/v1/' ) ),
				'nonce'        => wp_create_nonce( 'ncp_front' ),
				'pollInterval' => max( 5, (int) ncp()->settings->get( 'poll_interval', 10 ) ) * 1000,
				'i18n'         => array(
					'copied'      => __( 'Copied!', 'novacrypto-pay' ),
					'copy'        => __( 'Copy', 'novacrypto-pay' ),
					'expired'     => __( 'Expired', 'novacrypto-pay' ),
					'noWallet'    => __( 'No compatible browser wallet detected. Please scan the QR code or copy the address.', 'novacrypto-pay' ),
					'walletError' => __( 'The wallet rejected the request.', 'novacrypto-pay' ),
					'loading'     => __( 'Loading…', 'novacrypto-pay' ),
					'error'       => __( 'Something went wrong. Please try again.', 'novacrypto-pay' ),
				),
			)
		);
	}

	public static function enqueue() {
		wp_enqueue_script( 'ncp-frontend' );
		wp_enqueue_style( 'ncp-frontend' );
		wp_add_inline_style( 'ncp-frontend', self::inline_css() );
	}

	public static function inline_css() {
		$s   = ncp()->settings;
		$css = sprintf(
			':root{--ncp-primary:%s;--ncp-accent:%s;--ncp-radius:%dpx;}',
			sanitize_hex_color( $s->get( 'primary_color' ) ) ?: '#2563eb',
			sanitize_hex_color( $s->get( 'accent_color' ) ) ?: '#16a34a',
			(int) $s->get( 'border_radius', 12 )
		);
		return $css . "\n" . wp_strip_all_tags( (string) $s->get( 'custom_css' ) );
	}

	public static function theme_class() {
		$mode = ncp()->settings->get( 'theme_mode', 'auto' );
		return 'ncp-theme-' . ( in_array( $mode, array( 'light', 'dark' ), true ) ? $mode : 'auto' );
	}

	/* ---------- Routing ---------- */

	public function maybe_render() {
		$uuid = get_query_var( 'ncp_pay' );
		$slug = get_query_var( 'ncp_link' );
		if ( ! $uuid && ! $slug ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		self::enqueue();
		if ( $uuid ) {
			$payment = ncp()->payments->get_by_uuid( $uuid );
			$content = $payment ? self::render_payment_box( $payment, array( 'full' => true ) ) : '<div class="ncp-notice ncp-notice-error">' . esc_html__( 'Payment not found.', 'novacrypto-pay' ) . '</div>';
			$title   = $payment ? ( $payment->title ?: __( 'Complete your payment', 'novacrypto-pay' ) ) : __( 'Payment', 'novacrypto-pay' );
		} else {
			$link    = ncp()->links->get_by_slug( $slug );
			$content = $link ? self::render_link_form( $link ) : '<div class="ncp-notice ncp-notice-error">' . esc_html__( 'Payment link not found.', 'novacrypto-pay' ) . '</div>';
			$title   = $link ? $link->title : __( 'Payment link', 'novacrypto-pay' );
		}
		self::output_page( $title, $content );
		exit;
	}

	private static function output_page( $title, $content ) {
		$mode = ncp()->settings->get( 'pay_page_mode', 'theme' );
		add_filter( 'pre_get_document_title', function () use ( $title ) { return $title . ' – ' . get_bloginfo( 'name' ); } );
		add_filter( 'body_class', function ( $c ) { $c[] = 'ncp-page'; return $c; } );
		if ( 'standalone' === $mode ) {
			include NCP_DIR . 'templates/standalone-page.php';
			return;
		}
		get_header();
		echo '<div class="ncp-page-wrap ' . esc_attr( self::theme_class() ) . '"><main class="ncp-page-main">';
		echo $content; // phpcs:ignore -- built from escaped templates.
		echo '</main></div>';
		get_footer();
	}

	/* ---------- Rendering ---------- */

	public static function render_payment_box( $payment, array $opts = array() ) {
		self::enqueue();
		$opts = wp_parse_args( $opts, array( 'full' => false, 'show_title' => true ) );
		$data = ncp()->payments->to_public_array( $payment );
		$coin = $payment->coin;
		$file = locate_template( 'novacrypto-pay/payment-box.php' ) ?: NCP_DIR . 'templates/payment-box.php';
		ob_start();
		include $file;
		return ob_get_clean();
	}

	public static function render_link_form( $link, array $opts = array() ) {
		self::enqueue();
		$ok    = ncp()->links->is_usable( $link );
		$coins = ncp()->coins->for_checkout( $link->coins );
		$opts  = wp_parse_args( $opts, array( 'inline' => false ) );
		$file  = locate_template( 'novacrypto-pay/link-form.php' ) ?: NCP_DIR . 'templates/link-form.php';
		ob_start();
		include $file;
		return ob_get_clean();
	}

	/** Coin picker with live quotes (used by shortcodes, link forms, WC checkout). */
	public static function render_coin_picker( array $coins, $amount_fiat, $fiat, $name = 'ncp_coin', $selected = '' ) {
		$file = locate_template( 'novacrypto-pay/coin-picker.php' ) ?: NCP_DIR . 'templates/coin-picker.php';
		ob_start();
		include $file;
		return ob_get_clean();
	}

	/**
	 * Wallet-native payment URI (BIP21 / EIP-681 / Solana Pay / BOLT11 / Tron).
	 */
	public static function payment_uri( $payment ) {
		$coin = $payment->coin;
		if ( ! $coin ) {
			return '';
		}
		$amount = NCP_Coins::format_amount( $payment->amount_crypto, $coin['decimals'] );
		$label  = rawurlencode( get_bloginfo( 'name' ) );
		switch ( $coin['family'] ) {
			case 'utxo':
				$addr = 'bch' === $coin['network'] ? preg_replace( '/^bitcoincash:/i', '', $payment->address ) : $payment->address;
				return $coin['uri_scheme'] . ':' . $addr . '?amount=' . $amount . '&label=' . $label;
			case 'evm':
				$chain = (int) $coin['chain_id'];
				if ( $coin['contract'] ) {
					$units = self::to_units( $payment->amount_crypto, (int) $coin['decimals'] );
					return 'ethereum:' . $coin['contract'] . '@' . $chain . '/transfer?address=' . $payment->address . '&uint256=' . $units;
				}
				$wei = self::to_units( $payment->amount_crypto, 18 );
				return 'ethereum:' . $payment->address . '@' . $chain . '?value=' . $wei;
			case 'solana':
				$uri = 'solana:' . $payment->address . '?amount=' . $amount . '&label=' . $label . '&message=' . rawurlencode( $payment->title ?: 'Payment' );
				if ( $coin['contract'] ) {
					$uri .= '&spl-token=' . $coin['contract'];
				}
				return $uri;
			case 'lightning':
				return 'lightning:' . strtoupper( $payment->address );
			case 'tron':
				return $payment->address; // No widely-supported URI scheme; QR encodes the bare address.
		}
		return $payment->address;
	}

	/** Decimal string -> integer base units (string) without float error. */
	public static function to_units( $amount, $decimals ) {
		$amount = (string) $amount;
		$parts  = explode( '.', $amount, 2 );
		$int    = ltrim( $parts[0], '0' );
		$frac   = isset( $parts[1] ) ? substr( str_pad( $parts[1], $decimals, '0' ), 0, $decimals ) : str_repeat( '0', $decimals );
		$units  = ltrim( $int . $frac, '0' );
		return '' === $units ? '0' : $units;
	}

	/** Wallet button config for the JS layer. */
	public static function wallet_config( $payment ) {
		$coin = $payment->coin;
		if ( ! $coin || ! ncp()->settings->get( 'show_wallet_buttons', true ) ) {
			return array();
		}
		$cfg = array( 'family' => $coin['family'], 'uri' => self::payment_uri( $payment ) );
		if ( 'evm' === $coin['family'] ) {
			$nets  = ncp()->coins->evm_networks();
			$net   = $nets[ $coin['network'] ] ?? array();
			$cfg  += array(
				'chainId'   => '0x' . dechex( (int) $coin['chain_id'] ),
				'chainName' => $net['name'] ?? $coin['network'],
				'rpc'       => $net['rpc'] ?? '',
				'explorer'  => $net['explorer'] ?? '',
				'symbol'    => $net['symbol'] ?? 'ETH',
				'to'        => $payment->address,
				'contract'  => $coin['contract'],
				'valueHex'  => '0x' . NCP_Math::to_hex( self::to_units( $payment->amount_crypto, $coin['contract'] ? (int) $coin['decimals'] : 18 ), 0 ),
			);
		}
		return $cfg;
	}

	/* ---------- AJAX ---------- */

	private function verify_nonce() {
		if ( ! check_ajax_referer( 'ncp_front', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired, please reload the page.', 'novacrypto-pay' ) ), 403 );
		}
	}

	public function ajax_status() {
		$this->verify_nonce();
		$uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$payment = ncp()->payments->get_by_uuid( $uuid );
		if ( ! $payment ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		if ( in_array( $payment->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true ) ) {
			$checked = ncp()->monitor->check( $payment );
			if ( ! is_wp_error( $checked ) ) {
				$payment = $checked;
			}
		}
		wp_send_json_success( ncp()->payments->to_public_array( $payment ) );
	}

	/** Create a payment from a shortcode button / donation box / payment link form. */
	public function ajax_start() {
		$this->verify_nonce();
		$p       = wp_unslash( $_POST ); // phpcs:ignore -- sanitized below.
		$coin_id = sanitize_key( $p['coin'] ?? '' );
		$email   = sanitize_email( $p['email'] ?? '' );
		$name    = sanitize_text_field( $p['name'] ?? '' );
		if ( ncp()->settings->get( 'require_email', true ) && ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'novacrypto-pay' ) ) );
		}
		if ( ! empty( $p['link'] ) ) {
			$link = ncp()->links->get_by_slug( sanitize_text_field( $p['link'] ) );
			if ( ! $link ) {
				wp_send_json_error( array( 'message' => __( 'Link not found.', 'novacrypto-pay' ) ) );
			}
			$payment = ncp()->links->start_payment( $link, $coin_id, (float) ( $p['amount'] ?? 0 ), $email, $name );
		} else {
			// Shortcode / block: the amount is signed server-side so it cannot be tampered with.
			$sig  = sanitize_text_field( $p['sig'] ?? '' );
			$args = array(
				'amount'   => (string) ( $p['amount'] ?? '' ),
				'currency' => strtoupper( sanitize_text_field( $p['currency'] ?? '' ) ),
				'title'    => sanitize_text_field( $p['title'] ?? '' ),
				'custom'   => ! empty( $p['custom'] ) ? '1' : '0',
				'ref'      => sanitize_text_field( $p['ref'] ?? '' ),
			);
			if ( '1' !== $args['custom'] && ! hash_equals( self::sign( $args ), $sig ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid request signature.', 'novacrypto-pay' ) ) );
			}
			$amount = (float) $args['amount'];
			if ( '1' === $args['custom'] ) {
				$amount = (float) ( $p['custom_amount'] ?? 0 );
				$min    = (float) ( $p['min'] ?? 0 );
				if ( $amount <= 0 || ( $min && $amount < $min ) ) {
					wp_send_json_error( array( 'message' => __( 'Please enter a valid amount.', 'novacrypto-pay' ) ) );
				}
			}
			$payment = ncp()->payments->create(
				array(
					'source'         => '1' === $args['custom'] ? 'donation' : 'shortcode',
					'source_id'      => $args['ref'],
					'coin_id'        => $coin_id,
					'amount_fiat'    => $amount,
					'fiat_currency'  => $args['currency'] ?: ncp()->settings->store_currency(),
					'customer_email' => $email,
					'customer_name'  => $name,
					'title'          => $args['title'],
					'return_url'     => esc_url_raw( $p['return_url'] ?? '' ),
					'apply_discount' => false,
				)
			);
		}
		if ( is_wp_error( $payment ) ) {
			wp_send_json_error( array( 'message' => $payment->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'uuid' => $payment->uuid,
				'url'  => self::pay_url( $payment->uuid ),
				'html' => self::render_payment_box( $payment ),
			)
		);
	}

	/** Live quote for a coin picker. */
	public function ajax_quote() {
		$this->verify_nonce();
		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		$fiat   = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : ncp()->settings->store_currency();
		$coins  = isset( $_POST['coins'] ) ? array_map( 'sanitize_key', (array) $_POST['coins'] ) : array_keys( ncp()->coins->for_checkout() );
		$out    = array();
		foreach ( $coins as $id ) {
			$coin = ncp()->coins->get( $id );
			if ( ! $coin ) {
				continue;
			}
			$conv = ncp()->rates->convert( $amount, $id, $fiat );
			$out[ $id ] = array(
				'amount' => is_wp_error( $conv ) ? null : NCP_Coins::format_amount( $conv['amount'], $coin['decimals'] ),
				'rate'   => is_wp_error( $conv ) ? null : $conv['rate'],
				'fee'    => ncp()->settings->get( 'show_fee_estimate' ) ? ncp()->rates->fee_estimate( $id ) : null,
				'symbol' => $coin['symbol'],
			);
		}
		wp_send_json_success( $out );
	}

	public static function sign( array $args ) {
		ksort( $args );
		return hash_hmac( 'sha256', wp_json_encode( $args ), wp_salt( 'nonce' ) );
	}
}
