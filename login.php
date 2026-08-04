<?php
// login.php — เข้าสู่ระบบ / เลือกบทบาท
// ล็อกอินสำเร็จ → homepage หรือ admin_dashboard (หรือ return_to ถ้ามี)

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน login

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/user_activity_tracking.php';
require_once __DIR__ . '/includes/address_helpers.php';
require_once __DIR__ . '/includes/foundation_banks.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/return_to.php';
require_once __DIR__ . '/includes/welcome_session.php';
require_once __DIR__ . '/includes/foundation_review_schema.php';
require_once __DIR__ . '/includes/auth_register_helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

drawdream_return_to_capture_from_request();
$storedReturnTo = drawdream_return_to_get();

// ถ้า login แล้ว ไป homepage (หรือ return_to ถ้ามี)
if (isset($_SESSION['user_id'])) {
    $role = (string)($_SESSION['role'] ?? '');
    $returnDest = drawdream_return_to_consume_after_login($role);
    if ($returnDest !== null) {
        header('Location: ' . $returnDest);
        exit();
    }
    header('Location: ' . drawdream_post_login_redirect_url($role));
    exit();
}

$error = "";
$success = "";
$form_old = [];
$csrf_stale_msg = 'ฟอร์มค้างนานเกินไป กรุณากดส่งอีกครั้ง (ข้อมูลที่กรอกไว้ยังอยู่)';

if (!function_exists('drawdream_login_form_old')) {
    function drawdream_login_form_old(string $key, string $default = ''): string
    {
        global $form_old;
        return htmlspecialchars(trim((string)($form_old[$key] ?? $default)), ENT_QUOTES, 'UTF-8');
    }
}

$page = $_GET['page'] ?? 'home';
$step = $_GET['step'] ?? 'choose';
$role = $_GET['role'] ?? '';
$googleLoginEnabled = drawdream_google_oauth_is_ready();

if ($error === '' && isset($_GET['error'])) {
    $error = trim((string)$_GET['error']);
}

// ======== ประมวลผล Register ========
function drawdream_login_is_register_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    if (isset($_POST['register'])) {
        return true;
    }
    $role = (string)($_POST['role'] ?? '');
    if ($role === 'foundation' && trim((string)($_POST['foundation_name'] ?? '')) !== '') {
        return true;
    }
    if ($role === 'donor' && trim((string)($_POST['email'] ?? '')) !== '' && isset($_POST['first_name'])) {
        return true;
    }

    return false;
}

if (drawdream_login_is_register_post()) {
    if (!drawdream_auth_csrf_verify()) {
        $form_old = $_POST;
        unset($form_old['password'], $form_old['confirm_password']);
        $error = $csrf_stale_msg;
        $page = 'register';
        $step = 'form';
        $role = (string)($_POST['role'] ?? 'donor');
    } else {
    $page = 'register';
    $step = 'form';
    $role = (string)($_POST['role'] ?? 'donor');

    if ($role === 'donor') {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = drawdream_normalize_email((string)($_POST['email'] ?? ''));
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "รูปแบบอีเมลไม่ถูกต้อง";
        } elseif (!drawdream_password_meets_policy($password)) {
            $error = drawdream_password_policy_message();
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } else {
            $reg = drawdream_auth_register_donor($conn, $email, $password, $first_name, $last_name);
            if (!$reg['ok']) {
                $error = (string)($reg['error'] ?? drawdream_auth_email_in_use_message());
            }
        }
    } elseif ($role === 'foundation') {
        $foundation_name = trim($_POST['foundation_name']);
        $registration_number = preg_replace('/\D/', '', trim($_POST['registration_number'] ?? ''));
        $email = drawdream_normalize_email((string)($_POST['email'] ?? ''));
        $phone = preg_replace('/\D/', '', trim((string)($_POST['phone'] ?? '')));
        $address = drawdream_merge_foundation_address_from_post($_POST);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account_number = preg_replace('/\D/', '', trim($_POST['bank_account_number'] ?? ''));
        $bank_account_name = trim($_POST['bank_account_name'] ?? '');

        if (empty($foundation_name) || empty($email) || empty($password)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "รูปแบบอีเมลไม่ถูกต้อง";
        } elseif (strlen($registration_number) !== 13) {
            $error = "เลขประจำตัวนิติบุคคลต้องเป็นตัวเลข 13 หลัก";
        } elseif (!drawdream_thai_phone_digits_ok($phone)) {
            $error = 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 9–10 หลัก';
        } elseif ($address === '') {
            $error = "กรุณาเลือกจังหวัด อำเภอ ตำบล และรหัสไปรษณีย์ให้ครบ";
        } elseif (!drawdream_password_meets_policy($password)) {
            $error = drawdream_password_policy_message();
        } elseif ($password !== $confirm_password) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } elseif ($bank_name !== '' && !in_array($bank_name, array_keys(drawdream_foundation_bank_list()), true)) {
            $error = "กรุณาเลือกธนาคารจากรายการ";
        } elseif ($bank_account_number !== '' && strlen($bank_account_number) !== 10) {
            $error = "เลขบัญชีต้องเป็นตัวเลขครบ 10 หลัก";
        } else {
            try {
            $stmt = $conn->prepare("SELECT user_id FROM `user` WHERE email = ?");
            if (!$stmt) {
                throw new RuntimeException('prepare email check: ' . $conn->error);
            }
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "อีเมลนี้ถูกใช้งานแล้ว หากเคยสมัครหรือเข้าสู่ระบบด้วย Google แล้ว ให้กด เข้าสู่ระบบ ด้านล่าง หรือใช้ ลืมรหัสผ่าน";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO `user` (email, password, role) VALUES (?, ?, 'foundation')");
                    if (!$stmt) {
                        throw new RuntimeException('prepare user');
                    }
                    $stmt->bind_param("ss", $email, $hashed_password);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('insert user: ' . $stmt->error);
                    }
                    $user_id = (int)$conn->insert_id;

                    $stmt2 = $conn->prepare(
                        "INSERT INTO foundation_profile (user_id, foundation_name, registration_number, phone, address, bank_name, bank_account_number, bank_account_name)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if (!$stmt2) {
                        throw new RuntimeException('prepare foundation_profile: ' . $conn->error);
                    }
                    $stmt2->bind_param("isssssss", $user_id, $foundation_name, $registration_number, $phone, $address, $bank_name, $bank_account_number, $bank_account_name);
                    if (!$stmt2->execute()) {
                        throw new RuntimeException('insert foundation_profile: ' . $stmt2->error);
                    }
                    $foundation_id = (int)$conn->insert_id;
                    if ($foundation_id <= 0) {
                        $stFid = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
                        if ($stFid) {
                            $stFid->bind_param('i', $user_id);
                            $stFid->execute();
                            $fidRow = $stFid->get_result()->fetch_assoc();
                            $foundation_id = (int)($fidRow['foundation_id'] ?? 0);
                        }
                    }
                    $conn->commit();

                    drawdream_session_regenerate_after_login();
                    drawdream_auth_csrf_rotate();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'foundation';
                    $_SESSION['account_verified'] = 0;
                    $_SESSION['foundation_id'] = $foundation_id;
                    drawdream_log_user_login($conn, $user_id, 'register');
                    $dest = drawdream_post_login_redirect_url('foundation')
                        . '?registered=1&msg=' . rawurlencode('สมัครสมาชิกสำเร็จ — บัญชีมูลนิธิรอแอดมินตรวจสอบก่อนใช้งานเต็มรูปแบบ');
                    header('Location: ' . $dest);
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('[drawdream_foundation_register] ' . $e->getMessage());
                    $error = 'ไม่สามารถบันทึกข้อมูลมูลนิธิได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
                }
            }
            } catch (Throwable $e) {
                error_log('[drawdream_foundation_register] ' . $e->getMessage());
                $error = 'ไม่สามารถบันทึกข้อมูลมูลนิธิได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
            }
        }
    }

    if ($error !== '') {
        $form_old = $_POST;
        unset($form_old['password'], $form_old['confirm_password']);
    }
    }
}

// ======== ประมวลผล Login ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!drawdream_auth_csrf_verify()) {
        $form_old = $_POST;
        unset($form_old['password']);
        $error = $csrf_stale_msg;
        $page = 'login';
    } else {
    $email = drawdream_normalize_email((string)($_POST['email'] ?? ''));
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "กรุณากรอกอีเมลและรหัสผ่าน";
    } else {
        // ดึงข้อมูล user จาก email ก่อน ไม่ filter role
        $stmt = $conn->prepare("SELECT * FROM `user` WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row && password_verify($password, $row['password'])) {
            drawdream_session_regenerate_after_login();
            drawdream_auth_csrf_rotate();
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['email']   = $row['email'];
            $_SESSION['role']    = $row['role'];
            drawdream_log_user_login($conn, (int)$row['user_id'], 'password');

            if ($row['role'] === 'foundation') {
                $stmt2 = $conn->prepare("SELECT account_verified, foundation_name, foundation_id FROM foundation_profile WHERE user_id = ?");
                $stmt2->bind_param("i", $row['user_id']);
                $stmt2->execute();
                $fp = $stmt2->get_result()->fetch_assoc();
                $_SESSION['account_verified'] = $fp['account_verified'];
                $_SESSION['foundation_id'] = (int)($fp['foundation_id'] ?? 0);
            }

            $returnDest = drawdream_return_to_consume_after_login((string)$row['role']);
            if ($returnDest !== null) {
                header('Location: ' . $returnDest);
            } else {
                header('Location: ' . drawdream_post_login_redirect_url((string)$row['role']));
            }
            exit();
        } elseif ($row) {
            $error = "รหัสผ่านไม่ถูกต้อง";
        } else {
            $error = "ไม่พบผู้ใช้งานนี้";
        }
    }
    }
}

$isFoundationRegister = ($page === 'register' && ($step ?? '') === 'form' && ($role ?? '') === 'foundation');
?>
<!DOCTYPE html>
<html lang="th">

<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $page === 'home' ? 'DrawDream' : ($page === 'login' ? 'เข้าสู่ระบบ' : 'สมัครสมาชิก') ?> | DrawDream</title>
    <link rel="stylesheet" href="css/auth.css?v=5">
    <link rel="stylesheet" href="css/thai_address.css?v=1">
<?php if ($isFoundationRegister): ?>
    <link rel="preload" href="vendor/thai_address/raw_database.json" as="fetch" crossorigin>
<?php endif; ?>
</head>

<body>

    <div class="auth-container<?= $isFoundationRegister ? ' auth-container--foundation-register' : '' ?>">
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
            <form method="POST" action="login.php?page=login" autocomplete="on">
                <?= drawdream_auth_csrf_field() ?>
                <?php if ($storedReturnTo !== null): ?>
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($storedReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <div class="form-group">
                    <input type="email" name="email" id="login-email" placeholder="อีเมล" required autofocus autocomplete="username" value="<?= drawdream_login_form_old('email') ?>">
                </div>
                <div class="form-group">
                    <div style="position:relative;">
                        <input type="password" name="password" id="login-password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder(), ENT_QUOTES, 'UTF-8') ?>" required autocomplete="current-password" <?= drawdream_password_input_attrs() ?> class="password-input">
                        <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                        <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                    </div>
                </div>
                <button type="submit" name="login" class="btn-submit">เข้าสู่ระบบ</button>
            </form>
            <p class="forgot-password-link">
                <a href="auth/forgot_password.php">ลืมรหัสผ่าน?</a>
            </p>
            <div class="google-login-wrap">
                <?php if ($googleLoginEnabled): ?>
                    <?php
                    $googleStartUrl = 'auth/google_start.php';
                    if ($storedReturnTo !== null) {
                        $googleStartUrl .= '?return_to=' . rawurlencode($storedReturnTo);
                    }
                    ?>
                    <a href="<?= htmlspecialchars($googleStartUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-google-login">เข้าสู่ระบบด้วย Google</a>
                <?php else: ?>
                    <div class="google-login-note">Google Login ยังไม่พร้อม: ตั้งค่า `config/google_oauth.local.php` ก่อน</div>
                <?php endif; ?>
            </div>
            <a href="login.php" class="back-link">← ย้อนกลับ</a>
            <div class="register-link">
                ยังไม่มีบัญชี? <a href="login.php?page=register&step=choose">สมัครบัญชี</a>
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
                    <form method="POST" action="login.php?page=register&amp;step=form&amp;role=donor">
                        <?= drawdream_auth_csrf_field() ?>
                        <input type="hidden" name="register" value="1">
                        <input type="hidden" name="role" value="donor">
                        <div class="form-group">
                            <input type="text" name="first_name" placeholder="ชื่อ" required value="<?= drawdream_login_form_old('first_name') ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" name="last_name" placeholder="นามสกุล" required value="<?= drawdream_login_form_old('last_name') ?>">
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="อีเมล" required value="<?= drawdream_login_form_old('email') ?>">
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder(), ENT_QUOTES, 'UTF-8') ?>" required <?= drawdream_password_input_attrs() ?> class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder('ยืนยันรหัสผ่าน'), ENT_QUOTES, 'UTF-8') ?>" required <?= drawdream_password_input_attrs() ?> class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <button type="submit" name="register" class="btn-submit">สมัครสมาชิก</button>
                    </form>
                <?php else: ?>
                    <h2>สมัครสมาชิก (มูลนิธิ)</h2>
                    <p class="subtitle">กรอกข้อมูลมูลนิธิของคุณ</p>
                    <form method="POST" action="login.php?page=register&amp;step=form&amp;role=foundation" id="foundationRegisterForm">
                        <?= drawdream_auth_csrf_field() ?>
                        <input type="hidden" name="register" value="1">
                        <input type="hidden" name="role" value="foundation">
                        <div class="form-group">
                            <input type="text" name="foundation_name" placeholder="ชื่อมูลนิธิ" required value="<?= drawdream_login_form_old('foundation_name') ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" name="registration_number" placeholder="เลขประจำตัวนิติบุคคล (13 หลัก)" required inputmode="numeric" autocomplete="off" maxlength="13" pattern="\d{13}" value="<?= drawdream_login_form_old('registration_number') ?>">
                        </div>
                        <div class="form-group">
                            <input type="tel" name="phone" placeholder="เบอร์โทรศัพท์ (9–10 หลัก)" required inputmode="numeric" autocomplete="tel" maxlength="10" minlength="9" pattern="0\d{8,9}" title="กรอกตัวเลข 9–10 หลัก" value="<?= drawdream_login_form_old('phone') ?>">
                        </div>
                        <?php
                        $thai_address_options = [
                            'require' => true,
                            'full_hidden' => (string)($form_old['addr_full_hidden'] ?? ''),
                            'line' => [
                                'house_no' => (string)($form_old['addr_house_no'] ?? ''),
                                'soi' => (string)($form_old['addr_soi'] ?? ''),
                                'road' => (string)($form_old['addr_road'] ?? ''),
                            ],
                        ];
                        include __DIR__ . '/includes/thai_address_fields.php';
                        ?>
                        <div class="form-group">
                            <label for="foundation_reg_bank_name" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">ชื่อธนาคาร</label>
                            <select name="bank_name" id="foundation_reg_bank_name" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;">
                                <option value="">— เลือกธนาคาร (ถ้ามี) —</option>
                                <?php foreach (drawdream_foundation_bank_list() as $bval => $blabel): ?>
                                    <option value="<?= htmlspecialchars($bval) ?>"<?= (($form_old['bank_name'] ?? '') === $bval) ? ' selected' : '' ?>><?= htmlspecialchars($blabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="foundation_reg_bank_account" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">เลขบัญชี</label>
                            <input type="text" name="bank_account_number" id="foundation_reg_bank_account" inputmode="numeric" autocomplete="off" maxlength="10" pattern="\d{10}" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;" value="<?= drawdream_login_form_old('bank_account_number') ?>">
                        </div>
                        <div class="form-group">
                            <label for="foundation_reg_bank_holder" class="form-label" style="display:block;margin-bottom:6px;font-weight:600;">ชื่อบัญชีธนาคาร</label>
                            <input type="text" name="bank_account_name" id="foundation_reg_bank_holder" placeholder="ชื่อบัญชี (ถ้ามี)" autocomplete="off" class="form-input" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #D1D5DB;" value="<?= drawdream_login_form_old('bank_account_name') ?>">
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="อีเมล" required autocomplete="email" value="<?= drawdream_login_form_old('email') ?>">
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder(), ENT_QUOTES, 'UTF-8') ?>" required <?= drawdream_password_input_attrs() ?> class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder('ยืนยันรหัสผ่าน'), ENT_QUOTES, 'UTF-8') ?>" required <?= drawdream_password_input_attrs() ?> class="password-input">
                                <!-- ปุ่มแสดง/ซ่อนรหัสผ่าน -->
                                <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                            </div>
                        </div>
                        <button type="submit" name="register" class="btn-submit" id="foundationRegisterSubmit">สมัครสมาชิก</button>
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
    document.querySelectorAll('.toggle-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = btn.parentElement.querySelector('.password-input');
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '👁';
            } else {
                input.type = 'password';
                btn.textContent = '👁';
            }
        });
    });

    </script>
    <script src="js/auth-csrf-keepalive.js?v=2" data-csrf-url="auth/csrf_refresh.php"></script>
<?php if ($page === 'register' && ($step ?? '') === 'form' && ($role ?? '') === 'foundation'): ?>
    <script src="js/thai_address_select.js?v=1"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof ThaiAddressSelect !== 'undefined') {
        <?php
        $iniTambon = (string)($form_old['addr_tambon'] ?? '');
        $iniZip = (string)($form_old['addr_zip'] ?? '');
        if ($iniTambon !== '' && str_contains($iniTambon, "\x1E")) {
            $parts = explode("\x1E", $iniTambon, 2);
            if ($iniZip === '' && ($parts[0] ?? '') !== '') {
                $iniZip = (string)$parts[0];
            }
            $iniTambon = (string)($parts[1] ?? $iniTambon);
        }
        ?>
        ThaiAddressSelect.mount({
          province: '#addr_province',
          amphoe: '#addr_amphoe',
          tambon: '#addr_tambon',
          zip: '#addr_zip',
          hiddenFull: '#addr_full_hidden',
          initial: <?= json_encode([
              'province' => (string)($form_old['addr_province'] ?? ''),
              'amphoe' => (string)($form_old['addr_amphoe'] ?? ''),
              'tambon' => $iniTambon,
              'zip' => $iniZip,
          ], JSON_UNESCAPED_UNICODE) ?>
        });
      }
      var acc = document.getElementById('foundation_reg_bank_account');
      if (acc) {
        acc.addEventListener('input', function () {
          acc.value = acc.value.replace(/\D/g, '').slice(0, 10);
        });
      }
      var regForm = document.getElementById('foundationRegisterForm');
      var regBtn = document.getElementById('foundationRegisterSubmit');
      if (regForm && regBtn) {
        regForm.addEventListener('submit', function () {
          ['addr_zip', 'addr_tambon', 'addr_amphoe', 'addr_province'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
              el.dispatchEvent(new Event('change', { bubbles: true }));
            }
          });
          regBtn.textContent = 'กำลังบันทึก…';
          regBtn.setAttribute('aria-busy', 'true');
        });
      }
    });
    </script>
<?php endif; ?>

</body>

</html>