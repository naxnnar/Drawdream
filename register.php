<?php
// เปิดโชว์ error ชั่วคราวต้องมาก่อนสุด
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';
$error = "";
$success = "";

// โหมดที่เลือกจากหน้าแรก
$as = $_GET['as'] ?? 'donor'; // donor หรือ foundation
$pageTitle = ($as === 'foundation') ? 'สมัครสมาชิก (มูลนิธิ)' : 'สมัครสมาชิก (ผู้บริจาค)';

if (isset($_POST['register'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // ตรวจสอบข้อมูลพื้นฐาน
    if (empty($email) || empty($password)) {
        $error = "กรุณากรอกอีเมลและรหัสผ่าน";
    } elseif ($password !== $confirm_password) {
        $error = "รหัสผ่านไม่ตรงกัน";
    } elseif (strlen($password) < 6) {
        $error = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";
    } else {
        // ตรวจสอบว่าอีเมลซ้ำหรือไม่
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "อีเมลนี้ถูกใช้งานแล้ว";
        } else {
            // เข้ารหัสรหัสผ่าน
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // กำหนด role
            $role = ($as === 'foundation') ? 'foundation' : 'donor';

            // เริ่ม transaction
            $conn->begin_transaction();

            try {
                // บันทึกลง users table
                $stmt = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $email, $hashed_password, $role);
                $stmt->execute();
                $user_id = $conn->insert_id;

                if ($as === 'donor') {
                    // บันทึกข้อมูลผู้บริจาค
                    $first_name = trim($_POST['first_name'] ?? '');
                    $last_name = trim($_POST['last_name'] ?? '');

                    if (empty($first_name) || empty($last_name)) {
                        throw new Exception("กรุณากรอกชื่อ-นามสกุล");
                    }

                    $stmt = $conn->prepare("INSERT INTO donor (user_id, first_name, last_name) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $user_id, $first_name, $last_name);
                    $stmt->execute();

                } else {
                    // บันทึกข้อมูลมูลนิธิ
                    $foundation_name = trim($_POST['foundation_name'] ?? '');
                    $registration_number = trim($_POST['registration_number'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    $address = trim($_POST['address'] ?? '');
                    $website = trim($_POST['website'] ?? '');

                    if (empty($foundation_name) || empty($registration_number) || empty($phone) || empty($address)) {
                        throw new Exception("กรุณากรอกข้อมูลให้ครบถ้วน");
                    }

                    $stmt = $conn->prepare("INSERT INTO foundation_profile (user_id, foundation_name, registration_number, phone, address, website) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssss", $user_id, $foundation_name, $registration_number, $phone, $address, $website);
                    $stmt->execute();
                }

                $conn->commit();

                // เข้าสู่ระบบอัตโนมัติ
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;

                // ไปหน้าตาม role ทันที
                if ($role === 'donor') {
                    header("Location: p1_home.php");
                } else {
                    // foundation
                    header("Location: p2_project.php");
                }
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
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

        <?php if (!empty($success)): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="post" action="register.php?as=<?php echo urlencode($as); ?>">
            
            <?php if ($as === 'donor'): ?>
                <!-- ฟอร์มสำหรับผู้บริจาค -->
                <input
                    type="text"
                    name="first_name"
                    placeholder="ชื่อ"
                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                    required
                >

                <input
                    type="text"
                    name="last_name"
                    placeholder="นามสกุล"
                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                    required
                >

            <?php else: ?>
                <!-- ฟอร์มสำหรับมูลนิธิ -->
                <input
                    type="text"
                    name="foundation_name"
                    placeholder="ชื่อมูลนิธิ"
                    value="<?php echo htmlspecialchars($_POST['foundation_name'] ?? ''); ?>"
                    required
                >

                <input
                    type="text"
                    name="registration_number"
                    placeholder="เลขทะเบียนมูลนิธิ"
                    value="<?php echo htmlspecialchars($_POST['registration_number'] ?? ''); ?>"
                    required
                >

                <input
                    type="tel"
                    name="phone"
                    placeholder="เบอร์โทรศัพท์"
                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                    required
                >

                <textarea
                    name="address"
                    placeholder="ที่อยู่มูลนิธิ"
                    rows="3"
                    required
                ><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>

                <input
                    type="url"
                    name="website"
                    placeholder="เว็บไซต์/โซเชียลมีเดีย (ถ้ามี)"
                    value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>"
                >

            <?php endif; ?>

            <!-- ฟิลด์ทั่วไป -->
            <input
                type="email"
                name="email"
                placeholder="อีเมล"
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                required
            >

            <input
                type="password"
                name="password"
                placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)"
                required
            >

            <input
                type="password"
                name="confirm_password"
                placeholder="ยืนยันรหัสผ่าน"
                required
            >

            <button type="submit" name="register" class="btn-main">
                สมัครสมาชิก
            </button>
        </form>

        <a href="register_choose.php" class="link">← เปลี่ยนประเภทบัญชี</a>
        <a href="login.php?as=<?php echo urlencode($as); ?>" class="link">มีบัญชีอยู่แล้ว? เข้าสู่ระบบ</a>

    </div>
</div>

</body>
</html>