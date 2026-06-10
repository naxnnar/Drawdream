<?php
/**
 * โหมดมูลนิธิดูหน้าเว็บแบบผู้บริจาค (preview) — สลับ session role ชั่วคราว
 */
declare(strict_types=1);

require_once __DIR__ . '/qr_payment_abandon.php';

/** path สัมพัทธ์จากไฟล์ที่กำลังรัน (เช่น payment/foo.php → ../) */
function drawdream_nav_base_from_script(): string
{
    $root = str_replace('\\', '/', dirname(__DIR__));
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__));
    if ($scriptDir === $root) {
        return '';
    }
    $rel = substr($scriptDir, strlen($root));
    $rel = trim($rel, '/');
    if ($rel === '') {
        return '';
    }
    $depth = substr_count($rel, '/') + 1;

    return str_repeat('../', $depth);
}

/** หน้าปัจจุบัน (relative ใต้รากโปรเจกต์) สำหรับพารามิเตอร์ return */
function drawdream_foundation_preview_current_return_path(): string
{
    $script = ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? 'homepage.php')), '/');
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') {
        parse_str($qs, $params);
        unset($params['preview_mode'], $params['return']);
        $qs = http_build_query($params);
        if ($qs !== '') {
            $script .= '?' . $qs;
        }
    }

    return $script;
}

function drawdream_foundation_preview_redirect(string $url): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    $loc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $json = json_encode($url, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '"/foundation.php"';
    }
    echo '<meta http-equiv="refresh" content="0;url=' . $loc . '"><script>location.replace(' . $json . ');</script>';
    exit;
}

/**
 * ประมวลผล ?preview_mode=donor|exit แล้ว redirect (เรียกจาก preview_mode.php หรือ navbar)
 */
function drawdream_foundation_preview_process_request(): void
{
    if (!isset($_GET['preview_mode'])) {
        return;
    }

    $mode = (string)$_GET['preview_mode'];
    $navBase = drawdream_nav_base_from_script();

    if ($mode === 'donor') {
        $effectiveRole = $_SESSION['real_role'] ?? $_SESSION['role'] ?? '';
        if ($effectiveRole === 'foundation') {
            $_SESSION['real_role'] = 'foundation';
            $_SESSION['role'] = 'donor';
        }
        $fallback = $navBase . 'homepage.php';
        $returnRaw = trim((string)($_GET['return'] ?? ''));
        $target = $returnRaw !== ''
            ? drawdream_safe_payment_return_url($returnRaw, $fallback)
            : drawdream_safe_payment_return_url(drawdream_foundation_preview_current_return_path(), $fallback);
        drawdream_foundation_preview_redirect($target);
    }

    if ($mode === 'exit') {
        if (isset($_SESSION['real_role'])) {
            $_SESSION['role'] = $_SESSION['real_role'];
            unset($_SESSION['real_role']);
        }
        drawdream_foundation_preview_redirect($navBase . 'foundation.php');
    }

    drawdream_foundation_preview_redirect($navBase . 'foundation.php');
}

function drawdream_foundation_preview_exit_href(string $navBase): string
{
    return $navBase . 'preview_mode.php?preview_mode=exit';
}

function drawdream_foundation_preview_enter_href(string $navBase): string
{
    $return = drawdream_foundation_preview_current_return_path();

    return $navBase . 'preview_mode.php?preview_mode=donor&return=' . rawurlencode($return);
}
