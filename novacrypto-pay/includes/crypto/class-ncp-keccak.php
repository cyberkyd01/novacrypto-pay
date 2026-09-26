<?php
/**
 * Pure-PHP Keccak-256 (the pre-NIST padding used by Ethereum).
 *
 * Operates on 32-bit half-words so it is safe on 64-bit signed PHP ints.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Keccak {

	private static $rc = array(
		array( 0x00000000, 0x00000001 ), array( 0x00000000, 0x00008082 ), array( 0x80000000, 0x0000808a ), array( 0x80000000, 0x80008000 ),
		array( 0x00000000, 0x0000808b ), array( 0x00000000, 0x80000001 ), array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008009 ),
		array( 0x00000000, 0x0000008a ), array( 0x00000000, 0x00000088 ), array( 0x00000000, 0x80008009 ), array( 0x00000000, 0x8000000a ),
		array( 0x00000000, 0x8000808b ), array( 0x80000000, 0x0000008b ), array( 0x80000000, 0x00008089 ), array( 0x80000000, 0x00008003 ),
		array( 0x80000000, 0x00008002 ), array( 0x80000000, 0x00000080 ), array( 0x00000000, 0x0000800a ), array( 0x80000000, 0x8000000a ),
		array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008080 ), array( 0x00000000, 0x80000001 ), array( 0x80000000, 0x80008008 ),
	);

	private static $rotc = array( 1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44 );
	private static $piln = array( 10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1 );

	/** Rotate a 64-bit lane stored as [hi, lo] 32-bit words. */
	private static function rotl( $hi, $lo, $n ) {
		if ( $n >= 32 ) {
			list( $hi, $lo ) = array( $lo, $hi );
			$n -= 32;
		}
		if ( 0 === $n ) {
			return array( $hi, $lo );
		}
		$nhi = ( ( $hi << $n ) | ( $lo >> ( 32 - $n ) ) ) & 0xffffffff;
		$nlo = ( ( $lo << $n ) | ( $hi >> ( 32 - $n ) ) ) & 0xffffffff;
		return array( $nhi, $nlo );
	}

	private static function keccakf( array &$st ) {
		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			$bc = array();
			for ( $i = 0; $i < 5; $i++ ) {
				$bc[ $i ] = array(
					$st[ $i ][0] ^ $st[ $i + 5 ][0] ^ $st[ $i + 10 ][0] ^ $st[ $i + 15 ][0] ^ $st[ $i + 20 ][0],
					$st[ $i ][1] ^ $st[ $i + 5 ][1] ^ $st[ $i + 10 ][1] ^ $st[ $i + 15 ][1] ^ $st[ $i + 20 ][1],
				);
			}
			for ( $i = 0; $i < 5; $i++ ) {
				$r = self::rotl( $bc[ ( $i + 1 ) % 5 ][0], $bc[ ( $i + 1 ) % 5 ][1], 1 );
				$t = array( $bc[ ( $i + 4 ) % 5 ][0] ^ $r[0], $bc[ ( $i + 4 ) % 5 ][1] ^ $r[1] );
				for ( $j = 0; $j < 25; $j += 5 ) {
					$st[ $j + $i ][0] ^= $t[0];
					$st[ $j + $i ][1] ^= $t[1];
				}
			}
			// Rho Pi.
			$t = $st[1];
			for ( $i = 0; $i < 24; $i++ ) {
				$j        = self::$piln[ $i ];
				$bc0      = $st[ $j ];
				$st[ $j ] = self::rotl( $t[0], $t[1], self::$rotc[ $i ] );
				$t        = $bc0;
			}
			// Chi.
			for ( $j = 0; $j < 25; $j += 5 ) {
				for ( $i = 0; $i < 5; $i++ ) {
					$bc[ $i ] = $st[ $j + $i ];
				}
				for ( $i = 0; $i < 5; $i++ ) {
					$st[ $j + $i ][0] ^= ( ~$bc[ ( $i + 1 ) % 5 ][0] ) & $bc[ ( $i + 2 ) % 5 ][0];
					$st[ $j + $i ][1] ^= ( ~$bc[ ( $i + 1 ) % 5 ][1] ) & $bc[ ( $i + 2 ) % 5 ][1];
				}
			}
			// Iota.
			$st[0][0] ^= self::$rc[ $round ][0];
			$st[0][1] ^= self::$rc[ $round ][1];
		}
	}

	/** @return string hex digest (64 chars) */
	public static function hash256( $msg ) {
		$rate = 136;
		$st   = array_fill( 0, 25, array( 0, 0 ) );
		// Padding: 0x01 ... 0x80.
		$msg .= "\x01";
		$msg .= str_repeat( "\0", $rate - ( strlen( $msg ) % $rate ) );
		$msg[ strlen( $msg ) - 1 ] = chr( ord( $msg[ strlen( $msg ) - 1 ] ) | 0x80 );

		for ( $off = 0, $len = strlen( $msg ); $off < $len; $off += $rate ) {
			for ( $i = 0; $i < 17; $i++ ) {
				$w = unpack( 'V2', substr( $msg, $off + $i * 8, 8 ) );
				$st[ $i ][1] ^= $w[1];
				$st[ $i ][0] ^= $w[2];
			}
			self::keccakf( $st );
		}
		$out = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$out .= pack( 'V2', $st[ $i ][1], $st[ $i ][0] );
		}
		return bin2hex( $out );
	}
}
