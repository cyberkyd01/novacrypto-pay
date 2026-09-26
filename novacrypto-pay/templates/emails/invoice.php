<?php defined( 'ABSPATH' ) || exit; $link = $data['link']; ?>
<h2 style="margin-top:0;"><?php esc_html_e( 'Invoice', 'novacrypto-pay' ); ?> #<?php echo esc_html( $link->id ); ?> – <?php echo esc_html( $link->title ); ?></h2>
<?php if ( $link->customer_name ) : ?><p><?php printf( esc_html__( 'Hello %s,', 'novacrypto-pay' ), esc_html( $link->customer_name ) ); ?></p><?php endif; ?>
<p><?php esc_html_e( 'You have a new invoice payable in cryptocurrency.', 'novacrypto-pay' ); ?></p>
<?php if ( $link->description ) : ?><div style="background:#f9fafb;padding:12px 16px;border-radius:8px;"><?php echo wp_kses_post( wpautop( $link->description ) ); ?></div><?php endif; ?>
<p style="font-size:22px;font-weight:700;"><?php echo esc_html( number_format( (float) $link->amount, 2 ) . ' ' . $link->currency ); ?></p>
<?php if ( $link->expires_at ) : ?><p style="color:#6b7280;"><?php printf( esc_html__( 'Due by %s', 'novacrypto-pay' ), esc_html( get_date_from_gmt( $link->expires_at, get_option( 'date_format' ) ) ) ); ?></p><?php endif; ?>
<p><a href="<?php echo esc_url( $link->url ); ?>" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"><?php esc_html_e( 'Pay invoice', 'novacrypto-pay' ); ?></a></p>
<p style="color:#6b7280;font-size:13px;"><?php echo esc_html( $link->url ); ?></p>
