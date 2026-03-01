<?php
session_start();
if (isset($_SESSION['user_id'])) {
    // ถ้าล็อกอินอยู่แล้วให้เด้งไปหน้าตาม role
    if ($_SESSION['role'] === 'donor') {
        header("Location: p1_home.php");
    } else {
        header("Location: p2_project.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg">
    <div class="login-card">
        <img src="img/logopic.png" class="logo" alt="DrawDream Logo">
        <h2>คุณเป็นใคร?</h2>
        <p style="text-align:center; color:#666; margin-bottom:25px;">
            เลือกประเภทบัญชีที่ต้องการสมัคร
        </p>

        <!-- ผู้บริจาค -->
        <a href="register.php?as=donor" class="btn-main" style="display:block; text-align:center; text-decoration:none; margin-bottom:15px;">
            ผู้บริจาค
        </a>

        <!-- มูลนิธิ -->
        <a href="register.php?as=foundation" class="btn-main" style="display:block; text-align:center; text-decoration:none;">
            มูลนิธิ
        </a>

        <a href="index.php" class="link" style="display:block; text-align:center; margin-top:20px;">← ย้อนกลับ</a>
    </div>
</div>
</body>
</html>