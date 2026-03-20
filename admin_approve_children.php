<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

$needCols = [
    'reject_reason' => "ALTER TABLE Children ADD COLUMN reject_reason TEXT NULL AFTER approve_profile",
    'reviewed_at' => "ALTER TABLE Children ADD COLUMN reviewed_at DATETIME NULL AFTER reject_reason",
];
foreach ($needCols as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM Children LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}

$child_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'approve';
$rejectReason = trim($_POST['reject_reason'] ?? '');
$returnUrl = $_POST['return'] ?? $_GET['return'] ?? 'children_.php';
if (!preg_match('/^[a-zA-Z0-9_\.\-]+(\?[a-zA-Z0-9_=&\-]*)?$/', $returnUrl)) {
    $returnUrl = 'children_.php';
}

if ($child_id <= 0) {
    echo "<script>alert('ไม่พบรหัสโปรไฟล์เด็ก'); history.back();</script>";
    exit();
}

if ($action === 'reject' && $rejectReason === '') {
    echo "<script>alert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ'); history.back();</script>";
    exit();
}

$new_status = ($action === 'reject') ? 'ไม่อนุมัติ' : 'อนุมัติ';
$alert_msg = ($action === 'reject') ? 'ไม่อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'อนุมัติโปรไฟล์เรียบร้อยแล้ว';
$reasonToSave = ($action === 'reject') ? $rejectReason : null;

$sql = "UPDATE Children SET approve_profile = ?, reject_reason = ?, reviewed_at = NOW() WHERE child_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $new_status, $reasonToSave, $child_id);

if ($stmt->execute()) {
    echo "<script>alert('" . $alert_msg . "'); window.location='" . $returnUrl . "';</script>";
} else {
    echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); history.back();</script>";
}
?>