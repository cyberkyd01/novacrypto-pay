<?php
/**
 * Main plugin orchestrator.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

final class NCP_Plugin {

	/** @var NCP_Plugin */
	private static $instance;

	/** @var NCP_Settings */
	public $settings;
	/** @var NCP_Coins */
	public $coins;
	/** @var NCP_Rates */
	public $rates;
	/** @var NCP_Wallets */
	public $wallets;
	/** @var NCP_Payments */
	public $payments;
	/** @var NCP_Monitor */
	public $monitor;
	/** @var NCP_Notifications */
	public $notifications;
	/** @var NCP_Links */
	public $links;
	/** @var NCP_Logger */
	public $logger;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'novacrypto-pay', false, dirname( NCP_BASENAME ) . '/languages' );

		$this->logger        = new NCP_Logger();
		$this->settings      = new NCP_Settings();
		$this->coins         = new NCP_Coins();
		$this->rates         = new NCP_Rates();
		$this->wallets       = new NCP_Wallets();
		$this->payments      = new NCP_Payments();
		$this->monitor       = new NCP_Monitor();
		$this->notifications = new NCP_Notifications();
		$this->links         = new NCP_Links();

		new NCP_Frontend();
		new NCP_Shortcodes();
		new NCP_Blocks();
		new NCP_Rest();

		if ( is_admin() ) {
			new NCP_Admin();
		}

		add_action( 'plugins_loaded', array( $this, 'load_integrations' ), 20 );
		add_action( 'init', array( 'NCP_Install', 'maybe_upgrade' ), 1 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ncp', 'NCP_CLI' );
		}
	}

	public function load_integrations() {
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'register_wc_gateway' ) );
			new NCP_WC_Integration();
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				$this->register_wc_blocks();
			} else {
				add_action( 'woocommerce_blocks_loaded', array( $this, 'register_wc_blocks' ) );
			}
		}
		if ( function_exists( 'edd_get_option' ) || class_exists( 'Easy_Digital_Downloads' ) ) {
			new NCP_EDD_Gateway();
		}
	}

	public function register_wc_gateway( $gateways ) {
		$gateways[] = 'NCP_WC_Gateway';
		return $gateways;
	}

	public function register_wc_blocks() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( $registry ) {
					$registry->register( new NCP_WC_Blocks() );
				}
			);
		}
	}
}
