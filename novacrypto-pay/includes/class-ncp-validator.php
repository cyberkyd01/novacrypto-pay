<?php
/**
 * Address validation for every supported family, with checksum verification.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Validator {

	/**
	 * @return true|WP_Error
	 */
	public static function validate( $address, array $coin ) {
		$address = trim( $address );
		$testnet = ncp()->settings->is_testnet();
		if ( '' === $address ) {
			return new WP_Error( 'ncp_addr_empty', __( 'Address is empty.', 'novacrypto-pay' ) );
		}
		switch ( $coin['family'] ) {
			case 'utxo':
				$ok = self::utxo( $address, $coin['network'], $testnet );
				break;
			case 'evm':
				$ok = self::evm( $address );
				break;
			case 'tron':
				$ok = self::tron( $address );
				break;
			case 'solana':
				$ok = self::solana( $address );
				break;
			case 'lightning':
				$ok = true;
				break;
			default:
				$ok = (bool) preg_match( '/^[A-Za-z0-9:_-]{10,120}$/', $address );
		}
		if ( ! $ok ) {
			/* translators: %s coin name */
			return new WP_Error( 'ncp_addr_invalid', sprintf( __( '%s address is invalid (checksum or format check failed).', 'novacrypto-pay' ), $coin['name'] ) );
		}
		return true;
	}

	public static function utxo( $addr, $network, $testnet = false ) {
		// Bech32 / Bech32m.
		$hrps = array(
			'btc'  => $testnet ? array( 'tb', 'bcrt' ) : array( 'bc' ),
			'ltc'  => $testnet ? array( 'tltc' ) : array( 'ltc' ),
			'doge' => array(),
			'bch'  => array(),
		);
		$lower = strtolower( $addr );
		foreach ( $hrps[ $network ] ?? array() as $hrp ) {
			if ( 0 === strpos( $lower, $hrp . '1' ) ) {
				return false !== NCP_Bech32::segwit_decode( $hrp, $addr );
			}
		}
		if ( 'bch' === $network ) {
			if ( false !== stripos( $addr, ':' ) || preg_match( '/^[qp][a-z0-9]{41}$/i', $addr ) ) {
				return self::cashaddr( $addr );
			}
		}
		// Base58Check.
		$payload = NCP_Base58::decode_check( $addr );
		if ( false === $payload || 21 !== strlen( $payload ) ) {
			return false;
		}
		$v        = ord( $payload[0] );
		$versions = array(
			'btc'  => $testnet ? array( 0x6f, 0xc4 ) : array( 0x00, 0x05 ),
			'ltc'  => $testnet ? array( 0x6f, 0x3a, 0xc4 ) : array( 0x30, 0x32, 0x05 ),
			'doge' => $testnet ? array( 0x71, 0xc4 ) : array( 0x1e, 0x16 ),
			'bch'  => $testnet ? array( 0x6f, 0xc4 ) : array( 0x00, 0x05 ),
		);
		return in_array( $v, $versions[ $network ] ?? array(), true );
	}

	/** CashAddr (BCH) checksum validation. */
	public static function cashaddr( $addr ) {
		$addr = strtolower( $addr );
		if ( false === strpos( $addr, ':' ) ) {
			$addr = 'bitcoincash:' . $addr;
		}
		list( $prefix, $payload ) = explode( ':', $addr, 2 );
		$charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
		$data    = array();
		for ( $i = 0, $l = strlen( $payload ); $i < $l; $i++ ) {
			$d = strpos( $charset, $payload[ $i ] );
			if ( false === $d ) {
				return false;
			}
			$data[] = $d;
		}
		$values = array();
		for ( $i = 0, $l = strlen( $prefix ); $i < $l; $i++ ) {
			$values[] = ord( $prefix[ $i ] ) & 0x1f;
		}
		$values[] = 0;
		$values   = array_merge( $values, $data );
		$c        = 1;
		$gens     = array( 0x98f2bc8e61, 0x79b76d99e2, 0xf33e5fb3c4, 0xae2eabe2a8, 0x1e4f43e470 );
		foreach ( $values as $d ) {
			$c0 = $c >> 35;
			$c  = ( ( $c & 0x07ffffffff ) << 5 ) ^ $d;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $c0 >> $i ) & 1 ) {
					$c ^= $gens[ $i ];
				}
			}
		}
		return 0 === ( $c ^ 1 );
	}

	public static function evm( $addr ) {
		if ( ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $addr ) ) {
			return false;
		}
		$hex = substr( $addr, 2 );
		if ( strtolower( $hex ) === $hex || strtoupper( $hex ) === $hex ) {
			return true; // No checksum encoded.
		}
		return NCP_BIP32::eip55( $hex ) === $addr;
	}

	public static function tron( $addr ) {
		if ( ! preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr ) ) {
			return false;
		}
		$payload = NCP_Base58::decode_check( $addr );
		return false !== $payload && 21 === strlen( $payload ) && "\x41" === $payload[0];
	}

	public static function solana( $addr ) {
		if ( ! preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $addr ) ) {
			return false;
		}
		$bin = NCP_Base58::decode( $addr );
		return false !== $bin && 32 === strlen( $bin );
	}

	public static function txid( $txid, array $coin ) {
		$txid = trim( $txid );
		switch ( $coin['family'] ) {
			case 'evm':
				return (bool) preg_match( '/^0x[0-9a-fA-F]{64}$/', $txid );
			case 'utxo':
			case 'tron':
				return (bool) preg_match( '/^[0-9a-fA-F]{64}$/', $txid );
			case 'solana':
				return (bool) preg_match( '/^[1-9A-HJ-NP-Za-km-z]{80,90}$/', $txid );
			case 'lightning':
				return (bool) preg_match( '/^[0-9a-fA-F]{64}$/', $txid );
		}
		return (bool) preg_match( '/^[A-Za-z0-9]{20,128}$/', $txid );
	}

	/** Extended public key validation for a coin. @return true|WP_Error */
	public static function xpub( $xpub, array $coin ) {
		if ( ! NCP_Math::available() ) {
			return new WP_Error( 'ncp_no_math', __( 'HD wallet support needs the PHP GMP or BCMath extension.', 'novacrypto-pay' ) );
		}
		if ( preg_match( '/^[xyztuvLMd]prv/', $xpub ) ) {
			return new WP_Error( 'ncp_xprv', __( 'Never paste a PRIVATE extended key (xprv). Use the public xpub/ypub/zpub only.', 'novacrypto-pay' ) );
		}
		$hint = in_array( $coin['network'], array( 'btc', 'ltc', 'doge' ), true ) ? $coin['network'] : 'evm';
		$node = NCP_BIP32::parse( $xpub, $hint );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		if ( 'utxo' === $coin['family'] && $node['network'] !== ( ncp()->settings->is_testnet() ? 'testnet' : 'mainnet' ) ) {
			return new WP_Error( 'ncp_xpub_net', __( 'Extended key network (mainnet/testnet) does not match the plugin Test Mode setting.', 'novacrypto-pay' ) );
		}
		return true;
	}
}
