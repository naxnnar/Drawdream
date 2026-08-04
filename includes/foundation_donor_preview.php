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

/** @return list<string> */
function drawdream_foundation_preview_donor_visible_paths(): array
{
    return [
        'homepage.php',
        'foundation.php',
        'foundation_public_profile.php',
        'children_.php',
        'children_donate.php',
        'project.php',
        'project_result.php',
        'needlist_result.php',
        'about.php',
        'about_support.php',
        'profile.php',
        'donor_update_profile.php',
        'update_profile.php',
        'notifications.php',
        'notifications_feed.php',
        'mark_notif_read.php',
        'donation_receipt.php',
        'welcome.php',
        'login.php',
        'logout.php',
        'preview_mode.php',
        'health.php',
        'detail_alin.php',
    ];
}

function drawdream_foundation_preview_normalize_path(string $relativePath): string
{
    return strtolower(ltrim(str_replace('\\', '/', strtok($relativePath, '?') ?: $relativePath), '/'));
}

function drawdream_foundation_preview_path_is_donor_visible(string $relativePath): bool
{
    $normalized = drawdream_foundation_preview_normalize_path($relativePath);
    if ($normalized === '') {
        return true;
    }
    if (str_starts_with($normalized, 'payment/')) {
        return true;
    }
    if (str_starts_with($normalized, 'auth/')) {
        return true;
    }
    if (in_array($normalized, drawdream_foundation_preview_donor_visible_paths(), true)) {
        return true;
    }
    if (str_starts_with($normalized, 'foundation_')) {
        return false;
    }
    if (str_starts_with($normalized, 'admin_')) {
        return false;
    }
    if (str_starts_with($normalized, 'tools/')) {
        return false;
    }

    return false;
}

function drawdream_foundation_preview_qualify_url(string $relative, string $navBase): string
{
    if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $relative) !== 0) {
        return $relative;
    }
    if (str_starts_with($relative, '../')) {
        return $relative;
    }

    return $navBase . ltrim($relative, '/');
}

/** แมปหน้าจัดการมูลนิธิ → หน้าสาธารณะที่ผู้บริจาคเห็น */
function drawdream_foundation_preview_public_equivalent_for_path(string $path): string
{
    $pathOnly = drawdream_foundation_preview_normalize_path($path);
    $query = [];
    $qPos = strpos($path, '?');
    if ($qPos !== false) {
        parse_str(substr($path, $qPos + 1), $query);
    }

    switch ($pathOnly) {
        case 'foundation_post_needlist_result.php':
            $fid = (int)($query['fid'] ?? 0);
            return $fid > 0 ? ('needlist_result.php?fid=' . $fid) : 'foundation.php';
        case 'foundation_post_update.php':
            $pid = (int)($query['project_id'] ?? $query['id'] ?? 0);
            return $pid > 0 ? ('project_result.php?id=' . $pid) : 'project.php';
        case 'foundation_need_view.php':
            return 'foundation.php';
        case 'foundation_project_view.php':
            return 'project.php';
        case 'foundation_child_outcome.php':
            $cid = (int)($query['id'] ?? 0);
            return $cid > 0 ? ('children_donate.php?id=' . $cid) : 'children_.php';
        case 'foundation_public_profile.php':
            return ltrim(str_replace('\\', '/', $path), '/');
        default:
            if (str_starts_with($pathOnly, 'foundation_')) {
                return 'foundation.php';
            }
            if (str_starts_with($pathOnly, 'admin_')) {
                return 'homepage.php';
            }

            return 'homepage.php';
    }
}

function drawdream_foundation_preview_donor_safe_url(string $path, string $fallback): string
{
    $safe = drawdream_safe_payment_return_url($path, '');
    if ($safe === '') {
        return $fallback;
    }
    if (drawdream_foundation_preview_path_is_donor_visible($safe)) {
        return $safe;
    }

    return drawdream_foundation_preview_public_equivalent_for_path($safe);
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
        $json = '"/homepage.php"';
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
        $currentPath = $returnRaw !== '' ? $returnRaw : drawdream_foundation_preview_current_return_path();
        $target = drawdream_foundation_preview_donor_safe_url($currentPath, $fallback);
        drawdream_foundation_preview_redirect(drawdream_foundation_preview_qualify_url($target, $navBase));
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

function drawdream_foundation_in_donor_preview(): bool
{
    return isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'foundation';
}

/** หลัง reconcile DB — คง role=donor ถ้ายังอยู่ในโหมด preview (รวมหน้า DRAWDREAM_DB_LIGHT) */
function drawdream_foundation_preview_persist_session_role(): void
{
    if (drawdream_foundation_in_donor_preview()) {
        $_SESSION['role'] = 'donor';
    }
}

/** บังคับให้โหมด preview เห็นเฉพาะหน้าที่ผู้บริจาคเข้าถึงได้ */
function drawdream_foundation_preview_enforce_donor_only(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (!drawdream_foundation_in_donor_preview()) {
        return;
    }
    $current = drawdream_foundation_preview_current_return_path();
    if (drawdream_foundation_preview_path_is_donor_visible($current)) {
        return;
    }
    $navBase = drawdream_nav_base_from_script();
    $target = drawdream_foundation_preview_public_equivalent_for_path($current);
    drawdream_foundation_preview_redirect(drawdream_foundation_preview_qualify_url($target, $navBase));
}

/** หน้าจัดการมูลนิธิ — บล็อกโหมดดูตัวอย่างผู้บริจาค */
function drawdream_foundation_require_management_access(): void
{
    if (drawdream_foundation_in_donor_preview()) {
        $navBase = drawdream_nav_base_from_script();
        $current = drawdream_foundation_preview_current_return_path();
        $target = drawdream_foundation_preview_public_equivalent_for_path($current);
        drawdream_foundation_preview_redirect(drawdream_foundation_preview_qualify_url($target, $navBase));
    }

    require_once __DIR__ . '/return_to.php';

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
        header('Location: ' . drawdream_login_url('login', null, drawdream_return_to_current_request()));
        exit;
    }
}
