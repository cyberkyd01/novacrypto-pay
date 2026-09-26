<?php defined( 'ABSPATH' ) || exit; ?>
<h2 style="margin-top:0;"><?php esc_html_e( 'Your payment window has expired', 'novacrypto-pay' ); ?></h2>
<p><?php esc_html_e( 'We did not detect a payment within the allowed time. If you already sent funds, they will still be matched automatically. Otherwise you can start a new payment from your order.', 'novacrypto-pay' ); ?></p>
<?php include __DIR__ . '/_summary.php'; ?>
