<?php defined( 'ABSPATH' ) || exit; ?>
<h2 style="margin-top:0;"><?php esc_html_e( 'We have detected your payment', 'novacrypto-pay' ); ?></h2>
<p><?php esc_html_e( 'Your transaction has been seen on the network and is waiting for confirmations. We will email you again once it is fully confirmed.', 'novacrypto-pay' ); ?></p>
<?php include __DIR__ . '/_summary.php'; ?>
<p><a href="<?php echo esc_url( $data['payment_url'] ); ?>" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"><?php esc_html_e( 'View payment status', 'novacrypto-pay' ); ?></a></p>
