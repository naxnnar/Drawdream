<?php
// includes/foundation_analytics.php — ข้อมูลรายงานเชิงวิเคราะห์ต่อมูลนิธิ (แอดมิน)
// สรุปสั้น: คำนวณข้อมูลสถิติที่ใช้สร้างรายงาน analytics ของมูลนิธิ
declare(strict_types=1);

require_once __DIR__ . '/donate_category_resolve.php';

/**
 * @return array{foundation_id:int,foundation_name:string,account_verified:int}|null
 */
function drawdream_foundation_analytics_profile(mysqli $conn, int $foundationId): ?array
{
    if ($foundationId <= 0) {
        return null;
    }
    $st = $conn->prepare(
        'SELECT foundation_id, foundation_name, account_verified FROM foundation_profile WHERE foundation_id = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return is_array($row) ? $row : null;
}

/**
 * @return array{
 *   childCat:int,
 *   projCat:int,
 *   needCat:int,
 *   foundationName:string,
 *   sumChild:float,
 *   sumProject:float,
 *   sumNeed:float,
 *   sumTotal:float,
 *   donationRowCount:int,
 *   cntChildProfiles:int,
 *   cntProjects:int,
 *   needItemCnt:int
 * }
 */
function drawdream_foundation_analytics_totals(mysqli $conn, int $foundationId, string $foundationName): array
{
    $childCat = drawdream_get_or_create_child_donate_category_id($conn);
    $projCat = drawdream_get_or_create_project_donate_category_id($conn);
    $needCat = drawdream_get_or_create_needitem_donate_category_id($conn);

    $sql = "
    SELECT d.category_id, SUM(d.amount) AS amt, COUNT(*) AS n
    FROM donation d
    WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
      AND (
        (d.category_id = ? AND d.target_id IN (
            SELECT child_id FROM foundation_children
            WHERE foundation_id = ? AND deleted_at IS NULL
        ))
        OR (d.category_id = ? AND d.target_id IN (
            SELECT project_id FROM foundation_project
            WHERE deleted_at IS NULL
              AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))
        ))
        OR (d.category_id = ? AND d.target_id = ?)
      )
    GROUP BY d.category_id
    ";
    $st = $conn->prepare($sql);
    $sumChild = 0.0;
    $sumProject = 0.0;
    $sumNeed = 0.0;
    $donationRowCount = 0;
    if ($st) {
        $st->bind_param(
            'iiiiisi',
            $childCat,
            $foundationId,
            $projCat,
            $foundationId,
            $foundationName,
            $needCat,
            $foundationId
        );
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $cid = (int)($r['category_id'] ?? 0);
            $amt = (float)($r['amt'] ?? 0);
            $n = (int)($r['n'] ?? 0);
            $donationRowCount += $n;
            if ($cid === $childCat) {
                $sumChild += $amt;
            } elseif ($cid === $projCat) {
                $sumProject += $amt;
            } elseif ($cid === $needCat) {
                $sumNeed += $amt;
            }
        }
    }

    $cntChildProfiles = 0;
    $stC = $conn->prepare('SELECT COUNT(*) AS c FROM foundation_children WHERE foundation_id = ? AND deleted_at IS NULL');
    if ($stC) {
        $stC->bind_param('i', $foundationId);
        $stC->execute();
        $cntChildProfiles = (int)($stC->get_result()->fetch_assoc()['c'] ?? 0);
    }

    $cntProjects = 0;
    $stP = $conn->prepare(
        "SELECT COUNT(*) AS c FROM foundation_project
         WHERE deleted_at IS NULL
           AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
    );
    if ($stP) {
        $stP->bind_param('is', $foundationId, $foundationName);
        $stP->execute();
        $cntProjects = (int)($stP->get_result()->fetch_assoc()['c'] ?? 0);
    }

    $needItemCnt = 0;
    $stN = $conn->prepare('SELECT COUNT(*) AS c FROM foundation_needlist WHERE foundation_id = ?');
    if ($stN) {
        $stN->bind_param('i', $foundationId);
        $stN->execute();
        $needItemCnt = (int)($stN->get_result()->fetch_assoc()['c'] ?? 0);
    }

    return [
        'childCat' => $childCat,
        'projCat' => $projCat,
        'needCat' => $needCat,
        'foundationName' => $foundationName,
        'sumChild' => $sumChild,
        'sumProject' => $sumProject,
        'sumNeed' => $sumNeed,
        'sumTotal' => $sumChild + $sumProject + $sumNeed,
        'donationRowCount' => $donationRowCount,
        'cntChildProfiles' => $cntChildProfiles,
        'cntProjects' => $cntProjects,
        'needItemCnt' => $needItemCnt,
    ];
}

/**
 * ยอดตาม category_id (อ้างอิง donate_category / ประเภทหลักของมูลนิธิ)
 *
 * @return list<array{label:string,amount:float,count:int,pct:float}>
 */
function drawdream_foundation_analytics_popular_categories(mysqli $conn, int $foundationId, string $foundationName): array
{
    $childCat = drawdream_get_or_create_child_donate_category_id($conn);
    $projCat = drawdream_get_or_create_project_donate_category_id($conn);
    $needCat = drawdream_get_or_create_needitem_donate_category_id($conn);

    $sql = "
    SELECT d.category_id, SUM(d.amount) AS amt, COUNT(*) AS n
    FROM donation d
    WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
      AND (
        (d.category_id = ? AND d.target_id IN (
            SELECT child_id FROM foundation_children
            WHERE foundation_id = ? AND deleted_at IS NULL
        ))
        OR (d.category_id = ? AND d.target_id IN (
            SELECT project_id FROM foundation_project
            WHERE deleted_at IS NULL
              AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))
        ))
        OR (d.category_id = ? AND d.target_id = ?)
      )
    GROUP BY d.category_id
    ORDER BY amt DESC
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $st->bind_param(
        'iiiiisi',
        $childCat,
        $foundationId,
        $projCat,
        $foundationId,
        $foundationName,
        $needCat,
        $foundationId
    );
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float)($r['amt'] ?? 0);
    }
    $out = [];
    foreach ($rows as $r) {
        $cid = (int)($r['category_id'] ?? 0);
        $amt = (float)($r['amt'] ?? 0);
        $n = (int)($r['n'] ?? 0);
        if ($cid === $childCat) {
            $label = 'บริจาคให้เด็ก (หมวดเด็ก)';
        } elseif ($cid === $projCat) {
            $label = 'บริจาคโครงการ (หมวดโครงการ)';
        } elseif ($cid === $needCat) {
            $label = 'บริจาคสิ่งของ (หมวดสิ่งของ)';
        } else {
            $dc = $conn->prepare(
                'SELECT child_donate, project_donate, needitem_donate FROM donate_category WHERE category_id = ? LIMIT 1'
            );
            $label = 'หมวดหมู่ #' . $cid;
            if ($dc) {
                $dc->bind_param('i', $cid);
                $dc->execute();
                $dr = $dc->get_result()->fetch_assoc();
                if (is_array($dr)) {
                    foreach (['child_donate', 'project_donate', 'needitem_donate'] as $col) {
                        $v = trim((string)($dr[$col] ?? ''));
                        if ($v !== '' && $v !== '-') {
                            $label = $v;
                            break;
                        }
                    }
                }
            }
        }
        $pct = $total > 0 ? round(($amt / $total) * 100, 1) : 0.0;
        $out[] = ['label' => $label, 'amount' => $amt, 'count' => $n, 'pct' => $pct];
    }

    return $out;
}

/**
 * อุปการะเด็ก (Sponsorship) ต่อมูลนิธิ — นับแถว donate_type = child_subscription
 *
 * @return array{
 *   monthly: array{cancelled:int,active:int,paused:int,other:int,denom:int,cancel_pct:?float},
 *   all_plans: array{cancelled:int,active:int,paused:int,other:int,denom:int,cancel_pct:?float}
 * }
 */
function drawdream_foundation_analytics_sponsorship(mysqli $conn, int $foundationId, int $childCat): array
{
    $baseChildIn = 'd.target_id IN (SELECT child_id FROM foundation_children WHERE foundation_id = ? AND deleted_at IS NULL)';
    $sqlMonthly = "
    SELECT LOWER(TRIM(COALESCE(d.recurring_status, ''))) AS rs, COUNT(*) AS c
    FROM donation d
    WHERE d.donate_type = 'child_subscription'
      AND d.category_id = ?
      AND LOWER(TRIM(COALESCE(d.recurring_plan_code, ''))) = 'monthly'
      AND (LOWER(TRIM(COALESCE(d.payment_status, ''))) IN ('completed', 'subscription'))
      AND {$baseChildIn}
    GROUP BY rs
    ";
    $sqlAll = "
    SELECT LOWER(TRIM(COALESCE(d.recurring_status, ''))) AS rs, COUNT(*) AS c
    FROM donation d
    WHERE d.donate_type = 'child_subscription'
      AND d.category_id = ?
      AND (LOWER(TRIM(COALESCE(d.payment_status, ''))) IN ('completed', 'subscription'))
      AND {$baseChildIn}
    GROUP BY rs
    ";

    $parse = static function (array $aggRows): array {
        $cancelled = 0;
        $active = 0;
        $paused = 0;
        $other = 0;
        foreach ($aggRows as $row) {
            $rs = (string)($row['rs'] ?? '');
            $c = (int)($row['c'] ?? 0);
            if ($rs === 'cancelled') {
                $cancelled += $c;
            } elseif ($rs === 'active') {
                $active += $c;
            } elseif ($rs === 'paused') {
                $paused += $c;
            } else {
                $other += $c;
            }
        }
        $denom = $cancelled + $active + $paused;
        $cancelPct = $denom > 0 ? round(($cancelled / $denom) * 100, 1) : null;

        return [
            'cancelled' => $cancelled,
            'active' => $active,
            'paused' => $paused,
            'other' => $other,
            'denom' => $denom,
            'cancel_pct' => $cancelPct,
        ];
    };

    $run = static function (mysqli $conn, string $sql, int $childCat, int $foundationId) use ($parse): array {
        $st = $conn->prepare($sql);
        if (!$st) {
            return $parse([]);
        }
        $st->bind_param('ii', $childCat, $foundationId);
        $st->execute();
        $agg = $st->get_result()->fetch_all(MYSQLI_ASSOC);

        return $parse($agg);
    };

    return [
        'monthly' => $run($conn, $sqlMonthly, $childCat, $foundationId),
        'all_plans' => $run($conn, $sqlAll, $childCat, $foundationId),
    ];
}
