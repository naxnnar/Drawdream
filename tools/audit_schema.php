<?php
declare(strict_types=1);
/**
 * ตรวจ schema แบบ read-only (ไม่ ALTER) — รันก่อน reload PHP หลัง deploy
 *
 * Usage:
 *   php tools/audit_schema.php
 *   php tools/audit_schema.php --strict   (exit 1 ถ้า migration < v8)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('DRAWDREAM_DB_LIGHT', true);

require dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/drawdream_migrations.php';
require_once dirname(__DIR__) . '/includes/drawdream_donor_receipt_schema.php';

$strict = in_array('--strict', $argv ?? [], true);
$fail = 0;
$pass = 0;

function audit_ok(bool $cond, string $label): void
{
    global $fail, $pass;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $fail++;
}

function audit_has_column(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = @$conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");

    return $r && $r->num_rows > 0;
}

function audit_has_table(mysqli $conn, string $table): bool
{
    $t = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$t}'");

    return $r && $r->num_rows > 0;
}

$cached = drawdream_migration_cached_version();
$target = drawdream_migration_version();
audit_ok($cached >= $target, "migration cache v{$cached} >= v{$target}");
if ($strict) {
    audit_ok($cached >= $target, 'strict: migration must be current');
}

audit_ok(audit_has_table($conn, 'user'), 'table user');
audit_ok(audit_has_table($conn, 'foundation_profile'), 'table foundation_profile');
audit_ok(audit_has_table($conn, 'donation'), 'table donation');
audit_ok(audit_has_table($conn, 'notifications'), 'table notifications');

audit_ok(audit_has_column($conn, 'user', 'last_seen_at'), 'user.last_seen_at');
audit_ok(audit_has_column($conn, 'foundation_profile', 'registration_number'), 'foundation_profile.registration_number');
audit_ok(audit_has_column($conn, 'foundation_profile', 'account_verified'), 'foundation_profile.account_verified');
audit_ok(audit_has_column($conn, 'donation', 'omise_charge_id'), 'donation.omise_charge_id');
audit_ok(audit_has_column($conn, 'donation', 'donate_type'), 'donation.donate_type');
audit_ok(audit_has_column($conn, 'notifications', 'is_read'), 'notifications.is_read');

$receiptFlags = drawdream_donor_receipt_column_flags($conn);
audit_ok((bool)($receiptFlags['receipt_type'] ?? false), 'donor.receipt_type');

audit_ok(audit_has_table($conn, 'password_reset_token'), 'table password_reset_token');

echo "----\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
