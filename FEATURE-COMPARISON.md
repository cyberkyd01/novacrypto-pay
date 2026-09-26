# Cryptoniq vs. NovaCrypto Pay — feature analysis

## 1. What Cryptoniq offers (CodeCanyon item 22419379 + cryptoniq.io, Lite & Pro)

Source: the CodeCanyon listing, cryptoniq.io and the free "Cryptoniq Lite" listing on WordPress.org.

| Area | Cryptoniq |
|---|---|
| Model | Non-custodial: customers send funds straight to the merchant's own wallet. No KYC, no fees. |
| Coins (Pro) | BTC, ETH, LTC, DOGE, TRX, SOL, BNB; ETH on Arbitrum & Optimism; USDT/USDC on ETH, ARB, OP, BNB, SOL, TRX. Lite: BTC + ETH only. |
| Shop integration | WooCommerce only (5.x – 9.x, HPOS compatible). |
| Checkout | Responsive payment box, QR code, one-click copy, live conversion at current rate, 3-hour fixed payment window. |
| Detection | Lite: polling every few seconds while the customer stays on the page (blockchain.info + Etherscan). Pro: "real-time webhook detection". |
| Rates | CoinGecko, refreshed every 30 min by WP-Cron. Lite: USD/EUR only. Pro: 150+ fiat currencies. |
| Admin | Wallet addresses (multiple per coin, rotated per order), enable/disable coins, colour options, coin pricing for products, Etherscan API key. |
| Other | Zero-conf support, HD-wallet add-on (paid extra), order-email integration, Redux Framework dependency, Elementor/Gravity Forms/Visual Composer "compatible". |
| Licensing | $39 one-time on CodeCanyon; cryptoniq.io sells Pro as a $9.99–$119.99 subscription. |

Gaps observed: no standalone mode (WooCommerce required), no payment links/invoices/donations, no EDD, no REST API, no webhooks/chat alerts, no Lightning, no custom tokens/networks, no under/over-payment handling, single rate provider, fixed 3-hour window, HD wallet only as a paid add-on, requires the Redux Framework plugin.

## 2. NovaCrypto Pay — parity features (everything Cryptoniq does)

1. Non-custodial, direct-to-wallet, no KYC, no fees.
2. All Cryptoniq Pro coins: BTC, ETH, LTC, DOGE, TRX, SOL, BNB, ETH on Arbitrum/Optimism, USDT/USDC on ETH/ARB/OP/BNB/SOL/TRX.
3. WooCommerce gateway (classic checkout), HPOS-compatible, order emails, order notes, admin order box.
4. Responsive payment box with QR code, copy buttons, live conversion, countdown timer.
5. Automatic on-chain detection with browser polling **and** server-side cron (customers may close the tab).
6. Multiple addresses per coin with rotation.
7. Enable/disable coins, colour options, per-product coin prices, Etherscan API key.
8. Zero-conf option, 150+ fiat currencies (CoinGecko), HD wallet support (built in, not an add-on).
9. No framework dependencies (no Redux); works with Elementor/any builder via shortcodes.

## 3. NovaCrypto Pay — the 30 extra features

| # | Feature | Where |
|---|---|---|
| 1 | **Built-in HD wallet (xpub/ypub/zpub/Ltub/dgub) with a fresh address per payment** for BTC, LTC, DOGE and all EVM chains; pure-PHP BIP32/secp256k1, private keys never touch the server; expired indexes are reused to stay gap-limit friendly | `includes/crypto/`, `class-ncp-wallets.php` |
| 2 | **Lightning Network** via LNbits or a Lightning address (LUD-16 + LUD-21 verify) | `class-ncp-lightning.php` |
| 3 | **Extra networks**: Bitcoin Cash, Polygon, Base, Avalanche, DAI | `class-ncp-coins.php` |
| 4 | **Any EVM chain** by chain ID/RPC (Fantom, Cronos, zkSync, your own L2…) | Settings → Coins & Wallets |
| 5 | **Any token** (ERC-20 / BEP-20 / TRC-20 / SPL) by contract address & decimals | Settings → Coins & Wallets |
| 6 | **Five rate providers with automatic fallback** (CoinGecko, CoinPaprika, CoinMarketCap, Binance, Kraken) + stale-rate safety net so checkout never breaks | `class-ncp-rates.php` |
| 7 | **Global and per-coin markup, manual fixed rates, per-coin minimum order** | Settings → Exchange Rates |
| 8 | **Crypto discount %** (negative WC fee / reduced link total) | Settings → General |
| 9 | **Underpayment / overpayment handling**: tolerance %, partial-payment accumulation, "pay the remainder" flow, admin accept-underpaid | `class-ncp-payments.php::evaluate()` |
| 10 | **Configurable confirmations per coin**, configurable payment window (not fixed 3 h) with rate lock, extend/re-quote from admin | Settings |
| 11 | **Late-payment detection** after expiry (24 h) | `class-ncp-monitor.php` |
| 12 | **Explorer fallback chains**: mempool.space → Blockstream → BlockCypher → Blockchair; Etherscan V2 → JSON-RPC (log/balance based, key-free) → Blockscout; TronGrid; Solana RPC | `class-ncp-explorers.php` |
| 13 | **Standalone mode** – no WooCommerce needed | shortcodes, links, REST |
| 14 | **Payment links** (reusable, max-uses, expiry, coin restrictions, success URL) | Admin → Links & Invoices |
| 15 | **Invoices** emailed to customers with hosted pay page, auto-marked paid | Admin → Links & Invoices |
| 16 | **Donation pages / widget** with preset amounts and minimum | `[ncp_donate]`, block |
| 17 | **7 shortcodes** (`ncp_pay`, `ncp_donate`, `ncp_wallets`, `ncp_price`, `ncp_link`, `ncp_payment`, `ncp_status`) with HMAC-signed amounts | `class-ncp-shortcodes.php` |
| 18 | **5 Gutenberg blocks** with live editor preview | `class-ncp-blocks.php`, `assets/js/blocks.js` |
| 19 | **Easy Digital Downloads gateway** | `gateways/class-ncp-edd-gateway.php` |
| 20 | **WooCommerce Blocks (block checkout) support** | `gateways/class-ncp-wc-blocks.php` |
| 21 | **One-click wallet payments**: MetaMask/any EIP-1193 wallet with automatic network switch/add, plus BIP-21, EIP-681, Solana Pay and BOLT11 deep links in the QR | `assets/js/frontend.js`, `NCP_Frontend::payment_uri()` |
| 22 | **Customer tx-hash submission** (verified directly via RPC / explorer) and **admin manual confirmation** | REST `/txid`, admin actions |
| 23 | **Refund workflow**: collect a refund address at checkout, WooCommerce refund hook records it, mark refunded | payment box, WC gateway |
| 24 | **Telegram, Discord and Slack alerts** | `class-ncp-notifications.php` |
| 25 | **Signed outgoing webhooks (IPN)** with HMAC-SHA256 signature and exponential-backoff retry queue | `wp_ncp_webhook_queue` |
| 26 | **REST API** with API key (create/list/get/check/txid/refund-address/status, coins, rates) for headless & mobile apps | `class-ncp-rest.php` |
| 27 | **Duplicate-transaction protection** (one txid can never pay two orders) and **checksum address validation** (Base58Check, Bech32/Bech32m, CashAddr, EIP-55, Tron, Solana) | `class-ncp-validator.php` |
| 28 | **Dashboard analytics + ledger + CSV accounting export with rate snapshots**, settings import/export, logs viewer, diagnostics buttons | Admin → Dashboard / Tools |
| 29 | **Test mode** (BTC testnet, Sepolia, BSC testnet, Amoy, Nile, Solana devnet), **dark mode / appearance customizer**, theme template overrides, standalone pay-page layout | Settings → General / Appearance |
| 30 | **WP-CLI commands**, developer hooks/filters, i18n-ready (.pot included), uninstall cleanup option | `class-ncp-cli.php`, `languages/` |

## 4. Verification performed

* 26 cryptographic test vectors (BIP32/44/49/84, Bech32, Base58Check, Keccak-256, EIP-55, secp256k1): `php novacrypto-pay/tests/test-crypto.php` → 0 failures.
* End-to-end smoke test on a real WordPress 6.x + WooCommerce install with MySQL: live CoinGecko rates, live detection against mempool.space, TronGrid, Solana RPC and Ethereum JSON-RPC (USDT transfer logs), HD address derivation, salted amounts, partial → underpaid → paid lifecycle, tolerance, expiry + index reuse, invoices, donations, REST API auth, WooCommerce order sync (pending → on-hold → processing), fixed crypto product prices, all shortcodes, email templates, settings import/export.
* HTTP checks of every admin page, hosted pay/link pages, REST endpoints, AJAX guards and the settings/invoice/mark-paid form handlers with zero PHP warnings or notices in `debug.log`.
