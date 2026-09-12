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
        echo "— DB password, APP_URL, PERUSAHAAN_*, PAJAK_* (§7b), backups —"
        echo "then run:"
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
# A failed deploy ends in one of two honest states, never in a third.
#
# Leaving the shop in maintenance mode until somebody notices is bad. Lifting
# maintenance over an application that cannot serve is worse: buyers get a
# stack trace on every page instead of a page that says come back shortly.
#
# So a failure asks whether the code on disk can actually serve — it boots,
# and the built assets exist — and only then lifts the maintenance page.
# Where it cannot, the 503 stays up and the recovery is printed.
##
dapatMelayani() {
    php artisan about --only=environment >/dev/null 2>&1 || return 1
    [ -s public/build/manifest.json ] || return 1
}

kembalikan() {
    local kode=$?

    if [ "$kode" -ne 0 ]; then
        echo
        echo "Deploy gagal (kode ${kode})."

        if $FIRST; then
            :
        elif dapatMelayani; then
            echo "Kode lama masih bisa melayani — situs dibuka kembali."
            php artisan up || true
        else
            echo "Aplikasi belum bisa melayani, jadi halaman perbaikan TETAP tampil."
            echo "Buyers melihat 'sebentar lagi', bukan stack trace. Perbaiki lalu:"
            echo
            echo "  npm ci && npm run build     # aset yang hilang"
            echo "  php artisan optimize"
            echo "  php artisan up"
        fi
    fi

    exit "$kode"
}
trap kembalikan EXIT

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

##
# The build is the step most likely to die on a small box, and its failure is
# silent in the worst way: nothing is missing until a page is rendered, and by
# then maintenance mode has been lifted and every request is a 500. So the
# artefact is checked here, while the 503 is still up.
##
if [ ! -s public/build/manifest.json ]; then
    echo "Build tidak menghasilkan public/build/manifest.json."
    echo "Biasanya kehabisan memori. Coba: NODE_OPTIONS=--max-old-space-size=1024 npm run build"
    exit 1
fi

php artisan migrate --force

# public/storage → storage/app/public, for the promo images the Owner uploads
# in Pengaturan. Idempotent: an existing link is left alone.
php artisan storage:link --force >/dev/null 2>&1 || php artisan storage:link

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
