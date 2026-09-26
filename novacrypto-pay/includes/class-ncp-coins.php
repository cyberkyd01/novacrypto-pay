<?php
/**
 * Coin / network registry.
 *
 * Every coin has: id, name, symbol, family (utxo|evm|tron|solana|lightning),
 * network (btc|ltc|doge|bch|eth|bsc|polygon|arbitrum|optimism|base|avalanche|tron|solana|lightning|custom),
 * chain_id (EVM), contract (token), decimals, gecko (CoinGecko id), paprika,
 * binance (symbol), kraken, explorer (tx url pattern), address_explorer,
 * hd (bool – supports xpub derivation), color, icon.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Coins {

	/** @var array */
	private $coins;

	public function __construct() {
		add_action( 'init', array( $this, 'build' ), 2 );
	}

	public function build() {
		$this->coins = $this->builtin();
		$this->coins = array_merge( $this->coins, $this->custom_networks(), $this->custom_tokens() );
		foreach ( $this->coins as $id => &$c ) {
			$c['id'] = $id;
			if ( isset( $c['icon'] ) && false === strpos( $c['icon'], '/' ) ) {
				$c['icon'] = NCP_URL . 'assets/img/' . $c['icon'] . '.svg';
			}
			$c       = wp_parse_args(
				$c,
				array(
					'contract'  => '',
					'chain_id'  => 0,
					'decimals'  => 8,
					'hd'        => false,
					'stable'    => false,
					'icon'      => NCP_URL . 'assets/img/' . ( $c['icon'] ?? $id ) . '.svg',
					'color'     => '#6b7280',
					'gecko'     => '',
					'paprika'   => '',
					'binance'   => '',
					'kraken'    => '',
					'cmc'       => '',
					'confirmations' => 1,
					'uri_scheme' => '',
				)
			);
		}
		unset( $c );
		$this->coins = apply_filters( 'ncp_coins', $this->coins );
	}

	public function all() {
		if ( null === $this->coins ) {
			$this->build();
		}
		return $this->coins;
	}

	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Coins which have a configured wallet AND are enabled. */
	public function enabled() {
		$out = array();
		foreach ( $this->all() as $id => $coin ) {
			if ( ncp()->wallets->is_enabled( $id ) ) {
				$out[ $id ] = $coin;
			}
		}
		return $out;
	}

	/** Coins offered at checkout (respecting the checkout_coins allow-list). */
	public function for_checkout( $allowed = array() ) {
		$enabled = $this->enabled();
		$filter  = $allowed ? $allowed : (array) ncp()->settings->get( 'checkout_coins', array() );
		if ( $filter ) {
			$enabled = array_intersect_key( $enabled, array_flip( $filter ) );
		}
		return $enabled;
	}

	public function network_label( $coin ) {
		$labels = array(
			'btc'       => 'Bitcoin',
			'ltc'       => 'Litecoin',
			'doge'      => 'Dogecoin',
			'bch'       => 'Bitcoin Cash',
			'eth'       => 'Ethereum',
			'bsc'       => 'BNB Smart Chain',
			'polygon'   => 'Polygon',
			'arbitrum'  => 'Arbitrum One',
			'optimism'  => 'Optimism',
			'base'      => 'Base',
			'avalanche' => 'Avalanche C-Chain',
			'tron'      => 'Tron',
			'solana'    => 'Solana',
			'lightning' => 'Lightning Network',
		);
		if ( isset( $labels[ $coin['network'] ] ) ) {
			return $labels[ $coin['network'] ];
		}
		$nets = (array) ncp()->settings->get( 'custom_networks', array() );
		foreach ( $nets as $n ) {
			if ( ( $n['id'] ?? '' ) === $coin['network'] ) {
				return $n['name'];
			}
		}
		return ucfirst( $coin['network'] );
	}

	/** Required confirmations for a coin (settings override builtin). */
	public function confirmations( $id ) {
		$coin = $this->get( $id );
		$over = (array) ncp()->settings->get( 'coin_confirmations', array() );
		if ( isset( $over[ $id ] ) && '' !== $over[ $id ] ) {
			return max( 0, (int) $over[ $id ] );
		}
		return $coin ? (int) $coin['confirmations'] : 1;
	}

	/** EVM networks map: network => [chain_id, rpc, explorer, native symbol]. */
	public function evm_networks() {
		$testnet = ncp()->settings->is_testnet();
		$nets    = array(
			'eth'       => array( 'chain_id' => $testnet ? 11155111 : 1, 'rpc' => $testnet ? 'https://ethereum-sepolia-rpc.publicnode.com' : 'https://ethereum-rpc.publicnode.com', 'explorer' => $testnet ? 'https://sepolia.etherscan.io' : 'https://etherscan.io', 'blockscout' => $testnet ? 'https://eth-sepolia.blockscout.com' : 'https://eth.blockscout.com', 'symbol' => 'ETH', 'name' => 'Ethereum' ),
			'bsc'       => array( 'chain_id' => $testnet ? 97 : 56, 'rpc' => $testnet ? 'https://data-seed-prebsc-1-s1.binance.org:8545' : 'https://bsc-rpc.publicnode.com', 'explorer' => $testnet ? 'https://testnet.bscscan.com' : 'https://bscscan.com', 'blockscout' => '', 'symbol' => 'BNB', 'name' => 'BNB Smart Chain' ),
			'polygon'   => array( 'chain_id' => $testnet ? 80002 : 137, 'rpc' => $testnet ? 'https://rpc-amoy.polygon.technology' : 'https://polygon-bor-rpc.publicnode.com', 'explorer' => $testnet ? 'https://amoy.polygonscan.com' : 'https://polygonscan.com', 'blockscout' => $testnet ? '' : 'https://polygon.blockscout.com', 'symbol' => 'POL', 'name' => 'Polygon' ),
			'arbitrum'  => array( 'chain_id' => $testnet ? 421614 : 42161, 'rpc' => $testnet ? 'https://sepolia-rollup.arbitrum.io/rpc' : 'https://arbitrum-one-rpc.publicnode.com', 'explorer' => $testnet ? 'https://sepolia.arbiscan.io' : 'https://arbiscan.io', 'blockscout' => $testnet ? '' : 'https://arbitrum.blockscout.com', 'symbol' => 'ETH', 'name' => 'Arbitrum One' ),
			'optimism'  => array( 'chain_id' => $testnet ? 11155420 : 10, 'rpc' => $testnet ? 'https://sepolia.optimism.io' : 'https://optimism-rpc.publicnode.com', 'explorer' => $testnet ? 'https://sepolia-optimism.etherscan.io' : 'https://optimistic.etherscan.io', 'blockscout' => $testnet ? '' : 'https://optimism.blockscout.com', 'symbol' => 'ETH', 'name' => 'Optimism' ),
			'base'      => array( 'chain_id' => $testnet ? 84532 : 8453, 'rpc' => $testnet ? 'https://sepolia.base.org' : 'https://base-rpc.publicnode.com', 'explorer' => $testnet ? 'https://sepolia.basescan.org' : 'https://basescan.org', 'blockscout' => $testnet ? 'https://base-sepolia.blockscout.com' : 'https://base.blockscout.com', 'symbol' => 'ETH', 'name' => 'Base' ),
			'avalanche' => array( 'chain_id' => $testnet ? 43113 : 43114, 'rpc' => $testnet ? 'https://api.avax-test.network/ext/bc/C/rpc' : 'https://avalanche-c-chain-rpc.publicnode.com', 'explorer' => $testnet ? 'https://testnet.snowtrace.io' : 'https://snowtrace.io', 'blockscout' => '', 'symbol' => 'AVAX', 'name' => 'Avalanche' ),
		);
		foreach ( (array) ncp()->settings->get( 'custom_networks', array() ) as $n ) {
			if ( empty( $n['id'] ) || empty( $n['chain_id'] ) ) {
				continue;
			}
			$nets[ sanitize_key( $n['id'] ) ] = array(
				'chain_id'   => (int) $n['chain_id'],
				'rpc'        => esc_url_raw( $n['rpc'] ?? '' ),
				'explorer'   => esc_url_raw( $n['explorer'] ?? '' ),
				'blockscout' => esc_url_raw( $n['blockscout'] ?? '' ),
				'symbol'     => strtoupper( sanitize_text_field( $n['symbol'] ?? 'ETH' ) ),
				'name'       => sanitize_text_field( $n['name'] ?? $n['id'] ),
			);
		}
		$custom_rpcs = (array) ncp()->settings->get( 'custom_rpcs', array() );
		foreach ( $nets as $k => &$n ) {
			if ( ! empty( $custom_rpcs[ $n['chain_id'] ] ) ) {
				$n['rpc'] = $custom_rpcs[ $n['chain_id'] ];
			}
		}
		return apply_filters( 'ncp_evm_networks', $nets );
	}

	private function builtin() {
		$t = ncp()->settings->is_testnet();
		$evm = $this->evm_networks();

		$coins = array(
			'btc'  => array( 'name' => 'Bitcoin', 'symbol' => 'BTC', 'family' => 'utxo', 'network' => 'btc', 'decimals' => 8, 'hd' => true, 'gecko' => 'bitcoin', 'paprika' => 'btc-bitcoin', 'binance' => 'BTCUSDT', 'kraken' => 'XBT', 'cmc' => 'BTC', 'color' => '#f7931a', 'confirmations' => 1, 'uri_scheme' => 'bitcoin', 'explorer' => $t ? 'https://mempool.space/testnet/tx/%s' : 'https://mempool.space/tx/%s', 'address_explorer' => $t ? 'https://mempool.space/testnet/address/%s' : 'https://mempool.space/address/%s' ),
			'ltc'  => array( 'name' => 'Litecoin', 'symbol' => 'LTC', 'family' => 'utxo', 'network' => 'ltc', 'decimals' => 8, 'hd' => true, 'gecko' => 'litecoin', 'paprika' => 'ltc-litecoin', 'binance' => 'LTCUSDT', 'kraken' => 'LTC', 'cmc' => 'LTC', 'color' => '#345d9d', 'confirmations' => 2, 'uri_scheme' => 'litecoin', 'explorer' => 'https://litecoinspace.org/tx/%s', 'address_explorer' => 'https://litecoinspace.org/address/%s' ),
			'doge' => array( 'name' => 'Dogecoin', 'symbol' => 'DOGE', 'family' => 'utxo', 'network' => 'doge', 'decimals' => 8, 'hd' => true, 'gecko' => 'dogecoin', 'paprika' => 'doge-dogecoin', 'binance' => 'DOGEUSDT', 'kraken' => 'XDG', 'cmc' => 'DOGE', 'color' => '#c2a633', 'confirmations' => 3, 'uri_scheme' => 'dogecoin', 'explorer' => 'https://dogechain.info/tx/%s', 'address_explorer' => 'https://dogechain.info/address/%s' ),
			'bch'  => array( 'name' => 'Bitcoin Cash', 'symbol' => 'BCH', 'family' => 'utxo', 'network' => 'bch', 'decimals' => 8, 'hd' => false, 'gecko' => 'bitcoin-cash', 'paprika' => 'bch-bitcoin-cash', 'binance' => 'BCHUSDT', 'kraken' => 'BCH', 'cmc' => 'BCH', 'color' => '#8dc351', 'confirmations' => 1, 'uri_scheme' => 'bitcoincash', 'explorer' => 'https://blockchair.com/bitcoin-cash/transaction/%s', 'address_explorer' => 'https://blockchair.com/bitcoin-cash/address/%s' ),
			'trx'  => array( 'name' => 'Tron', 'symbol' => 'TRX', 'family' => 'tron', 'network' => 'tron', 'decimals' => 6, 'gecko' => 'tron', 'paprika' => 'trx-tron', 'binance' => 'TRXUSDT', 'kraken' => 'TRX', 'cmc' => 'TRX', 'color' => '#eb0029', 'confirmations' => 1, 'explorer' => $t ? 'https://nile.tronscan.org/#/transaction/%s' : 'https://tronscan.org/#/transaction/%s', 'address_explorer' => 'https://tronscan.org/#/address/%s' ),
			'sol'  => array( 'name' => 'Solana', 'symbol' => 'SOL', 'family' => 'solana', 'network' => 'solana', 'decimals' => 9, 'gecko' => 'solana', 'paprika' => 'sol-solana', 'binance' => 'SOLUSDT', 'kraken' => 'SOL', 'cmc' => 'SOL', 'color' => '#9945ff', 'confirmations' => 1, 'uri_scheme' => 'solana', 'explorer' => 'https://solscan.io/tx/%s', 'address_explorer' => 'https://solscan.io/account/%s' ),
			'ln-btc' => array( 'name' => 'Bitcoin Lightning', 'symbol' => 'BTC', 'family' => 'lightning', 'network' => 'lightning', 'decimals' => 8, 'gecko' => 'bitcoin', 'paprika' => 'btc-bitcoin', 'binance' => 'BTCUSDT', 'kraken' => 'XBT', 'cmc' => 'BTC', 'color' => '#792ee5', 'confirmations' => 0, 'icon' => 'lightning', 'uri_scheme' => 'lightning', 'explorer' => '', 'address_explorer' => '' ),
		);

		// Native EVM coins.
		$native = array(
			'eth'   => array( 'net' => 'eth', 'name' => 'Ethereum', 'gecko' => 'ethereum', 'paprika' => 'eth-ethereum', 'binance' => 'ETHUSDT', 'kraken' => 'ETH', 'cmc' => 'ETH', 'color' => '#627eea', 'conf' => 3 ),
			'bnb'   => array( 'net' => 'bsc', 'name' => 'BNB', 'gecko' => 'binancecoin', 'paprika' => 'bnb-binance-coin', 'binance' => 'BNBUSDT', 'kraken' => '', 'cmc' => 'BNB', 'color' => '#f3ba2f', 'conf' => 5 ),
			'pol'   => array( 'net' => 'polygon', 'name' => 'Polygon', 'gecko' => 'polygon-ecosystem-token', 'paprika' => 'pol-polygon-ecosystem-token', 'binance' => 'POLUSDT', 'kraken' => 'POL', 'cmc' => 'POL', 'color' => '#8247e5', 'conf' => 20 ),
			'eth-arb' => array( 'net' => 'arbitrum', 'name' => 'Ethereum (Arbitrum)', 'gecko' => 'ethereum', 'paprika' => 'eth-ethereum', 'binance' => 'ETHUSDT', 'kraken' => 'ETH', 'cmc' => 'ETH', 'color' => '#28a0f0', 'conf' => 10, 'icon' => 'eth' ),
			'eth-op'  => array( 'net' => 'optimism', 'name' => 'Ethereum (Optimism)', 'gecko' => 'ethereum', 'paprika' => 'eth-ethereum', 'binance' => 'ETHUSDT', 'kraken' => 'ETH', 'cmc' => 'ETH', 'color' => '#ff0420', 'conf' => 10, 'icon' => 'eth' ),
			'eth-base' => array( 'net' => 'base', 'name' => 'Ethereum (Base)', 'gecko' => 'ethereum', 'paprika' => 'eth-ethereum', 'binance' => 'ETHUSDT', 'kraken' => 'ETH', 'cmc' => 'ETH', 'color' => '#0052ff', 'conf' => 10, 'icon' => 'eth' ),
			'avax'  => array( 'net' => 'avalanche', 'name' => 'Avalanche', 'gecko' => 'avalanche-2', 'paprika' => 'avax-avalanche', 'binance' => 'AVAXUSDT', 'kraken' => 'AVAX', 'cmc' => 'AVAX', 'color' => '#e84142', 'conf' => 3 ),
		);
		foreach ( $native as $id => $n ) {
			if ( ! isset( $evm[ $n['net'] ] ) ) {
				continue;
			}
			$coins[ $id ] = array(
				'name'     => $n['name'],
				'symbol'   => $evm[ $n['net'] ]['symbol'],
				'family'   => 'evm',
				'network'  => $n['net'],
				'chain_id' => $evm[ $n['net'] ]['chain_id'],
				'decimals' => 18,
				'hd'       => true,
				'gecko'    => $n['gecko'],
				'paprika'  => $n['paprika'],
				'binance'  => $n['binance'],
				'kraken'   => $n['kraken'],
				'cmc'      => $n['cmc'],
				'color'    => $n['color'],
				'icon'     => $n['icon'] ?? $id,
				'confirmations' => $n['conf'],
				'uri_scheme' => 'ethereum',
				'explorer' => $evm[ $n['net'] ]['explorer'] . '/tx/%s',
				'address_explorer' => $evm[ $n['net'] ]['explorer'] . '/address/%s',
			);
		}

		// Stablecoins (mainnet contracts; on testnet these are disabled unless custom tokens are configured).
		$stables = array(
			'usdt-eth'   => array( 'net' => 'eth', 'sym' => 'USDT', 'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'dec' => 6 ),
			'usdc-eth'   => array( 'net' => 'eth', 'sym' => 'USDC', 'contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'dec' => 6 ),
			'dai-eth'    => array( 'net' => 'eth', 'sym' => 'DAI', 'contract' => '0x6B175474E89094C44Da98b954EedeAC495271d0F', 'dec' => 18 ),
			'usdt-bsc'   => array( 'net' => 'bsc', 'sym' => 'USDT', 'contract' => '0x55d398326f99059fF775485246999027B3197955', 'dec' => 18 ),
			'usdc-bsc'   => array( 'net' => 'bsc', 'sym' => 'USDC', 'contract' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'dec' => 18 ),
			'usdt-polygon' => array( 'net' => 'polygon', 'sym' => 'USDT', 'contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'dec' => 6 ),
			'usdc-polygon' => array( 'net' => 'polygon', 'sym' => 'USDC', 'contract' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'dec' => 6 ),
			'usdt-arb'   => array( 'net' => 'arbitrum', 'sym' => 'USDT', 'contract' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'dec' => 6 ),
			'usdc-arb'   => array( 'net' => 'arbitrum', 'sym' => 'USDC', 'contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'dec' => 6 ),
			'usdt-op'    => array( 'net' => 'optimism', 'sym' => 'USDT', 'contract' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58', 'dec' => 6 ),
			'usdc-op'    => array( 'net' => 'optimism', 'sym' => 'USDC', 'contract' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85', 'dec' => 6 ),
			'usdc-base'  => array( 'net' => 'base', 'sym' => 'USDC', 'contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'dec' => 6 ),
			'usdt-avax'  => array( 'net' => 'avalanche', 'sym' => 'USDT', 'contract' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'dec' => 6 ),
			'usdc-avax'  => array( 'net' => 'avalanche', 'sym' => 'USDC', 'contract' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'dec' => 6 ),
		);
		$stable_meta = array(
			'USDT' => array( 'gecko' => 'tether', 'paprika' => 'usdt-tether', 'cmc' => 'USDT', 'color' => '#26a17b' ),
			'USDC' => array( 'gecko' => 'usd-coin', 'paprika' => 'usdc-usd-coin', 'cmc' => 'USDC', 'color' => '#2775ca' ),
			'DAI'  => array( 'gecko' => 'dai', 'paprika' => 'dai-dai', 'cmc' => 'DAI', 'color' => '#f5ac37' ),
		);
		$net_conf = array();
		foreach ( $native as $n ) {
			$net_conf[ $n['net'] ] = $n['conf'];
		}
		if ( ! $t ) {
			foreach ( $stables as $id => $s ) {
				if ( ! isset( $evm[ $s['net'] ] ) ) {
					continue;
				}
				$m            = $stable_meta[ $s['sym'] ];
				$coins[ $id ] = array(
					'name'     => $s['sym'] . ' (' . $evm[ $s['net'] ]['name'] . ')',
					'symbol'   => $s['sym'],
					'family'   => 'evm',
					'network'  => $s['net'],
					'chain_id' => $evm[ $s['net'] ]['chain_id'],
					'contract' => $s['contract'],
					'decimals' => $s['dec'],
					'hd'       => true,
					'stable'   => true,
					'gecko'    => $m['gecko'],
					'paprika'  => $m['paprika'],
					'binance'  => 'USDT' === $s['sym'] ? '' : $s['sym'] . 'USDT',
					'kraken'   => $s['sym'],
					'cmc'      => $m['cmc'],
					'color'    => $m['color'],
					'icon'     => strtolower( $s['sym'] ),
					'confirmations' => $net_conf[ $s['net'] ] ?? 5,
					'uri_scheme' => 'ethereum',
					'explorer' => $evm[ $s['net'] ]['explorer'] . '/tx/%s',
					'address_explorer' => $evm[ $s['net'] ]['explorer'] . '/address/%s',
				);
			}
			$coins['usdt-trx'] = array( 'name' => 'USDT (Tron TRC-20)', 'symbol' => 'USDT', 'family' => 'tron', 'network' => 'tron', 'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'decimals' => 6, 'stable' => true, 'gecko' => 'tether', 'paprika' => 'usdt-tether', 'cmc' => 'USDT', 'kraken' => 'USDT', 'color' => '#26a17b', 'icon' => 'usdt', 'confirmations' => 1, 'explorer' => 'https://tronscan.org/#/transaction/%s', 'address_explorer' => 'https://tronscan.org/#/address/%s' );
			$coins['usdc-trx'] = array( 'name' => 'USDC (Tron TRC-20)', 'symbol' => 'USDC', 'family' => 'tron', 'network' => 'tron', 'contract' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8', 'decimals' => 6, 'stable' => true, 'gecko' => 'usd-coin', 'paprika' => 'usdc-usd-coin', 'cmc' => 'USDC', 'kraken' => 'USDC', 'color' => '#2775ca', 'icon' => 'usdc', 'confirmations' => 1, 'explorer' => 'https://tronscan.org/#/transaction/%s', 'address_explorer' => 'https://tronscan.org/#/address/%s' );
			$coins['usdt-sol'] = array( 'name' => 'USDT (Solana SPL)', 'symbol' => 'USDT', 'family' => 'solana', 'network' => 'solana', 'contract' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 'decimals' => 6, 'stable' => true, 'gecko' => 'tether', 'paprika' => 'usdt-tether', 'cmc' => 'USDT', 'kraken' => 'USDT', 'color' => '#26a17b', 'icon' => 'usdt', 'confirmations' => 1, 'uri_scheme' => 'solana', 'explorer' => 'https://solscan.io/tx/%s', 'address_explorer' => 'https://solscan.io/account/%s' );
			$coins['usdc-sol'] = array( 'name' => 'USDC (Solana SPL)', 'symbol' => 'USDC', 'family' => 'solana', 'network' => 'solana', 'contract' => 'EPjFWdd5AufqSSqeM4qYbmLN7bE4d6Gz8sWJvnS5qGEV', 'decimals' => 6, 'stable' => true, 'gecko' => 'usd-coin', 'paprika' => 'usdc-usd-coin', 'cmc' => 'USDC', 'kraken' => 'USDC', 'color' => '#2775ca', 'icon' => 'usdc', 'confirmations' => 1, 'uri_scheme' => 'solana', 'explorer' => 'https://solscan.io/tx/%s', 'address_explorer' => 'https://solscan.io/account/%s' );
		}

		return $coins;
	}

	/** Custom EVM networks add a native coin each. */
	private function custom_networks() {
		$out = array();
		$evm = $this->evm_networks();
		foreach ( (array) ncp()->settings->get( 'custom_networks', array() ) as $n ) {
			$id = sanitize_key( $n['id'] ?? '' );
			if ( ! $id || ! isset( $evm[ $id ] ) ) {
				continue;
			}
			$out[ 'native-' . $id ] = array(
				'name'     => $evm[ $id ]['name'],
				'symbol'   => $evm[ $id ]['symbol'],
				'family'   => 'evm',
				'network'  => $id,
				'chain_id' => $evm[ $id ]['chain_id'],
				'decimals' => 18,
				'hd'       => true,
				'gecko'    => sanitize_text_field( $n['gecko'] ?? '' ),
				'color'    => sanitize_hex_color( $n['color'] ?? '' ) ?: '#6b7280',
				'icon'     => 'generic',
				'confirmations' => (int) ( $n['confirmations'] ?? 5 ),
				'uri_scheme' => 'ethereum',
				'explorer' => $evm[ $id ]['explorer'] . '/tx/%s',
				'address_explorer' => $evm[ $id ]['explorer'] . '/address/%s',
			);
		}
		return $out;
	}

	/** Custom tokens (ERC-20 / BEP-20 / TRC-20 / SPL) from settings. */
	private function custom_tokens() {
		$out = array();
		$evm = $this->evm_networks();
		foreach ( (array) ncp()->settings->get( 'custom_tokens', array() ) as $tk ) {
			$net = sanitize_key( $tk['network'] ?? '' );
			$sym = strtoupper( sanitize_text_field( $tk['symbol'] ?? '' ) );
			if ( ! $net || ! $sym || empty( $tk['contract'] ) ) {
				continue;
			}
			$id     = 'tok-' . strtolower( $sym ) . '-' . $net;
			$family = 'tron' === $net ? 'tron' : ( 'solana' === $net ? 'solana' : 'evm' );
			if ( 'evm' === $family && ! isset( $evm[ $net ] ) ) {
				continue;
			}
			$explorer = 'evm' === $family ? $evm[ $net ]['explorer'] : ( 'tron' === $net ? 'https://tronscan.org/#' : 'https://solscan.io' );
			$out[ $id ] = array(
				'name'     => sanitize_text_field( $tk['name'] ?? $sym ) . ' (' . ( 'evm' === $family ? $evm[ $net ]['name'] : ucfirst( $net ) ) . ')',
				'symbol'   => $sym,
				'family'   => $family,
				'network'  => $net,
				'chain_id' => 'evm' === $family ? $evm[ $net ]['chain_id'] : 0,
				'contract' => sanitize_text_field( $tk['contract'] ),
				'decimals' => (int) ( $tk['decimals'] ?? 18 ),
				'hd'       => 'evm' === $family,
				'stable'   => ! empty( $tk['stable'] ),
				'gecko'    => sanitize_text_field( $tk['gecko'] ?? '' ),
				'color'    => sanitize_hex_color( $tk['color'] ?? '' ) ?: '#6b7280',
				'icon'     => 'generic',
				'confirmations' => (int) ( $tk['confirmations'] ?? 5 ),
				'uri_scheme' => 'evm' === $family ? 'ethereum' : ( 'solana' === $net ? 'solana' : '' ),
				'explorer' => $explorer . ( 'tron' === $net ? '/transaction/%s' : '/tx/%s' ),
				'address_explorer' => $explorer . ( 'tron' === $net ? '/address/%s' : ( 'solana' === $net ? '/account/%s' : '/address/%s' ) ),
			);
		}
		return $out;
	}

	/** Format a crypto amount trimmed to significant decimals. */
	public static function format_amount( $amount, $decimals = 8 ) {
		$s = number_format( (float) $amount, max( 0, min( 8, (int) $decimals ) ), '.', '' );
		$s = rtrim( rtrim( $s, '0' ), '.' );
		return '' === $s ? '0' : $s;
	}
}
