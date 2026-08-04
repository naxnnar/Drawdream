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
require_once dirname(__DIR__) . '/includes/child_subscription_history.php';

$tz = new DateTimeZone('Asia/Bangkok');
$nowSql = drawdream_subscription_now_bangkok_sql();
$st = $conn->prepare(
    "SELECT h.history_id, h.child_id, h.donor_user_id, h.donate_id, h.recurring_schedule_id,
            h.recurring_plan_code, h.recurring_next_charge_at, dn.omise_customer_id, dn.omise_card_id
     FROM child_subscription_history h
     INNER JOIN donor dn ON dn.user_id = h.donor_user_id
     WHERE h.current_status = 'active'
       AND h.recurring_schedule_id LIKE 'local_cron_%'
     ORDER BY h.child_id ASC, h.donor_user_id ASC, h.history_id DESC"
);
$st->execute();
$res = $st->get_result();
$latestActiveSubs = [];
while ($hist = $res->fetch_assoc()) {
    $k = (int)($hist['child_id'] ?? 0) . ':' . (int)($hist['donor_user_id'] ?? 0);
    if (!isset($latestActiveSubs[$k])) {
        $latestActiveSubs[$k] = $hist;
    }
}

$processed = 0;
$errors = [];

foreach ($latestActiveSubs as $row) {
    $subId = (int)($row['donate_id'] ?? 0);
    $childId = (int)($row['child_id'] ?? 0);
    $donorUid = (int)($row['donor_user_id'] ?? 0);
    $custId = trim((string)($row['omise_customer_id'] ?? ''));
    $cardId = trim((string)($row['omise_card_id'] ?? ''));
    $dueStr = trim((string)($row['recurring_next_charge_at'] ?? ''));
    if ($dueStr === '' || strtotime($dueStr) === false || strtotime($dueStr) > strtotime($nowSql)) {
        continue;
    }
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
        'SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1'
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
        drawdream_child_subscription_history_log(
            $conn,
            $childId,
            $donorUid,
            $subId,
            (string)($row['recurring_schedule_id'] ?? ''),
            null,
            'charge_failed',
            'active',
            'active',
            (string)($row['recurring_plan_code'] ?? ''),
            (float)$planSpec['amount_thb'],
            'cron',
            'omise_error',
            ['message' => (string)($ch['message'] ?? 'charge failed')]
        );
        $errors[] = 'sub ' . $subId . ': ' . (string)($ch['message'] ?? 'charge failed');
        continue;
    }
    $paid = ($ch['paid'] ?? false) === true || (string)($ch['status'] ?? '') === 'successful';
    if (!$paid) {
        drawdream_child_subscription_history_log(
            $conn,
            $childId,
            $donorUid,
            $subId,
            (string)($row['recurring_schedule_id'] ?? ''),
            (string)($ch['id'] ?? ''),
            'charge_not_paid',
            'active',
            'active',
            (string)($row['recurring_plan_code'] ?? ''),
            (float)$planSpec['amount_thb'],
            'cron',
            'charge_status_not_successful',
            ['status' => (string)($ch['status'] ?? '')]
        );
        $errors[] = 'sub ' . $subId . ': not paid status=' . (string)($ch['status'] ?? '');
        continue;
    }
    $chId = (string)($ch['id'] ?? '');
    $amtSat = (int)($ch['amount'] ?? $planSpec['amount_satang']);
    $rec = drawdream_child_persist_subscription_paid_charge($conn, $chId, $amtSat, $childId, $donorUid, 'cron');
    if (!$rec) {
        $dup = $conn->prepare('SELECT 1 FROM donation WHERE omise_charge_id = ? AND payment_status = ? LIMIT 1');
        $done = 'completed';
        $dup->bind_param('ss', $chId, $done);
        $dup->execute();
        if (!$dup->get_result()->fetch_row()) {
            drawdream_child_subscription_history_log(
                $conn,
                $childId,
                $donorUid,
                $subId,
                (string)($row['recurring_schedule_id'] ?? ''),
                $chId,
                'charge_persist_failed',
                'active',
                'active',
                (string)($row['recurring_plan_code'] ?? ''),
                $amtSat / 100.0,
                'cron',
                'persist_donation_failed'
            );
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
    drawdream_child_subscription_history_log(
        $conn,
        $childId,
        $donorUid,
        $subId,
        (string)($row['recurring_schedule_id'] ?? ''),
        $chId !== '' ? $chId : null,
        'next_charge_scheduled',
        'active',
        'active',
        (string)($row['recurring_plan_code'] ?? ''),
        $amtSat / 100.0,
        'cron',
        'next_charge_updated',
        ['next_charge_at' => $nextSql]
    );
    ++$processed;
}

$out = ['ok' => true, 'processed' => $processed, 'errors' => $errors];
if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
