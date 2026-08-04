<?php
declare(strict_types=1);

require_once __DIR__ . '/drawdream_project_service_charge.php';
require_once __DIR__ . '/child_sponsorship.php';
require_once __DIR__ . '/child_omise_subscription.php';
require_once __DIR__ . '/escrow_funds_schema.php';

/**
 * สถิติงานปฏิบัติการ (Ops) สำหรับแถบสถานะแดชบอร์ดมูลนิธิ
 *
 * @return array{
 *   groups: list<array{key:string,title:string,items:list<array{key:string,label:string,count:int,href:string,urgent:bool}>}>,
 *   escrow_pending_baht: float,
 *   need_awaiting_delivery: int,
 *   active_sponsors: int,
 *   child_outcome_due: int
 * }
 */
function foundation_dashboard_build_ops_stats(mysqli $conn, int $foundationId, string $foundationName, ?array $childIds = null, ?int $activeSponsors = null): array
{
    if ($foundationId <= 0) {
        return [
            'groups' => [],
            'escrow_pending_baht' => 0.0,
            'need_awaiting_delivery' => 0,
            'active_sponsors' => 0,
            'child_outcome_due' => 0,
        ];
    }

    $fn = trim($foundationName);
    $projScope = '(foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))';
    $monthStart = (new DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

    $count = static function (mysqli $conn, string $sql, string $types, array $params): int {
        $st = $conn->prepare($sql);
        if (!$st) {
            return 0;
        }
        if ($types !== '' && $params !== []) {
            $st->bind_param($types, ...$params);
        }
        $st->execute();

        return (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    };

    $projFundraising = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) = 'approved'
           AND COALESCE(goal_amount,0) > 0
           AND COALESCE(current_donate,0) < COALESCE(goal_amount,0) - 0.01",
        'is',
        [$foundationId, $fn]
    );

    $projGoalMetSc = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) IN ('approved','completed')
           AND COALESCE(goal_amount,0) > 0
           AND COALESCE(current_donate,0) >= COALESCE(goal_amount,0) - 0.01
           AND (service_charge_paid_at IS NULL OR TRIM(COALESCE(service_charge_paid_at,'')) = '')",
        'is',
        [$foundationId, $fn]
    );

    $projWaitEscrow = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at,'')) <> ''
           AND LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('purchasing','done')",
        'is',
        [$foundationId, $fn]
    );

    $projOutcomeDue = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE {$projScope}
           AND LOWER(TRIM(COALESCE(project_status,''))) IN ('purchasing','done')
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )",
        'iss',
        [$foundationId, $fn, $monthStart]
    );

    $childIds = $childIds ?? [];
    if ($childIds === []) {
        $stChild = $conn->prepare(
            'SELECT child_id FROM foundation_children WHERE foundation_id = ?'
        );
        if ($stChild) {
            $stChild->bind_param('i', $foundationId);
            $stChild->execute();
            $rs = $stChild->get_result();
            while ($row = $rs->fetch_assoc()) {
                $childIds[] = (int)($row['child_id'] ?? 0);
            }
        }
    }

    if ($activeSponsors === null) {
        $activeSponsors = 0;
        if ($childIds !== []) {
            $activeMap = drawdream_child_ids_with_active_plan_sponsorship($conn, $childIds);
            $activeSponsors = count($activeMap);
        }
    }

    $childOutcomeDue = drawdream_foundation_count_children_outcome_due_cached($conn, $foundationId);

    $needPending = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'pending'",
        'i',
        [$foundationId]
    );

    $needGoalMetSc = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'approved'
           AND COALESCE(total_price,0) > 0
           AND COALESCE(current_donate,0) >= COALESCE(total_price,0) - 0.01
           AND (service_charge_paid_at IS NULL OR TRIM(COALESCE(service_charge_paid_at,'')) = '')",
        'i',
        [$foundationId]
    );

    $needOutcomeDue = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) = 'done'
           AND (
             update_at IS NULL
             OR update_at < ?
             OR COALESCE(TRIM(update_text), '') = ''
           )",
        'is',
        [$foundationId, $monthStart]
    );

    $escrowPending = foundation_dashboard_escrow_pending_baht($conn, $foundationId, $fn);

    $needAwaitingDelivery = $count(
        $conn,
        "SELECT COUNT(*) AS c FROM foundation_needlist
         WHERE foundation_id = ?
           AND LOWER(TRIM(COALESCE(approve_item,''))) IN ('approved','purchasing')
           AND service_charge_paid_at IS NOT NULL
           AND TRIM(COALESCE(service_charge_paid_at,'')) <> ''
           AND COALESCE(total_price,0) > 0
           AND COALESCE(current_donate,0) >= COALESCE(total_price,0) - 0.01",
        'i',
        [$foundationId]
    );

    $groups = [
        [
            'key' => 'project',
            'title' => 'โครงการ',
            'items' => [
                [
                    'key' => 'fundraising',
                    'label' => 'กำลังระดม',
                    'count' => $projFundraising,
                    'href' => 'foundation_projects_directory.php',
                    'urgent' => false,
                ],
                [
                    'key' => 'goal_met_sc',
                    'label' => 'ครบเป้ารอชำระค่าบริการ',
                    'count' => $projGoalMetSc,
                    'href' => 'foundation_projects_directory.php',
                    'urgent' => $projGoalMetSc > 0,
                ],
                [
                    'key' => 'wait_escrow',
                    'label' => 'รอแอดมินโอน',
                    'count' => $projWaitEscrow,
                    'href' => 'foundation_projects_directory.php',
                    'urgent' => $projWaitEscrow > 0,
                ],
                [
                    'key' => 'outcome_due',
                    'label' => 'รอโพสต์ผล',
                    'count' => $projOutcomeDue,
                    'href' => 'foundation_bulk_project_outcome.php',
                    'urgent' => $projOutcomeDue > 0,
                ],
            ],
        ],
        [
            'key' => 'child',
            'title' => 'เด็ก / อุปการะ',
            'items' => [
                [
                    'key' => 'active_sponsors',
                    'label' => 'มีผู้อุปการะรายรอบ',
                    'count' => $activeSponsors,
                    'href' => 'foundation_children_directory.php',
                    'urgent' => false,
                ],
                [
                    'key' => 'outcome_due',
                    'label' => 'ถึงกำหนดส่งข้อความ',
                    'count' => $childOutcomeDue,
                    'href' => 'foundation_bulk_child_outcome.php',
                    'urgent' => $childOutcomeDue > 0,
                ],
            ],
        ],
        [
            'key' => 'need',
            'title' => 'รายการสิ่งของ',
            'items' => [
                [
                    'key' => 'pending',
                    'label' => 'รออนุมัติ',
                    'count' => $needPending,
                    'href' => 'foundation_needlist_directory.php',
                    'urgent' => $needPending > 0,
                ],
                [
                    'key' => 'goal_met_sc',
                    'label' => 'ครบเป้ารอชำระค่าบริการ',
                    'count' => $needGoalMetSc,
                    'href' => 'foundation_needlist_directory.php',
                    'urgent' => $needGoalMetSc > 0,
                ],
                [
                    'key' => 'outcome_due',
                    'label' => 'รอโพสต์ผลจัดส่ง',
                    'count' => $needOutcomeDue,
                    'href' => 'foundation_bulk_needlist_outcome.php',
                    'urgent' => $needOutcomeDue > 0,
                ],
            ],
        ],
    ];

    return [
        'groups' => $groups,
        'escrow_pending_baht' => $escrowPending,
        'need_awaiting_delivery' => $needAwaitingDelivery,
        'active_sponsors' => $activeSponsors,
        'child_outcome_due' => $childOutcomeDue,
    ];
}

/** ยอดเงินค้างรับเฉพาะโครงการ — ไม่รวมรายการสิ่งของ */
function foundation_dashboard_escrow_pending_baht(mysqli $conn, int $foundationId, string $foundationName): float
{
    $fn = trim($foundationName);

    $sqlProj = "
        SELECT COALESCE(SUM(ef.amount), 0) AS total
        FROM escrow_funds ef
        INNER JOIN foundation_project p ON p.project_id = ef.target_id
        WHERE ef.status = 'holding'
          AND ef.target_type = 'project'
         
          AND (p.foundation_id = ? OR (p.foundation_id IS NULL AND p.foundation_name = ?))
    ";
    $stP = $conn->prepare($sqlProj);
    if (!$stP) {
        return 0.0;
    }
    $stP->bind_param('is', $foundationId, $fn);
    $stP->execute();

    return (float)($stP->get_result()->fetch_assoc()['total'] ?? 0);
}

/**
 * สรุปบริจาครายเดือน (เดือนนี้ / เดือนก่อน) จากฐานข้อมูลทั้งหมด
 *
 * @return array{
 *   this_month: array{key:string,sum:float,count:int},
 *   prev_month: array{key:string,sum:float,count:int},
 *   last_donation_at: string,
 *   total_donation_count: int
 * }
 */
function foundation_dashboard_donation_period_meta(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat
): array {
    $fn = trim($foundationName);
    $tz = new DateTimeZone('Asia/Bangkok');
    $now = new DateTimeImmutable('now', $tz);
    $thisKey = $now->format('Y-m');
    $prevKey = $now->modify('first day of last month')->format('Y-m');

    $baseWhere = "
        LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
        AND (
            (d.category_id = ? AND d.target_id IN (
                SELECT child_id FROM foundation_children
                WHERE foundation_id = ?
            ))
            OR (d.category_id = ? AND d.target_id IN (
                SELECT project_id FROM foundation_project
                WHERE (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))
            ))
            OR (d.category_id = ? AND d.target_id = ?)
        )
    ";

    $monthAgg = static function (mysqli $conn, string $thisKey, string $prevKey, string $where, string $types, array $params): array {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ? THEN d.amount ELSE 0 END), 0) AS this_sum,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ? THEN 1 ELSE 0 END), 0) AS this_cnt,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ? THEN d.amount ELSE 0 END), 0) AS prev_sum,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ? THEN 1 ELSE 0 END), 0) AS prev_cnt,
                    COUNT(*) AS total_cnt
                FROM donation d
                WHERE {$where}";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [
                'this_month' => ['sum' => 0.0, 'count' => 0],
                'prev_month' => ['sum' => 0.0, 'count' => 0],
                'total_donation_count' => 0,
            ];
        }
        $bindTypes = $types . 'ssss';
        $bindParams = array_merge($params, [$thisKey, $thisKey, $prevKey, $prevKey]);
        $st->bind_param($bindTypes, ...$bindParams);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: [];

        return [
            'this_month' => ['sum' => (float)($row['this_sum'] ?? 0), 'count' => (int)($row['this_cnt'] ?? 0)],
            'prev_month' => ['sum' => (float)($row['prev_sum'] ?? 0), 'count' => (int)($row['prev_cnt'] ?? 0)],
            'total_donation_count' => (int)($row['total_cnt'] ?? 0),
        ];
    };

    $baseTypes = 'iiiiisi';
    $baseParams = [$childCat, $foundationId, $projCat, $foundationId, $fn, $needCat, $foundationId];

    $agg = $monthAgg($conn, $thisKey, $prevKey, $baseWhere, $baseTypes, $baseParams);

    return [
        'this_month' => ['key' => $thisKey, 'sum' => $agg['this_month']['sum'], 'count' => $agg['this_month']['count']],
        'prev_month' => ['key' => $prevKey, 'sum' => $agg['prev_month']['sum'], 'count' => $agg['prev_month']['count']],
        'last_donation_at' => '',
        'total_donation_count' => $agg['total_donation_count'],
    ];
}
