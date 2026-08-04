<?php
// includes/drawdream_project_service_charge.php — ค่าบริการ 5% โครงการมูลนิธิ
declare(strict_types=1);

require_once __DIR__ . '/drawdream_needlist_schema.php';
require_once __DIR__ . '/drawdream_schema_once.php';

/** ตรวจ/เพิ่มคอลัมน์ service_charge บน foundation_project */
function drawdream_ensure_foundation_project_service_charge_columns(mysqli $conn): void
{
    drawdream_schema_once('project_service_charge', static function (mysqli $c): void {
        drawdream_ensure_foundation_project_service_charge_columns_inner($c);
    }, $conn);
}

/** @internal */
function drawdream_ensure_foundation_project_service_charge_columns_inner(mysqli $conn): void
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
}

function drawdream_project_goal_met(float $raised, float $goal): bool
{
    return drawdream_needlist_item_goal_met($raised, $goal);
}

/** @param array<string,mixed> $row */
function drawdream_project_service_charge_due_from_row(array $row): bool
{
    $raised = (float)($row['current_donate'] ?? 0);
    if ($raised <= 1e-9) {
        return false;
    }
    $goal = (float)($row['goal_amount'] ?? 0);
    if ($goal > 0 && drawdream_project_goal_met($raised, $goal)) {
        return true;
    }
    $endDate = trim((string)($row['end_date'] ?? ''));
    if ($endDate !== '') {
        $ts = strtotime($endDate);
        if ($ts !== false && date('Y-m-d', $ts) <= date('Y-m-d')) {
            return true;
        }
    }

    return false;
}

function drawdream_project_service_charge_view_link(int $projectId): string
{
    return 'foundation_project_view.php?id=' . max(0, $projectId);
}

/** SQL เงื่อนไข: ถึงเวลาคิดค่าบริการ 5% จากยอดบริจาคจริง */
function drawdream_project_sql_service_charge_due(): string
{
    return '(COALESCE(current_donate, 0) > 0 AND (
        (COALESCE(goal_amount, 0) > 0 AND COALESCE(current_donate, 0) >= COALESCE(goal_amount, 0))
        OR (end_date IS NOT NULL AND end_date <= CURDATE())
    ))';
}

/** บันทึก service_charge เมื่อครบเป้าหรือปิดรับตามวันที่ */
function drawdream_project_sync_service_charge_for_project(mysqli $conn, int $projectId): void
{
    if ($projectId <= 0) {
        return;
    }
    drawdream_ensure_foundation_project_service_charge_columns($conn);
    $dueSql = drawdream_project_sql_service_charge_due();
    $zero = $conn->prepare(
        "UPDATE foundation_project
         SET service_charge = 0
         WHERE project_id = ?
           AND NOT {$dueSql}"
    );
    if ($zero) {
        $zero->bind_param('i', $projectId);
        @$zero->execute();
    }
    $rate = drawdream_needlist_service_charge_rate();
    $set = $conn->prepare(
        "UPDATE foundation_project
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE project_id = ?
           AND {$dueSql}"
    );
    if ($set) {
        $set->bind_param('di', $rate, $projectId);
        @$set->execute();
    }
}

function drawdream_project_backfill_service_charges(mysqli $conn): void
{
    $dueSql = drawdream_project_sql_service_charge_due();
    $rate = drawdream_needlist_service_charge_rate();
    @$conn->query(
        "UPDATE foundation_project
         SET service_charge = 0
         WHERE NOT {$dueSql}"
    );
    $st = $conn->prepare(
        "UPDATE foundation_project
         SET service_charge = ROUND(COALESCE(current_donate, 0) * ?, 2)
         WHERE {$dueSql}"
    );
    if ($st) {
        $st->bind_param('d', $rate);
        @$st->execute();
    }
}

/** แจ้งมูลนิธิให้ชำระค่าบริการ — ลิงก์ไปหน้ารายละเอียดโครงการ */
function drawdream_project_notify_service_charge_due(mysqli $conn, int $projectId): void
{
    if ($projectId <= 0) {
        return;
    }
    if (!function_exists('drawdream_send_notification')) {
        require_once __DIR__ . '/notification_audit.php';
    }
    $st = $conn->prepare(
        'SELECT p.project_id, p.project_name, p.current_donate, p.goal_amount, p.end_date,
                p.service_charge, p.service_charge_paid_at, fp.user_id AS foundation_user_id
         FROM foundation_project p
         JOIN foundation_profile fp ON p.foundation_name = fp.foundation_name
         WHERE p.project_id = ?
         LIMIT 1'
    );
    if (!$st) {
        return;
    }
    $st->bind_param('i', $projectId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!is_array($row) || !drawdream_project_service_charge_due_from_row($row)) {
        return;
    }
    if (!empty($row['service_charge_paid_at'])) {
        return;
    }

    drawdream_project_sync_service_charge_for_project($conn, $projectId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!is_array($row)) {
        return;
    }

    $scAmt = (float)($row['service_charge'] ?? 0);
    if ($scAmt <= 0) {
        $raised = (float)($row['current_donate'] ?? 0);
        $scAmt = drawdream_needlist_compute_service_charge($raised);
    }
    if ($scAmt <= 0) {
        return;
    }

    $foundationUserId = (int)($row['foundation_user_id'] ?? 0);
    if ($foundationUserId <= 0) {
        return;
    }

    $projName = (string)($row['project_name'] ?? '');
    $total = number_format((float)($row['current_donate'] ?? 0), 2);
    $scLabel = number_format($scAmt, 2);
    $goal = (float)($row['goal_amount'] ?? 0);
    $raised = (float)($row['current_donate'] ?? 0);
    $goalMet = $goal > 0 && drawdream_project_goal_met($raised, $goal);
    $notifTitle = $goalMet
        ? 'โครงการของคุณได้รับเงินครบแล้ว! 🎉'
        : 'โครงการของคุณปิดรับบริจาคแล้ว 📅';
    $notifMsg = "โครงการ \"{$projName}\" ได้รับเงินบริจาครวม {$total} บาท "
        . "กรุณาชำระค่าบริการระบบ {$scLabel} บาท (5%) จากหน้ารายละเอียดโครงการ "
        . 'ก่อนแอดมินยืนยันโอนเงิน escrow';
    $notifLink = drawdream_project_service_charge_view_link($projectId);
    drawdream_send_notification($conn, $foundationUserId, '', $notifTitle, $notifMsg, $notifLink);
}

/** @param array<string,mixed> $row */
function drawdream_project_has_outcome_posted(array $row): bool
{
    if (trim((string)($row['update_text'] ?? '')) !== '') {
        return true;
    }
    $raw = trim((string)($row['update_images'] ?? ''));
    if ($raw === '') {
        return false;
    }
    $arr = json_decode($raw, true);

    return is_array($arr) && count($arr) > 0;
}

/**
 * แท็บสถานะมุมมองมูลนิธิ — หลังครบเป้า/ปิดรับ
 *
 * @param array<string,mixed> $row
 * @return array{label:string,class:string}
 */
function drawdream_foundation_project_workflow_pill(array $row): array
{
    $pst = strtolower(trim((string)($row['project_status'] ?? '')));
    if ($pst === '') {
        $pst = 'pending';
    }

    $early = [
        'pending' => ['label' => 'รอดำเนินการ', 'class' => 'st-pending'],
        'approved' => ['label' => 'กำลังระดมทุน', 'class' => 'st-approved'],
        'rejected' => ['label' => 'ไม่ผ่านการอนุมัติ', 'class' => 'st-rejected'],
        'done' => ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'],
    ];
    if (isset($early[$pst])) {
        return $early[$pst];
    }

    if (drawdream_project_has_outcome_posted($row)) {
        return ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'];
    }

    if ($pst === 'purchasing') {
        return ['label' => 'รออัปเดตผลลัพธ์', 'class' => 'st-await-outcome'];
    }

    $scDue = drawdream_project_service_charge_due_from_row($row);
    $scPaid = trim((string)($row['service_charge_paid_at'] ?? '')) !== '';

    if ($scDue && !$scPaid) {
        return ['label' => 'ชำระค่าบริการ', 'class' => 'st-sc-due'];
    }

    if ($scDue && $scPaid) {
        return ['label' => 'รอแอดมินโอนเงิน', 'class' => 'st-wait-admin'];
    }

    if ($pst === 'completed') {
        return ['label' => 'โครงการสำเร็จแล้ว', 'class' => 'st-completed'];
    }

    return ['label' => $pst, 'class' => 'st-pending'];
}

/** แอดมินโอน escrow แล้ว — มูลนิธิควรอัปเดตผลลัพธ์ */
function drawdream_foundation_project_admin_escrow_released(array $row): bool
{
    $pst = strtolower(trim((string)($row['project_status'] ?? '')));

    return in_array($pst, ['purchasing', 'done'], true);
}
