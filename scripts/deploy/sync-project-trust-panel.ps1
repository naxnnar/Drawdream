# Deploy project trust panel on payment page
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "payment/payment_project.php",
    "css/payment.css"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Write-Host "Done." -ForegroundColor Green
