<?php
declare(strict_types=1);

// auth/csrf_refresh.php — คืน CSRF token ล่าสุด (ใช้รีเฟรชก่อนส่งฟอร์มสมัคร/ล็อกอิน)
require_once __DIR__ . '/../includes/env_loader.php';
drawdream_load_env_file(__DIR__ . '/../.env');
require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/csrf.php';

drawdream_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$token = drawdream_auth_csrf_token(false);
$_SESSION['drawdream_auth_keepalive'] = time();

echo json_encode([
    'ok' => true,
    'csrf' => $token,
], JSON_UNESCAPED_UNICODE);
