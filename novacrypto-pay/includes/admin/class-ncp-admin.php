<?php
/**
 * Admin: menus, pages, actions, AJAX helpers.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_ncp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_ncp_payment_action', array( $this, 'payment_action' ) );
		add_action( 'admin_post_ncp_link_save', array( $this, 'link_save' ) );
		add_action( 'admin_post_ncp_link_action', array( $this, 'link_action' ) );
		add_action( 'admin_post_ncp_tools', array( $this, 'tools_action' ) );
		add_action( 'admin_post_ncp_export', array( $this, 'export' ) );
		add_action( 'wp_ajax_ncp_admin', array( $this, 'ajax' ) );
		add_filter( 'plugin_action_links_' . NCP_BASENAME, array( $this, 'plugin_links' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=ncp-settings' ) ) . '">' . __( 'Settings', 'novacrypto-pay' ) . '</a>' );
		return $links;
	}

	public function menu() {
		$cap  = 'manage_novacrypto';
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="#a7aaad" stroke-width="2"/><path d="M9 7h5a2.5 2.5 0 010 5H9m0 0h5.5a2.5 2.5 0 010 5H9M11 5v2m0 10v2" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round"/></svg>' );
		$pending = ncp()->payments->stats( 30 )['pending'];
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_menu_page( __( 'NovaCrypto Pay', 'novacrypto-pay' ), __( 'NovaCrypto Pay', 'novacrypto-pay' ) . $badge, $cap, 'ncp-dashboard', array( $this, 'page_dashboard' ), $icon, 56 );
		add_submenu_page( 'ncp-dashboard', __( 'Dashboard', 'novacrypto-pay' ), __( 'Dashboard', 'novacrypto-pay' ), $cap, 'ncp-dashboard', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'ncp-dashboard', __( 'Payments', 'novacrypto-pay' ), __( 'Payments', 'novacrypto-pay' ) . $badge, $cap, 'ncp-payments', array( $this, 'page_payments' ) );
		add_submenu_page( 'ncp-dashboard', __( 'Payment Links & Invoices', 'novacrypto-pay' ), __( 'Links & Invoices', 'novacrypto-pay' ), $cap, 'ncp-links', array( $this, 'page_links' ) );
		add_submenu_page( 'ncp-dashboard', __( 'Settings', 'novacrypto-pay' ), __( 'Settings', 'novacrypto-pay' ), $cap, 'ncp-settings', array( $this, 'page_settings' ) );
		add_submenu_page( 'ncp-dashboard', __( 'Tools & Logs', 'novacrypto-pay' ), __( 'Tools & Logs', 'novacrypto-pay' ), $cap, 'ncp-tools', array( $this, 'page_tools' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'ncp-' ) && 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ncp-admin', NCP_URL . 'assets/css/admin.css', array(), NCP_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'ncp-admin', NCP_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), NCP_VERSION, true );
		wp_localize_script( 'ncp-admin', 'ncpAdmin', array( 'nonce' => wp_create_nonce( 'ncp_admin' ) ) );
	}

	public function maybe_redirect() {
		if ( get_transient( 'ncp_activation_redirect' ) && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore
			delete_transient( 'ncp_activation_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=ncp-settings&welcome=1' ) );
			exit;
		}
	}

	public function notices() {
		if ( ! current_user_can( 'manage_novacrypto' ) ) {
			return;
		}
		if ( isset( $_GET['ncp_msg'] ) ) { // phpcs:ignore
			$msg  = sanitize_text_field( wp_unslash( $_GET['ncp_msg'] ) ); // phpcs:ignore
			$type = isset( $_GET['ncp_type'] ) ? sanitize_key( wp_unslash( $_GET['ncp_type'] ) ) : 'success'; // phpcs:ignore
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'ncp-' ) ) {
			if ( ! ncp()->coins->enabled() ) {
				echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( '<strong>NovaCrypto Pay:</strong> no coins are enabled yet. Add at least one wallet address under <a href="%s">Settings → Coins &amp; Wallets</a>.', 'novacrypto-pay' ), esc_url( admin_url( 'admin.php?page=ncp-settings#coins' ) ) ) ) . '</p></div>';
			}
			if ( ! NCP_Math::available() ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'NovaCrypto Pay: the PHP GMP or BCMath extension is missing, so HD wallet (xpub) address generation is disabled. Static addresses still work.', 'novacrypto-pay' ) . '</p></div>';
			}
			if ( ncp()->settings->is_testnet() ) {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'NovaCrypto Pay is in TEST MODE (testnets). Disable it in Settings → General before going live.', 'novacrypto-pay' ) . '</p></div>';
			}
		}
	}

	private function redirect( $page, $msg, $type = 'success', $extra = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'ncp_msg' => rawurlencode( $msg ), 'ncp_type' => $type ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function check( $nonce_action ) {
		if ( ! current_user_can( 'manage_novacrypto' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'novacrypto-pay' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/* ---------- Dashboard ---------- */

	public function page_dashboard() {
		$stats = ncp()->payments->stats( 30 );
		$recent = ncp()->payments->query( array( 'per_page' => 10 ) );
		$fiat  = ncp()->settings->store_currency();
		include NCP_DIR . 'includes/admin/views/dashboard.php';
	}

	public function dashboard_widget() {
		if ( current_user_can( 'manage_novacrypto' ) ) {
			wp_add_dashboard_widget( 'ncp_widget', __( 'Crypto payments (30 days)', 'novacrypto-pay' ), function () {
				$s = ncp()->payments->stats( 30 );
				echo '<p><strong>' . esc_html( number_format( $s['paid_fiat'], 2 ) . ' ' . ncp()->settings->store_currency() ) . '</strong> ' . esc_html__( 'received in', 'novacrypto-pay' ) . ' ' . (int) $s['paid_count'] . ' ' . esc_html__( 'payments', 'novacrypto-pay' ) . ' · ' . (int) $s['pending'] . ' ' . esc_html__( 'pending', 'novacrypto-pay' ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=ncp-payments' ) ) . '">' . esc_html__( 'View all payments →', 'novacrypto-pay' ) . '</a></p>';
			} );
		}
	}

	/* ---------- Payments ---------- */

	public function page_payments() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore
		if ( 'view' === $action && ! empty( $_GET['id'] ) ) { // phpcs:ignore
			$payment = ncp()->payments->get( (int) $_GET['id'] ); // phpcs:ignore
			if ( ! $payment ) {
				wp_die( esc_html__( 'Payment not found.', 'novacrypto-pay' ) );
			}
			$data = ncp()->payments->to_public_array( $payment );
			$logs = array();
			include NCP_DIR . 'includes/admin/views/payment-detail.php';
			return;
		}
		$table = new NCP_Payments_Table();
		$table->prepare_items();
		include NCP_DIR . 'includes/admin/views/payments.php';
	}

	public function payment_action() {
		$this->check( 'ncp_payment_action' );
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$payment = ncp()->payments->get( $id );
		if ( ! $payment ) {
			$this->redirect( 'ncp-payments', __( 'Payment not found.', 'novacrypto-pay' ), 'error' );
		}
		$extra = array( 'action' => 'view', 'id' => $id );
		switch ( $do ) {
			case 'check':
				$r = ncp()->monitor->check( $payment, true );
				$this->redirect( 'ncp-payments', is_wp_error( $r ) ? $r->get_error_message() : __( 'Blockchain check complete.', 'novacrypto-pay' ), is_wp_error( $r ) ? 'error' : 'success', $extra );
				break;
			case 'mark_paid':
				$txid = isset( $_POST['txid'] ) ? sanitize_text_field( wp_unslash( $_POST['txid'] ) ) : '';
				if ( $txid ) {
					ncp()->payments->add_transaction( $payment, $txid, $payment->amount_crypto, 999, time(), 'manual' );
				}
				ncp()->payments->update( $id, array( 'received_crypto' => $payment->amount_crypto, 'confirmations' => 999 ) );
				ncp()->payments->set_status( $payment, 'paid', 'manually confirmed by ' . wp_get_current_user()->user_login );
				$this->redirect( 'ncp-payments', __( 'Payment marked as paid.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'accept_underpaid':
				ncp()->payments->update_meta( $payment, 'underpaid_accepted', (float) $payment->amount_crypto - (float) $payment->received_crypto );
				ncp()->payments->set_status( $payment, 'paid', 'underpayment accepted by admin' );
				$this->redirect( 'ncp-payments', __( 'Underpayment accepted; payment marked paid.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'cancel':
				ncp()->payments->set_status( $payment, 'cancelled', 'cancelled by admin' );
				$this->redirect( 'ncp-payments', __( 'Payment cancelled.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'refunded':
				ncp()->payments->set_status( $payment, 'refunded', 'refund sent by admin' );
				$this->redirect( 'ncp-payments', __( 'Payment marked refunded.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'extend':
				ncp()->payments->extend( $payment, 60 );
				if ( 'expired' === $payment->status ) {
					ncp()->payments->set_status( $payment, 'pending', 'extended by admin' );
				}
				$this->redirect( 'ncp-payments', __( 'Payment window extended by 60 minutes.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'requote':
				$r = ncp()->payments->requote( $payment );
				$this->redirect( 'ncp-payments', is_wp_error( $r ) ? $r->get_error_message() : __( 'Payment re-quoted at the current rate.', 'novacrypto-pay' ), is_wp_error( $r ) ? 'error' : 'success', $extra );
				break;
			case 'resend_email':
				if ( $payment->customer_email ) {
					ncp()->notifications->email_customer( $payment, 'paid' === $payment->status ? 'paid' : 'detected' );
				}
				$this->redirect( 'ncp-payments', __( 'Email sent.', 'novacrypto-pay' ), 'success', $extra );
				break;
			case 'note':
				$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
				$notes = (array) ( $payment->meta['admin_notes'] ?? array() );
				$notes[] = array( 'time' => time(), 'user' => wp_get_current_user()->user_login, 'note' => $note );
				ncp()->payments->update_meta( $payment, 'admin_notes', $notes );
				$this->redirect( 'ncp-payments', __( 'Note added.', 'novacrypto-pay' ), 'success', $extra );
				break;
		}
		$this->redirect( 'ncp-payments', __( 'Unknown action.', 'novacrypto-pay' ), 'error', $extra );
	}

	/* ---------- Links & invoices ---------- */

	public function page_links() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore
		$link   = null;
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$link = 'edit' === $action && ! empty( $_GET['id'] ) ? ncp()->links->get( (int) $_GET['id'] ) : null; // phpcs:ignore
			$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ( $link ? $link->type : 'link' ); // phpcs:ignore
			include NCP_DIR . 'includes/admin/views/link-form.php';
			return;
		}
		$links = ncp()->links->all();
		include NCP_DIR . 'includes/admin/views/links.php';
	}

	public function link_save() {
		$this->check( 'ncp_link_save' );
		$p    = wp_unslash( $_POST ); // phpcs:ignore -- sanitized in NCP_Links.
		$args = array(
			'type'                => sanitize_key( $p['type'] ?? 'link' ),
			'title'               => $p['title'] ?? '',
			'description'         => $p['description'] ?? '',
			'amount'              => $p['amount'] ?? null,
			'currency'            => $p['currency'] ?? ncp()->settings->store_currency(),
			'allow_custom_amount' => ! empty( $p['allow_custom_amount'] ),
			'min_amount'          => $p['min_amount'] ?? null,
			'coins'               => (array) ( $p['coins'] ?? array() ),
			'customer_email'      => $p['customer_email'] ?? '',
			'customer_name'       => $p['customer_name'] ?? '',
			'max_uses'            => $p['max_uses'] ?? null,
			'expires_at'          => $p['expires_at'] ?? null,
			'success_url'         => $p['success_url'] ?? '',
		);
		if ( 'invoice' === $args['type'] ) {
			$args['max_uses'] = 1;
			$args['allow_custom_amount'] = false;
		}
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			$fields = $args;
			$fields['coins'] = array_values( array_filter( array_map( 'sanitize_key', $fields['coins'] ) ) );
			$fields['title'] = sanitize_text_field( $fields['title'] );
			$fields['description'] = wp_kses_post( $fields['description'] );
			$fields['amount'] = '' === $fields['amount'] || null === $fields['amount'] ? null : (float) $fields['amount'];
			$fields['currency'] = strtoupper( substr( sanitize_text_field( $fields['currency'] ), 0, 3 ) );
			$fields['allow_custom_amount'] = (int) $fields['allow_custom_amount'];
			$fields['min_amount'] = $fields['min_amount'] ? (float) $fields['min_amount'] : null;
			$fields['customer_email'] = sanitize_email( $fields['customer_email'] );
			$fields['customer_name'] = sanitize_text_field( $fields['customer_name'] );
			$fields['max_uses'] = $fields['max_uses'] ? (int) $fields['max_uses'] : null;
			$fields['expires_at'] = $fields['expires_at'] ? gmdate( 'Y-m-d H:i:s', strtotime( $fields['expires_at'] ) ) : null;
			$fields['success_url'] = esc_url_raw( $fields['success_url'] );
			$fields['type'] = in_array( $fields['type'], array( 'link', 'invoice', 'donation' ), true ) ? $fields['type'] : 'link';
			ncp()->links->update( $id, $fields );
			$link = ncp()->links->get( $id );
		} else {
			$link = ncp()->links->create( $args );
			if ( is_wp_error( $link ) ) {
				$this->redirect( 'ncp-links', $link->get_error_message(), 'error' );
			}
		}
		if ( 'invoice' === $link->type && ! empty( $p['send_now'] ) ) {
			$sent = ncp()->links->send_invoice( $link );
			if ( is_wp_error( $sent ) || ! $sent ) {
				$this->redirect( 'ncp-links', __( 'Invoice saved, but the email could not be sent. Check your site mail configuration (e.g. an SMTP plugin) and use the Email button to retry.', 'novacrypto-pay' ), 'warning' );
			}
			$this->redirect( 'ncp-links', __( 'Invoice saved and emailed.', 'novacrypto-pay' ) );
		}
		$this->redirect( 'ncp-links', __( 'Saved.', 'novacrypto-pay' ) );
	}

	public function link_action() {
		$this->check( 'ncp_link_action' );
		$id   = (int) ( $_POST['id'] ?? 0 );
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$link = ncp()->links->get( $id );
		if ( ! $link ) {
			$this->redirect( 'ncp-links', __( 'Not found.', 'novacrypto-pay' ), 'error' );
		}
		switch ( $do ) {
			case 'delete':
				ncp()->links->delete( $id );
				$this->redirect( 'ncp-links', __( 'Deleted.', 'novacrypto-pay' ) );
				break;
			case 'toggle':
				ncp()->links->update( $id, array( 'status' => 'active' === $link->status ? 'disabled' : 'active' ) );
				$this->redirect( 'ncp-links', __( 'Status updated.', 'novacrypto-pay' ) );
				break;
			case 'send':
				$r = ncp()->links->send_invoice( $link );
				$this->redirect( 'ncp-links', is_wp_error( $r ) ? $r->get_error_message() : ( $r ? __( 'Invoice emailed.', 'novacrypto-pay' ) : __( 'Email could not be sent (check your mail configuration).', 'novacrypto-pay' ) ), is_wp_error( $r ) || ! $r ? 'error' : 'success' );
				break;
		}
		$this->redirect( 'ncp-links', __( 'Unknown action.', 'novacrypto-pay' ), 'error' );
	}

	/* ---------- Settings ---------- */

	public function page_settings() {
		$page = new NCP_Settings_Page();
		$page->render();
	}

	public function save_settings() {
		$this->check( 'ncp_save_settings' );
		$in = isset( $_POST['ncp'] ) ? (array) wp_unslash( $_POST['ncp'] ) : array(); // phpcs:ignore -- sanitized per-field below.
		$s  = ncp()->settings;
		$d  = $s->defaults();
		$errors = array();

		$bools = array( 'enabled', 'testnet', 'rate_lock', 'require_email', 'collect_refund_address', 'allow_txid_submit', 'unique_amounts', 'zero_conf', 'show_fee_estimate', 'show_wallet_buttons', 'notify_admin_paid', 'notify_admin_underpaid', 'notify_customer_detected', 'notify_customer_paid', 'notify_customer_expired', 'enable_rest', 'enable_logging', 'debug_logging', 'delete_on_uninstall' );
		$ints  = array( 'payment_window', 'gap_limit', 'rate_cache_ttl', 'poll_interval', 'explorer_min_interval', 'border_radius' );
		$floats = array( 'crypto_discount', 'underpay_tolerance', 'global_markup' );
		$texts = array( 'title', 'order_prefix', 'coinmarketcap_key', 'etherscan_key', 'blockcypher_token', 'trongrid_key', 'lnbits_invoice_key', 'lightning_address', 'telegram_token', 'telegram_chat_id', 'email_from_name', 'admin_email' );
		$urls  = array( 'solana_rpc', 'lnbits_url', 'discord_webhook', 'slack_webhook', 'logo_url' );
		$selects = array(
			'underpay_action'   => array( 'hold', 'accept', 'ask' ),
			'overpay_action'    => array( 'accept' ),
			'pay_page_mode'     => array( 'theme', 'standalone' ),
			'theme_mode'        => array( 'auto', 'light', 'dark' ),
			'lightning_backend' => array( 'none', 'lnbits', 'lnaddress' ),
		);
		$new = array();
		foreach ( $bools as $k ) {
			$new[ $k ] = ! empty( $in[ $k ] );
		}
		foreach ( $ints as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) ? (int) $in[ $k ] : $d[ $k ];
		}
		foreach ( $floats as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) ? (float) $in[ $k ] : $d[ $k ];
		}
		foreach ( $texts as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $d[ $k ];
		}
		foreach ( $urls as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) ? esc_url_raw( trim( $in[ $k ] ) ) : $d[ $k ];
		}
		foreach ( $selects as $k => $allowed ) {
			$new[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], $allowed, true ) ? $in[ $k ] : $d[ $k ];
		}
		$new['description']    = isset( $in['description'] ) ? wp_kses_post( $in['description'] ) : $d['description'];
		$new['custom_css']     = isset( $in['custom_css'] ) ? wp_strip_all_tags( $in['custom_css'] ) : '';
		$new['store_currency'] = isset( $in['store_currency'] ) ? strtoupper( substr( sanitize_text_field( $in['store_currency'] ), 0, 3 ) ) : 'USD';
		$new['primary_color']  = sanitize_hex_color( $in['primary_color'] ?? '' ) ?: $d['primary_color'];
		$new['accent_color']   = sanitize_hex_color( $in['accent_color'] ?? '' ) ?: $d['accent_color'];
		$new['checkout_coins'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['checkout_coins'] ?? array() ) ) ) );
		$new['webhook_urls']   = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', (array) ( $in['webhook_urls'] ?? array() ) ) ) ) );

		$providers = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['rate_providers'] ?? array() ) ), array_keys( ncp()->rates->providers() ) ) );
		$primary   = sanitize_key( $in['rate_primary'] ?? '' );
		if ( $primary && in_array( $primary, $providers, true ) ) {
			$providers = array_merge( array( $primary ), array_diff( $providers, array( $primary ) ) );
		}
		$new['rate_providers'] = $providers ?: $d['rate_providers'];

		foreach ( array( 'manual_rates', 'coin_confirmations', 'coin_markup', 'coin_min_amount' ) as $map ) {
			$new[ $map ] = array();
			foreach ( (array) ( $in[ $map ] ?? array() ) as $coin => $v ) {
				if ( '' !== trim( (string) $v ) ) {
					$new[ $map ][ sanitize_key( $coin ) ] = (float) $v;
				}
			}
		}
		$new['custom_rpcs'] = array();
		foreach ( (array) ( $in['custom_rpcs'] ?? array() ) as $row ) {
			if ( ! empty( $row['chain_id'] ) && ! empty( $row['url'] ) ) {
				$new['custom_rpcs'][ (int) $row['chain_id'] ] = esc_url_raw( $row['url'] );
			}
		}
		$new['custom_networks'] = array();
		foreach ( (array) ( $in['custom_networks'] ?? array() ) as $row ) {
			if ( empty( $row['id'] ) || empty( $row['chain_id'] ) ) {
				continue;
			}
			$new['custom_networks'][] = array(
				'id'            => sanitize_key( $row['id'] ),
				'name'          => sanitize_text_field( $row['name'] ?? '' ),
				'chain_id'      => (int) $row['chain_id'],
				'symbol'        => strtoupper( sanitize_text_field( $row['symbol'] ?? 'ETH' ) ),
				'rpc'           => esc_url_raw( $row['rpc'] ?? '' ),
				'explorer'      => esc_url_raw( $row['explorer'] ?? '' ),
				'blockscout'    => esc_url_raw( $row['blockscout'] ?? '' ),
				'gecko'         => sanitize_text_field( $row['gecko'] ?? '' ),
				'confirmations' => (int) ( $row['confirmations'] ?? 5 ),
			);
		}
		$new['custom_tokens'] = array();
		foreach ( (array) ( $in['custom_tokens'] ?? array() ) as $row ) {
			if ( empty( $row['symbol'] ) || empty( $row['contract'] ) || empty( $row['network'] ) ) {
				continue;
			}
			$new['custom_tokens'][] = array(
				'symbol'        => strtoupper( sanitize_text_field( $row['symbol'] ) ),
				'name'          => sanitize_text_field( $row['name'] ?? '' ),
				'network'       => sanitize_key( $row['network'] ),
				'contract'      => sanitize_text_field( $row['contract'] ),
				'decimals'      => (int) ( $row['decimals'] ?? 18 ),
				'gecko'         => sanitize_text_field( $row['gecko'] ?? '' ),
				'stable'        => ! empty( $row['stable'] ),
				'confirmations' => (int) ( $row['confirmations'] ?? 5 ),
			);
		}

		$s->update( $new );
		// Rebuild the coin registry with the new networks/tokens before saving wallets.
		ncp()->coins->build();

		// Wallets.
		$win = isset( $_POST['ncp_wallets'] ) ? (array) wp_unslash( $_POST['ncp_wallets'] ) : array(); // phpcs:ignore
		foreach ( ncp()->coins->all() as $id => $coin ) {
			$w   = (array) ( $win[ $id ] ?? array() );
			$adr = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) ( $w['addresses'] ?? '' ) ) ) ) );
			foreach ( $adr as $i => $a ) {
				$v = NCP_Validator::validate( $a, $coin );
				if ( is_wp_error( $v ) ) {
					$errors[] = $coin['name'] . ': ' . $v->get_error_message() . ' (' . $a . ') – ' . __( 'not saved', 'novacrypto-pay' );
					unset( $adr[ $i ] );
				}
			}
			$adr = array_values( $adr );
			$xpub = trim( (string) ( $w['xpub'] ?? '' ) );
			if ( $xpub && $coin['hd'] ) {
				$v = NCP_Validator::xpub( $xpub, $coin );
				if ( is_wp_error( $v ) ) {
					$errors[] = $coin['name'] . ': ' . $v->get_error_message();
					$xpub     = '';
				}
			} elseif ( $xpub ) {
				$xpub = '';
			}
			$old = ncp()->wallets->get( $id );
			ncp()->wallets->set(
				$id,
				array(
					'enabled'     => ! empty( $w['enabled'] ),
					'addresses'   => $adr,
					'xpub'        => $xpub,
					'xpub_script' => in_array( $w['xpub_script'] ?? '', array( '', 'p2pkh', 'p2wpkh', 'p2sh-p2wpkh' ), true ) ? ( $w['xpub_script'] ?? '' ) : '',
					'hd_index'    => $xpub && $xpub === $old['xpub'] ? (int) ( $w['hd_index'] ?? $old['hd_index'] ) : 0,
					'hd_change'   => (int) ( $w['hd_change'] ?? 0 ),
				)
			);
		}
		if ( ! empty( $_POST['regenerate_api_key'] ) ) { // phpcs:ignore
			update_option( 'ncp_api_key', 'ncp_' . wp_generate_password( 40, false ) );
		}
		if ( ! empty( $_POST['regenerate_webhook_secret'] ) ) { // phpcs:ignore
			update_option( 'ncp_webhook_secret', wp_generate_password( 48, false ) );
		}
		NCP_Frontend::add_rewrite_rules();
		flush_rewrite_rules();
		$tab = isset( $_POST['ncp_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['ncp_active_tab'] ) ) : 'general'; // phpcs:ignore
		if ( $errors ) {
			$this->redirect( 'ncp-settings', __( 'Settings saved with warnings: ', 'novacrypto-pay' ) . implode( ' | ', $errors ), 'warning', array( 'tab' => $tab ) );
		}
		$this->redirect( 'ncp-settings', __( 'Settings saved.', 'novacrypto-pay' ), 'success', array( 'tab' => $tab ) );
	}

	/* ---------- Tools ---------- */

	public function page_tools() {
		$logs = ncp()->logger->recent( 200 );
		include NCP_DIR . 'includes/admin/views/tools.php';
	}

	public function tools_action() {
		$this->check( 'ncp_tools' );
		$do = sanitize_key( $_POST['do'] ?? '' );
		switch ( $do ) {
			case 'clear_logs':
				ncp()->logger->clear();
				$this->redirect( 'ncp-tools', __( 'Logs cleared.', 'novacrypto-pay' ) );
				break;
			case 'run_cron':
				ncp()->monitor->cron_check();
				$this->redirect( 'ncp-tools', __( 'Payment check run complete.', 'novacrypto-pay' ) );
				break;
			case 'clear_rate_cache':
				global $wpdb;
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ncp_rate_%' OR option_name LIKE '_transient_timeout_ncp_rate_%' OR option_name LIKE '_transient_ncp_fee_%' OR option_name LIKE '_transient_timeout_ncp_fee_%'" ); // phpcs:ignore
				$this->redirect( 'ncp-tools', __( 'Rate cache cleared.', 'novacrypto-pay' ) );
				break;
			case 'import':
				if ( ! empty( $_FILES['file']['tmp_name'] ) ) {
					$r = ncp()->settings->import( file_get_contents( $_FILES['file']['tmp_name'] ) ); // phpcs:ignore
					$this->redirect( 'ncp-tools', is_wp_error( $r ) ? $r->get_error_message() : __( 'Settings imported.', 'novacrypto-pay' ), is_wp_error( $r ) ? 'error' : 'success' );
				}
				break;
		}
		$this->redirect( 'ncp-tools', __( 'Unknown action.', 'novacrypto-pay' ), 'error' );
	}

	public function export() {
		$this->check( 'ncp_export' );
		$what = sanitize_key( $_GET['what'] ?? 'settings' );
		if ( 'settings' === $what ) {
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename=novacrypto-settings-' . gmdate( 'Ymd' ) . '.json' );
			echo ncp()->settings->export(); // phpcs:ignore
			exit;
		}
		// Payments CSV (accounting export with rate snapshots).
		$from = sanitize_text_field( $_GET['from'] ?? '' );
		$to   = sanitize_text_field( $_GET['to'] ?? '' );
		$res  = ncp()->payments->query( array( 'per_page' => 100000, 'from' => $from, 'to' => $to, 'status' => sanitize_key( $_GET['status'] ?? '' ) ) );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=novacrypto-payments-' . gmdate( 'Ymd' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'uuid', 'created_at', 'paid_at', 'status', 'source', 'source_id', 'title', 'coin', 'symbol', 'network', 'address', 'amount_crypto', 'received_crypto', 'amount_fiat', 'fiat_currency', 'rate', 'rate_provider', 'fiat_value_received', 'confirmations', 'customer_email', 'customer_name', 'refund_address', 'txids' ) );
		foreach ( $res['items'] as $p ) {
			fputcsv( $out, array( $p->id, $p->uuid, $p->created_at, $p->paid_at, $p->status, $p->source, $p->source_id, $p->title, $p->coin_id, $p->coin['symbol'] ?? '', $p->coin ? ncp()->coins->network_label( $p->coin ) : '', $p->address, NCP_Coins::format_amount( $p->amount_crypto, 18 ), NCP_Coins::format_amount( $p->received_crypto, 18 ), $p->amount_fiat, $p->fiat_currency, $p->rate, $p->rate_provider, number_format( (float) $p->received_crypto * (float) $p->rate, 2, '.', '' ), $p->confirmations, $p->customer_email, $p->customer_name, $p->refund_address, implode( ' ', wp_list_pluck( $p->txs, 'txid' ) ) ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/* ---------- AJAX ---------- */

	public function ajax() {
		if ( ! current_user_can( 'manage_novacrypto' ) || ! check_ajax_referer( 'ncp_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$task = sanitize_key( $_POST['task'] ?? '' );
		$coin_id = sanitize_key( $_POST['coin'] ?? '' );
		switch ( $task ) {
			case 'hd_preview':
				$coin = ncp()->coins->get( $coin_id );
				$xpub = sanitize_text_field( wp_unslash( $_POST['xpub'] ?? '' ) );
				if ( ! $coin || ! $xpub ) {
					wp_send_json_error( array( 'message' => 'missing' ) );
				}
				$v = NCP_Validator::xpub( $xpub, $coin );
				if ( is_wp_error( $v ) ) {
					wp_send_json_error( array( 'message' => $v->get_error_message() ) );
				}
				$hint = in_array( $coin['network'], array( 'btc', 'ltc', 'doge' ), true ) ? $coin['network'] : 'evm';
				$out  = array();
				for ( $i = 0; $i < 5; $i++ ) {
					$a     = NCP_BIP32::address_at( $xpub, $i, 0, $hint, sanitize_text_field( wp_unslash( $_POST['script'] ?? '' ) ) );
					$out[] = '/0/' . $i . '  ' . ( is_wp_error( $a ) ? $a->get_error_message() : $a );
				}
				wp_send_json_success( $out );
				break;
			case 'validate':
				$coin = ncp()->coins->get( $coin_id );
				$list = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', sanitize_textarea_field( wp_unslash( $_POST['addresses'] ?? '' ) ) ) ) );
				if ( ! $coin ) {
					wp_send_json_error( array( 'message' => 'unknown coin' ) );
				}
				$bad = array();
				foreach ( $list as $a ) {
					$v = NCP_Validator::validate( $a, $coin );
					if ( is_wp_error( $v ) ) {
						$bad[] = $a;
					}
				}
				if ( $bad ) {
					wp_send_json_error( array( 'message' => __( 'Invalid: ', 'novacrypto-pay' ) . implode( ', ', $bad ) ) );
				}
				wp_send_json_success( array( 'message' => $list ? sprintf( _n( '%d valid address', '%d valid addresses', count( $list ), 'novacrypto-pay' ), count( $list ) ) : '' ) );
				break;
			case 'test_rates':
				$out = array();
				foreach ( ncp()->coins->enabled() as $id => $c ) {
					$r = ncp()->rates->get( $id );
					$out[ $c['name'] ] = is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : number_format( $r['rate'], 4 ) . ' ' . ncp()->settings->store_currency() . ' via ' . $r['provider'];
				}
				wp_send_json_success( $out ?: array( 'note' => 'no enabled coins' ) );
				break;
			case 'test_explorer':
				$coin = ncp()->coins->get( $coin_id );
				$w    = ncp()->wallets->get( $coin_id );
				$addr = $w['addresses'][0] ?? '';
				if ( ! $coin || ! $addr ) {
					wp_send_json_error( array( 'message' => __( 'Coin needs at least one static address to test.', 'novacrypto-pay' ) ) );
				}
				$r = ncp()->monitor->explorers->fetch( $coin, $addr );
				wp_send_json_success( is_wp_error( $r ) ? array( 'error' => $r->get_error_message() ) : array( 'providers' => ncp()->monitor->explorers->providers_for( $coin ), 'transactions_found' => count( $r ), 'sample' => array_slice( $r, 0, 3 ) ) );
				break;
			case 'test_notifications':
				wp_send_json_success( ncp()->notifications->test_all() );
				break;
			case 'test_lightning':
				$inv = NCP_Lightning::create_invoice( 0.00001, 'NovaCrypto test' );
				wp_send_json_success( is_wp_error( $inv ) ? array( 'error' => $inv->get_error_message() ) : array( 'ok' => true, 'invoice' => substr( $inv['payment_request'], 0, 40 ) . '…' ) );
				break;
			case 'system':
				wp_send_json_success( array( 'php' => PHP_VERSION, 'gmp' => function_exists( 'gmp_add' ), 'bcmath' => function_exists( 'bcadd' ), 'curl' => function_exists( 'curl_version' ), 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'next_cron' => wp_next_scheduled( 'ncp_cron_check_payments' ) ? gmdate( 'c', wp_next_scheduled( 'ncp_cron_check_payments' ) ) : 'not scheduled', 'woocommerce' => class_exists( 'WooCommerce' ), 'edd' => class_exists( 'Easy_Digital_Downloads' ), 'permalinks' => (bool) get_option( 'permalink_structure' ) ) );
				break;
		}
		wp_send_json_error( array( 'message' => 'unknown task' ) );
	}
}
