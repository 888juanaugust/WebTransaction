#!/usr/bin/env bash
#
# The first hour on a bare VPS (Ubuntu 24.04), as root:
#
#   bash deploy/provision.sh erp.example.co.id
#
# Idempotent: run again after a half-finished attempt and it continues. It
# stops before what it cannot decide for you (the central user's SSH key,
# the database password, .env) and says what is next. docs/DEPLOY.md is the
# runbook this implements.

set -euo pipefail

DOMAIN="${1:?Usage: bash deploy/provision.sh <domain>}"
APP_DIR=/srv/central
APP_USER=central
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

say() { printf '\n==> %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }

# --- a user that is not root -------------------------------------------------
if ! id "$APP_USER" >/dev/null 2>&1; then
    say "Creating the $APP_USER user"
    adduser --disabled-password --gecos '' "$APP_USER"
    usermod -aG sudo "$APP_USER"
    if [ -d /root/.ssh ]; then
        rsync --archive --chown="$APP_USER:$APP_USER" /root/.ssh "/home/$APP_USER"
    fi
    echo "Set a sudo password for $APP_USER now (for sudo, not for SSH):"
    passwd "$APP_USER"
fi
# SSH hardening stays manual on purpose: lock root out only after a session as
# the new user is proven to work (DEPLOY.md, section 1).

# --- firewall ----------------------------------------------------------------
say "Firewall: SSH, 80, 443 and nothing else"
ufw allow OpenSSH >/dev/null
ufw allow 80 >/dev/null
ufw allow 443 >/dev/null
ufw --force enable >/dev/null
# PostgreSQL and Redis bind 127.0.0.1 and stay off the list.

# --- packages ----------------------------------------------------------------
say "Packages: PHP 8.4, PostgreSQL 16, Redis, Caddy, supervisor"
if ! apt-cache policy php8.4-fpm 2>/dev/null | grep -q Candidate; then
    add-apt-repository -y ppa:ondrej/php
fi
apt-get update -q
apt-get install -yq \
    php8.4-fpm php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml php8.4-zip \
    php8.4-gd php8.4-intl php8.4-curl php8.4-bcmath \
    postgresql-16 postgresql-client-16 redis-server supervisor caddy git unzip rsync
if ! command -v composer >/dev/null; then
    say "Composer (the installer is checked against its published signature before it runs)"
    EXPECTED="$(curl -fsS https://composer.github.io/installer.sig)"
    curl -fsS https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    if [ "$EXPECTED" != "$ACTUAL" ]; then
        rm -f /tmp/composer-setup.php
        echo "The Composer installer does not match its signature; not running it." >&2
        exit 1
    fi
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
if ! command -v node >/dev/null; then
    say "Node 22 (the asset build only), from NodeSource's signed apt repository"
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -q
    apt-get install -yq nodejs
fi

# --- database ----------------------------------------------------------------
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='central'" | grep -q 1; then
    say "PostgreSQL role and database (you are asked for the password: keep it for .env)"
    sudo -u postgres createuser --pwprompt central
    sudo -u postgres createdb --owner=central central
fi

# --- redis: the queue fails loudly, never evicts -----------------------------
say "Redis: maxmemory 512mb, noeviction"
sed -i 's/^# *maxmemory .*/maxmemory 512mb/; s/^maxmemory .*/maxmemory 512mb/' /etc/redis/redis.conf
sed -i 's/^# *maxmemory-policy .*/maxmemory-policy noeviction/; s/^maxmemory-policy .*/maxmemory-policy noeviction/' /etc/redis/redis.conf
grep -q '^maxmemory 512mb' /etc/redis/redis.conf || echo 'maxmemory 512mb' >> /etc/redis/redis.conf
grep -q '^maxmemory-policy noeviction' /etc/redis/redis.conf || echo 'maxmemory-policy noeviction' >> /etc/redis/redis.conf
systemctl enable --now redis-server postgresql >/dev/null
systemctl restart redis-server

# --- the application directory ----------------------------------------------
if [ ! -d "$APP_DIR/.git" ]; then
    say "Application directory"
    mkdir -p "$APP_DIR"
    chown "$APP_USER:$APP_USER" "$APP_DIR"
    [ "$REPO_DIR" = "$APP_DIR" ] || echo "Clone the repository as $APP_USER:  sudo -u $APP_USER git clone <repo> $APP_DIR"
fi

# --- caddy, logrotate, supervisor from the kit's own files -------------------
say "Caddyfile ($DOMAIN)"
sed "s/__DOMAIN__/$DOMAIN/" "$REPO_DIR/deploy/Caddyfile" > /etc/caddy/Caddyfile
mkdir -p /var/log/caddy && chown caddy:caddy /var/log/caddy
systemctl enable --now caddy >/dev/null
systemctl reload caddy || true

say "Log rotation"
cp "$REPO_DIR/deploy/logrotate/central" /etc/logrotate.d/central

say "Supervisor worker"
cp "$REPO_DIR/deploy/supervisor/central-worker.conf" /etc/supervisor/conf.d/
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null || true

say "Scheduler cron for $APP_USER"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
( sudo -u "$APP_USER" crontab -l 2>/dev/null | grep -Fv 'schedule:run' ; echo "$CRON_LINE" ) | sudo -u "$APP_USER" crontab -

say "Provisioned. Still yours, in order (docs/DEPLOY.md):"
cat <<'NEXT'
  1. sshd_config: PermitRootLogin no, PasswordAuthentication no, once a session as central works.
  2. As central: clone the repository into /srv/central, then  bash deploy/deploy.sh --first
  3. Fill .env: the database password, APP_URL, MAIL_*, BACKUP_* and the bucket's AWS_* keys.
  4. php artisan erp:install ... (section 3), then point the domain here and  systemctl reload caddy
  5. php artisan central:launch-check, and keep going until it exits 0.
NEXT
