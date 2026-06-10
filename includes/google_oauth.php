<?php
declare(strict_types=1);
// สรุปสั้น: helper กลางสำหรับ Google Login (สร้าง URL, แลก token, ดึงข้อมูลผู้ใช้)
require_once __DIR__ . '/env_loader.php';
drawdream_load_env_file(__DIR__ . '/../.env');

/**
 * includes/google_oauth.php
 * ไฟล์รวม helper สำหรับ Google Login
 * ทำ 3 อย่างหลัก:
 * - สร้าง URL ไปหน้า Google
 * - แลก code เป็น token
 * - ดึงข้อมูลผู้ใช้จาก token
 *
 * ลำดับใช้งานแบบง่าย:
 * 1) drawdream_google_oauth_build_auth_url() สร้าง URL สำหรับ redirect ไป Google
 * 2) callback page เอา code มาแลก token ผ่าน drawdream_google_oauth_exchange_code()
 * 3) ใช้ access token ดึง profile ผ่าน drawdream_google_oauth_fetch_userinfo()
 */

/**
 * แยก hostname จาก HTTP_HOST (รองรับพอร์ต และ IPv6 [::1])
 */
function drawdream_google_oauth_host_only(string $httpHost): string
{
    $httpHost = strtolower(trim($httpHost));
    if ($httpHost === '') {
        return '';
    }
    if (str_starts_with($httpHost, '[')) {
        $end = strpos($httpHost, ']');
        return $end !== false ? substr($httpHost, 1, $end - 1) : $httpHost;
    }

    return (string)preg_replace('/:\d+$/', '', $httpHost);
}

/**
 * โฮสต์ dev/local ที่อนุญาตให้ใช้ redirect_uri ตาม request จริง (รวม LAN สำหรับมือถือ)
 */
function drawdream_google_oauth_is_dev_host(string $httpHost): bool
{
    $host = drawdream_google_oauth_host_only($httpHost);
    if ($host === '') {
        return false;
    }
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return (bool)!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    return str_ends_with($host, '.local');
}

/**
 * redirect_uri จาก URL ที่ผู้ใช้เปิดจริง (สำคัญเมื่อทดสอบบนมือถือผ่าน IP ใน Wi‑Fi)
 */
function drawdream_google_oauth_request_redirect_uri(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . trim((string)$_SERVER['HTTP_HOST']) . '/auth/google_callback.php';
}

/**
 * redirect_uri สำหรับ dev local เมื่อยังไม่ตั้ง GOOGLE_REDIRECT_URI ใน .env
 */
function drawdream_google_oauth_guess_local_redirect_uri(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    if (!drawdream_google_oauth_is_dev_host((string)$_SERVER['HTTP_HOST'])) {
        return '';
    }

    return drawdream_google_oauth_request_redirect_uri();
}

/**
 * โหลดค่า client_id/client_secret/redirect_uri
 * ถ้ามีไฟล์ local จะใช้ไฟล์ local ก่อน
 * ถ้าไม่มี จะไปอ่านจาก environment variable
 */
function drawdream_google_oauth_config(): array
{
    $configFile = __DIR__ . '/../config/google_oauth.local.php';
    if (is_file($configFile)) {
        $cfg = require $configFile;
    } else {
        $cfg = [
            'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
            'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
        ];
    }

    $configuredRedirect = trim((string)($cfg['redirect_uri'] ?? ''));
    $requestRedirect = drawdream_google_oauth_request_redirect_uri();
    $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');

    // มือถือ/LAN: ต้องใช้ host เดียวกับที่เปิดหน้าเว็บ — 127.0.0.1 บนมือถือชี้ไปที่ตัวเครื่องเอง ทำให้ Google ตอบ 400
    if ($requestRedirect !== '' && drawdream_google_oauth_is_dev_host($httpHost)) {
        $redirectUri = $requestRedirect;
    } elseif ($configuredRedirect !== '') {
        $redirectUri = $configuredRedirect;
    } else {
        $redirectUri = drawdream_google_oauth_guess_local_redirect_uri() ?: $requestRedirect;
    }

    return [
        'client_id' => trim((string)($cfg['client_id'] ?? '')),
        'client_secret' => trim((string)($cfg['client_secret'] ?? '')),
        'redirect_uri' => $redirectUri,
    ];
}

/**
 * เช็กว่าค่า OAuth ครบหรือยัง
 * ใช้ก่อนโชว์ปุ่ม "เข้าสู่ระบบด้วย Google"
 */
function drawdream_google_oauth_is_ready(): bool
{
    $cfg = drawdream_google_oauth_config();
    return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '' && $cfg['redirect_uri'] !== '';
}

/**
 * สร้าง authorize URL พร้อม state (กัน CSRF)
 */
function drawdream_google_oauth_build_auth_url(string $state): string
{
    $cfg = drawdream_google_oauth_config();
    $query = http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'include_granted_scopes' => 'true',
        'prompt' => 'select_account',
        'state' => $state,
    ]);

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
}

/**
 * ส่ง POST แบบ form ไป endpoint OAuth
 * คืนค่าเป็นรูปแบบเดียวกันเสมอ: ok/payload หรือ ok=false/error
 */
function drawdream_google_oauth_apply_curl_ssl($ch): void
{
    foreach ([__DIR__ . '/../payment/cacert.pem', __DIR__ . '/../config/cacert.pem'] as $caPath) {
        if (is_file($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
            break;
        }
    }
}

function drawdream_google_oauth_post(string $url, array $data): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_not_available'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    curl_setopt_array($ch, $opts);
    drawdream_google_oauth_apply_curl_ssl($ch);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'request_failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_json_response', 'raw' => $response, 'http_code' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => 'http_' . $httpCode, 'payload' => $decoded];
    }

    return ['ok' => true, 'payload' => $decoded];
}

/**
 * ส่ง GET แล้วแปลงผลลัพธ์ JSON
 */
function drawdream_google_oauth_get_json(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_not_available'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    drawdream_google_oauth_apply_curl_ssl($ch);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'request_failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_json_response', 'raw' => $response, 'http_code' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => 'http_' . $httpCode, 'payload' => $decoded];
    }

    return ['ok' => true, 'payload' => $decoded];
}

/**
 * ข้อความ error จาก Google token endpoint สำหรับแสดงบน dev
 */
function drawdream_google_oauth_format_token_error(array $tokenRes): string
{
    $base = 'ไม่สามารถเชื่อมต่อ Google ได้ กรุณาลองใหม่';
    $payload = $tokenRes['payload'] ?? [];
    if (!is_array($payload)) {
        $err = trim((string)($tokenRes['error'] ?? ''));
        return $err !== '' ? $base . ' (' . $err . ')' : $base;
    }
    $detail = trim((string)($payload['error_description'] ?? $payload['error'] ?? ''));
    if ($detail === '') {
        $detail = trim((string)($tokenRes['error'] ?? ''));
    }
    if ($detail === '') {
        return $base;
    }
    if (stripos($detail, 'redirect_uri') !== false) {
        $hintUri = drawdream_google_oauth_request_redirect_uri();
        if ($hintUri === '') {
            $hintUri = 'http://127.0.0.1:8080/auth/google_callback.php';
        }

        return $base . ' — เพิ่ม Authorized redirect URI ใน Google Console ให้ตรงทุกตัวอักษร: ' . $hintUri
            . ' (ทดสอบบนมือถือต้องใช้ IP Wi‑Fi ของเครื่อง dev ไม่ใช่ 127.0.0.1)';
    }
    if (stripos($detail, 'client secret') !== false || stripos($detail, 'invalid_client') !== false) {
        return $base . ' — Client Secret ใน .env ไม่ตรง Google Console ให้ไป Credentials → OAuth client → สร้าง Client secret ใหม่ แล้ววางใน GOOGLE_CLIENT_SECRET แล้วรีสตาร์ทเซิร์ฟเวอร์';
    }

    return $base . ' (' . $detail . ')';
}
