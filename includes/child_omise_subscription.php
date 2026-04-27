<?php
// includes/child_omise_subscription.php — เก็บสถานะอุปการะใน donation.recurring_*
// สรุปสั้น: จัดการข้อมูล subscription ของเด็กและการเชื่อมกับ Omise (customer/card/schedule)
declare(strict_types=1);

require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/child_subscription_history.php';

function drawdream_child_omise_subscription_ensure_schema(mysqli $conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM donor LIKE 'omise_customer_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query('ALTER TABLE donor ADD COLUMN omise_customer_id VARCHAR(64) NULL DEFAULT NULL');
    }
    $chkCard = $conn->query("SHOW COLUMNS FROM donor LIKE 'omise_card_id'");
    if ($chkCard && $chkCard->num_rows === 0) {
        $conn->query('ALTER TABLE donor ADD COLUMN omise_card_id VARCHAR(64) NULL DEFAULT NULL');
    }
    drawdream_payment_transaction_ensure_schema($conn);
}

/** วันที่รอบถัดไปจาก DB → ใช้เป็นวันตัด (1–28) สำหรับ cron */
function drawdream_subscription_bill_day_from_datetime_sql(string $sql): int
{
    $ts = strtotime($sql);
    if ($ts === false) {
        return 15;
    }

    return min(28, max(1, (int) date('j', $ts)));
}

function drawdream_subscription_now_bangkok_sql(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
}

function drawdream_subscription_next_charge_at(
    DateTimeImmutable $afterBangkok,
    array $planSpec,
    int $billDay
): DateTimeImmutable {
    $tz = $afterBangkok->getTimezone();
    $every = max(1, (int)($planSpec['every'] ?? 1));
    $anchor = $afterBangkok->modify('+' . $every . ' months');
    $y = (int)$anchor->format('Y');
    $m = (int)$anchor->format('n');
    $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz);
    $lastDom = (int)$firstOfMonth->format('t');
    $d = min(max(1, $billDay), $lastDom);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d 08:00:00', $y, $m, $d), $tz);
}

function drawdream_child_persist_subscription_paid_charge(
    mysqli $conn,
    string $chargeId,
    int $amountSatang,
    int $childId,
    int $donorUserId,
    string $sourceChannel = 'system'
): bool {
    if ($chargeId === '' || strpos($chargeId, 'chrg_') !== 0 || $childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $chk = $conn->prepare('SELECT 1 FROM donation WHERE omise_charge_id = ? AND transaction_status = ? LIMIT 1');
    if (!$chk) {
        return false;
    }
    $completed = 'completed';
    $chk->bind_param('ss', $chargeId, $completed);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) {
        return false;
    }
    $amountBaht = $amountSatang / 100.0;
    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($categoryId <= 0) {
        return false;
    }
    // ใช้แถว subscription ที่มีอยู่เป็นแถวแรกของการชำระ (กันเกิดแถวซ้ำจากการสมัครครั้งแรก)
    $seed = $conn->prepare(
        "SELECT donate_id
         FROM donation
         WHERE target_id = ? AND donor_id = ?
           AND donate_type = 'child_subscription'
           AND recurring_status IN ('active', 'paused')
           AND (omise_charge_id IS NULL OR omise_charge_id = '')
         ORDER BY donate_id DESC
         LIMIT 1"
    );
    $seedId = 0;
    if ($seed) {
        $seed->bind_param('ii', $childId, $donorUserId);
        $seed->execute();
        $seedRow = $seed->get_result()->fetch_assoc();
        $seedId = (int)($seedRow['donate_id'] ?? 0);
    }

    $scheduleIdForLog = null;
    $planCodeForLog = '';
    if ($seedId > 0) {
        $seedMeta = $conn->prepare('SELECT recurring_schedule_id, recurring_plan_code FROM donation WHERE donate_id = ? LIMIT 1');
        if ($seedMeta) {
            $seedMeta->bind_param('i', $seedId);
            $seedMeta->execute();
            $seedMetaRow = $seedMeta->get_result()->fetch_assoc() ?: [];
            $scheduleIdForLog = isset($seedMetaRow['recurring_schedule_id']) ? (string)$seedMetaRow['recurring_schedule_id'] : null;
            $planCodeForLog = (string)($seedMetaRow['recurring_plan_code'] ?? '');
        }
        $up = $conn->prepare(
            "UPDATE donation
             SET amount = ?, payment_status = ?, transfer_datetime = NOW(),
                 omise_charge_id = ?, transaction_status = ?
             WHERE donate_id = ?"
        );
        if (!$up) {
            return false;
        }
        $up->bind_param('dsssi', $amountBaht, $completed, $chargeId, $completed, $seedId);
        $ok = $up->execute() && $up->affected_rows >= 1;
        $donateIdForLog = $seedId;
    } else {
        $planCode = '';
        $nextAt = null;
        $scheduleId = null;
        $sMeta = $conn->prepare(
            "SELECT recurring_plan_code, recurring_next_charge_at, recurring_schedule_id
             FROM donation
             WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription'
             ORDER BY donate_id DESC
             LIMIT 1"
        );
        if ($sMeta) {
            $sMeta->bind_param('ii', $childId, $donorUserId);
            $sMeta->execute();
            $m = $sMeta->get_result()->fetch_assoc() ?: null;
            if (is_array($m)) {
                $planCode = (string)($m['recurring_plan_code'] ?? '');
                $nextAt = ($m['recurring_next_charge_at'] ?? null) !== null ? (string)$m['recurring_next_charge_at'] : null;
                $scheduleId = ($m['recurring_schedule_id'] ?? null) !== null ? (string)$m['recurring_schedule_id'] : null;
            }
        }
        $ins = $conn->prepare(
            'INSERT INTO donation (
                category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                omise_charge_id, transaction_status,
                donate_type, recurring_status, recurring_plan_code,
                recurring_next_charge_at, recurring_schedule_id
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            return false;
        }
        $rType = DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION_CHARGE;
        $rStatus = 'charged';
        $ins->bind_param(
            'iiiddssssssss',
            $categoryId,
            $childId,
            $donorUserId,
            $amountBaht,
            $completed,
            $chargeId,
            $completed,
            $rType,
            $rStatus,
            $planCode,
            $nextAt,
            $scheduleId
        );
        $ok = $ins->execute();
        $donateIdForLog = (int)$conn->insert_id;
        $scheduleIdForLog = $scheduleId;
        $planCodeForLog = $planCode;
    }
    if ($ok) {
        drawdream_child_subscription_history_log(
            $conn,
            $childId,
            $donorUserId,
            ($donateIdForLog ?? 0) > 0 ? (int)$donateIdForLog : null,
            $scheduleIdForLog,
            $chargeId,
            'charge_success',
            'active',
            'active',
            $planCodeForLog !== '' ? $planCodeForLog : null,
            $amountBaht,
            $sourceChannel,
            'persist_subscription_paid_charge'
        );
    }
    if ($ok && !function_exists('drawdream_child_sync_sponsorship_status')) {
        require_once __DIR__ . '/child_sponsorship.php';
    }
    if ($ok && function_exists('drawdream_child_sync_sponsorship_status')) {
        drawdream_child_sync_sponsorship_status($conn, $childId);
    }
    return $ok;
}

function drawdream_child_has_active_omise_subscription(mysqli $conn, int $childId, int $donorUserId): bool
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $active = 'active';
    $type = 'child_subscription';
    $st = $conn->prepare(
        'SELECT 1 FROM donation
         WHERE target_id = ? AND donor_id = ? AND donate_type = ? AND recurring_status = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('iiss', $childId, $donorUserId, $type, $active);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

function drawdream_child_has_any_active_subscription(mysqli $conn, int $childId): bool
{
    if ($childId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $active = 'active';
    $type = 'child_subscription';
    $st = $conn->prepare(
        'SELECT 1 FROM donation
         WHERE target_id = ? AND donate_type = ? AND recurring_status = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('iss', $childId, $type, $active);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

/**
 * @param list<int> $childIds
 * @return array<int, true>
 */
function drawdream_child_ids_with_active_plan_sponsorship(mysqli $conn, array $childIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int)$x, $childIds), static fn ($x) => $x > 0)));
    if ($ids === []) {
        return [];
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $active = 'active';
    $type = 'child_subscription';
    $sql = "SELECT DISTINCT target_id AS child_id
            FROM donation
            WHERE donate_type = ? AND recurring_status = ? AND target_id IN ($ph)";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $bindTypes = 'ss' . $types;
    $st->bind_param($bindTypes, $type, $active, ...$ids);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['child_id']] = true;
    }
    return $out;
}

function drawdream_subscription_safe_bill_day(DateTimeImmutable $bangkokNow): int
{
    $d = (int)$bangkokNow->format('j');
    return min(28, max(1, $d));
}

function drawdream_child_subscription_plan(string $plan): ?array
{
    $plan = strtolower(trim($plan));
    if ($plan === 'monthly') {
        return ['every' => 1, 'period' => 'month', 'amount_thb' => 700.0, 'amount_satang' => 70000, 'plan_code' => 'monthly'];
    }
    if ($plan === 'semiannual') {
        return ['every' => 6, 'period' => 'month', 'amount_thb' => 4200.0, 'amount_satang' => 420000, 'plan_code' => 'semiannual'];
    }
    if ($plan === 'yearly') {
        return ['every' => 12, 'period' => 'month', 'amount_thb' => 8400.0, 'amount_satang' => 840000, 'plan_code' => 'yearly'];
    }
    return null;
}

function drawdream_child_can_start_omise_subscription(mysqli $conn, int $childId, array $childRow, int $donorUserId): bool
{
    if ($donorUserId <= 0) {
        return false;
    }
    if (!empty($childRow['deleted_at'])) {
        return false;
    }
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    require_once __DIR__ . '/child_sponsorship.php';
    if (!drawdream_child_can_receive_donation($conn, $childId, $childRow)) {
        return false;
    }

    return !drawdream_child_has_active_omise_subscription($conn, $childId, $donorUserId);
}
