#!/usr/bin/env bash
# Tune PHP OPcache + Nginx gzip/static cache on DrawDream VPS
set -euo pipefail

PHP_VER="${DRAWDREAM_PHP_VER:-8.1}"

cat >/etc/php/${PHP_VER}/fpm/conf.d/99-drawdream-opcache.ini <<'EOF'
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF
ln -sf /etc/php/${PHP_VER}/fpm/conf.d/99-drawdream-opcache.ini /etc/php/${PHP_VER}/cli/conf.d/99-drawdream-opcache.ini

SITE="/etc/nginx/sites-available/drawdream"
if [[ -f "$SITE" ]] && ! grep -q 'gzip on' "$SITE"; then
  sed -i '/client_max_body_size/a \
\
    gzip on;\
    gzip_types text/css application/javascript application/json image/svg+xml;\
    gzip_min_length 256;' "$SITE"
fi

if [[ -f "$SITE" ]] && ! grep -q 'expires 7d' "$SITE"; then
  sed -i '/location \/ {/i \
    location ~* \\.(css|js|jpg|jpeg|png|gif|webp|ico|svg|woff2?)$ {\
        expires 7d;\
        add_header Cache-Control "public, immutable";\
        try_files $uri =404;\
    }\
' "$SITE"
fi

nginx -t
systemctl restart php${PHP_VER}-fpm nginx
echo "OK: OPcache + Nginx tuning applied"
