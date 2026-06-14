<?php
declare(strict_types=1);

/** cache ข้อมูล navbar ใน session (ลด query ไป Aiven ทุกคลิก) */
function drawdream_navbar_cache_get(string $key, int $ttlSeconds = 60): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $bucket = $_SESSION['drawdream_navbar_cache'][$key] ?? null;
    if (!is_array($bucket)) {
        return null;
    }
    $at = (int)($bucket['at'] ?? 0);
    if ($at <= 0 || (time() - $at) > $ttlSeconds) {
        return null;
    }
    $data = $bucket['data'] ?? null;

    return is_array($data) ? $data : null;
}

function drawdream_navbar_cache_set(string $key, array $data): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (!isset($_SESSION['drawdream_navbar_cache']) || !is_array($_SESSION['drawdream_navbar_cache'])) {
        $_SESSION['drawdream_navbar_cache'] = [];
    }
    $_SESSION['drawdream_navbar_cache'][$key] = ['at' => time(), 'data' => $data];
}
