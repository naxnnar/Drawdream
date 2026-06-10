<?php
// payment/config.php — คีย์ Omise + endpoint (Test/Live)
// สรุปสั้น: คอนฟิกกลางของระบบชำระเงิน — อ่านค่าลับจาก .env / ตัวแปรสภาพแวดล้อมบนโฮสต์ (ไม่เก็บใน Git)
/**
 * Omise — คีย์ API และ endpoint
 *
 * ตั้งค่า: คัดลอก `.env.example` → `.env` ที่รากโปรเจกต์ แล้วใส่ค่าจริง
 * บนโฮสต์ production ใส่ env ในแผงโฮสต์หรือไฟล์ `.env` นอก web root
 *
 * ตัวแปรที่ต้องมี (ชำระเงิน):
 * - OMISE_PUBLIC_KEY, OMISE_SECRET_KEY
 * - OMISE_WEBHOOK_SECRET (สำหรับ payment/omise_webhook.php)
 * - DRAWDREAM_SUBSCRIPTION_CRON_SECRET (ถ้าเรียก cron ผ่าน HTTP)
 *
 * @see README.md, .env.example
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/env_loader.php';
drawdream_load_env_file(dirname(__DIR__) . '/.env');

if (!function_exists('drawdream_payment_env')) {
    /** อ่าน env — ไม่ทับค่าที่มีใน environment แล้ว (drawdream_load_env_file ทำไว้ก่อนหน้า) */
    function drawdream_payment_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return trim((string) $v);
        }

        return $default;
    }

    function drawdream_payment_env_bool(string $key, bool $default): bool
    {
        $raw = drawdream_payment_env($key);
        if ($raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!defined('OMISE_PUBLIC_KEY')) {
    define('OMISE_PUBLIC_KEY', drawdream_payment_env('OMISE_PUBLIC_KEY'));
}
if (!defined('OMISE_SECRET_KEY')) {
    define('OMISE_SECRET_KEY', drawdream_payment_env('OMISE_SECRET_KEY'));
}
if (!defined('OMISE_API_URL')) {
    define('OMISE_API_URL', drawdream_payment_env('OMISE_API_URL', 'https://api.omise.co'));
}

/** ถ้าตั้งค่า: ชี้ไปที่ไฟล์ cacert.pem (Mozilla) */
if (!defined('OMISE_CURL_CAINFO')) {
    $envCa = drawdream_payment_env('OMISE_CURL_CAINFO');
    if ($envCa !== '' && is_file($envCa)) {
        define('OMISE_CURL_CAINFO', $envCa);
    } else {
        foreach ([dirname(__DIR__) . '/payment/cacert.pem', dirname(__DIR__) . '/config/cacert.pem'] as $caPath) {
            if (is_file($caPath)) {
                define('OMISE_CURL_CAINFO', $caPath);
                break;
            }
        }
    }
}

if (!defined('OMISE_ALLOW_LOCAL_MOCK')) {
    define('OMISE_ALLOW_LOCAL_MOCK', drawdream_payment_env_bool('OMISE_ALLOW_LOCAL_MOCK', false));
}

if (!defined('OMISE_TEST_MOCK_WHEN_HTTPS_FAILS')) {
    define('OMISE_TEST_MOCK_WHEN_HTTPS_FAILS', drawdream_payment_env_bool('OMISE_TEST_MOCK_WHEN_HTTPS_FAILS', false));
}

if (!defined('OMISE_TEST_AUTO_MARK_PAID')) {
    define('OMISE_TEST_AUTO_MARK_PAID', drawdream_payment_env_bool('OMISE_TEST_AUTO_MARK_PAID', true));
}

if (!defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET')) {
    define('DRAWDREAM_SUBSCRIPTION_CRON_SECRET', drawdream_payment_env('DRAWDREAM_SUBSCRIPTION_CRON_SECRET'));
}

/**
 * เรียกจากหน้าชำระเงินเมื่อต้องการข้อความชัดเจนว่ายังไม่ได้ตั้ง .env
 */
function drawdream_payment_require_omise_keys(): void
{
    if (OMISE_PUBLIC_KEY !== '' && OMISE_SECRET_KEY !== '') {
        return;
    }
    $msg = 'ยังไม่ได้ตั้งค่า Omise: สร้างไฟล์ .env จาก .env.example แล้วใส่ OMISE_PUBLIC_KEY และ OMISE_SECRET_KEY';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}
