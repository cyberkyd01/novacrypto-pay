<?php
/**
 * Plugin Name:       NovaCrypto Pay – Cryptocurrency Payments for WordPress & WooCommerce
 * Plugin URI:        https://example.com/novacrypto-pay
 * Description:       Accept Bitcoin, Ethereum, Lightning, stablecoins and 25+ networks directly to your own wallets. HD-wallet (xpub) fresh addresses, WooCommerce & EDD gateways, payment links, invoices, donations, REST API, webhooks and Telegram/Discord/Slack alerts. No middleman, no KYC, no fees.
 * Version:           1.0.0
 * Author:            NovaCrypto
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       novacrypto-pay
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.9
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

define( 'NCP_VERSION', '1.0.0' );
define( 'NCP_DB_VERSION', '1' );
define( 'NCP_FILE', __FILE__ );
define( 'NCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NCP_URL', plugin_dir_url( __FILE__ ) );
define( 'NCP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader: NCP_Foo_Bar => includes/(subdir/)class-ncp-foo-bar.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'NCP_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		foreach ( array( 'includes/', 'includes/crypto/', 'includes/gateways/', 'includes/integrations/', 'includes/admin/' ) as $dir ) {
			if ( file_exists( NCP_DIR . $dir . $file ) ) {
				require_once NCP_DIR . $dir . $file;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'NCP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NCP_Install', 'deactivate' ) );

/**
 * Global accessor.
 *
 * @return NCP_Plugin
 */
function ncp() {
	return NCP_Plugin::instance();
}

add_action( 'plugins_loaded', 'ncp', 5 );

// Declare WooCommerce HPOS + cart/checkout blocks compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
