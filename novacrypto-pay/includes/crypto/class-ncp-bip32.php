<?php
/**
 * BIP32 extended *public* key parsing and non-hardened child derivation.
 *
 * Supports xpub/ypub/zpub (BTC), tpub/upub/vpub (BTC testnet), Ltub/Mtub
 * (LTC), dgub (DOGE) and derives receiving addresses in the script type the
 * key prefix implies. Private keys never touch the server.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_BIP32 {

	/**
	 * Version-byte map: prefix hex => [coin, script type, network].
	 */
	private static $versions = array(
		'0488b21e' => array( 'btc', 'p2pkh', 'mainnet' ),  // xpub
		'049d7cb2' => array( 'btc', 'p2sh-p2wpkh', 'mainnet' ), // ypub
		'04b24746' => array( 'btc', 'p2wpkh', 'mainnet' ), // zpub
		'043587cf' => array( 'btc', 'p2pkh', 'testnet' ),  // tpub
		'044a5262' => array( 'btc', 'p2sh-p2wpkh', 'testnet' ), // upub
		'045f1cf6' => array( 'btc', 'p2wpkh', 'testnet' ), // vpub
		'019da462' => array( 'ltc', 'p2pkh', 'mainnet' ),  // Ltub
		'01b26ef6' => array( 'ltc', 'p2sh-p2wpkh', 'mainnet' ), // Mtub
		'02facafd' => array( 'doge', 'p2pkh', 'mainnet' ), // dgub
	);

	/**
	 * Parse an extended public key string.
	 *
	 * @return array|WP_Error [ 'coin','script','network','depth','fingerprint','child','chain','key'(33b bin),'point' ]
	 */
	public static function parse( $xpub, $coin_hint = '', $script_hint = '' ) {
		$xpub = trim( $xpub );
		$raw  = NCP_Base58::decode_check( $xpub );
		if ( false === $raw || 78 !== strlen( $raw ) ) {
			return new WP_Error( 'ncp_bad_xpub', __( 'Extended public key is not valid Base58Check (78 bytes expected).', 'novacrypto-pay' ) );
		}
		$version = bin2hex( substr( $raw, 0, 4 ) );
		if ( ! isset( self::$versions[ $version ] ) ) {
			return new WP_Error( 'ncp_bad_xpub_version', __( 'Unsupported extended key version. Use xpub, ypub, zpub, tpub, Ltub, Mtub or dgub (never a private xprv).', 'novacrypto-pay' ) );
		}
		list( $coin, $script, $network ) = self::$versions[ $version ];
		// Many LTC/DOGE wallets export plain xpub; allow the coin hint to override.
		if ( $coin_hint && 'btc' === $coin && 'btc' !== $coin_hint ) {
			$coin = $coin_hint; // ltc/doge (plain xpub exports) or any EVM chain id.
		}
		if ( $script_hint && 'p2pkh' === $script && in_array( $script_hint, array( 'p2wpkh', 'p2sh-p2wpkh' ), true ) ) {
			$script = $script_hint;
		}
		$key   = substr( $raw, 45, 33 );
		$point = NCP_Secp256k1::decompress( $key );
		if ( null === $point ) {
			return new WP_Error( 'ncp_bad_xpub_point', __( 'Extended public key does not encode a valid curve point.', 'novacrypto-pay' ) );
		}
		return array(
			'coin'        => $coin,
			'script'      => $script,
			'network'     => $network,
			'depth'       => ord( $raw[4] ),
			'fingerprint' => bin2hex( substr( $raw, 5, 4 ) ),
			'child'       => unpack( 'N', substr( $raw, 9, 4 ) )[1],
			'chain'       => substr( $raw, 13, 32 ),
			'key'         => $key,
			'point'       => $point,
			'version'     => $version,
		);
	}

	/**
	 * CKDpub: derive non-hardened child $index.
	 *
	 * @return array|null node with 'key','chain','point'
	 */
	public static function ckd_pub( array $node, $index ) {
		if ( $index >= 0x80000000 ) {
			return null; // Hardened derivation impossible from a public key.
		}
		$data = $node['key'] . pack( 'N', $index );
		$i    = hash_hmac( 'sha512', $data, $node['chain'], true );
		$il   = NCP_Math::from_bin( substr( $i, 0, 32 ) );
		$ir   = substr( $i, 32 );
		if ( NCP_Math::cmp( $il, NCP_Secp256k1::N ) >= 0 ) {
			return null;
		}
		$pt = NCP_Secp256k1::add( NCP_Secp256k1::mul( $il, NCP_Secp256k1::generator() ), $node['point'] );
		if ( null === $pt ) {
			return null;
		}
		$child          = $node;
		$child['point'] = $pt;
		$child['key']   = NCP_Secp256k1::compress( $pt );
		$child['chain'] = $ir;
		$child['depth'] = $node['depth'] + 1;
		$child['child'] = $index;
		return $child;
	}

	/** Derive a path such as "0/5" relative to the given node. */
	public static function derive_path( array $node, $path ) {
		$path = trim( $path, '/mM' );
		if ( '' === $path ) {
			return $node;
		}
		foreach ( explode( '/', $path ) as $seg ) {
			if ( ! ctype_digit( $seg ) ) {
				return null;
			}
			$node = self::ckd_pub( $node, (int) $seg );
			if ( null === $node ) {
				return null;
			}
		}
		return $node;
	}

	/** hash160 = RIPEMD160(SHA256(x)). */
	public static function hash160( $bin ) {
		return hash( 'ripemd160', hash( 'sha256', $bin, true ), true );
	}

	/**
	 * Build an address from a compressed pubkey for a coin/script/network.
	 */
	public static function address( $pubkey, $coin, $script, $network = 'mainnet' ) {
		$h160 = self::hash160( $pubkey );
		$nets = array(
			'btc'  => array( 'p2pkh' => "\x00", 'p2sh' => "\x05", 'hrp' => 'bc' ),
			'ltc'  => array( 'p2pkh' => "\x30", 'p2sh' => "\x32", 'hrp' => 'ltc' ),
			'doge' => array( 'p2pkh' => "\x1e", 'p2sh' => "\x16", 'hrp' => '' ),
		);
		if ( 'testnet' === $network ) {
			$nets['btc'] = array( 'p2pkh' => "\x6f", 'p2sh' => "\xc4", 'hrp' => 'tb' );
			$nets['ltc'] = array( 'p2pkh' => "\x6f", 'p2sh' => "\x3a", 'hrp' => 'tltc' );
			$nets['doge'] = array( 'p2pkh' => "\x71", 'p2sh' => "\xc4", 'hrp' => '' );
		}
		if ( ! isset( $nets[ $coin ] ) ) {
			return null;
		}
		$n = $nets[ $coin ];
		switch ( $script ) {
			case 'p2wpkh':
				return $n['hrp'] ? NCP_Bech32::segwit_encode( $n['hrp'], 0, $h160 ) : null;
			case 'p2sh-p2wpkh':
				$redeem = "\x00\x14" . $h160;
				return NCP_Base58::encode_check( $n['p2sh'] . self::hash160( $redeem ) );
			case 'p2pkh':
			default:
				return NCP_Base58::encode_check( $n['p2pkh'] . $h160 );
		}
	}

	/** Ethereum-style address (EIP-55 checksummed) from a compressed pubkey. */
	public static function evm_address( $pubkey ) {
		$pt = NCP_Secp256k1::decompress( $pubkey );
		if ( null === $pt ) {
			return null;
		}
		$unc  = substr( NCP_Secp256k1::uncompress( $pt ), 1 );
		$hash = NCP_Keccak::hash256( $unc );
		return self::eip55( substr( $hash, 24 ) );
	}

	/** Apply EIP-55 mixed-case checksum to a 40-char hex address. */
	public static function eip55( $hex ) {
		$hex  = strtolower( ltrim( $hex, 'x0' ) );
		$hex  = str_pad( $hex, 40, '0', STR_PAD_LEFT );
		$hash = NCP_Keccak::hash256( $hex );
		$out  = '';
		for ( $i = 0; $i < 40; $i++ ) {
			$out .= hexdec( $hash[ $i ] ) >= 8 ? strtoupper( $hex[ $i ] ) : $hex[ $i ];
		}
		return '0x' . $out;
	}

	/**
	 * Convenience: address for account-level xpub at change/index.
	 *
	 * @return string|WP_Error
	 */
	public static function address_at( $xpub, $index, $change = 0, $coin_hint = '', $script_hint = '' ) {
		$node = self::parse( $xpub, $coin_hint, $script_hint );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$child = self::derive_path( $node, $change . '/' . $index );
		if ( null === $child ) {
			return new WP_Error( 'ncp_derive_failed', __( 'Child key derivation failed.', 'novacrypto-pay' ) );
		}
		if ( in_array( $node['coin'], array( 'btc', 'ltc', 'doge' ), true ) ) {
			return self::address( $child['key'], $node['coin'], $node['script'], $node['network'] );
		}
		return self::evm_address( $child['key'] );
	}
}
