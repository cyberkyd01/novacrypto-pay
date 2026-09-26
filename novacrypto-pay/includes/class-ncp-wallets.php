<?php
/**
 * Wallet configuration: per-coin enable flag, address pool (rotated), optional
 * HD extended public key (fresh address per payment) and derivation index.
 *
 * Stored in option `ncp_wallets`:
 *   coin_id => [ enabled, addresses[], xpub, xpub_script, hd_index, hd_change ]
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Wallets {

	const OPTION = 'ncp_wallets';

	private $data;

	public function __construct() {
		$this->data = (array) get_option( self::OPTION, array() );
	}

	public function all() {
		return $this->data;
	}

	public function get( $coin_id ) {
		return wp_parse_args(
			$this->data[ $coin_id ] ?? array(),
			array(
				'enabled'    => false,
				'addresses'  => array(),
				'xpub'       => '',
				'xpub_script' => '',
				'hd_index'   => 0,
				'hd_change'  => 0,
				'rotation'   => 0,
				'label'      => '',
			)
		);
	}

	public function set( $coin_id, array $values ) {
		$this->data[ $coin_id ] = array_merge( $this->get( $coin_id ), $values );
		update_option( self::OPTION, $this->data, false );
	}

	public function is_enabled( $coin_id ) {
		$w    = $this->get( $coin_id );
		$coin = ncp()->coins->get( $coin_id );
		if ( ! $coin || empty( $w['enabled'] ) ) {
			return false;
		}
		if ( 'lightning' === $coin['family'] ) {
			return 'none' !== ncp()->settings->get( 'lightning_backend', 'none' );
		}
		return ! empty( $w['addresses'] ) || ! empty( $w['xpub'] );
	}

	public function uses_hd( $coin_id ) {
		$w = $this->get( $coin_id );
		return ! empty( $w['xpub'] ) && NCP_Math::available();
	}

	/**
	 * Allocate an address for a new payment.
	 *
	 * @return array|WP_Error [ 'address' => string, 'index' => int|null, 'unique' => bool ]
	 */
	public function allocate( $coin_id ) {
		$coin = ncp()->coins->get( $coin_id );
		if ( ! $coin ) {
			return new WP_Error( 'ncp_no_coin', __( 'Unknown coin.', 'novacrypto-pay' ) );
		}
		if ( 'lightning' === $coin['family'] ) {
			return array( 'address' => '', 'index' => null, 'unique' => true );
		}
		$w = $this->get( $coin_id );

		if ( $this->uses_hd( $coin_id ) ) {
			// Reuse indexes of expired/cancelled payments that never received funds (gap-limit friendly).
			$reuse = ncp()->payments->reusable_hd_index( $coin_id );
			if ( null !== $reuse ) {
				$index = $reuse['index'];
				return array( 'address' => $reuse['address'], 'index' => $index, 'unique' => true );
			}
			$index = (int) $w['hd_index'];
			$hint  = in_array( $coin['network'], array( 'btc', 'ltc', 'doge' ), true ) ? $coin['network'] : 'evm';
			$addr  = NCP_BIP32::address_at( $w['xpub'], $index, (int) $w['hd_change'], $hint, $w['xpub_script'] );
			if ( is_wp_error( $addr ) ) {
				return $addr;
			}
			$this->set( $coin_id, array( 'hd_index' => $index + 1 ) );
			return array( 'address' => $addr, 'index' => $index, 'unique' => true );
		}

		$addresses = array_values( array_filter( array_map( 'trim', (array) $w['addresses'] ) ) );
		if ( ! $addresses ) {
			return new WP_Error( 'ncp_no_address', __( 'No wallet address configured for this coin.', 'novacrypto-pay' ) );
		}
		// Prefer an address with no other pending payment, else round-robin. Static addresses are
		// never treated as unique: another payment may share them later, so matching is by amount.
		$busy = ncp()->payments->busy_addresses( $coin_id );
		foreach ( $addresses as $a ) {
			if ( ! in_array( $a, $busy, true ) ) {
				return array( 'address' => $a, 'index' => null, 'unique' => false );
			}
		}
		$i = (int) $w['rotation'] % count( $addresses );
		$this->set( $coin_id, array( 'rotation' => $i + 1 ) );
		return array( 'address' => $addresses[ $i ], 'index' => null, 'unique' => false );
	}

	/** Preview the next N HD addresses (admin verification). */
	public function preview_hd( $coin_id, $count = 5 ) {
		$w    = $this->get( $coin_id );
		$coin = ncp()->coins->get( $coin_id );
		if ( ! $w['xpub'] || ! $coin ) {
			return array();
		}
		$hint = in_array( $coin['network'], array( 'btc', 'ltc', 'doge' ), true ) ? $coin['network'] : 'evm';
		$out  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$a = NCP_BIP32::address_at( $w['xpub'], (int) $w['hd_index'] + $i, (int) $w['hd_change'], $hint, $w['xpub_script'] );
			$out[ (int) $w['hd_index'] + $i ] = is_wp_error( $a ) ? $a->get_error_message() : $a;
		}
		return $out;
	}
}
