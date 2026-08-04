<?php
// notifications_feed.php — JSON สำหรับ dropdown แจ้งเตือน (prefetch ฝั่งเบราว์เซอร์)

declare(strict_types=1);

require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['foundation', 'donor'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');
$navBase = '';

require_once __DIR__ . '/includes/navbar_cache.php';

$cacheKey = 'user_notifs_' . $role . '_' . $uid . '_feed';
$cached = drawdream_navbar_cache_get($cacheKey, 90);
if ($cached !== null && isset($cached['html'], $cached['count'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo json_encode([
        'count' => (int)$cached['count'],
        'html' => (string)$cached['html'],
        'cached' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/includes/navbar_notifications.php';

define('DRAWDREAM_DB_LIGHT', true);
require_once __DIR__ . '/db.php';

try {
    $bundle = drawdream_navbar_notifications_bundle($conn, $uid, $role, $navBase, true);
    echo json_encode([
        'count' => (int)$bundle['count'],
        'html' => (string)$bundle['html'],
        'cached' => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'load_failed'], JSON_UNESCAPED_UNICODE);
}
