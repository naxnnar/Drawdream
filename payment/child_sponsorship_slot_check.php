<?php
// payment/child_sponsorship_slot_check.php — ตรวจสล็อตอุปการะรายรอบ (กัน race ก่อนเปิดฟอร์มบัตร)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('DRAWDREAM_DB_LIGHT', true);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/child_omise_subscription.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'can_subscribe' => false, 'reason' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$childId = (int)($_GET['child_id'] ?? $_GET['id'] ?? 0);
$donorUid = (int)$_SESSION['user_id'];

if ($childId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'can_subscribe' => false, 'reason' => 'invalid_child'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = drawdream_child_subscription_slot_status($conn, $childId, $donorUid);

echo json_encode([
    'ok' => (bool)($status['ok'] ?? false),
    'can_subscribe' => (bool)($status['can_subscribe'] ?? false),
    'reason' => (string)($status['reason'] ?? ''),
    'message' => (string)($status['message'] ?? ''),
    'holder_user_id' => (int)($status['holder_user_id'] ?? 0),
], JSON_UNESCAPED_UNICODE);
