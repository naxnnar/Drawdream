#!/bin/bash
set -euo pipefail

APP=/var/www/drawdream
ZIP=/tmp/drawdream-deploy.zip
STAGE="/tmp/drawdream-extract-$$"

if [ ! -f "$ZIP" ]; then
  echo "Missing $ZIP — upload zip first."
  exit 1
fi

mkdir -p "$STAGE"
unzip -oq "$ZIP" -d "$STAGE"

if [ -f "$APP/.env" ]; then
  cp "$APP/.env" /root/drawdream.env.before-sync
fi
if [ -d "$APP/uploads" ]; then
  cp -a "$APP/uploads" "/tmp/drawdream-uploads-backup-$$"
fi

rsync -a --exclude='.env' --exclude='uploads' "$STAGE"/ "$APP"/

if [ -f /root/drawdream.env.before-sync ]; then
  cp /root/drawdream.env.before-sync "$APP/.env"
  chown www-data:www-data "$APP/.env"
  chmod 640 "$APP/.env"
fi
if [ -d "/tmp/drawdream-uploads-backup-$$" ]; then
  rm -rf "$APP/uploads"
  cp -a "/tmp/drawdream-uploads-backup-$$" "$APP/uploads"
  rm -rf "/tmp/drawdream-uploads-backup-$$"
fi

mkdir -p "$APP/uploads" "$APP/data"
chown -R www-data:www-data "$APP/uploads" "$APP/data" 2>/dev/null || true
chmod -R 775 "$APP/uploads" "$APP/data" 2>/dev/null || true
rm -rf "$STAGE"
rm -f "$ZIP"

systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
systemctl reload nginx

echo "SYNC OK"
