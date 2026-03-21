<?php
// ไฟล์นี้: db.php
// หน้าที่: ไฟล์เชื่อมต่อฐานข้อมูลกลางของระบบ
$conn = mysqli_connect("localhost", "root", "", "drawdream_db");

if (!$conn) {
    die("Connection failed");
}
?>
