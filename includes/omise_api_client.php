<?php
// includes/omise_api_client.php — HTTP POST แบบ form-urlencoded ไป Omise (customer / schedules)
declare(strict_types=1);

/**
 * ใช้ CA bundle จาก OMISE_CURL_CAINFO, config/cacert.pem, หรือ php.ini (curl.cainfo / openssl.cafile)
 * ช่วยให้ cURL บน Windows เชื่อม api.omise.co ได้เมื่อยังไม่ได้ตั้ง CA ใน php.ini
 */
function drawdream_omise_curl_ca_bundle_path(): ?string
{
    if (defined('OMISE_CURL_CAINFO') && is_string(OMISE_CURL_CAINFO) && OMISE_CURL_CAINFO !== '' && is_file(OMISE_CURL_CAINFO)) {
        return OMISE_CURL_CAINFO;
    }
    $root = dirname(__DIR__);
    $paymentCa = $root . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_file($paymentCa)) {
        return $paymentCa;
    }
    $configCa = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_file($configCa)) {
        return $configCa;
    }
    $iniCa = ini_get('curl.cainfo');
    if (is_string($iniCa) && $iniCa !== '' && is_file($iniCa)) {
        return $iniCa;
    }
    $iniCa = ini_get('openssl.cafile');
    if (is_string($iniCa) && $iniCa !== '' && is_file($iniCa)) {
        return $iniCa;
    }

    return null;
}

/** @param \CurlHandle|resource $ch */
function drawdream_omise_curl_apply_omise_defaults($ch): void
{
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 90,
    ];
    $ca = drawdream_omise_curl_ca_bundle_path();
    if ($ca !== null) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
}

/**
 * @param array<string, mixed> $fields รองรับ nested array สำหรับ charge[metadata][key]
 * @return array<string, mixed> คำตอบ Omise หรือ ['object'=>'error', ...] เมื่อเครือข่าย/SSL/JSON ผิดพลาด (ไม่คืน null)
 */
function drawdream_omise_post_form(string $path, array $fields): array
{
    $path = '/' . ltrim($path, '/');
    $url = rtrim(OMISE_API_URL, '/') . $path;
    $ch = curl_init($url);
    drawdream_omise_curl_apply_omise_defaults($ch);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $response === '') {
        $msg = $curlErr !== '' ? $curlErr : ('เชื่อมต่อ Omise ไม่ได้ (HTTP ' . $httpCode . ')');

        return [
            'object' => 'error',
            'code' => 'curl',
            'message' => $msg,
            'http_code' => $httpCode,
        ];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'object' => 'error',
            'code' => 'invalid_json',
            'message' => 'คำตอบ Omise ไม่ใช่ JSON: ' . substr((string)$response, 0, 160),
            'http_code' => $httpCode,
        ];
    }

    return $decoded;
}

/**
 * สร้าง charge จากบัตรที่ผูกลูกค้า (ไม่ใช้ Charge Schedule)
 *
 * @param array<string, string> $metadata
 * @return array<string, mixed>
 */
function drawdream_omise_create_card_charge(
    string $customerId,
    string $cardId,
    int $amountSatang,
    string $description,
    array $metadata
): array {
    $fields = [
        'amount' => (string)$amountSatang,
        'currency' => 'thb',
        'customer' => $customerId,
        'card' => $cardId,
        'description' => $description,
        'capture' => 'true',
    ];
    foreach ($metadata as $mk => $mv) {
        $fields['metadata[' . $mk . ']'] = (string)$mv;
    }
    return drawdream_omise_post_form('/charges', $fields);
}
