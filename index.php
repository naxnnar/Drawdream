<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (isset($_SESSION['user_id'])) {
    // ถ้าล็อกอินอยู่แล้วให้เด้งไปหน้าตาม role
    if ($_SESSION['role'] === 'donor') {
        header("Location: p1_home.php");
    } else {
        header("Location: p2_project.php"); // admin/foundation
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยินดีต้อนรับ | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg">
    <div class="login-card">
        <!-- โลโก้ -->
        <img src="img/logopic.png" class="logo" alt="DrawDream Logo">
        
        <h2>คุณมีบัญชีอยู่แล้วหรือยัง?</h2>
        <p style="text-align:center; color:#666; margin-bottom:25px;">
            เลือกเพื่อเริ่มต้นใช้งาน DrawDream
        </p>

        <!-- ปุ่มสมัครสมาชิก -->
        <a href="register_choose.php" class="btn-main" style="display:block; text-align:center; text-decoration:none; margin-bottom:15px;">
            สมัครสมาชิก
        </a>

        <!-- ปุ่มเข้าสู่ระบบ -->
        <a href="login_choose.php" class="btn-main" style="display:block; text-align:center; text-decoration:none;">
            เข้าสู่ระบบ (มีบัญชีอยู่แล้ว)
        </a>
    </div>
</div>
</body>
</html>