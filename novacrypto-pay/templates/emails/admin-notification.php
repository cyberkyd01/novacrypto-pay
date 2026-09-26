<?php defined( 'ABSPATH' ) || exit; $type = $extra['type'] ?? 'paid'; ?>
<h2 style="margin-top:0;"><?php echo esc_html( NCP_Payments::status_label( $type ) ); ?>: <?php echo esc_html( $data['amount_crypto'] . ' ' . $data['symbol'] ); ?></h2>
<p><?php printf( esc_html__( 'Source: %1$s #%2$s · Customer: %3$s', 'novacrypto-pay' ), esc_html( $payment->source ), esc_html( $payment->source_id ), esc_html( $payment->customer_email ?: '—' ) ); ?></p>
<?php include __DIR__ . '/_summary.php'; ?>
<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-payments&action=view&id=' . $payment->id ) ); ?>" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"><?php esc_html_e( 'Open in dashboard', 'novacrypto-pay' ); ?></a></p>
