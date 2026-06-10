<?php
declare(strict_types=1);

require_once __DIR__ . '/drawdream_project_service_charge.php';
require_once __DIR__ . '/child_sponsorship.php';

/**
 * รายการงานค้าง / Action items สำหรับแดชบอร์ดมูลนิธิ (อิงข้อมูลจริง)
 *
 * @return list<array{text: string, href: string, priority: string}>
 */
function foundation_dashboard_build_todos(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    bool $accountVerified
): array {
    if ($foundationId <= 0) {
        return [];
    }

    drawdream_ensure_foundation_project_service_charge_columns($conn);

    $todos = [];
    $fn = trim($foundationName);
    $monthStart = (new DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

    if (!$accountVerified) {
        $todos[] = [
            'priority' => 'high',
            'text' => 'บัญชีมูลนิธียังไม่ได้รับการยืนยัน — ตรวจสอบโปรไฟล์และเอกสารให้ครบก่อนเปิดรับบริจาคเต็มรูปแบบ',
            'href' => 'profile.php',
        ];
    }

    $projScope = '(foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))';

    // โครงการครบเป้า ยังไม่ชำระค่าบริการระบบ
    $stScDue = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) IN ('approved','completed')
           AND COALESCE(goal_amount,0) > 0
           AND COALESCE(current_donate,0) >= COALESCE(goal_amount,0) - 0.01
           AND (service_charge_paid_at IS NULL OR TRIM(COALESCE(service_charge_paid_at,'')) = '')"
    );
    if ($stScDue) {
        $stScDue->bind_param('is', $foundationId, $fn);
        $stScDue->execute();
        $n = (int)($stScDue->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'high',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'ระดมทุนครบแล้ว — ชำระค่าบริการระบบเพื่อให้ดำเนินการต่อได้',
                'href' => 'foundation_projects_directory.php',
            ];
        }
    }

    // ชำระค่าบริการแล้ว รอแอดมินโอน escrow
    $stWaitAdmin = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at,'')) <> ''
           AND LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('purchasing','done')"
    );
    if ($stWaitAdmin) {
        $stWaitAdmin->bind_param('is', $foundationId, $fn);
        $stWaitAdmin->execute();
        $n = (int)($stWaitAdmin->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'normal',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'ชำระค่าบริการแล้ว — รอแอดมินยืนยันโอนเงิน (หลังนั้นจะอัปโหลดผลลัพธ์ได้)',
                'href' => 'foundation_projects_directory.php',
            ];
        }
    }

    // โครงการพร้อมโพสต์ผลลัพธ์ (purchasing/done) แต่ยังไม่อัปเดตเดือนนี้
    $stProjOutcome = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) IN ('purchasing','done')
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )"
    );
    if ($stProjOutcome) {
        $stProjOutcome->bind_param('iss', $foundationId, $fn, $monthStart);
        $stProjOutcome->execute();
        $n = (int)($stProjOutcome->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'high',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'พร้อมอัปเดตผลลัพธ์แล้ว — โพสต์รูป/ข้อความให้ผู้บริจาคเห็น',
                'href' => 'foundation_post_update.php',
            ];
        }
    }

    // เด็กที่มีผู้อุปการะ — ต้องอัปเดตข้อความภายใน 1 เดือนนับจากวันเริ่มอุปการะ (รอบครบทุกเดือน)
    $childOutcomeDueCnt = drawdream_foundation_count_children_outcome_due($conn, $foundationId);
    if ($childOutcomeDueCnt > 0) {
        $todos[] = [
            'priority' => 'high',
            'text' => 'มีเด็ก ' . $childOutcomeDueCnt . ' คนที่ถึงกำหนดส่งข้อความจากเด็ก (รอบ 1 เดือนนับจากวันมีผู้อุปการะ) — อัปเดตให้ผู้อุปการะ',
            'href' => 'foundation_children_directory.php',
        ];
    }

    // รายการสิ่งของครบเป้า ยังไม่ชำระค่าบริการ
    $stNeedSc = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'approved'
           AND COALESCE(total_price,0) > 0
           AND COALESCE(current_donate,0) >= COALESCE(total_price,0) - 0.01
           AND (service_charge_paid_at IS NULL OR TRIM(COALESCE(service_charge_paid_at,'')) = '')"
    );
    if ($stNeedSc) {
        $stNeedSc->bind_param('i', $foundationId);
        $stNeedSc->execute();
        $n = (int)($stNeedSc->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'high',
                'text' => foundation_dashboard_todo_count_th($n, 'รายการสิ่งของ') . 'ระดมครบแล้ว — ชำระค่าบริการระบบเพื่อดำเนินการจัดซื้อ',
                'href' => 'foundation_needlist_directory.php',
            ];
        }
    }

    // สิ่งของสถานะ done (แอดมินจัดส่งแล้ว) รอมูลนิธิโพสต์ผล
    $stNeedOutcome = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'done'
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )"
    );
    if ($stNeedOutcome) {
        $stNeedOutcome->bind_param('is', $foundationId, $monthStart);
        $stNeedOutcome->execute();
        $n = (int)($stNeedOutcome->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'normal',
                'text' => foundation_dashboard_todo_count_th($n, 'รายการสิ่งของ') . 'จัดส่งแล้ว — โพสต์ผลการจัดส่งให้ผู้บริจาคเห็น',
                'href' => 'foundation_post_needlist_result.php',
            ];
        }
    }

    // โครงการรอแอดมินอนุมัติ
    $stProjPending = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) = 'pending'"
    );
    if ($stProjPending) {
        $stProjPending->bind_param('is', $foundationId, $fn);
        $stProjPending->execute();
        $n = (int)($stProjPending->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'normal',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'รอแอดมินอนุมัติ — ตรวจสอบว่าส่งข้อมูลครบหรือไม่',
                'href' => 'foundation_projects_directory.php',
            ];
        }
    }

    // โปรไฟล์เด็กรออนุมัติ
    $stChildPending = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_children
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_profile,''))) NOT IN ('approved','อนุมัติ','อนุมัติแล้ว','rejected','ไม่อนุมัติ')"
    );
    if ($stChildPending) {
        $stChildPending->bind_param('i', $foundationId);
        $stChildPending->execute();
        $n = (int)($stChildPending->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'normal',
                'text' => 'มีเด็ก ' . $n . ' คนที่โปรไฟล์ยังรออนุมัติจากแอดมิน',
                'href' => 'foundation_children_directory.php',
            ];
        }
    }

    // รายการสิ่งของรออนุมัติ
    $stNeedPending = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'pending'"
    );
    if ($stNeedPending) {
        $stNeedPending->bind_param('i', $foundationId);
        $stNeedPending->execute();
        $n = (int)($stNeedPending->get_result()->fetch_assoc()['c'] ?? 0);
        if ($n > 0) {
            $todos[] = [
                'priority' => 'normal',
                'text' => foundation_dashboard_todo_count_th($n, 'รายการสิ่งของ') . 'รอแอดมินอนุมัติ',
                'href' => 'foundation_needlist_directory.php',
            ];
        }
    }

    usort($todos, static function (array $a, array $b): int {
        $pa = ($a['priority'] ?? '') === 'high' ? 0 : 1;
        $pb = ($b['priority'] ?? '') === 'high' ? 0 : 1;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return 0;
    });

    return array_slice($todos, 0, 6);
}

function foundation_dashboard_todo_count_th(int $n, string $noun): string
{
    if ($n <= 0) {
        return '';
    }

    return 'มี ' . $n . ' ' . $noun . ' ';
}
