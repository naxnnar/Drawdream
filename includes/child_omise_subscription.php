<?php
// includes/child_omise_subscription.php — เก็บสถานะอุปการะใน donation.recurring_*
// สรุปสั้น: จัดการข้อมูล subscription ของเด็กและการเชื่อมกับ Omise (customer/card/schedule)
declare(strict_types=1);

require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/child_subscription_history.php';
require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_child_omise_subscription_ensure_schema(mysqli $conn): void
{
    drawdream_schema_once('child_omise_subscription', static function (mysqli $c): void {
        $chk = $c->query("SHOW COLUMNS FROM donor LIKE 'omise_customer_id'");
        if ($chk && $chk->num_rows === 0) {
            $c->query('ALTER TABLE donor ADD COLUMN omise_customer_id VARCHAR(64) NULL DEFAULT NULL');
        }
        $chkCard = $c->query("SHOW COLUMNS FROM donor LIKE 'omise_card_id'");
        if ($chkCard && $chkCard->num_rows === 0) {
            $c->query('ALTER TABLE donor ADD COLUMN omise_card_id VARCHAR(64) NULL DEFAULT NULL');
        }
        drawdream_payment_transaction_ensure_schema_inner($c);
    }, $conn);
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
    $chk = $conn->prepare('SELECT 1 FROM donation WHERE omise_charge_id = ? AND payment_status = ? LIMIT 1');
    if (!$chk) {
        return false;
    }
    $completed = 'completed';
    $chk->bind_param('ss', $chargeId, $completed);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) {
        if (!function_exists('drawdream_child_sync_sponsorship_status')) {
            require_once __DIR__ . '/child_sponsorship.php';
        }
        if (function_exists('drawdream_child_sync_sponsorship_status')) {
            drawdream_child_sync_sponsorship_status($conn, $childId);
        }
        return false;
    }
    $amountBaht = $amountSatang / 100.0;
    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($categoryId <= 0) {
        return false;
    }
    $scheduleIdForLog = null;
    $planCodeForLog = '';
    $planCode = '';
    $scheduleId = null;
    $hMeta = $conn->prepare(
        "SELECT recurring_plan_code, recurring_schedule_id
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ? AND current_status = 'active'
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if ($hMeta) {
        $hMeta->bind_param('ii', $childId, $donorUserId);
        $hMeta->execute();
        $m = $hMeta->get_result()->fetch_assoc() ?: null;
        if (is_array($m)) {
            $planCode = (string)($m['recurring_plan_code'] ?? '');
            $scheduleId = ($m['recurring_schedule_id'] ?? null) !== null ? (string)$m['recurring_schedule_id'] : null;
        }
    }
    $ins = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, donate_type
        ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)'
    );
    if (!$ins) {
        return false;
    }
    $rType = DRAWDREAM_DONATE_TYPE_CHILD_SUBSCRIPTION_CHARGE;
    $ins->bind_param(
        'iiiddss',
        $categoryId,
        $childId,
        $donorUserId,
        $amountBaht,
        $completed,
        $chargeId,
        $rType
    );
    $ok = $ins->execute();
    $donateIdForLog = (int)$conn->insert_id;
    $scheduleIdForLog = $scheduleId;
    $planCodeForLog = $planCode;
    if ($ok) {
        $nextChargeSql = null;
        if ($planCodeForLog !== '' && function_exists('drawdream_child_subscription_plan')) {
            $spec = drawdream_child_subscription_plan($planCodeForLog);
            if (is_array($spec) && function_exists('drawdream_subscription_next_charge_at')) {
                $anchor = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
                $billDay = drawdream_subscription_safe_bill_day($anchor);
                $nextChargeSql = drawdream_subscription_next_charge_at($anchor, $spec, $billDay)->format('Y-m-d H:i:s');
            }
        }
        if ($scheduleIdForLog === null || trim((string)$scheduleIdForLog) === '') {
            $scheduleIdForLog = 'local_cron_' . bin2hex(random_bytes(8));
        }
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
            'persist_subscription_paid_charge',
            null,
            $nextChargeSql !== null ? ['next_charge_at' => $nextChargeSql] : null
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

function drawdream_child_subscription_history_activate_paid_row(mysqli $conn, int $childId, int $donorUserId): bool
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    if (!drawdream_donor_has_child_subscription_payment($conn, $donorUserId, $childId)) {
        return false;
    }
    $status = drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId);
    if ($status === 'active' || in_array($status, ['cancelled', 'cancle', 'canceled'], true)) {
        return false;
    }
    $st = $conn->prepare(
        "SELECT history_id FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('ii', $childId, $donorUserId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $historyId = (int)($row['history_id'] ?? 0);
    if ($historyId <= 0) {
        return false;
    }
    $upd = $conn->prepare(
        "UPDATE child_subscription_history
         SET current_status = 'active',
             event_type = CASE
                 WHEN event_type IS NULL OR TRIM(event_type) = '' OR event_type = 'subscription_reserving'
                 THEN 'subscription_created'
                 ELSE event_type
             END
         WHERE history_id = ?
         LIMIT 1"
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param('i', $historyId);

    return $upd->execute() && $upd->affected_rows >= 0;
}

function drawdream_donor_ensure_active_subscription_record(mysqli $conn, int $donorUserId, int $childId): void
{
    if ($donorUserId <= 0 || $childId <= 0) {
        return;
    }
    drawdream_child_subscription_history_ensure_schema($conn);
    drawdream_child_subscription_history_activate_paid_row($conn, $childId, $donorUserId);
    if (function_exists('drawdream_child_subscription_history_backfill_active_row')) {
        drawdream_child_subscription_history_backfill_active_row($conn, $childId, $donorUserId);
    }
    drawdream_repair_donor_subscription_history_if_missing($conn, $donorUserId, $childId);
}

function drawdream_child_donor_latest_subscription_status(mysqli $conn, int $childId, int $donorUserId): string
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return '';
    }
    $st = $conn->prepare(
        "SELECT current_status
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return '';
    }
    $st->bind_param('ii', $childId, $donorUserId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return strtolower(trim((string)($row['current_status'] ?? '')));
}

function drawdream_donor_has_child_subscription_payment(mysqli $conn, int $donorUserId, int $childId): bool
{
    if ($donorUserId <= 0 || $childId <= 0) {
        return false;
    }
    $st = $conn->prepare(
        "SELECT 1 FROM donation
         WHERE donor_id = ? AND target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') IN ('child_subscription', 'child_subscription_charge')
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('ii', $donorUserId, $childId);
    $st->execute();

    return (bool)$st->get_result()->fetch_row();
}

/** ผู้บริจาคควรเห็นปุ่มยกเลิกอุปการะ (แผนรายเดือน / 6 เดือน / รายปี ที่ยัง active) */
function drawdream_donor_should_show_cancel_subscription(mysqli $conn, int $donorUserId, int $childId): bool
{
    if ($donorUserId <= 0 || $childId <= 0) {
        return false;
    }
    drawdream_donor_ensure_active_subscription_record($conn, $donorUserId, $childId);
    $status = drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId);
    if ($status === 'active') {
        return true;
    }
    if (in_array($status, ['cancelled', 'cancle', 'canceled'], true)) {
        return false;
    }
    if (!drawdream_donor_has_child_subscription_payment($conn, $donorUserId, $childId)) {
        return false;
    }
    drawdream_donor_ensure_active_subscription_record($conn, $donorUserId, $childId);

    return drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active';
}

/**
 * ข้อมูลแผนอุปการะ active ของผู้บริจาคกับเด็กคนนี้ (หน้าโปรไฟล์เด็ก)
 *
 * @return array<string,mixed>|null
 */
function drawdream_donor_active_child_subscription_for_child(mysqli $conn, int $donorUserId, int $childId): ?array
{
    if (!drawdream_donor_should_show_cancel_subscription($conn, $donorUserId, $childId)) {
        return null;
    }
    $st = $conn->prepare(
        "SELECT donate_id AS id, recurring_plan_code AS plan_code,
                recurring_next_charge_at AS next_charge_at, current_status AS status, amount_baht
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'active'
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('ii', $childId, $donorUserId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!is_array($row)) {
        return null;
    }
    $spec = drawdream_child_subscription_plan((string)($row['plan_code'] ?? ''));
    $row['amount_thb'] = (float)($row['amount_baht'] ?? 0);
    if ($row['amount_thb'] <= 0 && is_array($spec)) {
        $row['amount_thb'] = (float)($spec['amount_thb'] ?? 0);
    }

    return $row;
}

function drawdream_child_has_active_omise_subscription(mysqli $conn, int $childId, int $donorUserId): bool
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);

    return drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active';
}

function drawdream_child_has_any_active_subscription(mysqli $conn, int $childId): bool
{
    if ($childId <= 0) {
        return false;
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $st = $conn->prepare(
        "SELECT 1
         FROM child_subscription_history h
         INNER JOIN (
            SELECT donor_user_id, MAX(history_id) AS max_history_id
            FROM child_subscription_history
            WHERE child_id = ?
            GROUP BY donor_user_id
         ) x ON x.max_history_id = h.history_id
         WHERE LOWER(TRIM(COALESCE(h.current_status, ''))) = 'active'
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('i', $childId);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

/** ซ่อมประวัติ subscription จากรายการหักรอบที่บันทึกแล้ว (ทุกผู้บริจาคของเด็กคนนี้) */
function drawdream_repair_child_subscription_history_from_charges(mysqli $conn, int $childId): void
{
    if ($childId <= 0) {
        return;
    }
    $donorIds = [];
    $st = $conn->prepare(
        "SELECT DISTINCT donor_id AS donor_user_id
         FROM donation
         WHERE target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') IN ('child_subscription', 'child_subscription_charge')"
    );
    if ($st) {
        $st->bind_param('i', $childId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $donorUid = (int)($row['donor_user_id'] ?? 0);
            if ($donorUid > 0) {
                $donorIds[$donorUid] = true;
            }
        }
    }
    $stHist = $conn->prepare(
        'SELECT DISTINCT donor_user_id FROM child_subscription_history WHERE child_id = ?'
    );
    if ($stHist) {
        $stHist->bind_param('i', $childId);
        $stHist->execute();
        $resHist = $stHist->get_result();
        while ($row = $resHist->fetch_assoc()) {
            $donorUid = (int)($row['donor_user_id'] ?? 0);
            if ($donorUid > 0) {
                $donorIds[$donorUid] = true;
            }
        }
    }
    foreach (array_keys($donorIds) as $donorUid) {
        drawdream_repair_donor_subscription_history_if_missing($conn, (int)$donorUid, $childId);
    }
}

function drawdream_repair_donor_subscription_history_if_missing(
    mysqli $conn,
    int $donorUserId,
    int $childId
): void {
    if ($donorUserId <= 0 || $childId <= 0) {
        return;
    }
    if (function_exists('drawdream_child_subscription_history_backfill_active_row')) {
        if (drawdream_child_subscription_history_backfill_active_row($conn, $childId, $donorUserId)) {
            if (function_exists('drawdream_child_sync_sponsorship_status')) {
                require_once __DIR__ . '/child_sponsorship.php';
                drawdream_child_sync_sponsorship_status($conn, $childId);
            }
            return;
        }
    }
    if (drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active') {
        $stRow = $conn->prepare(
            "SELECT history_id, donate_id, recurring_schedule_id, recurring_next_charge_at,
                    omise_charge_id, current_status
             FROM child_subscription_history
             WHERE child_id = ? AND donor_user_id = ?
               AND LOWER(TRIM(COALESCE(current_status, ''))) = 'active'
             ORDER BY history_id DESC
             LIMIT 1"
        );
        if ($stRow) {
            $stRow->bind_param('ii', $childId, $donorUserId);
            $stRow->execute();
            $activeRow = $stRow->get_result()->fetch_assoc();
            if (
                is_array($activeRow)
                && function_exists('drawdream_child_subscription_history_row_needs_backfill')
                && !drawdream_child_subscription_history_row_needs_backfill($activeRow)
            ) {
                return;
            }
        } else {
            return;
        }
    }
    $latestStatus = drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId);
    if (in_array($latestStatus, ['cancelled', 'cancle', 'canceled'], true)) {
        return;
    }
    $chk = $conn->prepare(
        'SELECT 1 FROM child_subscription_history WHERE child_id = ? AND donor_user_id = ? LIMIT 1'
    );
    if ($chk) {
        $chk->bind_param('ii', $childId, $donorUserId);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) {
            return;
        }
    }
    $st = $conn->prepare(
        "SELECT donate_id, amount, omise_charge_id
         FROM donation
         WHERE donor_id = ? AND target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') = 'child_subscription_charge'
         ORDER BY donate_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return;
    }
    $st->bind_param('ii', $donorUserId, $childId);
    $st->execute();
    $don = $st->get_result()->fetch_assoc();
    if (!$don) {
        return;
    }
    $amountBaht = (float)($don['amount'] ?? 0);
    $planCode = 'monthly';
    if ($amountBaht >= 8000) {
        $planCode = 'yearly';
    } elseif ($amountBaht >= 4000) {
        $planCode = 'semiannual';
    }
    $donateId = (int)($don['donate_id'] ?? 0);
    $chargeId = trim((string)($don['omise_charge_id'] ?? ''));
    $localSchId = 'local_cron_repair_' . bin2hex(random_bytes(8));
    $repairPayload = ['next_charge_at' => ''];
    $planSpecRepair = drawdream_child_subscription_plan($planCode);
    if (is_array($planSpecRepair)) {
        $paidAnchor = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
        $paidRaw = trim((string)($don['transfer_datetime'] ?? ''));
        if ($paidRaw !== '') {
            try {
                $paidAnchor = new DateTimeImmutable($paidRaw, new DateTimeZone('Asia/Bangkok'));
            } catch (Exception $e) {
                // keep now
            }
        }
        $billDayRepair = drawdream_subscription_safe_bill_day($paidAnchor);
        $repairPayload['next_charge_at'] = drawdream_subscription_next_charge_at(
            $paidAnchor,
            $planSpecRepair,
            $billDayRepair
        )->format('Y-m-d H:i:s');
    }
    drawdream_child_subscription_history_log(
        $conn,
        $childId,
        $donorUserId,
        $donateId > 0 ? $donateId : null,
        $localSchId,
        $chargeId !== '' ? $chargeId : null,
        'subscription_created',
        null,
        'active',
        $planCode,
        $amountBaht > 0 ? $amountBaht : null,
        'profile_repair',
        'rebuilt_from_subscription_charge_donation',
        $repairPayload
    );
    if (function_exists('drawdream_child_sync_sponsorship_status')) {
        require_once __DIR__ . '/child_sponsorship.php';
        drawdream_child_sync_sponsorship_status($conn, $childId);
    }
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
    $sql = "SELECT DISTINCT target_id AS child_id
            FROM (
                SELECT h1.child_id AS target_id, h1.current_status
                FROM child_subscription_history h1
                INNER JOIN (
                    SELECT child_id, donor_user_id, MAX(history_id) AS max_history_id
                    FROM child_subscription_history
                    WHERE child_id IN ($ph)
                    GROUP BY child_id, donor_user_id
                ) latest ON latest.max_history_id = h1.history_id
            ) latest_status
            WHERE latest_status.current_status = ?";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $bindTypes = 's' . $types;
    $st->bind_param($bindTypes, $active, ...$ids);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['child_id']] = true;
    }
    return $out;
}

/**
 * เด็กที่มี subscription สถานะ active อย่างน้อย 1 ราย (batch สำหรับลบหลายโปรไฟล์)
 *
 * @param list<int> $childIds
 * @return array<int, true>
 */
function drawdream_child_ids_with_any_active_subscription_batch(mysqli $conn, array $childIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int)$x, $childIds), static fn ($x) => $x > 0)));
    if ($ids === []) {
        return [];
    }
    drawdream_child_omise_subscription_ensure_schema($conn);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT DISTINCT h.child_id
            FROM child_subscription_history h
            INNER JOIN (
                SELECT child_id, donor_user_id, MAX(history_id) AS max_history_id
                FROM child_subscription_history
                WHERE child_id IN ($ph)
                GROUP BY child_id, donor_user_id
            ) x ON x.max_history_id = h.history_id
            WHERE LOWER(TRIM(COALESCE(h.current_status, ''))) = 'active'";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $st->bind_param($types, ...$ids);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)($row['child_id'] ?? 0)] = true;
    }
    return $out;
}

/**
 * ตรวจเร็วก่อนเรียก Omise (ไม่เปิด transaction)
 *
 * @param array<string, mixed> $childRow
 * @return array{ok: bool, message: string, reason?: string}
 */
function drawdream_child_subscription_preflight_payment(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    array $childRow
): array {
    if ($childId <= 0 || $donorUserId <= 0) {
        return ['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'reason' => 'invalid'];
    }
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return ['ok' => false, 'message' => 'ไม่สามารถสมัครอุปการะได้ในขณะนี้', 'reason' => 'not_eligible'];
    }
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return [
            'ok' => false,
            'message' => 'เด็กคนนี้มีผู้อุปการะรายรอบแล้ว',
            'reason' => 'taken',
        ];
    }
    $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
    if ($holder > 0 && $holder !== $donorUserId) {
        return [
            'ok' => false,
            'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
            'reason' => 'reserving',
        ];
    }
    if (drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active') {
        return [
            'ok' => false,
            'message' => 'คุณมีแผนอุปการะ active กับเด็กคนนี้อยู่แล้ว',
            'reason' => 'already_active',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * หลัง Omise charge สำเร็จ — บันทึก donation + active ใน transaction เดียว (สั้นที่สุด)
 *
 * @return array{ok: bool, donate_id?: int, message?: string, reason?: string}
 */
function drawdream_child_subscription_commit_paid_first_charge(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    int $categoryId,
    float $amountBaht,
    string $chargeId,
    string $localSchId,
    string $nextSql,
    string $transferNowSql,
    string $planCode,
    string $cardId
): array {
    if ($childId <= 0 || $donorUserId <= 0 || $categoryId <= 0 || $chargeId === '') {
        return ['ok' => false, 'message' => 'ข้อมูลไม่ครบ', 'reason' => 'invalid'];
    }

    if (!$conn->begin_transaction()) {
        return ['ok' => false, 'message' => 'ระบบไม่พร้อม กรุณาลองใหม่', 'reason' => 'tx'];
    }

    try {
        $stLock = $conn->prepare('SELECT child_id FROM foundation_children WHERE child_id = ? FOR UPDATE');
        if (!$stLock) {
            throw new RuntimeException('lock_prepare');
        }
        $stLock->bind_param('i', $childId);
        $stLock->execute();
        if (!$stLock->get_result()->fetch_assoc()) {
            $conn->rollback();

            return ['ok' => false, 'message' => 'ไม่พบข้อมูลเด็ก', 'reason' => 'child_missing'];
        }

        if (drawdream_child_has_any_active_subscription($conn, $childId)) {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'เด็กคนนี้มีผู้อุปการะรายรอบแล้ว (มีผู้สมัครสำเร็จก่อนหน้า)',
                'reason' => 'taken',
            ];
        }

        $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
        if ($holder > 0 && $holder !== $donorUserId) {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
                'reason' => 'reserving',
            ];
        }

        $recurringType = 'child_subscription_charge';
        $completed = 'completed';
        $ins = $conn->prepare(
            'INSERT INTO donation (
                category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                omise_charge_id, donate_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('donation_insert_prepare');
        }
        $ins->bind_param(
            'iiidssss',
            $categoryId,
            $childId,
            $donorUserId,
            $amountBaht,
            $completed,
            $transferNowSql,
            $chargeId,
            $recurringType
        );
        if (!$ins->execute()) {
            throw new RuntimeException('donation_insert_execute');
        }
        $donateId = (int)$conn->insert_id;
        if ($donateId <= 0) {
            throw new RuntimeException('donation_insert_id');
        }

        if ($cardId !== '') {
            $updCard = $conn->prepare('UPDATE donor SET omise_card_id = ? WHERE user_id = ?');
            if ($updCard) {
                $updCard->bind_param('si', $cardId, $donorUserId);
                $updCard->execute();
            }
        }

        drawdream_child_subscription_history_ensure_schema($conn);
        $resId = drawdream_child_subscription_history_open_reservation_id($conn, $childId, $donorUserId);
        $promoted = false;
        if ($resId > 0) {
            $promoted = drawdream_child_subscription_history_promote_reservation(
                $conn,
                $resId,
                $donateId,
                $localSchId,
                $chargeId,
                'subscription_created',
                $planCode,
                $amountBaht,
                $nextSql
            );
        }
        if (!$promoted) {
            $eventType = 'subscription_created';
            $statusActive = 'active';
            $stHist = $conn->prepare(
                'INSERT INTO child_subscription_history (
                    child_id, donor_user_id, donate_id, recurring_schedule_id, recurring_next_charge_at,
                    omise_charge_id, event_type, current_status, recurring_plan_code, amount_baht, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            if (!$stHist) {
                throw new RuntimeException('history_insert_prepare');
            }
            $stHist->bind_param(
                'iiissssssd',
                $childId,
                $donorUserId,
                $donateId,
                $localSchId,
                $nextSql,
                $chargeId,
                $eventType,
                $statusActive,
                $planCode,
                $amountBaht
            );
            if (!$stHist->execute()) {
                throw new RuntimeException('history_insert_execute');
            }
        }

        $conn->commit();

        return ['ok' => true, 'donate_id' => $donateId];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[drawdream_child_sub] commit_paid_first_charge: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'บันทึกการอุปการะไม่สำเร็จ กรุณาติดต่อผู้ดูแล', 'reason' => 'error'];
    }
}

/**
 * รายการอุปการะเด็กที่ยัง active ของผู้บริจาค (หน้า profile / ประวัติบริจาค)
 *
 * @return list<array{id:int,child_id:int,plan_code:string,child_name:string,amount_thb:float}>
 */
function drawdream_donor_list_active_child_subscriptions(mysqli $conn, int $donorUserId): array
{
    if ($donorUserId <= 0) {
        return [];
    }
    drawdream_child_subscription_history_ensure_schema($conn);

    $childIds = [];
    $stHist = $conn->prepare(
        'SELECT DISTINCT child_id FROM child_subscription_history WHERE donor_user_id = ?'
    );
    if ($stHist) {
        $stHist->bind_param('i', $donorUserId);
        $stHist->execute();
        $res = $stHist->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (int)($row['child_id'] ?? 0);
            if ($cid > 0) {
                $childIds[$cid] = true;
            }
        }
    }

    $stDon = $conn->prepare(
        "SELECT DISTINCT target_id AS child_id
         FROM donation
         WHERE donor_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') IN ('child_subscription', 'child_subscription_charge')"
    );
    if ($stDon) {
        $stDon->bind_param('i', $donorUserId);
        $stDon->execute();
        $res = $stDon->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (int)($row['child_id'] ?? 0);
            if ($cid > 0) {
                $childIds[$cid] = true;
            }
        }
    }

    if ($childIds === []) {
        return [];
    }

    $out = [];
    $stActive = $conn->prepare(
        "SELECT donate_id AS id, child_id, recurring_plan_code AS plan_code, amount_baht
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'active'
         ORDER BY history_id DESC
         LIMIT 1"
    );
    $stName = $conn->prepare(
        'SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1'
    );

    foreach (array_keys($childIds) as $childId) {
        $childId = (int)$childId;
        if ($childId <= 0) {
            continue;
        }
        drawdream_repair_donor_subscription_history_if_missing($conn, $donorUserId, $childId);
        if (!drawdream_child_has_active_omise_subscription($conn, $childId, $donorUserId)) {
            continue;
        }

        $planCode = '';
        $donateId = 0;
        $amountBaht = 0.0;
        if ($stActive) {
            $stActive->bind_param('ii', $childId, $donorUserId);
            $stActive->execute();
            $sub = $stActive->get_result()->fetch_assoc();
            if (is_array($sub)) {
                $planCode = strtolower(trim((string)($sub['plan_code'] ?? '')));
                $donateId = (int)($sub['id'] ?? 0);
                $amountBaht = (float)($sub['amount_baht'] ?? 0);
            }
        }

        $childName = '';
        if ($stName) {
            $stName->bind_param('i', $childId);
            $stName->execute();
            $childName = trim((string)($stName->get_result()->fetch_assoc()['child_name'] ?? ''));
        }
        if ($childName === '') {
            $stDn = $conn->prepare(
                "SELECT fc.child_name
                 FROM donation d
                 INNER JOIN foundation_children fc ON fc.child_id = d.target_id
                 WHERE d.donor_id = ? AND d.target_id = ? AND d.payment_status = 'completed'
                 ORDER BY d.transfer_datetime DESC
                 LIMIT 1"
            );
            if ($stDn) {
                $stDn->bind_param('ii', $donorUserId, $childId);
                $stDn->execute();
                $childName = trim((string)($stDn->get_result()->fetch_assoc()['child_name'] ?? ''));
            }
        }

        $spec = drawdream_child_subscription_plan($planCode);
        if ($amountBaht <= 0 && is_array($spec)) {
            $amountBaht = (float)($spec['amount_thb'] ?? 0);
        }

        $out[] = [
            'id' => $donateId,
            'child_id' => $childId,
            'plan_code' => $planCode,
            'child_name' => $childName,
            'amount_thb' => $amountBaht,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp((string)($a['child_name'] ?? ''), (string)($b['child_name'] ?? ''));
    });

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

/** นาทีที่ถือว่าการจองสล็อต (reserving) ยังมีผล — กันสมัครพร้อมกันชนกัน */
function drawdream_child_subscription_reserve_ttl_minutes(): int
{
    return 10;
}

/** มีผู้จองสล็อตอุปการะรายรอบอยู่หรือไม่ (ยังไม่หมดเวลา) */
function drawdream_child_subscription_has_open_reservation(mysqli $conn, int $childId): bool
{
    return drawdream_child_subscription_reserving_holder_user_id($conn, $childId) > 0;
}

/** ผู้ใช้ที่กำลังจองสล็อตล่าสุด (0 = ไม่มี) */
function drawdream_child_subscription_reserving_holder_user_id(mysqli $conn, int $childId): int
{
    if ($childId <= 0) {
        return 0;
    }
    drawdream_child_subscription_history_ensure_schema($conn);
    $ttl = drawdream_child_subscription_reserve_ttl_minutes();
    $st = $conn->prepare(
        "SELECT h.donor_user_id
         FROM child_subscription_history h
         WHERE h.child_id = ?
           AND h.current_status = 'reserving'
           AND h.created_at >= (NOW() - INTERVAL ? MINUTE)
         ORDER BY h.history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('ii', $childId, $ttl);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!is_array($row)) {
        return 0;
    }

    return (int)($row['donor_user_id'] ?? 0);
}

/**
 * สถานะสล็อตอุปการะรายรอบสำหรับผู้บริจาค (ก่อนเปิดฟอร์มบัตร / หลัง POST)
 *
 * @return array{ok: bool, can_subscribe: bool, reason: string, message: string, holder_user_id?: int}
 */
function drawdream_child_subscription_slot_status(mysqli $conn, int $childId, int $donorUserId): array
{
    if ($childId <= 0 || $donorUserId <= 0) {
        return [
            'ok' => false,
            'can_subscribe' => false,
            'reason' => 'invalid',
            'message' => 'ข้อมูลไม่ถูกต้อง',
        ];
    }
    $st = $conn->prepare(
        'SELECT c.* FROM foundation_children c WHERE c.child_id = ? LIMIT 1'
    );
    if (!$st) {
        return [
            'ok' => false,
            'can_subscribe' => false,
            'reason' => 'db',
            'message' => 'ระบบไม่พร้อม กรุณาลองใหม่',
        ];
    }
    $st->bind_param('i', $childId);
    $st->execute();
    $child = $st->get_result()->fetch_assoc();
    if (!is_array($child)) {
        return [
            'ok' => true,
            'can_subscribe' => false,
            'reason' => 'child_missing',
            'message' => 'ไม่พบข้อมูลเด็ก',
        ];
    }
    if (drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active') {
        return [
            'ok' => true,
            'can_subscribe' => false,
            'reason' => 'already_active',
            'message' => '',
        ];
    }
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return [
            'ok' => true,
            'can_subscribe' => false,
            'reason' => 'taken',
            'message' => 'เด็กคนนี้มีผู้อุปการะรายรอบแล้ว',
        ];
    }
    $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
    if ($holder > 0 && $holder !== $donorUserId) {
        return [
            'ok' => true,
            'can_subscribe' => false,
            'reason' => 'reserving',
            'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
            'holder_user_id' => $holder,
        ];
    }
    if (!drawdream_child_can_start_omise_subscription($conn, $childId, $child, $donorUserId)) {
        return [
            'ok' => true,
            'can_subscribe' => false,
            'reason' => 'not_eligible',
            'message' => 'ไม่สามารถสมัครอุปการะได้ในขณะนี้',
        ];
    }

    return [
        'ok' => true,
        'can_subscribe' => true,
        'reason' => 'open',
        'message' => '',
    ];
}

/** UI รายรอบ: ล็อกถ้ามีผู้อุปการะแล้ว หรือมีคนอื่นกำลังจองสล็อต */
function drawdream_child_subscription_recurring_blocked_for_donor(mysqli $conn, int $childId, int $donorUserId): bool
{
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return true;
    }
    $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);

    return $holder > 0 && $holder !== $donorUserId;
}

/**
 * จองสล็อตก่อนชำระบัตร — ข้าม slot_status/can_start แบบลึก (UI + slot_check ตรวจแล้ว)
 * ยังใช้ transaction + FOR UPDATE และเช็ค active/reserving จริง
 *
 * @param array<string, mixed> $childRow แถว foundation_children ที่โหลดแล้ว
 * @return array{ok: bool, message: string, reason?: string}
 */
function drawdream_child_subscription_reserve_slot_for_payment(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    string $planCode,
    array $childRow
): array {
    if ($childId <= 0 || $donorUserId <= 0) {
        return ['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'reason' => 'invalid'];
    }
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return ['ok' => false, 'message' => 'ไม่สามารถสมัครอุปการะได้ในขณะนี้', 'reason' => 'not_eligible'];
    }

    if (!$conn->begin_transaction()) {
        return ['ok' => false, 'message' => 'ระบบไม่พร้อม กรุณาลองใหม่', 'reason' => 'tx'];
    }

    try {
        $st = $conn->prepare(
            'SELECT child_id FROM foundation_children WHERE child_id = ? FOR UPDATE'
        );
        if (!$st) {
            throw new RuntimeException('lock_prepare');
        }
        $st->bind_param('i', $childId);
        $st->execute();
        if (!$st->get_result()->fetch_assoc()) {
            $conn->rollback();

            return ['ok' => false, 'message' => 'ไม่พบข้อมูลเด็ก', 'reason' => 'child_missing'];
        }

        if (drawdream_child_has_any_active_subscription($conn, $childId)) {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'เด็กคนนี้มีผู้อุปการะรายรอบแล้ว (มีผู้สมัครสำเร็จก่อนหน้า)',
                'reason' => 'taken',
            ];
        }

        $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
        if ($holder > 0 && $holder !== $donorUserId) {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
                'reason' => 'reserving',
            ];
        }

        if (drawdream_child_donor_latest_subscription_status($conn, $childId, $donorUserId) === 'active') {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'คุณมีแผนอุปการะ active กับเด็กคนนี้อยู่แล้ว',
                'reason' => 'already_active',
            ];
        }

        if ($holder !== $donorUserId) {
            $planSpec = drawdream_child_subscription_plan($planCode);
            $amount = is_array($planSpec) ? (float)($planSpec['amount_thb'] ?? 0) : 0.0;
            drawdream_child_subscription_history_log(
                $conn,
                $childId,
                $donorUserId,
                null,
                null,
                null,
                'subscription_reserving',
                null,
                'reserving',
                $planCode,
                $amount > 0 ? $amount : null,
                'web_reserve',
                'slot_reserved'
            );
        }

        $conn->commit();

        return ['ok' => true, 'message' => ''];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[drawdream_child_sub] reserve_slot_for_payment: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'จองสิทธิ์อุปการะไม่สำเร็จ กรุณาลองใหม่', 'reason' => 'error'];
    }
}

/**
 * จองสล็อตอุปการะ (transaction + FOR UPDATE) — เรียกก่อนสร้าง Omise charge
 *
 * @return array{ok: bool, message: string, reason?: string}
 */
function drawdream_child_subscription_reserve_slot(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    string $planCode
): array {
    $status = drawdream_child_subscription_slot_status($conn, $childId, $donorUserId);
    if (!($status['can_subscribe'] ?? false)) {
        return [
            'ok' => false,
            'message' => (string)($status['message'] ?? 'ไม่สามารถจองสิทธิ์อุปการะได้'),
            'reason' => (string)($status['reason'] ?? 'blocked'),
        ];
    }

    if (!$conn->begin_transaction()) {
        return ['ok' => false, 'message' => 'ระบบไม่พร้อม กรุณาลองใหม่', 'reason' => 'tx'];
    }

    try {
        $st = $conn->prepare(
            'SELECT child_id FROM foundation_children WHERE child_id = ? FOR UPDATE'
        );
        if (!$st) {
            throw new RuntimeException('lock_prepare');
        }
        $st->bind_param('i', $childId);
        $st->execute();
        if (!$st->get_result()->fetch_assoc()) {
            $conn->rollback();

            return ['ok' => false, 'message' => 'ไม่พบข้อมูลเด็ก', 'reason' => 'child_missing'];
        }

        $stChild = $conn->prepare(
            'SELECT c.* FROM foundation_children c WHERE c.child_id = ? LIMIT 1'
        );
        $stChild->bind_param('i', $childId);
        $stChild->execute();
        $child = $stChild->get_result()->fetch_assoc();
        if (!is_array($child) || !drawdream_child_can_start_omise_subscription($conn, $childId, $child, $donorUserId)) {
            $conn->rollback();
            if (is_array($child) && drawdream_child_has_any_active_subscription($conn, $childId)) {
                return [
                    'ok' => false,
                    'message' => 'เด็กคนนี้มีผู้อุปการะรายรอบแล้ว (มีผู้สมัครสำเร็จก่อนหน้า)',
                    'reason' => 'taken',
                ];
            }
            $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
            if ($holder > 0 && $holder !== $donorUserId) {
                return [
                    'ok' => false,
                    'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
                    'reason' => 'reserving',
                ];
            }

            return ['ok' => false, 'message' => 'ไม่สามารถสมัครอุปการะได้ในขณะนี้', 'reason' => 'not_eligible'];
        }

        $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
        if ($holder > 0 && $holder !== $donorUserId) {
            $conn->rollback();

            return [
                'ok' => false,
                'message' => 'มีผู้บริจาครายอื่นกำลังสมัครอุปการะอยู่ กรุณารอสักครู่แล้วลองใหม่',
                'reason' => 'reserving',
            ];
        }

        if ($holder !== $donorUserId) {
            $planSpec = drawdream_child_subscription_plan($planCode);
            $amount = is_array($planSpec) ? (float)($planSpec['amount_thb'] ?? 0) : 0.0;
            drawdream_child_subscription_history_log(
                $conn,
                $childId,
                $donorUserId,
                null,
                null,
                null,
                'subscription_reserving',
                null,
                'reserving',
                $planCode,
                $amount > 0 ? $amount : null,
                'web_reserve',
                'slot_reserved'
            );
        }

        $conn->commit();
        drawdream_child_subscription_history_cleanup_stale_reservations($conn, $childId);

        return ['ok' => true, 'message' => ''];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[drawdream_child_sub] reserve_slot: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'จองสิทธิ์อุปการะไม่สำเร็จ กรุณาลองใหม่', 'reason' => 'error'];
    }
}

/** ปล่อยสล็อตเมื่อสมัครล้มเหลวหลังจองแล้ว */
function drawdream_child_subscription_release_slot(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    string $note = ''
): void {
    if ($childId <= 0 || $donorUserId <= 0) {
        return;
    }
    $holder = drawdream_child_subscription_reserving_holder_user_id($conn, $childId);
    if ($holder !== $donorUserId) {
        return;
    }
    drawdream_child_subscription_history_log(
        $conn,
        $childId,
        $donorUserId,
        null,
        null,
        null,
        'subscription_reserve_released',
        'reserving',
        'cancelled',
        null,
        null,
        'web_reserve',
        $note !== '' ? $note : 'slot_released'
    );
}
