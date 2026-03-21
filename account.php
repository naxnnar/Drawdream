<?php
// ไฟล์นี้: account.php
// หน้าที่: หน้าจัดการข้อมูลบัญชีผู้ใช้
// ------------------------------
// Backend: ตัดสินใจปลายทางหลังล็อกอินตาม role
// ------------------------------
session_start();

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
  header("Location: admin+approve_projects.php");
  exit();
}

// donor (ค่า default)
header("Location: welcome.php");
exit();