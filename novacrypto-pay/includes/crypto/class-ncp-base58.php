<?php
/**
 * Base58 / Base58Check codec (Bitcoin alphabet).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Base58 {

	const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	public static function encode( $bin ) {
		if ( '' === $bin ) {
			return '';
		}
		$zeros = 0;
		while ( $zeros < strlen( $bin ) && "\0" === $bin[ $zeros ] ) {
			$zeros++;
		}
		$num = NCP_Math::from_bin( $bin );
		$out = '';
		while ( NCP_Math::cmp( $num, '0' ) > 0 ) {
			$rem = (int) NCP_Math::mod( $num, '58' );
			$num = NCP_Math::div( $num, '58' );
			$out = self::ALPHABET[ $rem ] . $out;
		}
		return str_repeat( '1', $zeros ) . $out;
	}

	/** @return string|false binary */
	public static function decode( $str ) {
		if ( '' === $str ) {
			return '';
		}
		$num   = '0';
		$zeros = 0;
		$len   = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$pos = strpos( self::ALPHABET, $str[ $i ] );
			if ( false === $pos ) {
				return false;
			}
			$num = NCP_Math::add( NCP_Math::mul( $num, '58' ), (string) $pos );
		}
		while ( $zeros < $len && '1' === $str[ $zeros ] ) {
			$zeros++;
		}
		$hex = NCP_Math::to_hex( $num, 0 );
		if ( strlen( $hex ) % 2 ) {
			$hex = '0' . $hex;
		}
		$bin = '' === $hex || '0' === $hex ? '' : hex2bin( $hex );
		return str_repeat( "\0", $zeros ) . $bin;
	}

	public static function encode_check( $payload ) {
		$checksum = substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 );
		return self::encode( $payload . $checksum );
	}

	/** @return string|false payload without checksum */
	public static function decode_check( $str ) {
		$bin = self::decode( $str );
		if ( false === $bin || strlen( $bin ) < 5 ) {
			return false;
		}
		$payload  = substr( $bin, 0, -4 );
		$checksum = substr( $bin, -4 );
		$expected = substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 );
		return hash_equals( $expected, $checksum ) ? $payload : false;
	}
}
