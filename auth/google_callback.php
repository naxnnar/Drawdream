<?php
declare(strict_types=1);

// สรุปสั้น: ไฟล์นี้รับผลลัพธ์การล็อกอิน Google และจัดการสถานะผู้ใช้
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/google_oauth.php';
require_once __DIR__ . '/../includes/return_to.php';
require_once __DIR__ . '/../includes/welcome_session.php';
require_once __DIR__ . '/../includes/utf8_helpers.php';

$redirectLogin = static function (string $message): void {
    header('Location: ' . drawdream_login_url('login', $message, drawdream_return_to_get()));
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
    $redirectLogin(drawdream_google_oauth_format_token_error($tokenRes));
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

$email = drawdream_normalize_email((string)($userInfoRes['payload']['email'] ?? ''));
$emailVerified = (bool)($userInfoRes['payload']['email_verified'] ?? false);

if ($email === '' || !$emailVerified) {
    $redirectLogin('บัญชี Google นี้ยังไม่ยืนยันอีเมล');
}

$stmt = $conn->prepare('SELECT user_id, email, role FROM `user` WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isNewGoogleDonor = false;

if (!$user) {
    $givenName = trim((string)($userInfoRes['payload']['given_name'] ?? ''));
    $familyName = trim((string)($userInfoRes['payload']['family_name'] ?? ''));
    $displayName = trim((string)($userInfoRes['payload']['name'] ?? ''));
    if ($givenName === '' && $displayName !== '') {
        $givenName = $displayName;
    }
    if ($givenName === '') {
        $givenName = 'ผู้บริจาค';
    }
    if ($familyName === '') {
        $familyName = 'Google';
    }

    try {
        $randomPassword = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        $randomPassword = sha1($email . ':' . microtime(true));
    }
    $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);

    $stmtIns = $conn->prepare("INSERT INTO `user` (email, password, role) VALUES (?, ?, 'donor')");
    $stmtIns->bind_param('ss', $email, $hashedPassword);
    if (!$stmtIns->execute()) {
        $redirectLogin('ไม่สามารถสร้างบัญชีผู้บริจาคจาก Google ได้ กรุณาลองใหม่');
    }
    $newUserId = (int)$conn->insert_id;
    $stmtIns->close();

    $stmtDonor = $conn->prepare('INSERT INTO donor (user_id, first_name, last_name) VALUES (?, ?, ?)');
    $stmtDonor->bind_param('iss', $newUserId, $givenName, $familyName);
    if (!$stmtDonor->execute()) {
        $conn->query('DELETE FROM `user` WHERE user_id = ' . $newUserId);
        $redirectLogin('ไม่สามารถสร้างโปรไฟล์ผู้บริจาคจาก Google ได้ กรุณาลองใหม่');
    }
    $stmtDonor->close();

    $user = [
        'user_id' => $newUserId,
        'email' => $email,
        'role' => 'donor',
    ];
    $isNewGoogleDonor = true;
}

$userRole = (string)($user['role'] ?? '');
if ($userRole !== 'donor' && $userRole !== 'foundation') {
    $redirectLogin('Google Login รองรับเฉพาะบัญชีผู้บริจาคและมูลนิธิ');
}

drawdream_session_regenerate_after_login();
drawdream_log_user_login($conn, (int)$user['user_id'], $isNewGoogleDonor ? 'google_register' : 'google');
$_SESSION['user_id'] = (int)$user['user_id'];
$_SESSION['email'] = (string)$user['email'];
$_SESSION['role'] = $userRole;

if ($userRole === 'foundation') {
    $stmt2 = $conn->prepare('SELECT account_verified, foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
    $userId = (int)$user['user_id'];
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $fp = $stmt2->get_result()->fetch_assoc();
    $_SESSION['account_verified'] = (int)($fp['account_verified'] ?? 0);
    $_SESSION['foundation_id'] = (int)($fp['foundation_id'] ?? 0);
}

$returnDest = drawdream_return_to_consume_after_login($userRole);
if ($returnDest !== null) {
    header('Location: ../' . $returnDest);
    exit();
}

header('Location: ../' . drawdream_post_login_redirect_url($userRole));
exit();
