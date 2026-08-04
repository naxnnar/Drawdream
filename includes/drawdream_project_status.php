<?php
// includes/drawdream_project_status.php — มาตรฐานสถานะโครงการ + normalize DB
// สรุปสั้น: รวมกติกาแปลงและตรวจสถานะโครงการให้ทุกหน้าตีความตรงกัน
// โครงการบางแถวเก็บสถานะภาษาไทย (เช่น รอดำเนินการ) แต่โค้ดส่วนใหญ่ใช้ pending/approved/rejected — ปรับค่าและเงื่อนไขให้สอดคล้องกัน
/**
 * ไฟล์นี้เป็น "ศูนย์กลางเรื่องสถานะโครงการ"
 * เพื่อแก้ปัญหาในระบบจริงที่เคยมีทั้งสถานะไทย/อังกฤษปะปนกัน
 * แล้วทำให้เงื่อนไข filter หน้า donor/admin เพี้ยน
 */

declare(strict_types=1);

/** อัปเดตค่าเก่าในตารางให้ใช้รหัสภาษาอังกฤษ (รันได้ซ้ำ ไม่กระทบแถวที่ถูกต้องแล้ว) */
function drawdream_normalize_foundation_project_statuses(mysqli $conn): void
{
    // mapping นี้ตั้งใจให้เรียกซ้ำได้ปลอดภัย (idempotent)
    $conn->query(
        "UPDATE foundation_project SET project_status = 'pending'
         WHERE TRIM(COALESCE(project_status,'')) IN ('รอดำเนินการ','รอดำนิการ','Pending','PENDING')
        "
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'approved'
         WHERE TRIM(COALESCE(project_status,'')) IN ('อนุมัติ','Approved','APPROVED')
        "
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'rejected'
         WHERE TRIM(COALESCE(project_status,'')) IN ('ไม่อนุมัติ','ปฏิเสธ','Rejected','REJECTED')
        "
    );
    $conn->query(
        "UPDATE foundation_project SET project_status = 'completed'
         WHERE TRIM(COALESCE(project_status,'')) IN ('เสร็จสิ้น','สำเร็จ','Completed','COMPLETED')
        "
    );
}

/**
 * สถานะที่ผู้บริจาคเห็น: fundraising | completed | closed
 * purchasing (หลัง escrow) นับเป็น completed
 *
 * @param array<string,mixed> $row
 */
function drawdream_donor_project_effective_state(array $row): string
{
    $goal = !empty($row['goal_amount']) ? (float)$row['goal_amount'] : 0.0;
    $raised = (float)($row['current_donate'] ?? 0);
    $dbSt = strtolower(trim((string)($row['project_status'] ?? '')));

    $half = ($goal > 0) ? ($goal * 0.5) : 0.0;

    $endRaw = $row['end_date'] ?? null;
    $ended = false;
    if (!empty($endRaw)) {
        try {
            $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
            $endDay = substr((string)$endRaw, 0, 10);
            $ended = ($endDay !== '' && $endDay < $today);
        } catch (Exception $e) {
            $ended = false;
        }
    }

    if (in_array($dbSt, ['completed', 'done', 'purchasing'], true)) {
        return 'completed';
    }

    if ($raised >= $goal && $goal > 0) {
        return 'completed';
    }

    if ($ended) {
        if ($raised >= $half) {
            return 'completed';
        }

        return 'closed';
    }

    return 'fundraising';
}

/** นับโครงการที่ระดมทุนสำเร็จ — สอดคล้อง filter «เสร็จสิ้น» บน project.php */
function drawdream_count_donor_completed_projects(mysqli $conn): int
{
    drawdream_normalize_foundation_project_statuses($conn);

    $rs = $conn->query(
        "SELECT goal_amount, current_donate, project_status, end_date
         FROM foundation_project
         WHERE LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('pending','rejected')"
    );
    if (!$rs) {
        return 0;
    }

    $count = 0;
    while ($row = $rs->fetch_assoc()) {
        if (drawdream_donor_project_effective_state($row) === 'completed') {
            $count++;
        }
    }

    return $count;
}

/**
 * นิพจน์ SQL สำหรับ WHERE — โครงการรอแอดมินอุมัติ
 *
 * @param string $col เช่น "project_status" หรือ "p.project_status"
 */
function drawdream_sql_project_is_pending(string $col = 'project_status'): string
{
    // ไม่ได้ query DB — แค่คืนข้อความ SQL ให้เอาไปใส่ใน WHERE ต่อ
    // รับมือทั้ง "pending" / "รอดำเนินการ" / typo เก่าในฐานข้อมูล ไว้ที่เดียว
    $safe = preg_replace('/[^a-zA-Z0-9_.]/', '', $col);
    if ($safe === '') {
        $safe = 'project_status';
    }
    return "(LOWER(TRIM(COALESCE({$safe},''))) = 'pending' OR TRIM({$safe}) IN ('รอดำเนินการ','รอดำนิการ'))";
}

/** อัปเดตสถานะโครงการที่ครบเป้าหมายหรือหมดเวลา — เรียกจาก project.php แทน profile.php */
function drawdream_check_completed_foundation_projects(mysqli $conn): void
{
    if (!function_exists('drawdream_project_notify_service_charge_due')) {
        require_once __DIR__ . '/drawdream_project_service_charge.php';
    }

    $rs = $conn->query(
        "SELECT project_id FROM foundation_project
         WHERE project_status = 'approved'
           AND end_date IS NOT NULL
           AND end_date < CURDATE()
           AND COALESCE(current_donate, 0) > 0"
    );
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $pid = (int)($row['project_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            drawdream_project_sync_service_charge_for_project($conn, $pid);
            $upd = $conn->prepare(
                "UPDATE foundation_project SET project_status = 'completed', completed_at = NOW() WHERE project_id = ?"
            );
            if ($upd) {
                $upd->bind_param('i', $pid);
                $upd->execute();
            }
            drawdream_project_notify_service_charge_due($conn, $pid);
        }
    }

    $conn->query(
        "UPDATE foundation_project
         SET project_status = 'completed'
         WHERE project_status = 'approved'
           AND current_donate >= goal_amount
           AND goal_amount > 0"
    );
    $conn->query(
        "UPDATE foundation_project
         SET project_status = 'completed'
         WHERE project_status = 'approved'
           AND end_date < CURDATE()"
    );
}
