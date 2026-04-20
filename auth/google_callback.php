<?php
declare(strict_types=1);

// สรุปสั้น: ไฟล์นี้รับผลลัพธ์การล็อกอิน Google และจัดการสถานะผู้ใช้
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/google_oauth.php';

$redirectLogin = static function (string $message): void {
    header('Location: ../login.php?page=login&error=' . urlencode($message));
    exit();
};

if (!drawdream_google_oauth_is_ready()) {
    $redirectLogin('ยังไม่ได้ตั้งค่า Google Login ในระบบ');
}

$state = (string)($_GET['state'] ?? '');
$sessionState = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
    $redirectLogin('การยืนยันความปลอดภัยล้มเหลว กรุณาลองใหม่อีกครั้ง');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    $redirectLogin('ไม่พบรหัสยืนยันจาก Google');
}

$cfg = drawdream_google_oauth_config();
$tokenRes = drawdream_google_oauth_post('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $cfg['client_id'],
    'client_secret' => $cfg['client_secret'],
    'redirect_uri' => $cfg['redirect_uri'],
    'grant_type' => 'authorization_code',
]);

if (empty($tokenRes['ok'])) {
    $redirectLogin('ไม่สามารถเชื่อมต่อ Google ได้ กรุณาลองใหม่');
}

$accessToken = (string)($tokenRes['payload']['access_token'] ?? '');
if ($accessToken === '') {
    $redirectLogin('ไม่พบ access token จาก Google');
}

$userInfoRes = drawdream_google_oauth_get_json(
    'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . rawurlencode($accessToken)
);
if (empty($userInfoRes['ok'])) {
    $redirectLogin('ไม่สามารถอ่านข้อมูลผู้ใช้จาก Google ได้');
}

$email = trim((string)($userInfoRes['payload']['email'] ?? ''));
$emailVerified = (bool)($userInfoRes['payload']['email_verified'] ?? false);

if ($email === '' || !$emailVerified) {
    $redirectLogin('บัญชี Google นี้ยังไม่ยืนยันอีเมล');
}

$stmt = $conn->prepare('SELECT user_id, email, role FROM `user` WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $redirectLogin('ไม่พบบัญชีนี้ในระบบ กรุณาสมัครสมาชิกก่อน');
}

$userRole = (string)($user['role'] ?? '');
if ($userRole !== 'donor' && $userRole !== 'foundation') {
    $redirectLogin('Google Login รองรับเฉพาะบัญชีผู้บริจาคและมูลนิธิ');
}

$_SESSION['user_id'] = (int)$user['user_id'];
$_SESSION['email'] = (string)$user['email'];
$_SESSION['role'] = $userRole;

if ($userRole === 'foundation') {
    $stmt2 = $conn->prepare('SELECT account_verified FROM foundation_profile WHERE user_id = ? LIMIT 1');
    $userId = (int)$user['user_id'];
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $fp = $stmt2->get_result()->fetch_assoc();
    $_SESSION['account_verified'] = (int)($fp['account_verified'] ?? 0);
}

$_SESSION['show_welcome'] = true;

header('Location: ../welcome.php');
exit();
