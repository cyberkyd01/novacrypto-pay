<?php
/**
 * Gutenberg blocks (server-rendered wrappers around the shortcodes).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Blocks {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	public function category( $cats ) {
		$cats[] = array( 'slug' => 'novacrypto', 'title' => __( 'NovaCrypto Pay', 'novacrypto-pay' ) );
		return $cats;
	}

	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'ncp-blocks', NCP_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ), NCP_VERSION, true );
		wp_localize_script( 'ncp-blocks', 'ncpBlocks', array( 'coins' => array_map( function ( $c ) { return array( 'id' => $c['id'], 'name' => $c['name'] ); }, array_values( ncp()->coins->enabled() ) ), 'currency' => ncp()->settings->store_currency() ) );

		$blocks = array(
			'pay-button' => array( 'shortcode' => 'ncp_pay', 'attrs' => array( 'amount' => array( 'type' => 'string', 'default' => '10' ), 'currency' => array( 'type' => 'string', 'default' => ncp()->settings->store_currency() ), 'title' => array( 'type' => 'string', 'default' => '' ), 'button' => array( 'type' => 'string', 'default' => __( 'Pay with crypto', 'novacrypto-pay' ) ), 'coins' => array( 'type' => 'string', 'default' => '' ), 'ref' => array( 'type' => 'string', 'default' => '' ) ) ),
			'donation'   => array( 'shortcode' => 'ncp_donate', 'attrs' => array( 'title' => array( 'type' => 'string', 'default' => __( 'Support us with crypto', 'novacrypto-pay' ) ), 'presets' => array( 'type' => 'string', 'default' => '5,10,25,50' ), 'min' => array( 'type' => 'string', 'default' => '1' ), 'currency' => array( 'type' => 'string', 'default' => ncp()->settings->store_currency() ), 'coins' => array( 'type' => 'string', 'default' => '' ), 'button' => array( 'type' => 'string', 'default' => __( 'Donate', 'novacrypto-pay' ) ) ) ),
			'wallets'    => array( 'shortcode' => 'ncp_wallets', 'attrs' => array( 'coins' => array( 'type' => 'string', 'default' => '' ), 'show_qr' => array( 'type' => 'string', 'default' => '1' ), 'title' => array( 'type' => 'string', 'default' => __( 'We accept cryptocurrency', 'novacrypto-pay' ) ) ) ),
			'price'      => array( 'shortcode' => 'ncp_price', 'attrs' => array( 'amount' => array( 'type' => 'string', 'default' => '100' ), 'currency' => array( 'type' => 'string', 'default' => ncp()->settings->store_currency() ), 'coin' => array( 'type' => 'string', 'default' => 'btc' ) ) ),
			'link'       => array( 'shortcode' => 'ncp_link', 'attrs' => array( 'slug' => array( 'type' => 'string', 'default' => '' ) ) ),
		);
		foreach ( $blocks as $name => $b ) {
			register_block_type(
				'novacrypto/' . $name,
				array(
					'editor_script'   => 'ncp-blocks',
					'style'           => 'ncp-frontend',
					'attributes'      => $b['attrs'],
					'render_callback' => function ( $attributes ) use ( $b ) {
						$pairs = array();
						foreach ( $attributes as $k => $v ) {
							$pairs[] = $k . '="' . esc_attr( $v ) . '"';
						}
						return do_shortcode( '[' . $b['shortcode'] . ' ' . implode( ' ', $pairs ) . ']' );
					},
				)
			);
		}
	}
}
