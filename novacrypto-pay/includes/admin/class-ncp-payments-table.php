<?php
/**
 * Payments list table.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NCP_Payments_Table extends WP_List_Table {

	public $total = 0;

	public function __construct() {
		parent::__construct( array( 'singular' => 'payment', 'plural' => 'payments', 'ajax' => false ) );
	}

	public function get_columns() {
		return array(
			'id'       => __( 'ID', 'novacrypto-pay' ),
			'created'  => __( 'Date', 'novacrypto-pay' ),
			'status'   => __( 'Status', 'novacrypto-pay' ),
			'source'   => __( 'Source', 'novacrypto-pay' ),
			'coin'     => __( 'Coin', 'novacrypto-pay' ),
			'amount'   => __( 'Amount', 'novacrypto-pay' ),
			'fiat'     => __( 'Fiat', 'novacrypto-pay' ),
			'customer' => __( 'Customer', 'novacrypto-pay' ),
			'address'  => __( 'Address', 'novacrypto-pay' ),
		);
	}

	public function get_sortable_columns() {
		return array( 'id' => array( 'id', true ), 'created' => array( 'created_at', false ), 'fiat' => array( 'amount_fiat', false ), 'status' => array( 'status', false ) );
	}

	protected function get_views() {
		global $wpdb;
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$wpdb->prefix}ncp_payments GROUP BY status", OBJECT_K ); // phpcs:ignore
		$cur    = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore
		$views  = array( '' => __( 'All', 'novacrypto-pay' ) );
		foreach ( NCP_Payments::STATUSES as $s ) {
			$views[ $s ] = NCP_Payments::status_label( $s );
		}
		$out = array();
		foreach ( $views as $k => $label ) {
			$n = $k ? (int) ( $counts[ $k ]->c ?? 0 ) : array_sum( wp_list_pluck( $counts, 'c' ) );
			$out[ $k ?: 'all' ] = '<a href="' . esc_url( add_query_arg( array( 'page' => 'ncp-payments', 'status' => $k ), admin_url( 'admin.php' ) ) ) . '"' . ( $cur === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . $n . ')</span></a>';
		}
		return $out;
	}

	public function prepare_items() {
		$per  = 25;
		$page = $this->get_pagenum();
		$res  = ncp()->payments->query(
			array(
				'status'   => sanitize_key( $_GET['status'] ?? '' ), // phpcs:ignore
				'coin_id'  => sanitize_key( $_GET['coin'] ?? '' ), // phpcs:ignore
				'source'   => sanitize_key( $_GET['source'] ?? '' ), // phpcs:ignore
				'search'   => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), // phpcs:ignore
				'per_page' => $per,
				'page'     => $page,
				'orderby'  => sanitize_key( $_GET['orderby'] ?? 'id' ), // phpcs:ignore
				'order'    => sanitize_key( $_GET['order'] ?? 'desc' ), // phpcs:ignore
			)
		);
		$this->items = $res['items'];
		$this->total = $res['total'];
		$this->set_pagination_args( array( 'total_items' => $res['total'], 'per_page' => $per ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		echo '<select name="coin"><option value="">' . esc_html__( 'All coins', 'novacrypto-pay' ) . '</option>';
		foreach ( ncp()->coins->all() as $id => $c ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( sanitize_key( $_GET['coin'] ?? '' ), $id, false ) . '>' . esc_html( $c['name'] ) . '</option>'; // phpcs:ignore
		}
		echo '</select> <select name="source"><option value="">' . esc_html__( 'All sources', 'novacrypto-pay' ) . '</option>';
		foreach ( array( 'woocommerce', 'edd', 'link', 'invoice', 'donation', 'shortcode', 'api' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( sanitize_key( $_GET['source'] ?? '' ), $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; // phpcs:ignore
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'novacrypto-pay' ), '', 'filter_action', false );
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ncp_export&what=payments&status=' . sanitize_key( $_GET['status'] ?? '' ) ), 'ncp_export' ) ) . '">' . esc_html__( 'Export CSV', 'novacrypto-pay' ) . '</a>'; // phpcs:ignore
		echo '</div>';
	}

	public function column_default( $item, $col ) {
		$url = admin_url( 'admin.php?page=ncp-payments&action=view&id=' . $item->id );
		switch ( $col ) {
			case 'id':
				return '<a href="' . esc_url( $url ) . '"><strong>#' . (int) $item->id . '</strong></a><br><small>' . esc_html( substr( $item->uuid, 0, 8 ) ) . '</small>';
			case 'created':
				return esc_html( get_date_from_gmt( $item->created_at, 'Y-m-d H:i' ) );
			case 'status':
				return '<span class="ncp-badge ncp-badge-' . esc_attr( $item->status ) . '">' . esc_html( NCP_Payments::status_label( $item->status ) ) . '</span>' . ( ! empty( $item->meta['late_payment'] ) ? ' <small>' . esc_html__( '(late)', 'novacrypto-pay' ) . '</small>' : '' );
			case 'source':
				$label = esc_html( ucfirst( $item->source ) . ( $item->source_id ? ' #' . $item->source_id : '' ) );
				if ( 'woocommerce' === $item->source && function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( (int) $item->source_id );
					return $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">' . $label . '</a>' : $label;
				}
				return $label;
			case 'coin':
				return esc_html( $item->coin['name'] ?? $item->coin_id );
			case 'amount':
				return esc_html( NCP_Coins::format_amount( $item->amount_crypto ) . ' ' . ( $item->coin['symbol'] ?? '' ) ) . ( (float) $item->received_crypto > 0 ? '<br><small>' . esc_html__( 'recv', 'novacrypto-pay' ) . ' ' . esc_html( NCP_Coins::format_amount( $item->received_crypto ) ) . '</small>' : '' );
			case 'fiat':
				return esc_html( number_format( (float) $item->amount_fiat, 2 ) . ' ' . $item->fiat_currency );
			case 'customer':
				return esc_html( $item->customer_email ?: '—' );
			case 'address':
				return '<code style="font-size:11px">' . esc_html( strlen( $item->address ) > 24 ? substr( $item->address, 0, 12 ) . '…' . substr( $item->address, -8 ) : $item->address ) . '</code>';
		}
		return '';
	}

	public function no_items() {
		esc_html_e( 'No payments yet.', 'novacrypto-pay' );
	}
}
