<?php
declare(strict_types=1);

/**
 * return_to — กลับหน้าเดิมหลัง login (flow บริจาค)
 * เก็บ path ภายในเว็บเท่านั้น ไม่รองรับ URL ภายนอก
 */

/** @var list<string> */
const DRAWDREAM_RETURN_TO_ALLOWED = [
    'children_donate.php',
    'children_.php',
    'payment/child_donate.php',
    'payment/child_subscription_create.php',
    'payment/foundation_donate.php',
    'payment/payment_project.php',
    'foundation_donate_info.php',
    'foundation.php',
    'foundation_dashboard.php',
    'foundation_children_directory.php',
    'foundation_projects_directory.php',
    'foundation_needlist_directory.php',
    'foundation_add_children.php',
    'foundation_add_project.php',
    'foundation_add_need.php',
    'foundation_need_view.php',
    'foundation_need_wizard.php',
    'profile.php',
    'update_profile.php',
    'project.php',
];

function drawdream_return_to_normalize(string $raw): string
{
    $path = trim($raw);
    if ($path === '') {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    return $path;
}

function drawdream_return_to_is_allowed(string $path): bool
{
    $path = drawdream_return_to_normalize($path);
    if ($path === '') {
        return false;
    }
    $lower = strtolower($path);
    if (
        str_contains($path, '..')
        || str_contains($lower, '://')
        || str_starts_with($lower, '//')
        || str_contains($lower, 'javascript:')
        || str_starts_with($lower, 'login.php')
        || str_starts_with($lower, 'logout.php')
        || str_starts_with($lower, 'welcome.php')
        || str_starts_with($lower, 'auth/')
    ) {
        return false;
    }

    $base = $path;
    if (($qPos = strpos($base, '?')) !== false) {
        $base = substr($base, 0, $qPos);
    }
    if (($hPos = strpos($base, '#')) !== false) {
        $base = substr($base, 0, $hPos);
    }
    foreach (DRAWDREAM_RETURN_TO_ALLOWED as $allowed) {
        if ($base === $allowed) {
            return true;
        }
    }

    return false;
}

function drawdream_return_to_store(?string $path): void
{
    $path = drawdream_return_to_normalize((string)$path);
    if (!drawdream_return_to_is_allowed($path)) {
        return;
    }
    $_SESSION['login_return_to'] = $path;
}

function drawdream_return_to_get(): ?string
{
    $path = drawdream_return_to_normalize((string)($_SESSION['login_return_to'] ?? ''));
    if ($path === '' || !drawdream_return_to_is_allowed($path)) {
        return null;
    }

    return $path;
}

function drawdream_return_to_clear(): void
{
    unset($_SESSION['login_return_to']);
}

/**
 * อ่าน return_to จาก GET/POST แล้วเก็บใน session (ถ้าถูกต้อง)
 */
function drawdream_return_to_capture_from_request(): void
{
    $raw = $_POST['return_to'] ?? $_GET['return_to'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return;
    }
    drawdream_return_to_store($raw);
}

/**
 * path ปัจจุบันสำหรับส่งต่อเป็น return_to (relative ใต้ root เว็บ)
 */
function drawdream_return_to_current_request(): string
{
    $script = ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $qs = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
    if ($script === '') {
        return '';
    }

    return $qs !== '' ? $script . '?' . $qs : $script;
}

/**
 * @param array<string, scalar|null> $params
 */
function drawdream_return_to_path(string $script, array $params = []): string
{
    $script = drawdream_return_to_normalize($script);
    if ($script === '') {
        return '';
    }
    $pairs = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $pairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
    }

    return $pairs === [] ? $script : $script . '?' . implode('&', $pairs);
}

function drawdream_login_page_prefix(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/payment/')) {
        return '../';
    }
    if (str_contains($script, '/auth/')) {
        return '../';
    }

    return '';
}

function drawdream_login_url(string $page = 'login', ?string $error = null, ?string $returnTo = null): string
{
    $params = ['page' => $page];
    if ($error !== null && trim($error) !== '') {
        $params['error'] = $error;
    }
    $normalizedReturn = drawdream_return_to_normalize((string)$returnTo);
    if ($normalizedReturn !== '' && drawdream_return_to_is_allowed($normalizedReturn)) {
        $params['return_to'] = $normalizedReturn;
    }

    return drawdream_login_page_prefix() . 'login.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * หลัง login สำเร็จ — คืน path ปลายทางหรือ null ถ้าไม่มี/ไม่ใช้ได้
 */
function drawdream_return_to_consume_after_login(string $role): ?string
{
    $path = drawdream_return_to_get();
    if ($path === null) {
        return null;
    }
    if (!in_array($role, ['donor', 'admin', 'foundation'], true)) {
        drawdream_return_to_clear();

        return null;
    }
    drawdream_return_to_clear();

    return $path;
}
