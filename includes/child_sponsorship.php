<?php
// includes/child_sponsorship.php — อุปการะเด็กรายเดือน (ใช้ donation เป็นแหล่งข้อมูลเดียว)
// สรุปสั้น: คำนวณสถานะอุปการะเด็กและกติกายอดรายรอบที่ใช้ทั้งหน้าเด็กและหลังชำระเงิน
/**
 * อุปการะเด็กรายเดือน (ปฏิทิน Asia/Bangkok)
 *
 * - sum(donation หมวด child ที่ completed) ในช่วง [max(วันที่ 1 เดือนนี้, approve_at), เดือนถัดไป)
 * - >= เป้าหมายรายรอบของเด็ก (อิงแพ็กเกจจริง 700/4200/8400) = อุปการะครบในรอบเดือน
 * - ไม่นับยอดก่อน approve_at / anchor
 * - drawdream_child_can_receive_donation() หยุดรับเมื่อครบ threshold ในรอบนั้น
 *
 * @see README.md
 */

const DRAWDREAM_CHILD_DEFAULT_PLAN_AMOUNT = 700.0;

/** แมป plan_code -> จำนวนเงินต่อรอบตามแพ็กเกจ */
function drawdream_child_plan_amount_by_code(string $planCode): ?float
{
    $plan = strtolower(trim($planCode));
    if ($plan === 'monthly') {
        return 700.0;
    }
    if ($plan === 'semiannual') {
        return 4200.0;
    }
    if ($plan === 'yearly') {
        return 8400.0;
    }
    return null;
}

function drawdream_child_plan_months_by_code(string $planCode): int
{
    $plan = strtolower(trim($planCode));
    if ($plan === 'monthly') {
        return 1;
    }
    if ($plan === 'semiannual') {
        return 6;
    }
    if ($plan === 'yearly') {
        return 12;
    }
    return 0;
}

function drawdream_child_plan_code_from_amount(float $amountBaht): string
{
    $a = round($amountBaht, 2);
    if (abs($a - 700.0) < 0.01) {
        return 'monthly';
    }
    if (abs($a - 4200.0) < 0.01) {
        return 'semiannual';
    }
    if (abs($a - 8400.0) < 0.01) {
        return 'yearly';
    }
    return '';
}

/**
 * ช่วงสิทธิ์ของผู้บริจาครายคนสำหรับเด็กคนหนึ่ง (anniversary + stack)
 * @return array{current:bool,start:?DateTimeImmutable,end:?DateTimeImmutable}
 */
function drawdream_child_donor_plan_coverage_window(mysqli $conn, int $childId, int $donorUserId): array
{
    $out = ['current' => false, 'start' => null, 'end' => null];
    if ($childId <= 0 || $donorUserId <= 0) {
        return $out;
    }
    require_once __DIR__ . '/donate_category_resolve.php';
    $catId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($catId <= 0) {
        return $out;
    }
    $st = $conn->prepare(
        "SELECT amount, transfer_datetime, donate_id
         FROM donation
         WHERE category_id = ? AND target_id = ? AND donor_id = ? AND payment_status = 'completed'
          AND donate_type = 'child_subscription_charge'
         ORDER BY transfer_datetime ASC, donate_id ASC"
    );
    if (!$st) {
        return $out;
    }
    $st->bind_param('iii', $catId, $childId, $donorUserId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($rows === []) {
        return $out;
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTimeImmutable('now', $tz);
    $coverageStart = null;
    $coverageEnd = null;
    foreach ($rows as $r) {
        $planCode = drawdream_child_plan_code_from_amount((float)($r['amount'] ?? 0));
        $months = drawdream_child_plan_months_by_code($planCode);
        if ($months <= 0) {
            continue;
        }
        $dtRaw = trim((string)($r['transfer_datetime'] ?? ''));
        if ($dtRaw === '') {
            continue;
        }
        try {
            $paidAt = new DateTimeImmutable($dtRaw, $tz);
        } catch (Exception $e) {
            continue;
        }
        $coverageStart = ($coverageEnd === null || $paidAt >= $coverageEnd) ? $paidAt : $coverageEnd;
        $coverageEnd = $coverageStart->modify('+' . $months . ' months');
        if ($now >= $coverageStart && $now < $coverageEnd) {
            $out['current'] = true;
            $out['start'] = $coverageStart;
            $out['end'] = $coverageEnd;
            return $out;
        }
    }
    $out['start'] = $coverageStart;
    $out['end'] = $coverageEnd;
    return $out;
}

/**
 * คำนวณช่วงสิทธิ์อุปการะแบบเป๊ะตามวัน/เวลา (anniversary model)
 * - ใช้เวลาจ่ายจริงเป็น anchor ของสิทธิ์แต่ละรอบ
 * - ถ้าจ่ายซ้อนก่อนสิทธิ์เดิมหมด จะต่อช่วงจากปลายสิทธิ์เดิม (stack ต่อเนื่อง)
 * - คืนหน้าต่างสิทธิ์ล่าสุด [start, end) และบอกว่าปัจจุบันอยู่ในสิทธิ์หรือไม่
 *
 * @return array{current:bool,start:?DateTimeImmutable,end:?DateTimeImmutable,plan_code:string}
 */
function drawdream_child_plan_coverage_window(mysqli $conn, int $childId): array
{
    static $cache = [];
    if ($childId <= 0) {
        return ['current' => false, 'start' => null, 'end' => null, 'plan_code' => ''];
    }
    if (isset($cache[$childId])) {
        return $cache[$childId];
    }

    $out = ['current' => false, 'start' => null, 'end' => null, 'plan_code' => ''];
    require_once __DIR__ . '/donate_category_resolve.php';
    $catId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($catId <= 0) {
        $cache[$childId] = $out;

        return $out;
    }
    $st = $conn->prepare(
        "SELECT amount, transfer_datetime, donate_id
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') IN ('child_subscription', 'child_subscription_charge')
         ORDER BY transfer_datetime ASC, donate_id ASC"
    );
    if (!$st) {
        $cache[$childId] = $out;

        return $out;
    }
    $st->bind_param('ii', $catId, $childId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($rows === []) {
        $cache[$childId] = $out;

        return $out;
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTimeImmutable('now', $tz);
    $coverageStart = null;
    $coverageEnd = null;
    $activeNow = false;
    $activeStart = null;
    $activeEnd = null;
    $activePlanCode = '';
    foreach ($rows as $r) {
        $planCode = drawdream_child_plan_code_from_amount((float)($r['amount'] ?? 0));
        $months = drawdream_child_plan_months_by_code($planCode);
        if ($months <= 0) {
            continue;
        }
        $dtRaw = trim((string)($r['transfer_datetime'] ?? ''));
        if ($dtRaw === '') {
            continue;
        }
        try {
            $paidAt = new DateTimeImmutable($dtRaw, $tz);
        } catch (Exception $e) {
            continue;
        }
        if ($coverageEnd === null || $paidAt >= $coverageEnd) {
            $coverageStart = $paidAt;
        } else {
            $coverageStart = $coverageEnd;
        }
        $coverageEnd = $coverageStart->modify('+' . $months . ' months');
        if ($now >= $coverageStart && $now < $coverageEnd) {
            $activeNow = true;
            $activeStart = $coverageStart;
            $activeEnd = $coverageEnd;
            $activePlanCode = $planCode;
        }
    }
    if ($coverageStart !== null && $coverageEnd !== null && $activeStart === null) {
        $activeStart = $coverageStart;
        $activeEnd = $coverageEnd;
        $activePlanCode = drawdream_child_plan_code_from_amount((float)($rows[count($rows) - 1]['amount'] ?? 0));
    }
    $out['current'] = $activeNow;
    $out['start'] = $activeStart;
    $out['end'] = $activeEnd;
    $out['plan_code'] = $activePlanCode;
    $cache[$childId] = $out;

    return $out;
}

function drawdream_child_has_plan_coverage_now(mysqli $conn, int $childId): bool
{
    $w = drawdream_child_plan_coverage_window($conn, $childId);
    return (bool)($w['current'] ?? false);
}

/**
 * เป้าหมายยอดรอบปัจจุบันของเด็ก:
 * - ใช้แพ็กเกจล่าสุดของเด็กจาก donation.recurring_* (active/cancelled/paused)
 * - ถ้าไม่มีประวัติแพ็กเกจ ใช้ค่าเริ่มต้นรายเดือน 700 บาท
 */
function drawdream_child_cycle_target_amount(mysqli $conn, int $childId): float
{
    if ($childId <= 0) {
        return DRAWDREAM_CHILD_DEFAULT_PLAN_AMOUNT;
    }
    $st = $conn->prepare(
        "SELECT recurring_plan_code AS plan_code
         FROM child_subscription_history
         WHERE child_id = ?
           AND recurring_plan_code IS NOT NULL
           AND TRIM(recurring_plan_code) <> ''
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return DRAWDREAM_CHILD_DEFAULT_PLAN_AMOUNT;
    }
    $st->bind_param('i', $childId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return DRAWDREAM_CHILD_DEFAULT_PLAN_AMOUNT;
    }
    $mapped = drawdream_child_plan_amount_by_code((string)($row['plan_code'] ?? ''));
    if ($mapped !== null) {
        return $mapped;
    }
    return DRAWDREAM_CHILD_DEFAULT_PLAN_AMOUNT;
}

function drawdream_child_sponsorship_ensure_columns(mysqli $conn): void
{
    // foundation_children ใช้ approve_at ตามสคีมาปัจจุบัน — ไม่สร้างคอลัมน์เสริม
}

/**
 * เดือนปฏิทินปัจจุบัน (Asia/Bangkok): วันที่ 1 00:00 ถึงก่อนวันที่ 1 เดือนถัดไป
 * คืน [effectiveStart, monthEnd) โดย effectiveStart = วันที่เริ่มนับยอด (ไม่ก่อน anchor)
 */
function drawdream_child_current_cycle_bounds(?string $anchorSql): ?array
{
    if ($anchorSql === null || trim($anchorSql) === '') {
        return null;
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    try {
        $anchor = new DateTimeImmutable($anchorSql, $tz);
    } catch (Exception $e) {
        return null;
    }
    $now = new DateTimeImmutable('now', $tz);
    if ($anchor > $now) {
        return null;
    }

    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = $monthStart->modify('+1 month');

    if ($anchor >= $monthEnd) {
        return null;
    }

    $effectiveStart = ($anchor > $monthStart) ? $anchor : $monthStart;

    return [$effectiveStart, $monthEnd];
}

function drawdream_child_anchor_datetime(array $childRow): ?string
{
    $ap = trim((string)($childRow['approve_at'] ?? ''));
    return $ap !== '' ? $ap : null;
}

/** ยอดบริจาคในเดือนปฏิทินปัจจุบัน (หลัง anchor ตาม effectiveStart) */
function drawdream_child_cycle_total(mysqli $conn, int $childId, array $childRow): float
{
    static $cache = [];
    drawdream_child_sponsorship_ensure_columns($conn);
    $anchor = drawdream_child_anchor_datetime($childRow);
    if ($anchor === null) {
        return 0.0;
    }
    $bounds = drawdream_child_current_cycle_bounds($anchor);
    if ($bounds === null) {
        return 0.0;
    }
    [$effectiveStart, $monthEnd] = $bounds;
    $cacheKey = $childId . '|' . $effectiveStart->format('Y-m-d H:i:s') . '|' . $monthEnd->format('Y-m-d H:i:s');
    if (isset($cache[$cacheKey])) {
        return (float)$cache[$cacheKey];
    }
    require_once __DIR__ . '/donate_category_resolve.php';
    require_once __DIR__ . '/donate_type.php';
    $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($childCategoryId <= 0) {
        return 0.0;
    }
    $startStr = $effectiveStart->format('Y-m-d H:i:s');
    $endStr = $monthEnd->format('Y-m-d H:i:s');
    $dtOne = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS t
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = \'completed\'
           AND transfer_datetime >= ? AND transfer_datetime < ?
           AND COALESCE(donate_type, \'\') <> ?'
    );
    $stmt->bind_param('iisss', $childCategoryId, $childId, $startStr, $endStr, $dtOne);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $cache[$cacheKey] = (float)($r['t'] ?? 0);

    return (float)$cache[$cacheKey];
}

/**
 * ยอดสะสม “ทุนการศึกษา” จากบริจาครายวัน (PromptPay, donate_type = child_one_time) — นับยอดเต็มต่อครั้ง
 * รายการเก่าที่ไม่มี donate_type (ไม่ใช่ subscription) ยังใช้กฎส่วนเกิน 700 บ./ครั้ง เพื่อความต่อเนื่อง
 */
function drawdream_child_education_fund_total_thb(mysqli $conn, int $childId): float
{
    if ($childId <= 0) {
        return 0.0;
    }
    require_once __DIR__ . '/payment_transaction_schema.php';
    drawdream_payment_transaction_ensure_schema($conn);
    require_once __DIR__ . '/donate_category_resolve.php';
    require_once __DIR__ . '/donate_type.php';
    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($categoryId <= 0) {
        return 0.0;
    }
    $dtOne = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
    $st = $conn->prepare(
        'SELECT COALESCE(SUM(
            CASE
                WHEN donate_type = ? THEN amount
                WHEN COALESCE(donate_type, \'\') IN (\'child_subscription\', \'child_subscription_charge\') THEN 0
                WHEN amount > 700 THEN amount - 700
                ELSE 0
            END
        ), 0) AS t
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = \'completed\''
    );
    if (!$st) {
        return 0.0;
    }
    $st->bind_param('sii', $dtOne, $categoryId, $childId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();

    return (float)($r['t'] ?? 0);
}

/**
 * @param list<array<string,mixed>> $rows แต่ละแถวต้องมี child_id (+ approve_at เมื่อมี)
 * @return array<int,float> child_id => ยอดในรอบปัจจุบัน
 */
function drawdream_child_cycle_totals_batch(mysqli $conn, array $rows): array
{
    drawdream_child_sponsorship_ensure_columns($conn);
    if ($rows === []) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $cid = (int)($row['child_id'] ?? 0);
        if ($cid <= 0 || array_key_exists($cid, $out)) {
            continue;
        }
        $out[$cid] = drawdream_child_cycle_total($conn, $cid, $row);
    }
    return $out;
}

function drawdream_child_is_cycle_sponsored(mysqli $conn, int $childId, array $childRow): bool
{
    /* ยกเลิกแผนแล้วยังอยู่ในช่วงสิทธิ์ที่จ่ายไปแล้ว (รายเดือน / 6 เดือน / รายปี) ยังถือว่ามีอุปการะครบรอบ */
    if (drawdream_child_has_plan_coverage_now($conn, $childId)) {
        return true;
    }
    $target = drawdream_child_cycle_target_amount($conn, $childId);
    return drawdream_child_cycle_total($conn, $childId, $childRow) >= $target;
}

/** @deprecated ใช้ drawdream_child_is_cycle_sponsored */
function drawdream_child_is_month_sponsored(mysqli $conn, int $childId, array $childRow = []): bool
{
    if ($childRow === []) {
        $st = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
        $st->bind_param('i', $childId);
        $st->execute();
        $childRow = $st->get_result()->fetch_assoc() ?: [];
    }
    return drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

function drawdream_child_can_receive_donation(mysqli $conn, int $childId, array $childRow): bool
{
    if (!drawdream_child_can_receive_daily_donation($conn, $childId, $childRow)) {
        return false;
    }
    require_once __DIR__ . '/child_omise_subscription.php';
    if (drawdream_child_has_any_active_subscription($conn, $childId)) {
        return false;
    }
    return !drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

/** บริจาครายวัน (PromptPay ครั้งเดียว) — เปิดได้แม้มีผู้อุปการะรายรอบแล้ว */
function drawdream_child_can_receive_daily_donation(mysqli $conn, int $childId, array $childRow): bool
{
    $ap = $childRow['approve_profile'] ?? '';
    return in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true);
}

/** อุปการะครบยอดในเดือนปฏิทินปัจจุบัน (โปรไฟล์อนุมัติหรือกำลังดำเนินการ + ยอดรอบเดือน >= threshold) */
function drawdream_child_is_monthly_fully_sponsored(mysqli $conn, int $childId, array $childRow): bool
{
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    return drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

/**
 * เด็กอยู่ในโซน "มีผู้อุปการะ" สาธารณะ:
 * - มีแผน Omise (รายเดือน / 6 เดือน / รายปี) ที่สถานะในประวัติยัง active หรือ
 * - แผนถูกยกเลิกแล้วแต่ยังอยู่ในช่วงสิทธิ์ตามระยะที่จ่ายไปแล้ว (คำนวณจาก drawdream_child_plan_coverage_window)
 * ไม่นับแค่บริจาคครั้งเดียว (รายวัน PromptPay) ที่ครบเกณฑ์รอบเดือนโดยไม่มีแผนรายรอบ
 *
 * @param array<int, true> $planSponsoredMap จาก drawdream_child_ids_with_active_plan_sponsorship()
 */
function drawdream_child_is_showcase_sponsored(
    mysqli $conn,
    int $childId,
    array $childRow,
    float $cycleAmountInMonth,
    array $planSponsoredMap
): bool {
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ'], true)) {
        return false;
    }
    if (!empty($planSponsoredMap[$childId])) {
        return true;
    }
    return drawdream_child_has_plan_coverage_now($conn, $childId);
}

/**
 * รายชื่อผู้อุปการะแบบรายรอบ (Omise: รายเดือน / 6 เดือน / รายปี) เท่านั้น — ไม่รวมบริจาคครั้งเดียว
 * ยกเลิกแล้วยังแสดงชื่อ พร้อมป้าย "เคยอุปการะ"
 *
 * @return array{rows: list<array{name:string,status:string,status_label:string,detail_line:string,sort_ts:int}>, active_recurring_count:int}
 */
function drawdream_child_foundation_sponsor_display_list(mysqli $conn, int $childId, int $childCategoryId): array
{
    if ($childId <= 0 || $childCategoryId <= 0) {
        return ['rows' => [], 'active_recurring_count' => 0];
    }

    $planLabels = [
        'monthly' => 'รายเดือน',
        'semiannual' => 'ราย 6 เดือน',
        'yearly' => 'รายปี',
    ];

    $latestSubByDonor = [];
    $st = $conn->prepare(
        "SELECT donor_user_id, current_status, recurring_plan_code, donate_id, recurring_next_charge_at, created_at
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id IS NOT NULL
         ORDER BY history_id DESC"
    );
    if ($st) {
        $st->bind_param('i', $childId);
        $st->execute();
        $rs = $st->get_result();
        while ($row = $rs->fetch_assoc()) {
            $uid = (int)($row['donor_user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if (!isset($latestSubByDonor[$uid])) {
                $latestSubByDonor[$uid] = $row;
            }
        }
    }

    $allIds = array_keys($latestSubByDonor);
    sort($allIds);
    if ($allIds === []) {
        return ['rows' => [], 'active_recurring_count' => 0];
    }

    $nameMap = [];
    $ph = implode(',', array_fill(0, count($allIds), '?'));
    $types = str_repeat('i', count($allIds));
    $stn = $conn->prepare("SELECT user_id, first_name, last_name FROM donor WHERE user_id IN ($ph)");
    if ($stn) {
        $stn->bind_param($types, ...$allIds);
        $stn->execute();
        $rn = $stn->get_result();
        while ($dn = $rn->fetch_assoc()) {
            $uid = (int)($dn['user_id'] ?? 0);
            $nameMap[$uid] = trim((string)($dn['first_name'] ?? '') . ' ' . (string)($dn['last_name'] ?? ''));
        }
    }

    $activeRecurring = 0;
    $rows = [];
    foreach ($allIds as $uid) {
        if ($uid <= 0) {
            continue;
        }
        $name = trim((string)($nameMap[$uid] ?? ''));
        if ($name === '') {
            $name = 'ผู้บริจาค (ไม่ระบุชื่อ)';
        }

        $subRow = $latestSubByDonor[$uid] ?? null;

        $subTs = 0;
        $coverage = drawdream_child_donor_plan_coverage_window($conn, $childId, $uid);
        $coverageEndTs = 0;
        if (($coverage['end'] ?? null) instanceof DateTimeImmutable) {
            $coverageEndTs = (int)$coverage['end']->format('U');
        }
        if (is_array($subRow)) {
            $raw = trim((string)($subRow['recurring_next_charge_at'] ?? ''));
            if ($raw === '') {
                $raw = trim((string)($subRow['created_at'] ?? ''));
            }
            if ($raw !== '') {
                $ts = strtotime($raw);
                $subTs = $ts !== false ? $ts : 0;
            }
        }
        $sortTs = max($subTs, $coverageEndTs);

        $status = 'other';
        $statusLabel = '';
        $detailLine = '';

        if (is_array($subRow)) {
            $rs = strtolower(trim((string)($subRow['current_status'] ?? '')));
            $pc = strtolower(trim((string)($subRow['recurring_plan_code'] ?? '')));
            $planText = $planLabels[$pc] ?? ($pc !== '' ? $pc : 'รายรอบ');

            if ($rs === 'active' || !empty($coverage['current'])) {
                $status = 'active';
                $activeRecurring++;
                $statusLabel = 'กำลังอุปการะ — ' . $planText;
                if (in_array($rs, ['cancelled', 'cancle', 'canceled'], true) && !empty($coverage['current']) && ($coverage['end'] ?? null) instanceof DateTimeImmutable) {
                    $detailLine = 'ยกเลิกการต่ออายุแล้ว · สิทธิ์ถึง ' . $coverage['end']->format('d/m/Y H:i');
                } else {
                    $detailLine = $subTs > 0 ? ('อัปเดตแผนล่าสุด: ' . date('d/m/Y H:i', $subTs)) : '';
                }
            } elseif (in_array($rs, ['cancelled', 'cancle', 'canceled'], true)) {
                $status = 'cancelled';
                $statusLabel = 'เคยอุปการะ — ' . $planText . ' · ยกเลิกแล้ว';
                $detailLine = $subTs > 0 ? ('อัปเดตสถานะล่าสุด: ' . date('d/m/Y H:i', $subTs)) : '';
            } else {
                $status = 'other';
                $statusLabel = 'แผนอุปการะ (' . $rs . ') — ' . $planText;
                $detailLine = $subTs > 0 ? ('อัปเดตล่าสุด: ' . date('d/m/Y H:i', $subTs)) : '';
            }
        }

        $rows[] = [
            'name' => $name,
            'status' => $status,
            'status_label' => $statusLabel,
            'detail_line' => $detailLine,
            'sort_ts' => $sortTs,
        ];
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            $cmp = ($b['sort_ts'] <=> $a['sort_ts']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        }
    );

    return [
        'rows' => $rows,
        'active_recurring_count' => $activeRecurring,
    ];
}

function drawdream_child_outcome_ensure_columns(mysqli $conn): void
{
    // ผลลัพธ์เด็ก: update_text, update_images, update_at
}

/**
 * @return list<string> ชื่อไฟล์รูปผลลัพธ์เด็ก (basename เท่านั้น)
 */
function drawdream_child_outcome_images_parse(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $x) {
        $b = basename((string)$x);
        if ($b !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $b) === 1) {
            $out[] = $b;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $basenames
 */
function drawdream_child_outcome_images_json(array $basenames): string
{
    $clean = [];
    foreach ($basenames as $x) {
        $b = basename((string)$x);
        if ($b !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $b) === 1) {
            $clean[] = $b;
        }
    }
    $clean = array_values(array_unique($clean));

    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function drawdream_child_outcome_image_url(string $filename): string
{
    $safe = basename($filename);
    if ($safe === '') {
        return '';
    }
    if (is_file(__DIR__ . '/../uploads/evidence/' . $safe)) {
        return 'uploads/evidence/' . $safe;
    }
    return 'uploads/childern/' . $safe;
}

/**
 * HTML แกลเลอรีรูปผลลัพธ์ (path สัมพันธ์จากรากเว็บ)
 *
 * @param list<string> $basenames
 */
function drawdream_child_outcome_images_html(array $basenames): string
{
    if ($basenames === []) {
        return '';
    }
    $html = '<div class="child-outcome-public__gallery">';
    foreach ($basenames as $fn) {
        $safe = htmlspecialchars($fn, ENT_QUOTES, 'UTF-8');
        $imgUrl = htmlspecialchars(drawdream_child_outcome_image_url($fn), ENT_QUOTES, 'UTF-8');
        $html .= '<a class="child-outcome-public__gallery-item" href="' . $imgUrl . '" target="_blank" rel="noopener">';
        $html .= '<img src="' . $imgUrl . '" alt="" loading="lazy" decoding="async">';
        $html .= '</a>';
    }
    $html .= '</div>';

    return $html;
}

function drawdream_child_total_donations(mysqli $conn, int $childId): float
{
    require_once __DIR__ . '/donate_category_resolve.php';
    $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($childCategoryId <= 0) {
        return 0.0;
    }
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS t
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = \'completed\''
    );
    $stmt->bind_param('ii', $childCategoryId, $childId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['t'] ?? 0);
}

/**
 * ยอดรวม donation หมวด child หลาย child_id (ใช้หน้ารายการมูลนิธิ)
 *
 * @param list<int> $childIds
 * @return array<int,float>
 */
function drawdream_child_donation_totals_batch(mysqli $conn, array $childIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int)$x, $childIds), static fn ($x) => $x > 0)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = 0.0;
    }
    if ($ids === []) {
        return $out;
    }
    require_once __DIR__ . '/donate_category_resolve.php';
    $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($childCategoryId <= 0) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT target_id AS child_id, COALESCE(SUM(amount), 0) AS t
         FROM donation
         WHERE category_id = ? AND payment_status = 'completed' AND target_id IN ($ph)
         GROUP BY target_id"
    );
    if (!$stmt) {
        return $out;
    }
    $bindTypes = 'i' . $types;
    $stmt->bind_param($bindTypes, $childCategoryId, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['child_id']] = (float)($row['t'] ?? 0);
    }
    return $out;
}

function drawdream_child_sync_sponsorship_status(mysqli $conn, int $childId): void
{
    drawdream_child_sponsorship_ensure_columns($conn);
    $st = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
    $st->bind_param('i', $childId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }
    if (!function_exists('drawdream_child_has_any_active_subscription')) {
        require_once __DIR__ . '/child_omise_subscription.php';
    }
    if (function_exists('drawdream_repair_child_subscription_history_from_charges')) {
        drawdream_repair_child_subscription_history_from_charges($conn, $childId);
    }
    // สถานะคอลัมน์ status = มีผู้อุปการะแพ็กเกจรายรอบ (รายเดือน / 6 เดือน / รายปี)
    $hasActiveSub = function_exists('drawdream_child_has_any_active_subscription')
        ? drawdream_child_has_any_active_subscription($conn, $childId)
        : false;
    $hasCoverage = drawdream_child_has_plan_coverage_now($conn, $childId);
    $status = ($hasActiveSub || $hasCoverage) ? 'อุปการะแล้ว' : 'รออุปการะ';
    $stmt = $conn->prepare('UPDATE foundation_children SET status = ? WHERE child_id = ?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('si', $status, $childId);
    $stmt->execute();
}

/**
 * สถานะอุปการะเพื่อแสดงใน UI ฝั่งมูลนิธิ/แอดมิน (ยึดแพ็กเกจรายรอบจริง)
 *
 * @return array{code:string,label:string,detail:string,next_open_at:?DateTimeImmutable}
 */
function drawdream_child_sponsorship_ui_status(mysqli $conn, int $childId): array
{
    $out = ['code' => 'waiting', 'label' => 'รออุปการะ', 'detail' => '', 'next_open_at' => null];
    if ($childId <= 0) {
        return $out;
    }

    $latest = null;
    $st = $conn->prepare(
        "SELECT current_status, recurring_plan_code, recurring_next_charge_at, created_at
         FROM child_subscription_history
         WHERE child_id = ?
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if ($st) {
        $st->bind_param('i', $childId);
        $st->execute();
        $latest = $st->get_result()->fetch_assoc() ?: null;
    }

    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTimeImmutable('now', $tz);
    $coverage = drawdream_child_plan_coverage_window($conn, $childId);
    $coverageEnd = (($coverage['end'] ?? null) instanceof DateTimeImmutable) ? $coverage['end'] : null;
    $hasCoverageNow = !empty($coverage['current']);
    $latestStatus = strtolower(trim((string)($latest['current_status'] ?? '')));

    if (in_array($latestStatus, ['cancelled', 'cancle', 'canceled'], true)) {
        $out['code'] = 'cancelled';
        $out['label'] = 'ยกเลิกแล้ว';
        if ($coverageEnd instanceof DateTimeImmutable && $coverageEnd > $now) {
            $out['next_open_at'] = $coverageEnd;
            $out['detail'] = 'เปิดอุปการะรอบถัดไปได้วันที่ ' . $coverageEnd->format('d/m/Y H:i');
        } else {
            $out['detail'] = 'สามารถเริ่มอุปการะใหม่ได้ทันที';
        }
        return $out;
    }

    if ($hasCoverageNow) {
        $out['code'] = 'active';
        $out['label'] = 'อุปการะแล้ว';
        if ($coverageEnd instanceof DateTimeImmutable) {
            $out['detail'] = 'สิทธิ์ปัจจุบันถึง ' . $coverageEnd->format('d/m/Y H:i');
        }
        return $out;
    }

    return $out;
}

/**
 * รายชื่อ donor_user_id ที่ถือเป็น "ผู้อุปการะปัจจุบัน" ของเด็ก
 * - มีสถานะ active ในตารางประวัติอุปการะ หรือ
 * - ยกเลิกแล้วแต่ยังอยู่ในช่วงสิทธิ์ตามระยะที่ชำระจริง (coverage ยังไม่หมด)
 *
 * @return array<int,true> key = donor_user_id
 */
function drawdream_child_current_sponsor_user_ids(mysqli $conn, int $childId): array
{
    $out = [];
    if ($childId <= 0) {
        return $out;
    }

    $stHist = $conn->prepare(
        "SELECT donor_user_id, current_status
         FROM child_subscription_history
         WHERE child_id = ?
         ORDER BY history_id DESC"
    );
    if (!$stHist) {
        return $out;
    }
    $stHist->bind_param('i', $childId);
    $stHist->execute();
    $rows = $stHist->get_result()->fetch_all(MYSQLI_ASSOC);
    $latestByDonor = [];
    foreach ($rows as $r) {
        $uid = (int)($r['donor_user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        if (!isset($latestByDonor[$uid])) {
            $latestByDonor[$uid] = strtolower(trim((string)($r['current_status'] ?? '')));
        }
    }
    foreach ($latestByDonor as $uid => $st) {
        if ($st === 'active') {
            $out[(int)$uid] = true;
            continue;
        }
        $coverage = drawdream_child_donor_plan_coverage_window($conn, $childId, (int)$uid);
        if (!empty($coverage['current'])) {
            $out[(int)$uid] = true;
        }
    }

    return $out;
}

/**
 * เด็กมีผู้อุปการะที่ต้องได้รับข้อความอัปเดต (แผนรายรอบ / สิทธิ์ coverage / ครบยอดรอบเดือน)
 */
function drawdream_child_has_active_sponsor_for_outcome(mysqli $conn, int $childId, array $childRow): bool
{
    if ($childId <= 0) {
        return false;
    }
    $ap = (string)($childRow['approve_profile'] ?? '');
    if (!in_array($ap, ['อนุมัติ', 'กำลังดำเนินการ', 'approved', 'อนุมัติแล้ว'], true)) {
        return false;
    }
    if (drawdream_child_current_sponsor_user_ids($conn, $childId) !== []) {
        return true;
    }
    if (!function_exists('drawdream_child_has_any_active_subscription')) {
        require_once __DIR__ . '/child_omise_subscription.php';
    }
    if (function_exists('drawdream_child_has_any_active_subscription')
        && drawdream_child_has_any_active_subscription($conn, $childId)) {
        return true;
    }
    if (drawdream_child_has_plan_coverage_now($conn, $childId)) {
        return true;
    }

    return drawdream_child_is_cycle_sponsored($conn, $childId, $childRow);
}

/**
 * วันที่เริ่มมีผู้อุปการะครั้งแรก (ใช้เป็นจุดเริ่มนับรอบ 1 เดือน)
 */
function drawdream_child_sponsorship_started_at(mysqli $conn, int $childId, array $childRow = []): ?DateTimeImmutable
{
    if ($childId <= 0) {
        return null;
    }
    $tz = new DateTimeZone('Asia/Bangkok');
    $candidates = [];

    $stSub = $conn->prepare(
        'SELECT MIN(created_at) AS ts FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id IS NOT NULL AND donor_user_id > 0'
    );
    if ($stSub) {
        $stSub->bind_param('i', $childId);
        $stSub->execute();
        $ts = trim((string)($stSub->get_result()->fetch_assoc()['ts'] ?? ''));
        if ($ts !== '') {
            $candidates[] = $ts;
        }
    }

    require_once __DIR__ . '/donate_category_resolve.php';
    $catId = drawdream_get_or_create_child_donate_category_id($conn);
    if ($catId > 0) {
        $stCharge = $conn->prepare(
            "SELECT MIN(transfer_datetime) AS ts FROM donation
             WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'
               AND donate_type = 'child_subscription_charge'"
        );
        if ($stCharge) {
            $stCharge->bind_param('ii', $catId, $childId);
            $stCharge->execute();
            $ts = trim((string)($stCharge->get_result()->fetch_assoc()['ts'] ?? ''));
            if ($ts !== '') {
                $candidates[] = $ts;
            }
        }

        $target = drawdream_child_cycle_target_amount($conn, $childId);
        if ($target > 0) {
            $stDon = $conn->prepare(
                "SELECT amount, transfer_datetime FROM donation
                 WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'
                 ORDER BY transfer_datetime ASC, donate_id ASC"
            );
            if ($stDon) {
                $stDon->bind_param('ii', $catId, $childId);
                $stDon->execute();
                $sum = 0.0;
                $rs = $stDon->get_result();
                while ($row = $rs->fetch_assoc()) {
                    $sum += (float)($row['amount'] ?? 0);
                    if ($sum >= $target - 0.01) {
                        $ts = trim((string)($row['transfer_datetime'] ?? ''));
                        if ($ts !== '') {
                            $candidates[] = $ts;
                        }
                        break;
                    }
                }
            }
        }
    }

    if ($candidates === []) {
        if ($childRow === []) {
            $st = $conn->prepare('SELECT approve_at FROM foundation_children WHERE child_id = ? LIMIT 1');
            if ($st) {
                $st->bind_param('i', $childId);
                $st->execute();
                $childRow = $st->get_result()->fetch_assoc() ?: [];
            }
        }
        $ap = trim((string)($childRow['approve_at'] ?? ''));
        if ($ap !== '') {
            $candidates[] = $ap;
        }
    }

    if ($candidates === []) {
        return null;
    }

    $best = null;
    foreach ($candidates as $raw) {
        try {
            $dt = new DateTimeImmutable($raw, $tz);
        } catch (Exception $e) {
            continue;
        }
        if ($best === null || $dt < $best) {
            $best = $dt;
        }
    }

    return $best;
}

/** ต้นรอบเดือนปัจจุบัน (ครบรอบทุก 1 เดือนนับจากวันเริ่มอุปการะ) */
function drawdream_child_outcome_period_start(DateTimeImmutable $sponsorStarted, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now = $now ?? new DateTimeImmutable('now', $sponsorStarted->getTimezone());
    $periodStart = $sponsorStarted;
    $next = $periodStart->modify('+1 month');
    while ($next <= $now) {
        $periodStart = $next;
        $next = $periodStart->modify('+1 month');
    }

    return $periodStart;
}

/** ถึงกำหนดอัปเดตข้อความเด็กในรอบ 1 เดือนปัจจุบัน (มีผู้อุปการะ + ยังไม่โพสต์ในรอบนี้) */
function drawdream_child_outcome_update_is_due(mysqli $conn, int $childId, array $childRow): bool
{
    if (!drawdream_child_has_active_sponsor_for_outcome($conn, $childId, $childRow)) {
        return false;
    }
    $started = drawdream_child_sponsorship_started_at($conn, $childId, $childRow);
    if (!$started instanceof DateTimeImmutable) {
        return false;
    }
    $periodStart = drawdream_child_outcome_period_start($started);
    $updateRaw = trim((string)($childRow['update_at'] ?? ''));
    if ($updateRaw === '') {
        return true;
    }
    try {
        $updated = new DateTimeImmutable($updateRaw, $periodStart->getTimezone());
    } catch (Exception $e) {
        return true;
    }

    return $updated < $periodStart;
}

function drawdream_foundation_count_children_outcome_due(mysqli $conn, int $foundationId): int
{
    if ($foundationId <= 0) {
        return 0;
    }
    $st = $conn->prepare(
        'SELECT child_id, approve_profile, approve_at, update_at, update_text
         FROM foundation_children
         WHERE foundation_id = ?'
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        $cid = (int)($row['child_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        if (drawdream_child_outcome_update_is_due($conn, $cid, $row)) {
            $count++;
        }
    }

    return $count;
}

/**
 * ลบข้อมูลที่อ้างอิง child_id ก่อนลบแถว foundation_children
 * (donation ทุกหมวดที่ child_donate ไม่ว่าง, admin audit, notifications ที่ลิงก์ถึงเด็ก)
 *
 * หมายเหตุ: AUTO_INCREMENT ของแต่ละตารางใน MySQL จะนับต่อจากค่าสูงสุดที่เคยมี — ไม่มีการรีใบเลขย้อนเติมช่องว่าง (เป็นมาตรฐานที่ปลอดภัย)
 */
function drawdream_purge_child_related_data(mysqli $conn, int $childId): void
{
    if ($childId <= 0) {
        return;
    }

    if (is_file(__DIR__ . '/child_outcome_history.php')) {
        require_once __DIR__ . '/child_outcome_history.php';
        drawdream_child_outcome_history_purge_child($childId);
    }

    $dq = $conn->prepare(
        'SELECT d.donate_id FROM donation d
         INNER JOIN donate_category dc ON dc.category_id = d.category_id
         WHERE d.target_id = ? AND TRIM(COALESCE(dc.child_donate, \'\')) NOT IN (\'\', \'-\')'
    );
    if ($dq) {
        $dq->bind_param('i', $childId);
        $dq->execute();
        $dres = $dq->get_result();
        while ($dr = $dres->fetch_assoc()) {
            $did = (int)($dr['donate_id'] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $dd = $conn->prepare('DELETE FROM donation WHERE donate_id = ?');
            if ($dd) {
                $dd->bind_param('i', $did);
                $dd->execute();
            }
        }
    }

    $adm = $conn->prepare(
        'DELETE FROM `admin`
         WHERE target_id = ?
           AND LOWER(TRIM(COALESCE(target_entity, \'\'))) = \'child\''
    );
    if ($adm) {
        $adm->bind_param('i', $childId);
        $adm->execute();
    }

    $idStr = (string)(int)$childId;
    $notifExact = [
        'children_donate.php?id=' . $idStr,
        'payment/child_donate.php?child_id=' . $idStr,
        'payment.php?child_id=' . $idStr,
    ];
    foreach ($notifExact as $link) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link = ?');
        if ($nf) {
            $nf->bind_param('s', $link);
            $nf->execute();
        }
    }
    // อย่าใช้ %child_id=4% ลอยๆ — จะไปจับ child_id=40 / id=40
    $notifLike = [
        'children_donate.php?id=' . $idStr . '&%',
        '%/children_donate.php?id=' . $idStr . '&%',
        '%/children_donate.php?id=' . $idStr,
        'payment/child_donate.php?child_id=' . $idStr . '&%',
        '../payment/child_donate.php?child_id=' . $idStr . '%',
        '%/payment/child_donate.php?child_id=' . $idStr . '&%',
        '%/payment/child_donate.php?child_id=' . $idStr,
        '%check_child_payment.php%&child_id=' . $idStr . '&%',
        '%check_child_payment.php%&child_id=' . $idStr,
        '%check_child_payment.php%?child_id=' . $idStr . '&%',
        'payment.php?child_id=' . $idStr . '&%',
        '%/payment.php?child_id=' . $idStr . '&%',
        '%/payment.php?child_id=' . $idStr,
    ];
    foreach ($notifLike as $pat) {
        $nf = $conn->prepare('DELETE FROM notifications WHERE link LIKE ?');
        if ($nf) {
            $nf->bind_param('s', $pat);
            $nf->execute();
        }
    }
}

/** ลบไฟล์รูปใน uploads/childern/ หลังลบโปรไฟล์ (ชื่อไฟล์จากฐานข้อมูล) */
function drawdream_delete_child_upload_files(?string $photoChild, ?string $qrImage): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'childern' . DIRECTORY_SEPARATOR;
    foreach ([$photoChild, $qrImage] as $raw) {
        $fn = basename(trim((string)$raw));
        if ($fn === '' || $fn === '.' || $fn === '..') {
            continue;
        }
        $path = $dir . $fn;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
