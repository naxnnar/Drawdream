#!/usr/bin/env bash
# DrawDream — ติดตั้ง Nginx + PHP 8.2 และ deploy โค้ดบน Ubuntu 22.04
# รันบน VPS: bash /root/server-bootstrap.sh
set -euo pipefail

DRAWDREAM_SERVER_IP="${DRAWDREAM_SERVER_IP:-82.26.104.99}"
APP_ROOT="/var/www/drawdream"
REPO_URL="${DRAWDREAM_REPO_URL:-https://github.com/naxnnar/Drawdream.git}"
REPO_BRANCH="${DRAWDREAM_REPO_BRANCH:-main}"

echo "==> DrawDream bootstrap (IP: ${DRAWDREAM_SERVER_IP})"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx git unzip curl ca-certificates software-properties-common

if ! dpkg -l | grep -q php8.2-fpm; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -qq
fi

apt-get install -y -qq \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
  php8.2-curl php8.2-xml php8.2-zip php8.2-gd php8.2-fileinfo \
  php8.2-intl php8.2-bcmath

cat >/etc/php/8.2/fpm/conf.d/99-drawdream.ini <<'EOF'
upload_max_filesize = 32M
post_max_size = 36M
max_execution_time = 120
memory_limit = 256M
date.timezone = Asia/Bangkok
EOF

systemctl enable nginx php8.2-fpm
systemctl restart php8.2-fpm

mkdir -p "${APP_ROOT}"
if [[ -f /tmp/drawdream-deploy.zip ]]; then
  echo "==> Extract code from /tmp/drawdream-deploy.zip"
  rm -rf "${APP_ROOT:?}"/*
  unzip -oq /tmp/drawdream-deploy.zip -d "${APP_ROOT}"
elif [[ ! -f "${APP_ROOT}/db.php" ]]; then
  echo "==> Clone from ${REPO_URL} (branch ${REPO_BRANCH})"
  rm -rf "${APP_ROOT:?}"/*
  git clone --depth 1 --branch "${REPO_BRANCH}" "${REPO_URL}" "${APP_ROOT}"
else
  echo "==> Code already in ${APP_ROOT}, skip clone"
fi

mkdir -p /etc/ssl/aiven
mkdir -p "${APP_ROOT}/data"
mkdir -p "${APP_ROOT}/uploads/childern" "${APP_ROOT}/uploads/needs" \
  "${APP_ROOT}/uploads/profiles" "${APP_ROOT}/uploads/evidence" "${APP_ROOT}/uploads/updates"

if [[ -f /root/aiven-ca.pem ]]; then
  cp /root/aiven-ca.pem /etc/ssl/aiven/ca.pem
  chmod 644 /etc/ssl/aiven/ca.pem
  echo "==> Installed Aiven CA at /etc/ssl/aiven/ca.pem"
fi

if [[ -f /root/drawdream.env ]]; then
  cp /root/drawdream.env "${APP_ROOT}/.env"
  chmod 640 "${APP_ROOT}/.env"
  chown www-data:www-data "${APP_ROOT}/.env"
  echo "==> Installed .env from /root/drawdream.env"
elif [[ ! -f "${APP_ROOT}/.env" ]]; then
  cp "${APP_ROOT}/.env.example" "${APP_ROOT}/.env"
  echo "!! ยังไม่มี .env — แก้ ${APP_ROOT}/.env และ /etc/ssl/aiven/ca.pem ก่อนใช้งาน"
fi

chown -R www-data:www-data "${APP_ROOT}/uploads" "${APP_ROOT}/data"
chmod -R 775 "${APP_ROOT}/uploads" "${APP_ROOT}/data"

cat >/etc/nginx/sites-available/drawdream <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DRAWDREAM_SERVER_IP};

    root ${APP_ROOT};
    index index.php homepage.php;

    client_max_body_size 36M;

    access_log /var/log/nginx/drawdream.access.log;
    error_log  /var/log/nginx/drawdream.error.log;

    location ~ /\.(env|git) {
        deny all;
    }
    location ~ ^/(config|includes|data|tools|scripts)/ {
        deny all;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/drawdream /etc/nginx/sites-enabled/drawdream
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo ""
echo "==> Done. Open: http://${DRAWDREAM_SERVER_IP}/"
echo "==> Next: nano /etc/ssl/aiven/ca.pem  +  nano ${APP_ROOT}/.env  (Aiven DB)"
echo "==> Test DB: cd ${APP_ROOT} && php -r \"require 'includes/env_loader.php'; drawdream_load_env_file(__DIR__.'/.env'); require 'db.php'; echo 'DB OK'.PHP_EOL;\""
