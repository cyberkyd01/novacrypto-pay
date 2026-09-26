<?php
/**
 * Blockchain explorers: fetch incoming transactions for an address.
 *
 * Every provider returns a normalised list:
 *   [ [ 'txid' => string, 'amount' => float (coin units), 'confirmations' => int, 'time' => int|null ], ... ]
 *
 * Providers are tried in order until one succeeds (automatic fallback).
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Explorers {

	private $timeout = 12;

	/**
	 * @return array|WP_Error
	 */
	public function fetch( array $coin, $address, $payment = null ) {
		$providers = $this->providers_for( $coin );
		$errors    = array();
		foreach ( $providers as $provider ) {
			$method = 'p_' . $provider;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			$res = $this->$method( $coin, $address, $payment );
			if ( is_wp_error( $res ) ) {
				$errors[ $provider ] = $res->get_error_message();
				ncp()->logger->debug( "Explorer {$provider} failed for {$coin['id']}: " . $res->get_error_message(), 'explorer' );
				continue;
			}
			ncp()->logger->debug( "Explorer {$provider} returned " . count( $res ) . " tx(s) for {$address}", 'explorer' );
			return apply_filters( 'ncp_explorer_result', $res, $coin, $address, $provider );
		}
		return new WP_Error( 'ncp_explorers_failed', implode( '; ', array_map( function ( $k, $v ) { return "$k: $v"; }, array_keys( $errors ), $errors ) ) );
	}

	public function providers_for( array $coin ) {
		$testnet = ncp()->settings->is_testnet();
		switch ( $coin['family'] ) {
			case 'utxo':
				$map = array(
					'btc'  => array( 'mempool', 'blockstream', 'blockcypher', 'blockchair' ),
					'ltc'  => array( 'litecoinspace', 'blockcypher', 'blockchair' ),
					'doge' => array( 'blockcypher', 'blockchair' ),
					'bch'  => array( 'blockchair', 'blockcypher' ),
				);
				$list = $map[ $coin['network'] ] ?? array( 'blockchair' );
				if ( $testnet ) {
					$list = array_values( array_intersect( $list, array( 'mempool', 'blockstream', 'blockcypher' ) ) );
				}
				break;
			case 'evm':
				$list = array( 'etherscan', 'evm_rpc', 'blockscout' );
				break;
			case 'tron':
				$list = array( 'trongrid' );
				break;
			case 'solana':
				$list = array( 'solana_rpc' );
				break;
			case 'lightning':
				$list = array( 'lightning' );
				break;
			default:
				$list = array();
		}
		return apply_filters( 'ncp_explorer_providers', $list, $coin );
	}

	/* ---------- HTTP helpers ---------- */

	private function get_json( $url, $headers = array() ) {
		$res = wp_remote_get( $url, array( 'timeout' => $this->timeout, 'headers' => $headers, 'user-agent' => 'NovaCryptoPay/' . NCP_VERSION ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( 404 === $code ) {
			return array(); // Unknown address == no transactions on most explorers.
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ncp_http', 'HTTP ' . $code . ' ' . substr( $body, 0, 120 ) );
		}
		$json = json_decode( $body, true );
		return null === $json ? new WP_Error( 'ncp_json', 'Invalid JSON' ) : $json;
	}

	public function rpc( $url, $method, $params = array(), $id = 1 ) {
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'ncp_json', 'Invalid JSON-RPC response' );
		}
		if ( isset( $json['error'] ) ) {
			return new WP_Error( 'ncp_rpc', is_array( $json['error'] ) ? ( $json['error']['message'] ?? 'RPC error' ) : (string) $json['error'] );
		}
		return $json['result'] ?? null;
	}

	/* ---------- UTXO: mempool.space-style ---------- */

	private function mempool_style( $base, $address ) {
		$tip = $this->get_json( $base . '/api/blocks/tip/height' );
		if ( is_wp_error( $tip ) ) {
			return $tip;
		}
		$txs = $this->get_json( $base . '/api/address/' . rawurlencode( $address ) . '/txs' );
		if ( is_wp_error( $txs ) ) {
			return $txs;
		}
		$out = array();
		foreach ( (array) $txs as $tx ) {
			$amount = 0;
			foreach ( $tx['vout'] ?? array() as $vout ) {
				if ( isset( $vout['scriptpubkey_address'] ) && strtolower( $vout['scriptpubkey_address'] ) === strtolower( $address ) ) {
					$amount += (int) $vout['value'];
				}
			}
			if ( $amount <= 0 ) {
				continue;
			}
			$confirmed = ! empty( $tx['status']['confirmed'] );
			$out[]     = array(
				'txid'          => $tx['txid'],
				'amount'        => $amount / 1e8,
				'confirmations' => $confirmed ? max( 1, (int) $tip - (int) $tx['status']['block_height'] + 1 ) : 0,
				'time'          => $confirmed ? (int) $tx['status']['block_time'] : null,
			);
		}
		return $out;
	}

	protected function p_mempool( $coin, $address ) {
		return $this->mempool_style( ncp()->settings->is_testnet() ? 'https://mempool.space/testnet' : 'https://mempool.space', $address );
	}

	protected function p_blockstream( $coin, $address ) {
		return $this->mempool_style( ncp()->settings->is_testnet() ? 'https://blockstream.info/testnet' : 'https://blockstream.info', $address );
	}

	protected function p_litecoinspace( $coin, $address ) {
		return $this->mempool_style( 'https://litecoinspace.org', $address );
	}

	/* ---------- UTXO: BlockCypher ---------- */

	protected function p_blockcypher( $coin, $address ) {
		$chain = array( 'btc' => 'btc', 'ltc' => 'ltc', 'doge' => 'doge', 'bch' => 'bch' )[ $coin['network'] ] ?? null;
		if ( ! $chain ) {
			return new WP_Error( 'ncp_unsupported', 'chain' );
		}
		$net   = ncp()->settings->is_testnet() ? ( 'btc' === $chain ? 'test3' : 'main' ) : 'main';
		$token = ncp()->settings->get( 'blockcypher_token' );
		$addr  = 'bch' === $chain ? preg_replace( '/^bitcoincash:/i', '', $address ) : $address;
		$url   = "https://api.blockcypher.com/v1/{$chain}/{$net}/addrs/" . rawurlencode( $addr ) . '/full?limit=50&txlimit=50' . ( $token ? '&token=' . rawurlencode( $token ) : '' );
		$json  = $this->get_json( $url );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$out = array();
		foreach ( $json['txs'] ?? array() as $tx ) {
			$amount = 0;
			foreach ( $tx['outputs'] ?? array() as $o ) {
				foreach ( (array) ( $o['addresses'] ?? array() ) as $a ) {
					if ( strtolower( $a ) === strtolower( $addr ) ) {
						$amount += (int) $o['value'];
					}
				}
			}
			if ( $amount <= 0 ) {
				continue;
			}
			$out[] = array(
				'txid'          => $tx['hash'],
				'amount'        => $amount / 1e8,
				'confirmations' => (int) ( $tx['confirmations'] ?? 0 ),
				'time'          => ! empty( $tx['confirmed'] ) ? strtotime( $tx['confirmed'] ) : ( ! empty( $tx['received'] ) ? strtotime( $tx['received'] ) : null ),
			);
		}
		return $out;
	}

	/* ---------- UTXO: Blockchair ---------- */

	protected function p_blockchair( $coin, $address ) {
		$chain = array( 'btc' => 'bitcoin', 'ltc' => 'litecoin', 'doge' => 'dogecoin', 'bch' => 'bitcoin-cash' )[ $coin['network'] ] ?? null;
		if ( ! $chain ) {
			return new WP_Error( 'ncp_unsupported', 'chain' );
		}
		$json = $this->get_json( "https://api.blockchair.com/{$chain}/dashboards/address/" . rawurlencode( $address ) . '?limit=20' );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$data = $json['data'][ $address ] ?? ( is_array( $json['data'] ?? null ) ? reset( $json['data'] ) : null );
		if ( ! $data ) {
			return array();
		}
		$best   = (int) ( $json['context']['state'] ?? 0 );
		$hashes = array_slice( (array) ( $data['transactions'] ?? array() ), 0, 20 );
		if ( ! $hashes ) {
			return array();
		}
		$txj = $this->get_json( "https://api.blockchair.com/{$chain}/dashboards/transactions/" . implode( ',', array_map( 'rawurlencode', $hashes ) ) );
		if ( is_wp_error( $txj ) ) {
			return $txj;
		}
		$out = array();
		foreach ( (array) ( $txj['data'] ?? array() ) as $hash => $tx ) {
			$amount = 0;
			foreach ( $tx['outputs'] ?? array() as $o ) {
				$recipient = $o['recipient'] ?? '';
				if ( strtolower( $recipient ) === strtolower( preg_replace( '/^bitcoincash:/i', '', $address ) ) ) {
					$amount += (int) $o['value'];
				}
			}
			if ( $amount <= 0 ) {
				continue;
			}
			$block = (int) ( $tx['transaction']['block_id'] ?? -1 );
			$out[] = array(
				'txid'          => $hash,
				'amount'        => $amount / 1e8,
				'confirmations' => $block > 0 ? max( 1, $best - $block + 1 ) : 0,
				'time'          => ! empty( $tx['transaction']['time'] ) ? strtotime( $tx['transaction']['time'] . ' UTC' ) : null,
			);
		}
		return $out;
	}

	/* ---------- EVM ---------- */

	private function etherscan_like( $base, $coin, $address, $key = '' ) {
		$addr = strtolower( $address );
		if ( $coin['contract'] ) {
			$url = $base . '&module=account&action=tokentx&contractaddress=' . $coin['contract'] . '&address=' . $addr . '&page=1&offset=50&sort=desc';
		} else {
			$url = $base . '&module=account&action=txlist&address=' . $addr . '&page=1&offset=50&sort=desc';
		}
		if ( $key ) {
			$url .= '&apikey=' . rawurlencode( $key );
		}
		$json = $this->get_json( $url );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( isset( $json['status'] ) && '0' === (string) $json['status'] && ! empty( $json['message'] ) && false === stripos( $json['message'], 'No transactions' ) ) {
			return new WP_Error( 'ncp_explorer', $json['message'] . ' ' . ( is_string( $json['result'] ) ? $json['result'] : '' ) );
		}
		$out = array();
		foreach ( (array) ( $json['result'] ?? array() ) as $tx ) {
			if ( ! is_array( $tx ) || strtolower( $tx['to'] ?? '' ) !== $addr ) {
				continue;
			}
			if ( isset( $tx['isError'] ) && '1' === (string) $tx['isError'] ) {
				continue;
			}
			if ( isset( $tx['txreceipt_status'] ) && '0' === (string) $tx['txreceipt_status'] ) {
				continue;
			}
			$dec   = $coin['contract'] ? (int) ( $tx['tokenDecimal'] ?? $coin['decimals'] ) : 18;
			$value = $this->from_units( $tx['value'] ?? '0', $dec );
			if ( $value <= 0 ) {
				continue;
			}
			$out[] = array(
				'txid'          => $tx['hash'],
				'amount'        => $value,
				'confirmations' => (int) ( $tx['confirmations'] ?? 1 ),
				'time'          => (int) ( $tx['timeStamp'] ?? 0 ) ?: null,
			);
		}
		return $out;
	}

	protected function p_etherscan( $coin, $address ) {
		$key = ncp()->settings->get( 'etherscan_key' );
		if ( ! $key ) {
			return new WP_Error( 'ncp_nokey', 'no Etherscan API key' );
		}
		return $this->etherscan_like( 'https://api.etherscan.io/v2/api?chainid=' . (int) $coin['chain_id'], $coin, $address, $key );
	}

	protected function p_blockscout( $coin, $address ) {
		$nets = ncp()->coins->evm_networks();
		$base = $nets[ $coin['network'] ]['blockscout'] ?? '';
		if ( ! $base ) {
			return new WP_Error( 'ncp_unsupported', 'no blockscout for network' );
		}
		return $this->etherscan_like( rtrim( $base, '/' ) . '/api?x=1', $coin, $address );
	}

	/**
	 * Key-free JSON-RPC detection (no explorer account needed):
	 *  - tokens: scans ERC-20 Transfer logs to the address (works for shared and unique addresses)
	 *  - native coin on a unique (HD) address: watches the balance
	 *  - known transactions: refreshes confirmations from the receipt
	 */
	protected function p_evm_rpc( $coin, $address, $payment = null ) {
		$nets = ncp()->coins->evm_networks();
		$rpc  = $nets[ $coin['network'] ]['rpc'] ?? '';
		if ( ! $rpc ) {
			return new WP_Error( 'ncp_unsupported', 'no RPC' );
		}
		$latest_hex = $this->rpc( $rpc, 'eth_blockNumber' );
		if ( is_wp_error( $latest_hex ) ) {
			return $latest_hex;
		}
		$latest = hexdec( $latest_hex );
		$out    = array();

		// Refresh confirmations of transactions we already know about.
		if ( $payment ) {
			foreach ( $payment->txs as $known ) {
				if ( 0 !== strpos( $known->txid, '0x' ) ) {
					continue;
				}
				$receipt = $this->rpc( $rpc, 'eth_getTransactionReceipt', array( $known->txid ) );
				if ( is_array( $receipt ) && ! empty( $receipt['blockNumber'] ) && '0x1' === ( $receipt['status'] ?? '0x1' ) ) {
					$out[] = array( 'txid' => $known->txid, 'amount' => (float) $known->amount, 'confirmations' => max( 1, $latest - hexdec( $receipt['blockNumber'] ) + 1 ), 'time' => null );
				}
			}
		}

		if ( $coin['contract'] ) {
			// ERC-20 Transfer(address,address,uint256) logs where topic2 == our address.
			$topic_to = '0x' . str_pad( substr( strtolower( $address ), 2 ), 64, '0', STR_PAD_LEFT );
			$range    = (int) apply_filters( 'ncp_evm_log_range', 4000, $coin );
			$logs     = $this->rpc( $rpc, 'eth_getLogs', array( array(
				'fromBlock' => '0x' . dechex( max( 0, $latest - $range ) ),
				'toBlock'   => 'latest',
				'address'   => $coin['contract'],
				'topics'    => array( '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', null, $topic_to ),
			) ) );
			if ( is_wp_error( $logs ) ) {
				return $out ? $out : $logs;
			}
			$known_ids = wp_list_pluck( $out, 'txid' );
			foreach ( (array) $logs as $log ) {
				if ( empty( $log['transactionHash'] ) || in_array( $log['transactionHash'], $known_ids, true ) || ! empty( $log['removed'] ) ) {
					continue;
				}
				$hex   = ltrim( substr( (string) ( $log['data'] ?? '0x0' ), 2 ), '0' );
				$value = $this->from_units( '' === $hex ? '0' : NCP_Math::from_hex( $hex ), (int) $coin['decimals'] );
				if ( $value <= 0 ) {
					continue;
				}
				$block = hexdec( $log['blockNumber'] ?? '0x0' );
				$out[] = array( 'txid' => $log['transactionHash'], 'amount' => $value, 'confirmations' => $block ? max( 1, $latest - $block + 1 ) : 0, 'time' => null );
			}
			return $out;
		}

		if ( ! $payment || empty( $payment->meta['unique_address'] ) ) {
			return $out ? $out : new WP_Error( 'ncp_unsupported', 'native-coin RPC detection needs a unique (HD) address; add an Etherscan key or xpub' );
		}
		$balance = $this->evm_balance( $rpc, $coin, $address, 'latest' );
		if ( is_wp_error( $balance ) ) {
			return $balance;
		}
		if ( $balance <= 0 ) {
			return $out;
		}
		$req  = max( 1, (int) $payment->required_confirmations );
		$old  = $this->evm_balance( $rpc, $coin, $address, '0x' . dechex( max( 0, $latest - $req ) ) );
		$conf = ( ! is_wp_error( $old ) && $old >= $balance ) ? $req : 0;
		$out[] = array( 'txid' => 'rpc:' . strtolower( $address ), 'amount' => $balance, 'confirmations' => $conf, 'time' => time() );
		return $out;
	}

	/**
	 * Verify a single transaction hash (customer-submitted) directly.
	 *
	 * @return array|null normalised tx or null when it does not pay this address.
	 */
	public function lookup_tx( array $coin, $txid, $address ) {
		$txid = trim( $txid );
		if ( 'evm' === $coin['family'] ) {
			$nets = ncp()->coins->evm_networks();
			$rpc  = $nets[ $coin['network'] ]['rpc'] ?? '';
			$tx   = $rpc ? $this->rpc( $rpc, 'eth_getTransactionByHash', array( $txid ) ) : null;
			if ( ! is_array( $tx ) ) {
				return null;
			}
			$receipt = $this->rpc( $rpc, 'eth_getTransactionReceipt', array( $txid ) );
			$latest  = hexdec( (string) $this->rpc( $rpc, 'eth_blockNumber' ) );
			$conf    = is_array( $receipt ) && ! empty( $receipt['blockNumber'] ) ? max( 1, $latest - hexdec( $receipt['blockNumber'] ) + 1 ) : 0;
			if ( is_array( $receipt ) && isset( $receipt['status'] ) && '0x1' !== $receipt['status'] ) {
				return null;
			}
			if ( $coin['contract'] ) {
				$topic_to = '0x' . str_pad( substr( strtolower( $address ), 2 ), 64, '0', STR_PAD_LEFT );
				foreach ( (array) ( $receipt['logs'] ?? array() ) as $log ) {
					if ( strtolower( $log['address'] ?? '' ) === strtolower( $coin['contract'] ) && ( $log['topics'][2] ?? '' ) === $topic_to ) {
						$hex = ltrim( substr( (string) $log['data'], 2 ), '0' );
						return array( 'txid' => $txid, 'amount' => $this->from_units( '' === $hex ? '0' : NCP_Math::from_hex( $hex ), (int) $coin['decimals'] ), 'confirmations' => $conf, 'time' => null );
					}
				}
				return null;
			}
			if ( strtolower( $tx['to'] ?? '' ) !== strtolower( $address ) ) {
				return null;
			}
			$hex = ltrim( substr( (string) ( $tx['value'] ?? '0x0' ), 2 ), '0' );
			return array( 'txid' => $txid, 'amount' => $this->from_units( '' === $hex ? '0' : NCP_Math::from_hex( $hex ), 18 ), 'confirmations' => $conf, 'time' => null );
		}
		if ( 'utxo' === $coin['family'] && in_array( $coin['network'], array( 'btc', 'ltc' ), true ) ) {
			$base = 'ltc' === $coin['network'] ? 'https://litecoinspace.org' : ( ncp()->settings->is_testnet() ? 'https://mempool.space/testnet' : 'https://mempool.space' );
			$tx   = $this->get_json( $base . '/api/tx/' . rawurlencode( $txid ) );
			$tip  = $this->get_json( $base . '/api/blocks/tip/height' );
			if ( is_wp_error( $tx ) || empty( $tx['vout'] ) ) {
				return null;
			}
			$amount = 0;
			foreach ( $tx['vout'] as $v ) {
				if ( strtolower( $v['scriptpubkey_address'] ?? '' ) === strtolower( $address ) ) {
					$amount += (int) $v['value'];
				}
			}
			if ( $amount <= 0 ) {
				return null;
			}
			$confirmed = ! empty( $tx['status']['confirmed'] );
			return array( 'txid' => $txid, 'amount' => $amount / 1e8, 'confirmations' => $confirmed && ! is_wp_error( $tip ) ? max( 1, (int) $tip - (int) $tx['status']['block_height'] + 1 ) : 0, 'time' => $confirmed ? (int) $tx['status']['block_time'] : null );
		}
		return null; // Other families are covered by the address explorers.
	}

	private function evm_balance( $rpc, $coin, $address, $block ) {
		if ( $coin['contract'] ) {
			$data = '0x70a08231' . str_pad( substr( strtolower( $address ), 2 ), 64, '0', STR_PAD_LEFT );
			$res  = $this->rpc( $rpc, 'eth_call', array( array( 'to' => $coin['contract'], 'data' => $data ), $block ) );
		} else {
			$res = $this->rpc( $rpc, 'eth_getBalance', array( $address, $block ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$hex = ltrim( substr( (string) $res, 2 ), '0' );
		return $this->from_units( '' === $hex ? '0' : NCP_Math::from_hex( $hex ), $coin['contract'] ? (int) $coin['decimals'] : 18 );
	}

	/* ---------- Tron ---------- */

	protected function p_trongrid( $coin, $address, $payment = null ) {
		$base    = ncp()->settings->is_testnet() ? 'https://nile.trongrid.io' : 'https://api.trongrid.io';
		$headers = array();
		if ( ncp()->settings->get( 'trongrid_key' ) ) {
			$headers['TRON-PRO-API-KEY'] = ncp()->settings->get( 'trongrid_key' );
		}
		$out = array();
		if ( $coin['contract'] ) {
			$json = $this->get_json( $base . '/v1/accounts/' . rawurlencode( $address ) . '/transactions/trc20?only_to=true&limit=50&contract_address=' . rawurlencode( $coin['contract'] ), $headers );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			foreach ( (array) ( $json['data'] ?? array() ) as $tx ) {
				if ( ( $tx['to'] ?? '' ) !== $address ) {
					continue;
				}
				$dec   = (int) ( $tx['token_info']['decimals'] ?? $coin['decimals'] );
				$out[] = array(
					'txid'          => $tx['transaction_id'],
					'amount'        => $this->from_units( $tx['value'] ?? '0', $dec ),
					'confirmations' => 1, // TronGrid trc20 endpoint returns confirmed transfers.
					'time'          => (int) ( ( $tx['block_timestamp'] ?? 0 ) / 1000 ) ?: null,
				);
			}
			return $out;
		}
		$json = $this->get_json( $base . '/v1/accounts/' . rawurlencode( $address ) . '/transactions?only_to=true&limit=50', $headers );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$hex_addr = strtolower( bin2hex( NCP_Base58::decode_check( $address ) ) );
		foreach ( (array) ( $json['data'] ?? array() ) as $tx ) {
			$c = $tx['raw_data']['contract'][0] ?? null;
			if ( ! $c || 'TransferContract' !== ( $c['type'] ?? '' ) ) {
				continue;
			}
			if ( strtolower( $c['parameter']['value']['to_address'] ?? '' ) !== $hex_addr ) {
				continue;
			}
			if ( isset( $tx['ret'][0]['contractRet'] ) && 'SUCCESS' !== $tx['ret'][0]['contractRet'] ) {
				continue;
			}
			$out[] = array(
				'txid'          => $tx['txID'],
				'amount'        => (int) ( $c['parameter']['value']['amount'] ?? 0 ) / 1e6,
				'confirmations' => ! empty( $tx['blockNumber'] ) ? 1 : 0,
				'time'          => (int) ( ( $tx['block_timestamp'] ?? 0 ) / 1000 ) ?: null,
			);
		}
		return $out;
	}

	/* ---------- Solana ---------- */

	protected function p_solana_rpc( $coin, $address, $payment = null ) {
		$rpc  = ncp()->settings->get( 'solana_rpc', 'https://api.mainnet-beta.solana.com' );
		if ( ncp()->settings->is_testnet() && false !== strpos( $rpc, 'mainnet' ) ) {
			$rpc = 'https://api.devnet.solana.com';
		}
		$sigs = $this->rpc( $rpc, 'getSignaturesForAddress', array( $address, array( 'limit' => 25 ) ) );
		if ( is_wp_error( $sigs ) ) {
			return $sigs;
		}
		$seen = $payment ? (array) ( $payment->meta['sol_seen'] ?? array() ) : array();
		$out  = array();
		$new_seen = $seen;
		foreach ( (array) $sigs as $s ) {
			if ( ! empty( $s['err'] ) ) {
				continue;
			}
			$sig = $s['signature'];
			if ( isset( $seen[ $sig ] ) ) {
				$out[] = $seen[ $sig ];
				continue;
			}
			if ( $payment && ! empty( $s['blockTime'] ) && $s['blockTime'] < strtotime( $payment->created_at . ' UTC' ) - 900 ) {
				continue; // Older than this payment.
			}
			$tx = $this->rpc( $rpc, 'getTransaction', array( $sig, array( 'encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0, 'commitment' => 'confirmed' ) ) );
			if ( is_wp_error( $tx ) || ! is_array( $tx ) ) {
				continue;
			}
			$amount = 0.0;
			if ( $coin['contract'] ) {
				$pre  = 0.0;
				$post = 0.0;
				foreach ( (array) ( $tx['meta']['preTokenBalances'] ?? array() ) as $b ) {
					if ( ( $b['owner'] ?? '' ) === $address && ( $b['mint'] ?? '' ) === $coin['contract'] ) {
						$pre += (float) ( $b['uiTokenAmount']['uiAmount'] ?? 0 );
					}
				}
				foreach ( (array) ( $tx['meta']['postTokenBalances'] ?? array() ) as $b ) {
					if ( ( $b['owner'] ?? '' ) === $address && ( $b['mint'] ?? '' ) === $coin['contract'] ) {
						$post += (float) ( $b['uiTokenAmount']['uiAmount'] ?? 0 );
					}
				}
				$amount = $post - $pre;
			} else {
				$keys = $tx['transaction']['message']['accountKeys'] ?? array();
				foreach ( $keys as $i => $k ) {
					$pk = is_array( $k ) ? ( $k['pubkey'] ?? '' ) : $k;
					if ( $pk === $address ) {
						$amount = ( (int) ( $tx['meta']['postBalances'][ $i ] ?? 0 ) - (int) ( $tx['meta']['preBalances'][ $i ] ?? 0 ) ) / 1e9;
						break;
					}
				}
			}
			if ( $amount <= 0 ) {
				$new_seen[ $sig ] = null;
				continue;
			}
			$status = $s['confirmationStatus'] ?? 'confirmed';
			$row    = array(
				'txid'          => $sig,
				'amount'        => $amount,
				'confirmations' => 'processed' === $status ? 0 : 1,
				'time'          => (int) ( $s['blockTime'] ?? 0 ) ?: null,
			);
			$out[] = $row;
			if ( 'finalized' === $status ) {
				$new_seen[ $sig ] = $row;
			}
		}
		if ( $payment && $new_seen !== $seen ) {
			ncp()->payments->update_meta( $payment, 'sol_seen', array_filter( $new_seen ) );
		}
		return array_values( array_filter( $out ) );
	}

	/* ---------- Lightning ---------- */

	protected function p_lightning( $coin, $address, $payment = null ) {
		if ( ! $payment ) {
			return array();
		}
		$res = NCP_Lightning::check( $payment );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res['paid'] ) {
			return array();
		}
		return array(
			array(
				'txid'          => 'ln:' . ( $payment->meta['ln_payment_hash'] ?? md5( $payment->address ) ),
				'amount'        => (float) $payment->amount_crypto,
				'confirmations' => 1,
				'time'          => time(),
			),
		);
	}

	/* ---------- utils ---------- */

	/** Convert an integer string in base units to a float in coin units. */
	private function from_units( $int, $decimals ) {
		$int = (string) $int;
		if ( ! ctype_digit( $int ) ) {
			return (float) $int;
		}
		if ( function_exists( 'bcdiv' ) ) {
			return (float) bcdiv( $int, bcpow( '10', (string) $decimals, 0 ), 18 );
		}
		if ( strlen( $int ) <= $decimals ) {
			return (float) ( '0.' . str_pad( $int, $decimals, '0', STR_PAD_LEFT ) );
		}
		return (float) ( substr( $int, 0, -$decimals ) . '.' . substr( $int, -$decimals ) );
	}
}
