<?php
// logout.php — ออกจากระบบและล้างเซสชัน
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน logout
require_once __DIR__ . '/includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/includes/session_init.php';
drawdream_session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
