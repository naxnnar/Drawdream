<?php
// includes/pending_child_donation.php — ใช้ donation ตารางเดียว (pending -> completed) — คอลัมน์หลักเท่านั้น
// สรุปสั้น: บริหารรายการบริจาคเด็กแบบ pending -> completed ให้ปลอดภัยและไม่ซ้ำ
declare(strict_types=1);
/**
 * โมดูลนี้ดูแล lifecycle ของ "บริจาคเด็กแบบ one-time ผ่าน QR"
 * โดยใช้แนวคิด 2 เฟส:
 * - insert pending ตอนสร้าง charge
 * - finalize เป็น completed ตอน callback/check สำเร็จ
 *
 * จุดสำคัญ:
 * - finalize จะยืนยัน donor/target/category ตรงกับ pending row ก่อนเสมอ
 * - ใช้ transaction เพื่อกันข้อมูลค้างครึ่งทาง
 */

require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';

function drawdream_insert_pending_child_donation(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    float $amountBaht,
    string $omiseChargeId
): int {
    if ($childId <= 0 || $donorUserId <= 0 || $amountBaht < 20 || $omiseChargeId === '') {
        return 0;
    }
    drawdream_payment_transaction_ensure_schema($conn);
    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($categoryId <= 0) {
        return 0;
    }
    $pending = 'pending';
    $paymentPending = 'pending';
    $planDaily = DRAWDREAM_DONATION_RECURRING_PLAN_DAILY;
    $ins = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, transaction_status, donate_type, recurring_plan_code
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return 0;
    }
    $donateType = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
    $ins->bind_param(
        'iiidsssss',
        $categoryId,
        $childId,
        $donorUserId,
        $amountBaht,
        $paymentPending,
        $omiseChargeId,
        $pending,
        $donateType,
        $planDaily
    );
    if (!$ins->execute()) {
        return 0;
    }
    return (int)$conn->insert_id;
}

function drawdream_finalize_child_donation(
    mysqli $conn,
    int $childId,
    int $donateId,
    string $chargeId,
    float $amountBaht,
    int $donorUserId
): bool {
    if ($childId <= 0 || $chargeId === '' || $donorUserId <= 0) {
        return false;
    }
    drawdream_payment_transaction_ensure_schema($conn);
    $pending = 'pending';
    $st = $conn->prepare(
        'SELECT donate_id, category_id, target_id, donor_id
         FROM donation
         WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('ss', $chargeId, $pending);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $rowDonateId = (int)($row['donate_id'] ?? 0);
    if ($donateId > 0 && $donateId !== $rowDonateId) {
        // ป้องกัน finalize ผิดแถว หาก caller ส่ง donateId ที่ไม่ match กับ charge
        return false;
    }
    $pDonor = (int)($row['donor_id'] ?? 0);
    $pTarget = (int)($row['target_id'] ?? 0);
    $pCat = (int)($row['category_id'] ?? 0);
    if ($pDonor !== $donorUserId || $pTarget !== $childId || $pCat <= 0) {
        // ตรวจความถูกต้องของเจ้าของรายการและปลายทางบริจาค
        return false;
    }
    $completed = 'completed';
    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        $dtOne = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
        $planDaily = DRAWDREAM_DONATION_RECURRING_PLAN_DAILY;
        $up = $conn->prepare(
            'UPDATE donation
             SET amount = ?, payment_status = ?, transaction_status = ?, transfer_datetime = NOW(),
                 donate_type = ?, recurring_plan_code = ?
             WHERE donate_id = ? AND transaction_status = ?'
        );
        if (!$up) {
            throw new RuntimeException('prepare update donation');
        }
        $up->bind_param('dssssis', $amountBaht, $completed, $completed, $dtOne, $planDaily, $rowDonateId, $pending);
        $up->execute();
        if ($up->affected_rows < 1) {
            throw new RuntimeException('update donation');
        }
        if (!function_exists('drawdream_child_sync_sponsorship_status')) {
            require_once __DIR__ . '/child_sponsorship.php';
        }
        if (function_exists('drawdream_child_sync_sponsorship_status')) {
            drawdream_child_sync_sponsorship_status($conn, $childId);
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}
