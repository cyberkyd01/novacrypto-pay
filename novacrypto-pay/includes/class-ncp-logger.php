<?php
/**
 * Lightweight DB-backed logger.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Logger {

	public function log( $level, $message, $context = 'core', array $data = array() ) {
		global $wpdb;
		if ( ! ncp()->settings->get( 'enable_logging', true ) && 'error' !== $level ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'ncp_logs',
			array(
				'level'      => substr( $level, 0, 10 ),
				'context'    => substr( $context, 0, 40 ),
				'message'    => substr( $message, 0, 2000 ),
				'data'       => $data ? wp_json_encode( $data ) : null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'error' === $level ) {
			error_log( '[NovaCrypto Pay] ' . $message ); // phpcs:ignore
		}
	}

	public function info( $m, $c = 'core', $d = array() ) {
		$this->log( 'info', $m, $c, $d );
	}
	public function error( $m, $c = 'core', $d = array() ) {
		$this->log( 'error', $m, $c, $d );
	}
	public function debug( $m, $c = 'core', $d = array() ) {
		if ( ncp()->settings->get( 'debug_logging', false ) ) {
			$this->log( 'debug', $m, $c, $d );
		}
	}

	public function recent( $limit = 200, $level = '' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}ncp_logs";
		if ( $level ) {
			$sql .= $wpdb->prepare( ' WHERE level = %s', $level );
		}
		$sql .= $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', $limit );
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public function clear() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ncp_logs" ); // phpcs:ignore
	}

	public function prune( $days = 30 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ncp_logs WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}
}
