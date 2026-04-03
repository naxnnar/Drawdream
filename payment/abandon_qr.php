<?php
// POST: ยกเลิกรายการสแกน QR ที่ยังไม่ชำระ — ตั้งสถานะเป็น failed ไม่ใช้ pending ค้าง
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../project.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
$chargeId = trim((string)($_POST['charge_id'] ?? ''));
if ($chargeId === '') {
    $chargeId = trim((string)($_SESSION['pending_charge_id'] ?? ''));
}
$fallback = '../project.php';
$return = drawdream_safe_payment_return_url((string)($_POST['return_url'] ?? ''), $fallback);

if ($chargeId !== '') {
    drawdream_abandon_pending_donation_by_charge($conn, $uid, $chargeId);
}
drawdream_clear_pending_payment_session();

header('Location: ' . $return);
exit;
