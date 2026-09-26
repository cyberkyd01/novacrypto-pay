<?php
/** Shared payment summary table. Vars: $data */
defined( 'ABSPATH' ) || exit;
$rows = array(
	__( 'Amount', 'novacrypto-pay' )   => $data['amount_crypto'] . ' ' . $data['symbol'] . ' (' . number_format( $data['amount_fiat'], 2 ) . ' ' . $data['fiat_currency'] . ')',
	__( 'Received', 'novacrypto-pay' ) => $data['received_crypto'] . ' ' . $data['symbol'],
	__( 'Network', 'novacrypto-pay' )  => $data['network'],
	__( 'Address', 'novacrypto-pay' )  => $data['address'],
	__( 'Status', 'novacrypto-pay' )   => $data['status_label'],
	__( 'Reference', 'novacrypto-pay' ) => $data['title'] ?: $data['uuid'],
);
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;font-size:14px;">
<?php foreach ( $rows as $k => $v ) : ?>
<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;width:35%;"><?php echo esc_html( $k ); ?></td><td style="padding:8px 0;border-bottom:1px solid #e5e7eb;word-break:break-all;"><?php echo esc_html( $v ); ?></td></tr>
<?php endforeach; ?>
<?php foreach ( $data['txs'] as $tx ) : ?>
<tr><td style="padding:8px 0;color:#6b7280;">TX</td><td style="padding:8px 0;word-break:break-all;"><?php echo $tx['url'] ? '<a href="' . esc_url( $tx['url'] ) . '">' . esc_html( $tx['txid'] ) . '</a>' : esc_html( $tx['txid'] ); ?></td></tr>
<?php endforeach; ?>
</table>
