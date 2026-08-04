<?php
declare(strict_types=1);

require_once __DIR__ . '/return_to.php';

/** @return array<string, string> */
function drawdream_foundation_need_flash_messages(): array
{
    return [
        'created' => 'เสนอรายการสิ่งของสำเร็จ รอแอดมินอนุมัติ',
        'updated' => 'อัปเดตรายการสิ่งของแล้ว',
        'resubmitted' => 'แก้ไขรายการแล้ว ส่งให้แอดมินตรวจอนุมัติใหม่',
    ];
}

function drawdream_foundation_need_flash_set(string $key): void
{
    if (!isset(drawdream_foundation_need_flash_messages()[$key])) {
        return;
    }
    $_SESSION['foundation_need_flash'] = $key;
}

function drawdream_foundation_need_flash_consume(): ?string
{
    $key = $_SESSION['foundation_need_flash'] ?? null;
    unset($_SESSION['foundation_need_flash']);
    if (!is_string($key) || !isset(drawdream_foundation_need_flash_messages()[$key])) {
        return null;
    }

    return $key;
}

function drawdream_foundation_need_flash_message(?string $key): string
{
    if ($key === null) {
        return '';
    }

    return drawdream_foundation_need_flash_messages()[$key] ?? '';
}

function drawdream_foundation_need_return_to_resolve(bool $fromPost = false): string
{
    $default = 'foundation.php#my-needlist-section';
    $sources = $fromPost
        ? [$_POST['return_to'] ?? '', $_GET['return_to'] ?? '']
        : [$_GET['return_to'] ?? '', $_POST['return_to'] ?? ''];

    foreach ($sources as $raw) {
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }
        if (drawdream_return_to_is_allowed($raw)) {
            return drawdream_return_to_normalize($raw);
        }
    }

    if (!$fromPost) {
        $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($ref !== '') {
            $refPath = parse_url($ref, PHP_URL_PATH);
            $refQuery = parse_url($ref, PHP_URL_QUERY);
            $refHash = parse_url($ref, PHP_URL_FRAGMENT);
            if (is_string($refPath) && $refPath !== '') {
                $rel = ltrim(str_replace('\\', '/', $refPath), '/');
                $basename = basename($rel);
                if ($basename !== '' && $basename !== 'foundation_add_need.php') {
                    $candidate = $basename;
                    if (is_string($refQuery) && $refQuery !== '') {
                        $candidate .= '?' . $refQuery;
                    }
                    if (is_string($refHash) && $refHash !== '') {
                        $candidate .= '#' . $refHash;
                    }
                    if (drawdream_return_to_is_allowed($candidate)) {
                        return drawdream_return_to_normalize($candidate);
                    }
                }
            }
        }
    }

    return $default;
}

function drawdream_foundation_need_return_to_param(string $returnTo): string
{
    $path = drawdream_return_to_normalize($returnTo);
    if ($path === '' || !drawdream_return_to_is_allowed($path)) {
        return '';
    }

    return 'return_to=' . rawurlencode($path);
}

function drawdream_foundation_need_save_redirect(string $flashKey, string $returnTo): never
{
    drawdream_foundation_need_flash_set($flashKey);
    $loc = drawdream_return_to_normalize($returnTo);
    if ($loc === '' || !drawdream_return_to_is_allowed($loc)) {
        $loc = drawdream_foundation_need_return_to_resolve(true);
    }
    header('Location: ' . $loc, true, 303);
    exit;
}

function drawdream_foundation_need_flash_render_html(): string
{
    $key = drawdream_foundation_need_flash_consume();
    if ($key === null) {
        return '';
    }
    $msg = drawdream_foundation_need_flash_message($key);
    if ($msg === '') {
        return '';
    }

    return '<div class="alert alert-success needlist-flash" role="status" data-need-save-flash="1">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . '</div>';
}
