#!/usr/bin/env bash
#
# First hour on a bare Hostinger VPS (Ubuntu 24.04 LTS, KVM 2), as root.
#
#   bash deploy/provision.sh portal.example.co.id
#
# Idempotent: run it again after a half-finished attempt and it continues
# rather than breaking what already worked. It stops before anything it
# cannot decide for you — the deploy user's SSH key, the database password,
# `.env` — and says what is next.
#
# docs/DEPLOY.md is the runbook this implements; read it first. The prose
# explains *why* each step is what it is; this file only does them.

set -euo pipefail

DOMAIN="${1:?Usage: bash deploy/provision.sh <domain>  (e.g. portal.example.co.id)}"
APP_DIR=/var/www/webtransaction
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

say() { printf '\n==> %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }

# --- a user that is not root -------------------------------------------------

if ! id deploy >/dev/null 2>&1; then
    say "Creating the deploy user"
    adduser --disabled-password --gecos '' deploy
    usermod -aG sudo deploy
    if [ -d /root/.ssh ]; then
        rsync --archive --chown=deploy:deploy /root/.ssh /home/deploy
    fi
    echo "Set a sudo password for deploy now (needed for sudo, not for SSH):"
    passwd deploy
fi

# SSH hardening is left to you on purpose: locking root out before proving a
# deploy session works is how a fresh VPS becomes a support ticket. The
# runbook's §2 has the two sshd_config lines when you are ready.

# --- firewall ----------------------------------------------------------------

say "Firewall: SSH, 80, 443 — and nothing else"
ufw allow OpenSSH >/dev/null
ufw allow 80 >/dev/null
ufw allow 443 >/dev/null
ufw --force enable >/dev/null
# Postgres and Redis stay off the list: both bind localhost only.

# --- packages ----------------------------------------------------------------

say "Packages (PHP 8.4, Postgres 16, Redis, Caddy, supervisor)"
if ! apt-cache policy php8.4-fpm 2>/dev/null | grep -q Candidate; then
    add-apt-repository -y ppa:ondrej/php
fi
apt-get update -q
apt-get install -yq \
    php8.4-fpm php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml php8.4-zip \
    php8.4-gd php8.4-intl php8.4-curl php8.4-bcmath \
    postgresql-16 postgresql-client-16 redis-server supervisor caddy git unzip rsync

if ! command -v composer >/dev/null; then
    say "Composer"
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if ! command -v node >/dev/null; then
    say "Node 22"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -yq nodejs
fi

# --- database ----------------------------------------------------------------

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='webtransaction'" | grep -q 1; then
    say "Postgres role + database (you will be asked for the DB password — keep it for .env)"
    sudo -u postgres createuser --pwprompt webtransaction
    sudo -u postgres createdb --owner=webtransaction webtransaction
fi

# --- redis: the queue must fail loudly, never evict --------------------------

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
    chown deploy:deploy "$APP_DIR"
    if [ "$REPO_DIR" != "$APP_DIR" ]; then
        echo "Clone the repository as deploy:  sudo -u deploy git clone <repo> $APP_DIR"
    fi
fi

# --- caddy and supervisor from the kit's own files ---------------------------

say "Caddyfile ($DOMAIN)"
sed "s/__DOMAIN__/$DOMAIN/" "$REPO_DIR/deploy/Caddyfile" > /etc/caddy/Caddyfile
systemctl enable --now caddy >/dev/null
# Reload only once the app exists; a root pointing at nothing is a 502 either way.
systemctl reload caddy || true

say "Supervisor worker"
cp "$REPO_DIR/deploy/supervisor/webtransaction-worker.conf" /etc/supervisor/conf.d/
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null || true

say "Scheduler cron for deploy"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
( sudo -u deploy crontab -l 2>/dev/null | grep -Fv 'schedule:run' ; echo "$CRON_LINE" ) | sudo -u deploy crontab -

# --- what this script will not do for you ------------------------------------

say "Provisioned. Still yours to do, in order (docs/DEPLOY.md §2, §4, §7–9):"
cat <<'NEXT'
  1. sshd_config: PermitRootLogin no, PasswordAuthentication no — after
     proving a deploy SSH session works.
  2. As deploy: clone the repo into /var/www/webtransaction, then
     bash deploy/deploy.sh --first   (composer, build, .env, key, migrate)
  3. Fill .env: DB password from above, APP_URL, PERUSAHAAN_*, backups.
  4. Point the domain's A record here, then: systemctl reload caddy
  5. php artisan launch:check — and keep going until it exits 0.
NEXT
