<?php
// เปิดโชว์ error ต้องมาก่อนสุด
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

$error = "";

// โหมดที่เลือกจากหน้าแรก
$as = $_GET['as'] ?? 'donor'; // donor หรือ foundation
$pageTitle = ($as === 'foundation') ? 'เข้าสู่ระบบ (มูลนิธิ)' : 'เข้าสู่ระบบ (ผู้บริจาค)';

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password_input = $_POST['password'] ?? '';

    // ล็อกอินจากตาราง users (admin/foundation/donor)
    $stmt = $conn->prepare("SELECT user_id, email, password, role FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        // รองรับทั้งรหัสผ่านธรรมดา และแบบ hash
        $passOK = ($password_input === $row['password']) || password_verify($password_input, $row['password']);

        if ($passOK) {

            // เช็คว่าเข้าถูกโหมดไหม
            if ($as === 'foundation' && $row['role'] !== 'foundation') {
                $error = "บัญชีนี้ไม่สามารถเข้าสู่ระบบในโหมดมูลนิธิได้";
            } elseif ($as === 'donor' && !in_array($row['role'], ['donor','admin'])) {
                $error = "บัญชีนี้ไม่สามารถเข้าสู่ระบบในโหมดผู้บริจาคได้";
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['email']   = $row['email'];
                $_SESSION['role']    = $row['role'];

                // ไปหน้าตาม role
                if ($row['role'] === 'donor') {
                    header("Location: p1_home.php");
                } else {
                    // admin / foundation
                    header("Location: p2_project.php");
                }
                exit();
            }

        } else {
            $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
        }

    } else {
        $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="bg">
    <div class="login-card">

        <!-- โลโก้ -->
        <img src="img/logopic.png" class="logo" alt="DrawDream Logo">

        <h2><?php echo htmlspecialchars($pageTitle); ?></h2>

        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <!-- สำคัญ: ใส่ action เพื่อให้ query ?as=donor/foundation ไม่หายตอนกด submit -->
        <form method="post" action="login.php?as=<?php echo urlencode($as); ?>">
            <input
                type="email"
                name="email"
                placeholder="ระบุอีเมลของคุณ"
                required
            >

            <input
                type="password"
                name="password"
                placeholder="รหัสผ่าน"
                required
            >

            <button type="submit" name="login" class="btn-main">
                เข้าสู่ระบบ
            </button>
        </form>

        <a href="login_choose.php" class="link">← เปลี่ยนประเภทบัญชี</a>
        <a href="register.php?as=<?php echo urlencode($as); ?>" class="link">ยังไม่มีบัญชี? สมัครสมาชิก</a>

    </div>
</div>

</body>
</html>