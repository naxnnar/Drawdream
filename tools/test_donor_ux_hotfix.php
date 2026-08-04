<?php
declare(strict_types=1);
/**
 * Post-deploy smoke tests for donor UX hotfix (DB_LIGHT, schema v6, notif CSRF).
 * CLI on server: php tools/test_donor_ux_hotfix.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

$root = dirname(__DIR__);
require $root . '/db.php';
require_once $root . '/includes/drawdream_donor_receipt_schema.php';

$fail = 0;
$pass = 0;

function ok(bool $cond, string $label): void
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

$cache = trim((string)@file_get_contents($root . '/config/migration_done.txt'));
ok(str_starts_with($cache, 'v6'), 'migration cache is v6 (' . $cache . ')');

$flags = drawdream_donor_receipt_column_flags($conn);
ok((bool)($flags['receipt_type'] ?? false), 'donor.receipt_type column exists');
ok((bool)($flags['receipt_company_name'] ?? false), 'donor.receipt_company_name column exists');

$lightFiles = ['login.php', 'profile.php', 'children_donate.php'];
foreach ($lightFiles as $rel) {
    $path = $root . '/' . $rel;
    ok(is_file($path), "file exists: {$rel}");
    $src = (string)@file_get_contents($path);
    ok(str_contains($src, "define('DRAWDREAM_DB_LIGHT', true)"), "{$rel} has DRAWDREAM_DB_LIGHT");
}

$notifSrc = (string)@file_get_contents($root . '/notifications.php');
ok(str_contains($notifSrc, "isset(\$_POST['mark_all'])"), 'notifications.php uses POST mark_all');
ok(!str_contains($notifSrc, 'mark_all=1'), 'notifications.php removed GET mark_all link');

$markSrc = (string)@file_get_contents($root . '/mark_notif_read.php');
ok(str_contains($markSrc, "isset(\$_POST['mark_all'])"), 'mark_notif_read.php uses POST mark_all');
ok(!preg_match('/isset\s*\(\s*\$_GET\s*\[\s*[\'"]all[\'"]\s*\]\s*\)/', $markSrc), 'mark_notif_read.php removed GET all');

$navSrc = (string)@file_get_contents($root . '/navbar.php');
ok(str_contains($navSrc, "method: 'POST'"), 'navbar mark-all uses POST fetch');

$r = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM `user` WHERE role = \'donor\' LIMIT 1');
ok(is_object($r), 'DB query works');
if (is_object($r)) {
    $row = mysqli_fetch_assoc($r);
    ok((int)($row['c'] ?? 0) >= 1, 'at least one donor user in DB');
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
