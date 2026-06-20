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
$syncSh = Join-Path $PSScriptRoot "server-sync.sh"

Write-Host "==> Uploading to $target ..." -ForegroundColor Cyan
scp $zipPath "${target}:/tmp/drawdream-deploy.zip"
scp $syncSh "${target}:/tmp/server-sync.sh"

Write-Host "==> Extracting on server (preserving .env + uploads/)..." -ForegroundColor Cyan
ssh $target "chmod +x /tmp/server-sync.sh && bash /tmp/server-sync.sh"

Write-Host ""
Write-Host "Done. Refresh: https://drawdream.org" -ForegroundColor Green
Write-Host "(Server .env and uploads/ were NOT replaced.)" -ForegroundColor DarkGray
