<?php
// includes/child_subscription_history.php — บันทึกประวัติ subscription เด็กแบบละเอียด
declare(strict_types=1);

function drawdream_child_subscription_history_ensure_schema(mysqli $conn): void
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
    $stmt = $conn->prepare(
        'INSERT INTO child_subscription_history (
            child_id, donor_user_id, donate_id, recurring_schedule_id, recurring_next_charge_at, omise_charge_id,
            event_type, current_status, recurring_plan_code, amount_baht, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        return;
    }
    $nextChargeAt = null;
    if (is_array($payload)) {
        $candidates = [
            (string)($payload['next_charge_at'] ?? ''),
            (string)($payload['next_charge_at_before_cancel'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $v = trim($candidate);
            if ($v !== '' && strtotime($v) !== false) {
                $nextChargeAt = date('Y-m-d H:i:s', strtotime($v));
                break;
            }
        }
    }
    $stmt->bind_param(
        'iiissssssd',
        $childId,
        $donorUserId,
        $donateId,
        $scheduleId,
        $nextChargeAt,
        $chargeId,
        $eventType,
        $currentStatus,
        $planCode,
        $amountBaht
    );
    @$stmt->execute();
}

