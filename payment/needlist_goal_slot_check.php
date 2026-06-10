<?php
// payment/needlist_goal_slot_check.php — ตรวจว่า needlist ยังรับบริจาคได้ (กัน race ตอนรอสแกน QR)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/drawdream_needlist_payment_finalize.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'can_pay' => false, 'reason' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$foundationId = (int)($_GET['fid'] ?? $_GET['foundation_id'] ?? 0);
$amount = (float)($_GET['amount'] ?? 0);
$chargeId = trim((string)($_GET['charge_id'] ?? ''));
$donorUid = (int)$_SESSION['user_id'];

if ($foundationId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'can_pay' => false, 'reason' => 'invalid_foundation'], JSON_UNESCAPED_UNICODE);
    exit;
}

$remaining = drawdream_needlist_remaining_goal_baht($conn, $foundationId);
$canPay = drawdream_needlist_can_accept_donation_amount($conn, $foundationId, $amount);

$response = [
    'ok' => true,
    'can_pay' => $canPay,
    'remaining' => (int)floor($remaining + 1e-9),
    'reason' => $canPay ? 'open' : ($remaining <= 0 ? 'goal_met' : 'amount_exceeds_remaining'),
    'closed' => !$canPay,
    'abandoned' => false,
];

if (!$canPay && $chargeId !== '' && isset($_SESSION['pending_charge_id']) && $_SESSION['pending_charge_id'] === $chargeId) {
    if ((int)($_SESSION['pending_foundation_id'] ?? 0) === $foundationId) {
        drawdream_abandon_pending_donation_by_charge($conn, $donorUid, $chargeId);
        drawdream_clear_pending_payment_session();
        $response['abandoned'] = true;
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
