<?php
// payment/omise_helpers.php — ดึง charge / URI ภาพ PromptPay จาก Omise (ใช้ร่วมกับ payment_project, scan_qr)
// สรุปสั้น: helper กลางเรียก Omise API และจัดการ fallback เครือข่ายหลายรูปแบบ
/**
 * ไฟล์รวม helper เชื่อม Omise
 * พยายามยิง API ตามลำดับ:
 * 1) cURL
 * 2) SSL socket
 * 3) file_get_contents
 * เพื่อให้รันได้แม้เครื่อง dev แต่ละคน config ไม่เหมือนกัน
 *
 * ทุกฟังก์ชันคืนผลแบบ ok/error เพื่อให้หน้าเรียกใช้งาน handle ง่าย
 */

/**
 * ไฟล์ CA bundle (Mozilla) — วางที่ payment/cacert.pem (เช่น ดาวน์โหลดจาก https://curl.se/ca/cacert.pem )
 * ใช้แทนการตั้ง openssl.cafile ใน php.ini เมื่อยังไม่ได้ตั้ง
 */
function drawdream_omise_ca_bundle_path(): ?string
{
    $p = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_readable($p)) {
        return $p;
    }
    $iniCa = ini_get('curl.cainfo');
    if (is_string($iniCa) && $iniCa !== '' && is_readable($iniCa)) {
        return $iniCa;
    }
    $iniOpenSslCa = ini_get('openssl.cafile');
    if (is_string($iniOpenSslCa) && $iniOpenSslCa !== '' && is_readable($iniOpenSslCa)) {
        return $iniOpenSslCa;
    }
    error_log('[drawdream_omise] missing CA bundle fallback, using system store');
    return null;
}

/**
 * ตัวเลือก SSL สำหรับ stream/cURL (มี cafile เมื่อมีไฟล์ cacert.pem)
 *
 * @return array<string, mixed>
 */
function drawdream_omise_ssl_options(): array
{
    $ssl = [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ];
    $ca = drawdream_omise_ca_bundle_path();
    if ($ca !== null) {
        $ssl['cafile'] = $ca;
    }
    return $ssl;
}

/**
 * @return array<string, mixed>
 */
function drawdream_omise_socket_ssl_context(string $host): array
{
    $ssl = drawdream_omise_ssl_options();
    $ssl['SNI_enabled'] = true;
    $ssl['peer_name'] = $host;
    return $ssl;
}

/**
 * ถอดร่าง body แบบ chunked (HTTP/1.1)
 */
function drawdream_omise_http_decode_chunked(string $body): string
{
    $out = '';
    $offset = 0;
    $len = strlen($body);
    while ($offset < $len) {
        $lineEnd = strpos($body, "\r\n", $offset);
        if ($lineEnd === false) {
            break;
        }
        $chunkHex = trim(substr($body, $offset, $lineEnd - $offset));
        if ($chunkHex === '' || !preg_match('/^([0-9a-fA-F]+)/', $chunkHex, $hm)) {
            break;
        }
        $chunkLen = hexdec($hm[1]);
        if ($chunkLen === 0) {
            break;
        }
        $offset = $lineEnd + 2;
        $out .= substr($body, $offset, $chunkLen);
        $offset += $chunkLen + 2;
    }
    return $out;
}

/**
 * แยก body จาก raw HTTP response (รองรับ Content-Length และ chunked)
 *
 * @return array{body: string, err: string}
 */
function drawdream_omise_http_split_response(string $raw): array
{
    $sep = strpos($raw, "\r\n\r\n");
    if ($sep === false) {
        return ['body' => '', 'err' => 'คำตอบ HTTP ไม่ถูกต้อง'];
    }
    $headerBlock = substr($raw, 0, $sep);
    $body = substr($raw, $sep + 4);
    if (stripos($headerBlock, 'Transfer-Encoding: chunked') !== false) {
        return ['body' => drawdream_omise_http_decode_chunked($body), 'err' => ''];
    }
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $headerBlock, $m)) {
        $body = substr($body, 0, (int) $m[1]);
    }
    return ['body' => $body, 'err' => ''];
}

/**
 * HTTPS ไป Omise โดยไม่ใช้ wrapper https:// ของ file_get_contents (แก้ปัญหา Windows / openssl.cafile)
 *
 * @return array{ok: bool, body: string, err: string}
 */
function drawdream_omise_http_via_ssl_socket(string $method, string $path, ?string $jsonBody): array
{
    if (!extension_loaded('openssl')) {
        return ['ok' => false, 'body' => '', 'err' => 'PHP ต้องเปิด extension openssl เพื่อเชื่อม Omise แบบ HTTPS (หรือติดตั้ง cURL)'];
    }

    $method = strtoupper(trim($method));
    $path = '/' . ltrim($path, '/');
    $base = rtrim(OMISE_API_URL, '/') . '/';
    $parts = parse_url($base);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return ['ok' => false, 'body' => '', 'err' => 'OMISE_API_URL ไม่ถูกต้อง'];
    }
    $host = $parts['host'];
    $port = (int) ($parts['port'] ?? 443);

    $auth = base64_encode(OMISE_SECRET_KEY . ':');
    $payload = '';
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $payload = $jsonBody ?? '{}';
    }

    $lines = [
        "{$method} {$path} HTTP/1.1",
        "Host: {$host}",
        "Authorization: Basic {$auth}",
        'User-Agent: DrawDreamPHP/1.0',
        'Accept: application/json',
        'Accept-Encoding: identity',
        'Connection: close',
    ];
    if ($payload !== '') {
        $lines[] = 'Content-Type: application/json';
        $lines[] = 'Content-Length: ' . strlen($payload);
    }
    $request = implode("\r\n", $lines) . "\r\n\r\n" . $payload;

    $ctx = stream_context_create([
        'ssl' => drawdream_omise_socket_ssl_context($host),
    ]);
    $socket = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($socket === false) {
        return ['ok' => false, 'body' => '', 'err' => $errstr !== '' ? $errstr : "เชื่อมต่อ ssl://{$host}:{$port} ไม่ได้ ({$errno})"];
    }
    stream_set_timeout($socket, 30);
    fwrite($socket, $request);
    $raw = stream_get_contents($socket);
    fclose($socket);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'body' => '', 'err' => 'ไม่ได้รับข้อมูลจาก Omise'];
    }

    $split = drawdream_omise_http_split_response($raw);
    if ($split['err'] !== '') {
        return ['ok' => false, 'body' => '', 'err' => $split['err']];
    }
    return ['ok' => true, 'body' => $split['body'], 'err' => ''];
}

/**
 * เรียก Omise API (HTTPS) — cURL → SSL socket → file_get_contents (ตามลำดับ)
 *
 * @return array{ok: bool, body: string, err: string}
 */
function drawdream_omise_http_raw(string $method, string $path, ?string $jsonBody): array
{
    $method = strtoupper(trim($method));
    $path = '/' . ltrim($path, '/');
    $url = rtrim(OMISE_API_URL, '/') . $path;

    if (function_exists('curl_init')) {
        // ทางหลัก (เสถียรสุด): cURL
        $maxAttempts = 3;
        $lastErr = '';
        $lastResponse = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $caBundle = drawdream_omise_ca_bundle_path();
            if ($caBundle !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            if ($method === 'GET') {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, []);
            } elseif ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody ?? '{}');
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody ?? '');
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $response !== '' && $httpCode < 500 && $httpCode !== 429) {
                return ['ok' => true, 'body' => (string)$response, 'err' => ''];
            }
            $lastResponse = is_string($response) ? $response : '';
            $lastErr = $curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode);
            if ($attempt < $maxAttempts) {
                usleep((int)(200000 * $attempt));
            }
        }
        return ['ok' => false, 'body' => $lastResponse, 'err' => $lastErr !== '' ? $lastErr : 'empty response'];
    }

    // fallback 1: SSL socket (มักช่วยเคส local/windows)
    $sock = drawdream_omise_http_via_ssl_socket($method, $path, $jsonBody);
    if ($sock['ok']) {
        return $sock;
    }

    if (!ini_get('allow_url_fopen')) {
        return [
            'ok' => false,
            'body' => '',
            'err' => $sock['err'] !== ''
                ? $sock['err']
                : 'PHP ไม่มี cURL, เชื่อม SSL ไม่สำเร็จ และปิด allow_url_fopen — เปิด extension=curl หรือ openssl ใน php.ini',
        ];
    }

    $auth = base64_encode(OMISE_SECRET_KEY . ':');
    $headers = ['Authorization: Basic ' . $auth];
    $content = '';
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $headers[] = 'Content-Type: application/json';
        $content = $jsonBody ?? '{}';
    }

    // fallback 2: file_get_contents
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => drawdream_omise_ssl_options(),
    ];
    $ctx = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        $last = error_get_last();
        $msg = is_array($last) ? (string) ($last['message'] ?? '') : '';
        $hint = $msg !== '' ? $msg : 'file_get_contents failed';
        if ($sock['err'] !== '') {
            $hint = $sock['err'] . ' | ' . $hint;
        }
        return ['ok' => false, 'body' => '', 'err' => $hint];
    }
    return ['ok' => true, 'body' => (string) $response, 'err' => ''];
}

/**
 * GET /charges/{id} พร้อม expand source (ต้อง include config.php ก่อน)
 */
function drawdream_omise_fetch_charge(string $chargeId): ?array
{
    $chargeId = trim($chargeId);
    if ($chargeId === '') {
        return null;
    }
    $path = '/charges/' . rawurlencode($chargeId) . '?expand[]=source';
    $http = drawdream_omise_http_raw('GET', $path, null);
    if (!$http['ok'] || $http['body'] === '') {
        error_log('[drawdream_omise] fetch charge failed: ' . drawdream_omise_error_for_human($http['err'] ?? ''));
        return null;
    }
    $decoded = json_decode($http['body'], true);
    return is_array($decoded) ? $decoded : null;
}

function drawdream_omise_error_for_human(string $err): string
{
    $lower = strtolower($err);
    if (str_contains($lower, 'timed out')) {
        return 'การเชื่อมต่อ Omise หมดเวลา กรุณาลองใหม่';
    }
    if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
        return 'การเชื่อมต่อ SSL ไป Omise ไม่สำเร็จ กรุณาตรวจสอบ CA certificate';
    }
    if (str_contains($lower, 'http 429')) {
        return 'คำขอไป Omise ถูกจำกัดความถี่ชั่วคราว';
    }
    if (preg_match('/http\s+5\d\d/i', $err)) {
        return 'ระบบ Omise ขัดข้องชั่วคราว กรุณาลองใหม่';
    }
    return $err !== '' ? $err : 'เชื่อมต่อ Omise ไม่สำเร็จ';
}

/**
 * ดึง download_uri ของภาพ QR PromptPay จากอ็อบเจ็กต์ charge (รูปแบบ Omise API)
 */
function drawdream_omise_promptpay_qr_uri_from_charge(?array $charge): string
{
    if ($charge === null) {
        return '';
    }
    $source = $charge['source'] ?? null;
    if (!is_array($source)) {
        return '';
    }
    $uri = $source['scannable_code']['image']['download_uri'] ?? '';
    return trim((string) $uri);
}
