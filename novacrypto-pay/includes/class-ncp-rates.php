<?php
/**
 * Exchange-rate engine with multiple providers, fallback, caching, markup and
 * manual overrides. Rates are "fiat per 1 coin".
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Rates {

	public function providers() {
		return apply_filters(
			'ncp_rate_providers',
			array(
				'coingecko'     => __( 'CoinGecko (150+ fiat currencies)', 'novacrypto-pay' ),
				'coinpaprika'   => __( 'CoinPaprika', 'novacrypto-pay' ),
				'coinmarketcap' => __( 'CoinMarketCap (API key)', 'novacrypto-pay' ),
				'binance'       => __( 'Binance (USD/USDT pairs)', 'novacrypto-pay' ),
				'kraken'        => __( 'Kraken (USD/EUR/GBP pairs)', 'novacrypto-pay' ),
			)
		);
	}

	/**
	 * Get the rate for a coin in a fiat currency.
	 *
	 * @return array|WP_Error [ 'rate' => float, 'provider' => string, 'raw' => float ]
	 */
	public function get( $coin_id, $fiat = null ) {
		$fiat = strtoupper( $fiat ? $fiat : ncp()->settings->store_currency() );
		$coin = ncp()->coins->get( $coin_id );
		if ( ! $coin ) {
			return new WP_Error( 'ncp_no_coin', __( 'Unknown coin.', 'novacrypto-pay' ) );
		}

		$manual = (array) ncp()->settings->get( 'manual_rates', array() );
		if ( ! empty( $manual[ $coin_id ] ) && (float) $manual[ $coin_id ] > 0 ) {
			return $this->apply_markup( (float) $manual[ $coin_id ], 'manual', $coin_id );
		}
		if ( $coin['stable'] && in_array( $fiat, array( 'USD' ), true ) && apply_filters( 'ncp_stable_is_par', false, $coin_id ) ) {
			return $this->apply_markup( 1.0, 'par', $coin_id );
		}

		$key    = 'ncp_rate_' . md5( $coin_id . '|' . $fiat . '|' . ( ncp()->settings->is_testnet() ? 't' : 'm' ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached['rate'] ) ) {
			return $this->apply_markup( $cached['rate'], $cached['provider'], $coin_id );
		}

		$errors = array();
		foreach ( (array) ncp()->settings->get( 'rate_providers', array( 'coingecko' ) ) as $provider ) {
			$method = 'from_' . $provider;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			$rate = $this->$method( $coin, $fiat );
			if ( is_wp_error( $rate ) ) {
				$errors[] = $provider . ': ' . $rate->get_error_message();
				continue;
			}
			if ( $rate > 0 ) {
				set_transient( $key, array( 'rate' => $rate, 'provider' => $provider ), (int) ncp()->settings->get( 'rate_cache_ttl', 300 ) );
				update_option( 'ncp_last_rate_' . $coin_id . '_' . $fiat, array( 'rate' => $rate, 'provider' => $provider, 'time' => time() ), false );
				return $this->apply_markup( $rate, $provider, $coin_id );
			}
		}

		// Stale fallback (up to 24h) keeps checkout alive during API outages.
		$stale = get_option( 'ncp_last_rate_' . $coin_id . '_' . $fiat );
		if ( is_array( $stale ) && time() - $stale['time'] < DAY_IN_SECONDS ) {
			ncp()->logger->error( 'All rate providers failed for ' . $coin_id . '/' . $fiat . '; using stale rate.', 'rates', $errors );
			return $this->apply_markup( $stale['rate'], $stale['provider'] . ' (stale)', $coin_id );
		}
		ncp()->logger->error( 'No rate available for ' . $coin_id . '/' . $fiat, 'rates', $errors );
		return new WP_Error( 'ncp_rate_unavailable', __( 'Exchange rate temporarily unavailable. Please try again in a minute.', 'novacrypto-pay' ) );
	}

	private function apply_markup( $rate, $provider, $coin_id ) {
		$markup  = (float) ncp()->settings->get( 'global_markup', 0 );
		$per     = (array) ncp()->settings->get( 'coin_markup', array() );
		if ( isset( $per[ $coin_id ] ) && '' !== $per[ $coin_id ] ) {
			$markup += (float) $per[ $coin_id ];
		}
		// A positive markup means the customer pays MORE fiat per coin => fewer coins? No: rate is fiat per coin,
		// so to charge more crypto we LOWER the fiat-per-coin rate by the markup.
		$final = $rate * ( 1 - $markup / 100 );
		return array(
			'rate'     => (float) apply_filters( 'ncp_final_rate', $final, $coin_id, $provider ),
			'raw'      => (float) $rate,
			'provider' => $provider,
			'markup'   => $markup,
		);
	}

	/** Convert a fiat amount to crypto amount (string with coin decimals). */
	public function convert( $amount_fiat, $coin_id, $fiat = null ) {
		$r = $this->get( $coin_id, $fiat );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$coin = ncp()->coins->get( $coin_id );
		$dec  = min( 8, (int) $coin['decimals'] );
		$amt  = (float) $amount_fiat / $r['rate'];
		return array(
			'amount'   => number_format( $amt, $dec, '.', '' ),
			'rate'     => $r['rate'],
			'provider' => $r['provider'],
		);
	}

	private function http( $url, $headers = array() ) {
		$res = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => $headers, 'user-agent' => 'NovaCryptoPay/' . NCP_VERSION . '; ' . home_url() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ncp_http', 'HTTP ' . $code );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		return null === $json ? new WP_Error( 'ncp_json', 'Bad JSON' ) : $json;
	}

	protected function from_coingecko( $coin, $fiat ) {
		if ( ! $coin['gecko'] ) {
			return new WP_Error( 'ncp_unsupported', 'no gecko id' );
		}
		$vs   = strtolower( $fiat );
		$json = $this->http( 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode( $coin['gecko'] ) . '&vs_currencies=' . $vs );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return isset( $json[ $coin['gecko'] ][ $vs ] ) ? (float) $json[ $coin['gecko'] ][ $vs ] : new WP_Error( 'ncp_unsupported', 'fiat not supported' );
	}

	protected function from_coinpaprika( $coin, $fiat ) {
		if ( ! $coin['paprika'] ) {
			return new WP_Error( 'ncp_unsupported', 'no paprika id' );
		}
		$json = $this->http( 'https://api.coinpaprika.com/v1/tickers/' . rawurlencode( $coin['paprika'] ) . '?quotes=' . $fiat );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return isset( $json['quotes'][ $fiat ]['price'] ) ? (float) $json['quotes'][ $fiat ]['price'] : new WP_Error( 'ncp_unsupported', 'fiat not supported' );
	}

	protected function from_coinmarketcap( $coin, $fiat ) {
		$key = ncp()->settings->get( 'coinmarketcap_key' );
		if ( ! $key || ! $coin['cmc'] ) {
			return new WP_Error( 'ncp_unsupported', 'no key/symbol' );
		}
		$json = $this->http( 'https://pro-api.coinmarketcap.com/v2/cryptocurrency/quotes/latest?symbol=' . $coin['cmc'] . '&convert=' . $fiat, array( 'X-CMC_PRO_API_KEY' => $key ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$row = $json['data'][ $coin['cmc'] ][0] ?? null;
		return isset( $row['quote'][ $fiat ]['price'] ) ? (float) $row['quote'][ $fiat ]['price'] : new WP_Error( 'ncp_unsupported', 'no quote' );
	}

	protected function from_binance( $coin, $fiat ) {
		if ( 'USD' !== $fiat && 'USDT' !== $fiat ) {
			return new WP_Error( 'ncp_unsupported', 'USD only' );
		}
		if ( ! $coin['binance'] ) {
			return 'USDT' === $coin['symbol'] ? 1.0 : new WP_Error( 'ncp_unsupported', 'no pair' );
		}
		$json = $this->http( 'https://api.binance.com/api/v3/ticker/price?symbol=' . $coin['binance'] );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return isset( $json['price'] ) ? (float) $json['price'] : new WP_Error( 'ncp_unsupported', 'no price' );
	}

	protected function from_kraken( $coin, $fiat ) {
		if ( ! $coin['kraken'] || ! in_array( $fiat, array( 'USD', 'EUR', 'GBP', 'CAD', 'JPY', 'CHF', 'AUD' ), true ) ) {
			return new WP_Error( 'ncp_unsupported', 'pair not supported' );
		}
		$pair = $coin['kraken'] . $fiat;
		$json = $this->http( 'https://api.kraken.com/0/public/Ticker?pair=' . $pair );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( empty( $json['result'] ) ) {
			return new WP_Error( 'ncp_unsupported', 'no result' );
		}
		$first = reset( $json['result'] );
		return isset( $first['c'][0] ) ? (float) $first['c'][0] : new WP_Error( 'ncp_unsupported', 'no price' );
	}

	/** Estimated network fee in native units for display (best effort). */
	public function fee_estimate( $coin_id ) {
		$coin = ncp()->coins->get( $coin_id );
		if ( ! $coin ) {
			return null;
		}
		$key = 'ncp_fee_' . $coin_id;
		$fee = get_transient( $key );
		if ( false !== $fee ) {
			return $fee;
		}
		$fee = null;
		if ( 'btc' === $coin['network'] ) {
			$json = $this->http( ( ncp()->settings->is_testnet() ? 'https://mempool.space/testnet' : 'https://mempool.space' ) . '/api/v1/fees/recommended' );
			if ( ! is_wp_error( $json ) && isset( $json['halfHourFee'] ) ) {
				$fee = $json['halfHourFee'] * 140 / 1e8; // ~140 vB typical.
			}
		} elseif ( 'evm' === $coin['family'] ) {
			$nets = ncp()->coins->evm_networks();
			$rpc  = $nets[ $coin['network'] ]['rpc'] ?? '';
			if ( $rpc ) {
				$res = wp_remote_post( $rpc, array( 'timeout' => 6, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_gasPrice', 'params' => array() ) ) ) );
				if ( ! is_wp_error( $res ) ) {
					$j = json_decode( wp_remote_retrieve_body( $res ), true );
					if ( ! empty( $j['result'] ) ) {
						$gwei = hexdec( $j['result'] ) / 1e9;
						$gas  = $coin['contract'] ? 65000 : 21000;
						$fee  = $gwei * $gas / 1e9;
					}
				}
			}
		} elseif ( 'tron' === $coin['family'] ) {
			$fee = $coin['contract'] ? 15 : 1.1; // TRX rule of thumb.
		} elseif ( 'solana' === $coin['family'] ) {
			$fee = 0.000005;
		} elseif ( 'lightning' === $coin['family'] ) {
			$fee = 0;
		}
		set_transient( $key, $fee, 300 );
		return $fee;
	}

	/** List of fiat currencies supported by CoinGecko (used in settings). */
	public static function fiat_currencies() {
		return array( 'USD', 'EUR', 'GBP', 'JPY', 'CNY', 'AUD', 'CAD', 'CHF', 'INR', 'BRL', 'MXN', 'RUB', 'KRW', 'SGD', 'HKD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'TRY', 'ZAR', 'AED', 'SAR', 'ILS', 'THB', 'IDR', 'MYR', 'PHP', 'VND', 'PKR', 'BDT', 'NGN', 'ARS', 'CLP', 'TWD', 'UAH', 'KWD', 'BHD', 'LKR', 'MMK', 'GEL', 'XDR', 'BTC', 'ETH' );
	}
}
