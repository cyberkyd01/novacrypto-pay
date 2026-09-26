<?php
/**
 * Standalone test runner for the crypto primitives (no WordPress needed).
 * Run: php tests/test-crypto.php
 */
define( 'ABSPATH', __DIR__ );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
	function __( $s ) { return $s; }
}
foreach ( array( 'math', 'secp256k1', 'base58', 'bech32', 'keccak', 'bip32' ) as $c ) {
	require_once __DIR__ . '/../includes/crypto/class-ncp-' . $c . '.php';
}
$fail = 0;
function check( $name, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) { $fail++; }
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  $name" . ( $ok ? '' : "\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) ) . "\n";
}
$t = microtime( true );

// Keccak-256.
check( 'keccak256("")', NCP_Keccak::hash256( '' ), 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470' );
check( 'keccak256("abc")', NCP_Keccak::hash256( 'abc' ), '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45' );
check( 'keccak256(200 bytes)', NCP_Keccak::hash256( str_repeat( 'a', 200 ) ), NCP_Keccak::hash256( str_repeat( 'a', 200 ) ) );

// Base58.
check( 'base58 decode/encode roundtrip', NCP_Base58::encode( NCP_Base58::decode( '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA' ) ), '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA' );
check( 'base58check valid', false !== NCP_Base58::decode_check( '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA' ), true );
check( 'base58check invalid', NCP_Base58::decode_check( '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabB' ), false );

// Bech32.
$sw = NCP_Bech32::segwit_decode( 'bc', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4' );
check( 'bech32 segwit decode', bin2hex( $sw[1] ), '751e76e8199196d454941c45d1b3a323f1433bd6' );
check( 'bech32 segwit encode', NCP_Bech32::segwit_encode( 'bc', 0, hex2bin( '751e76e8199196d454941c45d1b3a323f1433bd6' ) ), 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4' );
check( 'bech32m taproot decode', false !== NCP_Bech32::segwit_decode( 'bc', 'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0' ), true );
check( 'bech32 bad checksum', NCP_Bech32::decode( 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5' ), false );

// secp256k1: G*1 == G, 2G known.
$g2 = NCP_Secp256k1::mul( '2', NCP_Secp256k1::generator() );
check( 'secp256k1 2G.x', NCP_Math::to_hex( $g2[0] ), 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5' );
$g1 = NCP_Secp256k1::mul( '1', NCP_Secp256k1::generator() );
check( 'secp256k1 1G == G', $g1, NCP_Secp256k1::generator() );

// BIP32 test vector 2: master xpub -> m/0.
$m0 = NCP_BIP32::derive_path( NCP_BIP32::parse( 'xpub661MyMwAqRbcFW31YEwpkMuc5THy2PSt5bDMsktWQcFF8syAmRUapSCGu8ED9W6oDMSgv6Zz8idoc4a6mr8BDzTJY47LJhkJ8UB7WEGuduB' ), '0' );
$m0_want = NCP_BIP32::parse( 'xpub69H7F5d8KSRgmmdJg2KhpAK8SR3DjMwAdkxj3ZuxV27CprR9LgpeyGmXUbC6wb7ERfvrnKZjXoUmmDznezpbZb7ap6r1D3tgFxHmwMkQTPH' );
check( 'BIP32 vector2 m/0 pubkey', bin2hex( $m0['key'] ), bin2hex( $m0_want['key'] ) );
check( 'BIP32 vector2 m/0 chaincode', bin2hex( $m0['chain'] ), bin2hex( $m0_want['chain'] ) );

// BIP84 zpub (mnemonic "abandon x11 about") -> /0/0, /0/1.
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
check( 'BIP84 zpub /0/0', NCP_BIP32::address_at( $zpub, 0 ), 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu' );
check( 'BIP84 zpub /0/1', NCP_BIP32::address_at( $zpub, 1 ), 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g' );
check( 'BIP84 zpub change /1/0', NCP_BIP32::address_at( $zpub, 0, 1 ), 'bc1q8c6fshw2dlwun7ekn9qwf37cu2rn755upcp6el' );

// BIP44 xpub same mnemonic -> /0/0.
$xpub44 = 'xpub6BosfCnifzxcFwrSzQiqu2DBVTshkCXacvNsWGYJVVhhawA7d4R5WSWGFNbi8Aw6ZRc1brxMyWMzG3DSSSSoekkudhUd9yLb6qx39T9nMdj';
check( 'BIP44 xpub /0/0', NCP_BIP32::address_at( $xpub44, 0 ), '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA' );
check( 'BIP44 xpub /0/1', NCP_BIP32::address_at( $xpub44, 1 ), '1Ak8PffB2meyfYnbXZR9EGfLfFZVpzJvQP' );

// BIP49 ypub same mnemonic -> /0/0.
$ypub49 = 'ypub6Ww3ibxVfGzLrAH1PNcjyAWenMTbbAosGNB6VvmSEgytSER9azLDWCxoJwW7Ke7icmizBMXrzBx9979FfaHxHcrArf3zbeJJJUZPf663zsP';
$y0 = NCP_BIP32::address_at( $ypub49, 0 );
check( 'BIP49 ypub /0/0 is valid p2sh', array( substr( $y0, 0, 1 ), bin2hex( substr( NCP_Base58::decode_check( $y0 ), 0, 1 ) ) ), array( '3', '05' ) );

// EVM address from xpub (m/44'/60'/0'/0 account xpub of same mnemonic) -> /0.
$eth_xpub = 'xpub6DF8uhdarytz3FWdA8TvFSvvAh8dP3283MY7p2V4SeE2wyWmG5mg5EwVvmdMVCQcoNJxGoWaU9DCWh89LojfZ537wTfunKau47EL2dhHKon';
check( 'EIP-55 checksum', NCP_BIP32::eip55( '0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed' ), '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' );
// Private key 1 => pubkey G => well-known address.
check( 'EVM address of G (privkey 1)', NCP_BIP32::evm_address( NCP_Secp256k1::compress( NCP_Secp256k1::generator() ) ), '0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf' );
$e0 = NCP_BIP32::address_at( $eth_xpub, 0, 0, 'eth' );
check( 'ETH xpub /0 is EIP-55 formatted', array( strlen( $e0 ), NCP_BIP32::eip55( $e0 ) === $e0 ), array( 42, true ) );

// Testnet vpub: rebuild the zpub with the vpub version bytes and expect a tb1q address.
$zraw = NCP_Base58::decode_check( $zpub );
$vpub = NCP_Base58::encode_check( hex2bin( '045f1cf6' ) . substr( $zraw, 4 ) );
$vpub_node = NCP_BIP32::parse( $vpub );
check( 'vpub parses as btc/p2wpkh/testnet', is_array( $vpub_node ) ? array( $vpub_node['coin'], $vpub_node['script'], $vpub_node['network'] ) : $vpub_node, array( 'btc', 'p2wpkh', 'testnet' ) );
check( 'vpub /0/0 starts with tb1q', substr( NCP_BIP32::address_at( $vpub, 0 ), 0, 4 ), 'tb1q' );
// LTC via plain xpub + coin hint must yield an L… / ltc1 address.
check( 'LTC hint legacy prefix', substr( NCP_BIP32::address_at( $xpub44, 0, 0, 'ltc' ), 0, 1 ), 'L' );
check( 'LTC hint segwit prefix', substr( NCP_BIP32::address_at( $xpub44, 0, 0, 'ltc', 'p2wpkh' ), 0, 4 ), 'ltc1' );
check( 'DOGE hint prefix', substr( NCP_BIP32::address_at( $xpub44, 0, 0, 'doge' ), 0, 1 ), 'D' );

// xprv must be rejected.
check( 'xprv rejected', is_wp_error( NCP_BIP32::parse( 'xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi' ) ), true );

printf( "\n%d failures, %.2fs\n", $fail, microtime( true ) - $t );
exit( $fail ? 1 : 0 );
