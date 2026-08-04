<?php
declare(strict_types=1);

/**
 * แจ้งเตือน ops — LINE Notify หรือ webhook ทั่วไป (Discord/Telegram ฯลฯ)
 * ตั้งค่าใน .env: DRAWDREAM_ALERT_LINE_TOKEN หรือ DRAWDREAM_ALERT_WEBHOOK_URL
 */
function drawdream_ops_alert_send(string $message): bool
{
    $message = trim($message);
    if ($message === '') {
        return false;
    }

    $lineToken = trim((string)(getenv('DRAWDREAM_ALERT_LINE_TOKEN') ?: ''));
    if ($lineToken !== '') {
        return drawdream_ops_alert_line($lineToken, $message);
    }

    $webhook = trim((string)(getenv('DRAWDREAM_ALERT_WEBHOOK_URL') ?: ''));
    if ($webhook !== '') {
        return drawdream_ops_alert_webhook($webhook, $message);
    }

    return false;
}

function drawdream_ops_alert_line(string $token, string $message): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init('https://notify-api.line.me/api/notify');
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => http_build_query(['message' => $message]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function drawdream_ops_alert_webhook(string $url, string $message): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $payload = json_encode(['content' => $message, 'text' => $message], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}
