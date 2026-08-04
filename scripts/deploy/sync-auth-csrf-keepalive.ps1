# Deploy auth CSRF keepalive (long-form login/register)
# Usage: .\scripts\deploy\sync-auth-csrf-keepalive.ps1 -SshHost drawdream-vps

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$SshHost = "",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -ServerIp $ServerIp -User $User -SshHost $SshHost

$files = @(
    "includes/csrf.php",
    "auth/csrf_refresh.php",
    "js/auth-csrf-keepalive.js",
    "login.php",
    "auth/forgot_password.php",
    "auth/reset_password.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test:" -ForegroundColor Green
Write-Host "  https://drawdream.org/login.php?page=register&step=form&role=donor" -ForegroundColor Cyan
Write-Host "  Open form, wait a few minutes, submit (no CSRF stale error)" -ForegroundColor Cyan
