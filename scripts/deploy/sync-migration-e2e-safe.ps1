# Deploy: migration off hot path + E2E safety gate
# Usage: .\scripts\deploy\sync-migration-e2e-safe.ps1 -SshHost drawdream-vps

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

Write-Host "==> Pre-deploy: local static E2E" -ForegroundColor Cyan
php tools/e2e/static_suite.php
if ($LASTEXITCODE -ne 0) { throw "static_suite failed" }

$files = @(
    "db.php",
    "includes/drawdream_schema_once.php",
    "includes/drawdream_migrations.php",
    "includes/foundation_review_schema.php",
    "includes/user_activity_tracking.php",
    "includes/password_reset_schema.php",
    "includes/drawdream_needlist_schema.php",
    "includes/escrow_funds_schema.php",
    "includes/admin_audit_migrate.php",
    "includes/drawdream_foundation_children_schema.php",
    "includes/drawdream_donor_receipt_schema.php",
    "includes/drawdream_project_updates_schema.php",
    "includes/notification_audit.php",
    "includes/drawdream_soft_delete.php",
    "includes/foundation_dashboard_ops.php",
    "includes/foundation_dashboard_todos.php",
    "admin_escrow.php",
    "admin_approve_foundation.php",
    "payment/child_donate.php",
    "payment/project_service_charge.php",
    "payment/needlist_service_charge.php",
    "payment/child_subscription_create.php",
    "payment/child_sponsorship_slot_check.php",
    "payment/check_child_payment.php",
    "payment/check_needlist_payment.php",
    "payment/check_project_service_charge_payment.php",
    "payment/check_needlist_service_charge_payment.php",
    "payment/omise_webhook.php",
    "payment/cron_child_subscription_charges.php",
    "payment/child_subscription_cancel.php",
    "tools/run_migrations.php",
    "tools/audit_schema.php",
    "tools/run_boot_migration.php",
    "tools/e2e/bootstrap.php",
    "tools/e2e/static_suite.php",
    "tools/e2e/register_suite.php",
    "tools/e2e/run_all.php",
    "scripts/deploy/_ssh_target.ps1"
)

Write-Host "==> Sync $($files.Count) files -> ${SshHost}:${RemoteApp}" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Write-Host "==> Post-deploy: migrate + audit + static E2E + reload" -ForegroundColor Cyan
Invoke-DrawdreamPostDeploy -RemoteApp $RemoteApp
Write-Host "Done." -ForegroundColor Green
