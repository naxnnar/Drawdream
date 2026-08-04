# Deploy admin pages + includes (review_note removal, missing requires)
# Usage: .\scripts\deploy\sync-admin-ops-fix.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "admin_dashboard.php",
    "admin_bulk_approve.php",
    "admin_notifications.php",
    "admin_approve_foundation.php",
    "admin_approve_needlist.php",
    "admin_approve_projects.php",
    "admin_approve_children.php",
    "admin_needlist_view.php",
    "admin_view_needlist.php",
    "admin_escrow.php",
    "admin_view_child.php",
    "admin_view_project.php",
    "admin_view_foundation.php",
    "admin_dashboard_chart_data.php",
    "includes/admin_bulk_approve.php",
    "includes/drawdream_project_status.php",
    "includes/drawdream_needlist_schema.php",
    "includes/needlist_donate_window.php",
    "includes/foundation_review_schema.php",
    "includes/notification_audit.php",
    "includes/admin_audit_migrate.php"
)

Write-Host "==> Deploy admin ops fix ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done. Test admin_dashboard.php and admin_notifications.php bulk approve" -ForegroundColor Green
