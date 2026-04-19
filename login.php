<?php
// login.php — เข้าสู่ระบบ / เลือกบทบาท
// ล็อกอินสำเร็จ: ตั้ง show_welcome → welcome.php (ทุก role รวม admin; ไม่ใช้ admin_welcome)

session_start();
include 'db.php';
require_once __DIR__ . '/includes/address_helpers.php';
require_once __DIR__ . '/includes/foundation_banks.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_once __DIR__ . '/includes/utf8_helpers.php';

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
$googleLoginEnabled = drawdream_google_oauth_is_ready();

if ($error === '' && isset($_GET['error'])) {
    $error = trim((string)$_GET['error']);
}

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
        } elseif (drawdream_utf8_strlen($password) !== 10) {
            $error = "รหัสผ่านต้องมีความยาว 10 ตัวอักษรเท่านั้น";
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM `user` WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "อีเมลนี้ถูกใช้งานแล้ว";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO `user` (email, password, role) VALUES (?, ?, 'donor')");
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
        $registration_number = preg_replace('/\D/', '', trim($_POST['registration_number'] ?? ''));
        $email = trim($_POST['email']);
        $phone = preg_replace('/\D/', '', trim((string)($_POST['phone'] ?? '')));
        $address = drawdream_merge_foundation_address_from_post($_POST);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account_number = preg_replace('/\D/', '', trim($_POST['bank_account_number'] ?? ''));
        $bank_account_name = trim($_POST['bank_account_name'] ?? '');

        if (empty($foundation_name) || empty($email) || empty($password)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif (strlen($registration_number) !== 13) {
            $error = "เลขประจำตัวนิติบุคคลต้องเป็นตัวเลข 13 หลัก";
        } elseif (strlen($phone) !== 10) {
            $error = "เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก";
        } elseif ($address === '') {
            $error = "กรุณาเลือกจังหวัด อำเภอ ตำบล และรหัสไปรษณีย์ให้ครบ";
        } elseif (drawdream_utf8_strlen($password) !== 10) {
            $error = "รหัสผ่านต้องมีความยาว 10 ตัวอักษรเท่านั้น";
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } elseif ($bank_name !== '' && !in_array($bank_name, array_keys(drawdream_foundation_bank_list()), true)) {
            $error = "กรุณาเลือกธนาคารจากรายการ";
        } elseif ($bank_account_number !== '' && strlen($bank_account_number) !== 10) {
            $error = "เลขบัญชีต้องเป็นตัวเลขครบ 10 หลัก";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM `user` WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "อีเมลนี้ถูกใช้งานแล้ว";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO `user` (email, password, role) VALUES (?, ?, 'foundation')");
                $stmt->bind_param("ss", $email, $hashed_password);

                if ($stmt->execute()) {
                    $user_id = $conn->insert_id;
                    $stmt2 = $conn->prepare("INSERT INTO foundation_profile (user_id, foundation_name, registration_number, phone, address, bank_name, bank_account_number, bank_account_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt2->bind_param("isssssss", $user_id, $foundation_name, $registration_number, $phone, $address, $bank_name, $bank_account_number, $bank_account_name);
                    $stmt2->execute();

                    $success = "สมัครสมาชิกสำเร็จ! กำลังเข้าสู่ระบบ...";
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'foundation';
                    $_SESSION['account_verified'] = 0;
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

    if (empty($email) || empty($password)) {
        $error = "กรุณากรอกอีเมลและรหัสผ่าน";
    } elseif (drawdream_utf8_strlen($password) !== 10) {
        $error = "รหัสผ่านต้องมีความยาว 10 ตัวอักษรเท่านั้น";
    } else {
        // ดึงข้อมูล user จาก email ก่อน ไม่ filter role
        $stmt = $conn->prepare("SELECT * FROM `user` WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];

            if ($row['role'] === 'foundation') {
                $stmt2 = $conn->prepare("SELECT account_verified FROM foundation_profile WHERE user_id = ?");
                $stmt2->bind_param("i", $row['user_id']);
                $stmt2->execute();
                $fp = $stmt2->get_result()->fetch_assoc();
                $_SESSION['account_verified'] = $fp['account_verified'];
            }

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
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page === 'home' ? 'DrawDream' : ($page === 'login' ? 'เข้าสู่ระบบ' : 'สมัครสมาชิก') ?> | DrawDream</title>
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/thai_address.css?v=1">
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
                <a href="login.php?page=login" class="role-btn role-btn-outline">เข้าสู่ระบบ (มีบัญชีอยู่แล้ว)</a>
            </div>

        <?php elseif ($page === 'login'): ?>
            <h2>เข้าสู่ระบบ</h2>
            <p class="subtitle">ยินดีต้อนรับกลับมา!</p>
            <form method="POST">
                <div class="form-group">
                    <input type="email" name="email" placeholder="อีเมล" required autofocus>
                </div>
                <div class="form-group">
                    <div style="position:relative;">
                        <input type="password" name="password" placeholder="รหัสผ่าน (10 ตัวอักษร)" required minlength="10" maxlength="10" class="password-input">
                        <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                        <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                    </div>
                </div>
                <button type="submit" name="login" class="btn-submit">เข้าสู่ระบบ</button>
            </form>
            <div class="google-login-wrap">
                <?php if ($googleLoginEnabled): ?>
                    <a href="auth/google_start.php" class="btn-google-login">เข้าสู่ระบบด้วย Google</a>
                <?php else: ?>
                    <div class="google-login-note">Google Login ยังไม่พร้อม: ตั้งค่า `config/google_oauth.local.php` ก่อน</div>
                <?php endif; ?>
            </div>
            <a href="login.php" class="back-link">← ย้อนกลับ</a>
            <div class="register-link">
                ยังไม่มีบัญชี? <a href="login.php?page=register&step=choose">สมัครสมาชิก</a>
            </div>

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
                            <div style="position:relative;">
                                <input type="password" name="password" placeholder="รหัสผ่าน (10 ตัวอักษรเท่านั้น)" required minlength="10" maxlength="10" class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน (10 ตัวอักษร)" required minlength="10" maxlength="10" class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
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
                            <input type="text" name="registration_number" placeholder="เลขประจำตัวนิติบุคคล (13 หลัก)" required inputmode="numeric" autocomplete="off" maxlength="13" pattern="\d{13}">
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="อีเมล" required>
                        </div>
                        <div class="form-group">
                            <input type="tel" name="phone" placeholder="เบอร์โทรศัพท์ (10 หลัก)" required inputmode="numeric" autocomplete="tel" maxlength="10" minlength="10" pattern="\d{10}" title="กรอกตัวเลข 10 หลัก">
                        </div>
                        <?php
                        $thai_address_options = ['require' => true];
                        include __DIR__ . '/includes/thai_address_fields.php';
                        ?>
                        <div class="form-group">
                            <label for="foundation_reg_bank_name" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">ชื่อธนาคาร</label>
                            <select name="bank_name" id="foundation_reg_bank_name" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;">
                                <option value="">— เลือกธนาคาร (ถ้ามี) —</option>
                                <?php foreach (drawdream_foundation_bank_list() as $bval => $blabel): ?>
                                    <option value="<?= htmlspecialchars($bval) ?>"><?= htmlspecialchars($blabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="foundation_reg_bank_account" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">เลขบัญชี</label>
                            <input type="text" name="bank_account_number" id="foundation_reg_bank_account" inputmode="numeric" autocomplete="off" maxlength="10" pattern="\d{10}" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;">
                        </div>
                        <div class="form-group">
                            <label for="foundation_reg_bank_holder" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">ชื่อบัญชีธนาคาร</label>
                            <input type="text" name="bank_account_name" id="foundation_reg_bank_holder" placeholder="ชื่อบัญชี (ถ้ามี)" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;">
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="password" placeholder="รหัสผ่าน (10 ตัวอักษรเท่านั้น)" required minlength="10" maxlength="10" class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน (10 ตัวอักษร)" required minlength="10" maxlength="10" class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <button type="submit" name="register" class="btn-submit">สมัครสมาชิก</button>
                    </form>
                <?php endif; ?>
                <a href="login.php?page=register&step=choose" class="back-link">← เปลี่ยนประเภทบัญชี</a>
                <div class="register-link">
                    มีบัญชีอยู่แล้ว? <a href="login.php?page=login">เข้าสู่ระบบ</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Script: toggle password visibility ทุกช่องรหัสผ่าน -->
    <script>
    // ฟังก์ชันสำหรับสลับการแสดง/ซ่อนรหัสผ่าน (ใช้ไอคอนตาเปิด/ปิด)
    document.querySelectorAll('.toggle-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = btn.parentElement.querySelector('.password-input');
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '👁'; // (ดูรหัสผ่าน)
            } else {
                input.type = 'password';
                btn.textContent = '👁'; //  (ซ่อนรหัสผ่าน)
            }
        });
       
    });
    </script>
<?php if ($page === 'register' && ($step ?? '') === 'form' && ($role ?? '') === 'foundation'): ?>
    <script src="js/thai_address_select.js?v=1"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof ThaiAddressSelect !== 'undefined') {
        ThaiAddressSelect.mount({
          province: '#addr_province',
          amphoe: '#addr_amphoe',
          tambon: '#addr_tambon',
          zip: '#addr_zip'
        });
      }
      var acc = document.getElementById('foundation_reg_bank_account');
      if (acc) {
        acc.addEventListener('input', function () {
          acc.value = acc.value.replace(/\D/g, '').slice(0, 10);
        });
      }
    });
    </script>
<?php endif; ?>

</body>

</html>