<?php defined( 'ABSPATH' ) || exit; ?>
<h2 style="margin-top:0;"><?php esc_html_e( 'Payment confirmed – thank you!', 'novacrypto-pay' ); ?></h2>
<p><?php esc_html_e( 'Your cryptocurrency payment has been confirmed. Keep this email as your receipt.', 'novacrypto-pay' ); ?></p>
<?php include __DIR__ . '/_summary.php'; ?>
<?php if ( $data['return_url'] ) : ?><p><a href="<?php echo esc_url( $data['return_url'] ); ?>" style="display:inline-block;padding:12px 20px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;"><?php esc_html_e( 'Continue', 'novacrypto-pay' ); ?></a></p><?php endif; ?>
