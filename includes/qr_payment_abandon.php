<?php
// ยกเลิก QR ที่ยังไม่ชำระจริง: ไม่ใช้สถานะค้าง (pending) ค้างในระบบ — บันทึกเป็น failed

declare(strict_types=1);

/**
 * ลบค่า session ที่ผูกกับหน้าสแกน QR (โครงการ / เด็ก / มูลนิธิ-สิ่งของ)
 */
function drawdream_clear_pending_payment_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $sessionKeys = [
        'pending_charge_id',
        'pending_amount',
        'pending_donate_id',
        'qr_image',
        'pending_project',
        'pending_project_id',
        'pending_child_id',
        'pending_child_name',
        'pending_foundation',
        'pending_foundation_id',
    ];
    foreach ($sessionKeys as $k) {
        unset($_SESSION[$k]);
    }
}

/**
 * ยกเลิกรายการตาม Omise charge_id (ต้องเป็นของ donor คนนี้และยัง pending)
 *
 * @return int จำนวนแถว donation ที่อัปเดต (โดยปกติ 0 หรือ 1)
 */
function drawdream_abandon_pending_donation_by_charge(mysqli $conn, int $donorUserId, string $chargeId): int
{
    $chargeId = trim($chargeId);
    if ($donorUserId <= 0 || $chargeId === '') {
        return 0;
    }
    $sql = "
        UPDATE donation d
        INNER JOIN payment_transaction pt
            ON pt.donate_id = d.donate_id
            AND pt.omise_charge_id = ?
            AND pt.transaction_status = 'pending'
        SET d.payment_status = 'failed', pt.transaction_status = 'failed'
        WHERE d.donor_id = ? AND d.payment_status = 'pending'
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param('si', $chargeId, $donorUserId);
    $st->execute();
    return $st->affected_rows;
}

/**
 * ก่อนสร้าง QR ชุดใหม่: ปิดรายการ pending ทั้งหมดของผู้บริจาคคนนี้ (กันค้างหลายแท็บ / ลืมสแกน)
 */
function drawdream_abandon_all_pending_qr_for_donor(mysqli $conn, int $donorUserId): int
{
    if ($donorUserId <= 0) {
        return 0;
    }
    $sql = "
        UPDATE donation d
        INNER JOIN payment_transaction pt
            ON pt.donate_id = d.donate_id AND pt.transaction_status = 'pending'
        SET d.payment_status = 'failed', pt.transaction_status = 'failed'
        WHERE d.donor_id = ? AND d.payment_status = 'pending'
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $donorUserId);
    $st->execute();
    return $st->affected_rows;
}

/**
 * คืน URL กลับหลังยกเลิก — อนุญาตเฉพาะ path ภายในไซต์ (กัน open redirect)
 */
function drawdream_safe_payment_return_url(string $raw, string $fallback): string
{
    $t = trim($raw);
    if ($t === '') {
        return $fallback;
    }
    if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $t) !== 0) {
        return $fallback;
    }
    if (strpbrk($t, "\r\n\t\x00") !== false) {
        return $fallback;
    }
    if (!preg_match('#^(?:\.\./)+[a-zA-Z0-9_./?=&\-#]+$#', $t) && !preg_match('#^[a-zA-Z0-9_./?=&\-#]+$#', $t)) {
        return $fallback;
    }
    return $t;
}
