<?php
declare(strict_types=1);

/**
 * Schema migrations — รันเฉพาะ CLI (tools/run_migrations.php) ไม่รันตอนเปิดหน้าเว็บ
 */
function drawdream_migration_version(): int
{
    return 16;
}

function drawdream_migration_cache_path(): string
{
    return dirname(__DIR__) . '/config/migration_done.txt';
}

function drawdream_migration_cached_version(): int
{
    $cache = drawdream_migration_cache_path();
    if (!is_file($cache)) {
        return 0;
    }
    $raw = trim((string)@file_get_contents($cache));
    if (preg_match('/^v(\d+)/', $raw, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function drawdream_migration_should_run(bool $force = false): bool
{
    if ($force) {
        return true;
    }

    return drawdream_migration_cached_version() < drawdream_migration_version();
}

/**
 * รัน migration ทั้งหมด (idempotent) — เรียกจาก CLI เท่านั้น
 *
 * @return array{ran: bool, version: int, steps: list<string>}
 */
function drawdream_run_all_migrations(mysqli $conn, bool $dryRun = false, bool $force = false): array
{
    $target = drawdream_migration_version();
    $cached = drawdream_migration_cached_version();
    $steps = [];

    if (!$dryRun && !drawdream_migration_should_run($force)) {
        return ['ran' => false, 'version' => $cached, 'steps' => ['skip: cache v' . $cached . ' >= v' . $target]];
    }

    if ($dryRun) {
        return ['ran' => true, 'version' => $target, 'steps' => ['dry-run: would migrate v' . $cached . ' -> v' . $target]];
    }

    require_once __DIR__ . '/drawdream_project_status.php';
    require_once __DIR__ . '/admin_audit_migrate.php';
    require_once __DIR__ . '/drawdream_soft_delete.php';
    require_once __DIR__ . '/drawdream_needlist_schema.php';
    require_once __DIR__ . '/drawdream_project_updates_schema.php';
    require_once __DIR__ . '/drawdream_foundation_children_schema.php';
    require_once __DIR__ . '/foundation_review_schema.php';
    require_once __DIR__ . '/drawdream_donor_receipt_schema.php';
    require_once __DIR__ . '/notification_audit.php';
    require_once __DIR__ . '/user_activity_tracking.php';
    require_once __DIR__ . '/payment_transaction_schema.php';
    require_once __DIR__ . '/child_omise_subscription.php';
    require_once __DIR__ . '/child_subscription_history.php';
    require_once __DIR__ . '/drawdream_project_service_charge.php';
    require_once __DIR__ . '/escrow_funds_schema.php';
    require_once __DIR__ . '/password_reset_schema.php';
    require_once __DIR__ . '/drawdream_needlist_catalog_funded.php';
    require_once __DIR__ . '/foundation_outcome_compliance.php';

    $dropFoundationAdminVisibility = static function (mysqli $conn): void {
        if (!drawdream_schema_migrations_allowed()) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach (['admin_visibility_paused_at', 'admin_visibility_pause_reason', 'admin_visibility_paused'] as $col) {
            $chk = @$conn->query("SHOW COLUMNS FROM foundation_profile LIKE '" . $conn->real_escape_string($col) . "'");
            if ($chk && $chk->num_rows > 0) {
                @$conn->query('ALTER TABLE foundation_profile DROP COLUMN `' . $conn->real_escape_string($col) . '`');
            }
        }
    };

    $run = static function (string $label, callable $fn) use (&$steps, $conn): void {
        $fn($conn);
        $steps[] = $label;
    };

    $run('normalize_foundation_project_statuses', 'drawdream_normalize_foundation_project_statuses');
    $run('ensure_admin_audit_table', 'drawdream_ensure_admin_audit_table');
    $run('admin_deduplicate_entity_rows', 'drawdream_admin_deduplicate_entity_rows');
    $run('migrate_remove_soft_delete_columns', 'drawdream_migrate_remove_soft_delete_columns');
    $run('ensure_needlist_schema', 'drawdream_ensure_needlist_schema');
    $run('ensure_foundation_project_update_columns', 'drawdream_ensure_foundation_project_update_columns');
    $run('ensure_foundation_project_form_columns', 'drawdream_ensure_foundation_project_form_columns');
    $run('ensure_foundation_children_columns', 'drawdream_ensure_foundation_children_columns');
    $run('foundation_review_ensure_schema', 'drawdream_foundation_review_ensure_schema');
    $run('ensure_donor_receipt_columns', 'drawdream_ensure_donor_receipt_columns');
    $run('notifications_migrate_legacy_on_boot', 'drawdream_notifications_migrate_legacy_on_boot');
    $run('ensure_user_activity_columns', 'drawdream_ensure_user_activity_columns');
    $run('payment_transaction_ensure_schema', 'drawdream_payment_transaction_ensure_schema');
    $run('child_omise_subscription_ensure_schema', 'drawdream_child_omise_subscription_ensure_schema');
    $run('ensure_notifications_table', 'drawdream_ensure_notifications_table');
    $run('child_subscription_history_ensure_schema', 'drawdream_child_subscription_history_ensure_schema');
    $run('ensure_foundation_project_service_charge_columns', 'drawdream_ensure_foundation_project_service_charge_columns');
    $run('project_backfill_service_charges', 'drawdream_project_backfill_service_charges');
    $run('escrow_funds_run_migrations', 'drawdream_escrow_funds_run_migrations');
    $run('password_reset_ensure_schema', 'drawdream_password_reset_ensure_schema');
    $run('needlist_picks_on_completed_migration', 'drawdream_needlist_picks_on_completed_migration');
    $run('foundation_outcome_compliance_schema', 'drawdream_foundation_outcome_compliance_ensure_schema');
    $run('drop_foundation_admin_visibility_columns', $dropFoundationAdminVisibility);

    $marker = dirname(__DIR__) . '/config/user_presence_legacy_migrated.txt';
    if (!is_file($marker)) {
        drawdream_migrate_legacy_user_presence($conn);
        @file_put_contents($marker, date('c'));
        $steps[] = 'migrate_legacy_user_presence';
    }

    @file_put_contents(drawdream_migration_cache_path(), 'v' . $target . ' ' . date('Y-m-d H:i:s'));

    return ['ran' => true, 'version' => $target, 'steps' => $steps];
}
