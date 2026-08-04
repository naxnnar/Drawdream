<?php
declare(strict_types=1);
/**
 * Static checks — ไม่แตะ DB/Omise ปลอดภัยรันบน production หลัง deploy
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

require __DIR__ . '/bootstrap.php';

$e2e_fail = 0;
$e2e_pass = 0;
$root = dirname(__DIR__, 2);

$loginSrc = (string)@file_get_contents($root . '/login.php');
e2e_ok(str_contains($loginSrc, 'drawdream_login_is_register_post'), 'login.php register POST detection');
e2e_ok(str_contains($loginSrc, 'name="register" value="1"'), 'login.php hidden register field');
e2e_ok(str_contains($loginSrc, "define('DRAWDREAM_DB_LIGHT', true)"), 'login.php uses DB_LIGHT');

$dbSrc = (string)@file_get_contents($root . '/db.php');
e2e_ok(!str_contains($dbSrc, 'drawdream_run_all_migrations'), 'db.php does not run migrations on boot');
e2e_ok(!str_contains($dbSrc, '$_ddMigrationVersion'), 'db.php migration cache block removed');

$schemaOnce = (string)@file_get_contents($root . '/includes/drawdream_schema_once.php');
e2e_ok(str_contains($schemaOnce, 'drawdream_schema_migrations_allowed'), 'schema_once guards web requests');

e2e_ok(is_file($root . '/tools/run_migrations.php'), 'tools/run_migrations.php exists');
e2e_ok(is_file($root . '/health.php'), 'health.php exists');
e2e_ok(is_file($root . '/tools/monitor/check_errors.php'), 'tools/monitor/check_errors.php exists');

foreach (['payment/check_child_payment.php', 'payment/omise_webhook.php', 'admin_escrow.php'] as $rel) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    e2e_ok(!preg_match('/^\s*drawdream_(ensure_|escrow_funds_ensure|child_omise_subscription_ensure)/m', $src), "{$rel} no ensure call at request start");
}

$migSrc = (string)@file_get_contents($root . '/includes/drawdream_migrations.php');
e2e_ok(str_contains($migSrc, 'drawdream_migration_version(): int'), 'drawdream_migrations.php defines version');

echo "---- static PASS={$e2e_pass} FAIL={$e2e_fail}\n";
exit($e2e_fail > 0 ? 1 : 0);
