<?php
declare(strict_types=1);

define('DRAWDREAM_DB_LIGHT', true);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/password_reset.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$account = $token !== '' ? drawdream_password_reset_lookup($conn, $token) : null;

if ($token === '' || $account === null) {
    $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอลิงก์ใหม่';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account !== null) {
    if (!drawdream_auth_csrf_verify()) {
        $error = 'ฟอร์มค้างนานเกินไป กรุณากดส่งอีกครั้ง';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!drawdream_password_meets_policy($password)) {
            $error = drawdream_password_policy_message();
        } elseif ($password !== $confirm) {
            $error = 'รหัสผ่านไม่ตรงกัน';
        } elseif (drawdream_password_reset_apply($conn, $token, $password)) {
            $success = 'ตั้งรหัสผ่านใหม่สำเร็จแล้ว กำลังไปหน้าเข้าสู่ระบบ...';
            header('Refresh: 2; url=../login.php?page=login');
        } else {
            $error = 'ไม่สามารถตั้งรหัสผ่านใหม่ได้ กรุณาขอลิงก์ใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ตั้งรหัสผ่านใหม่ | DrawDream</title>
    <link rel="stylesheet" href="../css/auth.css?v=5">
</head>
<body>
    <div class="auth-container">
        <img src="../img/logopic.png" alt="DrawDream" class="logo">
        <h2>ตั้งรหัสผ่านใหม่</h2>
        <?php if ($account !== null && $success === ''): ?>
            <p class="subtitle">บัญชี <?= htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($account !== null && $success === ''): ?>
        <form method="POST" autocomplete="on">
            <?= drawdream_auth_csrf_field() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <div style="position:relative;">
                    <input type="password" name="password" id="reset-password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder(), ENT_QUOTES, 'UTF-8') ?>" required autocomplete="new-password" <?= drawdream_password_input_attrs() ?> class="password-input">
                    <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                </div>
            </div>
            <div class="form-group">
                <div style="position:relative;">
                    <input type="password" name="confirm_password" placeholder="<?= htmlspecialchars(drawdream_password_placeholder('ยืนยันรหัสผ่าน'), ENT_QUOTES, 'UTF-8') ?>" required autocomplete="new-password" <?= drawdream_password_input_attrs() ?> class="password-input">
                    <button type="button" class="toggle-password" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:18px;">👁</button>
                </div>
            </div>
            <button type="submit" class="btn-submit">บันทึกรหัสผ่านใหม่</button>
        </form>
        <?php endif; ?>

        <a href="<?= $account === null ? 'forgot_password.php' : '../login.php?page=login' ?>" class="back-link">← <?= $account === null ? 'ขอลิงก์ใหม่' : 'กลับไปเข้าสู่ระบบ' ?></a>
    </div>
    <script>
    document.querySelectorAll('.toggle-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = btn.parentElement.querySelector('.password-input');
            input.type = input.type === 'password' ? 'text' : 'password';
        });
    });
    </script>
    <script src="../js/auth-csrf-keepalive.js?v=2" data-csrf-url="csrf_refresh.php"></script>
</body>
</html>
