<?php
declare(strict_types=1);

require_once __DIR__ . '/notification_audit.php';

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
        return 0;
    }
    $st->bind_param('ss', $chargeId, $completed);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)($row['donate_id'] ?? 0);
}

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

