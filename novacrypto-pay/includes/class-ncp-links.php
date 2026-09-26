<?php
/**
 * Payment links, invoices and donation pages (standalone – no shop required).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Links {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'ncp_links';
	}

	public function create( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'type'                => 'link',
				'title'               => '',
				'description'         => '',
				'amount'              => null,
				'currency'            => ncp()->settings->store_currency(),
				'allow_custom_amount' => 0,
				'min_amount'          => null,
				'coins'               => array(),
				'customer_email'      => '',
				'customer_name'       => '',
				'max_uses'            => null,
				'expires_at'          => null,
				'success_url'         => '',
				'meta'                => array(),
			)
		);
		$slug = $this->unique_slug( $args['title'] );
		$now  = current_time( 'mysql', true );
		$ok   = $wpdb->insert(
			$this->table(),
			array(
				'slug'                => $slug,
				'type'                => in_array( $args['type'], array( 'link', 'invoice', 'donation' ), true ) ? $args['type'] : 'link',
				'title'               => sanitize_text_field( $args['title'] ),
				'description'         => wp_kses_post( $args['description'] ),
				'amount'              => null === $args['amount'] || '' === $args['amount'] ? null : (float) $args['amount'],
				'currency'            => strtoupper( substr( sanitize_text_field( $args['currency'] ), 0, 3 ) ),
				'allow_custom_amount' => (int) (bool) $args['allow_custom_amount'],
				'min_amount'          => $args['min_amount'] ? (float) $args['min_amount'] : null,
				'coins'               => wp_json_encode( array_values( array_filter( array_map( 'sanitize_key', (array) $args['coins'] ) ) ) ),
				'customer_email'      => sanitize_email( $args['customer_email'] ),
				'customer_name'       => sanitize_text_field( $args['customer_name'] ),
				'status'              => 'active',
				'max_uses'            => $args['max_uses'] ? (int) $args['max_uses'] : null,
				'expires_at'          => $args['expires_at'] ? gmdate( 'Y-m-d H:i:s', strtotime( $args['expires_at'] ) ) : null,
				'success_url'         => esc_url_raw( $args['success_url'] ),
				'meta'                => wp_json_encode( (array) $args['meta'] ),
				'created_by'          => get_current_user_id(),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'ncp_db', $wpdb->last_error );
		}
		$link = $this->get( $wpdb->insert_id );
		do_action( 'ncp_link_created', $link );
		return $link;
	}

	public function update( $id, array $fields ) {
		global $wpdb;
		if ( isset( $fields['coins'] ) && is_array( $fields['coins'] ) ) {
			$fields['coins'] = wp_json_encode( array_values( $fields['coins'] ) );
		}
		if ( isset( $fields['meta'] ) && is_array( $fields['meta'] ) ) {
			$fields['meta'] = wp_json_encode( $fields['meta'] );
		}
		$fields['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( $this->table(), $fields, array( 'id' => (int) $id ) );
	}

	public function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}

	private function unique_slug( $title ) {
		global $wpdb;
		$base = sanitize_title( $title ) ?: 'pay';
		$base = substr( $base, 0, 40 );
		do {
			$slug = $base . '-' . strtolower( wp_generate_password( 6, false ) );
		} while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE slug = %s", $slug ) ) ); // phpcs:ignore
		return $slug;
	}

	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE slug = %s", $slug ) ); // phpcs:ignore
		return $row ? $this->hydrate( $row ) : null;
	}

	private function hydrate( $row ) {
		$row->coins = $row->coins ? (array) json_decode( $row->coins, true ) : array();
		$row->meta  = $row->meta ? (array) json_decode( $row->meta, true ) : array();
		$row->url   = NCP_Frontend::link_url( $row->slug );
		return $row;
	}

	public function all( $type = '' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$this->table()}";
		if ( $type ) {
			$sql .= $wpdb->prepare( ' WHERE type = %s', $type );
		}
		return array_map( array( $this, 'hydrate' ), $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT 500' ) ); // phpcs:ignore
	}

	/** @return true|WP_Error */
	public function is_usable( $link ) {
		if ( 'active' !== $link->status ) {
			return new WP_Error( 'ncp_link_inactive', __( 'This payment link is no longer active.', 'novacrypto-pay' ) );
		}
		if ( $link->expires_at && strtotime( $link->expires_at . ' UTC' ) < time() ) {
			return new WP_Error( 'ncp_link_expired', __( 'This payment link has expired.', 'novacrypto-pay' ) );
		}
		if ( $link->max_uses && (int) $link->times_used >= (int) $link->max_uses ) {
			return new WP_Error( 'ncp_link_used', __( 'This payment link has already been used.', 'novacrypto-pay' ) );
		}
		return true;
	}

	/**
	 * Create a payment from a link submission.
	 */
	public function start_payment( $link, $coin_id, $amount = null, $email = '', $name = '' ) {
		$ok = $this->is_usable( $link );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( $link->coins && ! in_array( $coin_id, $link->coins, true ) ) {
			return new WP_Error( 'ncp_coin', __( 'That coin is not accepted for this link.', 'novacrypto-pay' ) );
		}
		$fiat = (float) $link->amount;
		if ( $link->allow_custom_amount ) {
			$fiat = (float) $amount;
			if ( $fiat <= 0 || ( $link->min_amount && $fiat < (float) $link->min_amount ) ) {
				return new WP_Error( 'ncp_amount', __( 'Please enter a valid amount.', 'novacrypto-pay' ) );
			}
		}
		if ( 'invoice' === $link->type ) {
			$existing = ncp()->payments->get_by_source( 'invoice', $link->id );
			if ( $existing && 'paid' === $existing->status ) {
				return new WP_Error( 'ncp_paid', __( 'This invoice is already paid.', 'novacrypto-pay' ) );
			}
		}
		$payment = ncp()->payments->create(
			array(
				'source'         => $link->type,
				'source_id'      => $link->id,
				'coin_id'        => $coin_id,
				'amount_fiat'    => $fiat,
				'fiat_currency'  => $link->currency,
				'customer_email' => $email ?: $link->customer_email,
				'customer_name'  => $name ?: $link->customer_name,
				'title'          => $link->title,
				'return_url'     => $link->success_url,
				'meta'           => array( 'link_slug' => $link->slug ),
				'apply_discount' => 'donation' !== $link->type,
			)
		);
		if ( ! is_wp_error( $payment ) ) {
			$this->update( $link->id, array( 'times_used' => (int) $link->times_used + 1 ) );
		}
		return $payment;
	}

	/** Email an invoice to the customer. */
	public function send_invoice( $link ) {
		if ( ! $link->customer_email ) {
			return new WP_Error( 'ncp_no_email', __( 'Invoice has no customer email.', 'novacrypto-pay' ) );
		}
		$subject = sprintf( '[%s] %s: %s', get_bloginfo( 'name' ), __( 'Invoice', 'novacrypto-pay' ), $link->title );
		ob_start();
		$data = array( 'link' => $link );
		include NCP_DIR . 'templates/emails/header.php';
		include NCP_DIR . 'templates/emails/invoice.php';
		include NCP_DIR . 'templates/emails/footer.php';
		$body = ob_get_clean();
		$sent = wp_mail( $link->customer_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			$meta = $link->meta;
			$meta['sent_at'] = time();
			$this->update( $link->id, array( 'meta' => $meta ) );
		}
		return $sent;
	}

	/* Mark invoice paid when its payment completes. */
	public static function on_paid( $payment ) {
		if ( 'invoice' === $payment->source ) {
			ncp()->links->update( (int) $payment->source_id, array( 'status' => 'paid' ) );
		}
	}
}
add_action( 'ncp_payment_paid', array( 'NCP_Links', 'on_paid' ) );
