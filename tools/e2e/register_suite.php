<?php
declare(strict_types=1);
/**
 * E2E สมัครมูลนิธิ/ผู้บริจาคผ่าน HTTP — ห้ามรันบน production (APP_ENV=production)
 *
 * ต้องตั้ง: DRAWDREAM_E2E_ALLOW_HTTP=1, DRAWDREAM_E2E_BASE_URL=http://localhost:8080
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

require __DIR__ . '/bootstrap.php';

$e2e_fail = 0;
$e2e_pass = 0;

if (!e2e_http_allowed()) {
    e2e_skip('HTTP register suites (set DRAWDREAM_E2E_ALLOW_HTTP=1 on non-production)');
    exit(0);
}

$base = rtrim((string)(getenv('DRAWDREAM_E2E_BASE_URL') ?: ''), '/');
if ($base === '') {
    e2e_ok(false, 'DRAWDREAM_E2E_BASE_URL is set');
    exit(1);
}

/**
 * @return array{cookies: list<string>, body: string, code: int}
 */
function e2e_http_post_form(string $url, array $fields, array $cookies = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['cookies' => $cookies, 'body' => '', 'code' => 0];
    }
    $cookieJar = tempnam(sys_get_temp_dir(), 'dde2e');
    $headers = ['Cookie: ' . implode('; ', $cookies)];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($cookieJar);

    return ['cookies' => $cookies, 'body' => $raw, 'code' => $code];
}

function e2e_fetch_csrf(string $html): string
{
    if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }

    return '';
}

// ดึง CSRF จากหน้าสมัครมูลนิธิ
$registerPage = @file_get_contents($base . '/login.php?page=register&step=form&role=foundation');
$csrf = e2e_fetch_csrf((string)$registerPage);
e2e_ok($csrf !== '', 'fetch CSRF from foundation register form');

$email = 'e2e+foundation+' . bin2hex(random_bytes(4)) . '@drawdream.test';
$password = 'E2eTest!234567';

$post = e2e_http_post_form($base . '/login.php?page=register&step=form&role=foundation', [
    'register' => '1',
    'role' => 'foundation',
    'csrf' => $csrf,
    'foundation_name' => 'E2E Test Foundation',
    'registration_number' => '1234567890123',
    'email' => $email,
    'phone' => '0812345678',
    'addr_house_no' => '1',
    'addr_soi' => '',
    'addr_road' => '',
    'addr_province' => 'กรุงเทพมหานคร',
    'addr_amphoe' => 'เขตพระนคร',
    'addr_tambon' => 'พระบรมมหาราชวัง',
    'addr_zip' => '10200',
    'addr_full_hidden' => 'เลขที่ 1 ต.พระบรมมหาราชวัง อ.เขตพระนคร จ.กรุงเทพมหานคร 10200',
    'password' => $password,
    'confirm_password' => $password,
]);

$redirectOk = ($post['code'] === 302 || $post['code'] === 303)
    && str_contains($post['body'], 'foundation.php');
e2e_ok($redirectOk, 'foundation register redirects to foundation.php');

echo "---- http_register PASS={$e2e_pass} FAIL={$e2e_fail}\n";
exit($e2e_fail > 0 ? 1 : 0);
