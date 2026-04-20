<?php
// updateprofile.php — legacy URL → update_profile.php (รวมเส้นทางแก้โปรไฟล์)
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน updateprofile
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$query = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
$target = 'update_profile.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 301);
exit;
