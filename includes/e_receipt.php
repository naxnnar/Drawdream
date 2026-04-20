<?php
declare(strict_types=1);
// สรุปสั้น: helper หา donate_id จาก charge และส่งแจ้งเตือนใบเสร็จอิเล็กทรอนิกส์

/**
 * includes/e_receipt.php
 * ใช้ทำงานเรื่อง "ใบเสร็จอิเล็กทรอนิกส์" หลังจ่ายสำเร็จ
 * สิ่งที่ไฟล์นี้ทำ:
 * 1) หา donate_id จาก charge id
 * 2) ส่งแจ้งเตือนให้ผู้บริจาคไปเปิดหน้าใบเสร็จ
 *
 * สิ่งที่ไฟล์นี้ไม่ทำ:
 * - ไม่ได้สร้าง PDF ใบเสร็จเอง
 */
require_once __DIR__ . '/notification_audit.php';

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
         WHERE omise_charge_id = ? AND transaction_status = ?
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
function drawdream_send_e_receipt_notification_by_donate_id(mysqli $conn, int $donateId): bool
{
    if ($donateId <= 0) {
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
        // ไม่มีรายการที่จ่ายสำเร็จจริง
        return false;
    }
    $donorId = (int)($row['donor_id'] ?? 0);
    if ($donorId <= 0) {
        // ข้อมูลไม่ครบ: หาเจ้าของใบเสร็จไม่เจอ
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

