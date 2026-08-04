# Upload latest local code to VPS (keeps server .env and uploads/).
# Usage: .\scripts\deploy\sync-code.ps1
# Optional: -SshHost drawdream-vps

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$SshHost = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -ServerIp $ServerIp -User $User -SshHost $SshHost

$tarPath = Join-Path $env:TEMP "drawdream-deploy.tar.gz"
if (Test-Path $tarPath) { Remove-Item $tarPath -Force }

Write-Host "==> Archiving local code (tar.gz, excludes .git uploads .env)..." -ForegroundColor Cyan
$excludeArgs = @(
    "--exclude=.git",
    "--exclude=uploads",
    "--exclude=.env",
    "--exclude=node_modules",
    "--exclude=.cursor"
)
& tar -czf $tarPath @excludeArgs -C $Root .
if ($LASTEXITCODE -ne 0) {
    throw "tar failed"
}

$syncSh = Join-Path $PSScriptRoot "server-sync.sh"

Write-Host "==> Uploading to $SshTarget ..." -ForegroundColor Cyan
Copy-FileToServer -LocalPath $tarPath -RemotePath "/tmp/drawdream-deploy.tar.gz"
Copy-FileToServer -LocalPath $syncSh -RemotePath "/tmp/server-sync.sh"

Write-Host "==> Extracting on server (preserving .env + uploads/)..." -ForegroundColor Cyan
Invoke-SshCommand "sed -i 's/\r$//' /tmp/server-sync.sh && chmod +x /tmp/server-sync.sh && bash /tmp/server-sync.sh"

Remove-Item $tarPath -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "Done. Refresh: https://drawdream.org" -ForegroundColor Green
Write-Host "(Server .env and uploads/ were NOT replaced.)" -ForegroundColor DarkGray
