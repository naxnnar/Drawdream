<?php
// ไฟล์นี้: logout.php
// หน้าที่: ไฟล์ออกจากระบบและล้างเซสชัน
session_start();
session_destroy();
header("Location: login.php");
?>
