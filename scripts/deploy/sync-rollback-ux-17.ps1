# Roll back VPS to pre-ux-17 (commit before faf4822). Does NOT change local working tree.
# Usage: .\scripts\deploy\sync-rollback-ux-17.ps1
# Optional: -SshHost drawdream-vps -SkipLaterDeploys

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$SshHost = "",
    [string]$RemoteApp = "/var/www/drawdream",
    [string]$RollbackRef = "34186bb",
    [switch]$SkipLaterDeploys
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -ServerIp $ServerIp -User $User -SshHost $SshHost

$ux17Files = @(
    "foundation_donate_info.php",
    "foundation.php",
    "css/foundation_donate_info.css",
    "includes/return_to.php",
    "includes/password_policy.php",
    "login.php",
    "auth/google_start.php",
    "auth/google_callback.php",
    "children_donate.php",
    "navbar.php",
    "homepage.php",
    "profile.php",
    "payment/child_donate.php",
    "payment/child_subscription_create.php",
    "payment/foundation_donate.php",
    "payment/payment_project.php",
    "tools/fix_notification_receipt_links.php",
    "about.php",
    "children_.php",
    "detail_alin.php",
    "detail_pin.php",
    "detail_san.php"
)

$addedInUx17 = @(
    "includes/return_to.php",
    "includes/password_policy.php",
    "tools/fix_notification_receipt_links.php"
)

Write-Host "==> Roll back ux-17 ($($ux17Files.Count) files) from git $RollbackRef -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan

$restore = @()
$removeOnServer = @()
foreach ($rel in $ux17Files) {
    $exists = $false
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    git cat-file -e "${RollbackRef}:${rel}" 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $exists = $true }
    $ErrorActionPreference = $prevEap
    if ($exists) {
        $restore += $rel
    } elseif ($addedInUx17 -contains $rel) {
        $removeOnServer += $rel
    } else {
        throw "Cannot roll back $rel - missing at $RollbackRef and not in remove list"
    }
}

$staging = Join-Path $env:TEMP ("drawdream-rollback-" + [guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $staging -Force | Out-Null
try {
    foreach ($rel in $restore) {
        $dest = Join-Path $staging $rel
        $destParent = Split-Path $dest -Parent
        if (-not (Test-Path $destParent)) {
            New-Item -ItemType Directory -Path $destParent -Force | Out-Null
        }
        & git -C $Root show "${RollbackRef}:${rel}" | Set-Content -LiteralPath $dest -Encoding utf8NoBOM
        if ($LASTEXITCODE -ne 0) {
            throw "git show failed for ${RollbackRef}:${rel}"
        }
    }

    Write-Host "  Restore $($restore.Count) files from $RollbackRef" -ForegroundColor DarkGray
    Sync-FileListToServer -Root $staging -Files $restore -RemoteApp $RemoteApp
    foreach ($rel in $restore) {
        Write-Host "  OK  $rel" -ForegroundColor DarkGray
    }

    if ($removeOnServer.Count -gt 0) {
        $rmPaths = ($removeOnServer | ForEach-Object { "$RemoteApp/$_".Replace('\', '/') }) -join ' '
        Write-Host "  Remove $($removeOnServer.Count) files added by ux-17" -ForegroundColor DarkGray
        Invoke-SshCommand "rm -f $rmPaths"
        foreach ($rel in $removeOnServer) {
            Write-Host "  DEL $rel" -ForegroundColor DarkGray
        }
    }
} finally {
    Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true; systemctl reload nginx"'

if (-not $SkipLaterDeploys) {
    Write-Host ""
    Write-Host "==> Re-apply deploys that overlap ux-17 (footer, admin logo, trust panel)..." -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot "sync-footer-foundation.ps1") -SshHost $SshTarget
    & (Join-Path $PSScriptRoot "sync-admin-logo.ps1") -SshHost $SshTarget
    & (Join-Path $PSScriptRoot "sync-project-trust-panel.ps1") -SshHost $SshTarget
}

Write-Host ""
Write-Host "Done. ux-17 rolled back on VPS (password back to 10 chars, return_to removed)." -ForegroundColor Green
Write-Host "Local files unchanged. Test: https://drawdream.org/login.php" -ForegroundColor Green
