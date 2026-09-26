<?php
/**
 * Minimal secp256k1 public-key arithmetic (no private keys are ever handled).
 *
 * Only what BIP32 public derivation needs: point addition, doubling,
 * scalar multiplication of the generator, compression/decompression.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Secp256k1 {

	const P  = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
	const N  = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
	const GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
	const GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';

	/**
	 * Add two affine points. Points are [x, y] decimal strings or null (infinity).
	 */
	public static function add( $p1, $p2 ) {
		if ( null === $p1 ) {
			return $p2;
		}
		if ( null === $p2 ) {
			return $p1;
		}
		$p = self::P;
		if ( 0 === NCP_Math::cmp( $p1[0], $p2[0] ) ) {
			if ( 0 === NCP_Math::cmp( $p1[1], $p2[1] ) ) {
				return self::double( $p1 );
			}
			return null;
		}
		$dy = NCP_Math::mod( NCP_Math::sub( $p2[1], $p1[1] ), $p );
		$dx = NCP_Math::mod( NCP_Math::sub( $p2[0], $p1[0] ), $p );
		$l  = NCP_Math::mod( NCP_Math::mul( $dy, NCP_Math::invmod( $dx, $p ) ), $p );
		$x3 = NCP_Math::mod( NCP_Math::sub( NCP_Math::sub( NCP_Math::mul( $l, $l ), $p1[0] ), $p2[0] ), $p );
		$y3 = NCP_Math::mod( NCP_Math::sub( NCP_Math::mul( $l, NCP_Math::sub( $p1[0], $x3 ) ), $p1[1] ), $p );
		return array( $x3, $y3 );
	}

	public static function double( $pt ) {
		if ( null === $pt ) {
			return null;
		}
		$p  = self::P;
		$l  = NCP_Math::mod(
			NCP_Math::mul(
				NCP_Math::mul( '3', NCP_Math::mul( $pt[0], $pt[0] ) ),
				NCP_Math::invmod( NCP_Math::mul( '2', $pt[1] ), $p )
			),
			$p
		);
		$x3 = NCP_Math::mod( NCP_Math::sub( NCP_Math::mul( $l, $l ), NCP_Math::mul( '2', $pt[0] ) ), $p );
		$y3 = NCP_Math::mod( NCP_Math::sub( NCP_Math::mul( $l, NCP_Math::sub( $pt[0], $x3 ) ), $pt[1] ), $p );
		return array( $x3, $y3 );
	}

	/** Scalar multiplication (double-and-add). $k is a decimal string. */
	public static function mul( $k, $pt ) {
		$result = null;
		$addend = $pt;
		$bits   = NCP_Math::to_hex( $k, 32 );
		$bin    = '';
		for ( $i = 0, $l = strlen( $bits ); $i < $l; $i++ ) {
			$bin .= str_pad( base_convert( $bits[ $i ], 16, 2 ), 4, '0', STR_PAD_LEFT );
		}
		$bin = ltrim( $bin, '0' );
		for ( $i = strlen( $bin ) - 1; $i >= 0; $i-- ) {
			if ( '1' === $bin[ $i ] ) {
				$result = self::add( $result, $addend );
			}
			$addend = self::double( $addend );
		}
		return $result;
	}

	public static function generator() {
		return array( self::GX, self::GY );
	}

	/** Compressed 33-byte SEC1 encoding (binary). */
	public static function compress( $pt ) {
		$prefix = NCP_Math::is_odd( $pt[1] ) ? "\x03" : "\x02";
		return $prefix . NCP_Math::to_bin( $pt[0], 32 );
	}

	/** Uncompressed 65-byte SEC1 encoding (binary). */
	public static function uncompress( $pt ) {
		return "\x04" . NCP_Math::to_bin( $pt[0], 32 ) . NCP_Math::to_bin( $pt[1], 32 );
	}

	/** Decompress a 33-byte SEC1 key into an affine point. */
	public static function decompress( $bin ) {
		if ( 33 !== strlen( $bin ) ) {
			return null;
		}
		$prefix = ord( $bin[0] );
		if ( 2 !== $prefix && 3 !== $prefix ) {
			return null;
		}
		$p  = self::P;
		$x  = NCP_Math::from_bin( substr( $bin, 1 ) );
		$y2 = NCP_Math::mod( NCP_Math::add( NCP_Math::powmod( $x, '3', $p ), '7' ), $p );
		// p ≡ 3 mod 4 so sqrt = y2^((p+1)/4).
		$y = NCP_Math::powmod( $y2, NCP_Math::div( NCP_Math::add( $p, '1' ), '4' ), $p );
		if ( NCP_Math::is_odd( $y ) !== ( 3 === $prefix ) ) {
			$y = NCP_Math::sub( $p, $y );
		}
		// Sanity: y^2 == y2.
		if ( 0 !== NCP_Math::cmp( NCP_Math::mod( NCP_Math::mul( $y, $y ), $p ), $y2 ) ) {
			return null;
		}
		return array( $x, $y );
	}
}
