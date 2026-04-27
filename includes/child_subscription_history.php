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
            omise_charge_id VARCHAR(64) NULL DEFAULT NULL,
            event_type VARCHAR(48) NOT NULL,
            previous_status VARCHAR(32) NULL DEFAULT NULL,
            current_status VARCHAR(32) NULL DEFAULT NULL,
            recurring_plan_code VARCHAR(32) NULL DEFAULT NULL,
            amount_baht DECIMAL(12,2) NULL DEFAULT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'THB',
            source_channel VARCHAR(32) NOT NULL DEFAULT 'web',
            event_note VARCHAR(255) NULL DEFAULT NULL,
            event_payload_json LONGTEXT NULL,
            event_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_csh_child_time (child_id, event_occurred_at),
            KEY idx_csh_donor_time (donor_user_id, event_occurred_at),
            KEY idx_csh_event_time (event_type, event_occurred_at),
            KEY idx_csh_charge (omise_charge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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
            child_id, donor_user_id, donate_id, recurring_schedule_id, omise_charge_id,
            event_type, previous_status, current_status, recurring_plan_code, amount_baht, currency,
            source_channel, event_note, event_payload_json, event_occurred_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        return;
    }
    $payloadJson = null;
    if (is_array($payload) && $payload !== []) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $payloadJson = $encoded;
        }
    }
    $currency = 'THB';
    $source = trim($sourceChannel) !== '' ? trim($sourceChannel) : 'web';
    $stmt->bind_param(
        'iiissssssdssss',
        $childId,
        $donorUserId,
        $donateId,
        $scheduleId,
        $chargeId,
        $eventType,
        $previousStatus,
        $currentStatus,
        $planCode,
        $amountBaht,
        $currency,
        $source,
        $eventNote,
        $payloadJson
    );
    @$stmt->execute();
}

