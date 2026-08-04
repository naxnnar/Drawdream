<?php
declare(strict_types=1);

require_once __DIR__ . '/drawdream_donor_receipt_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/foundation_dashboard_insights.php';

/**
 * @return array{select:string, from_where:string, scope_types:string, scope_params:list<mixed>}
 */
function foundation_dashboard_donation_query_parts(
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat
): array {
    $receiptCols = drawdream_donor_receipt_sql_selects();
    $selCompanyTax = $receiptCols['tax'];
    $selCompanyName = $receiptCols['name'];

    return [
        'select' => "
SELECT d.donate_id, d.amount, d.transfer_datetime, d.payment_status,
       d.omise_charge_id, dn.tax_id, d.donor_id,
       d.donate_type,
       d.category_id, d.target_id,
       dn.first_name, dn.last_name, u.email AS donor_email,
       {$selCompanyTax}, {$selCompanyName}",
        'from_where' => "
FROM donation d
LEFT JOIN donor dn ON dn.user_id = d.donor_id
LEFT JOIN `user` u ON u.user_id = d.donor_id
LEFT JOIN foundation_children fc
       ON fc.child_id = d.target_id AND fc.foundation_id = ? AND d.category_id = ?
LEFT JOIN foundation_project fp
       ON fp.project_id = d.target_id AND d.category_id = ?
      AND (fp.foundation_id = ? OR (fp.foundation_id IS NULL AND fp.foundation_name = ?))
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
  AND (
    fc.child_id IS NOT NULL
    OR fp.project_id IS NOT NULL
    OR (d.category_id = ? AND d.target_id = ?)
  )",
        'scope_types' => 'iiiisii',
        'scope_params' => [
            $foundationId,
            $childCat,
            $projCat,
            $foundationId,
            $foundationName,
            $needCat,
            $foundationId,
        ],
    ];
}

/**
 * @param array<string, string> $filters
 * @return array{sql:string, types:string, params:list<mixed>}
 */
function foundation_dashboard_donation_filter_sql(
    array $filters,
    int $childCat,
    int $projCat,
    int $needCat
): array {
    $extra = '';
    $types = '';
    $params = [];

    $cat = strtolower(trim((string)($filters['cat'] ?? 'all')));
    if ($cat === 'child') {
        $extra .= ' AND d.category_id = ?';
        $types .= 'i';
        $params[] = $childCat;
    } elseif ($cat === 'project') {
        $extra .= ' AND d.category_id = ?';
        $types .= 'i';
        $params[] = $projCat;
    } elseif ($cat === 'need') {
        $extra .= ' AND d.category_id = ?';
        $types .= 'i';
        $params[] = $needCat;
    }

    $weekKey = trim((string)($filters['week'] ?? ''));
    if ($weekKey !== '') {
        $extra .= " AND DATE_FORMAT(d.transfer_datetime, '%x-%v') = ?";
        $types .= 's';
        $params[] = $weekKey;
    } else {
        $dateMode = strtolower(trim((string)($filters['date_mode'] ?? 'all')));
        if ($dateMode === 'day') {
            $from = trim((string)($filters['date_from'] ?? ''));
            $to = trim((string)($filters['date_to'] ?? ''));
            if ($to === '') {
                $to = $from;
            }
            if ($from !== '') {
                $extra .= ' AND DATE(d.transfer_datetime) >= ? AND DATE(d.transfer_datetime) <= ?';
                $types .= 'ss';
                $params[] = $from;
                $params[] = $to;
            }
        } elseif ($dateMode === 'month') {
            $month = trim((string)($filters['date_month'] ?? ''));
            if ($month !== '') {
                $extra .= " AND DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ?";
                $types .= 's';
                $params[] = $month;
            }
        } elseif ($dateMode === 'year') {
            $year = trim((string)($filters['date_year'] ?? ''));
            if ($year !== '' && ctype_digit($year)) {
                $extra .= ' AND YEAR(d.transfer_datetime) = ?';
                $types .= 'i';
                $params[] = (int)$year;
            }
        }
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $extra .= ' AND (
            dn.first_name LIKE ? OR dn.last_name LIKE ?
            OR u.email LIKE ? OR CAST(d.donate_id AS CHAR) LIKE ?
            OR COALESCE(dn.tax_id, \'\') LIKE ?
        )';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    return ['sql' => $extra, 'types' => $types, 'params' => $params];
}

/**
 * @param array<string, string> $filters
 */
function foundation_dashboard_count_donations(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat,
    array $filters = []
): int {
    if ($foundationId <= 0 || $childCat <= 0 || $projCat <= 0 || $needCat <= 0) {
        return 0;
    }

    $parts = foundation_dashboard_donation_query_parts(
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat
    );
    $filter = foundation_dashboard_donation_filter_sql($filters, $childCat, $projCat, $needCat);
    $sql = 'SELECT COUNT(*) AS c ' . $parts['from_where'] . $filter['sql'];
    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }

    $types = $parts['scope_types'] . $filter['types'];
    $params = array_merge($parts['scope_params'], $filter['params']);
    $st->bind_param($types, ...$params);
    $st->execute();

    return (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
}

/**
 * จำนวนรายการบริจาคแยกตามประเภท (ใช้ตัวกรองวันที่/ค้นหา — ไม่รวม cat ที่เลือกอยู่)
 *
 * @param array<string, string> $filters
 * @return array{all:int, child:int, project:int, need:int}
 */
function foundation_dashboard_filter_category_counts(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat,
    array $filters = []
): array {
    $none = ['all' => 0, 'child' => 0, 'project' => 0, 'need' => 0];
    if ($foundationId <= 0 || $childCat <= 0 || $projCat <= 0 || $needCat <= 0) {
        return $none;
    }

    $filtersNoCat = $filters;
    $filtersNoCat['cat'] = 'all';

    $parts = foundation_dashboard_donation_query_parts(
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat
    );
    $filter = foundation_dashboard_donation_filter_sql($filtersNoCat, $childCat, $projCat, $needCat);
    $sql = 'SELECT COUNT(*) AS c_all,
            COALESCE(SUM(CASE WHEN d.category_id = ? THEN 1 ELSE 0 END), 0) AS c_child,
            COALESCE(SUM(CASE WHEN d.category_id = ? THEN 1 ELSE 0 END), 0) AS c_project,
            COALESCE(SUM(CASE WHEN d.category_id = ? THEN 1 ELSE 0 END), 0) AS c_need '
        . $parts['from_where'] . $filter['sql'];
    $st = $conn->prepare($sql);
    if (!$st) {
        return $none;
    }

    $types = $parts['scope_types'] . $filter['types'] . 'iii';
    $params = array_merge($parts['scope_params'], $filter['params'], [$childCat, $projCat, $needCat]);
    $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];

    return [
        'all' => (int)($row['c_all'] ?? 0),
        'child' => (int)($row['c_child'] ?? 0),
        'project' => (int)($row['c_project'] ?? 0),
        'need' => (int)($row['c_need'] ?? 0),
    ];
}

/**
 * @param array<string, string> $filters
 * @return list<array<string, mixed>>
 */
function foundation_dashboard_fetch_donation_rows(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat,
    int $limit,
    int $offset,
    array $filters = []
): array {
    if ($foundationId <= 0 || $childCat <= 0 || $projCat <= 0 || $needCat <= 0 || $limit <= 0) {
        return [];
    }

    $parts = foundation_dashboard_donation_query_parts(
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat
    );
    $filter = foundation_dashboard_donation_filter_sql($filters, $childCat, $projCat, $needCat);
    $sql = $parts['select'] . $parts['from_where'] . $filter['sql']
        . ' ORDER BY d.transfer_datetime DESC, d.donate_id DESC LIMIT ? OFFSET ?';
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }

    $types = $parts['scope_types'] . $filter['types'] . 'ii';
    $params = array_merge($parts['scope_params'], $filter['params'], [$limit, max(0, $offset)]);
    $st->bind_param($types, ...$params);
    $st->execute();

    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * @param array<string, string> $filters
 * @return array{
 *   donations:list<array<string,mixed>>,
 *   pagination:array{page:int,per_page:int,total_rows:int,total_pages:int,filtered_sum:float}
 * }
 */
function foundation_dashboard_load_donation_table_page(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat,
    array $childMap,
    array $projectMap,
    int $page = 1,
    int $perPage = 50,
    array $filters = []
): array {
    $page = max(1, $page);
    $perPage = min(100, max(10, $perPage));
    $totalRows = foundation_dashboard_count_donations(
        $conn,
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat,
        $filters
    );
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $perPage) : 1;
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $donRows = foundation_dashboard_fetch_donation_rows(
        $conn,
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat,
        $perPage,
        $offset,
        $filters
    );

    $filteredSum = 0.0;
    foreach ($donRows as $row) {
        $filteredSum += (float)($row['amount'] ?? 0);
    }

    return [
        'donations' => foundation_dashboard_donations_json_payload(
            $donRows,
            $childCat,
            $projCat,
            $needCat,
            $childMap,
            $projectMap
        ),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => max(1, $totalPages),
            'filtered_sum' => $filteredSum,
        ],
        'filter_counts' => foundation_dashboard_filter_category_counts(
            $conn,
            $foundationId,
            $foundationName,
            $childCat,
            $projCat,
            $needCat,
            $filters
        ),
    ];
}

/**
 * โหลดรายการบริจาคสำหรับกราฟ (สูงสุด $limit แถวล่าสุด)
 *
 * @return array{
 *   donations:list<array<string,mixed>>,
 *   week_meta:array{labels:list<string>,keys:list<string>},
 *   summary:array<string,mixed>,
 *   analysis_titles:array{pie_title:string,pie_insight:string},
 *   donation_years:list<int>
 * }
 */
function foundation_dashboard_load_donation_bundle(
    mysqli $conn,
    int $foundationId,
    string $foundationName,
    int $childCat,
    int $projCat,
    int $needCat,
    array $childMap,
    array $projectMap,
    int $limit = 500
): array {
    $limit = min(500, max(1, $limit));
    $donRows = foundation_dashboard_fetch_donation_rows(
        $conn,
        $foundationId,
        $foundationName,
        $childCat,
        $projCat,
        $needCat,
        $limit,
        0,
        []
    );

    $fdAnalysis = foundation_dashboard_analyze_donations(
        $donRows,
        $childCat,
        $projCat,
        $needCat,
        $childMap,
        $projectMap
    );

    $donationYears = [];
    foreach ($donRows as $r) {
        $ts = trim((string)($r['transfer_datetime'] ?? ''));
        if ($ts === '') {
            continue;
        }
        $t = strtotime($ts);
        if ($t !== false) {
            $donationYears[(int)date('Y', $t)] = true;
        }
    }
    $donationYears = array_keys($donationYears);
    rsort($donationYears, SORT_NUMERIC);
    if ($donationYears === []) {
        $donationYears = [(int)date('Y')];
    }

    $latestByCat = ['child' => '', 'project' => '', 'need' => ''];
    foreach ($donRows as $r) {
        $cid = (int)($r['category_id'] ?? 0);
        $ts = trim((string)($r['transfer_datetime'] ?? ''));
        if ($ts === '') {
            continue;
        }
        if ($cid === $childCat && $latestByCat['child'] === '') {
            $latestByCat['child'] = $ts;
        } elseif ($cid === $projCat && $latestByCat['project'] === '') {
            $latestByCat['project'] = $ts;
        } elseif ($cid === $needCat && $latestByCat['need'] === '') {
            $latestByCat['need'] = $ts;
        }
        if ($latestByCat['child'] !== '' && $latestByCat['project'] !== '' && $latestByCat['need'] !== '') {
            break;
        }
    }

    return [
        'donations' => foundation_dashboard_donations_json_payload(
            $donRows,
            $childCat,
            $projCat,
            $needCat,
            $childMap,
            $projectMap
        ),
        'week_meta' => [
            'labels' => $fdAnalysis['weekly_labels'],
            'keys' => $fdAnalysis['weekly_keys'],
        ],
        'summary' => [
            'sum_child' => (float)$fdAnalysis['sum_child'],
            'sum_project' => (float)$fdAnalysis['sum_project'],
            'sum_need' => (float)$fdAnalysis['sum_need'],
            'sum_total' => (float)$fdAnalysis['sum_total'],
            'row_count_child' => (int)$fdAnalysis['row_count_child'],
            'row_count_project' => (int)$fdAnalysis['row_count_project'],
            'row_count_need' => (int)$fdAnalysis['row_count_need'],
            'donation_count' => count($donRows),
            'latest_by_cat' => $latestByCat,
        ],
        'analysis_titles' => [
            'line_title' => (string)($fdAnalysis['line_title'] ?? ''),
            'line_insight' => (string)($fdAnalysis['line_insight'] ?? ''),
            'pie_title' => (string)($fdAnalysis['pie_title'] ?? ''),
            'pie_insight' => (string)($fdAnalysis['pie_insight'] ?? ''),
        ],
        'donation_years' => $donationYears,
        'filter_counts' => foundation_dashboard_filter_category_counts(
            $conn,
            $foundationId,
            $foundationName,
            $childCat,
            $projCat,
            $needCat,
            []
        ),
    ];
}
