#!/usr/bin/env bash
# Emergency patch: remove deleted_at from GitHub main on a DB that has no deleted_at column.
# Run on VPS: bash /root/patch-on-server.sh
set -euo pipefail

APP="${DRAWDREAM_APP_ROOT:-/var/www/drawdream}"
cd "$APP"

echo "==> Strip deleted_at from SQL in *.php (except soft_delete stub)"
while IFS= read -r -d '' f; do
  sed -i \
    -e 's/[[:space:]]*AND deleted_at IS NULL//g' \
    -e 's/[[:space:]]*AND c\.deleted_at IS NULL//g' \
    -e 's/[[:space:]]*AND p\.deleted_at IS NULL//g' \
    -e 's/[[:space:]]*AND fc\.deleted_at IS NULL//g' \
    -e "s/[[:space:]]*\\\$sql \.= ' AND c\.deleted_at IS NULL';//g" \
    "$f"
done < <(find . -name '*.php' ! -path './includes/drawdream_soft_delete.php' -print0)

if [[ -f project.php ]]; then
  sed -i \
    -e "/\\\$where\[\] = 'p\.deleted_at IS NULL';/d" \
    -e "/\\\$latestWhere\[\] = 'p\.deleted_at IS NULL';/d" \
    project.php
fi

echo "==> Disable soft-delete bootstrap (do not ADD deleted_at column)"
cat >includes/drawdream_soft_delete.php <<'PHP'
<?php
declare(strict_types=1);

/** DrawDream no longer uses soft delete / deleted_at. */
function drawdream_ensure_soft_delete_columns(mysqli $conn): void
{
}

function drawdream_migrate_remove_soft_delete_columns(mysqli $conn): void
{
}
PHP

if [[ -f db.php ]]; then
  sed -i 's/drawdream_ensure_soft_delete_columns/drawdream_migrate_remove_soft_delete_columns/g' db.php
fi

rm -f config/migration_done.txt

echo "==> Test DB bootstrap"
php -r "require 'includes/env_loader.php'; drawdream_load_env_file(__DIR__.'/.env'); require 'db.php'; echo 'DB OK'.PHP_EOL;"

echo "==> Done. Open http://$(hostname -I 2>/dev/null | awk '{print $1}')/login.php"
