<?php
/**
 * Payment box template. Override by copying to yourtheme/novacrypto-pay/payment-box.php
 *
 * Available: $payment (object), $data (array from to_public_array), $coin (array), $opts (array)
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

$s          = ncp()->settings;
$wallet_cfg = NCP_Frontend::wallet_config( $payment );
$is_active  = in_array( $payment->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true );
$is_ln      = 'lightning' === ( $coin['family'] ?? '' );
$logo       = $s->get( 'logo_url' );
$tx_url_tpl = $coin['explorer'] ?? '';
$remaining  = (float) $data['remaining_crypto'];
$show_remaining = in_array( $payment->status, array( 'underpaid', 'detected' ), true ) && $remaining > 0 && (float) $data['received_crypto'] > 0;
?>
<div class="ncp-box <?php echo esc_attr( NCP_Frontend::theme_class() ); ?> ncp-status-<?php echo esc_attr( $payment->status ); ?>"
	id="ncp-box-<?php echo esc_attr( $payment->uuid ); ?>"
	data-uuid="<?php echo esc_attr( $payment->uuid ); ?>"
	data-status="<?php echo esc_attr( $payment->status ); ?>"
	data-expires="<?php echo esc_attr( (int) $data['expires_in'] ); ?>"
	data-uri="<?php echo esc_attr( $data['uri'] ); ?>"
	data-wallet="<?php echo esc_attr( wp_json_encode( $wallet_cfg ) ); ?>"
	data-return="<?php echo esc_url( $payment->return_url ); ?>">

	<div class="ncp-box-header">
		<?php if ( $logo ) : ?>
			<img class="ncp-logo" src="<?php echo esc_url( $logo ); ?>" alt="" />
		<?php endif; ?>
		<div class="ncp-box-heading">
			<?php if ( $opts['show_title'] ) : ?>
				<h3 class="ncp-title"><?php echo esc_html( $payment->title ?: __( 'Complete your payment', 'novacrypto-pay' ) ); ?></h3>
			<?php endif; ?>
			<div class="ncp-fiat">
				<?php echo esc_html( number_format( (float) $payment->amount_fiat, 2 ) . ' ' . $payment->fiat_currency ); ?>
				<?php if ( ! empty( $payment->meta['discount_percent'] ) ) : ?>
					<span class="ncp-discount-tag"><?php printf( esc_html__( '%s%% crypto discount applied', 'novacrypto-pay' ), esc_html( $payment->meta['discount_percent'] ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<span class="ncp-badge ncp-badge-<?php echo esc_attr( $payment->status ); ?>" data-ncp="status"><?php echo esc_html( $data['status_label'] ); ?></span>
	</div>

	<div class="ncp-coin-line">
		<img src="<?php echo esc_url( $coin['icon'] ?? '' ); ?>" alt="" class="ncp-coin-icon" onerror="this.style.display='none'" />
		<strong><?php echo esc_html( $data['coin_name'] ); ?></strong>
		<span class="ncp-network"><?php echo esc_html( $data['network'] ); ?></span>
		<?php if ( $s->is_testnet() ) : ?><span class="ncp-testnet"><?php esc_html_e( 'TESTNET', 'novacrypto-pay' ); ?></span><?php endif; ?>
	</div>

	<?php if ( $is_active ) : ?>
	<div class="ncp-body">
		<div class="ncp-qr-col">
			<div class="ncp-qr" data-ncp="qr"></div>
			<?php if ( $tx_url_tpl && ! $is_ln && ! empty( $coin['address_explorer'] ) ) : ?>
				<a class="ncp-explorer-link" target="_blank" rel="noopener" href="<?php echo esc_url( sprintf( $coin['address_explorer'], $payment->address ) ); ?>"><?php esc_html_e( 'View address on explorer', 'novacrypto-pay' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="ncp-details-col">
			<label class="ncp-label"><?php echo $show_remaining ? esc_html__( 'Remaining amount to send', 'novacrypto-pay' ) : esc_html__( 'Amount to send', 'novacrypto-pay' ); ?></label>
			<div class="ncp-copy-row">
				<code class="ncp-amount" data-ncp="amount"><?php echo esc_html( $show_remaining ? $data['remaining_crypto'] : $data['amount_crypto'] ); ?> <?php echo esc_html( $data['symbol'] ); ?></code>
				<button type="button" class="ncp-copy" data-copy="<?php echo esc_attr( $show_remaining ? $data['remaining_crypto'] : $data['amount_crypto'] ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button>
			</div>
			<p class="ncp-hint ncp-exact"><?php esc_html_e( 'Send exactly this amount. Network fees are paid by you on top.', 'novacrypto-pay' ); ?></p>

			<label class="ncp-label"><?php echo $is_ln ? esc_html__( 'Lightning invoice', 'novacrypto-pay' ) : esc_html__( 'To this address', 'novacrypto-pay' ); ?></label>
			<div class="ncp-copy-row">
				<code class="ncp-address" data-ncp="address"><?php echo esc_html( $payment->address ); ?></code>
				<button type="button" class="ncp-copy" data-copy="<?php echo esc_attr( $payment->address ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button>
			</div>

			<?php if ( $wallet_cfg ) : ?>
			<div class="ncp-wallet-buttons">
				<?php if ( 'evm' === $wallet_cfg['family'] ) : ?>
					<button type="button" class="ncp-btn ncp-btn-wallet" data-ncp="metamask"><?php esc_html_e( 'Pay with browser wallet (MetaMask etc.)', 'novacrypto-pay' ); ?></button>
				<?php endif; ?>
				<a class="ncp-btn ncp-btn-secondary" href="<?php echo esc_attr( $data['uri'] ); ?>" data-ncp="open-wallet"><?php esc_html_e( 'Open in wallet app', 'novacrypto-pay' ); ?></a>
			</div>
			<?php endif; ?>

			<div class="ncp-meta-grid">
				<div><span><?php esc_html_e( 'Rate', 'novacrypto-pay' ); ?></span><strong>1 <?php echo esc_html( $data['symbol'] ); ?> = <?php echo esc_html( number_format( (float) $payment->rate, 2 ) . ' ' . $payment->fiat_currency ); ?></strong></div>
				<div><span><?php esc_html_e( 'Time left', 'novacrypto-pay' ); ?></span><strong data-ncp="countdown">--:--</strong></div>
				<div><span><?php esc_html_e( 'Confirmations', 'novacrypto-pay' ); ?></span><strong><span data-ncp="conf"><?php echo (int) $data['confirmations']; ?></span> / <?php echo (int) $data['required_confirmations']; ?></strong></div>
				<?php $fee = $s->get( 'show_fee_estimate' ) ? ncp()->rates->fee_estimate( $payment->coin_id ) : null; ?>
				<?php if ( null !== $fee ) : ?>
				<div><span><?php esc_html_e( 'Est. network fee', 'novacrypto-pay' ); ?></span><strong>≈ <?php echo esc_html( NCP_Coins::format_amount( $fee, 6 ) . ' ' . ( $wallet_cfg['symbol'] ?? $data['symbol'] ) ); ?></strong></div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="ncp-progress" data-ncp="progress">
		<div class="ncp-progress-bar"></div>
		<span class="ncp-progress-text" data-ncp="progress-text"><?php esc_html_e( 'Waiting for payment… this page updates automatically.', 'novacrypto-pay' ); ?></span>
	</div>

	<div class="ncp-txs" data-ncp="txs">
		<?php foreach ( $data['txs'] as $tx ) : ?>
			<div class="ncp-tx"><?php echo $tx['url'] ? '<a target="_blank" rel="noopener" href="' . esc_url( $tx['url'] ) . '">' . esc_html( substr( $tx['txid'], 0, 18 ) ) . '…</a>' : esc_html( substr( $tx['txid'], 0, 18 ) ); ?> · <?php echo esc_html( $tx['amount'] . ' ' . $data['symbol'] ); ?> · <?php printf( esc_html__( '%d conf', 'novacrypto-pay' ), (int) $tx['confirmations'] ); ?></div>
		<?php endforeach; ?>
	</div>

	<?php if ( $s->get( 'allow_txid_submit', true ) && ! $is_ln ) : ?>
	<details class="ncp-extra">
		<summary><?php esc_html_e( 'Already paid? Submit your transaction hash', 'novacrypto-pay' ); ?></summary>
		<form class="ncp-inline-form" data-ncp="txid-form">
			<input type="text" name="txid" placeholder="<?php esc_attr_e( 'Transaction hash / ID', 'novacrypto-pay' ); ?>" required />
			<button type="submit" class="ncp-btn"><?php esc_html_e( 'Submit', 'novacrypto-pay' ); ?></button>
		</form>
		<p class="ncp-form-msg" data-ncp="txid-msg"></p>
	</details>
	<?php endif; ?>

	<?php if ( $s->get( 'collect_refund_address', true ) && ! $is_ln ) : ?>
	<details class="ncp-extra">
		<summary><?php esc_html_e( 'Refund address (optional)', 'novacrypto-pay' ); ?></summary>
		<p class="ncp-hint"><?php esc_html_e( 'If a refund is ever needed, we will send it to this address.', 'novacrypto-pay' ); ?></p>
		<form class="ncp-inline-form" data-ncp="refund-form">
			<input type="text" name="address" value="<?php echo esc_attr( $payment->refund_address ); ?>" placeholder="<?php echo esc_attr( $data['symbol'] ); ?> <?php esc_attr_e( 'address', 'novacrypto-pay' ); ?>" required />
			<button type="submit" class="ncp-btn ncp-btn-secondary"><?php esc_html_e( 'Save', 'novacrypto-pay' ); ?></button>
		</form>
		<p class="ncp-form-msg" data-ncp="refund-msg"></p>
	</details>
	<?php endif; ?>

	<?php else : ?>
	<div class="ncp-final" data-ncp="final">
		<?php if ( 'paid' === $payment->status ) : ?>
			<div class="ncp-final-icon ncp-final-ok">✓</div>
			<h4><?php esc_html_e( 'Payment received. Thank you!', 'novacrypto-pay' ); ?></h4>
			<p><?php echo esc_html( $data['received_crypto'] . ' ' . $data['symbol'] ); ?> · <?php echo esc_html( $payment->paid_at ? get_date_from_gmt( $payment->paid_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?></p>
			<?php foreach ( $data['txs'] as $tx ) : ?>
				<div class="ncp-tx"><?php echo $tx['url'] ? '<a target="_blank" rel="noopener" href="' . esc_url( $tx['url'] ) . '">' . esc_html( substr( $tx['txid'], 0, 24 ) ) . '…</a>' : esc_html( substr( $tx['txid'], 0, 24 ) ); ?></div>
			<?php endforeach; ?>
			<?php if ( $payment->return_url ) : ?><a class="ncp-btn" href="<?php echo esc_url( $payment->return_url ); ?>"><?php esc_html_e( 'Continue', 'novacrypto-pay' ); ?></a><?php endif; ?>
		<?php elseif ( 'expired' === $payment->status ) : ?>
			<div class="ncp-final-icon ncp-final-warn">⏱</div>
			<h4><?php esc_html_e( 'This payment request has expired.', 'novacrypto-pay' ); ?></h4>
			<p><?php esc_html_e( 'Exchange rates change quickly, so requests are only valid for a limited time. If you already sent funds they will still be detected. Otherwise please start a new payment.', 'novacrypto-pay' ); ?></p>
			<?php if ( $payment->return_url ) : ?><a class="ncp-btn ncp-btn-secondary" href="<?php echo esc_url( $payment->return_url ); ?>"><?php esc_html_e( 'Go back', 'novacrypto-pay' ); ?></a><?php endif; ?>
		<?php else : ?>
			<div class="ncp-final-icon ncp-final-warn">!</div>
			<h4><?php echo esc_html( $data['status_label'] ); ?></h4>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $opts['full'] ) : ?>
	<p class="ncp-powered"><?php esc_html_e( 'Funds are sent directly to the merchant wallet. No intermediary.', 'novacrypto-pay' ); ?> · <?php echo esc_html( substr( $payment->uuid, 0, 8 ) ); ?></p>
	<?php endif; ?>
</div>
