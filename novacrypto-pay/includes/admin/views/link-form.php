<?php defined( 'ABSPATH' ) || exit; $v = function ( $k, $d = '' ) use ( $link ) { return $link ? $link->$k : $d; }; $coins = ncp()->coins->enabled(); ?>
<div class="wrap ncp-admin">
	<h1><?php echo $link ? esc_html__( 'Edit', 'novacrypto-pay' ) : esc_html__( 'New', 'novacrypto-pay' ); ?> <?php echo esc_html( array( 'link' => __( 'payment link', 'novacrypto-pay' ), 'invoice' => __( 'invoice', 'novacrypto-pay' ), 'donation' => __( 'donation page', 'novacrypto-pay' ) )[ $type ] ?? $type ); ?></h1>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ncp-panel" style="max-width:820px">
		<?php wp_nonce_field( 'ncp_link_save' ); ?>
		<input type="hidden" name="action" value="ncp_link_save" />
		<input type="hidden" name="id" value="<?php echo (int) $v( 'id', 0 ); ?>" />
		<input type="hidden" name="type" id="ncp_link_type" value="<?php echo esc_attr( $type ); ?>" />
		<table class="form-table">
			<tr><th><label><?php esc_html_e( 'Title', 'novacrypto-pay' ); ?></label></th><td><input type="text" name="title" class="regular-text" required value="<?php echo esc_attr( $v( 'title' ) ); ?>" /></td></tr>
			<tr><th><label><?php esc_html_e( 'Description', 'novacrypto-pay' ); ?></label></th><td><textarea name="description" rows="4" class="large-text"><?php echo esc_textarea( $v( 'description' ) ); ?></textarea></td></tr>
			<tr><th><label><?php esc_html_e( 'Amount', 'novacrypto-pay' ); ?></label></th><td>
				<input type="number" step="0.01" name="amount" value="<?php echo esc_attr( $v( 'amount' ) ); ?>" <?php echo 'invoice' === $type ? 'required' : ''; ?> />
				<select name="currency"><?php foreach ( NCP_Rates::fiat_currencies() as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $v( 'currency', ncp()->settings->store_currency() ), $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?></select>
				<?php if ( 'invoice' !== $type ) : ?>
					<p><label><input type="checkbox" name="allow_custom_amount" value="1" <?php checked( 'donation' === $type ? (bool) $v( 'allow_custom_amount', 1 ) : (bool) $v( 'allow_custom_amount' ) ); ?> /> <?php esc_html_e( 'Let the payer choose the amount (the amount above becomes the default)', 'novacrypto-pay' ); ?></label></p>
					<p><label><?php esc_html_e( 'Minimum amount', 'novacrypto-pay' ); ?> <input type="number" step="0.01" name="min_amount" value="<?php echo esc_attr( $v( 'min_amount' ) ); ?>" /></label></p>
				<?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Accepted coins', 'novacrypto-pay' ); ?></th><td>
				<?php foreach ( $coins as $id => $c ) : ?><label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="coins[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $v( 'coins', array() ), true ) ); ?> /> <?php echo esc_html( $c['name'] ); ?></label><?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Leave all unchecked to accept every enabled coin.', 'novacrypto-pay' ); ?></p>
			</td></tr>
			<tr><th><label><?php esc_html_e( 'Customer email', 'novacrypto-pay' ); ?></label></th><td><input type="email" name="customer_email" class="regular-text" value="<?php echo esc_attr( $v( 'customer_email' ) ); ?>" <?php echo 'invoice' === $type ? 'required' : ''; ?> /> <input type="text" name="customer_name" placeholder="<?php esc_attr_e( 'Customer name', 'novacrypto-pay' ); ?>" value="<?php echo esc_attr( $v( 'customer_name' ) ); ?>" /></td></tr>
			<?php if ( 'invoice' !== $type ) : ?><tr><th><label><?php esc_html_e( 'Max uses', 'novacrypto-pay' ); ?></label></th><td><input type="number" name="max_uses" value="<?php echo esc_attr( $v( 'max_uses' ) ); ?>" /> <span class="description"><?php esc_html_e( 'Blank = unlimited', 'novacrypto-pay' ); ?></span></td></tr><?php endif; ?>
			<tr><th><label><?php echo 'invoice' === $type ? esc_html__( 'Due date', 'novacrypto-pay' ) : esc_html__( 'Expires', 'novacrypto-pay' ); ?></label></th><td><input type="datetime-local" name="expires_at" value="<?php echo esc_attr( $v( 'expires_at' ) ? gmdate( 'Y-m-d\TH:i', strtotime( $v( 'expires_at' ) . ' UTC' ) ) : '' ); ?>" /> <span class="description">UTC</span></td></tr>
			<tr><th><label><?php esc_html_e( 'Success URL', 'novacrypto-pay' ); ?></label></th><td><input type="url" name="success_url" class="regular-text" value="<?php echo esc_attr( $v( 'success_url' ) ); ?>" /> <span class="description"><?php esc_html_e( 'Where to send the payer after a confirmed payment (optional).', 'novacrypto-pay' ); ?></span></td></tr>
		</table>
		<p class="submit">
			<button class="button button-primary"><?php esc_html_e( 'Save', 'novacrypto-pay' ); ?></button>
			<?php if ( 'invoice' === $type ) : ?><button class="button" name="send_now" value="1"><?php esc_html_e( 'Save & email invoice', 'novacrypto-pay' ); ?></button><?php endif; ?>
			<?php if ( $link ) : ?>&nbsp; <a target="_blank" href="<?php echo esc_url( $link->url ); ?>"><?php echo esc_html( $link->url ); ?></a><?php endif; ?>
		</p>
	</form>
</div>
