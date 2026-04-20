<?php
// includes/escrow_funds_schema.php — ตาราง escrow_funds + helper สำหรับโครงการ
// สรุปสั้น: ตรวจ/สร้างตาราง escrow_funds และ helper การอัปเดตยอดฝั่ง escrow

declare(strict_types=1);

function drawdream_escrow_funds_ensure_schema(mysqli $conn): void
{
    $r = @$conn->query("SHOW TABLES LIKE 'escrow_funds'");
    if ($r && $r->num_rows > 0) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `escrow_funds` (
        `escrow_id` INT NOT NULL AUTO_INCREMENT,
        `project_id` INT NOT NULL,
        `donate_id` INT NOT NULL,
        `omise_charge_id` VARCHAR(100) NOT NULL DEFAULT '',
        `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('holding','released','refunded') NOT NULL DEFAULT 'holding',
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `released_at` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`escrow_id`),
        KEY `idx_escrow_project_status` (`project_id`, `status`),
        KEY `idx_escrow_donate_id` (`donate_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
}

/**
 * ยอดเงินพักสำหรับการ์ดสรุป: ยอด holding ใน escrow_funds + โครงการ completed ที่ยังไม่มีแถว escrow (ข้อมูลเก่า)
 */
function drawdream_escrow_project_holding_total_display(mysqli $conn): float
{
    drawdream_escrow_funds_ensure_schema($conn);

    $holding = 0.0;
    $sumR = @$conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM escrow_funds WHERE status = 'holding'");
    if ($sumR && ($sr = $sumR->fetch_assoc())) {
        $holding = (float) ($sr['total'] ?? 0);
    }

    $legacy = 0.0;
    $legR = @$conn->query(
        "SELECT COALESCE(SUM(p.current_donate),0) AS total
         FROM foundation_project p
         WHERE p.project_status = 'completed' AND p.deleted_at IS NULL
         AND NOT EXISTS (SELECT 1 FROM escrow_funds ef WHERE ef.project_id = p.project_id)"
    );
    if ($legR && ($lr = $legR->fetch_assoc())) {
        $legacy = (float) ($lr['total'] ?? 0);
    }

    return $holding + $legacy;
}

/**
 * แทรกแถว holding ต่อ donate_id (ไม่ซ้ำ) — เรียกภายใน transaction ได้
 */
function drawdream_escrow_funds_try_insert_holding(
    mysqli $conn,
    int $project_id,
    int $donate_id,
    string $omise_charge_id,
    float $amountBaht
): bool {
    if ($project_id <= 0 || $donate_id <= 0) {
        return true;
    }

    drawdream_escrow_funds_ensure_schema($conn);

    $chk = $conn->prepare('SELECT 1 FROM escrow_funds WHERE donate_id = ? LIMIT 1');
    if (!$chk) {
        return false;
    }
    $chk->bind_param('i', $donate_id);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) {
        return true;
    }

    $omise = substr($omise_charge_id, 0, 100);
    $ins = $conn->prepare(
        "INSERT INTO escrow_funds (project_id, donate_id, omise_charge_id, amount, status, created_at)
         VALUES (?, ?, ?, ?, 'holding', NOW())"
    );
    if (!$ins) {
        return false;
    }
    $ins->bind_param('iisd', $project_id, $donate_id, $omise, $amountBaht);

    return (bool) $ins->execute();
}

function drawdream_escrow_funds_release_holding_for_project(mysqli $conn, int $project_id): int
{
    if ($project_id <= 0) {
        return 0;
    }
    drawdream_escrow_funds_ensure_schema($conn);
    $st = $conn->prepare(
        "UPDATE escrow_funds SET status = 'released', released_at = NOW()
         WHERE project_id = ? AND status = 'holding'"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $project_id);
    $st->execute();

    return (int) $st->affected_rows;
}
