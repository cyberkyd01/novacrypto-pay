<?php
/**
 * Notifications: email (admin + customer), Telegram, Discord, Slack and signed
 * outgoing webhooks with a retry queue.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Notifications {

	public function __construct() {
		add_action( 'ncp_payment_status_changed', array( $this, 'on_status_change' ), 10, 4 );
		add_action( 'ncp_payment_created', array( $this, 'on_created' ) );
	}

	public function on_created( $payment ) {
		$this->queue_webhooks( $payment, 'payment.created' );
	}

	public function on_status_change( $payment, $old, $new, $note ) {
		$s = ncp()->settings;
		$this->queue_webhooks( $payment, 'payment.' . $new );

		switch ( $new ) {
			case 'detected':
			case 'confirming':
				if ( $s->get( 'notify_customer_detected' ) && $payment->customer_email && empty( $payment->meta['notified_detected'] ) ) {
					$this->email_customer( $payment, 'detected' );
					ncp()->payments->update_meta( $payment, 'notified_detected', 1 );
				}
				break;
			case 'paid':
				if ( $s->get( 'notify_admin_paid' ) ) {
					$this->email_admin( $payment, 'paid' );
				}
				if ( $s->get( 'notify_customer_paid' ) && $payment->customer_email ) {
					$this->email_customer( $payment, 'paid' );
				}
				$this->chat( $payment, 'paid' );
				break;
			case 'underpaid':
				if ( $s->get( 'notify_admin_underpaid' ) ) {
					$this->email_admin( $payment, 'underpaid' );
				}
				if ( $payment->customer_email ) {
					$this->email_customer( $payment, 'underpaid' );
				}
				$this->chat( $payment, 'underpaid' );
				break;
			case 'expired':
				if ( $s->get( 'notify_customer_expired' ) && $payment->customer_email ) {
					$this->email_customer( $payment, 'expired' );
				}
				break;
		}
	}

	/* ---------- Email ---------- */

	private function headers() {
		$from = ncp()->settings->get( 'email_from_name' ) ?: get_bloginfo( 'name' );
		return array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>' );
	}

	public function render_email( $template, $payment, array $extra = array() ) {
		$file = locate_template( 'novacrypto-pay/emails/' . $template . '.php' );
		if ( ! $file ) {
			$file = NCP_DIR . 'templates/emails/' . $template . '.php';
		}
		if ( ! file_exists( $file ) ) {
			$file = NCP_DIR . 'templates/emails/generic.php';
		}
		$data = ncp()->payments->to_public_array( $payment );
		ob_start();
		include NCP_DIR . 'templates/emails/header.php';
		include $file;
		include NCP_DIR . 'templates/emails/footer.php';
		return ob_get_clean();
	}

	public function email_customer( $payment, $type ) {
		$subjects = array(
			'detected'  => __( 'We have detected your payment', 'novacrypto-pay' ),
			'paid'      => __( 'Payment confirmed – thank you!', 'novacrypto-pay' ),
			'underpaid' => __( 'Your payment was less than the amount due', 'novacrypto-pay' ),
			'expired'   => __( 'Your payment window has expired', 'novacrypto-pay' ),
			'invoice'   => __( 'You have a new invoice', 'novacrypto-pay' ),
		);
		$subject = apply_filters( 'ncp_email_subject', '[' . get_bloginfo( 'name' ) . '] ' . ( $subjects[ $type ] ?? $type ), $type, $payment );
		$body    = $this->render_email( 'customer-' . $type, $payment );
		return wp_mail( $payment->customer_email, $subject, $body, $this->headers() );
	}

	public function email_admin( $payment, $type ) {
		$to = ncp()->settings->get( 'admin_email' ) ?: get_option( 'admin_email' );
		/* translators: 1: status 2: amount 3: symbol */
		$subject = sprintf( __( '[%1$s] Crypto payment %2$s: %3$s %4$s', 'novacrypto-pay' ), get_bloginfo( 'name' ), NCP_Payments::status_label( $type ), NCP_Coins::format_amount( $payment->amount_crypto ), $payment->coin['symbol'] ?? '' );
		$body    = $this->render_email( 'admin-notification', $payment, array( 'type' => $type ) );
		return wp_mail( $to, $subject, $body, $this->headers() );
	}

	/* ---------- Chat ---------- */

	public function chat( $payment, $type ) {
		$coin = $payment->coin;
		$msg  = sprintf(
			"%s %s\n%s %s (%s %s)\n%s: %s\n%s",
			'paid' === $type ? '✅' : '⚠️',
			NCP_Payments::status_label( $type ),
			NCP_Coins::format_amount( $payment->amount_crypto ),
			$coin['symbol'] ?? '',
			number_format( (float) $payment->amount_fiat, 2 ),
			$payment->fiat_currency,
			__( 'Reference', 'novacrypto-pay' ),
			$payment->title ?: ( $payment->source . ' #' . $payment->source_id ),
			admin_url( 'admin.php?page=ncp-payments&action=view&id=' . $payment->id )
		);
		$msg = apply_filters( 'ncp_chat_message', $msg, $payment, $type );
		$this->telegram( $msg );
		$this->discord( $msg );
		$this->slack( $msg );
	}

	public function telegram( $text ) {
		$token = ncp()->settings->get( 'telegram_token' );
		$chat  = ncp()->settings->get( 'telegram_chat_id' );
		if ( ! $token || ! $chat ) {
			return false;
		}
		$res = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array( 'timeout' => 8, 'body' => array( 'chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true ) ) );
		return ! is_wp_error( $res );
	}

	public function discord( $text ) {
		$url = ncp()->settings->get( 'discord_webhook' );
		if ( ! $url ) {
			return false;
		}
		$res = wp_remote_post( $url, array( 'timeout' => 8, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'content' => $text ) ) ) );
		return ! is_wp_error( $res );
	}

	public function slack( $text ) {
		$url = ncp()->settings->get( 'slack_webhook' );
		if ( ! $url ) {
			return false;
		}
		$res = wp_remote_post( $url, array( 'timeout' => 8, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'text' => $text ) ) ) );
		return ! is_wp_error( $res );
	}

	/* ---------- Webhooks (IPN) ---------- */

	public function queue_webhooks( $payment, $event ) {
		global $wpdb;
		$urls = array_filter( array_map( 'trim', (array) ncp()->settings->get( 'webhook_urls', array() ) ) );
		if ( ! empty( $payment->meta['webhook_url'] ) ) {
			$urls[] = $payment->meta['webhook_url']; // Per-payment URL from the REST API.
		}
		$urls = apply_filters( 'ncp_webhook_urls', array_unique( $urls ), $payment, $event );
		if ( ! $urls ) {
			return;
		}
		$payload = wp_json_encode(
			array(
				'event'     => $event,
				'timestamp' => time(),
				'payment'   => ncp()->payments->to_public_array( $payment ),
				'source'    => $payment->source,
				'source_id' => $payment->source_id,
				'meta'      => array_diff_key( $payment->meta, array_flip( array( 'sol_seen' ) ) ),
			)
		);
		foreach ( $urls as $url ) {
			$wpdb->insert(
				$wpdb->prefix . 'ncp_webhook_queue',
				array(
					'payment_id'      => $payment->id,
					'event'           => $event,
					'url'             => esc_url_raw( $url ),
					'payload'         => $payload,
					'next_attempt_at' => current_time( 'mysql', true ),
					'created_at'      => current_time( 'mysql', true ),
				)
			);
		}
		// Try immediately (non-blocking for the request that triggered it).
		$this->process_webhook_queue( 5 );
	}

	public function process_webhook_queue( $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ncp_webhook_queue WHERE attempts < 8 AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d", current_time( 'mysql', true ), $limit ) ); // phpcs:ignore
		$secret = get_option( 'ncp_webhook_secret' );
		foreach ( $rows as $row ) {
			$sig = hash_hmac( 'sha256', $row->payload, $secret );
			$res = wp_remote_post(
				$row->url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type'      => 'application/json',
						'X-NCP-Event'       => $row->event,
						'X-NCP-Signature'   => 'sha256=' . $sig,
						'X-NCP-Timestamp'   => (string) time(),
						'User-Agent'        => 'NovaCryptoPay-Webhook/' . NCP_VERSION,
					),
					'body'    => $row->payload,
				)
			);
			$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
			if ( $code >= 200 && $code < 300 ) {
				$wpdb->delete( $wpdb->prefix . 'ncp_webhook_queue', array( 'id' => $row->id ) );
				ncp()->logger->debug( "Webhook {$row->event} delivered to {$row->url}", 'webhook' );
			} else {
				$attempts = (int) $row->attempts + 1;
				$delay    = min( 6 * HOUR_IN_SECONDS, 60 * pow( 3, $attempts - 1 ) ); // 1m,3m,9m,27m,81m,...
				$wpdb->update(
					$wpdb->prefix . 'ncp_webhook_queue',
					array( 'attempts' => $attempts, 'last_status' => is_wp_error( $res ) ? substr( $res->get_error_message(), 0, 20 ) : (string) $code, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ) ),
					array( 'id' => $row->id )
				);
				ncp()->logger->error( "Webhook {$row->event} to {$row->url} failed (attempt {$attempts}): " . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code ), 'webhook' );
			}
		}
	}

	/** Send a test message to every configured channel. */
	public function test_all() {
		$msg = sprintf( '🔔 %s: %s', get_bloginfo( 'name' ), __( 'NovaCrypto Pay test notification.', 'novacrypto-pay' ) );
		return array(
			'telegram' => $this->telegram( $msg ),
			'discord'  => $this->discord( $msg ),
			'slack'    => $this->slack( $msg ),
			'email'    => wp_mail( ncp()->settings->get( 'admin_email' ) ?: get_option( 'admin_email' ), $msg, $msg, $this->headers() ),
		);
	}
}
