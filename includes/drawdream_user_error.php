<?php
declare(strict_types=1);

/** บันทึกรายละเอียดทางเทคนิคใน log — ไม่แสดงผู้ใช้ */
function drawdream_user_error_log(string $detail): void
{
    if ($detail !== '') {
        error_log('[drawdream] ' . $detail);
    }
}

/** redirect พร้อมข้อความที่เป็นมิตร (?msg=) */
function drawdream_user_error_redirect(string $userMessage, string $target = 'foundation.php', string $logDetail = ''): never
{
    drawdream_user_error_log($logDetail !== '' ? $logDetail : $userMessage);
    $path = strtok($target, '?') ?: $target;
    $extra = '';
    if (str_contains($target, '?')) {
        $extra = substr($target, strlen($path) + 1);
    }
    $qs = 'msg=' . rawurlencode($userMessage);
    if ($extra !== '') {
        $qs = $extra . '&' . $qs;
    }
    $url = $path . '?' . $qs;
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
