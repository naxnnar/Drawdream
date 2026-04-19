<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/google_oauth.php';

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
