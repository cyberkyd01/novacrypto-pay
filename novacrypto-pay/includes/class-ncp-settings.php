<?php
/**
 * Settings storage with defaults.
 *
 * All settings live in a single option `ncp_settings` (array). Coin wallet
 * configuration lives in `ncp_wallets` (see NCP_Wallets).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Settings {

	const OPTION = 'ncp_settings';

	/** @var array */
	private $data;

	public function __construct() {
		$this->data = wp_parse_args( (array) get_option( self::OPTION, array() ), $this->defaults() );
	}

	public function defaults() {
		return array(
			// General.
			'enabled'                 => true,
			// Plain strings: defaults are built before `init`, when translations may not be loaded yet.
			'title'                   => 'Cryptocurrency',
			'description'             => 'Pay with Bitcoin, Ethereum, Lightning, stablecoins and more. Funds go directly to the merchant wallet.',
			'store_currency'          => 'USD',
			'testnet'                 => false,
			'payment_window'          => 60,        // minutes.
			'rate_lock'               => true,
			'checkout_coins'          => array(),   // empty = all enabled.
			'order_prefix'            => 'NCP',
			'pay_page_mode'           => 'theme',   // theme | standalone.
			'crypto_discount'         => 0,         // percent.
			'require_email'           => true,
			'collect_refund_address'  => true,
			'allow_txid_submit'       => true,
			'gap_limit'               => 100,
			// Amount handling.
			'underpay_tolerance'      => 1.0,       // percent accepted as paid.
			'underpay_action'         => 'hold',    // hold | accept | ask.
			'overpay_action'          => 'accept',
			'unique_amounts'          => true,      // add micro-salt so shared addresses can be matched.
			'zero_conf'               => false,     // treat mempool tx as paid.
			// Rates.
			'rate_providers'          => array( 'coingecko', 'coinpaprika', 'binance', 'kraken' ),
			'rate_cache_ttl'          => 300,
			'coinmarketcap_key'       => '',
			'global_markup'           => 0,
			'manual_rates'            => array(),
			// Detection / APIs.
			'poll_interval'           => 10,        // seconds for frontend polling.
			'explorer_min_interval'   => 15,        // seconds between explorer calls per payment.
			'etherscan_key'           => '',
			'blockcypher_token'       => '',
			'trongrid_key'            => '',
			'solana_rpc'              => 'https://api.mainnet-beta.solana.com',
			'custom_rpcs'             => array(),   // chain_id => url.
			'lightning_backend'       => 'none',    // none | lnbits | lnaddress.
			'lnbits_url'              => '',
			'lnbits_invoice_key'      => '',
			'lightning_address'       => '',
			// Appearance.
			'primary_color'           => '#2563eb',
			'accent_color'            => '#16a34a',
			'border_radius'           => 12,
			'theme_mode'              => 'auto',    // auto | light | dark.
			'logo_url'                => '',
			'show_fee_estimate'       => true,
			'show_wallet_buttons'     => true,
			'custom_css'              => '',
			// Notifications.
			'admin_email'             => get_option( 'admin_email' ),
			'notify_admin_paid'       => true,
			'notify_admin_underpaid'  => true,
			'notify_customer_detected' => true,
			'notify_customer_paid'    => true,
			'notify_customer_expired' => false,
			'telegram_token'          => '',
			'telegram_chat_id'        => '',
			'discord_webhook'         => '',
			'slack_webhook'           => '',
			'webhook_urls'            => array(),
			'email_from_name'         => get_bloginfo( 'name' ),
			// Advanced.
			'enable_rest'             => true,
			'enable_logging'          => true,
			'debug_logging'           => false,
			'delete_on_uninstall'     => false,
			'custom_tokens'           => array(),
			'custom_networks'         => array(),
			'coin_confirmations'      => array(),   // coin_id => int.
			'coin_markup'             => array(),   // coin_id => percent.
			'coin_min_amount'         => array(),   // coin_id => fiat min.
		);
	}

	public function get( $key, $default = null ) {
		if ( array_key_exists( $key, $this->data ) ) {
			return apply_filters( 'ncp_setting_' . $key, $this->data[ $key ] );
		}
		return $default;
	}

	public function all() {
		return $this->data;
	}

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function update( array $values ) {
		$this->data = array_merge( $this->data, $values );
		return $this->save();
	}

	public function save() {
		return update_option( self::OPTION, $this->data, false );
	}

	public function is_testnet() {
		return (bool) $this->get( 'testnet' );
	}

	public function store_currency() {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return get_woocommerce_currency();
		}
		return strtoupper( $this->get( 'store_currency', 'USD' ) );
	}

	/** Export everything (settings + wallets) as JSON. */
	public function export() {
		return wp_json_encode(
			array(
				'version'  => NCP_VERSION,
				'settings' => $this->data,
				'wallets'  => get_option( 'ncp_wallets', array() ),
			),
			JSON_PRETTY_PRINT
		);
	}

	/** @return true|WP_Error */
	public function import( $json ) {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['settings'] ) ) {
			return new WP_Error( 'ncp_import', __( 'Invalid settings file.', 'novacrypto-pay' ) );
		}
		$this->data = wp_parse_args( (array) $decoded['settings'], $this->defaults() );
		$this->save();
		if ( isset( $decoded['wallets'] ) && is_array( $decoded['wallets'] ) ) {
			update_option( 'ncp_wallets', $decoded['wallets'], false );
		}
		return true;
	}
}
