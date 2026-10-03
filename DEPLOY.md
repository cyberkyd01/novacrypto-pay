# Deploy NovaCrypto Pay on a VPS (over SSH)

NovaCrypto Pay is a WordPress plugin, not a standalone app. This guide gets it onto a WordPress site on your VPS from this repo, keeps it updated with one command, and makes payment confirmations run reliably in the background.

- **Path A (most common): you already have WordPress on the VPS.** The plugin goes into that site. Only the PHP extensions it needs are added, and only that site's PHP is reloaded.
- **Path B: there is no WordPress yet.** You get a new, isolated WordPress + WooCommerce just for NovaCrypto Pay, with its own database, PHP-FPM pool and nginx site. Nothing else on the server is touched.

**Target:** Ubuntu 22.04 / 24.04 LTS or Debian 12, x86_64 or arm64, over SSH as a sudo user or root. Every command uses `sudo`, which works for root too. On a minimal Debian logged in as root, run `apt-get install -y sudo` first.

## At a glance

| | Path A: existing WordPress | Path B: fresh isolated WordPress |
|---|---|---|
| Plugin location | `<your site>/wp-content/plugins/novacrypto-pay`, owned like the other plugins | `/opt/novacrypto/site/wp-content/plugins/novacrypto-pay` |
| Repo checkout, tools, backups | `/opt/novacrypto` (`repo/`, `bin/deploy.sh`, `bin/wp`, `backups/`) | same |
| Config | `/etc/novacrypto/deploy.conf` (the only file you edit) | same, plus `/etc/novacrypto/db.env` and `wp-admin.txt` (root only) |
| System user | `novacrypto` (owns the repo checkout and the deploy key) | `novacrypto` (also runs the site's PHP) |
| WordPress runs as | your site's PHP user (detected, usually `www-data`) | `novacrypto` |
| PHP | the version your site already uses (detected from its PHP-FPM pool); extensions added for it only | the distro's PHP-FPM (8.1 / 8.2 / 8.3), own pool `/etc/php/<ver>/fpm/pool.d/novacrypto.conf`, socket `/run/php/novacrypto-fpm.sock` |
| Database | your site's (the plugin adds `<prefix>ncp_*` tables) | own MariaDB/MySQL database `novacrypto_wp` + user `novacrypto` |
| Web server | yours, untouched | own nginx site `novacrypto`; `https://your-domain`, or `http://<server-ip>:8116` without a domain |
| Background checks | `novacrypto-cron.timer` runs `wp cron event run --due-now` every minute | same |
| WP-CLI | its own copy in `/opt/novacrypto/bin/wp`, pinned to the site's PHP version; a system-wide `wp`, if any, is not touched | same |
| Never touched | other sites, other PHP-FPM pools, `nginx.conf`, `php.ini`, firewall policies, other databases | same |

## Requirements

- **Plugin requirements** (from the plugin header and code): WordPress 5.8+, PHP 7.4+. The **GMP or BCMath** extension is needed for HD-wallet (xpub) addresses and for reading EVM token amounts; this guide installs both, and GMP is the fast one. **curl** is recommended for the WordPress HTTP API. WooCommerce 5.0+ and Easy Digital Downloads are optional. Verified while writing this guide on WordPress 7.1.2 + WooCommerce 11.1.2.
- **Path A:** WordPress already running on this server, with PHP from apt (Ubuntu/Debian packages or the ondrej/sury repository), served by PHP-FPM or Apache mod_php. Sites on control panels with their own PHP builds (cPanel, Plesk, RunCloud, LiteSpeed) need the extensions installed through the panel. The preflight tells you.
- **Path B:** 1 vCPU / 1 GB RAM / 5 GB free disk. A domain with an A record pointing at the server is strongly recommended: customers paying real money expect HTTPS. Without one, the shop runs on `http://<server-ip>:8116`.
- **Outbound HTTPS** from the server, to block explorers and rate APIs (mempool.space, Blockstream, Etherscan, CoinGecko, …) and your Lightning backend. The plugin **polls**; no blockchain service calls back into your server.
- **Your own wallet:** receiving addresses, or an account-level extended public key (`zpub…`, `xpub…`, `Ltub…`, `dgub…`). Never a seed phrase or private key: the plugin refuses `xprv`.
- **GitHub access** to `cyberkyd01/novacrypto-pay` (private): a read-only deploy key, created in step 5.

## 1. Connect and set your variables

```bash
ssh you@your-server-ip
```

Edit the values, then paste the whole block. This file is the **only** place you change settings; every later step reads it. If it already exists it is left alone; edit it with `sudo nano /etc/novacrypto/deploy.conf`. The last three values are filled in automatically by step A1/A2 or B1.

```bash
sudo mkdir -p /etc/novacrypto
[ -f /etc/novacrypto/deploy.conf ] && echo "Keeping existing /etc/novacrypto/deploy.conf" || sudo tee /etc/novacrypto/deploy.conf >/dev/null <<'EOF'
# NovaCrypto Pay deployment settings: the only file you edit. Every step of DEPLOY.md reads it.
APP=novacrypto
APP_USER=novacrypto
APP_HOME=/opt/novacrypto
REPO_URL=git@github.com:cyberkyd01/novacrypto-pay.git
BRANCH=main
# existing = Path A (a WordPress already on this server)   fresh = Path B (new isolated WordPress)
MODE=existing
# Path A: your WordPress folder, the one containing wp-load.php (preflight lists candidates).
# Path B: leave it; step B1 sets it to /opt/novacrypto/site.
WP_ROOT=/var/www/html
# Path B only: the new site's domain (empty = http://<server-ip>:PUBLIC_PORT), admin email and login.
DOMAIN=
EMAIL=you@example.com
WP_ADMIN_USER=ncp-admin
PUBLIC_PORT=8116
# Filled in by step A1/A2 or B1 (edit only if detection got it wrong):
PHPV=
WP_USER=
PLUGINS_DIR=
EOF
cat /etc/novacrypto/deploy.conf
```

## 2. Preflight checks

This step only reads, it changes nothing. Read every `STOP` line and fix it before you go on.

```bash
sudo bash <<'EOF'
source /etc/novacrypto/deploy.conf
ok()   { printf 'OK    %s\n' "$*"; }
warn() { printf 'WARN  %s\n' "$*"; }
stop() { printf 'STOP  %s\n' "$*"; }
. /etc/os-release
case "$ID-$VERSION_ID" in
  ubuntu-22.04|ubuntu-24.04|debian-12) ok "OS: $PRETTY_NAME" ;;
  *) warn "OS: $PRETTY_NAME is untested (expected Ubuntu 22.04/24.04 or Debian 12)" ;;
esac
case "$(uname -m)" in x86_64|aarch64) ok "CPU: $(uname -m)" ;; *) stop "CPU $(uname -m) is not supported" ;; esac
echo "----- WordPress sites found on this server:"
FOUND=$(find /var/www /srv /home /opt -maxdepth 5 -name wp-load.php -not -path "$APP_HOME/*" 2>/dev/null | sed 's#/wp-load.php$##')
if [ -n "$FOUND" ]; then echo "$FOUND" | sed 's#^#      #'; else echo "      (none)"; fi
echo "----- PHP-FPM versions installed: $(ls -d /etc/php/*/fpm 2>/dev/null | cut -d/ -f4 | tr '\n' ' ')"
WEB=$(ss -ltnpH '( sport = :80 or sport = :443 )' | grep -o 'users:(("[^"]*' | cut -d'"' -f2 | sort -u | tr '\n' ' ')
echo "----- Web server on 80/443: ${WEB:-none}"
if systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql 2>/dev/null; then echo "----- MySQL/MariaDB: running"; else echo "----- MySQL/MariaDB: not running here"; fi
if curl -fsS -o /dev/null --max-time 10 https://mempool.space/api/blocks/tip/height; then ok "Outbound HTTPS to block explorers works"; else stop "The server can't reach https://mempool.space: payment detection needs outbound HTTPS"; fi
case "$MODE" in
  existing)
    if [ -f "$WP_ROOT/wp-load.php" ]; then ok "Path A: WordPress found in $WP_ROOT"; else stop "Path A: no wp-load.php in WP_ROOT=$WP_ROOT. Set WP_ROOT to one of the folders listed above"; fi
    if [ -d "$WP_ROOT/wp-content/plugins/novacrypto-pay" ]; then warn "novacrypto-pay is already in this site: deploy.sh will replace it and keep the old copy"; fi
    ;;
  fresh)
    if [ -n "$FOUND" ]; then warn "Path B, but WordPress already exists here: Path A (MODE=existing) is usually what you want"; fi
    case "$WEB" in "") ok "Ports 80/443 are free (step B5 installs nginx)" ;; "nginx ") ok "Ports 80/443: nginx (step B5 adds one site)" ;; *) stop "Ports 80/443 belong to: $WEB. Use the Caddy/Apache appendix instead of step B5" ;; esac
    if [ -n "$DOMAIN" ]; then
      IP=$(curl -4fsS --max-time 10 https://api.ipify.org || true)
      DNS=$(getent ahostsv4 "$DOMAIN" | awk 'NR==1 {print $1}')
      if [ -n "$IP" ] && [ "$DNS" = "$IP" ]; then ok "DNS: $DOMAIN -> $DNS"; else stop "DNS: $DOMAIN resolves to '${DNS:-nothing}', expected ${IP:-the server IP}. Create the A record and wait"; fi
    elif ss -ltnH "sport = :$PUBLIC_PORT" | grep -q .; then stop "No-domain mode: port $PUBLIC_PORT is in use; change PUBLIC_PORT in deploy.conf"
    else ok "No-domain mode: port $PUBLIC_PORT is free"; fi
    ;;
  *) stop "MODE must be 'existing' or 'fresh' (it is '$MODE')" ;;
esac
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then warn "ufw is active: Path B adds only 80/443 (or $PUBLIC_PORT)"; fi
EOF
```

## 3. Install system packages

Additive only: nothing is upgraded or removed.

```bash
sudo bash -euo pipefail <<'EOF'
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1   # additive only: needrestart must not restart other services
source /etc/novacrypto/deploy.conf
export DEBIAN_FRONTEND=noninteractive
apt-get update
MISSING_PKGS=""; for p in ca-certificates curl git rsync openssl; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'ok installed' || MISSING_PKGS="$MISSING_PKGS $p"; done
if [ -n "$MISSING_PKGS" ]; then apt-get install -y --no-install-recommends $MISSING_PKGS; fi   # only what's missing - never upgrades installed packages
echo "Packages OK"
EOF
```

## 4. Create the app user and folders

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
id -u "$APP_USER" >/dev/null 2>&1 || useradd --system --home-dir "$APP_HOME" --shell /usr/sbin/nologin "$APP_USER"
install -d -m 755 -o root -g root "$APP_HOME" "$APP_HOME/bin"
install -d -m 750 -o "$APP_USER" -g "$APP_USER" "$APP_HOME/repo" "$APP_HOME/logs"
install -d -m 700 -o root -g root "$APP_HOME/backups"
ls -la "$APP_HOME"
EOF
```

## 5. Give the server read access to the repo

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
install -d -m 700 -o "$APP_USER" -g "$APP_USER" "$APP_HOME/.ssh"
[ -f "$APP_HOME/.ssh/id_ed25519" ] || sudo -u "$APP_USER" ssh-keygen -q -t ed25519 -N "" -C "$APP-deploy@$(hostname)" -f "$APP_HOME/.ssh/id_ed25519"
touch "$APP_HOME/.ssh/known_hosts"
grep -q '^github.com ' "$APP_HOME/.ssh/known_hosts" || ssh-keyscan -t ed25519 github.com 2>/dev/null >> "$APP_HOME/.ssh/known_hosts"
chown "$APP_USER:$APP_USER" "$APP_HOME/.ssh/known_hosts"
echo; echo "Add this key at https://github.com/cyberkyd01/novacrypto-pay/settings/keys/new"
echo "(title: $(hostname), leave 'Allow write access' unticked):"; echo
cat "$APP_HOME/.ssh/id_ed25519.pub"
EOF
```

Paste the printed `ssh-ed25519 …` line as a new deploy key, then test:

```bash
sudo -u novacrypto -H ssh -T git@github.com
```

You should see `Hi cyberkyd01/novacrypto-pay! You've successfully authenticated, but GitHub does not provide shell access.` (If the repo is ever public, skip the key and set `REPO_URL=https://github.com/cyberkyd01/novacrypto-pay.git`.)

## 6. Install WP-CLI (isolated)

This installs a private copy of WP-CLI at `/opt/novacrypto/bin/wp`. A small wrapper always runs it with the **same PHP version as the site** (`PHPV`), because a server's default `php` command is often a different version from the one serving WordPress. The download is verified against WP-CLI's published SHA-512.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
BASE="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar"
TMP="$(mktemp -d)"
curl -fsSL "$BASE/wp-cli.phar" -o "$TMP/wp-cli.phar"
curl -fsSL "$BASE/wp-cli.phar.sha512" -o "$TMP/wp-cli.phar.sha512"
(cd "$TMP" && echo "$(cat wp-cli.phar.sha512)  wp-cli.phar" | sha512sum -c -)
install -m 644 -o root -g root "$TMP/wp-cli.phar" "$APP_HOME/bin/wp-cli.phar"
rm -rf "$TMP"
cat > "$APP_HOME/bin/wp" <<'WPCLI'
#!/bin/sh
# WP-CLI for NovaCrypto Pay, pinned to the PHP version that serves the site (PHPV in /etc/novacrypto/deploy.conf).
. /etc/novacrypto/deploy.conf
PHPBIN="/usr/bin/php${PHPV}"
[ -n "$PHPV" ] && [ -x "$PHPBIN" ] || PHPBIN="$(command -v php)"
[ -n "$PHPBIN" ] || { echo "No PHP CLI found: finish step A2 or B1 first" >&2; exit 1; }
export WP_CLI_CACHE_DIR="${WP_CLI_CACHE_DIR:-/tmp/novacrypto-wp-cli-$(id -u)}"
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
exec "$PHPBIN" -d memory_limit=512M -d display_errors=stderr "$APP_HOME/bin/wp-cli.phar" "$@"
WPCLI
chmod 755 "$APP_HOME/bin/wp"
echo "WP-CLI installed at $APP_HOME/bin/wp"
EOF
```

The `memory_limit=512M` is there because `wp core download` runs out of memory under PHP's 128 MB default. `display_errors=stderr` keeps PHP notices out of the values the scripts read from WP-CLI.

Now follow **Path A** or **Path B**, then continue with step 7.

## Path A: your existing WordPress

### A1. Detect the site's PHP version and user

This finds the web server config that serves `WP_ROOT`, follows it to the PHP-FPM pool, and records that pool's PHP version (`PHPV`) and user (`WP_USER`) in `deploy.conf`. Values you already set there are kept.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
CONF=/etc/novacrypto/deploy.conf
setconf() { if grep -q "^$1=" "$CONF"; then sed -i "s|^$1=.*|$1=$2|" "$CONF"; else echo "$1=$2" >> "$CONF"; fi; }
[ "$MODE" = existing ] || { echo "MODE=$MODE: Path A does not apply, skip to Path B"; exit 0; }
[ -f "$WP_ROOT/wp-load.php" ] || { echo "STOP: no wp-load.php in $WP_ROOT. Set WP_ROOT in $CONF"; exit 1; }
SOCK=""; POOL=""; HOW=""; DET_PHPV=""; DET_USER=""
VHOSTS=$(grep -rlsF "$WP_ROOT" /etc/nginx /etc/apache2/sites-enabled 2>/dev/null || true)
if [ -n "$VHOSTS" ]; then
  SOCK=$(grep -hoE 'unix:[^ ;|"]+\.sock' $VHOSTS | head -1 | sed 's/^unix://' || true)
  if [ -z "$SOCK" ]; then   # nginx "fastcgi_pass <upstream>;" defined elsewhere
    UP=$(grep -hoE 'fastcgi_pass[[:space:]]+[A-Za-z0-9_.-]+;' $VHOSTS | head -1 | awk '{print $2}' | tr -d ';' || true)
    if [ -n "$UP" ]; then SOCK=$(grep -rhsA6 "upstream[[:space:]]*$UP" /etc/nginx | grep -oE 'unix:[^ ;]+\.sock' | head -1 | sed 's/^unix://' || true); fi
  fi
fi
if [ -n "$SOCK" ]; then
  S="${SOCK#/var}"
  POOL=$(grep -lsE "^[[:space:]]*listen[[:space:]]*=[[:space:]]*(/var)?$S[[:space:]]*$" /etc/php/*/fpm/pool.d/*.conf | head -1 || true)
fi
if [ -n "$POOL" ]; then
  DET_PHPV=$(echo "$POOL" | cut -d/ -f4)
  DET_USER=$(grep -E '^[[:space:]]*user[[:space:]]*=' "$POOL" | head -1 | cut -d= -f2 | tr -d ' ')
  HOW="PHP-FPM pool $POOL (socket $SOCK)"
elif [ "$(ls -d /etc/php/*/fpm 2>/dev/null | wc -l)" -eq 1 ]; then
  DET_PHPV=$(ls -d /etc/php/*/fpm | cut -d/ -f4); HOW="the only PHP-FPM version installed"
elif ls /etc/apache2/mods-enabled/php*.load >/dev/null 2>&1; then
  DET_PHPV=$(ls /etc/apache2/mods-enabled/php*.load | head -1 | sed -E 's#.*/php([0-9.]+)\.load#\1#'); HOW="Apache mod_php"
fi
FILES_OWNER=$(stat -c %U "$WP_ROOT/wp-content")
[ -n "$DET_USER" ] || DET_USER="$FILES_OWNER"
[ "$DET_USER" != root ] || DET_USER=www-data
if [ -z "$PHPV" ] && [ -n "$DET_PHPV" ]; then setconf PHPV "$DET_PHPV"; fi
if [ -z "$WP_USER" ]; then setconf WP_USER "$DET_USER"; fi
source "$CONF"
[ -n "$PHPV" ] || { echo "STOP: could not tell which PHP serves $WP_ROOT. Set PHPV= (for example 8.3) in $CONF, then run this again"; exit 1; }
echo "PHP version : $PHPV   (from: ${HOW:-your deploy.conf})"
echo "WordPress runs as: $WP_USER   (site files owned by: $FILES_OWNER)"
EOF
```

If either value is wrong, correct `PHPV` / `WP_USER` in `/etc/novacrypto/deploy.conf` (e.g. `PHPV=8.3`, `WP_USER=www-data`).

### A2. PHP extensions for that PHP version

This adds what's missing among `php<ver>-gmp`, `php<ver>-bcmath`, `php<ver>-curl`, plus `php<ver>-cli` (needed by WP-CLI), **for the site's PHP version only**. Then it gracefully reloads only that PHP's FPM service (or Apache, for mod_php), and only if something was installed. Last, it asks WordPress where its plugins folder is, so custom layouts like Bedrock work.

```bash
sudo bash -euo pipefail <<'EOF'
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1   # additive only: needrestart must not restart other services
source /etc/novacrypto/deploy.conf
CONF=/etc/novacrypto/deploy.conf
setconf() { if grep -q "^$1=" "$CONF"; then sed -i "s|^$1=.*|$1=$2|" "$CONF"; else echo "$1=$2" >> "$CONF"; fi; }
[ "$MODE" = existing ] || { echo "MODE=$MODE: Path A does not apply"; exit 0; }
[ -n "$PHPV" ] && [ -n "$WP_USER" ] || { echo "Run step A1 first"; exit 1; }
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-cache show "php$PHPV-gmp" >/dev/null 2>&1 || { echo "STOP: apt has no php$PHPV-gmp. This PHP doesn't come from Debian/Ubuntu or ondrej/sury (control panel or custom build): install GMP/BCMath through your panel, then continue with A2's last part by hand"; exit 1; }
NEED=()
[ -x "/usr/bin/php$PHPV" ] || NEED+=("php$PHPV-cli")
if [ -x "/usr/sbin/php-fpm$PHPV" ]; then MODS=$("/usr/sbin/php-fpm$PHPV" -m 2>/dev/null || true)
elif [ -x "/usr/bin/php$PHPV" ]; then MODS=$("/usr/bin/php$PHPV" -m 2>/dev/null || true)
else MODS=""; fi
for ext in gmp bcmath curl; do echo "$MODS" | grep -qix "$ext" || NEED+=("php$PHPV-$ext"); done
if [ ${#NEED[@]} -gt 0 ]; then
  echo "Installing: ${NEED[*]}"
  MISSING_PKGS=""; for p in "${NEED[@]}"; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'ok installed' || MISSING_PKGS="$MISSING_PKGS $p"; done
  if [ -n "$MISSING_PKGS" ]; then apt-get install -y --no-install-recommends $MISSING_PKGS; fi   # only what's missing - never upgrades installed packages
  if systemctl is-active --quiet "php$PHPV-fpm" 2>/dev/null; then
    "/usr/sbin/php-fpm$PHPV" -t && systemctl reload "php$PHPV-fpm" && echo "Reloaded php$PHPV-fpm (graceful: no requests dropped)"
  elif [ -e "/etc/apache2/mods-enabled/php$PHPV.load" ]; then
    apache2ctl configtest && systemctl reload apache2 && echo "Reloaded apache2 (graceful)"
  fi
else
  echo "PHP $PHPV already has gmp, bcmath and curl: nothing installed, nothing reloaded"
fi
"/usr/bin/php$PHPV" -m | grep -iE '^(gmp|bcmath|curl)$'
PD=$(sudo -u "$WP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" plugin path)
[ -d "$PD" ] || { echo "STOP: WordPress did not report its plugins folder (see the error above)"; exit 1; }
[ -n "$PLUGINS_DIR" ] || setconf PLUGINS_DIR "$PD"
grep -E '^(PHPV|WP_USER|PLUGINS_DIR)=' "$CONF"
EOF
```

You should see `bcmath`, `curl`, `gmp`, and then the three settings filled in. Continue with **step 7**.

## Path B: a fresh, isolated WordPress for NovaCrypto Pay

Set `MODE=fresh` (and optionally `DOMAIN`) in `/etc/novacrypto/deploy.conf` first. Skip this whole part if you did Path A.

### B1. PHP-FPM, extensions and MariaDB

This reuses the newest PHP-FPM already installed, or installs the distro's (`php-fpm`: 8.1 on Ubuntu 22.04, 8.2 on Debian 12, 8.3 on Ubuntu 24.04). It adds the extensions WordPress, WooCommerce and NovaCrypto Pay need for that version. It installs MariaDB only if no MySQL/MariaDB server is present; an existing one is reused, with a new database in B2.

```bash
sudo bash -euo pipefail <<'EOF'
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1   # additive only: needrestart must not restart other services
source /etc/novacrypto/deploy.conf
CONF=/etc/novacrypto/deploy.conf
setconf() { if grep -q "^$1=" "$CONF"; then sed -i "s|^$1=.*|$1=$2|" "$CONF"; else echo "$1=$2" >> "$CONF"; fi; }
[ "$MODE" = fresh ] || { echo "MODE=$MODE: Path B does not apply"; exit 0; }
export DEBIAN_FRONTEND=noninteractive
apt-get update
V=$(ls -d /etc/php/*/fpm 2>/dev/null | cut -d/ -f4 | sort -V | tail -1 || true)
if [ -z "$V" ]; then apt-get install -y --no-install-recommends php-fpm; V=$(ls -d /etc/php/*/fpm | cut -d/ -f4 | sort -V | tail -1); fi
[ "$(printf '%s\n' 7.4 "$V" | sort -V | head -1)" = 7.4 ] || { echo "STOP: PHP $V is too old (WordPress needs 7.4+)"; exit 1; }
MISSING_PKGS=""; for p in "php$V-fpm" "php$V-cli" "php$V-mysql" "php$V-curl" "php$V-gd" "php$V-mbstring" "php$V-xml" "php$V-zip" "php$V-intl" "php$V-gmp" "php$V-bcmath"; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'ok installed' || MISSING_PKGS="$MISSING_PKGS $p"; done
if [ -n "$MISSING_PKGS" ]; then apt-get install -y --no-install-recommends $MISSING_PKGS; fi   # only what's missing - never upgrades installed packages
if systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql 2>/dev/null; then
  echo "Using the MySQL/MariaDB server that is already running"
elif dpkg -l 2>/dev/null | grep -qE '^ii +(mariadb-server|mysql-server)'; then
  echo "STOP: a MySQL/MariaDB server is installed but not running. Start it, then run this again"; exit 1
else
  MISSING_PKGS=""; for p in mariadb-server; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'ok installed' || MISSING_PKGS="$MISSING_PKGS $p"; done
  if [ -n "$MISSING_PKGS" ]; then apt-get install -y --no-install-recommends $MISSING_PKGS; fi   # only what's missing - never upgrades installed packages
fi
setconf PHPV "$V"
setconf WP_USER "$APP_USER"
setconf WP_ROOT "$APP_HOME/site"
setconf PLUGINS_DIR "$APP_HOME/site/wp-content/plugins"
grep -E '^(PHPV|WP_USER|WP_ROOT|PLUGINS_DIR)=' "$CONF"
EOF
```

If PHP $V was already serving other sites, the added extensions become available to them too. That's harmless; nothing of theirs is reconfigured.

### B2. Database

This creates database `novacrypto_wp` and user `novacrypto@localhost` with a generated password, stored once in `/etc/novacrypto/db.env` (root only, never regenerated).

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
[ "$MODE" = fresh ] || { echo "MODE=$MODE: Path B does not apply"; exit 0; }
DBENV="/etc/$APP/db.env"
if [ ! -s "$DBENV" ]; then (umask 077; printf 'DB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' novacrypto_wp novacrypto "$(openssl rand -hex 24)" > "$DBENV"); fi
source "$DBENV"
if ! mysql -e 'SELECT 1' >/dev/null 2>&1; then
  echo "STOP: root can't log in to MySQL/MariaDB without a password on this server."
  echo "Run 'sudo mysql -u root -p' and paste these lines, then continue with B3:"
  echo "  CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  echo "  CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  echo "  GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
  exit 1
fi
mysql <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" -e "SELECT 'database OK' AS status" "$DB_NAME"
EOF
```

You should see `database OK`.

### B3. PHP-FPM pool

This creates its own pool `novacrypto`, running as user `novacrypto` on socket `/run/php/novacrypto-fpm.sock`. Only the web server's user may connect to the socket. The config is tested before PHP-FPM is reloaded (gracefully); if the test fails, the new file is removed again. Other pools are not edited.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
[ "$MODE" = fresh ] || { echo "MODE=$MODE: Path B does not apply"; exit 0; }
WEBUSER=$(ps -o user= -C nginx,caddy,apache2,httpd 2>/dev/null | grep -vx root | head -1 || true)
WEBUSER=${WEBUSER:-www-data}
POOL="/etc/php/$PHPV/fpm/pool.d/$APP.conf"
cat > "$POOL" <<FPM
; NovaCrypto Pay site (DEPLOY.md step B3)
[$APP]
user = $APP_USER
group = $APP_USER
listen = /run/php/$APP-fpm.sock
listen.owner = $WEBUSER
listen.group = $WEBUSER
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_flag[log_errors] = on
php_admin_value[error_log] = $APP_HOME/logs/php-error.log
FPM
if ! "/usr/sbin/php-fpm$PHPV" -t; then rm -f "$POOL"; echo "PHP-FPM config test failed: pool file removed"; exit 1; fi
systemctl reload "php$PHPV-fpm"
sleep 1
ls -l "/run/php/$APP-fpm.sock"
EOF
```

You should see the socket, owned by the web server's user (`www-data`).

### B4. WordPress and WooCommerce

As user `novacrypto`, this downloads WordPress into `/opt/novacrypto/site`, creates `wp-config.php` (mode 640), installs the site, sets pretty permalinks (the plugin's pay pages `/ncp-pay/…` and `/ncp-link/…` need them) and installs WooCommerce. It also turns off page-load WP-Cron, because the timer in step 8 runs cron instead. The admin password is generated once and saved in `/etc/novacrypto/wp-admin.txt`.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
source "/etc/$APP/db.env"
[ "$MODE" = fresh ] || { echo "MODE=$MODE: Path B does not apply"; exit 0; }
wpc() { sudo -u "$APP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" "$@"; }
install -d -m 755 -o "$APP_USER" -g "$APP_USER" "$WP_ROOT"
[ -f "$WP_ROOT/wp-load.php" ] || wpc core download --quiet
[ -f "$WP_ROOT/wp-config.php" ] || wpc config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost=localhost --quiet
chmod 640 "$WP_ROOT/wp-config.php"
if [ -n "$DOMAIN" ]; then URL="https://$DOMAIN"; else URL="http://$(curl -4fsS --max-time 10 https://api.ipify.org):$PUBLIC_PORT"; fi
if ! wpc core is-installed 2>/dev/null; then
  PASS=$(openssl rand -base64 18 | tr -d '/+=')
  wpc core install --url="$URL" --title="NovaCrypto Pay" --admin_user="$WP_ADMIN_USER" --admin_password="$PASS" --admin_email="$EMAIL" --skip-email
  (umask 077; printf 'URL=%s/wp-admin/\nUSER=%s\nPASSWORD=%s\n' "$URL" "$WP_ADMIN_USER" "$PASS" > "/etc/$APP/wp-admin.txt")
fi
wpc rewrite structure '/%postname%/' --quiet
wpc plugin is-installed woocommerce || wpc plugin install woocommerce --quiet
wpc plugin activate woocommerce --quiet
wpc config set DISABLE_WP_CRON true --raw --quiet
wpc core version
cat "/etc/$APP/wp-admin.txt"
EOF
```

### B5. Put it on your domain (nginx + HTTPS), or on port 8116

This adds only `/etc/nginx/sites-available/novacrypto` (or `/etc/nginx/conf.d/novacrypto.conf` on nginx.org packages). With `DOMAIN` set it listens on 80 for that name only; with `DOMAIN` empty it listens on `PUBLIC_PORT` (8116) with no `server_name`. nginx is reloaded, never restarted.

```bash
sudo bash -euo pipefail <<'EOF'
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1   # additive only: needrestart must not restart other services
source /etc/novacrypto/deploy.conf
[ "$MODE" = fresh ] || { echo "MODE=$MODE: Path B does not apply"; exit 0; }
WEB=$(ss -ltnpH '( sport = :80 or sport = :443 )' | grep -o 'users:(("[^"]*' | cut -d'"' -f2 | sort -u | tr '\n' ' ')
case "$WEB" in ""|"nginx ") ;; *) echo "Ports 80/443 belong to: $WEB. Use the Caddy/Apache appendix instead."; exit 1 ;; esac
command -v nginx >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nginx
if [ -d /etc/nginx/sites-available ]; then CONF="/etc/nginx/sites-available/$APP"; else CONF="/etc/nginx/conf.d/$APP.conf"; fi
if [ -n "$DOMAIN" ]; then P=80; NAME="server_name $DOMAIN;"; else P="$PUBLIC_PORT"; NAME=""; fi
LISTEN6=""; if [ -e /proc/net/if_inet6 ]; then LISTEN6="listen [::]:$P;"; fi
cat > "$CONF" <<NGINX
# NovaCrypto Pay WordPress (DEPLOY.md step B5)
server {
    listen $P;
    $LISTEN6
    $NAME
    root $WP_ROOT;
    index index.php;
    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_pass unix:/run/php/$APP-fpm.sock;
        fastcgi_read_timeout 120s;
    }
    location = /xmlrpc.php { deny all; }
    location ~* ^/wp-content/uploads/.*\.php\$ { deny all; }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
if [ "$CONF" = "/etc/nginx/sites-available/$APP" ]; then ln -sfn "$CONF" "/etc/nginx/sites-enabled/$APP"; fi
nginx -t
if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl start nginx; fi
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then
  if [ -n "$DOMAIN" ]; then ufw allow 80/tcp; ufw allow 443/tcp; else ufw allow "$PUBLIC_PORT/tcp"; fi
fi
echo "nginx site $CONF is active"
EOF
```

**HTTPS** (domain mode only). This checks DNS first; certbot then edits only the `novacrypto` server block.

```bash
sudo bash -euo pipefail <<'EOF'
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1   # additive only: needrestart must not restart other services
source /etc/novacrypto/deploy.conf
[ "$MODE" = fresh ] && [ -n "$DOMAIN" ] || { echo "No domain set: skipping HTTPS (site is on http://<server-ip>:$PUBLIC_PORT)"; exit 0; }
IP=$(curl -4fsS --max-time 10 https://api.ipify.org)
DNS=$(getent ahostsv4 "$DOMAIN" | awk 'NR==1 {print $1}')
[ "$DNS" = "$IP" ] || { echo "STOP: $DOMAIN resolves to '${DNS:-nothing}', not $IP. Fix the A record, wait, run again."; exit 1; }
if ! command -v certbot >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends certbot python3-certbot-nginx
elif dpkg -s certbot >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends python3-certbot-nginx
fi
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect
EOF
```

## 7. Create the deploy script, deploy and activate

`/opt/novacrypto/bin/deploy.sh` does the first install and every update, for both paths:

1. Fetches the branch and syntax-checks every PHP file with the site's PHP version.
2. Copies the plugin into a temporary folder **inside** the plugins directory (`.novacrypto-pay.new`; WordPress ignores dot-folders), owned like the other plugins. `tests/` is left out, because it is a standalone script that shouldn't be web-reachable.
3. Swaps it in with two renames, which leaves a gap of microseconds.
4. If the plugin was active, it checks that WordPress loads the new version, and swaps the old one back if not.
5. Keeps the previous copy in `/opt/novacrypto/backups/plugin/` (last 3) for rollback.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
cat > "$APP_HOME/bin/deploy.sh" <<'DEPLOY'
#!/usr/bin/env bash
# Deploys the latest commit of $BRANCH into WordPress's plugins folder. First install and every update. Safe to re-run.
set -Eeuo pipefail
source /etc/novacrypto/deploy.conf
[ "$(id -u)" -eq 0 ] || { echo "Run with: sudo $0"; exit 1; }

SLUG=novacrypto-pay
REPO="$APP_HOME/repo"; KEEP="$APP_HOME/backups/plugin"
for v in WP_ROOT WP_USER PHPV PLUGINS_DIR; do [ -n "${!v}" ] || { echo "$v is empty in /etc/$APP/deploy.conf: finish step A1/A2 (Path A) or B1 (Path B) first"; exit 1; }; done
[ -d "$PLUGINS_DIR" ] || { echo "The plugins folder $PLUGINS_DIR does not exist"; exit 1; }
as_app() { sudo -u "$APP_USER" -H bash -euo pipefail -c "$1"; }
wpc() { sudo -u "$WP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" "$@"; }

echo "==> Fetching $BRANCH"
if [ ! -d "$REPO/.git" ]; then as_app "git clone --quiet '$REPO_URL' '$REPO'"; fi
as_app "cd '$REPO' && git fetch --quiet --prune origin '$BRANCH' && git reset --quiet --hard 'origin/$BRANCH'"
SHA="$(as_app "cd '$REPO' && git rev-parse --short HEAD")"
SRC="$REPO/$SLUG"
[ -f "$SRC/$SLUG.php" ] || { echo "$SRC/$SLUG.php is missing from the repo"; exit 1; }

echo "==> PHP $PHPV syntax check"
find "$SRC" -name '*.php' -not -path '*/tests/*' -print0 | xargs -0 -n1 "/usr/bin/php$PHPV" -l >/dev/null

DEST="$PLUGINS_DIR/$SLUG"; NEW="$PLUGINS_DIR/.$SLUG.new"; OLD="$PLUGINS_DIR/.$SLUG.old"
OWNER="$(stat -c %U:%G "$PLUGINS_DIR")"
echo "==> Copying into $NEW (owner $OWNER)"
rm -rf "$NEW"
rsync -a --delete --exclude 'tests/' --chown="$OWNER" "$SRC/" "$NEW/"
chmod -R u=rwX,go=rX "$NEW"

ACTIVE=no
if [ -d "$DEST" ] && wpc plugin is-active "$SLUG" >/dev/null 2>&1; then ACTIVE=yes; fi

echo "==> Switching $DEST"
rm -rf "$OLD"
if [ -d "$DEST" ]; then mv "$DEST" "$OLD"; fi
mv "$NEW" "$DEST"

# PHP only re-reads changed files on its own when opcache checks timestamps (the default).
if [ -x "/usr/sbin/php-fpm$PHPV" ] && "/usr/sbin/php-fpm$PHPV" -i 2>/dev/null | grep -q '^opcache.validate_timestamps => Off'; then
  systemctl reload "php$PHPV-fpm" && echo "    Reloaded php$PHPV-fpm (opcache timestamps are off)"
fi

if [ "$ACTIVE" = yes ]; then
  echo "==> Checking that WordPress loads the new version"
  ERR="$(mktemp)"
  if ! VER="$(wpc eval 'echo NCP_VERSION;' 2>"$ERR")"; then
    echo "!! WordPress failed with the new version: rolling back" >&2
    tail -n 8 "$ERR" >&2
    rm -rf "$NEW"; mv "$DEST" "$NEW"
    if [ -d "$OLD" ]; then mv "$OLD" "$DEST"; fi
    rm -rf "$NEW" "$ERR"
    echo "!! The previous version is back in place" >&2
    exit 1
  fi
  rm -f "$ERR"
  echo "    NovaCrypto Pay $VER is active"
fi

if [ -d "$OLD" ]; then
  mkdir -p "$KEEP"
  mv "$OLD" "$KEEP/$SLUG-$(date +%Y%m%d%H%M%S)"
  ls -1dt "$KEEP"/*/ | tail -n +4 | xargs -r rm -rf   # keep the last 3 copies
fi
echo "==> Deployed $SHA"
if [ "$ACTIVE" = no ]; then echo "    Not active yet: activate it (DEPLOY.md step 7)."; fi
DEPLOY
chmod 750 "$APP_HOME/bin/deploy.sh"
echo "deploy.sh written"
EOF
```

First deploy:

```bash
sudo /opt/novacrypto/bin/deploy.sh
```

The last lines should be `==> Deployed <commit>` and `Not active yet`. Now **activate** it. This creates the plugin's tables, schedules its two cron jobs, generates its REST API key and webhook secret, and registers the pay-page URLs:

```bash
source /etc/novacrypto/deploy.conf
sudo -u "$WP_USER" /opt/novacrypto/bin/wp --path="$WP_ROOT" plugin activate novacrypto-pay
```

You should see `Plugin 'novacrypto-pay' activated.` Activating changes nothing at your checkout yet. WooCommerce's *NovaCrypto Pay* method is enabled by default, but it only appears once at least one coin is set up (step 9). On a **multisite** network, add `--network` to activate it everywhere, or `--url=https://that-site` for one site.

## 8. Background payment checks (cron)

The plugin checks pending payments against the blockchain on a WordPress cron event that runs **every minute** (`ncp_cron_check_payments`). It also expires old payments, retries outgoing webhooks and polls Lightning invoices. It has a second, hourly event for log cleanup (`ncp_cron_maintenance`). Customers' open pay pages also poll while they're open. Plain WP-Cron only runs when someone visits the site, so a quiet shop could confirm payments late. This timer runs all due cron events every minute, as the site's own PHP user and with the site's PHP version:

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
[ -n "$WP_USER" ] && [ -n "$PHPV" ] || { echo "Finish Path A or B first"; exit 1; }
cat > "/etc/systemd/system/$APP-cron.service" <<UNIT
[Unit]
Description=NovaCrypto Pay: run due WordPress cron events (payment checks) for $WP_ROOT
After=network-online.target mariadb.service mysql.service

[Service]
Type=oneshot
User=$WP_USER
ExecStart=$APP_HOME/bin/wp --path=$WP_ROOT cron event run --due-now --quiet
Nice=10
TimeoutStartSec=600
PrivateTmp=true
NoNewPrivileges=true
UNIT
cat > "/etc/systemd/system/$APP-cron.timer" <<UNIT
[Unit]
Description=NovaCrypto Pay: WordPress cron every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now "$APP-cron.timer"
systemctl start "$APP-cron.service"
systemctl --no-pager --lines=0 status "$APP-cron.service" || true
systemctl list-timers "$APP-cron.timer" --no-pager
EOF
```

The status should show `status=0/SUCCESS`, and the timer a next run under a minute away. Runs never overlap: systemd waits for one to finish before the next. The plugin also holds its own lock.

**Page-load WP-Cron.** Path B has already turned it off. On **Path A** it's optional: with the timer running, page-load WP-Cron only adds work to visitors' requests. Turning it off is a site-wide change that affects all plugins, which is fine *because* the timer runs every due event. This writes one line to `wp-config.php` and leaves a marker, so the uninstall step knows to undo it:

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
[ "$MODE" = existing ] || { echo "Path B already did this"; exit 0; }
systemctl is-active --quiet "$APP-cron.timer" || { echo "Start the timer above first"; exit 1; }
"$APP_HOME/bin/wp" --allow-root --path="$WP_ROOT" config set DISABLE_WP_CRON true --raw
touch "/etc/$APP/disabled-wp-cron"
EOF
```

(`wp config` edits the file without loading WordPress, so `--allow-root` runs no site code as root. Undo it with the same command using `config delete DISABLE_WP_CRON`.)

## 9. Configure the plugin (required vs optional)

Sign in to `wp-admin` (Path B: the credentials are in `sudo cat /etc/novacrypto/wp-admin.txt`), then open **NovaCrypto Pay → Settings**.

**Required:**

1. **Coins & Wallets:** tick at least one coin and paste either receiving addresses (one per line) or an account-level extended public key: `zpub…` (native SegWit BTC), `xpub…` (Ethereum/EVM accounts), `Ltub…`/`xpub…` (Litecoin), `dgub…` (Dogecoin). With an xpub, every order gets a fresh address. Use **Preview addresses** and compare them with your wallet app before going live. Solana can't be derived from an xpub (ed25519): use static addresses; unique salted amounts tell payments apart.
2. **WooCommerce** (if you sell through it): **WooCommerce → Settings → Payments → NovaCrypto Pay** must be enabled. It is by default; check it with `sudo -u "$WP_USER" /opt/novacrypto/bin/wp --path="$WP_ROOT" wc payment_gateway get novacrypto --user=<admin login> --fields=id,enabled`.
3. **Permalinks** must not be *Plain*. The hosted pay pages (`/ncp-pay/<id>/`, `/ncp-link/<slug>/`) need them. Path B sets `/%postname%/`; on Path A, see *Settings → Permalinks*.
4. **General → Test mode** off for real money (it switches to testnets).

**Optional, by tab:**

| Setting | Where | When you need it |
|---|---|---|
| Etherscan API key (free) | Detection & APIs | Recommended for every EVM chain. Without it the plugin falls back to Blockscout / JSON-RPC log and balance detection |
| BlockCypher token, TronGrid key | Detection & APIs | Higher rate limits for BTC-family / Tron at volume |
| Custom RPC URLs (chain ID → Infura/Alchemy/own node), Solana RPC | Detection & APIs | Public RPCs are rate-limited; the Solana default is `https://api.mainnet-beta.solana.com` |
| Lightning: **LNbits** URL + **invoice/read key** | Detection & APIs → Lightning | Lightning payments through any node behind LNbits (self-hosted or lnbits.com). Also tick *Bitcoin Lightning* on Coins & Wallets |
| Lightning: **Lightning address** | Detection & APIs → Lightning | Alternative to LNbits. The provider must support LUD-21 `verify` (e.g. Alby; Wallet of Satoshi does not) |
| CoinMarketCap key | Exchange Rates | Only if you add CoinMarketCap as a rate provider. CoinGecko, CoinPaprika, Binance and Kraken need no key |
| Email / Telegram (bot token + chat ID) / Discord / Slack webhooks | Notifications | Alerts for paid or underpaid orders |
| Outgoing webhook URLs (IPN) | Notifications | Your own systems get signed POSTs on status changes: `X-NCP-Signature: sha256=HMAC_SHA256(raw_body, secret)`. The secret is generated on activation and shown under Tools & Logs |
| REST API key | Tools & Logs | For `POST /wp-json/ncp/v1/payments` from other apps (header `X-NCP-API-Key`). Generated on activation |

There are **no inbound webhook/IPN URLs to register** with any blockchain service, rate API or LNbits: the plugin polls them all from the cron job above. **Tools & Logs → System check** shows the PHP version, GMP/BCMath/curl, the next cron run and the permalink state on one screen.

## 10. Verify

```bash
source /etc/novacrypto/deploy.conf
W() { sudo -u "$WP_USER" /opt/novacrypto/bin/wp --path="$WP_ROOT" "$@"; }
W plugin get novacrypto-pay --fields=name,status,version
W eval 'echo NCP_Math::available() ? "big-number math OK\n" : "GMP/BCMath MISSING\n";'
W cron event list --fields=hook,next_run_relative,recurrence | grep ncp_
systemctl list-timers novacrypto-cron.timer --no-pager | head -2
W option get permalink_structure
curl -fsS "$(W option get home)/?rest_route=/ncp/v1/coins"; echo
```

Expected:

- `status active`, `version 1.0.0`.
- `big-number math OK`.
- `ncp_cron_check_payments … 1 minute` and `ncp_cron_maintenance … 1 hour`.
- A timer line with the next run.
- A permalink structure that isn't empty.
- A JSON array of your enabled coins (`[]` until you add one).

Then place a small test order, or create a payment link under **NovaCrypto Pay → Links & Invoices**, pay it, and watch it go *Detected → Confirming → Paid* under **Payments**. `W ncp check` runs one check pass by hand, and `W ncp rates` prints live rates.

## Updating

```bash
sudo /opt/novacrypto/bin/deploy.sh
```

This is the same path as the first deploy. Settings and payment records live in the database and are untouched; if a release changes the plugin's tables, it upgrades them itself on the next page load (`ncp_db_version`).

## Rolling back

`deploy.sh` restores the previous copy by itself if WordPress fails to load the new one. To go back by hand to the newest saved copy:

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
LATEST="$(ls -1dt "$APP_HOME"/backups/plugin/*/ 2>/dev/null | head -1)"
[ -n "$LATEST" ] || { echo "No saved copy yet"; exit 1; }
D="$PLUGINS_DIR/novacrypto-pay"
rsync -a --delete --chown="$(stat -c %U:%G "$PLUGINS_DIR")" "$LATEST" "$PLUGINS_DIR/.novacrypto-pay.new/"
mv "$D" "$PLUGINS_DIR/.novacrypto-pay.old" && mv "$PLUGINS_DIR/.novacrypto-pay.new" "$D"
rm -rf "$PLUGINS_DIR/.novacrypto-pay.old"
echo "Restored $LATEST"
EOF
```

The next `deploy.sh` installs the branch's latest commit again, so fix or revert the problem in the repo first.

## Logs, status, restart

```bash
source /etc/novacrypto/deploy.conf
journalctl -u novacrypto-cron.service -n 50 --no-pager           # cron runs (errors show here)
systemctl list-timers novacrypto-cron.timer --no-pager
sudo -u "$WP_USER" /opt/novacrypto/bin/wp --path="$WP_ROOT" cron event list --fields=hook,next_run_relative | grep ncp_
sudo tail -n 50 /opt/novacrypto/logs/php-error.log                # Path B PHP errors (Path A: your site's error log)
```

To restart PHP gracefully (Path B, or Path A sites on PHP-FPM), run `sudo systemctl reload php<version>-fpm`, with the `PHPV` from deploy.conf. There is no NovaCrypto service to restart: the plugin runs inside WordPress.

Plugin-level logs (explorer errors, webhook deliveries, status changes) are in **NovaCrypto Pay → Tools & Logs**, kept 30 days. Turn on *Advanced → Debug logging* for more detail.

## Backups and restore

The plugin's data lives in the WordPress database: the tables `<prefix>ncp_payments`, `ncp_transactions`, `ncp_links`, `ncp_logs`, `ncp_webhook_queue`, and the options `ncp_settings`, `ncp_wallets`, `ncp_api_key`, `ncp_webhook_secret`, plus `woocommerce_novacrypto_settings`. The plugin files can always be redeployed. On **Path A** your site's normal database backup already covers all of this. This block saves just NovaCrypto Pay's part. On **Path B** it saves the whole site.

```bash
sudo bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
W() { sudo -u "$WP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" "$@"; }
B="$APP_HOME/backups/data"; D="$(date +%F-%H%M)"; mkdir -p "$B"
if [ "$MODE" = fresh ]; then
  W db export - > "$B/wordpress-$D.sql"
  tar -czf "$B/uploads-$D.tgz" -C "$WP_ROOT/wp-content" uploads
else
  P="$(W db prefix)"
  W db export - --tables="${P}ncp_payments,${P}ncp_transactions,${P}ncp_links,${P}ncp_logs,${P}ncp_webhook_queue" > "$B/ncp-tables-$D.sql"
  for o in ncp_settings ncp_wallets ncp_api_key ncp_webhook_secret woocommerce_novacrypto_settings; do
    W option get "$o" --format=json > "$B/$o-$D.json" 2>/dev/null || rm -f "$B/$o-$D.json"
  done
fi
chmod 600 "$B"/*
ls -lh "$B"
EOF
```

Copy `/opt/novacrypto/backups/data/` off the server. The files hold your xpubs, which are public keys but still reveal your whole address history, plus the API key and webhook secret. **Tools & Logs → Export** also downloads the settings (with wallets) as JSON.

**Restore.** Set `STAMP` to the backup's date-time:

```bash
sudo STAMP=YYYY-MM-DD-HHMM bash -euo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
W() { sudo -u "$WP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" "$@"; }
B="$APP_HOME/backups/data"
if [ "$MODE" = fresh ]; then
  W db import - < "$B/wordpress-$STAMP.sql"
  tar -xzf "$B/uploads-$STAMP.tgz" -C "$WP_ROOT/wp-content"
  chown -R "$WP_USER:$WP_USER" "$WP_ROOT/wp-content/uploads"
else
  W db import - < "$B/ncp-tables-$STAMP.sql"
  for f in "$B"/*-"$STAMP".json; do o="$(basename "$f" "-$STAMP.json")"; W option update "$o" --format=json < "$f"; done
fi
W cache flush
EOF
```

`wp db export` / `import` need the `mysqldump` / `mysql` client. If they're missing (database on another host), install them with `sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1 apt-get install -y --no-install-recommends default-mysql-client`.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| A1: `could not tell which PHP serves …` | The vhost uses a TCP FastCGI address, an unusual layout, or a panel | Find `fastcgi_pass` / `SetHandler` in your site's config, then set `PHPV=` (and `WP_USER=` = the pool's `user`) in deploy.conf |
| A2: `apt has no php8.x-gmp` | The PHP comes from a control panel or a custom build | Install GMP or BCMath through the panel; static addresses work without them, xpubs and EVM token amounts don't |
| `Error: This does not seem to be a WordPress installation` | Wrong `WP_ROOT` | Point it at the folder with `wp-load.php` (preflight lists them) |
| `Error establishing a database connection` from WP-CLI only | The CLI PHP can't reach the DB the way FPM does (socket path, `DB_HOST`) | Compare `php$PHPV -i \| grep mysqli.default_socket` with the FPM one; set `DB_HOST` in wp-config.php to `localhost:/path/to/mysqld.sock` if needed |
| `deploy.sh`: `Permission denied (publickey)` | The deploy key isn't on GitHub | Redo step 5 |
| `deploy.sh` rolled back | WordPress threw a fatal error after the swap (maybe from another plugin) | The printed lines show the error; the old version is back. Fix and redeploy |
| Payments stay *Pending* after the customer paid | Cron isn't running, an explorer is rate-limiting, or the wrong address/xpub was used | `journalctl -u novacrypto-cron.service`; **Tools & Logs** for explorer errors; add an Etherscan/BlockCypher key; compare *Preview addresses* with your wallet |
| Crypto missing at checkout | No coin is enabled, the gateway is disabled, or there's a per-coin minimum | Step 9 items 1–2; *Exchange Rates → per-coin overrides* |
| Pay page shows 404 | Plain permalinks, or the web server lacks a WordPress rewrite | Set permalinks (step 9); Path B's nginx `try_files` handles it; then `W rewrite flush` |
| "HD wallet (xpub) address generation is disabled" notice | GMP and BCMath are both missing for the PHP that serves the site | Path A step A2 / Path B step B1, then reload that PHP-FPM |
| Lightning payments never confirm | The Lightning address provider doesn't support LUD-21 verify, or a wrong LNbits key | Use LNbits or a provider with verify; use the LNbits **invoice/read** key |
| Path B: `502 Bad Gateway` | The pool isn't running or the socket owner is wrong | `ls -l /run/php/novacrypto-fpm.sock` must be owned by the web server's user; `sudo /usr/sbin/php-fpm<ver> -t`; `journalctl -u php<ver>-fpm` |
| Path B: `nginx -t` fails | Another site claims the same `server_name` or port | Remove the duplicate there, or change `DOMAIN` / `PUBLIC_PORT` |
| certbot: `DNS problem` / `unauthorized` | The A record is wrong or not yet propagated, or port 80 is blocked | `getent ahostsv4 your-domain` must show this server; open 80/tcp |
| Multisite network: cron only runs for the main site | `wp cron event run` acts on one site | Add one timer per site with `--url=https://that-site` in `ExecStart` |

## Uninstall

This removes only what this guide created. Packages (PHP, MariaDB, nginx, certbot) stay installed. On **Path A**, deactivating keeps NovaCrypto Pay's payment records and settings in your database on purpose, because they are accounting records. To erase them too, tick *Advanced → Delete data on uninstall*, then run `W plugin uninstall novacrypto-pay` instead of `deactivate`. This block deliberately runs without `-e`, so one missing piece doesn't stop the rest.

```bash
sudo bash -uo pipefail <<'EOF'
source /etc/novacrypto/deploy.conf
W() { sudo -u "$WP_USER" "$APP_HOME/bin/wp" --path="$WP_ROOT" "$@"; }
systemctl disable --now "$APP-cron.timer" 2>/dev/null
rm -f "/etc/systemd/system/$APP-cron.timer" "/etc/systemd/system/$APP-cron.service"
systemctl daemon-reload
if [ "$MODE" = existing ]; then
  if [ -f "/etc/$APP/disabled-wp-cron" ]; then "$APP_HOME/bin/wp" --allow-root --path="$WP_ROOT" config delete DISABLE_WP_CRON; fi
  W plugin deactivate novacrypto-pay
  rm -rf "$PLUGINS_DIR/novacrypto-pay" "$PLUGINS_DIR/.novacrypto-pay.new" "$PLUGINS_DIR/.novacrypto-pay.old"
else
  rm -f "/etc/nginx/sites-enabled/$APP" "/etc/nginx/sites-available/$APP" "/etc/nginx/conf.d/$APP.conf"
  if command -v nginx >/dev/null 2>&1; then nginx -t && systemctl reload nginx; fi
  if [ -n "$DOMAIN" ] && command -v certbot >/dev/null 2>&1; then certbot delete --cert-name "$DOMAIN" --non-interactive; fi
  if [ -z "$DOMAIN" ] && command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then ufw delete allow "$PUBLIC_PORT/tcp"; fi
  rm -f "/etc/php/$PHPV/fpm/pool.d/$APP.conf"
  "/usr/sbin/php-fpm$PHPV" -t && systemctl reload "php$PHPV-fpm"
  if [ -f "/etc/$APP/db.env" ]; then
    source "/etc/$APP/db.env"
    mysql -e "DROP DATABASE IF EXISTS $DB_NAME; DROP USER IF EXISTS '$DB_USER'@'localhost';"
  fi
fi
rm -rf "$APP_HOME"
if id -u "$APP_USER" >/dev/null 2>&1; then userdel "$APP_USER"; fi
rm -rf "/etc/$APP"
echo "NovaCrypto Pay deployment removed."
EOF
```

`rm -rf /opt/novacrypto` also deletes the backups and, on Path B, the whole site. Copy anything you want to keep first.

## Appendix: Caddy / Apache instead of nginx

This applies to **Path B** when another web server owns 80/443; Path A never touches your web server. Do B1 to B4 (B3 picks up Caddy's or Apache's user for the socket automatically if that server is running), skip B5, then use one of these. `/opt/novacrypto/site` is `WP_ROOT`.

**Caddy** (add to your Caddyfile, then `sudo systemctl reload caddy`; Caddy gets the certificate itself):

```
shop.example.com {
	root * /opt/novacrypto/site
	php_fastcgi unix//run/php/novacrypto-fpm.sock
	file_server
	@blocked path /xmlrpc.php /wp-content/uploads/*.php
	respond @blocked 403
}
```

**Apache** (`sudo a2enmod proxy_fcgi rewrite`, save as `/etc/apache2/sites-available/novacrypto.conf`, run `sudo a2ensite novacrypto && sudo apache2ctl configtest && sudo systemctl reload apache2`, then `sudo certbot --apache -d shop.example.com`. Write WordPress's rewrite rules with `sudo -u novacrypto /opt/novacrypto/bin/wp --path=/opt/novacrypto/site rewrite flush --hard`):

```apache
<VirtualHost *:80>
    ServerName shop.example.com
    DocumentRoot /opt/novacrypto/site
    <Directory /opt/novacrypto/site>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/novacrypto-fpm.sock|fcgi://localhost"
    </FilesMatch>
    <Files xmlrpc.php>
        Require all denied
    </Files>
</VirtualHost>
```
