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
    // คุกกี้ session อยู่ได้นานขึ้น — ลดปัญหาฟอร์มสมัคร/ล็อกอินค้างแล้วส่งไม่ผ่าน (CSRF ไม่ตรง)
    $cookieLifetime = (int)(getenv('DRAWDREAM_SESSION_LIFETIME') ?: 172800); // 48 ชม.
    if ($cookieLifetime < 3600) {
        $cookieLifetime = 3600;
    }
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', (string)$cookieLifetime);
    }
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($cookieLifetime, '/; samesite=Lax', '', $secure, true);
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

/**
 * ล้าง session ถ้า user_id ใน session ไม่มีใน DB (เช่น หลังลบ/renumber user)
 * และ sync email/role จาก DB ให้ตรงกับ user_id ปัจจุบัน
 */
function drawdream_session_reconcile_user(mysqli $conn): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $st = $conn->prepare('SELECT user_id, email, role FROM `user` WHERE user_id = ? LIMIT 1');
    if (!$st) {
        return;
    }
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    if (!$row) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }
        session_destroy();
        return;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];
    $_SESSION['email'] = (string)$row['email'];
    $dbRole = (string)$row['role'];

    // โหมดมุมมองผู้บริจาค: คง role=donor จนกว่าจะกดออก (real_role=foundation)
    if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'foundation') {
        if ($dbRole === 'foundation') {
            $_SESSION['role'] = 'donor';
        } else {
            unset($_SESSION['real_role']);
            $_SESSION['role'] = $dbRole;
        }
    } else {
        $_SESSION['role'] = $dbRole;
    }
}
