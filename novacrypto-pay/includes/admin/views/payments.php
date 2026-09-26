<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap ncp-admin">
	<h1><?php esc_html_e( 'Payments', 'novacrypto-pay' ); ?></h1>
	<form method="get">
		<input type="hidden" name="page" value="ncp-payments" />
		<?php if ( ! empty( $_GET['status'] ) ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( $_GET['status'] ) ); ?>" /><?php endif; // phpcs:ignore ?>
		<?php $table->views(); $table->search_box( __( 'Search uuid / address / email / order', 'novacrypto-pay' ), 'ncp' ); $table->display(); ?>
	</form>
</div>
