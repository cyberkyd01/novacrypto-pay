<?php defined( 'ABSPATH' ) || exit; $max = max( 1, max( array_map( function ( $r ) { return (float) $r->fiat; }, $stats['by_day'] ) ?: array( 1 ) ) ); ?>
<div class="wrap ncp-admin">
	<h1><?php esc_html_e( 'NovaCrypto Pay', 'novacrypto-pay' ); ?> <span class="ncp-version">v<?php echo esc_html( NCP_VERSION ); ?></span></h1>
	<div class="ncp-cards">
		<div class="ncp-card"><div class="ncp-card-label"><?php esc_html_e( 'Received (30d)', 'novacrypto-pay' ); ?></div><div class="ncp-card-value"><?php echo esc_html( number_format( $stats['paid_fiat'], 2 ) ); ?> <small><?php echo esc_html( $fiat ); ?></small></div><div class="ncp-card-sub"><?php echo (int) $stats['paid_count']; ?> <?php esc_html_e( 'paid payments', 'novacrypto-pay' ); ?></div></div>
		<div class="ncp-card"><div class="ncp-card-label"><?php esc_html_e( 'Pending now', 'novacrypto-pay' ); ?></div><div class="ncp-card-value"><?php echo (int) $stats['pending']; ?></div><div class="ncp-card-sub"><?php esc_html_e( 'awaiting funds / confirmations', 'novacrypto-pay' ); ?></div></div>
		<div class="ncp-card"><div class="ncp-card-label"><?php esc_html_e( 'Underpaid', 'novacrypto-pay' ); ?></div><div class="ncp-card-value"><?php echo (int) $stats['underpaid']; ?></div><div class="ncp-card-sub"><?php esc_html_e( 'need review', 'novacrypto-pay' ); ?></div></div>
		<div class="ncp-card"><div class="ncp-card-label"><?php esc_html_e( 'Conversion (30d)', 'novacrypto-pay' ); ?></div><div class="ncp-card-value"><?php echo $stats['conversion'] ? esc_html( round( 100 * $stats['paid_count'] / $stats['conversion'] ) ) : 0; ?>%</div><div class="ncp-card-sub"><?php echo (int) $stats['expired']; ?> <?php esc_html_e( 'expired', 'novacrypto-pay' ); ?></div></div>
	</div>
	<div class="ncp-panel">
		<h2><?php esc_html_e( 'Daily volume (paid, last 30 days)', 'novacrypto-pay' ); ?></h2>
		<?php if ( $stats['by_day'] ) : ?>
		<div class="ncp-chart">
			<?php foreach ( $stats['by_day'] as $d ) : ?>
				<div class="ncp-bar" style="height:<?php echo esc_attr( max( 2, round( 100 * (float) $d->fiat / $max ) ) ); ?>%"><span><?php echo esc_html( $d->d . ': ' . number_format( (float) $d->fiat, 2 ) . ' ' . $fiat . ' (' . (int) $d->c . ')' ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php else : ?><p><?php esc_html_e( 'No paid payments in the last 30 days.', 'novacrypto-pay' ); ?></p><?php endif; ?>
	</div>
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'By coin (30d)', 'novacrypto-pay' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Coin', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Payments', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Crypto', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Fiat', 'novacrypto-pay' ); ?></th></tr></thead><tbody>
			<?php foreach ( $stats['by_coin'] as $r ) : $c = ncp()->coins->get( $r->coin_id ); ?>
				<tr><td><?php echo esc_html( $c['name'] ?? $r->coin_id ); ?></td><td><?php echo (int) $r->c; ?></td><td><?php echo esc_html( NCP_Coins::format_amount( $r->crypto ) . ' ' . ( $c['symbol'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format( (float) $r->fiat, 2 ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $stats['by_coin'] ) : ?><tr><td colspan="4"><?php esc_html_e( 'Nothing yet.', 'novacrypto-pay' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'Quick start', 'novacrypto-pay' ); ?></h2>
			<ol class="ncp-setup-steps">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-settings&tab=coins' ) ); ?>"><?php esc_html_e( 'Enable coins and paste wallet addresses or an xpub/zpub', 'novacrypto-pay' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-settings&tab=detection' ) ); ?>"><?php esc_html_e( 'Add a free Etherscan API key for EVM detection (optional)', 'novacrypto-pay' ); ?></a></li>
				<li><?php echo class_exists( 'WooCommerce' ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=novacrypto' ) ) . '">' . esc_html__( 'Enable the WooCommerce gateway', 'novacrypto-pay' ) . '</a>' : esc_html__( 'No shop? Use a payment link, invoice or the [ncp_pay] shortcode / block.', 'novacrypto-pay' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-links&action=new' ) ); ?>"><?php esc_html_e( 'Create a payment link or invoice', 'novacrypto-pay' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-settings&tab=notifications' ) ); ?>"><?php esc_html_e( 'Connect Telegram / Discord / Slack / webhooks', 'novacrypto-pay' ); ?></a></li>
			</ol>
			<p><strong><?php esc_html_e( 'Shortcodes:', 'novacrypto-pay' ); ?></strong> <code>[ncp_pay amount="25"]</code> <code>[ncp_donate]</code> <code>[ncp_wallets]</code> <code>[ncp_price amount="100" coin="btc"]</code> <code>[ncp_link slug="…"]</code></p>
		</div>
	</div>
	<div class="ncp-panel">
		<h2><?php esc_html_e( 'Recent payments', 'novacrypto-pay' ); ?> <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-payments' ) ); ?>"><?php esc_html_e( 'View all', 'novacrypto-pay' ); ?></a></h2>
		<table class="widefat striped"><thead><tr><th>#</th><th><?php esc_html_e( 'Date', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Status', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Source', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Fiat', 'novacrypto-pay' ); ?></th></tr></thead><tbody>
		<?php foreach ( $recent['items'] as $p ) : ?>
			<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-payments&action=view&id=' . $p->id ) ); ?>">#<?php echo (int) $p->id; ?></a></td><td><?php echo esc_html( get_date_from_gmt( $p->created_at, 'Y-m-d H:i' ) ); ?></td><td><span class="ncp-badge ncp-badge-<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( NCP_Payments::status_label( $p->status ) ); ?></span></td><td><?php echo esc_html( $p->source . ' #' . $p->source_id ); ?></td><td><?php echo esc_html( NCP_Coins::format_amount( $p->amount_crypto ) . ' ' . ( $p->coin['symbol'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format( (float) $p->amount_fiat, 2 ) . ' ' . $p->fiat_currency ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $recent['items'] ) : ?><tr><td colspan="6"><?php esc_html_e( 'No payments yet.', 'novacrypto-pay' ); ?></td></tr><?php endif; ?>
		</tbody></table>
	</div>
</div>
