# NovaCrypto Pay

A non-custodial cryptocurrency payment plugin for WordPress, WooCommerce and Easy Digital Downloads. Built as a feature-superset of the CodeCanyon "Cryptoniq" plugin — see [FEATURE-COMPARISON.md](FEATURE-COMPARISON.md).

## Install

1. Zip the `novacrypto-pay/` folder (or use `novacrypto-pay.zip`) and upload it under **Plugins → Add New → Upload**, or copy the folder to `wp-content/plugins/`.
2. Activate. You are redirected to **NovaCrypto Pay → Settings**.
3. **Coins & Wallets**: tick a coin and paste a wallet address (one per line) or an account-level extended public key (`zpub…` for native-SegWit Bitcoin, `xpub…` for Ethereum accounts, `Ltub…`/`xpub…` for Litecoin, `dgub…` for Dogecoin). Use *Preview addresses* to confirm they match your wallet.
4. Optional but recommended: **Detection & APIs → Etherscan API key** (free) for EVM chains. Without it the plugin uses JSON-RPC log detection for tokens and balance detection for HD addresses.
5. WooCommerce: enable the gateway under **WooCommerce → Settings → Payments → NovaCrypto Pay**.
6. Background checks run through WP-Cron every minute. For production add a system cron: `* * * * * curl -s https://yoursite/wp-cron.php >/dev/null` and define `DISABLE_WP_CRON` in `wp-config.php`.

Requirements: WordPress 5.8+, PHP 7.4+ (GMP or BCMath extension for HD wallets — present on almost all hosts), HTTPS recommended.

## Standalone usage (no shop)

* **Payment links / invoices / donation pages**: NovaCrypto Pay → Links & Invoices. Each gets a hosted URL such as `/ncp-link/consulting-ab12cd/`.
* **Shortcodes**: `[ncp_pay amount="25" currency="USD" title="Coffee"]`, `[ncp_donate presets="5,10,25"]`, `[ncp_wallets]`, `[ncp_price amount="100" coin="btc"]`, `[ncp_link slug="…"]`, `[ncp_payment uuid="…"]`, `[ncp_status uuid="…"]`.
* **Blocks**: "NovaCrypto Pay" category in the block inserter.
* **REST API** (key under Tools & Logs):

```bash
curl -X POST https://yoursite/wp-json/ncp/v1/payments \
  -H "X-NCP-API-Key: ncp_xxx" -H "Content-Type: application/json" \
  -d '{"coin":"btc","amount":25,"currency":"USD","email":"buyer@example.com","reference":"INV-1","webhook_url":"https://app.example/hook"}'
```

Webhook payloads are signed: `X-NCP-Signature: sha256=HMAC_SHA256(raw_body, secret)`.

## Project layout

```
novacrypto-pay/
├── novacrypto-pay.php            bootstrap + autoloader
├── includes/
│   ├── class-ncp-plugin.php      orchestrator
│   ├── class-ncp-coins.php       coin/network registry (built-in + custom)
│   ├── class-ncp-rates.php       multi-provider rate engine
│   ├── class-ncp-wallets.php     address pools + HD allocation
│   ├── class-ncp-payments.php    payment records + state machine
│   ├── class-ncp-explorers.php   blockchain detection (fallback chains)
│   ├── class-ncp-monitor.php     cron/on-demand checking + matching
│   ├── class-ncp-lightning.php   LNbits / Lightning address
│   ├── class-ncp-notifications.php  email, Telegram, Discord, Slack, webhooks
│   ├── class-ncp-rest.php        REST API
│   ├── class-ncp-frontend.php    hosted pages, AJAX, payment URIs
│   ├── class-ncp-shortcodes.php / class-ncp-blocks.php
│   ├── class-ncp-links.php       payment links, invoices, donations
│   ├── class-ncp-validator.php   address / txid / xpub validation
│   ├── class-ncp-cli.php         WP-CLI
│   ├── crypto/                   BIP32, secp256k1, Base58, Bech32, Keccak
│   ├── gateways/                 WooCommerce, WC Blocks, EDD
│   └── admin/                    menus, settings, list table, views
├── templates/                    overridable frontend + email templates
├── assets/                       CSS, JS (QR lib MIT), coin icons
├── languages/novacrypto-pay.pot
└── tests/test-crypto.php         run: php tests/test-crypto.php
```

## Developer hooks

`ncp_payment_created`, `ncp_payment_status_changed`, `ncp_payment_{status}`, `ncp_transaction_added`, `ncp_customer_txid_submitted`; filters `ncp_coins`, `ncp_evm_networks`, `ncp_rate_providers`, `ncp_final_rate`, `ncp_explorer_providers`, `ncp_explorer_result`, `ncp_match_transactions`, `ncp_webhook_urls`, `ncp_email_subject`, `ncp_chat_message`, `ncp_setting_{key}`.

## Notes and limitations

* Crypto payments are irreversible; refunds are manual (the plugin records the customer's refund address and lets you mark the payment refunded).
* Public explorer/RPC endpoints have rate limits. For high volume add API keys (Etherscan, BlockCypher, TronGrid) or your own RPC URLs.
* Lightning via a Lightning address requires a provider that supports LUD-21 `verify` (e.g. Alby). LNbits works with any node.
* Solana addresses cannot be derived from an xpub (ed25519); use static addresses — unique salted amounts keep concurrent payments distinguishable.
