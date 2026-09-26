<?php
/**
 * Static wallets box ([ncp_wallets]). Vars: $a, $coins
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ncp-box ncp-wallets <?php echo esc_attr( NCP_Frontend::theme_class() ); ?>">
	<?php if ( $a['title'] ) : ?><h3 class="ncp-title"><?php echo esc_html( $a['title'] ); ?></h3><?php endif; ?>
	<div class="ncp-wallet-grid">
	<?php foreach ( $coins as $id => $c ) : ?>
		<?php
		$w    = ncp()->wallets->get( $id );
		$addr = $w['addresses'][0] ?? '';
		if ( ! $addr || 'lightning' === $c['family'] ) {
			continue;
		}
		?>
		<div class="ncp-wallet-item">
			<div class="ncp-coin-line"><img src="<?php echo esc_url( $c['icon'] ); ?>" alt="" class="ncp-coin-icon" onerror="this.style.display='none'" /><strong><?php echo esc_html( $c['name'] ); ?></strong><span class="ncp-network"><?php echo esc_html( ncp()->coins->network_label( $c ) ); ?></span></div>
			<?php if ( '1' === (string) $a['show_qr'] ) : ?><div class="ncp-qr ncp-qr-small" data-qr="<?php echo esc_attr( $addr ); ?>"></div><?php endif; ?>
			<div class="ncp-copy-row"><code class="ncp-address"><?php echo esc_html( $addr ); ?></code><button type="button" class="ncp-copy" data-copy="<?php echo esc_attr( $addr ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button></div>
		</div>
	<?php endforeach; ?>
	</div>
</div>
