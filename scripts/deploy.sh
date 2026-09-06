#!/usr/bin/env bash
# Wiki Donate — VPS deploy helper (Ubuntu)
# Location on VPS: /var/www/wiki-donate/scripts/deploy.sh
# Run: bash scripts/deploy.sh
# Or: GitHub Actions calls it via SSH (see .github/workflows/deploy.yml)
#
# Assumptions:
# - Ubuntu 22.04/24.04, nginx, php8.4-fpm, mysql, node 22.23.2 installed (DEPLOY_UBUNTU_MANUAL.md Steps 5-9)
# - Repo cloned at /var/www/wiki-donate, user=wikidonate
# - .env already configured (APP_ENV=production, DB, MAIL, STRIPE, VITE_* )
# - Runs as user wikidonate with sudo for chown (or as root)

set -euo pipefail
APP_DIR="/var/www/wiki-donate"
BRANCH="${1:-main}"

cd "$APP_DIR"

echo "==> [deploy.sh] Wiki Donate — branch $BRANCH"
echo "    Dir: $APP_DIR"
echo "    PHP: $(php -v | head -1)"
echo "    Node: $(node -v) npm $(npm -v)"

echo "==> Git pull"
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"
git log --oneline -3

echo "==> Maintenance ON (60s retry)"
php artisan down --render="errors::503" --retry=60 || true

echo "==> Composer install"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

echo "==> NPM build (Vite)"
# VITE_* vars are baked at build time — ensure .env has VITE_API_URL etc. before this
npm ci --prefer-offline
npm run build
ls -lh public/build/.vite/manifest.json

echo "==> Laravel optimize"
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart || true

echo "==> Permissions (www-data on Ubuntu)"
# Needs sudo if run as wikidonate
if command -v sudo >/dev/null 2>&1; then
  sudo chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || chown -R www-data:www-data storage bootstrap/cache public/build || true
else
  chown -R www-data:www-data storage bootstrap/cache public/build || true
fi
chmod -R 775 storage bootstrap/cache

echo "==> Maintenance OFF"
php artisan up

echo "==> Health checks"
php artisan about --only=environment || true
curl -fsS http://127.0.0.1/up && echo " [local /up OK]" || echo " [local /up FAIL — check nginx/php8.4-fpm]"
# If you have domain + SSL, also check:
# curl -fsS https://wikidonate.org/up && echo " [remote /up OK]"

echo "==> Recent logs (last 20 lines)"
tail -n 20 storage/logs/laravel.log || echo "no laravel.log yet"

echo "✅ Deploy done — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
