<?php defined( 'ABSPATH' ) || exit; ?>
<h2 style="margin-top:0;"><?php esc_html_e( 'Your payment was less than the amount due', 'novacrypto-pay' ); ?></h2>
<p><?php printf( esc_html__( 'We received %1$s but %2$s was due. Please send the remaining %3$s to the same address to complete your order.', 'novacrypto-pay' ), '<strong>' . esc_html( $data['received_crypto'] . ' ' . $data['symbol'] ) . '</strong>', '<strong>' . esc_html( $data['amount_crypto'] . ' ' . $data['symbol'] ) . '</strong>', '<strong>' . esc_html( $data['remaining_crypto'] . ' ' . $data['symbol'] ) . '</strong>' ); ?></p>
<?php include __DIR__ . '/_summary.php'; ?>
<p><a href="<?php echo esc_url( $data['payment_url'] ); ?>" style="display:inline-block;padding:12px 20px;background:#d97706;color:#fff;border-radius:8px;text-decoration:none;"><?php esc_html_e( 'Complete payment', 'novacrypto-pay' ); ?></a></p>
