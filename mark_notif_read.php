<?php
// mark_notif_read.php — ทำเครื่องหมายแจ้งเตือนอ่านแล้ว
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน mark notif read
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/notification_audit.php';

if (!isset($_SESSION['user_id'])) exit();
$uid = (int)$_SESSION['user_id'];

// อ่านทั้งหมด: ไม่ลบข้อความแจ้งเตือน แต่บันทึกเวลาอ่านล่าสุดของผู้ใช้
if (isset($_GET['all'])) {
    drawdream_notifications_mark_all_read($conn, $uid);
    // ซ่อนแจ้งเตือน auto ที่ navbar สร้างซ้ำอัตโนมัติ จนกว่าสถานะงานจะเปลี่ยน
    $_SESSION['dismiss_auto_need_round_open'] = 1;
    $_SESSION['dismiss_auto_child_outcome'] = 1;
    $ref = $_SERVER['HTTP_REFERER'] ?? 'profile.php';
    header("Location: $ref");
    exit();
}
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM notifications WHERE notif_id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
    }
    echo "ok";
    exit();
}
echo "ok";