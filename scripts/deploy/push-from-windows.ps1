# Upload DrawDream to VPS and run server-bootstrap.sh
# Usage: .\scripts\deploy\push-from-windows.ps1
# You will be prompted for the VPS root password (2-3 times).

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

$zipPath = Join-Path $env:TEMP "drawdream-deploy.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host "==> Creating zip (excludes uploads, .git)..." -ForegroundColor Cyan
$items = Get-ChildItem -Force | Where-Object { $_.Name -notin @('.git', 'uploads', 'node_modules', '.cursor') }
Compress-Archive -Path ($items | ForEach-Object { $_.FullName }) -DestinationPath $zipPath -Force

$target = "${User}@${ServerIp}"
Write-Host "==> Uploading to $target ..." -ForegroundColor Cyan
scp "$PSScriptRoot\server-bootstrap.sh" "${target}:/root/server-bootstrap.sh"
scp $zipPath "${target}:/tmp/drawdream-deploy.zip"

if (Test-Path (Join-Path $Root ".env")) {
    Write-Host "==> Uploading .env from dev machine..." -ForegroundColor Cyan
    scp (Join-Path $Root ".env") "${target}:/root/drawdream.env"
}

$caCandidates = @(
    (Join-Path $Root "config\aiven-ca.pem"),
    (Join-Path $Root "aiven-ca.pem"),
    "$env:USERPROFILE\Downloads\ca.pem"
)
foreach ($ca in $caCandidates) {
    if (Test-Path $ca) {
        Write-Host "==> Uploading Aiven CA: $ca" -ForegroundColor Cyan
        scp $ca "${target}:/root/aiven-ca.pem"
        break
    }
}

Write-Host "==> Running bootstrap on server (may take 3-8 minutes)..." -ForegroundColor Cyan
ssh $target "chmod +x /root/server-bootstrap.sh && DRAWDREAM_SERVER_IP=$ServerIp bash /root/server-bootstrap.sh"

Write-Host ""
Write-Host "Done. Open: http://${ServerIp}/login.php" -ForegroundColor Green
