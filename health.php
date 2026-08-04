<?php
declare(strict_types=1);
/**
 * Health check — JSON สำหรับ uptime monitor / cron
 *
 * GET /health.php
 * GET /health.php?token=...  (ถ้าตั้ง DRAWDREAM_HEALTH_TOKEN ใน .env)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');

$expectedToken = trim((string)(getenv('DRAWDREAM_HEALTH_TOKEN') ?: ''));
if ($expectedToken !== '') {
    $given = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
    if (!hash_equals($expectedToken, $given)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$checks = [];
$ok = true;

require_once __DIR__ . '/includes/drawdream_migrations.php';

$dbMs = null;
try {
    if (!defined('DRAWDREAM_DB_LIGHT')) {
        define('DRAWDREAM_DB_LIGHT', true);
    }
    require __DIR__ . '/db.php';
    $t0 = microtime(true);
    $r = @$conn->query('SELECT 1 AS ok');
    $dbMs = (int)round((microtime(true) - $t0) * 1000);
    $checks['db'] = [
        'ok' => $r && (int)($r->fetch_assoc()['ok'] ?? 0) === 1,
        'latency_ms' => $dbMs,
    ];
    if (!$checks['db']['ok']) {
        $ok = false;
    }
} catch (Throwable $e) {
    $ok = false;
    $checks['db'] = ['ok' => false, 'error' => $e->getMessage()];
}

$migVersion = drawdream_migration_cached_version();
$target = drawdream_migration_version();
$checks['migration'] = [
    'ok' => $migVersion >= $target,
    'cached' => $migVersion,
    'required' => $target,
];
if (!$checks['migration']['ok']) {
    $ok = false;
}

$uploadsOk = is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads');
$checks['uploads'] = ['ok' => $uploadsOk];
if (!$uploadsOk) {
    $ok = false;
}

$omiseKey = (string)(getenv('OMISE_SECRET_KEY') ?: '');
$checks['omise'] = [
    'ok' => $omiseKey !== '',
    'mode' => str_starts_with($omiseKey, 'skey_test_') ? 'test' : (str_starts_with($omiseKey, 'skey_') ? 'live' : 'unset'),
];

http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'service' => 'drawdream',
    'time' => date('c'),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
