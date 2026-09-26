<?php
/**
 * Coin picker. Vars: $coins, $amount_fiat, $fiat, $name, $selected
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;
$first = true;
?>
<div class="ncp-coin-picker" data-ncp="coin-picker" data-amount="<?php echo esc_attr( $amount_fiat ); ?>" data-currency="<?php echo esc_attr( $fiat ); ?>">
	<?php foreach ( $coins as $id => $c ) : ?>
		<?php $checked = $selected ? $selected === $id : $first; $first = false; ?>
		<label class="ncp-coin-option<?php echo $checked ? ' is-selected' : ''; ?>">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?> />
			<img src="<?php echo esc_url( $c['icon'] ); ?>" alt="" class="ncp-coin-icon" onerror="this.style.visibility='hidden'" />
			<span class="ncp-coin-name"><?php echo esc_html( $c['name'] ); ?></span>
			<span class="ncp-coin-net"><?php echo esc_html( ncp()->coins->network_label( $c ) ); ?></span>
			<span class="ncp-coin-quote" data-coin="<?php echo esc_attr( $id ); ?>">…</span>
		</label>
	<?php endforeach; ?>
</div>
