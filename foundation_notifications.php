<?php
// foundation_notifications.php — คง path เดิมไว้ เปลี่ยนไปหน้าประวัติแจ้งเตือนรวม
// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน notifications
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? '?' . $_SERVER['QUERY_STRING']
    : '';
header('Location: notifications.php' . $qs, true, 302);
exit();
