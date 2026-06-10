<?php
declare(strict_types=1);

// JSON สถิติผู้ใช้งานสด — สำหรับแดชบอร์ดแอดมิน (รีเฟรชอัตโนมัติ)

include __DIR__ . '/db.php';
require_once __DIR__ . '/includes/user_activity_tracking.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = drawdream_admin_user_activity_stats($conn);
echo json_encode(['ok' => true] + $stats, JSON_UNESCAPED_UNICODE);
