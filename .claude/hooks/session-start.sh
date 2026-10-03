#!/bin/bash
# Gets a cloud session to the point where `php artisan test` can run:
# PHP 8.4 (composer.lock resolves Symfony 8, which needs >= 8.4.1), the local
# PostgreSQL 16 cluster with the role and databases phpunit.xml expects, Redis,
# and the Composer dependencies. Idempotent; every step that depends on the
# network fails soft, because the environment's egress policy decides what is
# reachable and a session that cannot run the suite locally still has CI.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

say() { echo "[session-start] $*" >&2; }

# --- PHP 8.4 -----------------------------------------------------------------
if ! php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' 2>/dev/null; then
  if timeout 15 curl -fsS -o /dev/null https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/Release 2>/dev/null; then
    say "installing PHP 8.4 from ppa:ondrej/php"
    export DEBIAN_FRONTEND=noninteractive
    if ! [ -f /etc/apt/sources.list.d/ondrej-php.list ]; then
      echo "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ondrej-php.list
      apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 4F4EA0AAE5267A6C >/dev/null 2>&1 || true
    fi
    apt-get update -qq >/dev/null 2>&1 || true
    apt-get install -y -qq php8.4-{cli,pgsql,redis,mbstring,gd,zip,xml,intl,curl,bcmath} >/dev/null 2>&1 \
      && update-alternatives --set php /usr/bin/php8.4 >/dev/null 2>&1 \
      || say "PHP 8.4 install failed; tests will only run in CI"
  else
    say "ppa.launchpadcontent.net not reachable; PHP stays at $(php -r 'echo PHP_VERSION;'), tests run in CI"
  fi
fi

# --- PostgreSQL 16 -----------------------------------------------------------
if [ -d /etc/postgresql/16/main ]; then
  pg_ctlcluster 16 main start >/dev/null 2>&1 || true
  for _ in $(seq 1 20); do
    pg_isready -q -h 127.0.0.1 -p 5432 && break
    sleep 0.5
  done
  if pg_isready -q -h 127.0.0.1 -p 5432; then
    su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='webtransaction'\"" | grep -q 1 \
      || su postgres -c "psql -qc \"CREATE ROLE webtransaction LOGIN SUPERUSER PASSWORD 'secret'\""
    for db in webtransaction webtransaction_test; do
      su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\"" | grep -q 1 \
        || su postgres -c "createdb -O webtransaction $db"
    done
  else
    say "PostgreSQL did not start"
  fi
fi

# --- Redis -------------------------------------------------------------------
if command -v redis-server >/dev/null 2>&1 && ! redis-cli ping >/dev/null 2>&1; then
  redis-server --daemonize yes >/dev/null 2>&1 || say "redis-server did not start"
fi

# --- Composer ----------------------------------------------------------------
# --ignore-platform-req=php lets vendor/ (and so Pint) exist on an older PHP;
# the suite itself still needs 8.4 to load Symfony 8.
if php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' 2>/dev/null; then
  composer install --no-interaction --no-progress -q || say "composer install failed"
  [ -f .env ] || { cp .env.example .env && php artisan key:generate -q; }
else
  composer install --no-interaction --no-progress -q --no-scripts --ignore-platform-req=php \
    || say "composer install failed"
fi

exit 0
