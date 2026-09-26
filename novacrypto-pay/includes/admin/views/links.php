<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap ncp-admin">
	<h1><?php esc_html_e( 'Payment Links & Invoices', 'novacrypto-pay' ); ?>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-links&action=new&type=link' ) ); ?>"><?php esc_html_e( 'New payment link', 'novacrypto-pay' ); ?></a>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-links&action=new&type=invoice' ) ); ?>"><?php esc_html_e( 'New invoice', 'novacrypto-pay' ); ?></a>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-links&action=new&type=donation' ) ); ?>"><?php esc_html_e( 'New donation page', 'novacrypto-pay' ); ?></a>
	</h1>
	<p class="description"><?php esc_html_e( 'Payment links are reusable, shareable checkout pages. Invoices are one-time and can be emailed to a customer. Donation pages let visitors choose their own amount. All work without WooCommerce.', 'novacrypto-pay' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th>#</th><th><?php esc_html_e( 'Type', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Title', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Customer', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Status', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Uses', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'URL', 'novacrypto-pay' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $links as $l ) : ?>
			<tr>
				<td><?php echo (int) $l->id; ?></td>
				<td><?php echo esc_html( ucfirst( $l->type ) ); ?></td>
				<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncp-links&action=edit&id=' . $l->id ) ); ?>"><strong><?php echo esc_html( $l->title ); ?></strong></a></td>
				<td><?php echo $l->allow_custom_amount ? esc_html__( 'custom', 'novacrypto-pay' ) . ( $l->min_amount ? ' (min ' . esc_html( $l->min_amount ) . ')' : '' ) : esc_html( number_format( (float) $l->amount, 2 ) . ' ' . $l->currency ); ?></td>
				<td><?php echo esc_html( $l->customer_email ?: '—' ); ?></td>
				<td><span class="ncp-badge ncp-badge-<?php echo esc_attr( 'paid' === $l->status ? 'paid' : ( 'active' === $l->status ? 'pending' : 'cancelled' ) ); ?>"><?php echo esc_html( ucfirst( $l->status ) ); ?></span></td>
				<td><?php echo (int) $l->times_used; ?><?php echo $l->max_uses ? '/' . (int) $l->max_uses : ''; ?></td>
				<td><a href="<?php echo esc_url( $l->url ); ?>" target="_blank"><?php echo esc_html( $l->slug ); ?></a> <button type="button" class="button button-small ncp-copy-btn" data-copy="<?php echo esc_attr( $l->url ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button><br><code style="font-size:11px">[ncp_link slug="<?php echo esc_attr( $l->slug ); ?>"]</code></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'ncp_link_action' ); ?><input type="hidden" name="action" value="ncp_link_action" /><input type="hidden" name="id" value="<?php echo (int) $l->id; ?>" />
						<?php if ( 'invoice' === $l->type && $l->customer_email && 'paid' !== $l->status ) : ?><button name="do" value="send" class="button button-small"><?php esc_html_e( 'Email', 'novacrypto-pay' ); ?></button><?php endif; ?>
						<?php if ( 'paid' !== $l->status ) : ?><button name="do" value="toggle" class="button button-small"><?php echo 'active' === $l->status ? esc_html__( 'Disable', 'novacrypto-pay' ) : esc_html__( 'Enable', 'novacrypto-pay' ); ?></button><?php endif; ?>
						<button name="do" value="delete" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this link?', 'novacrypto-pay' ) ); ?>')"><?php esc_html_e( 'Delete', 'novacrypto-pay' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $links ) : ?><tr><td colspan="9"><?php esc_html_e( 'No links yet.', 'novacrypto-pay' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
</div>
