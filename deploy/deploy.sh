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

# A maintenance page, not an error page — buyers mid-order deserve honesty.
$FIRST || php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize && php artisan filament:optimize

# Workers hold the old code in memory until told otherwise. A deploy that
# changes a job class without this leaves the previous version running.
php artisan queue:restart

$FIRST || php artisan up

echo
# Informational on a routine deploy — the launch gate blocks launches, not
# bug fixes. Non-zero here is a reminder, not a rollback.
php artisan launch:check || true
