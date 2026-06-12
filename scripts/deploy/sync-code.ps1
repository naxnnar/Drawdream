# Upload latest local code to VPS (keeps server .env and uploads/).
# Usage: .\scripts\deploy\sync-code.ps1
# Optional: -ServerIp 82.26.104.99

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

$zipPath = Join-Path $env:TEMP "drawdream-deploy.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host "==> Zipping local code (excludes .git, uploads, .env)..." -ForegroundColor Cyan
$items = Get-ChildItem -Force | Where-Object {
    $_.Name -notin @('.git', 'uploads', 'node_modules', '.cursor', '.env')
}
Compress-Archive -Path ($items | ForEach-Object { $_.FullName }) -DestinationPath $zipPath -Force

$target = "${User}@${ServerIp}"
Write-Host "==> Uploading to $target ..." -ForegroundColor Cyan
scp $zipPath "${target}:/tmp/drawdream-deploy.zip"

Write-Host "==> Extracting on server (preserving .env + uploads/)..." -ForegroundColor Cyan
$remote = @'
set -e
APP=/var/www/drawdream
STAGE=/tmp/drawdream-extract-$$

mkdir -p "$STAGE"
unzip -oq /tmp/drawdream-deploy.zip -d "$STAGE"

if [ -f "$APP/.env" ]; then
  cp "$APP/.env" /root/drawdream.env.before-sync
fi
if [ -d "$APP/uploads" ]; then
  cp -a "$APP/uploads" /tmp/drawdream-uploads-backup-$$
fi

rsync -a --exclude='.env' --exclude='uploads' "$STAGE"/ "$APP"/

if [ -f /root/drawdream.env.before-sync ]; then
  cp /root/drawdream.env.before-sync "$APP/.env"
  chown www-data:www-data "$APP/.env"
  chmod 640 "$APP/.env"
fi
if [ -d /tmp/drawdream-uploads-backup-$$ ]; then
  rm -rf "$APP/uploads"
  cp -a /tmp/drawdream-uploads-backup-$$ "$APP/uploads"
  rm -rf /tmp/drawdream-uploads-backup-$$
fi

mkdir -p "$APP/uploads" "$APP/data"
chown -R www-data:www-data "$APP/uploads" "$APP/data" 2>/dev/null || true
chmod -R 775 "$APP/uploads" "$APP/data" 2>/dev/null || true
rm -rf "$STAGE"
rm -f /var/www/drawdream/config/migration_done.txt

systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
systemctl reload nginx

echo "SYNC OK"
'@

ssh $target $remote

Write-Host ""
Write-Host "Done. Refresh: https://drawdream.org" -ForegroundColor Green
Write-Host "(Server .env and uploads/ were NOT replaced.)" -ForegroundColor DarkGray
