<?php
/**
 * Activation, schema, cron and upgrades.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Install {

	public static function activate() {
		self::create_tables();
		self::schedule_cron();
		self::add_caps();
		add_option( 'ncp_db_version', NCP_DB_VERSION );
		if ( ! get_option( 'ncp_api_key' ) ) {
			update_option( 'ncp_api_key', 'ncp_' . wp_generate_password( 40, false ) );
		}
		if ( ! get_option( 'ncp_webhook_secret' ) ) {
			update_option( 'ncp_webhook_secret', wp_generate_password( 48, false ) );
		}
		add_option( 'ncp_installed_at', time() );
		set_transient( 'ncp_activation_redirect', 1, 60 );
		// Register rewrite rules for pay pages before flushing.
		NCP_Frontend::add_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'ncp_cron_check_payments' );
		wp_clear_scheduled_hook( 'ncp_cron_maintenance' );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'ncp_db_version' ) !== NCP_DB_VERSION ) {
			self::create_tables();
			update_option( 'ncp_db_version', NCP_DB_VERSION );
		}
		if ( ! wp_next_scheduled( 'ncp_cron_check_payments' ) ) {
			self::schedule_cron();
		}
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( 'ncp_cron_check_payments' ) ) {
			wp_schedule_event( time() + 60, 'ncp_every_minute', 'ncp_cron_check_payments' );
		}
		if ( ! wp_next_scheduled( 'ncp_cron_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ncp_cron_maintenance' );
		}
	}

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_novacrypto' );
		}
		$shop = get_role( 'shop_manager' );
		if ( $shop ) {
			$shop->add_cap( 'manage_novacrypto' );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}ncp_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'api',
			source_id VARCHAR(64) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			coin_id VARCHAR(40) NOT NULL,
			address VARCHAR(191) NOT NULL,
			address_index INT UNSIGNED NULL,
			amount_crypto DECIMAL(36,18) NOT NULL DEFAULT 0,
			amount_fiat DECIMAL(20,4) NOT NULL DEFAULT 0,
			fiat_currency CHAR(3) NOT NULL DEFAULT 'USD',
			rate DECIMAL(30,10) NOT NULL DEFAULT 0,
			rate_provider VARCHAR(30) NULL,
			received_crypto DECIMAL(36,18) NOT NULL DEFAULT 0,
			confirmations INT UNSIGNED NOT NULL DEFAULT 0,
			required_confirmations INT UNSIGNED NOT NULL DEFAULT 1,
			customer_email VARCHAR(191) NULL,
			customer_name VARCHAR(191) NULL,
			refund_address VARCHAR(191) NULL,
			title VARCHAR(191) NULL,
			return_url TEXT NULL,
			meta LONGTEXT NULL,
			last_checked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			paid_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY source (source, source_id),
			KEY address (address(100)),
			KEY created_at (created_at)
		) $charset;
		CREATE TABLE {$p}ncp_transactions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payment_id BIGINT UNSIGNED NOT NULL,
			txid VARCHAR(191) NOT NULL,
			coin_id VARCHAR(40) NOT NULL,
			amount DECIMAL(36,18) NOT NULL DEFAULT 0,
			confirmations INT UNSIGNED NOT NULL DEFAULT 0,
			block_time DATETIME NULL,
			source VARCHAR(30) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY txid_coin (txid(150), coin_id),
			KEY payment_id (payment_id)
		) $charset;
		CREATE TABLE {$p}ncp_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'link',
			title VARCHAR(191) NOT NULL,
			description TEXT NULL,
			amount DECIMAL(20,4) NULL,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			allow_custom_amount TINYINT(1) NOT NULL DEFAULT 0,
			min_amount DECIMAL(20,4) NULL,
			coins TEXT NULL,
			customer_email VARCHAR(191) NULL,
			customer_name VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			max_uses INT UNSIGNED NULL,
			times_used INT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			success_url TEXT NULL,
			meta LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY type_status (type, status)
		) $charset;
		CREATE TABLE {$p}ncp_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL,
			context VARCHAR(40) NOT NULL,
			message TEXT NOT NULL,
			data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) $charset;
		CREATE TABLE {$p}ncp_webhook_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payment_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(40) NOT NULL,
			url TEXT NOT NULL,
			payload LONGTEXT NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_status VARCHAR(20) NULL,
			next_attempt_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY next_attempt_at (next_attempt_at)
		) $charset;";

		dbDelta( $sql );
	}
}

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['ncp_every_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (NovaCrypto Pay)',
		);
		return $schedules;
	}
);
