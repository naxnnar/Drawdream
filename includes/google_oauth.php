<?php
declare(strict_types=1);

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

    return [
        'client_id' => trim((string)($cfg['client_id'] ?? '')),
        'client_secret' => trim((string)($cfg['client_secret'] ?? '')),
        'redirect_uri' => trim((string)($cfg['redirect_uri'] ?? '')),
    ];
}

function drawdream_google_oauth_is_ready(): bool
{
    $cfg = drawdream_google_oauth_config();
    return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '' && $cfg['redirect_uri'] !== '';
}

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

function drawdream_google_oauth_post(string $url, array $data): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_not_available'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);

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
    ]);

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
