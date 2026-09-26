<?php
/**
 * Arbitrary-precision integer helper.
 *
 * Uses GMP when available and falls back to bcmath so HD-wallet address
 * derivation works on the widest possible range of hosts.
 *
 * All values are passed around as decimal strings.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Math {

	/** @return bool */
	public static function available() {
		return function_exists( 'gmp_add' ) || function_exists( 'bcadd' );
	}

	private static function gmp() {
		return function_exists( 'gmp_add' );
	}

	public static function add( $a, $b ) {
		return self::gmp() ? gmp_strval( gmp_add( $a, $b ) ) : bcadd( $a, $b, 0 );
	}

	public static function sub( $a, $b ) {
		return self::gmp() ? gmp_strval( gmp_sub( $a, $b ) ) : bcsub( $a, $b, 0 );
	}

	public static function mul( $a, $b ) {
		return self::gmp() ? gmp_strval( gmp_mul( $a, $b ) ) : bcmul( $a, $b, 0 );
	}

	public static function div( $a, $b ) {
		return self::gmp() ? gmp_strval( gmp_div_q( $a, $b ) ) : bcdiv( $a, $b, 0 );
	}

	/** Always returns a non-negative residue. */
	public static function mod( $a, $m ) {
		if ( self::gmp() ) {
			return gmp_strval( gmp_mod( $a, $m ) );
		}
		$r = bcmod( $a, $m );
		if ( '-' === substr( $r, 0, 1 ) ) {
			$r = bcadd( $r, $m, 0 );
		}
		return $r;
	}

	public static function powmod( $b, $e, $m ) {
		return self::gmp() ? gmp_strval( gmp_powm( $b, $e, $m ) ) : bcpowmod( $b, $e, $m, 0 );
	}

	public static function cmp( $a, $b ) {
		return self::gmp() ? gmp_cmp( $a, $b ) : bccomp( $a, $b, 0 );
	}

	/** Modular inverse via Fermat (m must be prime) with GMP fast-path. */
	public static function invmod( $a, $m ) {
		if ( self::gmp() ) {
			$inv = gmp_invert( $a, $m );
			return false === $inv ? '0' : gmp_strval( $inv );
		}
		return bcpowmod( self::mod( $a, $m ), bcsub( $m, '2', 0 ), $m, 0 );
	}

	public static function is_odd( $a ) {
		return 1 === ( (int) substr( $a, -1 ) % 2 );
	}

	/** Decimal string from hex string (no 0x). */
	public static function from_hex( $hex ) {
		$hex = ltrim( strtolower( $hex ), '0' );
		if ( '' === $hex ) {
			return '0';
		}
		if ( self::gmp() ) {
			return gmp_strval( gmp_init( $hex, 16 ) );
		}
		$dec = '0';
		$len = strlen( $hex );
		for ( $i = 0; $i < $len; $i++ ) {
			$dec = bcadd( bcmul( $dec, '16', 0 ), (string) hexdec( $hex[ $i ] ), 0 );
		}
		return $dec;
	}

	/** Hex string (lowercase, zero padded to $bytes) from decimal string. */
	public static function to_hex( $dec, $bytes = 32 ) {
		if ( self::gmp() ) {
			$hex = gmp_strval( gmp_init( $dec, 10 ), 16 );
		} else {
			$hex = '';
			$n   = $dec;
			while ( bccomp( $n, '0', 0 ) > 0 ) {
				$hex = dechex( (int) bcmod( $n, '16' ) ) . $hex;
				$n   = bcdiv( $n, '16', 0 );
			}
		}
		return str_pad( $hex, $bytes * 2, '0', STR_PAD_LEFT );
	}

	public static function from_bin( $bin ) {
		return self::from_hex( bin2hex( $bin ) );
	}

	public static function to_bin( $dec, $bytes = 32 ) {
		return hex2bin( self::to_hex( $dec, $bytes ) );
	}
}
