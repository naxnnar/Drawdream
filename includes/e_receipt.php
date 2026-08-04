<?php
declare(strict_types=1);
// สรุปสั้น: helper หา donate_id จาก charge และส่งแจ้งเตือนใบเสร็จอิเล็กทรอนิกส์

/**
 * includes/e_receipt.php
 * ใช้ทำงานเรื่อง "ใบเสร็จอิเล็กทรอนิกส์" หลังจ่ายสำเร็จ
 * สิ่งที่ไฟล์นี้ทำ:
 * 1) หา donate_id จาก charge id
 * 2) ส่งแจ้งเตือนให้ผู้บริจาค (role donor) ไปเปิดหน้าใบเสร็จ — มูลนิธิไม่ได้รับ
 *
 * สิ่งที่ไฟล์นี้ไม่ทำ:
 * - ไม่ได้สร้าง PDF ใบเสร็จเอง
 */
require_once __DIR__ . '/notification_audit.php';
require_once __DIR__ . '/donate_type.php';

/** ประเภทบริจาคที่ไม่ออกใบเสร็จ / ไม่แจ้งเตือน (มูลนิธิชำระค่าบริการระบบ) */
function drawdream_donation_receipt_ref_from_row(int $donateId, ?string $transferDatetime): string
{
    $ts = strtotime((string)($transferDatetime ?? ''));
    $receiptRefDate = $ts !== false ? date('Ymd', $ts) : date('Ymd');

    return 'DD-' . $receiptRefDate . '-' . str_pad((string)$donateId, 7, '0', STR_PAD_LEFT);
}

function drawdream_e_receipt_excluded_donate_types(): array
{
    return [
        DRAWDREAM_DONATE_TYPE_NEED_SERVICE_CHARGE,
        DRAWDREAM_DONATE_TYPE_PROJECT_SERVICE_CHARGE,
    ];
}

function drawdream_user_is_foundation_role(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $st = $conn->prepare('SELECT LOWER(TRIM(COALESCE(role, \'\'))) AS r FROM `user` WHERE user_id = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return strtolower(trim((string)($row['r'] ?? ''))) === 'foundation';
}

/**
 * รายการบริจาคนี้ควรได้ใบเสร็จอิเล็กทรอนิกส์หรือไม่ (ผู้บริจาคเท่านั้น ไม่รวมค่าบริการมูลนิธิ)
 */
function drawdream_donation_eligible_for_e_receipt(mysqli $conn, int $donateId): bool
{
    if ($donateId <= 0) {
        return false;
    }
    $completed = 'completed';
    $st = $conn->prepare(
        'SELECT donor_id, donate_type
         FROM donation
         WHERE donate_id = ? AND payment_status = ?
         LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $donateId, $completed);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $donateType = strtolower(trim((string)($row['donate_type'] ?? '')));
    if (in_array($donateType, drawdream_e_receipt_excluded_donate_types(), true)) {
        return false;
    }
    $donorId = (int)($row['donor_id'] ?? 0);
    if ($donorId <= 0 || drawdream_user_is_foundation_role($conn, $donorId)) {
        return false;
    }

    return true;
}

/**
 * หา donate_id ล่าสุดจาก charge id ที่ชำระเสร็จแล้ว
 * ใช้ตอน callback ที่มีข้อมูลแค่ charge id
 */
function drawdream_receipt_completed_donation_id_by_charge(mysqli $conn, string $chargeId): int
{
    $chargeId = trim($chargeId);
    if ($chargeId === '') {
        return 0;
    }
    $completed = 'completed';
    $st = $conn->prepare(
        'SELECT donate_id
         FROM donation
         WHERE omise_charge_id = ? AND payment_status = ?
         ORDER BY donate_id DESC
         LIMIT 1'
    );
    if (!$st) {
        // query ไม่พร้อม ให้ส่ง 0 กลับไปให้ฝั่งเรียกตัดสินใจต่อ
        return 0;
    }
    $st->bind_param('ss', $chargeId, $completed);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['donate_id'] ?? 0);
}

/**
 * ส่งแจ้งเตือน "ได้รับใบเสร็จอิเล็กทรอนิกส์" จาก donate_id
 *
 * เงื่อนไขที่ต้องผ่านก่อน:
 * - donate_id ต้องมีจริง
 * - donation ต้องอยู่สถานะ completed
 * - ต้องหา donor_id ได้ (ใช้เป็นผู้รับแจ้งเตือน)
 */
/**
 * URL หน้าใบเสร็จหลังบริจาค/อุปการะสำเร็จ (relative จาก root โปรเจกต์)
 */
function drawdream_payment_success_receipt_query(
    int $donateId,
    string $successTitle,
    string $returnUrl = '',
    string $successDetail = ''
): string {
    if ($donateId <= 0) {
        return '';
    }
    $params = [
        'donate_id' => (string)$donateId,
        'success' => '1',
        'success_title' => $successTitle !== '' ? $successTitle : 'บริจาคสำเร็จ',
    ];
    if ($successDetail !== '') {
        $params['success_detail'] = $successDetail;
    }
    if ($returnUrl !== '') {
        $params['return'] = $returnUrl;
    }

    return 'donation_receipt.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** ส่ง redirect ให้เบราว์เซอร์แล้วปิดการเชื่อมต่อ — งานหนักทำหลัง flush ได้ */
function drawdream_payment_flush_redirect(string $url): void
{
    header('Location: ' . $url);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
}

/**
 * Redirect ไปหน้าใบเสร็จพร้อมแบนเนอร์/ Swal สำเร็จ — คืน false ถ้าไม่มีใบเสร็จ (ให้ fallback หน้าเดิม)
 *
 * @param string $receiptPagePrefix เช่น '../' จาก payment/
 */
function drawdream_try_payment_success_receipt_redirect(
    mysqli $conn,
    int $donateId,
    string $successTitle,
    string $returnUrl = '',
    string $successDetail = '',
    string $receiptPagePrefix = '../'
): bool {
    if ($donateId <= 0 || !drawdream_donation_eligible_for_e_receipt($conn, $donateId)) {
        return false;
    }
    $q = drawdream_payment_success_receipt_query($donateId, $successTitle, $returnUrl, $successDetail);
    if ($q === '') {
        return false;
    }
    drawdream_payment_flush_redirect($receiptPagePrefix . $q);
    exit;
}

/** ส่งแจ้งเตือนใบเสร็จหลัง redirect — ลดเวลารอผู้บริจาคบนหน้ายืนยัน */
function drawdream_send_e_receipt_notification_deferred(mysqli $conn, int $donateId): void
{
    if ($donateId <= 0) {
        return;
    }
    register_shutdown_function(static function () use ($conn, $donateId): void {
        try {
            drawdream_send_e_receipt_notification_by_donate_id($conn, $donateId);
        } catch (Throwable $e) {
            error_log('[drawdream_e_receipt_deferred] ' . $e->getMessage());
        }
    });
}

function drawdream_send_e_receipt_notification_by_donate_id(mysqli $conn, int $donateId): bool
{
    if ($donateId <= 0 || !drawdream_donation_eligible_for_e_receipt($conn, $donateId)) {
        return false;
    }
    $completed = 'completed';
    $st = $conn->prepare(
        'SELECT donate_id, donor_id, amount
         FROM donation
         WHERE donate_id = ? AND payment_status = ?
         LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $donateId, $completed);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $donorId = (int)($row['donor_id'] ?? 0);
    if ($donorId <= 0) {
        return false;
    }
    $amount = (float)($row['amount'] ?? 0);
    return drawdream_send_notification(
        $conn,
        $donorId,
        'e_receipt_issued',
        'ได้รับใบเสร็จอิเล็กทรอนิกส์',
        'ใบเสร็จบริจาคจำนวน ' . number_format($amount, 2) . ' บาท พร้อมดาวน์โหลดสำหรับใช้ลดหย่อนภาษี',
        'donation_receipt.php?donate_id=' . $donateId,
        'e_receipt:' . $donateId
    );
}

