<?php defined( 'ABSPATH' ) || exit; $api_key = get_option( 'ncp_api_key' ); $secret = get_option( 'ncp_webhook_secret' ); ?>
<div class="wrap ncp-admin">
	<h1><?php esc_html_e( 'Tools & Logs', 'novacrypto-pay' ); ?></h1>
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'Diagnostics', 'novacrypto-pay' ); ?></h2>
			<p><button class="button" data-ncp-task="system" data-out="ncp-out-system"><?php esc_html_e( 'System check', 'novacrypto-pay' ); ?></button> <button class="button" data-ncp-task="test_rates" data-out="ncp-out-system"><?php esc_html_e( 'Test exchange rates', 'novacrypto-pay' ); ?></button> <button class="button" data-ncp-task="test_notifications" data-out="ncp-out-system"><?php esc_html_e( 'Send test notifications', 'novacrypto-pay' ); ?></button> <button class="button" data-ncp-task="test_lightning" data-out="ncp-out-system"><?php esc_html_e( 'Test Lightning', 'novacrypto-pay' ); ?></button></p>
			<p><?php esc_html_e( 'Test blockchain detection for:', 'novacrypto-pay' ); ?> <?php foreach ( ncp()->coins->enabled() as $id => $c ) : ?><button class="button button-small" data-ncp-task="test_explorer" data-coin="<?php echo esc_attr( $id ); ?>" data-out="ncp-out-system"><?php echo esc_html( $c['symbol'] . ' ' . ncp()->coins->network_label( $c ) ); ?></button> <?php endforeach; ?></p>
			<div id="ncp-out-system" style="font-family:Menlo,monospace;font-size:12px;background:#f6f7f7;padding:8px;min-height:40px;white-space:pre-wrap"></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px"><?php wp_nonce_field( 'ncp_tools' ); ?><input type="hidden" name="action" value="ncp_tools" />
				<button class="button" name="do" value="run_cron"><?php esc_html_e( 'Run payment check now', 'novacrypto-pay' ); ?></button>
				<button class="button" name="do" value="clear_rate_cache"><?php esc_html_e( 'Clear rate cache', 'novacrypto-pay' ); ?></button>
			</form>
			<p class="description" style="margin-top:8px"><?php esc_html_e( 'Tip: for reliable background checks, add a real cron job hitting wp-cron.php every minute and set DISABLE_WP_CRON.', 'novacrypto-pay' ); ?></p>
		</div>
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'REST API & Webhooks', 'novacrypto-pay' ); ?></h2>
			<p><strong><?php esc_html_e( 'API key', 'novacrypto-pay' ); ?></strong><br><code class="ncp-inline-code"><?php echo esc_html( $api_key ); ?></code> <button type="button" class="button button-small ncp-copy-btn" data-copy="<?php echo esc_attr( $api_key ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button></p>
			<p><strong><?php esc_html_e( 'Webhook signing secret', 'novacrypto-pay' ); ?></strong><br><code class="ncp-inline-code"><?php echo esc_html( $secret ); ?></code> <button type="button" class="button button-small ncp-copy-btn" data-copy="<?php echo esc_attr( $secret ); ?>"><?php esc_html_e( 'Copy', 'novacrypto-pay' ); ?></button></p>
			<p><strong><?php esc_html_e( 'Base URL', 'novacrypto-pay' ); ?></strong> <code><?php echo esc_html( rest_url( 'ncp/v1/' ) ); ?></code></p>
			<pre style="font-size:11px;background:#f6f7f7;padding:8px;overflow:auto">curl -X POST <?php echo esc_html( rest_url( 'ncp/v1/payments' ) ); ?> \
  -H "X-NCP-API-Key: <?php echo esc_html( $api_key ); ?>" -H "Content-Type: application/json" \
  -d '{"coin":"btc","amount":25,"currency":"USD","email":"buyer@example.com","reference":"INV-1","webhook_url":"https://yourapp.example/hook"}'

GET  /payments/{uuid}            POST /payments/{uuid}/check
POST /payments/{uuid}/txid       POST /payments/{uuid}/status  (api key)
GET  /coins                      GET  /rates?coin=btc&fiat=EUR

<?php esc_html_e( 'Webhook header: X-NCP-Signature: sha256=HMAC_SHA256(body, secret)', 'novacrypto-pay' ); ?></pre>
		</div>
	</div>
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'Export', 'novacrypto-pay' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ncp_export&what=settings' ), 'ncp_export' ) ); ?>"><?php esc_html_e( 'Export settings (JSON)', 'novacrypto-pay' ); ?></a></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ncp_export" /><input type="hidden" name="what" value="payments" /><?php wp_nonce_field( 'ncp_export' ); ?>
				<label><?php esc_html_e( 'From', 'novacrypto-pay' ); ?> <input type="date" name="from" /></label> <label><?php esc_html_e( 'To', 'novacrypto-pay' ); ?> <input type="date" name="to" /></label> <select name="status"><option value=""><?php esc_html_e( 'Any status', 'novacrypto-pay' ); ?></option><option value="paid"><?php esc_html_e( 'Paid only', 'novacrypto-pay' ); ?></option></select> <button class="button"><?php esc_html_e( 'Export payments (CSV, accounting)', 'novacrypto-pay' ); ?></button>
			</form>
		</div>
		<div class="ncp-panel">
			<h2><?php esc_html_e( 'Import settings', 'novacrypto-pay' ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ncp_tools' ); ?><input type="hidden" name="action" value="ncp_tools" /><input type="hidden" name="do" value="import" />
				<input type="file" name="file" accept="application/json" required /> <button class="button" onclick="return confirm('<?php echo esc_js( __( 'This overwrites all current settings and wallets. Continue?', 'novacrypto-pay' ) ); ?>')"><?php esc_html_e( 'Import', 'novacrypto-pay' ); ?></button>
			</form>
		</div>
	</div>
	<div class="ncp-panel">
		<h2><?php esc_html_e( 'Logs', 'novacrypto-pay' ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'ncp_tools' ); ?><input type="hidden" name="action" value="ncp_tools" /><button class="button button-small" name="do" value="clear_logs"><?php esc_html_e( 'Clear', 'novacrypto-pay' ); ?></button></form>
		</h2>
		<table class="widefat striped ncp-log-table"><thead><tr><th style="width:150px"><?php esc_html_e( 'Time (UTC)', 'novacrypto-pay' ); ?></th><th style="width:60px"><?php esc_html_e( 'Level', 'novacrypto-pay' ); ?></th><th style="width:90px"><?php esc_html_e( 'Context', 'novacrypto-pay' ); ?></th><th><?php esc_html_e( 'Message', 'novacrypto-pay' ); ?></th></tr></thead><tbody>
		<?php foreach ( $logs as $l ) : ?>
			<tr class="ncp-log-<?php echo esc_attr( $l->level ); ?>"><td><?php echo esc_html( $l->created_at ); ?></td><td><?php echo esc_html( $l->level ); ?></td><td><?php echo esc_html( $l->context ); ?></td><td><?php echo esc_html( $l->message ); ?><?php if ( $l->data ) : ?> <details><summary><?php esc_html_e( 'data', 'novacrypto-pay' ); ?></summary><pre style="white-space:pre-wrap"><?php echo esc_html( $l->data ); ?></pre></details><?php endif; ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $logs ) : ?><tr><td colspan="4"><?php esc_html_e( 'No log entries.', 'novacrypto-pay' ); ?></td></tr><?php endif; ?>
		</tbody></table>
	</div>
</div>
