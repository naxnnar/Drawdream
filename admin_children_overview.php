<?php
// admin_children_overview.php — redirect: รายชื่อเด็กแอดมินรวมอยู่ที่ children_.php (ตารางเดียวกับเมนู Profilechildren)
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน children overview
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}
header('Location: children_.php', true, 302);
exit();
