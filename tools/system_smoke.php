<?php
declare(strict_types=1);
/**
 * Smoke test หลัง deploy — ตรวจหน้าหลัก + health + schema
 * Usage: php tools/system_smoke.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');

$fail = 0;
$pass = 0;

function smoke_skip(string $label): void
{
    echo "[SKIP] {$label}\n";
}

function smoke_ok(bool $cond, string $label): void
{
    global $fail, $pass;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $fail++;
}

passthru('php ' . escapeshellarg($root . '/tools/audit_schema.php'), $c1);
if ($c1 !== 0) {
    $fail++;
} else {
    $pass++;
}

passthru('php ' . escapeshellarg($root . '/tools/e2e/static_suite.php'), $c2);
if ($c2 !== 0) {
    $fail++;
} else {
    $pass++;
}

$base = rtrim(trim((string)(getenv('DRAWDREAM_SMOKE_BASE_URL') ?: getenv('APP_URL') ?: '')), '/');
if ($base === '') {
    smoke_skip('HTTP smoke (set DRAWDREAM_SMOKE_BASE_URL or APP_URL)');
} else {
    $paths = ['health.php', 'login.php', 'homepage.php', 'foundation.php', 'donor_update_profile.php'];
    foreach ($paths as $path) {
        $url = $base . '/' . ltrim($path, '/');
        $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $headers = @get_headers($url);
            if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
                $code = (int)$m[1];
            }
        }
        smoke_ok($code >= 200 && $code < 500, "HTTP {$code} {$path}");
    }
}

echo "---- smoke PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
