<?php defined( 'ABSPATH' ) || exit; $coin = $payment->coin; $active = in_array( $payment->status, array( 'pending', 'detected', 'confirming', 'underpaid', 'expired' ), true ); ?>
<div class="wrap ncp-admin">
	<h1><?php printf( esc_html__( 'Payment #%d', 'novacrypto-pay' ), (int) $payment->id ); ?> <span class="ncp-badge ncp-badge-<?php echo esc_attr( $payment->status ); ?>"><?php echo esc_html( $data['status_label'] ); ?></span> <a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-payments' ) ); ?>">← <?php esc_html_e( 'All payments', 'novacrypto-pay' ); ?></a></h1>
	<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
		<div>
			<div class="ncp-panel">
				<h2><?php esc_html_e( 'Details', 'novacrypto-pay' ); ?></h2>
				<dl class="ncp-detail-grid">
					<dt>UUID</dt><dd><code><?php echo esc_html( $payment->uuid ); ?></code></dd>
					<dt><?php esc_html_e( 'Source', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $payment->source . ' #' . $payment->source_id ); ?><?php if ( 'woocommerce' === $payment->source && function_exists( 'wc_get_order' ) && ( $o = wc_get_order( (int) $payment->source_id ) ) ) : ?> · <a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>"><?php esc_html_e( 'Open order', 'novacrypto-pay' ); ?></a><?php endif; ?></dd>
					<dt><?php esc_html_e( 'Title', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $payment->title ?: '—' ); ?></dd>
					<dt><?php esc_html_e( 'Coin / network', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $data['coin_name'] . ' · ' . $data['network'] ); ?></dd>
					<dt><?php esc_html_e( 'Address', 'novacrypto-pay' ); ?></dt><dd><code><?php echo esc_html( $payment->address ); ?></code><?php if ( null !== $payment->address_index ) : ?> <small>(HD index <?php echo (int) $payment->address_index; ?>)</small><?php endif; ?><?php if ( ! empty( $coin['address_explorer'] ) && 'lightning' !== $coin['family'] ) : ?> · <a target="_blank" href="<?php echo esc_url( sprintf( $coin['address_explorer'], $payment->address ) ); ?>"><?php esc_html_e( 'explorer', 'novacrypto-pay' ); ?></a><?php endif; ?></dd>
					<dt><?php esc_html_e( 'Amount due', 'novacrypto-pay' ); ?></dt><dd><strong><?php echo esc_html( $data['amount_crypto'] . ' ' . $data['symbol'] ); ?></strong> = <?php echo esc_html( number_format( (float) $payment->amount_fiat, 2 ) . ' ' . $payment->fiat_currency ); ?></dd>
					<dt><?php esc_html_e( 'Received', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $data['received_crypto'] . ' ' . $data['symbol'] ); ?> (<?php echo esc_html( number_format( (float) $payment->received_crypto * (float) $payment->rate, 2 ) . ' ' . $payment->fiat_currency ); ?>) · <?php echo (int) $payment->confirmations; ?>/<?php echo (int) $payment->required_confirmations; ?> conf</dd>
					<dt><?php esc_html_e( 'Rate snapshot', 'novacrypto-pay' ); ?></dt><dd>1 <?php echo esc_html( $data['symbol'] ); ?> = <?php echo esc_html( number_format( (float) $payment->rate, 6 ) . ' ' . $payment->fiat_currency ); ?> <small>(<?php echo esc_html( $payment->rate_provider ); ?>)</small></dd>
					<dt><?php esc_html_e( 'Customer', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( trim( $payment->customer_name . ' ' . $payment->customer_email ) ?: '—' ); ?></dd>
					<dt><?php esc_html_e( 'Refund address', 'novacrypto-pay' ); ?></dt><dd><code><?php echo esc_html( $payment->refund_address ?: '—' ); ?></code></dd>
					<dt><?php esc_html_e( 'Created / expires', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $payment->created_at, 'Y-m-d H:i:s' ) . ' → ' . get_date_from_gmt( $payment->expires_at, 'Y-m-d H:i:s' ) ); ?></dd>
					<dt><?php esc_html_e( 'Paid at', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $payment->paid_at ? get_date_from_gmt( $payment->paid_at, 'Y-m-d H:i:s' ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Last checked', 'novacrypto-pay' ); ?></dt><dd><?php echo esc_html( $payment->last_checked_at ? get_date_from_gmt( $payment->last_checked_at, 'Y-m-d H:i:s' ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Pay page', 'novacrypto-pay' ); ?></dt><dd><a target="_blank" href="<?php echo esc_url( $data['payment_url'] ); ?>"><?php echo esc_html( $data['payment_url'] ); ?></a></dd>
					<?php if ( ! empty( $payment->meta['customer_txid'] ) ) : ?><dt><?php esc_html_e( 'Customer-submitted txid', 'novacrypto-pay' ); ?></dt><dd><code><?php echo esc_html( $payment->meta['customer_txid'] ); ?></code></dd><?php endif; ?>
				</dl>
			</div>
			<div class="ncp-panel">
				<h2><?php esc_html_e( 'Transactions', 'novacrypto-pay' ); ?></h2>
				<table class="widefat striped"><thead><tr><th>TXID</th><th><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Conf', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Source', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Seen', 'novacrypto-pay' ); ?></th></tr></thead><tbody>
				<?php foreach ( $payment->txs as $tx ) : ?>
					<tr><td style="word-break:break-all"><?php echo ! empty( $coin['explorer'] ) && 0 !== strpos( $tx->txid, 'rpc:' ) && 0 !== strpos( $tx->txid, 'ln:' ) ? '<a target="_blank" href="' . esc_url( sprintf( $coin['explorer'], $tx->txid ) ) . '">' . esc_html( $tx->txid ) . '</a>' : esc_html( $tx->txid ); ?></td><td><?php echo esc_html( NCP_Coins::format_amount( $tx->amount ) ); ?></td><td><?php echo (int) $tx->confirmations; ?></td><td><?php echo esc_html( $tx->source ); ?></td><td><?php echo esc_html( $tx->created_at ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $payment->txs ) : ?><tr><td colspan="5"><?php esc_html_e( 'No transactions detected yet.', 'novacrypto-pay' ); ?></td></tr><?php endif; ?>
				</tbody></table>
			</div>
			<div class="ncp-panel">
				<h2><?php esc_html_e( 'Meta', 'novacrypto-pay' ); ?></h2>
				<pre style="white-space:pre-wrap;font-size:12px"><?php echo esc_html( wp_json_encode( array_diff_key( $payment->meta, array( 'sol_seen' => 1 ) ), JSON_PRETTY_PRINT ) ); ?></pre>
			</div>
		</div>
		<div>
			<div class="ncp-panel">
				<h2><?php esc_html_e( 'Actions', 'novacrypto-pay' ); ?></h2>
				<?php $act = function ( $do, $label, $class = 'button', $confirm = '' ) use ( $payment ) { ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0" <?php echo $confirm ? 'onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : ''; ?>>
						<?php wp_nonce_field( 'ncp_payment_action' ); ?><input type="hidden" name="action" value="ncp_payment_action" /><input type="hidden" name="id" value="<?php echo (int) $payment->id; ?>" /><input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>" />
						<button class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php }; ?>
				<?php if ( $active ) { $act( 'check', __( 'Check blockchain now', 'novacrypto-pay' ), 'button button-primary' ); } ?>
				<?php if ( 'underpaid' === $payment->status ) { $act( 'accept_underpaid', __( 'Accept underpayment → paid', 'novacrypto-pay' ), 'button', __( 'Mark as paid despite the shortfall?', 'novacrypto-pay' ) ); } ?>
				<?php if ( $active ) { $act( 'extend', __( 'Extend window +60 min', 'novacrypto-pay' ) ); $act( 'requote', __( 'Re-quote at current rate', 'novacrypto-pay' ), 'button', __( 'Recalculate the crypto amount at the current exchange rate?', 'novacrypto-pay' ) ); } ?>
				<?php if ( $active ) { $act( 'cancel', __( 'Cancel', 'novacrypto-pay' ), 'button', __( 'Cancel this payment?', 'novacrypto-pay' ) ); } ?>
				<?php if ( 'paid' === $payment->status ) { $act( 'refunded', __( 'Mark refunded', 'novacrypto-pay' ), 'button', __( 'Confirm you have sent the refund on-chain.', 'novacrypto-pay' ) ); } ?>
				<?php if ( $payment->customer_email ) { $act( 'resend_email', __( 'Resend customer email', 'novacrypto-pay' ) ); } ?>
				<?php if ( $active ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;border-top:1px solid #dcdcde;padding-top:12px" onsubmit="return confirm('<?php echo esc_js( __( 'Mark this payment as PAID manually? This will complete the order.', 'novacrypto-pay' ) ); ?>')">
					<?php wp_nonce_field( 'ncp_payment_action' ); ?><input type="hidden" name="action" value="ncp_payment_action" /><input type="hidden" name="id" value="<?php echo (int) $payment->id; ?>" /><input type="hidden" name="do" value="mark_paid" />
					<label><strong><?php esc_html_e( 'Manual confirmation', 'novacrypto-pay' ); ?></strong></label>
					<input type="text" name="txid" placeholder="<?php esc_attr_e( 'Transaction hash (optional)', 'novacrypto-pay' ); ?>" style="width:100%;margin:6px 0" />
					<button class="button"><?php esc_html_e( 'Mark as paid', 'novacrypto-pay' ); ?></button>
				</form>
				<?php endif; ?>
			</div>
			<div class="ncp-panel">
				<h2><?php esc_html_e( 'Admin notes', 'novacrypto-pay' ); ?></h2>
				<?php foreach ( (array) ( $payment->meta['admin_notes'] ?? array() ) as $n ) : ?>
					<p style="border-left:3px solid #dcdcde;padding-left:8px"><small><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $n['time'] ) . ' · ' . $n['user'] ); ?></small><br><?php echo esc_html( $n['note'] ); ?></p>
				<?php endforeach; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ncp_payment_action' ); ?><input type="hidden" name="action" value="ncp_payment_action" /><input type="hidden" name="id" value="<?php echo (int) $payment->id; ?>" /><input type="hidden" name="do" value="note" />
					<textarea name="note" rows="3" style="width:100%" required></textarea>
					<button class="button" style="margin-top:6px"><?php esc_html_e( 'Add note', 'novacrypto-pay' ); ?></button>
				</form>
			</div>
		</div>
	</div>
</div>
