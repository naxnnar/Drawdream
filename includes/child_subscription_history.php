<?php
// includes/child_subscription_history.php — บันทึกประวัติ subscription เด็กแบบละเอียด
declare(strict_types=1);

require_once __DIR__ . '/drawdream_schema_once.php';

function drawdream_child_subscription_history_ensure_schema(mysqli $conn): void
{
    drawdream_schema_once('child_subscription_history', static function (mysqli $c): void {
        drawdream_child_subscription_history_ensure_schema_inner($c);
    }, $conn);
}

/** @internal */
function drawdream_child_subscription_history_ensure_schema_inner(mysqli $conn): void
{
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS child_subscription_history (
            history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            child_id INT UNSIGNED NOT NULL,
            donor_user_id INT UNSIGNED NOT NULL,
            donate_id INT UNSIGNED NULL DEFAULT NULL,
            recurring_schedule_id VARCHAR(96) NULL DEFAULT NULL,
            recurring_next_charge_at DATETIME NULL DEFAULT NULL,
            omise_charge_id VARCHAR(64) NULL DEFAULT NULL,
            event_type VARCHAR(48) NOT NULL,
            current_status VARCHAR(32) NULL DEFAULT NULL,
            recurring_plan_code VARCHAR(32) NULL DEFAULT NULL,
            amount_baht DECIMAL(12,2) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_csh_child_time (child_id, created_at),
            KEY idx_csh_donor_time (donor_user_id, created_at),
            KEY idx_csh_event_time (event_type, created_at),
            KEY idx_csh_charge (omise_charge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // รองรับฐานเดิม: เช็คก่อน ALTER เพื่อกัน duplicate/unknown column exception
    $hasColumn = static function (string $name) use ($conn): bool {
        $safe = $conn->real_escape_string($name);
        $q = $conn->query("SHOW COLUMNS FROM child_subscription_history LIKE '{$safe}'");
        return $q instanceof mysqli_result && $q->num_rows > 0;
    };

    // เติมคอลัมน์หลักที่โค้ดใช้งาน (กรณีฐานบางเครื่อง schema ไม่ครบ)
    if (!$hasColumn('current_status')) {
        $conn->query("ALTER TABLE child_subscription_history ADD COLUMN current_status VARCHAR(32) NULL DEFAULT NULL AFTER event_type");
    }
    if (!$hasColumn('recurring_plan_code')) {
        $conn->query("ALTER TABLE child_subscription_history ADD COLUMN recurring_plan_code VARCHAR(32) NULL DEFAULT NULL AFTER current_status");
    }
    if (!$hasColumn('amount_baht')) {
        $conn->query("ALTER TABLE child_subscription_history ADD COLUMN amount_baht DECIMAL(12,2) NULL DEFAULT NULL AFTER recurring_plan_code");
    }
    if (!$hasColumn('recurring_next_charge_at')) {
        $conn->query("ALTER TABLE child_subscription_history ADD COLUMN recurring_next_charge_at DATETIME NULL DEFAULT NULL AFTER recurring_schedule_id");
    }
    if ($hasColumn('previous_status')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN previous_status");
    }
    if ($hasColumn('source_channel')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN source_channel");
    }
    if ($hasColumn('currency')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN currency");
    }
    if ($hasColumn('event_note')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN event_note");
    }
    if ($hasColumn('event_payload_json')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN event_payload_json");
    }
    if ($hasColumn('event_occurred_at')) {
        $conn->query("ALTER TABLE child_subscription_history DROP COLUMN event_occurred_at");
    }
}

/**
 * แถว active ยังไม่ครบ donate_id / schedule / charge / รอบถัดไป หรือไม่
 *
 * @param array<string,mixed> $row
 */
function drawdream_child_subscription_history_row_needs_backfill(array $row): bool
{
    $status = strtolower(trim((string)($row['current_status'] ?? '')));
    if ($status !== 'active') {
        return false;
    }
    $donateId = (int)($row['donate_id'] ?? 0);
    $scheduleId = trim((string)($row['recurring_schedule_id'] ?? ''));
    $chargeId = trim((string)($row['omise_charge_id'] ?? ''));
    $nextCharge = trim((string)($row['recurring_next_charge_at'] ?? ''));

    return $donateId <= 0 || $scheduleId === '' || $chargeId === '' || $nextCharge === '';
}

/** @param array<string,mixed>|null $payload */
function drawdream_child_subscription_history_extract_next_charge_at(?array $payload): ?string
{
    if (!is_array($payload)) {
        return null;
    }
    foreach (['next_charge_at', 'next_charge_at_before_cancel'] as $key) {
        $v = trim((string)($payload[$key] ?? ''));
        if ($v !== '' && strtotime($v) !== false) {
            return date('Y-m-d H:i:s', strtotime($v));
        }
    }

    return null;
}

/** แถว reserving ล่าสุดของคู่เด็ก–ผู้บริจาค (0 = ไม่มี) */
function drawdream_child_subscription_history_open_reservation_id(
    mysqli $conn,
    int $childId,
    int $donorUserId
): int {
    if ($childId <= 0 || $donorUserId <= 0) {
        return 0;
    }
    drawdream_child_subscription_history_ensure_schema($conn);
    $st = $conn->prepare(
        "SELECT history_id
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'reserving'
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('ii', $childId, $donorUserId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return (int)($row['history_id'] ?? 0);
}

function drawdream_child_subscription_history_promote_reservation(
    mysqli $conn,
    int $historyId,
    ?int $donateId,
    ?string $scheduleId,
    ?string $chargeId,
    string $eventType,
    ?string $planCode,
    ?float $amountBaht,
    ?string $nextChargeAt
): bool {
    if ($historyId <= 0) {
        return false;
    }
    $eventTypeNorm = trim($eventType);
    if ($eventTypeNorm === 'subscription_reserving') {
        $eventTypeNorm = 'subscription_created';
    }
    $donateIdBind = ($donateId !== null && (int)$donateId > 0) ? (int)$donateId : null;
    $scheduleIdBind = ($scheduleId !== null && trim($scheduleId) !== '') ? trim($scheduleId) : null;
    $nextChargeAtBind = ($nextChargeAt !== null && trim($nextChargeAt) !== '') ? trim($nextChargeAt) : null;
    $chargeIdBind = ($chargeId !== null && trim($chargeId) !== '') ? trim($chargeId) : null;
    $planCodeBind = ($planCode !== null && trim($planCode) !== '') ? trim($planCode) : null;
    $amountBahtBind = ($amountBaht !== null && $amountBaht > 0) ? (float)$amountBaht : null;

    $upd = $conn->prepare(
        "UPDATE child_subscription_history
         SET donate_id = ?,
             recurring_schedule_id = ?,
             recurring_next_charge_at = ?,
             omise_charge_id = ?,
             event_type = ?,
             current_status = 'active',
             recurring_plan_code = CASE
                 WHEN recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '' THEN ?
                 ELSE recurring_plan_code
             END,
             amount_baht = CASE
                 WHEN amount_baht IS NULL OR amount_baht <= 0 THEN ?
                 ELSE amount_baht
             END
         WHERE history_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'reserving'
         LIMIT 1"
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param(
        'isssssdi',
        $donateIdBind,
        $scheduleIdBind,
        $nextChargeAtBind,
        $chargeIdBind,
        $eventTypeNorm,
        $planCodeBind,
        $amountBahtBind,
        $historyId
    );

    return $upd->execute() && $upd->affected_rows > 0;
}

function drawdream_child_subscription_history_cancel_open_reservation(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    string $eventType = 'subscription_reserve_released'
): bool {
    $historyId = drawdream_child_subscription_history_open_reservation_id($conn, $childId, $donorUserId);
    if ($historyId <= 0) {
        return false;
    }
    $eventTypeNorm = trim($eventType) !== '' ? trim($eventType) : 'subscription_reserve_released';
    $upd = $conn->prepare(
        "UPDATE child_subscription_history
         SET event_type = ?,
             current_status = 'cancelled'
         WHERE history_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'reserving'
         LIMIT 1"
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param('si', $eventTypeNorm, $historyId);

    return $upd->execute() && $upd->affected_rows > 0;
}

/** ลบแถว reserving ที่หมดอายุหรือมี active ของคู่เดียวกันแล้ว */
function drawdream_child_subscription_history_cleanup_stale_reservations(mysqli $conn, ?int $childId = null): int
{
    drawdream_child_subscription_history_ensure_schema($conn);
    if (!function_exists('drawdream_child_subscription_reserve_ttl_minutes')) {
        require_once __DIR__ . '/child_omise_subscription.php';
    }
    $ttl = drawdream_child_subscription_reserve_ttl_minutes();
    if ($ttl < 1) {
        $ttl = 10;
    }

    if ($childId !== null && $childId > 0) {
        $stExpired = $conn->prepare(
            "DELETE FROM child_subscription_history
             WHERE child_id = ?
               AND LOWER(TRIM(COALESCE(current_status, ''))) = 'reserving'
               AND created_at < (NOW() - INTERVAL ? MINUTE)"
        );
        $deleted = 0;
        if ($stExpired) {
            $stExpired->bind_param('ii', $childId, $ttl);
            $stExpired->execute();
            $deleted += $stExpired->affected_rows;
        }
        $stOrphan = $conn->prepare(
            "DELETE FROM child_subscription_history
             WHERE history_id IN (
                 SELECT history_id FROM (
                     SELECT h.history_id
                     FROM child_subscription_history h
                     INNER JOIN child_subscription_history a
                       ON a.child_id = h.child_id
                      AND a.donor_user_id = h.donor_user_id
                      AND LOWER(TRIM(COALESCE(a.current_status, ''))) = 'active'
                      AND a.history_id > h.history_id
                     WHERE h.child_id = ?
                       AND LOWER(TRIM(COALESCE(h.current_status, ''))) = 'reserving'
                 ) stale_ids
             )"
        );
        if ($stOrphan) {
            $stOrphan->bind_param('i', $childId);
            $stOrphan->execute();
            $deleted += $stOrphan->affected_rows;
        }

        return $deleted;
    }

    $deleted = 0;
    $expiredSql = "DELETE FROM child_subscription_history
                   WHERE LOWER(TRIM(COALESCE(current_status, ''))) = 'reserving'
                     AND created_at < (NOW() - INTERVAL {$ttl} MINUTE)";
    if ($conn->query($expiredSql)) {
        $deleted += (int)$conn->affected_rows;
    }
    $orphanSql = <<<'SQL'
DELETE FROM child_subscription_history
WHERE history_id IN (
    SELECT history_id FROM (
        SELECT h.history_id
        FROM child_subscription_history h
        INNER JOIN child_subscription_history a
          ON a.child_id = h.child_id
         AND a.donor_user_id = h.donor_user_id
         AND LOWER(TRIM(COALESCE(a.current_status, ''))) = 'active'
         AND a.history_id > h.history_id
        WHERE LOWER(TRIM(COALESCE(h.current_status, ''))) = 'reserving'
    ) stale_ids
)
SQL;
    if ($conn->query($orphanSql)) {
        $deleted += (int)$conn->affected_rows;
    }

    return $deleted;
}

function drawdream_child_subscription_history_after_active_change(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    string $eventType
): void {
    if (
        in_array($eventType, ['subscription_created', 'charge_success'], true)
    ) {
        drawdream_child_subscription_history_backfill_active_row($conn, $childId, $donorUserId);
    }
    if (!function_exists('drawdream_child_sync_sponsorship_status')) {
        require_once __DIR__ . '/child_sponsorship.php';
    }
    if (function_exists('drawdream_child_sync_sponsorship_status')) {
        drawdream_child_sync_sponsorship_status($conn, $childId);
    }
}

/**
 * เติมข้อมูลชำระ/รอบถัดไปให้แถว active ล่าสุดจาก donation + แผนรายรอบ
 */
function drawdream_child_subscription_history_backfill_active_row(
    mysqli $conn,
    int $childId,
    int $donorUserId
): bool {
    if ($childId <= 0 || $donorUserId <= 0) {
        return false;
    }
    drawdream_child_subscription_history_ensure_schema($conn);

    $st = $conn->prepare(
        "SELECT history_id, donate_id, recurring_schedule_id, recurring_next_charge_at,
                omise_charge_id, recurring_plan_code, amount_baht, event_type
         FROM child_subscription_history
         WHERE child_id = ? AND donor_user_id = ?
           AND LOWER(TRIM(COALESCE(current_status, ''))) = 'active'
         ORDER BY history_id DESC
         LIMIT 1"
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('ii', $childId, $donorUserId);
    $st->execute();
    $hist = $st->get_result()->fetch_assoc();
    if (!is_array($hist) || !drawdream_child_subscription_history_row_needs_backfill($hist)) {
        return false;
    }

    $historyId = (int)($hist['history_id'] ?? 0);
    if ($historyId <= 0) {
        return false;
    }

    $donateId = (int)($hist['donate_id'] ?? 0);
    $scheduleId = trim((string)($hist['recurring_schedule_id'] ?? ''));
    $chargeId = trim((string)($hist['omise_charge_id'] ?? ''));
    $nextCharge = trim((string)($hist['recurring_next_charge_at'] ?? ''));
    $planCode = strtolower(trim((string)($hist['recurring_plan_code'] ?? '')));
    $amountBaht = (float)($hist['amount_baht'] ?? 0);

    $stDon = $conn->prepare(
        "SELECT donate_id, amount, omise_charge_id, transfer_datetime
         FROM donation
         WHERE donor_id = ? AND target_id = ? AND payment_status = 'completed'
           AND COALESCE(donate_type, '') IN ('child_subscription', 'child_subscription_charge')
         ORDER BY donate_id DESC
         LIMIT 1"
    );
    if ($stDon) {
        $stDon->bind_param('ii', $donorUserId, $childId);
        $stDon->execute();
        $don = $stDon->get_result()->fetch_assoc();
        if (is_array($don)) {
            if ($donateId <= 0) {
                $donateId = (int)($don['donate_id'] ?? 0);
            }
            if ($chargeId === '') {
                $chargeId = trim((string)($don['omise_charge_id'] ?? ''));
            }
            if ($amountBaht <= 0) {
                $amountBaht = (float)($don['amount'] ?? 0);
            }
            if ($planCode === '') {
                $amt = (float)($don['amount'] ?? 0);
                if ($amt >= 8000) {
                    $planCode = 'yearly';
                } elseif ($amt >= 4000) {
                    $planCode = 'semiannual';
                } else {
                    $planCode = 'monthly';
                }
            }
        }
    }

    if ($scheduleId === '') {
        $stSch = $conn->prepare(
            "SELECT recurring_schedule_id
             FROM child_subscription_history
             WHERE child_id = ? AND donor_user_id = ?
               AND recurring_schedule_id IS NOT NULL
               AND TRIM(recurring_schedule_id) <> ''
             ORDER BY history_id DESC
             LIMIT 1"
        );
        if ($stSch) {
            $stSch->bind_param('ii', $childId, $donorUserId);
            $stSch->execute();
            $schRow = $stSch->get_result()->fetch_assoc();
            if (is_array($schRow)) {
                $scheduleId = trim((string)($schRow['recurring_schedule_id'] ?? ''));
            }
        }
    }
    if ($scheduleId === '' && $donateId > 0) {
        $scheduleId = 'local_cron_backfill_' . bin2hex(random_bytes(8));
    }

    if ($nextCharge === '' && $planCode !== '') {
        if (!function_exists('drawdream_child_subscription_plan')) {
            require_once __DIR__ . '/child_omise_subscription.php';
        }
        $spec = drawdream_child_subscription_plan($planCode);
        if (is_array($spec) && function_exists('drawdream_subscription_next_charge_at')) {
            $anchor = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
            if ($stDon && isset($don) && is_array($don)) {
                $paidRaw = trim((string)($don['transfer_datetime'] ?? ''));
                if ($paidRaw !== '') {
                    try {
                        $anchor = new DateTimeImmutable($paidRaw, new DateTimeZone('Asia/Bangkok'));
                    } catch (Exception $e) {
                        // keep now
                    }
                }
            }
            $billDay = function_exists('drawdream_subscription_safe_bill_day')
                ? drawdream_subscription_safe_bill_day($anchor)
                : min(28, max(1, (int)$anchor->format('j')));
            $nextCharge = drawdream_subscription_next_charge_at($anchor, $spec, $billDay)->format('Y-m-d H:i:s');
        }
    }

    if ($donateId <= 0 || $scheduleId === '' || $chargeId === '') {
        return false;
    }

    $eventType = trim((string)($hist['event_type'] ?? 'subscription_created'));
    if ($eventType === 'subscription_reserving') {
        $eventType = 'subscription_created';
    }

    $upd = $conn->prepare(
        "UPDATE child_subscription_history
         SET donate_id = ?,
             recurring_schedule_id = ?,
             recurring_next_charge_at = ?,
             omise_charge_id = ?,
             recurring_plan_code = CASE
                 WHEN recurring_plan_code IS NULL OR TRIM(recurring_plan_code) = '' THEN ?
                 ELSE recurring_plan_code
             END,
             amount_baht = CASE
                 WHEN amount_baht IS NULL OR amount_baht <= 0 THEN ?
                 ELSE amount_baht
             END,
             event_type = ?,
             current_status = 'active'
         WHERE history_id = ?
         LIMIT 1"
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param(
        'issssdsi',
        $donateId,
        $scheduleId,
        $nextCharge,
        $chargeId,
        $planCode,
        $amountBaht,
        $eventType,
        $historyId
    );

    return $upd->execute() && $upd->affected_rows >= 0;
}

/**
 * @param array<string,mixed>|null $payload
 */
function drawdream_child_subscription_history_log(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    ?int $donateId,
    ?string $scheduleId,
    ?string $chargeId,
    string $eventType,
    ?string $previousStatus,
    ?string $currentStatus,
    ?string $planCode,
    ?float $amountBaht,
    string $sourceChannel,
    ?string $eventNote = null,
    ?array $payload = null
): void {
    if ($childId <= 0 || $donorUserId <= 0 || trim($eventType) === '') {
        return;
    }
    drawdream_child_subscription_history_ensure_schema($conn);
    $nextChargeAt = drawdream_child_subscription_history_extract_next_charge_at($payload);
    $eventTypeBind = trim($eventType);
    $currentStatusBind = ($currentStatus !== null && trim($currentStatus) !== '') ? trim($currentStatus) : null;
    $statusNorm = strtolower(trim((string)($currentStatusBind ?? '')));

    if ($eventTypeBind === 'subscription_reserve_released' && $statusNorm === 'cancelled') {
        if (drawdream_child_subscription_history_cancel_open_reservation($conn, $childId, $donorUserId, $eventTypeBind)) {
            drawdream_child_subscription_history_cleanup_stale_reservations($conn, $childId);
            return;
        }
    }

    if (
        in_array($eventTypeBind, ['subscription_created', 'charge_success'], true)
        && $statusNorm === 'active'
    ) {
        $resId = drawdream_child_subscription_history_open_reservation_id($conn, $childId, $donorUserId);
        if (
            $resId > 0
            && drawdream_child_subscription_history_promote_reservation(
                $conn,
                $resId,
                $donateId,
                $scheduleId,
                $chargeId,
                $eventTypeBind,
                $planCode,
                $amountBaht,
                $nextChargeAt
            )
        ) {
            drawdream_child_subscription_history_cleanup_stale_reservations($conn, $childId);
            drawdream_child_subscription_history_after_active_change($conn, $childId, $donorUserId, $eventTypeBind);
            return;
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO child_subscription_history (
            child_id, donor_user_id, donate_id, recurring_schedule_id, recurring_next_charge_at, omise_charge_id,
            event_type, current_status, recurring_plan_code, amount_baht, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        return;
    }
    $donateIdBind = ($donateId !== null && (int)$donateId > 0) ? (int)$donateId : null;
    $scheduleIdBind = ($scheduleId !== null && trim($scheduleId) !== '') ? trim($scheduleId) : null;
    $nextChargeAtBind = ($nextChargeAt !== null && trim($nextChargeAt) !== '') ? $nextChargeAt : null;
    $chargeIdBind = ($chargeId !== null && trim($chargeId) !== '') ? trim($chargeId) : null;
    $planCodeBind = ($planCode !== null && trim($planCode) !== '') ? trim($planCode) : null;
    $amountBahtBind = ($amountBaht !== null && $amountBaht > 0) ? (float)$amountBaht : null;

    $stmt->bind_param(
        'iiissssssd',
        $childId,
        $donorUserId,
        $donateIdBind,
        $scheduleIdBind,
        $nextChargeAtBind,
        $chargeIdBind,
        $eventTypeBind,
        $currentStatusBind,
        $planCodeBind,
        $amountBahtBind
    );
    if (!$stmt->execute()) {
        $donateIdBind = ($donateId !== null && (int)$donateId > 0) ? (int)$donateId : 0;
        $scheduleIdBind = ($scheduleId !== null && trim($scheduleId) !== '') ? trim($scheduleId) : '';
        $nextChargeAtBind = ($nextChargeAt !== null && trim($nextChargeAt) !== '') ? $nextChargeAt : '';
        $chargeIdBind = ($chargeId !== null && trim($chargeId) !== '') ? trim($chargeId) : '';
        $currentStatusBind = ($currentStatus !== null && trim($currentStatus) !== '') ? trim($currentStatus) : 'active';
        $planCodeBind = ($planCode !== null && trim($planCode) !== '') ? trim($planCode) : '';
        $amountBahtBind = ($amountBaht !== null && $amountBaht > 0) ? (float)$amountBaht : 0.0;
        $stmt->bind_param(
            'iiissssssd',
            $childId,
            $donorUserId,
            $donateIdBind,
            $scheduleIdBind,
            $nextChargeAtBind,
            $chargeIdBind,
            $eventTypeBind,
            $currentStatusBind,
            $planCodeBind,
            $amountBahtBind
        );
        if (!$stmt->execute()) {
            error_log('[child_subscription_history_log] insert failed: ' . $stmt->error);
            return;
        }
    }

    if (
        in_array($eventTypeBind, ['subscription_created', 'charge_success'], true)
        && $statusNorm === 'active'
    ) {
        drawdream_child_subscription_history_cleanup_stale_reservations($conn, $childId);
        drawdream_child_subscription_history_after_active_change($conn, $childId, $donorUserId, $eventTypeBind);
    }
}

