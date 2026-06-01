<?php
declare(strict_types=1);

/**
 * Session cookie hardening + safe start. Include before session_start(), or rely on db.php bootstrap.
 */
function drawdream_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $xfProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $xfProto === 'https';
}

function drawdream_is_production_env(): bool
{
    $env = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
    return $env === 'production' || $env === 'prod';
}

/** ปิดแสดง error บนหน้าเว็บเมื่อ APP_ENV=production|prod */
function drawdream_apply_production_error_display(): void
{
    if (!drawdream_is_production_env()) {
        return;
    }
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

function drawdream_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    drawdream_apply_production_error_display();

    $secure = drawdream_is_https_request();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }

    session_start();
}

/** เรียกหลังยืนยันตัวตนสำเร็จ (login / Google OAuth) เพื่อกัน session fixation */
function drawdream_session_regenerate_after_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    session_regenerate_id(true);
}
