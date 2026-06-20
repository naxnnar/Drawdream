<?php
declare(strict_types=1);

// สรุปสั้น: ไฟล์นี้เริ่มขั้นตอนล็อกอินด้วย Google OAuth
require_once __DIR__ . '/../includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/../.env');
require_once __DIR__ . '/../includes/session_init.php';
drawdream_session_start();

require_once __DIR__ . '/../includes/google_oauth.php';
require_once __DIR__ . '/../includes/return_to.php';

drawdream_return_to_capture_from_request();

if (!drawdream_google_oauth_is_ready()) {
    header('Location: ../login.php?page=login&error=' . urlencode('ยังไม่ได้ตั้งค่า Google Login ในระบบ'));
    exit();
}

try {
    $state = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    $state = sha1((string)microtime(true) . ':' . mt_rand());
}

$_SESSION['google_oauth_state'] = $state;

$url = drawdream_google_oauth_build_auth_url($state);
header('Location: ' . $url);
exit();
