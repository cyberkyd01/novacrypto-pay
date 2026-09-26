<?php
/**
 * Uninstall: only drops data when the admin opted in.
 *
 * @package NovaCryptoPay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$settings = get_option( 'ncp_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'ncp_payments', 'ncp_transactions', 'ncp_links', 'ncp_logs', 'ncp_webhook_queue' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore
}
foreach ( array( 'ncp_settings', 'ncp_wallets', 'ncp_api_key', 'ncp_webhook_secret', 'ncp_db_version', 'ncp_installed_at', 'woocommerce_novacrypto_settings' ) as $o ) {
	delete_option( $o );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ncp_last_rate_%' OR option_name LIKE '_transient_ncp_%' OR option_name LIKE '_transient_timeout_ncp_%'" ); // phpcs:ignore
wp_clear_scheduled_hook( 'ncp_cron_check_payments' );
wp_clear_scheduled_hook( 'ncp_cron_maintenance' );
