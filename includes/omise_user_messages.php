<?php
// includes/omise_user_messages.php — ข้อความภาษาไทยจาก error ของ Omise API
// สรุปสั้น: แปล/แมป error จาก Omise ให้เป็นข้อความที่ผู้ใช้เข้าใจง่าย
declare(strict_types=1);

/** Omise บล็อก POST /schedules (มักหลังยังไม่ verify หรือบัญชียังไม่เปิดฟีเจอร์) */
function drawdream_omise_schedule_blocked_by_account(?array $res): bool
{
    if ($res === null || ($res['object'] ?? '') !== 'error') {
        return false;
    }
    $lower = strtolower((string)($res['message'] ?? ''));
    return str_contains($lower, 'email verification')
        || str_contains($lower, 'verify your email')
        || str_contains($lower, 'not allowed to')
        || str_contains($lower, 'cannot use this feature');
}

function drawdream_omise_is_not_found_error(?array $res): bool
{
    if ($res === null || ($res['object'] ?? '') !== 'error') {
        return false;
    }
    $code = (string)($res['code'] ?? '');
    $msg = strtolower((string)($res['message'] ?? ''));
    return $code === 'not_found'
        || str_contains($msg, 'not found')
        || str_contains($msg, 'was not found');
}

/**
 * แปลงข้อความ Omise ให้ผู้ใช้เข้าใจ (โดยเฉพาะ Charge Schedule)
 */
function drawdream_omise_error_message_for_user(?array $res, string $fallbackThai): string
{
    if ($res === null || !is_array($res)) {
        return $fallbackThai;
    }
    if (($res['object'] ?? '') !== 'error') {
        return $fallbackThai;
    }
    $msg = (string)($res['message'] ?? '');
    $lower = strtolower($msg);

    if (str_contains($lower, 'email verification')
        || str_contains($lower, 'verify your email')) {
        return 'Omise API ยังไม่ยอมให้สร้าง Charge Schedule (ข้อความ: ต้องยืนยันอีเมล) — สิ่งนี้ต่างจากหน้า «สถานะบัญชี / Recipient» ที่มีป้ายตรวจสอบแล้ว: '
            . 'หน้านั้นเป็นผู้รับโอน (recp_test_) สำหรับโอนเงินออก ไม่ใช่การปลดล็อกฟีเจอร์ Schedule หักบัตร '
            . 'ให้ล็อกอิน Dashboard ด้วยเจ้าของบัญชีที่สร้างคีย์ pkey_test_/skey_test_ นี้ → โหมด Test → เมนูบัญชี/การตั้งค่า '
            . 'https://dashboard.omise.co/test/account ตรวจว่ามีปุ่มยืนยันอีเมลหรือแจ้งรออีเมล แล้วกดลิงก์ในอีเมลจาก Omise (เช็คทั้ง Inbox/Spam) '
            . 'ถ้ายืนยันแล้วแต่ยัง error ให้เช็คว่าใน Keys เป็นคีย์ของบัญชีเดียวกับที่ยืนยัน หรือลองส่งคำถามไปที่ Omise Support เพราะบางบัญชีต้องให้ฝั่ง Omise เปิดสิทธิ์ Schedule';
    }

    $code = (string)($res['code'] ?? '');
    if ($code === 'curl') {
        $http = (int)($res['http_code'] ?? 0);
        $detail = $msg;
        $sslHint = '';
        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'unable to get local issuer')) {
            $sslHint = ' บน Windows/เครื่อง dev มักต้องใส่ไฟล์ CA: ดาวน์โหลด cacert.pem จาก https://curl.se/ca/cacert.pem แล้ววางที่ config/cacert.pem ในโปรเจกต์ หรือตั้ง curl.cainfo / openssl.cafile ใน php.ini ให้ชี้ไฟล์นั้น';
        }
        $tail = $detail !== '' ? (' — ' . $detail) : '';
        $httpPart = $http > 0 ? (' (HTTP ' . $http . ')') : '';

        return 'เชื่อมต่อ Omise ไม่สำเร็จ' . $httpPart . $tail . $sslHint;
    }
    if ($code === 'invalid_json') {
        return 'ได้รับคำตอบจาก Omise ที่อ่านไม่ได้ — ลองใหม่หรือตรวจเครือข่าย' . ($msg !== '' ? (' — ' . $msg) : '');
    }
    if ($code === 'not_found' || str_contains($lower, 'not found') || str_contains($lower, 'was not found')) {
        return 'Omise แจ้งว่าไม่พบข้อมูล (Resource not found) — มักเกิดจากรหัสลูกค้า/บัตรในฐานข้อมูลไม่ตรงกับบัญชี Omise ปัจจุบัน '
            . 'หรือโทเค็นบัตรใช้ได้ครั้งเดียวแล้วหมดอายุ ถ้าคุณเพิ่งสมัคร Schedule ระบบได้เคลียร์รหัสลูกค้าเก่าในระบบแล้ว — กรุณากด «บริจาค» อีกครั้งเพื่อสร้างโทเค็นใหม่ '
            . '(ถ้ายังไม่ได้ ให้รันใน phpMyAdmin: UPDATE donor SET omise_customer_id = NULL WHERE user_id = รหัสของคุณ;)';
    }

    if ($msg !== '') {
        return $msg;
    }
    if ($code !== '') {
        return $fallbackThai . ' (รหัส: ' . $code . ')';
    }
    return $fallbackThai;
}
