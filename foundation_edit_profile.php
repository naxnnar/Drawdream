<?php

// foundation_edit_profile.php — มูลนิธิแก้ไขโปรไฟล์องค์กร
// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน edit profile
declare(strict_types=1);

/**
 * ทางลัดจากหน้ามูลนิธิ → ฟอร์มแก้ไขข้อมูลมูลนิธิ (update_profile.php)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: welcome.php');
    exit;
}

header('Location: update_profile.php', true, 302);
exit;
