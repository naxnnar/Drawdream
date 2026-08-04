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

    $todos = [];
    $fn = trim($foundationName);
    $monthStart = (new DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

    if (!$accountVerified) {
        $todos[] = [
            'key' => 'account_verify',
            'priority' => 'high',
            'action_label' => 'ตรวจโปรไฟล์',
            'text' => 'บัญชีมูลนิธียังรอการยืนยัน — ขั้นที่ 2: รอแอดมินตรวจ (โดยทั่วไป 1–3 วันทำการ) ตรวจโปรไฟล์ให้ครบ',
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
                'key' => 'project_service_charge',
                'priority' => 'high',
                'action_label' => 'ชำระค่าบริการโครงการ',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'ระดมทุนครบแล้ว — ชำระค่าบริการระบบเพื่อให้ดำเนินการต่อได้ (ทีละโครงการ)',
                'href' => 'foundation_projects_directory.php?task=service_charge',
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
                'key' => 'project_wait_escrow',
                'priority' => 'normal',
                'action_label' => 'ดูสถานะโครงการ',
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
                'key' => 'project_outcome',
                'priority' => 'high',
                'action_label' => 'อัปเดตโครงการ',
                'text' => foundation_dashboard_todo_count_th($n, 'โครงการ') . 'พร้อมอัปเดตผลลัพธ์แล้ว — โพสต์รูป/ข้อความให้ผู้บริจาคเห็น (หลายโครงการพร้อมกันได้)',
                'href' => 'foundation_bulk_project_outcome.php',
            ];
        }
    }

    // เด็กที่มีผู้อุปการะ — ต้องอัปเดตข้อความภายใน 1 เดือนนับจากวันเริ่มอุปการะ (รอบครบทุกเดือน)
    $childOutcomeDueCnt = drawdream_foundation_count_children_outcome_due_cached($conn, $foundationId);
    if ($childOutcomeDueCnt > 0) {
        $todos[] = [
            'key' => 'child_outcome',
            'priority' => 'high',
            'action_label' => 'อัปเดตจดหมายเด็ก',
            'text' => 'มีเด็ก ' . $childOutcomeDueCnt . ' คนที่ถึงกำหนดส่งข้อความจากเด็ก (รอบ 1 เดือนนับจากวันมีผู้อุปการะ) — อัปเดตให้ผู้อุปการะพร้อมกันได้',
            'href' => 'foundation_bulk_child_outcome.php',
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
                'key' => 'need_service_charge',
                'priority' => 'high',
                'action_label' => 'ชำระค่าบริการสิ่งของ',
                'text' => foundation_dashboard_todo_count_th($n, 'รายการสิ่งของ') . 'ระดมครบแล้ว — ชำระค่าบริการระบบเพื่อดำเนินการจัดซื้อ (ทีละรายการ)',
                'href' => 'foundation_needlist_directory.php?task=service_charge',
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
                'key' => 'need_outcome',
                'priority' => 'normal',
                'action_label' => 'อัปเดตผลสิ่งของ',
                'text' => foundation_dashboard_todo_count_th($n, 'รายการสิ่งของ') . 'จัดส่งแล้ว — โพสต์ผลการจัดส่งให้ผู้บริจาคเห็น (หลายรายการพร้อมกันได้)',
                'href' => 'foundation_bulk_needlist_outcome.php',
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
                'key' => 'project_pending',
                'priority' => 'normal',
                'action_label' => 'ดูโครงการ',
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
                'key' => 'child_pending',
                'priority' => 'normal',
                'action_label' => 'ดูรายชื่อเด็ก',
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
                'key' => 'need_pending',
                'priority' => 'normal',
                'action_label' => 'ดูรายการสิ่งของ',
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

function foundation_dashboard_first_need_item_id(mysqli $conn, int $foundationId, string $extraWhere): int
{
    if ($foundationId <= 0 || $extraWhere === '') {
        return 0;
    }
    $sql = "SELECT item_id FROM foundation_needlist WHERE foundation_id = ? AND {$extraWhere} ORDER BY item_id ASC LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    return (int)($st->get_result()->fetch_assoc()['item_id'] ?? 0);
}

function foundation_dashboard_need_wizard_href(int $itemId): string
{
    return $itemId > 0 ? ('foundation_need_wizard.php?item_id=' . $itemId) : 'foundation_needlist_directory.php';
}
