<?php
/**
 * CLI: ตรวจว่าโหมดทดสอบ Omise พร้อม auto mark_as_paid (บริจาคสำเร็จอัตโนมัติที่หน้า QR)
 *
 * Usage:
 *   php tools/check_omise_test_auto_pay.php
 *   php tools/check_omise_test_auto_pay.php --api   # สร้าง charge 20 บาทแล้วลอง mark_as_paid จริง
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/payment/config.php';
require_once $root . '/payment/omise_helpers.php';

$argv = $_SERVER['argv'] ?? [];
$runApi = in_array('--api', $argv, true);

$ok = static function (bool $cond, string $label): void {
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . "\n";
};

$keyOk = OMISE_PUBLIC_KEY !== '' && OMISE_SECRET_KEY !== '';
$ok($keyOk, 'OMISE_PUBLIC_KEY + OMISE_SECRET_KEY ตั้งค่าแล้ว');

$testMode = drawdream_omise_is_test_mode();
$ok($testMode, 'ใช้ Omise Test Key (pkey_test_ / skey_test_)');

$autoOn = drawdream_omise_test_auto_mark_paid_enabled();
$ok($autoOn, 'OMISE_TEST_AUTO_MARK_PAID เปิดอยู่');

$mockOff = !OMISE_ALLOW_LOCAL_MOCK;
echo '[INFO] OMISE_ALLOW_LOCAL_MOCK=' . (OMISE_ALLOW_LOCAL_MOCK ? 'true' : 'false') . "\n";
echo '[INFO] หน้า scan_qr.php จะ mark_as_paid อัตโนมัติแล้ว poll ไปใบเสร็จเมื่อเงื่อนไขด้านบนครบ' . "\n";

if (!$keyOk || !$testMode || !$autoOn) {
    echo "\nแก้ใน .env (ดู .env.example):\n";
    echo "  OMISE_PUBLIC_KEY=pkey_test_...\n";
    echo "  OMISE_SECRET_KEY=skey_test_...\n";
    echo "  OMISE_TEST_AUTO_MARK_PAID=true\n";
    exit(1);
}

if (!$runApi) {
    echo "\nทดสอบเรียก Omise API จริง: php tools/check_omise_test_auto_pay.php --api\n";
    exit(0);
}

$satang = 2000;
$srcHttp = drawdream_omise_http_raw('POST', '/sources', json_encode([
    'type' => 'promptpay',
    'amount' => $satang,
    'currency' => 'THB',
], JSON_UNESCAPED_UNICODE));
if (!$srcHttp['ok'] || $srcHttp['body'] === '') {
    $ok(false, 'สร้าง PromptPay source — ' . drawdream_omise_error_for_human($srcHttp['err'] ?? ''));
    exit(1);
}
$source = json_decode($srcHttp['body'], true);
$sourceId = is_array($source) ? (string)($source['id'] ?? '') : '';
$ok($sourceId !== '', 'สร้าง PromptPay source');

$chgHttp = drawdream_omise_http_raw('POST', '/charges', json_encode([
    'amount' => $satang,
    'currency' => 'THB',
    'source' => $sourceId,
    'description' => 'DrawDream check_omise_test_auto_pay',
    'metadata' => ['type' => 'diagnostic'],
], JSON_UNESCAPED_UNICODE));
if (!$chgHttp['ok'] || $chgHttp['body'] === '') {
    $ok(false, 'สร้าง charge — ' . drawdream_omise_error_for_human($chgHttp['err'] ?? ''));
    exit(1);
}
$charge = json_decode($chgHttp['body'], true);
$chargeId = is_array($charge) ? (string)($charge['id'] ?? '') : '';
$ok($chargeId !== '', 'สร้าง charge ' . $chargeId);

$after = drawdream_omise_ensure_test_charge_paid($chargeId);
$paid = is_array($after) && (
    ($after['paid'] ?? false) === true
    || strtolower((string)($after['status'] ?? '')) === 'successful'
);
$ok($paid, 'mark_as_paid → successful (เหมือนที่หน้า QR ทำ)');

exit($paid ? 0 : 1);
