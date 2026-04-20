<?php
// payment/cron_child_subscription_charges.php — หักบัตรรอบถัดไป (แผน local_cron_* — customer/card อยู่ที่ตาราง donor)
// สรุปสั้น: สคริปต์ cron สำหรับเก็บเงินรอบใหม่ของแผนอุปการะเด็กอัตโนมัติ
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    $sec = defined('DRAWDREAM_SUBSCRIPTION_CRON_SECRET') ? (string)DRAWDREAM_SUBSCRIPTION_CRON_SECRET : '';
    if ($sec === '' || !isset($_GET['secret']) || !hash_equals($sec, (string)$_GET['secret'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/e_receipt.php';

drawdream_child_omise_subscription_ensure_schema($conn);
drawdream_child_sponsorship_ensure_columns($conn);

$tz = new DateTimeZone('Asia/Bangkok');
$nowSql = drawdream_subscription_now_bangkok_sql();
$st = $conn->prepare(
    'SELECT d.*, dn.omise_customer_id, dn.omise_card_id
     FROM donation d
     INNER JOIN donor dn ON dn.user_id = d.donor_id
     WHERE d.donate_type = \'child_subscription\'
       AND d.recurring_status = ?
       AND d.recurring_next_charge_at IS NOT NULL AND d.recurring_next_charge_at <= ?
       AND d.recurring_schedule_id LIKE \'local_cron_%\'
     ORDER BY d.recurring_next_charge_at ASC
     LIMIT 50'
);
$stAct = 'active';
$st->bind_param('ss', $stAct, $nowSql);
$st->execute();
$res = $st->get_result();

$processed = 0;
$errors = [];

while ($row = $res->fetch_assoc()) {
    $subId = (int)($row['donate_id'] ?? 0);
    $childId = (int)($row['target_id'] ?? 0);
    $donorUid = (int)($row['donor_id'] ?? 0);
    $custId = trim((string)($row['omise_customer_id'] ?? ''));
    $cardId = trim((string)($row['omise_card_id'] ?? ''));
    $dueStr = trim((string)($row['recurring_next_charge_at'] ?? ''));
    $billDay = drawdream_subscription_bill_day_from_datetime_sql($dueStr !== '' ? $dueStr : $nowSql);
    if ($subId <= 0 || $childId <= 0 || $donorUid <= 0 || $custId === '') {
        continue;
    }
    if ($cardId === '') {
        $errors[] = 'sub ' . $subId . ': no card id on donor';
        continue;
    }
    $planSpec = drawdream_child_subscription_plan((string)($row['recurring_plan_code'] ?? ''));
    if ($planSpec === null) {
        $errors[] = 'sub ' . $subId . ': bad plan';
        continue;
    }

    $stmtC = $conn->prepare(
        'SELECT child_name FROM foundation_children WHERE child_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmtC->bind_param('i', $childId);
    $stmtC->execute();
    $cRow = $stmtC->get_result()->fetch_assoc();
    $childName = (string)($cRow['child_name'] ?? '');
    $desc = 'อุปการะเด็ก ' . $childName . ' — ' . $planSpec['plan_code'] . ' (' . $planSpec['amount_thb'] . ' THB)';

    $ch = drawdream_omise_create_card_charge(
        $custId,
        $cardId,
        (int)$planSpec['amount_satang'],
        $desc,
        [
            'child_id' => (string)$childId,
            'donor_user_id' => (string)$donorUid,
            'plan_code' => $planSpec['plan_code'],
            'app' => 'drawdream_child_subscription',
        ]
    );
    if (($ch['object'] ?? '') === 'error') {
        $errors[] = 'sub ' . $subId . ': ' . (string)($ch['message'] ?? 'charge failed');
        continue;
    }
    $paid = ($ch['paid'] ?? false) === true || (string)($ch['status'] ?? '') === 'successful';
    if (!$paid) {
        $errors[] = 'sub ' . $subId . ': not paid status=' . (string)($ch['status'] ?? '');
        continue;
    }
    $chId = (string)($ch['id'] ?? '');
    $amtSat = (int)($ch['amount'] ?? $planSpec['amount_satang']);
    $rec = drawdream_child_persist_subscription_paid_charge($conn, $chId, $amtSat, $childId, $donorUid);
    if (!$rec) {
        $dup = $conn->prepare('SELECT 1 FROM donation WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1');
        $done = 'completed';
        $dup->bind_param('ss', $chId, $done);
        $dup->execute();
        if (!$dup->get_result()->fetch_row()) {
            $errors[] = 'sub ' . $subId . ': persist donation failed';
            continue;
        }
    }
    $receiptDonateId = drawdream_receipt_completed_donation_id_by_charge($conn, $chId);
    if ($receiptDonateId > 0) {
        drawdream_send_e_receipt_notification_by_donate_id($conn, $receiptDonateId);
    }

    $anchor = $dueStr !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueStr, $tz)
        : new DateTimeImmutable('now', $tz);
    if ($anchor === false) {
        $anchor = new DateTimeImmutable('now', $tz);
    }
    $nextAt = drawdream_subscription_next_charge_at($anchor, $planSpec, $billDay);
    $nextSql = $nextAt->format('Y-m-d H:i:s');
    $upd = $conn->prepare(
        'UPDATE donation SET recurring_next_charge_at = ? WHERE donate_id = ?'
    );
    $upd->bind_param('si', $nextSql, $subId);
    $upd->execute();
    ++$processed;
}

$out = ['ok' => true, 'processed' => $processed, 'errors' => $errors];
if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
