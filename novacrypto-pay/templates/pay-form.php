<?php
/**
 * Pay button / donation form. Vars: $a (atts), $coins, $args, $sig
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;
$custom  = ! empty( $a['custom'] );
$presets = $custom ? array_filter( array_map( 'floatval', explode( ',', (string) $a['presets'] ) ) ) : array();
$uid     = 'ncp-form-' . wp_generate_password( 6, false );
?>
<div class="ncp-box ncp-pay-form <?php echo esc_attr( NCP_Frontend::theme_class() ); ?>" id="<?php echo esc_attr( $uid ); ?>">
	<form data-ncp="start-form" data-inline="<?php echo esc_attr( $a['inline'] ); ?>">
		<input type="hidden" name="sig" value="<?php echo esc_attr( $sig ); ?>" />
		<input type="hidden" name="amount" value="<?php echo esc_attr( $args['amount'] ); ?>" />
		<input type="hidden" name="currency" value="<?php echo esc_attr( $args['currency'] ); ?>" />
		<input type="hidden" name="title" value="<?php echo esc_attr( $args['title'] ); ?>" />
		<input type="hidden" name="custom" value="<?php echo esc_attr( $args['custom'] ); ?>" />
		<input type="hidden" name="ref" value="<?php echo esc_attr( $args['ref'] ); ?>" />
		<input type="hidden" name="return_url" value="<?php echo esc_url( $a['return_url'] ); ?>" />
		<?php if ( $custom ) : ?><input type="hidden" name="min" value="<?php echo esc_attr( $a['min'] ); ?>" /><?php endif; ?>

		<?php if ( $a['title'] ) : ?><h3 class="ncp-title"><?php echo esc_html( $a['title'] ); ?></h3><?php endif; ?>

		<?php if ( $custom ) : ?>
			<div class="ncp-presets">
				<?php foreach ( $presets as $p ) : ?>
					<button type="button" class="ncp-preset" data-amount="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( number_format( $p, 0 ) . ' ' . $args['currency'] ); ?></button>
				<?php endforeach; ?>
			</div>
			<label class="ncp-label"><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?> (<?php echo esc_html( $args['currency'] ); ?>)</label>
			<input type="number" name="custom_amount" step="0.01" min="<?php echo esc_attr( $a['min'] ); ?>" value="<?php echo esc_attr( $presets ? reset( $presets ) : $a['min'] ); ?>" data-ncp="custom-amount" required />
		<?php else : ?>
			<div class="ncp-fiat"><?php echo esc_html( number_format( (float) $args['amount'], 2 ) . ' ' . $args['currency'] ); ?></div>
		<?php endif; ?>

		<label class="ncp-label"><?php esc_html_e( 'Pay with', 'novacrypto-pay' ); ?></label>
		<?php echo NCP_Frontend::render_coin_picker( $coins, $custom ? ( $presets ? reset( $presets ) : $a['min'] ) : $args['amount'], $args['currency'], 'coin' ); // phpcs:ignore ?>

		<?php if ( ncp()->settings->get( 'require_email', true ) ) : ?>
			<label class="ncp-label"><?php esc_html_e( 'Email for receipt', 'novacrypto-pay' ); ?></label>
			<input type="email" name="email" required placeholder="you@example.com" value="<?php echo esc_attr( wp_get_current_user()->user_email ?? '' ); ?>" />
		<?php endif; ?>

		<button type="submit" class="ncp-btn ncp-btn-primary"><?php echo esc_html( $a['button'] ); ?></button>
		<p class="ncp-form-msg" data-ncp="form-msg"></p>
	</form>
	<div data-ncp="payment-target"></div>
</div>
