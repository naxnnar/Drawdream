<?php
// includes/qr_payment_abandon.php — ล้าง session QR payment ค้าง
// สรุปสั้น: ปิด/ยกเลิกรายการ QR ที่ค้าง เพื่อไม่ให้ยอด pending สะสมผิด
// ยกเลิก QR ที่ยังไม่ชำระ: ใช้ donation ตารางเดียว

declare(strict_types=1);

require_once __DIR__ . '/payment_transaction_schema.php';

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
 * @return int 1 ถ้าลบ/ยกเลิกสำเร็จ, 0 ถ้าไม่พบหรือไม่ใช่ของผู้ใช้
 */
function drawdream_abandon_pending_donation_by_charge(mysqli $conn, int $donorUserId, string $chargeId): int
{
    $chargeId = trim($chargeId);
    if ($donorUserId <= 0 || $chargeId === '') {
        return 0;
    }
    drawdream_payment_transaction_ensure_schema($conn);

    $st = $conn->prepare(
        'SELECT donate_id, donor_id
         FROM donation
         WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1'
    );
    $pend = 'pending';
    $st->bind_param('ss', $chargeId, $pend);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return 0;
    }

    $donateId = isset($row['donate_id']) && $row['donate_id'] !== null ? (int)$row['donate_id'] : 0;
    $rowDonorId = (int)($row['donor_id'] ?? 0);

    if ($rowDonorId !== $donorUserId) {
        return 0;
    }
    $del = $conn->prepare('DELETE FROM donation WHERE donate_id = ? AND transaction_status = ?');
    $del->bind_param('is', $donateId, $pend);
    $del->execute();
    return $del->affected_rows > 0 ? 1 : 0;
}

/**
 * ก่อนสร้าง QR ชุดใหม่: ปิดรายการ pending ทั้งหมดของผู้บริจาคคนนี้
 */
function drawdream_abandon_all_pending_qr_for_donor(mysqli $conn, int $donorUserId): int
{
    if ($donorUserId <= 0) {
        return 0;
    }
    drawdream_payment_transaction_ensure_schema($conn);

    $st1 = $conn->prepare(
        'DELETE FROM donation
         WHERE transaction_status = ? AND donor_id = ?'
    );
    $pend = 'pending';
    $st1->bind_param('si', $pend, $donorUserId);
    $st1->execute();
    return (int)$st1->affected_rows;
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
