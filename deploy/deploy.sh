#!/usr/bin/env bash
#
# The routine release, as the central user, from /srv/central:
#
#   bash deploy/deploy.sh            # what git pull brings
#   bash deploy/deploy.sh --first    # the first time: composer, build, .env, key; no down/up
#
# Mirrors docs/DEPLOY.md "Updates" exactly; when the two disagree, one of
# them is wrong and that is the bug.

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
        echo "Fresh .env written. Fill the production values (docs/DEPLOY.md, section 2), then:"
        echo "  php artisan erp:install --no-interaction --company=\"...\" --currency=IDR --admin-email=..."
        echo "  php artisan storage:link"
        echo "  php artisan optimize && php artisan filament:optimize"
        echo "  sudo chown -R central:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache"
        exit 0
    fi
fi

# Room to build: npm ci and the Vite build want a few hundred MB, and a box
# that runs out midway leaves a half-written cache file, not an error.
FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')
if [ "$FREE_MB" -lt 1024 ]; then
    echo "Only ${FREE_MB} MB free; the build needs room. Clear some first:"
    echo "  rm -rf node_modules            # npm ci rebuilds it"
    echo "  sudo journalctl --vacuum-size=100M"
    exit 1
fi

# A maintenance page, not an error page, for whoever is mid-order.
$FIRST || php artisan down --render="errors::503"

# A failed deploy ends in one of two honest states: the old code serving
# again, or the maintenance page still up over code that cannot serve.
canServe() {
    php artisan about --only=environment >/dev/null 2>&1 || return 1
    [ -s public/build/manifest.json ] || return 1
}
finish() {
    local code=$?
    if [ "$code" -ne 0 ]; then
        echo
        echo "Deploy failed (exit $code)."
        if $FIRST; then
            :
        elif canServe; then
            echo "The code on disk still serves: the site is back up."
            php artisan up || true
        else
            echo "The application cannot serve, so the maintenance page STAYS up. Fix, then:"
            echo "  npm ci && npm run build"
            echo "  php artisan optimize && php artisan up"
        fi
    fi
    exit "$code"
}
trap finish EXIT

git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# The build is the step most likely to die on a small box, and silently: nothing
# is missing until a page renders. Checked here, while the 503 is still up.
if [ ! -s public/build/manifest.json ]; then
    echo "The build left no public/build/manifest.json; usually out of memory."
    echo "Try: NODE_OPTIONS=--max-old-space-size=1024 npm run build"
    exit 1
fi

php artisan migrate --force

# public/storage -> storage/app/public, for the site's images. Idempotent.
php artisan storage:link --force >/dev/null 2>&1 || php artisan storage:link

# Clear the cached config before rebuilding it: optimize writes it in one pass,
# and killed halfway it leaves a truncated file that kills every later artisan
# command ("Target class [config] does not exist"), the clearing ones included.
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php

php artisan optimize && php artisan filament:optimize

# Workers hold the old code in memory until told otherwise.
php artisan queue:restart

$FIRST || php artisan up

echo
# Informational on a routine deploy: the launch gate blocks launches, not fixes.
php artisan central:launch-check || true
