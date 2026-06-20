#!/bin/bash
set -euo pipefail

APP=/var/www/drawdream
BRANCH="${DRAWDREAM_GIT_BRANCH:-security/pre-production-hardening}"

cd "$APP"

if [ -f .env ]; then
  cp .env /root/drawdream.env.before-pull
fi

git fetch origin
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

if [ -f /root/drawdream.env.before-pull ]; then
  cp /root/drawdream.env.before-pull .env
  chown www-data:www-data .env
  chmod 640 .env
fi

mkdir -p config uploads data
touch config/migration_done.txt
chown www-data:www-data config/migration_done.txt 2>/dev/null || true
chown -R www-data:www-data uploads data 2>/dev/null || true

systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
systemctl reload nginx

echo "GIT PULL OK — branch: $BRANCH"
