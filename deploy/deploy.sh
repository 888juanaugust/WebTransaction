#!/usr/bin/env bash
#
# The routine release, as the deploy user, from the application directory:
#
#   bash deploy/deploy.sh            # ordinary deploy of what git pull brings
#   bash deploy/deploy.sh --first    # first deploy: .env, key, no down/up
#
# Mirrors docs/DEPLOY.md §10 exactly — if this file and that section ever
# disagree, one of them is wrong and the disagreement is the bug.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

FIRST=false
[ "${1:-}" = "--first" ] && FIRST=true

if $FIRST; then
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build

    if [ ! -f .env ]; then
        cp .env.example .env
        php artisan key:generate
        echo
        echo "Fresh .env written. Fill the production values (docs/DEPLOY.md §4)"
        echo "— DB password, APP_URL, PERUSAHAAN_*, backups — then run:"
        echo
        echo "  php artisan migrate --force && php artisan filament:assets"
        echo "  php artisan optimize && php artisan filament:optimize"
        echo "  sudo chown -R deploy:www-data storage bootstrap/cache"
        echo "  sudo chmod -R 775 storage bootstrap/cache"
        exit 0
    fi
fi

##
# Enough room to build. `npm ci` plus a Vite build wants a few hundred MB,
# and a box that runs out midway leaves a half-written cache file rather
# than an error — see the bootstrap-cache note below for what that costs.
##
FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')
if [ "$FREE_MB" -lt 1024 ]; then
    echo "Sisa disk hanya ${FREE_MB} MB. Build butuh ruang — bersihkan dulu:"
    echo "  rm -rf node_modules   # dibangun ulang oleh npm ci"
    echo "  sudo journalctl --vacuum-size=100M"
    exit 1
fi

# A maintenance page, not an error page — buyers mid-order deserve honesty.
$FIRST || php artisan down --render="errors::503"

##
# Whatever happens next, the site comes back up.
#
# Without this, any failing step below left the shop showing 503 until
# somebody noticed and ran `artisan up` by hand. A broken deploy is a bad
# afternoon; a broken deploy nobody can end is a bad week.
##
kembalikan() {
    local kode=$?

    if [ "$kode" -ne 0 ]; then
        echo
        echo "Deploy gagal (kode ${kode}). Mengembalikan situs dari mode perbaikan."
        $FIRST || php artisan up || true
    fi

    exit "$kode"
}
trap kembalikan EXIT

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

##
# Clear the cached config *before* rebuilding it.
#
# `artisan optimize` writes bootstrap/cache/config.php in one pass. Killed
# halfway — out of disk, out of memory — it leaves a truncated PHP file, and
# from then on every artisan command dies with "Target class [config] does
# not exist", including the ones that would clear it. Deleting the files
# first needs no working application, so a poisoned cache can never survive
# into the next deploy.
##
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php

php artisan optimize && php artisan filament:optimize

# Workers hold the old code in memory until told otherwise. A deploy that
# changes a job class without this leaves the previous version running.
php artisan queue:restart

$FIRST || php artisan up

echo
# Informational on a routine deploy — the launch gate blocks launches, not
# bug fixes. Non-zero here is a reminder, not a rollback.
php artisan launch:check || true
