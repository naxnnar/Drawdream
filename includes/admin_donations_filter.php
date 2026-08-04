<?php
// includes/admin_donations_filter.php — ตัวกรองค้นหา/วันที่สำหรับ admin_donations.php
declare(strict_types=1);

/**
 * @return array{
 *   date_mode: string,
 *   date_from: string,
 *   date_to: string,
 *   date_month: string,
 *   date_year: string,
 *   q: string,
 *   period_today: bool,
 *   label: string
 * }
 */
function admin_donations_parse_filters(array $get): array
{
    $periodToday = (string)($get['period'] ?? '') === 'today';
    $dateMode = strtolower(trim((string)($get['date_mode'] ?? 'all')));
    if (!in_array($dateMode, ['all', 'day', 'month', 'year'], true)) {
        $dateMode = 'all';
    }

    $dateFrom = trim((string)($get['date_from'] ?? ''));
    $dateTo = trim((string)($get['date_to'] ?? ''));
    $dateMonth = trim((string)($get['date_month'] ?? ''));
    $dateYear = trim((string)($get['date_year'] ?? ''));
    $q = trim((string)($get['q'] ?? ''));

    if ($periodToday) {
        $dateMode = 'day';
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
    }

    if ($dateMode === 'day') {
        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = '';
        }
        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = '';
        }
        if ($dateTo === '' && $dateFrom !== '') {
            $dateTo = $dateFrom;
        }
    } elseif ($dateMode === 'month') {
        if ($dateMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $dateMonth)) {
            $dateMonth = '';
        }
    } elseif ($dateMode === 'year') {
        if ($dateYear !== '' && !ctype_digit($dateYear)) {
            $dateYear = '';
        }
    }

    $label = admin_donations_filter_label($dateMode, $dateFrom, $dateTo, $dateMonth, $dateYear, $periodToday, $q);

    return [
        'date_mode' => $dateMode,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'date_month' => $dateMonth,
        'date_year' => $dateYear,
        'q' => $q,
        'period_today' => $periodToday,
        'label' => $label,
    ];
}

function admin_donations_filter_label(
    string $dateMode,
    string $dateFrom,
    string $dateTo,
    string $dateMonth,
    string $dateYear,
    bool $periodToday,
    string $q
): string {
    $parts = [];
    if ($periodToday) {
        $parts[] = 'วันนี้ (' . date('d/m/Y') . ')';
    } elseif ($dateMode === 'day' && $dateFrom !== '') {
        $fromLabel = date('d/m/Y', strtotime($dateFrom));
        $toLabel = date('d/m/Y', strtotime($dateTo !== '' ? $dateTo : $dateFrom));
        $parts[] = $fromLabel === $toLabel ? $fromLabel : ($fromLabel . ' – ' . $toLabel);
    } elseif ($dateMode === 'month' && $dateMonth !== '') {
        $ts = strtotime($dateMonth . '-01');
        $parts[] = $ts !== false ? date('m/Y', $ts) : $dateMonth;
    } elseif ($dateMode === 'year' && $dateYear !== '') {
        $parts[] = 'ปี ' . ((int)$dateYear + 543) . ' (' . $dateYear . ')';
    } elseif ($dateMode === 'all') {
        $parts[] = 'ทั้งหมด';
    }

    if ($q !== '') {
        $parts[] = 'ค้นหา: «' . $q . '»';
    }

    return $parts !== [] ? implode(' · ', $parts) : 'ทั้งหมด';
}

/**
 * @param array{
 *   date_mode: string,
 *   date_from: string,
 *   date_to: string,
 *   date_month: string,
 *   date_year: string,
 *   q: string,
 *   period_today: bool
 * } $filters
 * @return array{sql: string, types: string, params: array<int, mixed>}
 */
function admin_donations_filter_sql(array $filters): array
{
    $sql = '';
    $types = '';
    $params = [];

    if ($filters['period_today']) {
        $sql .= ' AND DATE(d.transfer_datetime) = CURDATE()';
    } elseif ($filters['date_mode'] === 'day' && $filters['date_from'] !== '') {
        $sql .= ' AND DATE(d.transfer_datetime) >= ? AND DATE(d.transfer_datetime) <= ?';
        $types .= 'ss';
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'] !== '' ? $filters['date_to'] : $filters['date_from'];
    } elseif ($filters['date_mode'] === 'month' && $filters['date_month'] !== '') {
        $sql .= " AND DATE_FORMAT(d.transfer_datetime, '%Y-%m') = ?";
        $types .= 's';
        $params[] = $filters['date_month'];
    } elseif ($filters['date_mode'] === 'year' && $filters['date_year'] !== '') {
        $sql .= ' AND YEAR(d.transfer_datetime) = ?';
        $types .= 'i';
        $params[] = (int)$filters['date_year'];
    }

    $q = $filters['q'];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (
            dn.first_name LIKE ? OR dn.last_name LIKE ?
            OR CONCAT(COALESCE(dn.first_name, \'\'), \' \', COALESCE(dn.last_name, \'\')) LIKE ?
            OR u.email LIKE ?
            OR COALESCE(d.omise_charge_id, \'\') LIKE ?
            OR COALESCE(dn.tax_id, \'\') LIKE ?
            OR COALESCE(fc.child_name, \'\') LIKE ?
            OR COALESCE(fp.project_name, \'\') LIKE ?
            OR COALESCE(fpn.foundation_name, \'\') LIKE ?
            OR CAST(d.donate_id AS CHAR) LIKE ?
        )';
        $types .= str_repeat('s', 10);
        for ($i = 0; $i < 10; $i++) {
            $params[] = $like;
        }
    }

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

/**
 * @param array{
 *   date_mode: string,
 *   date_from: string,
 *   date_to: string,
 *   date_month: string,
 *   date_year: string,
 *   q: string,
 *   period_today: bool
 * } $filters
 */
function admin_donations_has_active_filter(array $filters): bool
{
    if ($filters['period_today']) {
        return true;
    }
    if (trim($filters['q']) !== '') {
        return true;
    }
    if ($filters['date_mode'] === 'day' && $filters['date_from'] !== '') {
        return true;
    }
    if ($filters['date_mode'] === 'month' && $filters['date_month'] !== '') {
        return true;
    }
    if ($filters['date_mode'] === 'year' && $filters['date_year'] !== '') {
        return true;
    }

    return false;
}

/**
 * @param array{
 *   date_mode: string,
 *   date_from: string,
 *   date_to: string,
 *   date_month: string,
 *   date_year: string,
 *   q: string,
 *   period_today: bool
 * } $filters
 */
function admin_donations_summary_labels(array $filters): array
{
    if (admin_donations_has_active_filter($filters)) {
        return [
            'amount' => 'ยอดรวมจากตัวกรอง',
            'count' => 'จำนวนรายการที่พบ',
            'note' => 'ตัวเลขด้านล่างคำนวณจากเงื่อนไขกรอง/ค้นหาปัจจุบัน',
        ];
    }

    return [
        'amount' => 'ยอดรวมทั้งหมด',
        'count' => 'จำนวนรายการทั้งหมด',
        'note' => 'แสดงยอดรวมทุกรายการที่โอนสำเร็จในระบบ',
    ];
}

/**
 * @param array<string, scalar|null> $extra
 */
function admin_donations_query_string(array $extra = []): string
{
    $base = [];
    foreach (['q', 'date_mode', 'date_from', 'date_to', 'date_month', 'date_year', 'period', 'page'] as $key) {
        if (array_key_exists($key, $extra) && $extra[$key] !== null && $extra[$key] !== '') {
            $base[$key] = (string)$extra[$key];
        }
    }

    return http_build_query($base, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param array{
 *   date_mode: string,
 *   date_from: string,
 *   date_to: string,
 *   date_month: string,
 *   date_year: string,
 *   q: string,
 *   period_today: bool
 * } $filters
 */
function admin_donations_page_url(array $filters, int $page = 1): string
{
    $params = [];
    if ($filters['period_today']) {
        $params['period'] = 'today';
    } else {
        if ($filters['date_mode'] !== 'all') {
            $params['date_mode'] = $filters['date_mode'];
        }
        if ($filters['date_mode'] === 'day') {
            if ($filters['date_from'] !== '') {
                $params['date_from'] = $filters['date_from'];
            }
            if ($filters['date_to'] !== '') {
                $params['date_to'] = $filters['date_to'];
            }
        } elseif ($filters['date_mode'] === 'month' && $filters['date_month'] !== '') {
            $params['date_month'] = $filters['date_month'];
        } elseif ($filters['date_mode'] === 'year' && $filters['date_year'] !== '') {
            $params['date_year'] = $filters['date_year'];
        }
    }
    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }
    if ($page > 1) {
        $params['page'] = (string)$page;
    }

    $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return 'admin_donations.php' . ($qs !== '' ? '?' . $qs : '');
}

/**
 * @return list<int>
 */
function admin_donations_year_options(mysqli $conn): array
{
    $years = [];
    $res = mysqli_query(
        $conn,
        "SELECT DISTINCT YEAR(transfer_datetime) AS y
         FROM donation
         WHERE LOWER(TRIM(COALESCE(payment_status, ''))) = 'completed'
           AND transfer_datetime IS NOT NULL
         ORDER BY y DESC"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $y = (int)($row['y'] ?? 0);
            if ($y > 0) {
                $years[] = $y;
            }
        }
    }
    if ($years === []) {
        $years[] = (int)date('Y');
    }

    return $years;
}
