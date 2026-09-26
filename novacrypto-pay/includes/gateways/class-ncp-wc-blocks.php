<?php
/**
 * WooCommerce Blocks (checkout block) payment method integration.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class NCP_WC_Blocks extends AbstractPaymentMethodType {

	protected $name = 'novacrypto';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_novacrypto_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['novacrypto'] ) && $gateways['novacrypto']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script( 'ncp-wc-blocks', NCP_URL . 'assets/js/wc-blocks.js', array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ), NCP_VERSION, true );
		wp_enqueue_style( 'ncp-frontend' );
		return array( 'ncp-wc-blocks' );
	}

	public function get_payment_method_data() {
		$coins = array();
		foreach ( ncp()->coins->for_checkout() as $id => $c ) {
			$coins[] = array( 'id' => $id, 'name' => $c['name'], 'symbol' => $c['symbol'], 'network' => ncp()->coins->network_label( $c ), 'icon' => $c['icon'] );
		}
		return array(
			'title'       => $this->settings['title'] ?? ncp()->settings->get( 'title' ),
			'description' => $this->settings['description'] ?? ncp()->settings->get( 'description' ),
			'coins'       => $coins,
			'discount'    => (float) ncp()->settings->get( 'crypto_discount', 0 ),
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'ncp_front' ),
			'supports'    => array( 'products' ),
		);
	}
}
