#!/usr/bin/env bash
# Gets a Claude Code cloud session to the point where the app runs: the local
# PostgreSQL 16 cluster with the role and databases phpunit.xml expects, Redis,
# Composer dependencies, an installed demo database, and the browser's trust in
# the session proxy for the UI smoke script. Idempotent; every step that depends
# on the network fails soft, because the environment's egress policy decides
# what is reachable, and a session that cannot install locally still has CI.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

say() { echo "[session-start] $*" >&2; }

# --- PostgreSQL 16 -----------------------------------------------------------
if [ -d /etc/postgresql/16/main ]; then
  pg_ctlcluster 16 main start >/dev/null 2>&1 || true
  for _ in $(seq 1 20); do
    pg_isready -q -h 127.0.0.1 -p 5432 && break
    sleep 0.5
  done
  if pg_isready -q -h 127.0.0.1 -p 5432; then
    su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='central'\"" | grep -q 1 \
      || su postgres -c "psql -qc \"CREATE ROLE central LOGIN SUPERUSER PASSWORD 'secret'\""
    for db in central central_test; do
      su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\"" | grep -q 1 \
        || su postgres -c "createdb -O central $db"
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
# The session proxy refuses Composer's zip downloads from api.github.com but
# serves git clones of public repositories, hence --prefer-source.
if [ -f composer.json ]; then
  composer install --no-interaction --no-progress --prefer-source -q 2>/dev/null \
    || composer install --no-interaction --no-progress -q \
    || say "composer install failed; tests run in CI"
  [ -f .env ] || { cp .env.example .env && php artisan key:generate -q; }
  # A first session installs the demo company; later ones only migrate (the installer refuses a second run).
  if pg_isready -q -h 127.0.0.1 -p 5432 && [ -d vendor ]; then
    php artisan erp:install --no-interaction --demo -q 2>/dev/null \
      || php artisan migrate --force -q 2>/dev/null \
      || say "the database could not be installed or migrated"
  fi
fi

# --- npm (the UI smoke script's Playwright) ------------------------------------
if [ -f package.json ]; then
  npm ci --no-audit --no-fund -q 2>/dev/null || say "npm ci failed"
fi

# The pre-installed Chromium trusts certificates through NSS, and the
# session's TLS-intercepting proxy is not in that store, so every HTTPS page
# fails with ERR_CERT_AUTHORITY_INVALID until its CA is imported. The CA is
# the one certificate in the bundle issued by Anthropic's agent proxy.
if [ -n "${SSL_CERT_FILE:-}" ] && [ -f "$SSL_CERT_FILE" ]; then
  if ! command -v certutil >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    (apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq libnss3-tools >/dev/null 2>&1) \
      || say "libnss3-tools not installed; the smoke script's browser will not trust the proxy"
  fi
  if command -v certutil >/dev/null 2>&1; then
    mkdir -p "$HOME/.pki/nssdb"
    [ -f "$HOME/.pki/nssdb/cert9.db" ] || certutil -d "sql:$HOME/.pki/nssdb" -N --empty-password >/dev/null 2>&1
    tmp=$(mktemp -d)
    awk -v dir="$tmp" 'BEGIN{n=0} /BEGIN CERTIFICATE/{n++; f=sprintf("%s/cert-%03d.pem",dir,n)} {print > f}' "$SSL_CERT_FILE"
    for pem in "$tmp"/cert-*.pem; do
      subject=$(openssl x509 -in "$pem" -noout -subject 2>/dev/null)
      case "$subject" in
        *"agent-proxy interception CA"*)
          nick=$(printf '%s' "$subject" | sed -E 's/.*CN *= *([^,]*).*/\1/')
          certutil -d "sql:$HOME/.pki/nssdb" -L -n "$nick" >/dev/null 2>&1 \
            || certutil -d "sql:$HOME/.pki/nssdb" -A -t "C,," -n "$nick" -i "$pem" >/dev/null 2>&1 \
            || say "could not import the proxy CA into NSS"
          ;;
      esac
    done
    rm -rf "$tmp"
  fi
fi

exit 0
