<?php
// logout.php — ออกจากระบบและล้างเซสชัน
// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน logout
session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
