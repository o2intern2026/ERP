#!/usr/bin/env bash
# Runs ON the trial server (as root) after every merge to main: pull, install, additive migrate, rebuild caches.
# Never resets the database — testers' data stays. Called by deploy/deploy-trial.sh from a developer machine.
set -euo pipefail
APP_DIR=${APP_DIR:-/var/www/erp}
BRANCH=${BRANCH:-main}

cd "$APP_DIR"
echo "== $(date '+%F %T') deploy $BRANCH → $APP_DIR"
git fetch -q origin "$BRANCH"
git reset -q --hard "origin/$BRANCH"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan down --retry=10 --refresh=5 || true
php artisan migrate --force --no-interaction
php artisan optimize:clear -q
php artisan optimize -q          # config + route + view caches
php artisan storage:link -q 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache
php artisan up
echo "== deployed $(git rev-parse --short HEAD): $(git log -1 --pretty=%s | cut -c1-80)"
