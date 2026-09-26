=== NovaCrypto Pay – Cryptocurrency Payments for WordPress & WooCommerce ===
Contributors: novacrypto
Tags: bitcoin, ethereum, cryptocurrency, woocommerce, payment gateway, lightning, usdt, crypto payments, donations, invoices
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin, Ethereum, Lightning, USDT/USDC and 25+ networks directly to your own wallets. No middleman, no KYC, no fees.

== Description ==

NovaCrypto Pay turns any WordPress site into a non-custodial crypto checkout. Customers pay straight to **your** wallet addresses – the plugin watches the blockchain and confirms the order automatically.

**Works with**: WooCommerce (classic + block checkout, HPOS), Easy Digital Downloads, or completely standalone through payment links, invoices, donation pages, shortcodes and Gutenberg blocks.

= Coins & networks =
* Bitcoin (on-chain + Lightning), Litecoin, Dogecoin, Bitcoin Cash
* Ethereum, BNB Smart Chain, Polygon, Arbitrum, Optimism, Base, Avalanche
* Tron, Solana
* USDT / USDC / DAI on every supported chain (ERC-20, BEP-20, TRC-20, SPL)
* **Any EVM chain** and **any token** – add your own by chain ID / contract address

= What makes it different =
* **HD wallet support** – paste an xpub / ypub / zpub and every order gets a fresh address (BTC, LTC, DOGE and all EVM chains). Private keys never touch your server.
* **Lightning Network** via LNbits or a Lightning address (LUD-21).
* **Multiple rate providers** (CoinGecko, CoinPaprika, CoinMarketCap, Binance, Kraken) with automatic fallback, caching, markup and manual overrides. 150+ fiat currencies.
* **Explorer fallback chains** – mempool.space, Blockstream, BlockCypher, Blockchair, Etherscan V2, Blockscout, plain JSON-RPC, TronGrid, Solana RPC.
* **Under/over-payment handling** with tolerance, partial-payment accumulation and "pay the remainder" flow.
* **Configurable confirmations per coin**, zero-conf option, payment windows with rate lock, late-payment detection after expiry.
* **Payment links, invoices (emailed), donation pages** – no shop needed.
* **Shortcodes & blocks**: `[ncp_pay]`, `[ncp_donate]`, `[ncp_wallets]`, `[ncp_price]`, `[ncp_link]`, `[ncp_payment]`, `[ncp_status]`.
* **One-click wallet payments** – MetaMask / any EIP-1193 wallet, BIP-21, EIP-681, Solana Pay and BOLT11 deep links, QR codes.
* **Notifications** – customer & admin emails, Telegram, Discord, Slack, and signed outgoing webhooks (IPN) with retries.
* **REST API** with API keys for headless / mobile / SaaS use.
* **Crypto discount %**, per-product fixed crypto prices, refund-address collection and refund workflow.
* **Duplicate-transaction protection**, checksum address validation (Base58Check, Bech32/Bech32m, CashAddr, EIP-55, Tron, Solana).
* **Dashboard analytics, ledger with CSV accounting export** (rate snapshots), logs, settings import/export, WP-CLI, test mode, dark mode, template overrides, i18n-ready.

== Installation ==
1. Upload the `novacrypto-pay` folder to `/wp-content/plugins/` and activate.
2. Go to **NovaCrypto Pay → Settings → Coins & Wallets**, enable a coin and paste a wallet address or xpub.
3. (WooCommerce) Enable the gateway under WooCommerce → Settings → Payments.
4. Optional: add a free Etherscan API key for EVM detection, connect Telegram/Discord/Slack.

== Frequently Asked Questions ==

= Do you hold my funds? =
No. Payments go straight from the customer to your wallet. The plugin only reads public blockchain data.

= Is an API key required? =
No. Bitcoin-family coins, Tron and Solana work without keys. For EVM chains a free Etherscan key is recommended; otherwise Blockscout / RPC balance detection is used.

= Can I use it without WooCommerce? =
Yes – payment links, invoices, donation pages, shortcodes, blocks and the REST API work standalone.

= Is a real cron needed? =
WP-Cron works, but for reliable background confirmation we recommend a system cron hitting `wp-cron.php` every minute.

== Changelog ==
= 1.0.0 =
* Initial release.
