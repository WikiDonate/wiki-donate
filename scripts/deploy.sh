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
# ROOT CAUSE: chown www-data:www-data makes deploy user lose ownership → chmod fails.
DEPLOY_USER=$(whoami)
if sudo -n chown -R $DEPLOY_USER:www-data storage bootstrap/cache public/build 2>/dev/null; then
  echo "chown $DEPLOY_USER:www-data via sudo OK"
elif chown -R $DEPLOY_USER:www-data storage bootstrap/cache public/build 2>/dev/null; then
  echo "chown $DEPLOY_USER:www-data without sudo OK"
elif sudo -n chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null; then
  echo "chown www-data:www-data via sudo OK (fallback)"
else
  echo "⚠️ chown failed — run once: sudo usermod -aG www-data $DEPLOY_USER && sudo chown -R $DEPLOY_USER:www-data storage bootstrap/cache public/build"
fi
if sudo -n chmod -R 775 storage bootstrap/cache 2>/dev/null; then
  echo "chmod 775 via sudo OK"
elif chmod -R 775 storage bootstrap/cache 2>/dev/null; then
  echo "chmod 775 without sudo OK"
else
  echo "⚠️ chmod failed — run: sudo chown -R $DEPLOY_USER:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache"
fi
sudo -n chmod -R g+s storage bootstrap/cache 2>/dev/null || sudo -n chmod -R g+ws storage bootstrap/cache 2>/dev/null || true

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
