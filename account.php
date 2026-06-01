<?php
// account.php — หน้าจัดการข้อมูลบัญชีผู้ใช้
// Backend: ตัดสินใจปลายทางหลังล็อกอินตาม role
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน account
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();

if (!isset($_SESSION['email'])) {
  // ยังไม่ล็อกอินให้กลับหน้าเข้าสู่ระบบหลัก
  header("Location: login.php");
  exit();
}

$role = $_SESSION['role'] ?? 'donor';

if ($role === 'foundation') {
  // มูลนิธิไปหน้ารายการมูลนิธิ
  header("Location: foundation.php");
  exit();
}

if ($role === 'admin') {
  header('Location: admin_dashboard.php');
  exit();
}

// donor (ค่า default)
header("Location: welcome.php");
exit();