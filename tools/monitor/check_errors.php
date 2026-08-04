<?php
declare(strict_types=1);
/**
 * สแกน error log แล้วแจ้งเตือน (Fatal / timeout / [drawdream_*])
 *
 * Cron บน server (ทุก 5 นาที):
 *   0,5,10,... * * * * php /var/www/drawdream/tools/monitor/check_errors.php
 *
 * Env:
 *   DRAWDREAM_ALERT_LINE_TOKEN หรือ DRAWDREAM_ALERT_WEBHOOK_URL
 *   DRAWDREAM_NGINX_ERROR_LOG=/var/log/nginx/error.log
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');
require_once $root . '/includes/drawdream_ops_alert.php';

$logFile = trim((string)(getenv('DRAWDREAM_NGINX_ERROR_LOG') ?: '/var/log/nginx/error.log'));
$stateFile = $root . '/config/monitor_error_offset.txt';
$dedupeFile = $root . '/config/monitor_error_dedupe.json';
$cooldownSec = max(60, (int)(getenv('DRAWDREAM_ALERT_COOLDOWN_SEC') ?: 900));

$patterns = [
    '/PHP Fatal error/i',
    '/upstream timed out/i',
    '/\[drawdream_[^\]]+\]/i',
    '/MySQL server has gone away/i',
];

if (!is_file($logFile)) {
    echo "skip: log not found {$logFile}\n";
    exit(0);
}

$size = filesize($logFile);
$offset = 0;
if (is_file($stateFile)) {
    $offset = (int)trim((string)@file_get_contents($stateFile));
}
if ($offset > $size) {
    $offset = 0;
}

$fh = fopen($logFile, 'rb');
if (!$fh) {
    echo "fail: cannot open log\n";
    exit(1);
}
fseek($fh, $offset);
$newData = stream_get_contents($fh);
fclose($fh);
@file_put_contents($stateFile, (string)$size);

if ($newData === false || trim($newData) === '') {
    echo "ok: no new log lines\n";
    exit(0);
}

$hits = [];
foreach (explode("\n", $newData) as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    foreach ($patterns as $pat) {
        if (preg_match($pat, $line)) {
            $hits[] = $line;
            break;
        }
    }
}

if ($hits === []) {
    echo "ok: no alert patterns\n";
    exit(0);
}

$dedupe = [];
if (is_file($dedupeFile)) {
    $raw = json_decode((string)@file_get_contents($dedupeFile), true);
    if (is_array($raw)) {
        $dedupe = $raw;
    }
}
$now = time();
$toSend = [];
foreach ($hits as $line) {
    $key = substr(hash('sha256', $line), 0, 16);
    $last = (int)($dedupe[$key] ?? 0);
    if ($now - $last >= $cooldownSec) {
        $toSend[] = $line;
        $dedupe[$key] = $now;
    }
}

foreach ($dedupe as $k => $ts) {
    if ($now - (int)$ts > 86400 * 7) {
        unset($dedupe[$k]);
    }
}
@file_put_contents($dedupeFile, json_encode($dedupe, JSON_UNESCAPED_UNICODE));

if ($toSend === []) {
    echo "ok: deduped " . count($hits) . " hits\n";
    exit(0);
}

$host = trim((string)(getenv('APP_URL') ?: 'drawdream.org'));
$body = "[DrawDream alert]\n{$host}\n\n" . implode("\n", array_slice($toSend, 0, 5));
if (count($toSend) > 5) {
    $body .= "\n... +" . (count($toSend) - 5) . ' more';
}

$sent = drawdream_ops_alert_send($body);
echo ($sent ? 'alert sent' : 'alert skipped (no token/webhook)') . ': ' . count($toSend) . " lines\n";
exit(0);
