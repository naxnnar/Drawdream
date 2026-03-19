<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

if (isset($_GET['id'])) {
    $child_id = (int)$_GET['id'];

    $action = $_GET['action'] ?? 'approve';
    $new_status = ($action === 'reject') ? 'ไม่อนุมัติ' : 'อนุมัติ';
    $alert_msg = ($action === 'reject') ? 'ไม่อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'อนุมัติโปรไฟล์เรียบร้อยแล้ว';

    $sql = "UPDATE Children SET approve_profile = ? WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_status, $child_id);

    if ($stmt->execute()) {
        echo "<script>alert('" . $alert_msg . "'); window.location='donation.php';</script>";
    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); history.back();</script>";
    }
}
?>