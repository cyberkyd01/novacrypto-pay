<?php
/**
 * Tabbed settings page renderer.
 *
 * @package NovaCryptoPay
 */

defined( 'ABSPATH' ) || exit;

class NCP_Settings_Page {

	private $s;

	public function __construct() {
		$this->s = ncp()->settings;
	}

	private function v( $k ) {
		return $this->s->get( $k );
	}

	private function text( $key, $label, $desc = '', $type = 'text', $attrs = '' ) {
		echo '<tr><th scope="row"><label for="ncp_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="ncp_' . esc_attr( $key ) . '" name="ncp[' . esc_attr( $key ) . ']" value="' . esc_attr( $this->v( $key ) ) . '" class="regular-text" ' . $attrs . ' />';
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function check( $key, $label, $desc = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="ncp[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $this->v( $key ), true, false ) . ' /> ' . wp_kses_post( $desc ) . '</label></td></tr>';
	}

	private function select( $key, $label, array $options, $desc = '' ) {
		echo '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><select name="ncp[' . esc_attr( $key ) . ']">';
		foreach ( $options as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $this->v( $key ), $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function textarea( $key, $label, $desc = '', $rows = 4 ) {
		echo '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><textarea name="ncp[' . esc_attr( $key ) . ']" rows="' . (int) $rows . '" class="large-text code">' . esc_textarea( $this->v( $key ) ) . '</textarea>';
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	public function render() {
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore
		$tabs  = array(
			'general'       => __( 'General', 'novacrypto-pay' ),
			'coins'         => __( 'Coins & Wallets', 'novacrypto-pay' ),
			'rates'         => __( 'Exchange Rates', 'novacrypto-pay' ),
			'detection'     => __( 'Detection & APIs', 'novacrypto-pay' ),
			'appearance'    => __( 'Appearance', 'novacrypto-pay' ),
			'notifications' => __( 'Notifications', 'novacrypto-pay' ),
			'advanced'      => __( 'Advanced', 'novacrypto-pay' ),
		);
		?>
		<div class="wrap ncp-admin">
			<h1><?php esc_html_e( 'NovaCrypto Pay Settings', 'novacrypto-pay' ); ?> <span class="ncp-version">v<?php echo esc_html( NCP_VERSION ); ?></span></h1>
			<?php if ( isset( $_GET['welcome'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Welcome to NovaCrypto Pay!', 'novacrypto-pay' ); ?></strong> <?php esc_html_e( 'Start on the "Coins & Wallets" tab: enable a coin and paste your wallet address (or an xpub/zpub for fresh addresses per order).', 'novacrypto-pay' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ncp_save_settings' ); ?>
				<input type="hidden" name="action" value="ncp_save_settings" />
				<input type="hidden" name="ncp_active_tab" id="ncp_active_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<h2 class="nav-tab-wrapper ncp-tabs">
					<?php foreach ( $tabs as $id => $label ) : ?><a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
				</h2>
				<?php foreach ( $tabs as $id => $label ) : ?>
					<div id="<?php echo esc_attr( $id ); ?>" class="ncp-tab-panel" style="display:none">
						<?php $this->{'tab_' . $id}(); ?>
					</div>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------- tabs ---------- */

	private function tab_general() {
		echo '<table class="form-table">';
		$this->check( 'enabled', __( 'Enable payments', 'novacrypto-pay' ), __( 'Master switch for all checkouts, links and shortcodes.', 'novacrypto-pay' ) );
		$this->check( 'testnet', __( 'Test mode', 'novacrypto-pay' ), __( 'Use testnets (Bitcoin testnet3, Sepolia, BSC testnet, Amoy, Nile, Solana devnet…). Stablecoin presets are hidden in test mode; add test tokens as custom tokens.', 'novacrypto-pay' ) );
		$this->text( 'title', __( 'Payment method title', 'novacrypto-pay' ), __( 'Shown at checkout.', 'novacrypto-pay' ) );
		$this->textarea( 'description', __( 'Payment method description', 'novacrypto-pay' ), '', 3 );
		$cur = '';
		foreach ( NCP_Rates::fiat_currencies() as $c ) {
			$cur .= '<option value="' . esc_attr( $c ) . '" ' . selected( $this->v( 'store_currency' ), $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Store currency (standalone mode)', 'novacrypto-pay' ) . '</th><td><select name="ncp[store_currency]">' . $cur . '</select><p class="description">' . esc_html__( 'Used for payment links, invoices and shortcodes. WooCommerce / EDD use their own currency setting. 150+ fiat currencies supported via CoinGecko.', 'novacrypto-pay' ) . '</p></td></tr>'; // phpcs:ignore
		$this->text( 'payment_window', __( 'Payment window (minutes)', 'novacrypto-pay' ), __( 'How long a quoted amount stays valid. The rate is locked for this window.', 'novacrypto-pay' ), 'number', 'min="5" max="1440"' );
		$this->text( 'crypto_discount', __( 'Crypto discount (%)', 'novacrypto-pay' ), __( 'Reward customers who pay with crypto. Applied as a negative fee in WooCommerce and as a reduced total on links/invoices.', 'novacrypto-pay' ), 'number', 'step="0.01" min="0" max="50"' );
		$this->select( 'underpay_action', __( 'If a customer underpays', 'novacrypto-pay' ), array( 'hold' => __( 'Hold: mark Underpaid, ask for the remainder, notify admin (recommended)', 'novacrypto-pay' ), 'accept' => __( 'Accept anyway and mark paid', 'novacrypto-pay' ) ) );
		$this->text( 'underpay_tolerance', __( 'Underpayment tolerance (%)', 'novacrypto-pay' ), __( 'Payments within this percentage below the amount due are accepted as paid (covers exchange withdrawal fees / rounding).', 'novacrypto-pay' ), 'number', 'step="0.01" min="0" max="20"' );
		$this->check( 'zero_conf', __( 'Zero-confirmation', 'novacrypto-pay' ), __( 'Treat unconfirmed (mempool) transactions as paid. Faster, but carries double-spend risk. Overrides per-coin confirmations.', 'novacrypto-pay' ) );
		$this->check( 'unique_amounts', __( 'Unique amounts', 'novacrypto-pay' ), __( 'When several customers share one static address, add a tiny random amount so each payment can be matched unambiguously. (Not needed when using an xpub / HD wallet.)', 'novacrypto-pay' ) );
		$this->check( 'require_email', __( 'Require email', 'novacrypto-pay' ), __( 'Ask for an email on links, donations and shortcodes for receipts.', 'novacrypto-pay' ) );
		$this->check( 'collect_refund_address', __( 'Collect refund address', 'novacrypto-pay' ), __( 'Show an optional refund-address field on the payment page.', 'novacrypto-pay' ) );
		$this->check( 'allow_txid_submit', __( 'Allow tx hash submission', 'novacrypto-pay' ), __( 'Let customers paste their transaction hash to trigger an immediate check.', 'novacrypto-pay' ) );
		$this->select( 'pay_page_mode', __( 'Hosted pay page layout', 'novacrypto-pay' ), array( 'theme' => __( 'Inside my theme (header/footer)', 'novacrypto-pay' ), 'standalone' => __( 'Standalone minimal page', 'novacrypto-pay' ) ) );
		echo '</table>';
	}

	private function tab_coins() {
		$coins    = ncp()->coins->all();
		$families = array( 'utxo' => __( 'Bitcoin-style (UTXO)', 'novacrypto-pay' ), 'lightning' => __( 'Lightning Network', 'novacrypto-pay' ), 'evm' => __( 'Ethereum & EVM chains', 'novacrypto-pay' ), 'tron' => __( 'Tron', 'novacrypto-pay' ), 'solana' => __( 'Solana', 'novacrypto-pay' ) );
		echo '<p class="description">' . esc_html__( 'Enable a coin and paste one or more receiving addresses (one per line; rotated between concurrent customers). For Bitcoin, Litecoin, Dogecoin and every EVM chain you can instead paste an account-level extended PUBLIC key (xpub / ypub / zpub / Ltub / dgub) and a fresh address will be derived for every payment — never paste a private key.', 'novacrypto-pay' ) . '</p>';
		echo '<p><label><strong>' . esc_html__( 'Offer at checkout only:', 'novacrypto-pay' ) . '</strong></label> ';
		foreach ( $coins as $id => $c ) {
			if ( ncp()->wallets->is_enabled( $id ) ) {
				echo '<label style="margin-right:12px"><input type="checkbox" name="ncp[checkout_coins][]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, (array) $this->v( 'checkout_coins' ), true ), true, false ) . '/> ' . esc_html( $c['symbol'] . ' ' . ncp()->coins->network_label( $c ) ) . '</label>';
			}
		}
		echo '<br><span class="description">' . esc_html__( 'Leave all unchecked to offer every enabled coin.', 'novacrypto-pay' ) . '</span></p>';
		foreach ( $families as $fam => $label ) {
			echo '<div class="ncp-panel"><h2>' . esc_html( $label ) . '</h2>';
			if ( 'lightning' === $fam ) {
				echo '<p class="description">' . esc_html__( 'Lightning uses your LNbits wallet or a Lightning address (with LUD-21 verify, e.g. Alby). Configure the backend under "Detection & APIs".', 'novacrypto-pay' ) . '</p>';
			}
			foreach ( $coins as $id => $c ) {
				if ( $c['family'] !== $fam ) {
					continue;
				}
				$w = ncp()->wallets->get( $id );
				echo '<div class="ncp-coin-row"><div class="ncp-coin-head"><input type="checkbox" name="ncp_wallets[' . esc_attr( $id ) . '][enabled]" value="1" ' . checked( ! empty( $w['enabled'] ), true, false ) . ' /><img src="' . esc_url( $c['icon'] ) . '" alt="" onerror="this.style.display=\'none\'" /><strong>' . esc_html( $c['name'] ) . '</strong> <span class="ncp-net">' . esc_html( $c['symbol'] . ' · ' . ncp()->coins->network_label( $c ) . ( $c['contract'] ? ' · ' . substr( $c['contract'], 0, 10 ) . '…' : '' ) ) . '</span></div>';
				if ( 'lightning' === $fam ) {
					echo '</div>';
					continue;
				}
				echo '<div class="ncp-coin-fields"><div><label>' . esc_html__( 'Static address(es)', 'novacrypto-pay' ) . '</label><textarea name="ncp_wallets[' . esc_attr( $id ) . '][addresses]" data-coin="' . esc_attr( $id ) . '" rows="2" placeholder="' . esc_attr__( 'One address per line', 'novacrypto-pay' ) . '">' . esc_textarea( implode( "\n", (array) $w['addresses'] ) ) . '</textarea>';
				echo '<p class="description">' . esc_html__( 'Validated with checksum on save.', 'novacrypto-pay' ) . '</p></div>';
				if ( $c['hd'] ) {
					echo '<div><label>' . esc_html__( 'HD wallet – extended public key (optional, recommended)', 'novacrypto-pay' ) . '</label><input type="text" name="ncp_wallets[' . esc_attr( $id ) . '][xpub]" value="' . esc_attr( $w['xpub'] ) . '" placeholder="' . esc_attr( 'utxo' === $fam ? 'zpub6… / xpub6…' : 'xpub6… (m/44\'/60\'/0\')' ) . '" autocomplete="off" />';
					if ( 'utxo' === $fam ) {
						echo ' <select name="ncp_wallets[' . esc_attr( $id ) . '][xpub_script]"><option value="">' . esc_html__( 'Auto (from key prefix)', 'novacrypto-pay' ) . '</option><option value="p2wpkh" ' . selected( $w['xpub_script'], 'p2wpkh', false ) . '>Native SegWit (bc1q…)</option><option value="p2sh-p2wpkh" ' . selected( $w['xpub_script'], 'p2sh-p2wpkh', false ) . '>Wrapped SegWit (3…)</option><option value="p2pkh" ' . selected( $w['xpub_script'], 'p2pkh', false ) . '>Legacy (1…)</option></select>';
					}
					echo '<p class="description">' . esc_html__( 'Next index:', 'novacrypto-pay' ) . ' <input type="number" name="ncp_wallets[' . esc_attr( $id ) . '][hd_index]" value="' . (int) $w['hd_index'] . '" min="0" style="width:90px" /> ' . esc_html__( 'Change branch:', 'novacrypto-pay' ) . ' <input type="number" name="ncp_wallets[' . esc_attr( $id ) . '][hd_change]" value="' . (int) $w['hd_change'] . '" min="0" max="1" style="width:60px" /> <button type="button" class="button button-small ncp-hd-preview-btn" data-coin="' . esc_attr( $id ) . '">' . esc_html__( 'Preview addresses', 'novacrypto-pay' ) . '</button><span class="ncp-hd-preview" style="display:none"></span></p></div>';
				} else {
					echo '<div><p class="description">' . esc_html__( 'HD derivation is not available for this coin; use static addresses. Unique amounts keep concurrent payments distinguishable.', 'novacrypto-pay' ) . '</p></div>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}
		// Custom networks / tokens.
		$nets = ncp()->coins->evm_networks();
		echo '<div class="ncp-panel"><h2>' . esc_html__( 'Custom EVM networks', 'novacrypto-pay' ) . '</h2><p class="description">' . esc_html__( 'Add any EVM-compatible chain (Fantom, Cronos, zkSync, Linea, your own L2…). A native coin entry appears above after saving.', 'novacrypto-pay' ) . '</p>';
		$this->repeater( 'custom_networks', array( 'id' => __( 'ID (slug)', 'novacrypto-pay' ), 'name' => __( 'Name', 'novacrypto-pay' ), 'chain_id' => __( 'Chain ID', 'novacrypto-pay' ), 'symbol' => __( 'Symbol', 'novacrypto-pay' ), 'rpc' => __( 'RPC URL', 'novacrypto-pay' ), 'explorer' => __( 'Explorer URL', 'novacrypto-pay' ), 'blockscout' => __( 'Blockscout URL (optional)', 'novacrypto-pay' ), 'gecko' => __( 'CoinGecko ID', 'novacrypto-pay' ), 'confirmations' => __( 'Conf.', 'novacrypto-pay' ) ), (array) $this->v( 'custom_networks' ) );
		echo '</div><div class="ncp-panel"><h2>' . esc_html__( 'Custom tokens (ERC-20 / BEP-20 / TRC-20 / SPL)', 'novacrypto-pay' ) . '</h2>';
		$netopts = array( 'tron' => 'Tron', 'solana' => 'Solana' );
		foreach ( $nets as $k => $n ) {
			$netopts[ $k ] = $n['name'];
		}
		$this->repeater( 'custom_tokens', array( 'symbol' => __( 'Symbol', 'novacrypto-pay' ), 'name' => __( 'Name', 'novacrypto-pay' ), 'network' => array( 'select', $netopts ), 'contract' => __( 'Contract / mint address', 'novacrypto-pay' ), 'decimals' => __( 'Decimals', 'novacrypto-pay' ), 'gecko' => __( 'CoinGecko ID', 'novacrypto-pay' ), 'stable' => array( 'checkbox', __( 'Stablecoin', 'novacrypto-pay' ) ), 'confirmations' => __( 'Conf.', 'novacrypto-pay' ) ), (array) $this->v( 'custom_tokens' ) );
		echo '</div>';
	}

	private function repeater( $key, array $cols, array $rows ) {
		echo '<div class="ncp-repeater"><table class="widefat"><thead><tr>';
		foreach ( $cols as $ck => $cl ) {
			echo '<th>' . esc_html( is_array( $cl ) ? ( 'checkbox' === $cl[0] ? $cl[1] : ucfirst( $ck ) ) : $cl ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		$render_row = function ( $i, $row ) use ( $key, $cols ) {
			$html = '<tr>';
			foreach ( $cols as $ck => $cl ) {
				$name = 'ncp[' . $key . '][' . $i . '][' . $ck . ']';
				$val  = $row[ $ck ] ?? '';
				if ( is_array( $cl ) && 'select' === $cl[0] ) {
					$html .= '<td><select name="' . esc_attr( $name ) . '">';
					foreach ( $cl[1] as $ov => $ol ) {
						$html .= '<option value="' . esc_attr( $ov ) . '" ' . selected( $val, $ov, false ) . '>' . esc_html( $ol ) . '</option>';
					}
					$html .= '</select></td>';
				} elseif ( is_array( $cl ) && 'checkbox' === $cl[0] ) {
					$html .= '<td><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $val ), true, false ) . ' /></td>';
				} else {
					$html .= '<td><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" /></td>';
				}
			}
			return $html . '<td><button type="button" class="button ncp-repeater-remove">&times;</button></td></tr>';
		};
		foreach ( array_values( $rows ) as $i => $row ) {
			echo $render_row( $i, (array) $row ); // phpcs:ignore
		}
		echo '</tbody></table><template>' . $render_row( '__i__', array() ) . '</template><button type="button" class="button ncp-repeater-add">+ ' . esc_html__( 'Add row', 'novacrypto-pay' ) . '</button></div>'; // phpcs:ignore
	}

	private function tab_rates() {
		echo '<table class="form-table">';
		echo '<tr><th scope="row">' . esc_html__( 'Rate providers', 'novacrypto-pay' ) . '</th><td>';
		$enabled = (array) $this->v( 'rate_providers' );
		foreach ( ncp()->rates->providers() as $id => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="ncp[rate_providers][]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, $enabled, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Providers are tried in order until one returns a price; if all fail, the last known rate (up to 24h old) is used so checkout never breaks.', 'novacrypto-pay' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Primary provider', 'novacrypto-pay' ) . '</th><td><select name="ncp[rate_primary]">';
		foreach ( ncp()->rates->providers() as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( reset( $enabled ), $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		$this->text( 'coinmarketcap_key', __( 'CoinMarketCap API key', 'novacrypto-pay' ), '', 'text', 'autocomplete="off"' );
		$this->text( 'rate_cache_ttl', __( 'Cache rates for (seconds)', 'novacrypto-pay' ), '', 'number', 'min="30" max="3600"' );
		$this->text( 'global_markup', __( 'Global markup (%)', 'novacrypto-pay' ), __( 'Positive = charge more crypto (covers volatility / fees). Negative = discount.', 'novacrypto-pay' ), 'number', 'step="0.01" min="-50" max="50"' );
		echo '</table><div class="ncp-panel"><h2>' . esc_html__( 'Per-coin overrides', 'novacrypto-pay' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Coin', 'novacrypto-pay' ) . '</th><th>' . esc_html__( 'Markup %', 'novacrypto-pay' ) . '</th><th>' . esc_html__( 'Manual fixed rate (fiat per coin)', 'novacrypto-pay' ) . '</th><th>' . esc_html__( 'Required confirmations', 'novacrypto-pay' ) . '</th><th>' . esc_html__( 'Min. order (fiat)', 'novacrypto-pay' ) . '</th></tr></thead><tbody>';
		foreach ( ncp()->coins->all() as $id => $c ) {
			$m  = (array) $this->v( 'coin_markup' );
			$r  = (array) $this->v( 'manual_rates' );
			$cf = (array) $this->v( 'coin_confirmations' );
			$mn = (array) $this->v( 'coin_min_amount' );
			echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td><input type="number" step="0.01" name="ncp[coin_markup][' . esc_attr( $id ) . ']" value="' . esc_attr( $m[ $id ] ?? '' ) . '" style="width:90px" /></td><td><input type="number" step="any" name="ncp[manual_rates][' . esc_attr( $id ) . ']" value="' . esc_attr( $r[ $id ] ?? '' ) . '" style="width:140px" placeholder="' . esc_attr__( 'auto', 'novacrypto-pay' ) . '" /></td><td><input type="number" min="0" name="ncp[coin_confirmations][' . esc_attr( $id ) . ']" value="' . esc_attr( $cf[ $id ] ?? '' ) . '" style="width:80px" placeholder="' . esc_attr( $c['confirmations'] ) . '" /></td><td><input type="number" step="0.01" name="ncp[coin_min_amount][' . esc_attr( $id ) . ']" value="' . esc_attr( $mn[ $id ] ?? '' ) . '" style="width:90px" /></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function tab_detection() {
		echo '<table class="form-table">';
		$this->text( 'etherscan_key', __( 'Etherscan API key (V2, all EVM chains)', 'novacrypto-pay' ), __( 'Free key from etherscan.io covers Ethereum, BSC, Polygon, Arbitrum, Optimism, Base, Avalanche… Without it the plugin falls back to Blockscout and then to RPC balance detection (HD addresses only).', 'novacrypto-pay' ), 'text', 'autocomplete="off"' );
		$this->text( 'blockcypher_token', __( 'BlockCypher token (optional)', 'novacrypto-pay' ), __( 'Raises rate limits for Dogecoin / Bitcoin Cash / Litecoin fallback detection.', 'novacrypto-pay' ), 'text', 'autocomplete="off"' );
		$this->text( 'trongrid_key', __( 'TronGrid API key (optional)', 'novacrypto-pay' ), '', 'text', 'autocomplete="off"' );
		$this->text( 'solana_rpc', __( 'Solana RPC URL', 'novacrypto-pay' ), __( 'Public RPC works for low volume; use Helius/QuickNode/Alchemy for production.', 'novacrypto-pay' ), 'url' );
		echo '<tr><th scope="row">' . esc_html__( 'Custom EVM RPC endpoints', 'novacrypto-pay' ) . '</th><td>';
		$rows = array();
		foreach ( (array) $this->v( 'custom_rpcs' ) as $cid => $url ) {
			$rows[] = array( 'chain_id' => $cid, 'url' => $url );
		}
		$this->repeater( 'custom_rpcs', array( 'chain_id' => __( 'Chain ID', 'novacrypto-pay' ), 'url' => __( 'RPC URL (Infura/Alchemy/own node)', 'novacrypto-pay' ) ), $rows );
		echo '<p class="description">' . esc_html__( 'Overrides the built-in public RPC for fee estimates, MetaMask network switching and balance detection.', 'novacrypto-pay' ) . '</p></td></tr>';
		$this->text( 'poll_interval', __( 'Browser poll interval (seconds)', 'novacrypto-pay' ), '', 'number', 'min="5" max="120"' );
		$this->text( 'explorer_min_interval', __( 'Min. seconds between explorer calls per payment', 'novacrypto-pay' ), __( 'Protects you from API rate limits.', 'novacrypto-pay' ), 'number', 'min="5" max="600"' );
		echo '</table><div class="ncp-panel"><h2>' . esc_html__( 'Lightning Network', 'novacrypto-pay' ) . '</h2><table class="form-table">';
		$this->select( 'lightning_backend', __( 'Backend', 'novacrypto-pay' ), array( 'none' => __( 'Disabled', 'novacrypto-pay' ), 'lnbits' => 'LNbits (self-hosted or lnbits.com)', 'lnaddress' => __( 'Lightning address with LUD-21 verify (e.g. Alby, Wallet of Satoshi does NOT support verify)', 'novacrypto-pay' ) ) );
		$this->text( 'lnbits_url', __( 'LNbits URL', 'novacrypto-pay' ), 'https://legend.lnbits.com', 'url' );
		$this->text( 'lnbits_invoice_key', __( 'LNbits invoice/read key', 'novacrypto-pay' ), __( 'Use the INVOICE key (read-only), never the admin key.', 'novacrypto-pay' ), 'text', 'autocomplete="off"' );
		$this->text( 'lightning_address', __( 'Lightning address', 'novacrypto-pay' ), 'you@getalby.com' );
		echo '</table><p class="description">' . esc_html__( 'Remember to enable the "Bitcoin Lightning" coin on the Coins & Wallets tab.', 'novacrypto-pay' ) . '</p></div>';
	}

	private function tab_appearance() {
		echo '<table class="form-table">';
		echo '<tr><th scope="row">' . esc_html__( 'Primary colour', 'novacrypto-pay' ) . '</th><td><input type="text" class="ncp-color" name="ncp[primary_color]" value="' . esc_attr( $this->v( 'primary_color' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Accent (success) colour', 'novacrypto-pay' ) . '</th><td><input type="text" class="ncp-color" name="ncp[accent_color]" value="' . esc_attr( $this->v( 'accent_color' ) ) . '" /></td></tr>';
		$this->text( 'border_radius', __( 'Border radius (px)', 'novacrypto-pay' ), '', 'number', 'min="0" max="40"' );
		$this->select( 'theme_mode', __( 'Theme', 'novacrypto-pay' ), array( 'auto' => __( 'Auto (follow visitor OS dark mode)', 'novacrypto-pay' ), 'light' => __( 'Light', 'novacrypto-pay' ), 'dark' => __( 'Dark', 'novacrypto-pay' ) ) );
		$this->text( 'logo_url', __( 'Logo URL', 'novacrypto-pay' ), __( 'Shown on hosted pay pages, invoices and emails.', 'novacrypto-pay' ), 'url' );
		$this->check( 'show_fee_estimate', __( 'Show network fee estimate', 'novacrypto-pay' ), __( 'Displays an approximate miner/gas fee next to each coin.', 'novacrypto-pay' ) );
		$this->check( 'show_wallet_buttons', __( 'Wallet buttons', 'novacrypto-pay' ), __( '"Pay with MetaMask" and "Open in wallet app" (BIP-21 / EIP-681 / Solana Pay / BOLT11 deep links).', 'novacrypto-pay' ) );
		$this->textarea( 'custom_css', __( 'Custom CSS', 'novacrypto-pay' ), __( 'Applied to payment boxes. Override templates by copying templates/*.php to yourtheme/novacrypto-pay/.', 'novacrypto-pay' ), 6 );
		echo '</table>';
	}

	private function tab_notifications() {
		echo '<table class="form-table">';
		$this->text( 'admin_email', __( 'Admin notification email', 'novacrypto-pay' ), '', 'email' );
		$this->text( 'email_from_name', __( 'From name', 'novacrypto-pay' ) );
		$this->check( 'notify_admin_paid', __( 'Email admin on paid', 'novacrypto-pay' ) );
		$this->check( 'notify_admin_underpaid', __( 'Email admin on underpaid', 'novacrypto-pay' ) );
		$this->check( 'notify_customer_detected', __( 'Email customer when detected', 'novacrypto-pay' ) );
		$this->check( 'notify_customer_paid', __( 'Email customer receipt when paid', 'novacrypto-pay' ) );
		$this->check( 'notify_customer_expired', __( 'Email customer when expired', 'novacrypto-pay' ) );
		echo '</table><div class="ncp-panel"><h2>' . esc_html__( 'Chat alerts', 'novacrypto-pay' ) . '</h2><table class="form-table">';
		$this->text( 'telegram_token', __( 'Telegram bot token', 'novacrypto-pay' ), __( 'Create a bot with @BotFather, then message it once and put your chat ID below.', 'novacrypto-pay' ), 'text', 'autocomplete="off"' );
		$this->text( 'telegram_chat_id', __( 'Telegram chat ID', 'novacrypto-pay' ) );
		$this->text( 'discord_webhook', __( 'Discord webhook URL', 'novacrypto-pay' ), '', 'url' );
		$this->text( 'slack_webhook', __( 'Slack incoming webhook URL', 'novacrypto-pay' ), '', 'url' );
		echo '</table><p><button type="button" class="button" data-ncp-task="test_notifications" data-out="ncp-notif-out">' . esc_html__( 'Send test to all channels', 'novacrypto-pay' ) . '</button> <span id="ncp-notif-out"></span></p></div>';
		echo '<div class="ncp-panel"><h2>' . esc_html__( 'Outgoing webhooks (IPN)', 'novacrypto-pay' ) . '</h2><p class="description">' . esc_html__( 'Signed JSON POSTs on payment.created / detected / confirming / paid / underpaid / expired / cancelled / refunded with automatic retries. Signature header X-NCP-Signature = sha256 HMAC of the raw body using the secret shown under Tools.', 'novacrypto-pay' ) . '</p>';
		$rows = array_map( function ( $u ) { return array( 'url' => $u ); }, (array) $this->v( 'webhook_urls' ) );
		echo '<div class="ncp-repeater"><table class="widefat"><thead><tr><th>URL</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			echo '<tr><td><input type="url" name="ncp[webhook_urls][]" value="' . esc_attr( $r['url'] ) . '" /></td><td><button type="button" class="button ncp-repeater-remove">&times;</button></td></tr>';
		}
		echo '</tbody></table><template><tr><td><input type="url" name="ncp[webhook_urls][]" value="" /></td><td><button type="button" class="button ncp-repeater-remove">&times;</button></td></tr></template><button type="button" class="button ncp-repeater-add">+ ' . esc_html__( 'Add URL', 'novacrypto-pay' ) . '</button></div></div>';
	}

	private function tab_advanced() {
		echo '<table class="form-table">';
		$this->check( 'enable_rest', __( 'Enable REST API', 'novacrypto-pay' ), __( 'Endpoints under /wp-json/ncp/v1/ (see Tools for the key and examples).', 'novacrypto-pay' ) );
		echo '<tr><th scope="row">' . esc_html__( 'API key', 'novacrypto-pay' ) . '</th><td><code>' . esc_html( get_option( 'ncp_api_key' ) ) . '</code> <label style="margin-left:12px"><input type="checkbox" name="regenerate_api_key" value="1" /> ' . esc_html__( 'Regenerate on save', 'novacrypto-pay' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Webhook secret', 'novacrypto-pay' ) . '</th><td><code>' . esc_html( get_option( 'ncp_webhook_secret' ) ) . '</code> <label style="margin-left:12px"><input type="checkbox" name="regenerate_webhook_secret" value="1" /> ' . esc_html__( 'Regenerate on save', 'novacrypto-pay' ) . '</label></td></tr>';
		$this->text( 'gap_limit', __( 'HD gap limit hint', 'novacrypto-pay' ), __( 'Informational: most wallets scan 20 unused addresses ahead. Expired payments release their index for reuse automatically, and you can raise the gap limit in your wallet (Electrum, Sparrow, BlueWallet…).', 'novacrypto-pay' ), 'number' );
		$this->check( 'enable_logging', __( 'Logging', 'novacrypto-pay' ), __( 'Keep an activity log (Tools & Logs). Errors are always logged.', 'novacrypto-pay' ) );
		$this->check( 'debug_logging', __( 'Debug logging', 'novacrypto-pay' ), __( 'Verbose explorer / webhook traces.', 'novacrypto-pay' ) );
		$this->check( 'delete_on_uninstall', __( 'Delete all data on uninstall', 'novacrypto-pay' ), __( 'Drops payment tables and settings when the plugin is deleted.', 'novacrypto-pay' ) );
		echo '</table><div class="ncp-panel"><h2>' . esc_html__( 'WP-CLI', 'novacrypto-pay' ) . '</h2><pre>wp ncp check            # run the payment monitor
wp ncp rates            # print live rates for enabled coins
wp ncp derive btc 0 5   # preview HD addresses
wp ncp payment &lt;uuid&gt;   # show a payment
wp ncp expire           # expire stale payments</pre></div>';
		echo '<div class="ncp-panel"><h2>' . esc_html__( 'Developer hooks', 'novacrypto-pay' ) . '</h2><pre>do_action( \'ncp_payment_created\', $payment )
do_action( \'ncp_payment_status_changed\', $payment, $old, $new, $note )
do_action( \'ncp_payment_paid\', $payment )   // also _detected, _confirming, _underpaid, _expired …
apply_filters( \'ncp_coins\', $coins )
apply_filters( \'ncp_rate_providers\', $providers )
apply_filters( \'ncp_final_rate\', $rate, $coin_id, $provider )
apply_filters( \'ncp_explorer_providers\', $list, $coin )
apply_filters( \'ncp_match_transactions\', $matched, $payment, $all )
apply_filters( \'ncp_webhook_urls\', $urls, $payment, $event )</pre></div>';
	}
}
