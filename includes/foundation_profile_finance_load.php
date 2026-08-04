<?php
// foundation_profile_finance_load.php — โหลดสรุปยอดบริจาคมูลนิธิ (ใช้ lazy จาก profile)

require_once __DIR__ . '/donate_category_resolve.php';

/**
 * @return array{
 *   finance_child_total: float,
 *   finance_project_total: float,
 *   finance_need_total: float,
 *   finance_grand_total: float,
 *   foundation_finance_rows: list<array<string, mixed>>
 * }
 */
function foundation_profile_load_finance(mysqli $conn, int $foundationId, string $foundationName): array
{
    $finance_child_total = 0.0;
    $finance_project_total = 0.0;
    $finance_need_total = 0.0;
    $foundation_finance_rows = [];

    $childDonateCategoryId = drawdream_get_or_create_child_donate_category_id($conn);

    if ($foundationId > 0) {
        $nq = $conn->prepare('SELECT COALESCE(SUM(current_donate), 0) AS t FROM foundation_needlist WHERE foundation_id = ?');
        $nq->bind_param('i', $foundationId);
        $nq->execute();
        $finance_need_total = (float)($nq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    if ($foundationName !== '') {
        $pq = $conn->prepare("
            SELECT COALESCE(SUM(d.amount), 0) AS t
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            INNER JOIN foundation_project p ON p.project_id = d.target_id AND p.foundation_name = ?
            WHERE d.payment_status = 'completed'
        ");
        $pq->bind_param('s', $foundationName);
        $pq->execute();
        $finance_project_total = (float)($pq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    if ($foundationId > 0 && $childDonateCategoryId > 0) {
        $cq = $conn->prepare("
            SELECT COALESCE(SUM(d.amount), 0) AS t
            FROM donation d
            INNER JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.foundation_id = ?
            WHERE d.category_id = ? AND d.payment_status = 'completed'
        ");
        $cq->bind_param('ii', $foundationId, $childDonateCategoryId);
        $cq->execute();
        $finance_child_total = (float)($cq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    $finance_grand_total = $finance_child_total + $finance_project_total + $finance_need_total;

    $historyUnionParts = [];
    $historyTypes = '';
    $historyParams = [];
    if ($foundationName !== '') {
        $historyUnionParts[] = "
            SELECT d.transfer_datetime AS ts, d.amount, 'project' AS cat_key, p.project_name AS title
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            INNER JOIN foundation_project p ON p.project_id = d.target_id AND p.foundation_name = ?
            WHERE d.payment_status = 'completed'";
        $historyTypes .= 's';
        $historyParams[] = $foundationName;
    }
    if ($foundationId > 0) {
        $historyUnionParts[] = "
            SELECT d.transfer_datetime AS ts, d.amount, 'need' AS cat_key, COALESCE(fp.foundation_name, 'มูลนิธิของคุณ') AS title
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
            LEFT JOIN foundation_profile fp ON fp.foundation_id = d.target_id
            WHERE d.payment_status = 'completed' AND d.target_id = ?";
        $historyTypes .= 'i';
        $historyParams[] = $foundationId;
    }
    if ($foundationId > 0 && $childDonateCategoryId > 0) {
        $historyUnionParts[] = "
            SELECT d.transfer_datetime AS ts, d.amount, 'child' AS cat_key, fc.child_name AS title
            FROM donation d
            INNER JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.foundation_id = ?
            WHERE d.category_id = ? AND d.payment_status = 'completed'";
        $historyTypes .= 'ii';
        $historyParams[] = $foundationId;
        $historyParams[] = $childDonateCategoryId;
    }
    if ($historyUnionParts !== []) {
        $historySql = 'SELECT ts, amount, cat_key, title FROM (' . implode(' UNION ALL ', $historyUnionParts) . ') u ORDER BY ts DESC LIMIT 100';
        $historyStmt = $conn->prepare($historySql);
        if ($historyStmt) {
            $historyStmt->bind_param($historyTypes, ...$historyParams);
            $historyStmt->execute();
            $foundation_finance_rows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    return [
        'finance_child_total' => $finance_child_total,
        'finance_project_total' => $finance_project_total,
        'finance_need_total' => $finance_need_total,
        'finance_grand_total' => $finance_grand_total,
        'foundation_finance_rows' => $foundation_finance_rows,
    ];
}
