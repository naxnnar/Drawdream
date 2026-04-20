<?php
// admin_projects.php — redirect: คิวอนุมัติอยู่ที่ศูนย์แจ้งเตือน; มี ?id= ไปหน้าตรวจสอบรายการนั้น
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน projects
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs === '') {
    header('Location: admin_notifications.php#admin-pending-projects', true, 302);
} else {
    header('Location: admin_approve_projects.php?' . $qs, true, 302);
}
exit();
