<?php
/**
 * Bech32 / Bech32m codec with SegWit address helpers (BIP173 / BIP350).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Bech32 {

	const CHARSET       = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	const CONST_BECH32  = 1;
	const CONST_BECH32M = 0x2bc830a3;

	private static function polymod( array $values ) {
		$gen = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
		$chk = 1;
		foreach ( $values as $v ) {
			$b   = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $v;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $b >> $i ) & 1 ) {
					$chk ^= $gen[ $i ];
				}
			}
		}
		return $chk;
	}

	private static function hrp_expand( $hrp ) {
		$out = array();
		$len = strlen( $hrp );
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) >> 5;
		}
		$out[] = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) & 31;
		}
		return $out;
	}

	public static function encode( $hrp, array $data, $const = self::CONST_BECH32 ) {
		$values  = array_merge( self::hrp_expand( $hrp ), $data );
		$polymod = self::polymod( array_merge( $values, array( 0, 0, 0, 0, 0, 0 ) ) ) ^ $const;
		$out     = $hrp . '1';
		foreach ( $data as $d ) {
			$out .= self::CHARSET[ $d ];
		}
		for ( $i = 0; $i < 6; $i++ ) {
			$out .= self::CHARSET[ ( $polymod >> ( 5 * ( 5 - $i ) ) ) & 31 ];
		}
		return $out;
	}

	/** @return array|false [hrp, data(array of 5-bit ints), const] */
	public static function decode( $str ) {
		if ( strlen( $str ) > 90 || strtolower( $str ) !== $str && strtoupper( $str ) !== $str ) {
			return false;
		}
		$str = strtolower( $str );
		$pos = strrpos( $str, '1' );
		if ( false === $pos || $pos < 1 || $pos + 7 > strlen( $str ) ) {
			return false;
		}
		$hrp  = substr( $str, 0, $pos );
		$data = array();
		for ( $i = $pos + 1, $l = strlen( $str ); $i < $l; $i++ ) {
			$d = strpos( self::CHARSET, $str[ $i ] );
			if ( false === $d ) {
				return false;
			}
			$data[] = $d;
		}
		$pm = self::polymod( array_merge( self::hrp_expand( $hrp ), $data ) );
		if ( self::CONST_BECH32 !== $pm && self::CONST_BECH32M !== $pm ) {
			return false;
		}
		return array( $hrp, array_slice( $data, 0, -6 ), $pm );
	}

	public static function convert_bits( array $data, $from, $to, $pad = true ) {
		$acc    = 0;
		$bits   = 0;
		$ret    = array();
		$maxv   = ( 1 << $to ) - 1;
		foreach ( $data as $value ) {
			if ( $value < 0 || ( $value >> $from ) ) {
				return false;
			}
			$acc   = ( ( $acc << $from ) | $value ) & 0xffffffff;
			$bits += $from;
			while ( $bits >= $to ) {
				$bits -= $to;
				$ret[] = ( $acc >> $bits ) & $maxv;
			}
		}
		if ( $pad ) {
			if ( $bits ) {
				$ret[] = ( $acc << ( $to - $bits ) ) & $maxv;
			}
		} elseif ( $bits >= $from || ( ( $acc << ( $to - $bits ) ) & $maxv ) ) {
			return false;
		}
		return $ret;
	}

	/** Encode a segwit address from witness version + program (binary). */
	public static function segwit_encode( $hrp, $version, $program ) {
		$bytes = array_values( unpack( 'C*', $program ) );
		$data  = array_merge( array( $version ), self::convert_bits( $bytes, 8, 5, true ) );
		return self::encode( $hrp, $data, 0 === $version ? self::CONST_BECH32 : self::CONST_BECH32M );
	}

	/** @return array|false [version, program(binary)] */
	public static function segwit_decode( $hrp, $addr ) {
		$dec = self::decode( $addr );
		if ( false === $dec || $dec[0] !== $hrp || empty( $dec[1] ) ) {
			return false;
		}
		list( , $data, $const ) = $dec;
		$version = array_shift( $data );
		if ( $version > 16 ) {
			return false;
		}
		$prog = self::convert_bits( $data, 5, 8, false );
		if ( false === $prog || count( $prog ) < 2 || count( $prog ) > 40 ) {
			return false;
		}
		if ( 0 === $version && 20 !== count( $prog ) && 32 !== count( $prog ) ) {
			return false;
		}
		if ( ( 0 === $version && self::CONST_BECH32 !== $const ) || ( 0 !== $version && self::CONST_BECH32M !== $const ) ) {
			return false;
		}
		return array( $version, pack( 'C*', ...$prog ) );
	}
}
