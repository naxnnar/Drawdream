<?php
/**
 * CSRF protection — token หนึ่งตัวต่อ session (ใช้ร่วมทุกฟอร์ม POST)
 */
declare(strict_types=1);

function drawdream_csrf_ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!function_exists('drawdream_session_start')) {
            require_once __DIR__ . '/session_init.php';
        }
        drawdream_session_start();
    }
}

function drawdream_csrf_token(): string
{
    drawdream_csrf_ensure_session();
    if (empty($_SESSION['drawdream_csrf']) || !is_string($_SESSION['drawdream_csrf'])) {
        $_SESSION['drawdream_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['drawdream_csrf'];
}

function drawdream_csrf_rotate(): void
{
    drawdream_csrf_ensure_session();
    $_SESSION['drawdream_csrf'] = bin2hex(random_bytes(32));
}

/** HTML hidden input สำหรับแทรกในฟอร์ม */
function drawdream_csrf_field(): string
{
    $t = drawdream_csrf_token();

    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

function drawdream_csrf_verify(?string $submitted = null): bool
{
    drawdream_csrf_ensure_session();
    $submitted = $submitted ?? (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['drawdream_csrf'] ?? '');

    return $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);
}

/**
 * เรียกตอนต้น handler POST — 403 หรือ redirect ถ้า token ไม่ตรง
 */
function drawdream_csrf_require_valid(?string $redirectOnFail = null, ?string $message = null): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (drawdream_csrf_verify()) {
        return;
    }
    $msg = $message ?? 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่';
    if ($redirectOnFail !== null && $redirectOnFail !== '') {
        drawdream_csrf_fail_redirect($redirectOnFail, $msg);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function drawdream_csrf_fail_redirect(string $url, ?string $message = null): never
{
    $msg = $message ?? 'เซสชันไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่';
    $sep = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $sep . 'msg=' . rawurlencode($msg) . '&msg_icon=warning');
    exit;
}
