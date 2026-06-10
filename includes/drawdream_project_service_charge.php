<?php
// includes/drawdream_project_service_charge.php — ค่าบริการ 5% โครงการมูลนิธิ
declare(strict_types=1);

require_once __DIR__ . '/drawdream_needlist_schema.php';

/** ตรวจ/เพิ่มคอลัมน์ service_charge บน foundation_project */
function drawdream_ensure_foundation_project_service_charge_columns(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'foundation_project'");
    if (!$t || $t->num_rows === 0) {
        return;
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'service_charge'")) && $c->num_rows === 0) {
        @$conn->query(
            'ALTER TABLE foundation_project ADD COLUMN service_charge DECIMAL(12,2) NOT NULL DEFAULT 0 '
            . "COMMENT 'ค่าบริการ 5% บันทึกแสดงผล' AFTER current_donate"
        );
    }
    if (($c = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'service_charge_paid_at'")) && $c->num_rows === 0) {
        @$conn->query(
            'ALTER TABLE foundation_project ADD COLUMN service_charge_paid_at DATETIME NULL DEFAULT NULL '
            . "COMMENT 'วันที่มูลนิธิชำระค่าบริการโครงการ' AFTER service_charge"
        );
    }
    drawdream_project_backfill_service_charges($conn);
}

/** บันทึก service_charge เมื่อครบเป้าโครงการ */
function drawdream_project_sync_service_charge_for_project(mysqli $conn, int $projectId): void
{
    if ($projectId <= 0) {
        return;
    }
    drawdream_ensure_foundation_project_service_charge_columns($conn);
    $zero = $conn->prepare(
        'UPDATE foundation_project
         SET service_charge = 0
         WHERE project_id = ?
          
           AND (COALESCE(goal_amount, 0) <= 0 OR COALESCE(current_donate, 0) < COALESCE(goal_amount, 0))'
    );
    if ($zero) {
        $zero->bind_param('i', $projectId);
        @$zero->execute();
    }
    $rate = drawdream_needlist_service_charge_rate();
    $set = $conn->prepare(
        'UPDATE foundation_project
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE project_id = ?
          
           AND COALESCE(goal_amount, 0) > 0
           AND COALESCE(current_donate, 0) >= COALESCE(goal_amount, 0)'
    );
    if ($set) {
        $set->bind_param('di', $rate, $projectId);
        @$set->execute();
    }
}

function drawdream_project_backfill_service_charges(mysqli $conn): void
{
    $rate = drawdream_needlist_service_charge_rate();
    @$conn->query(
        'UPDATE foundation_project
         SET service_charge = 0
         WHERE (COALESCE(goal_amount, 0) <= 0
                OR COALESCE(current_donate, 0) < COALESCE(goal_amount, 0))'
    );
    $st = $conn->prepare(
        'UPDATE foundation_project
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE COALESCE(goal_amount, 0) > 0
           AND COALESCE(current_donate, 0) >= COALESCE(goal_amount, 0)'
    );
    if ($st) {
        $st->bind_param('d', $rate);
        @$st->execute();
    }
}

function drawdream_project_goal_met(float $raised, float $goal): bool
{
    return drawdream_needlist_item_goal_met($raised, $goal);
}
