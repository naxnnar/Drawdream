<?php
declare(strict_types=1);

define('DRAWDREAM_DB_LIGHT', true);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/password_reset.php';
require_once __DIR__ . '/../includes/google_oauth.php';
require_once __DIR__ . '/../includes/utf8_helpers.php';

$error = '';
$success = '';
$googleLoginEnabled = drawdream_google_oauth_is_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!drawdream_auth_csrf_verify()) {
        $error = 'ฟอร์มค้างนานเกินไป กรุณากดส่งอีกครั้ง';
    } else {
        $email = drawdream_normalize_email((string)($_POST['email'] ?? ''));
        if ($email === '') {
            $error = 'กรุณากรอกอีเมล';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $resetResult = drawdream_password_reset_request_by_email($conn, $email);
            if ($resetResult === 'mail_failed') {
                $error = 'ไม่สามารถส่งอีเมลได้ในขณะนี้ กรุณาลองอีกครั้งภายหลัง หรือเข้าสู่ระบบด้วย Google';
                if ($googleLoginEnabled) {
                    $error .= ' (ปุ่มด้านล่าง)';
                }
            } else {
                $success = 'หากอีเมลนี้มีบัญชีในระบบ เราจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้ภายในไม่กี่นาที กรุณาตรวจสอบกล่องจดหมาย (และโฟลเดอร์สแปม)';
            }
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
    <title>ลืมรหัสผ่าน | DrawDream</title>
    <link rel="stylesheet" href="../css/auth.css?v=5">
</head>
<body>
    <div class="auth-container">
        <img src="../img/logopic.png" alt="DrawDream" class="logo">
        <h2>ลืมรหัสผ่าน</h2>
        <p class="subtitle">กรอกอีเมลที่ใช้สมัครบัญชี เราจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success === ''): ?>
        <form method="POST" autocomplete="on">
            <?= drawdream_auth_csrf_field() ?>
            <div class="form-group">
                <input type="email" name="email" id="forgot-email" placeholder="อีเมล" required autofocus autocomplete="username" value="<?= htmlspecialchars(trim((string)($_POST['email'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <button type="submit" class="btn-submit">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
        </form>
        <?php endif; ?>

        <?php if ($googleLoginEnabled): ?>
            <p class="auth-hint">สมัครหรือเคยเข้าด้วย Google? <a href="google_start.php">เข้าสู่ระบบด้วย Google</a></p>
        <?php endif; ?>

        <a href="../login.php?page=login" class="back-link">← กลับไปเข้าสู่ระบบ</a>
    </div>
    <script src="../js/auth-csrf-keepalive.js?v=2" data-csrf-url="csrf_refresh.php"></script>
</body>
</html>
