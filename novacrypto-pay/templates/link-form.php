<?php
/**
 * Payment link / invoice / donation page form. Vars: $link, $ok, $coins, $opts
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;
$existing = 'invoice' === $link->type ? ncp()->payments->get_by_source( 'invoice', $link->id ) : null;
?>
<div class="ncp-box ncp-link-page <?php echo esc_attr( NCP_Frontend::theme_class() ); ?>">
	<?php if ( ncp()->settings->get( 'logo_url' ) ) : ?><img class="ncp-logo" src="<?php echo esc_url( ncp()->settings->get( 'logo_url' ) ); ?>" alt="" /><?php endif; ?>
	<h2 class="ncp-title"><?php echo esc_html( $link->title ); ?></h2>
	<?php if ( 'invoice' === $link->type ) : ?>
		<p class="ncp-hint"><?php printf( esc_html__( 'Invoice #%1$s for %2$s', 'novacrypto-pay' ), esc_html( $link->id ), esc_html( $link->customer_name ?: $link->customer_email ) ); ?></p>
	<?php endif; ?>
	<?php if ( $link->description ) : ?><div class="ncp-description"><?php echo wp_kses_post( wpautop( $link->description ) ); ?></div><?php endif; ?>

	<?php if ( 'paid' === $link->status || ( $existing && 'paid' === $existing->status ) ) : ?>
		<div class="ncp-final"><div class="ncp-final-icon ncp-final-ok">✓</div><h4><?php esc_html_e( 'This invoice has been paid.', 'novacrypto-pay' ); ?></h4></div>
	<?php elseif ( is_wp_error( $ok ) ) : ?>
		<div class="ncp-notice ncp-notice-error"><?php echo esc_html( $ok->get_error_message() ); ?></div>
	<?php elseif ( ! $coins ) : ?>
		<div class="ncp-notice ncp-notice-error"><?php esc_html_e( 'No payment methods available.', 'novacrypto-pay' ); ?></div>
	<?php elseif ( $existing && in_array( $existing->status, array( 'pending', 'detected', 'confirming', 'underpaid' ), true ) && ! ncp()->payments->is_expired( $existing ) ) : ?>
		<?php echo NCP_Frontend::render_payment_box( $existing ); // phpcs:ignore ?>
	<?php else : ?>
		<form data-ncp="start-form" data-inline="1">
			<input type="hidden" name="link" value="<?php echo esc_attr( $link->slug ); ?>" />
			<?php if ( $link->allow_custom_amount ) : ?>
				<label class="ncp-label"><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?> (<?php echo esc_html( $link->currency ); ?>)</label>
				<input type="number" name="amount" step="0.01" min="<?php echo esc_attr( $link->min_amount ?: 0.01 ); ?>" value="<?php echo esc_attr( $link->amount ?: $link->min_amount ?: '' ); ?>" data-ncp="custom-amount" required />
			<?php else : ?>
				<input type="hidden" name="amount" value="<?php echo esc_attr( $link->amount ); ?>" />
				<div class="ncp-fiat"><?php echo esc_html( number_format( (float) $link->amount, 2 ) . ' ' . $link->currency ); ?></div>
			<?php endif; ?>
			<label class="ncp-label"><?php esc_html_e( 'Pay with', 'novacrypto-pay' ); ?></label>
			<?php echo NCP_Frontend::render_coin_picker( $coins, $link->amount ?: $link->min_amount ?: 0, $link->currency, 'coin' ); // phpcs:ignore ?>
			<?php if ( ncp()->settings->get( 'require_email', true ) || $link->customer_email ) : ?>
				<label class="ncp-label"><?php esc_html_e( 'Email for receipt', 'novacrypto-pay' ); ?></label>
				<input type="email" name="email" required value="<?php echo esc_attr( $link->customer_email ); ?>" placeholder="you@example.com" />
			<?php endif; ?>
			<button type="submit" class="ncp-btn ncp-btn-primary"><?php echo 'donation' === $link->type ? esc_html__( 'Donate', 'novacrypto-pay' ) : esc_html__( 'Continue to payment', 'novacrypto-pay' ); ?></button>
			<p class="ncp-form-msg" data-ncp="form-msg"></p>
		</form>
		<div data-ncp="payment-target"></div>
	<?php endif; ?>
</div>
