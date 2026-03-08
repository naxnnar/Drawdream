<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

if (isset($_GET['id'])) {
    $child_id = (int)$_GET['id'];
    
    $sql = "UPDATE Children SET approve_profile = 'อนุมัติ' WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    
    if ($stmt->execute()) {
        echo "<script>alert('อนุมัติโปรไฟล์เรียบร้อยแล้ว'); window.location='donation.php';</script>";
    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); history.back();</script>";
    }
}
?>