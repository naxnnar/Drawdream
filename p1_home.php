
<?php
session_start();
if (!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>หน้าหลัก</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <h2>ยินดีต้อนรับเข้าสู่ระบบ</h2>
</div>

</body>
</html>
