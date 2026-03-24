<?php
// ไฟล์นี้: login.php
// หน้าที่: หน้าเข้าสู่ระบบของผู้ใช้
session_start();
include 'db.php';

// ถ้า login แล้ว ไป homepage
if (isset($_SESSION['user_id'])) {
    // แยกเส้นทางตาม role สำหรับผู้ที่ login ค้างอยู่
    if (!empty($_SESSION['show_welcome'])) {
        header("Location: welcome.php");
    } elseif (($_SESSION['role'] ?? '') === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: homepage.php");
    }
    exit();
}

$error = "";
$success = "";
$page = $_GET['page'] ?? 'home';
$step = $_GET['step'] ?? 'choose';
$role = $_GET['role'] ?? '';

// ======== ประมวลผล Register ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $role = $_POST['role'];

    if ($role === 'donor') {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "อีเมลนี้ถูกใช้งานแล้ว";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'donor')");
                $stmt->bind_param("ss", $email, $hashed_password);

                if ($stmt->execute()) {
                    $user_id = $conn->insert_id;
                    $stmt2 = $conn->prepare("INSERT INTO donor (user_id, first_name, last_name) VALUES (?, ?, ?)");
                    $stmt2->bind_param("iss", $user_id, $first_name, $last_name);
                    $stmt2->execute();

                    $success = "สมัครสมาชิกสำเร็จ! กำลังเข้าสู่ระบบ...";
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'donor';
                    $_SESSION['show_welcome'] = true;
                    header("refresh:2;url=welcome.php");
                } else {
                    $error = "เกิดข้อผิดพลาด: " . $stmt->error;
                }
            }
        }
    } elseif ($role === 'foundation') {
        $foundation_name = trim($_POST['foundation_name']);
        $registration_number = trim($_POST['registration_number']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($foundation_name) || empty($email) || empty($password)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "อีเมลนี้ถูกใช้งานแล้ว";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'foundation')");
                $stmt->bind_param("ss", $email, $hashed_password);

                if ($stmt->execute()) {
                    $user_id = $conn->insert_id;
                    $stmt2 = $conn->prepare("INSERT INTO foundation_profile (user_id, foundation_name, registration_number, phone, address) VALUES (?, ?, ?, ?, ?)");
                    $stmt2->bind_param("issss", $user_id, $foundation_name, $registration_number, $phone, $address);
                    $stmt2->execute();

                    $success = "สมัครสมาชิกสำเร็จ! กำลังเข้าสู่ระบบ...";
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'foundation';
                    $_SESSION['show_welcome'] = true;
                    header("refresh:2;url=welcome.php");
                } else {
                    $error = "เกิดข้อผิดพลาด: " . $stmt->error;
                }
            }
        }
    }
}

// ======== ประมวลผล Login ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($email) || empty($password)) {
        $error = "กรุณากรอกอีเมลและรหัสผ่าน";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();


        $row = $result->fetch_assoc();
        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];
            if ($row['role'] === 'foundation') {
                $stmt2 = $conn->prepare("SELECT account_verified FROM foundation_profile WHERE user_id = ?");
                $stmt2->bind_param("i", $row['user_id']);
                $stmt2->execute();
                $fp = $stmt2->get_result()->fetch_assoc();
                $_SESSION['account_verified'] = $fp['account_verified'];
            }

            // ทั้ง admin, donor, foundation: แสดงหน้า Welcome ก่อน
            $_SESSION['show_welcome'] = true;
            header("Location: welcome.php");
            exit();
        } elseif ($row) {
            $error = "รหัสผ่านไม่ถูกต้อง";
        } else {
            $error = "ไม่พบผู้ใช้งานนี้";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page === 'home' ? 'DrawDream' : ($page === 'login' ? 'เข้าสู่ระบบ' : 'สมัครสมาชิก') ?> | DrawDream</title>
    <link rel="stylesheet" href="css/auth.css">
</head>

<body>

    <div class="auth-container">
        <img src="img/logopic.png" alt="DrawDream" class="logo">

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($page === 'home'): ?>
            <h2>คุณมีบัญชีอยู่แล้วหรือยัง?</h2>
            <p class="subtitle">เลือกเพื่อเริ่มต้นใช้งาน DrawDream</p>
            <div class="role-buttons">
                <a href="login.php?page=register&step=choose" class="role-btn">สมัครสมาชิก</a>
                <a href="login.php?page=login&step=choose" class="role-btn role-btn-outline">เข้าสู่ระบบ (มีบัญชีอยู่แล้ว)</a>
            </div>

        <?php elseif ($page === 'login'): ?>
            <?php if ($step === 'choose'): ?>
                <h2>คุณเป็นใคร?</h2>
                <p class="subtitle">เลือกประเภทบัญชีเพื่อเข้าสู่ระบบ</p>
                <div class="role-buttons">
                    <a href="login.php?page=login&step=form&role=donor" class="role-btn">ผู้บริจาค</a>
                    <a href="login.php?page=login&step=form&role=foundation" class="role-btn">มูลนิธิ</a>
                </div>
                <a href="login.php" class="back-link">← ย้อนกลับ</a>
            <?php else: ?>
                <h2>เข้าสู่ระบบ (<?= $role === 'donor' ? 'ผู้บริจาค' : 'มูลนิธิ' ?>)</h2>
                <p class="subtitle">ยินดีต้อนรับกลับมา!</p>
                <form method="POST">
                    <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
                    <div class="form-group">
                        <input type="email" name="email" placeholder="อีเมล" required autofocus>
                    </div>
                    <div class="form-group">
                        <input type="password" name="password" placeholder="รหัสผ่าน" required>
                    </div>
                    <button type="submit" name="login" class="btn-submit">เข้าสู่ระบบ</button>
                </form>
                <a href="login.php?page=login&step=choose" class="back-link">← เปลี่ยนประเภทบัญชี</a>
                <div class="register-link">
                    ยังไม่มีบัญชี? <a href="login.php?page=register&step=choose">สมัครสมาชิก</a>
                </div>
            <?php endif; ?>

        <?php elseif ($page === 'register'): ?>
            <?php if ($step === 'choose'): ?>
                <h2>คุณเป็นใคร?</h2>
                <p class="subtitle">เลือกประเภทบัญชีที่ต้องการสมัคร</p>
                <div class="role-buttons">
                    <a href="login.php?page=register&step=form&role=donor" class="role-btn">ผู้บริจาค</a>
                    <a href="login.php?page=register&step=form&role=foundation" class="role-btn">มูลนิธิ</a>
                </div>
                <a href="login.php" class="back-link">← ย้อนกลับ</a>
            <?php else: ?>
                <?php if ($role === 'donor'): ?>
                    <h2>สมัครสมาชิก (ผู้บริจาค)</h2>
                    <p class="subtitle">กรอกข้อมูลของคุณ</p>
                    <form method="POST">
                        <input type="hidden" name="role" value="donor">
                        <div class="form-group">
                            <input type="text" name="first_name" placeholder="ชื่อ" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="last_name" placeholder="นามสกุล" required>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="อีเมล" required>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)" required minlength="6">
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน" required minlength="6">
                        </div>
                        <button type="submit" name="register" class="btn-submit">สมัครสมาชิก</button>
                    </form>
                <?php else: ?>
                    <h2>สมัครสมาชิก (มูลนิธิ)</h2>
                    <p class="subtitle">กรอกข้อมูลมูลนิธิของคุณ</p>
                    <form method="POST">
                        <input type="hidden" name="role" value="foundation">
                        <div class="form-group">
                            <input type="text" name="foundation_name" placeholder="ชื่อมูลนิธิ" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="registration_number" placeholder="เลขทะเบียนมูลนิธิ">
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="อีเมล" required>
                        </div>
                        <div class="form-group">
                            <input type="tel" name="phone" placeholder="เบอร์โทรศัพท์">
                        </div>
                        <div class="form-group">
                            <textarea name="address" placeholder="ที่อยู่มูลนิธิ" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)" required minlength="6">
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน" required minlength="6">
                        </div>
                        <button type="submit" name="register" class="btn-submit">สมัครสมาชิก</button>
                    </form>
                <?php endif; ?>
                <a href="login.php?page=register&step=choose" class="back-link">← เปลี่ยนประเภทบัญชี</a>
                <div class="register-link">
                    มีบัญชีอยู่แล้ว? <a href="login.php?page=login&step=choose">เข้าสู่ระบบ</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</body>

</html>