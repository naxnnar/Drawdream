<?php
// includes/escrow_funds_schema.php — ตาราง escrow_funds + helper แบบ ledger กลาง
// สรุปสั้น: รองรับ escrow ทั้ง project และ need_item ด้วย target_type/target_id

declare(strict_types=1);

function drawdream_escrow_has_index(mysqli $conn, string $table, string $indexName): bool
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $idxEsc = mysqli_real_escape_string($conn, $indexName);
    $q = @$conn->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$idxEsc}'");
    return (bool)($q && $q->num_rows > 0);
}

function drawdream_escrow_has_column(mysqli $conn, string $table, string $columnName): bool
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $colEsc = mysqli_real_escape_string($conn, $columnName);
    $q = @$conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    return (bool)($q && $q->num_rows > 0);
}

function drawdream_escrow_funds_ensure_schema(mysqli $conn): void
{
    $r = @$conn->query("SHOW TABLES LIKE 'escrow_funds'");
    if (!$r || $r->num_rows === 0) {
        $sql = "CREATE TABLE IF NOT EXISTS `escrow_funds` (
            `escrow_id` INT NOT NULL AUTO_INCREMENT,
            `target_type` ENUM('project','need_item') NOT NULL DEFAULT 'project',
            `target_id` INT NOT NULL DEFAULT 0,
            `donate_id` INT NOT NULL,
            `omise_charge_id` VARCHAR(100) NOT NULL DEFAULT '',
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('holding','released','refunded') NOT NULL DEFAULT 'holding',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `released_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`escrow_id`),
            UNIQUE KEY `uq_escrow_target_donate` (`target_type`, `target_id`, `donate_id`),
            KEY `idx_escrow_target_status` (`target_type`, `target_id`, `status`),
            KEY `idx_escrow_donate_id` (`donate_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        @$conn->query($sql);
    }

    if (($c = @$conn->query("SHOW COLUMNS FROM escrow_funds LIKE 'target_type'")) && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE escrow_funds ADD COLUMN target_type ENUM('project','need_item') NOT NULL DEFAULT 'project'");
    }
    if (($c = @$conn->query("SHOW COLUMNS FROM escrow_funds LIKE 'target_id'")) && $c->num_rows === 0) {
        @$conn->query("ALTER TABLE escrow_funds ADD COLUMN target_id INT NOT NULL DEFAULT 0 AFTER target_type");
    }
    // backfill ข้อมูลเก่า (เดิมเก็บเฉพาะ project_id)
    @$conn->query("UPDATE escrow_funds SET target_type = 'project' WHERE target_type IS NULL OR TRIM(target_type) = ''");
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'project_id')) {
        @$conn->query("UPDATE escrow_funds SET target_id = project_id WHERE target_id = 0 AND project_id > 0");
    }
    // ดัชนีสำหรับ ledger กลาง
    if (!drawdream_escrow_has_index($conn, 'escrow_funds', 'uq_escrow_target_donate')) {
        @$conn->query("ALTER TABLE escrow_funds ADD UNIQUE KEY uq_escrow_target_donate (target_type, target_id, donate_id)");
    }
    if (!drawdream_escrow_has_index($conn, 'escrow_funds', 'idx_escrow_target_status')) {
        @$conn->query("ALTER TABLE escrow_funds ADD KEY idx_escrow_target_status (target_type, target_id, status)");
    }

    // ล้างโครงสร้างเก่า: project_id ไม่ต้องเก็บแล้ว
    if (drawdream_escrow_has_index($conn, 'escrow_funds', 'idx_escrow_project_status')) {
        @$conn->query("ALTER TABLE escrow_funds DROP INDEX idx_escrow_project_status");
    }
    if (drawdream_escrow_has_column($conn, 'escrow_funds', 'project_id')) {
        @$conn->query("ALTER TABLE escrow_funds DROP COLUMN project_id");
    }
}

/**
 * ยอดเงินพักสำหรับการ์ดสรุป: ยอด holding ใน escrow_funds + โครงการ completed ที่ยังไม่มีแถว escrow (ข้อมูลเก่า)
 */
function drawdream_escrow_project_holding_total_display(mysqli $conn): float
{
    drawdream_escrow_funds_ensure_schema($conn);

    $holding = 0.0;
    $sumR = @$conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM escrow_funds WHERE status = 'holding' AND target_type = 'project'");
    if ($sumR && ($sr = $sumR->fetch_assoc())) {
        $holding = (float) ($sr['total'] ?? 0);
    }

    $legacy = 0.0;
    $legR = @$conn->query(
        "SELECT COALESCE(SUM(p.current_donate),0) AS total
         FROM foundation_project p
         WHERE p.project_status = 'completed'
         AND NOT EXISTS (
             SELECT 1 FROM escrow_funds ef
             WHERE ef.target_type = 'project' AND ef.target_id = p.project_id
         )"
    );
    if ($legR && ($lr = $legR->fetch_assoc())) {
        $legacy = (float) ($lr['total'] ?? 0);
    }

    return $holding + $legacy;
}

/**
 * ยอดเงินพักสำหรับรายการสิ่งของ (need_item)
 */
function drawdream_escrow_need_item_holding_total_display(mysqli $conn): float
{
    drawdream_escrow_funds_ensure_schema($conn);
    $holding = 0.0;
    $sumR = @$conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM escrow_funds WHERE status = 'holding' AND target_type = 'need_item'");
    if ($sumR && ($sr = $sumR->fetch_assoc())) {
        $holding = (float)($sr['total'] ?? 0);
    }
    return $holding;
}

/**
 * แทรกแถว holding ต่อ (target_type,target_id,donate_id) — ใช้ได้กับทั้ง project/need_item
 */
function drawdream_escrow_funds_try_insert_holding_for_target(
    mysqli $conn,
    string $targetType,
    int $targetId,
    int $donate_id,
    string $omise_charge_id,
    float $amountBaht
): bool {
    $targetType = strtolower(trim($targetType));
    if (!in_array($targetType, ['project', 'need_item'], true) || $targetId <= 0 || $donate_id <= 0) {
        return true;
    }

    drawdream_escrow_funds_ensure_schema($conn);

    $omise = substr($omise_charge_id, 0, 100);
    $ins = $conn->prepare(
        "INSERT INTO escrow_funds (target_type, target_id, donate_id, omise_charge_id, amount, status, created_at)
         VALUES (?, ?, ?, ?, ?, 'holding', NOW())
         ON DUPLICATE KEY UPDATE omise_charge_id = VALUES(omise_charge_id)"
    );
    if (!$ins) {
        return false;
    }
    $ins->bind_param('siisd', $targetType, $targetId, $donate_id, $omise, $amountBaht);

    return (bool) $ins->execute();
}

function drawdream_escrow_funds_try_insert_holding(
    mysqli $conn,
    int $project_id,
    int $donate_id,
    string $omise_charge_id,
    float $amountBaht
): bool {
    return drawdream_escrow_funds_try_insert_holding_for_target($conn, 'project', $project_id, $donate_id, $omise_charge_id, $amountBaht);
}

function drawdream_escrow_funds_release_holding_for_target(mysqli $conn, string $targetType, int $targetId): int
{
    $targetType = strtolower(trim($targetType));
    if (!in_array($targetType, ['project', 'need_item'], true) || $targetId <= 0) {
        return 0;
    }
    drawdream_escrow_funds_ensure_schema($conn);
    $st = $conn->prepare(
        "UPDATE escrow_funds SET status = 'released', released_at = NOW()
         WHERE target_type = ? AND target_id = ? AND status = 'holding'"
    );
    if (!$st) {
        return 0;
    }
    $st->bind_param('si', $targetType, $targetId);
    $st->execute();

    return (int) $st->affected_rows;
}

function drawdream_escrow_funds_release_holding_for_project(mysqli $conn, int $project_id): int
{
    return drawdream_escrow_funds_release_holding_for_target($conn, 'project', $project_id);
}

function drawdream_escrow_funds_release_holding_for_need_item(mysqli $conn, int $item_id): int
{
    return drawdream_escrow_funds_release_holding_for_target($conn, 'need_item', $item_id);
}
