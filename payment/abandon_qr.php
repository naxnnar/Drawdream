<?php
// payment/abandon_qr.php — ยกเลิก QR ค้าง (POST จาก scan_qr / check_*_payment)
// Omise: POST /charges/{id}/expire (ปิด QR pending) + DELETE donation pending + ล้าง session
declare(strict_types=1);
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

drawdream_csrf_require_valid('../project.php');

$uid = (int)$_SESSION['user_id'];
$chargeId = trim((string)($_POST['charge_id'] ?? ''));
if ($chargeId === '') {
    $chargeId = trim((string)($_SESSION['pending_charge_id'] ?? ''));
}

// ย้อนกลับเฉพาะหน้าฟีเจอร์ที่เปิดรายการสแกน (ไม่อ่าน return_url จากฟอร์ม)
$return = '../project.php';
if ((int)($_SESSION['pending_child_id'] ?? 0) > 0) {
    $return = '../children_donate.php?id=' . (int)$_SESSION['pending_child_id'];
} elseif ((int)($_SESSION['pending_project_id'] ?? 0) > 0) {
    $return = '../project.php';
} elseif ((int)($_SESSION['pending_foundation_id'] ?? 0) > 0) {
    $return = '../foundation.php';
}

if ($chargeId !== '') {
    drawdream_abandon_pending_donation_by_charge($conn, $uid, $chargeId);
}
drawdream_clear_pending_payment_session();

header('Location: ' . $return);
exit;
