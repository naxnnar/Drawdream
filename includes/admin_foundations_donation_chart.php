<?php
// includes/admin_foundations_donation_chart.php — ข้อมูลกราฟเปรียบเทียบยอดบริจาคทุกมูลนิธิ (แอดมิน)

require_once __DIR__ . '/donate_category_resolve.php';

/** สีช่องทางบริจาค — ตรงกับแดชบอร์ดมูลนิธิ */
function admin_foundations_channel_colors(): array
{
    return [
        'child' => '#4A5BA8',
        'project' => '#22c55e',
        'need' => '#f59e0b',
    ];
}

/** @return array{child:string,project:string,need:string} */
function admin_foundations_channel_labels(): array
{
    return [
        'child' => 'เด็ก',
        'project' => 'โครงการ',
        'need' => 'สิ่งของ',
    ];
}

/** @return list<string> */
function admin_foundations_chart_colors(): array
{
    return [
        '#4A5BA8',
        '#F28C88',
        '#4CAF50',
        '#FF9800',
        '#5F58B8',
        '#00BCD4',
        '#9C27B0',
        '#795548',
        '#607D8B',
        '#E91E63',
        '#3B82F6',
        '#14B8A6',
    ];
}

/**
 * รวมยอดบริจาคทุกมูลนิธิแบบ batch (เร็วกว่าเรียก analytics ทีละแห่ง)
 *
 * @return list<array{
 *   foundation_id:int,
 *   name:string,
 *   amount:float,
 *   count:int,
 *   child:float,
 *   project:float,
 *   need:float
 * }>
 */
function admin_foundations_donation_raw_rows(mysqli $conn): array
{
    $childCat = drawdream_get_or_create_child_donate_category_id($conn);
    $projCat = drawdream_get_or_create_project_donate_category_id($conn);
    $needCat = drawdream_get_or_create_needitem_donate_category_id($conn);

    $names = [];
    $resNames = $conn->query('SELECT foundation_id, foundation_name FROM foundation_profile ORDER BY foundation_name ASC');
    if ($resNames) {
        while ($row = $resNames->fetch_assoc()) {
            $fid = (int)($row['foundation_id'] ?? 0);
            if ($fid > 0) {
                $names[$fid] = trim((string)($row['foundation_name'] ?? '')) ?: ('มูลนิธิ #' . $fid);
            }
        }
    }

    /** @var array<int, array{child:float,project:float,need:float,count:int}> */
    $agg = [];

    $touch = static function (array &$agg, int $fid, string $bucket, float $amt, int $n): void {
        if ($fid <= 0) {
            return;
        }
        if (!isset($agg[$fid])) {
            $agg[$fid] = ['child' => 0.0, 'project' => 0.0, 'need' => 0.0, 'count' => 0];
        }
        $agg[$fid][$bucket] += $amt;
        $agg[$fid]['count'] += $n;
    };

    $stChild = $conn->prepare(
        "SELECT fc.foundation_id AS fid, COALESCE(SUM(d.amount), 0) AS amt, COUNT(*) AS n
         FROM donation d
         INNER JOIN foundation_children fc ON d.target_id = fc.child_id
         WHERE d.category_id = ?
           AND LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
         GROUP BY fc.foundation_id"
    );
    if ($stChild) {
        $stChild->bind_param('i', $childCat);
        $stChild->execute();
        $rs = $stChild->get_result();
        while ($r = $rs->fetch_assoc()) {
            $touch($agg, (int)($r['fid'] ?? 0), 'child', (float)($r['amt'] ?? 0), (int)($r['n'] ?? 0));
        }
    }

    $stProj = $conn->prepare(
        "SELECT COALESCE(NULLIF(p.foundation_id, 0), fp.foundation_id) AS fid,
                COALESCE(SUM(d.amount), 0) AS amt, COUNT(*) AS n
         FROM donation d
         INNER JOIN foundation_project p ON d.target_id = p.project_id
         LEFT JOIN foundation_profile fp
           ON (p.foundation_id IS NULL OR p.foundation_id = 0) AND fp.foundation_name = p.foundation_name
         WHERE d.category_id = ?
           AND LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
         GROUP BY fid
         HAVING fid IS NOT NULL AND fid > 0"
    );
    if ($stProj) {
        $stProj->bind_param('i', $projCat);
        $stProj->execute();
        $rs = $stProj->get_result();
        while ($r = $rs->fetch_assoc()) {
            $touch($agg, (int)($r['fid'] ?? 0), 'project', (float)($r['amt'] ?? 0), (int)($r['n'] ?? 0));
        }
    }

    // บริจาคสิ่งของ: donation.target_id = foundation_id (ไม่ใช่ item_id)
    $stNeed = $conn->prepare(
        "SELECT d.target_id AS fid, COALESCE(SUM(d.amount), 0) AS amt, COUNT(*) AS n
         FROM donation d
         INNER JOIN foundation_profile fp ON fp.foundation_id = d.target_id
         WHERE d.category_id = ?
           AND LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
         GROUP BY d.target_id"
    );
    if ($stNeed) {
        $stNeed->bind_param('i', $needCat);
        $stNeed->execute();
        $rs = $stNeed->get_result();
        while ($r = $rs->fetch_assoc()) {
            $touch($agg, (int)($r['fid'] ?? 0), 'need', (float)($r['amt'] ?? 0), (int)($r['n'] ?? 0));
        }
    }

    $raw = [];
    foreach ($agg as $fid => $buckets) {
        $amount = (float)$buckets['child'] + (float)$buckets['project'] + (float)$buckets['need'];
        $count = (int)$buckets['count'];
        if ($amount <= 0 && $count <= 0) {
            continue;
        }
        $raw[] = [
            'foundation_id' => (int)$fid,
            'name' => $names[$fid] ?? ('มูลนิธิ #' . $fid),
            'amount' => $amount,
            'count' => $count,
            'child' => (float)$buckets['child'],
            'project' => (float)$buckets['project'],
            'need' => (float)$buckets['need'],
        ];
    }

    usort($raw, static fn (array $a, array $b): int => ($b['amount'] <=> $a['amount']));

    return $raw;
}

/**
 * @param list<array{foundation_id:int,name:string,amount:float,count:int,child:float,project:float,need:float}> $raw
 * @return list<array{foundation_id:int,name:string,amount:float,count:int,pct:float,color:string}>
 */
function admin_foundations_donation_pie_rows_from_raw(array $raw, int $maxSlices = 10): array
{
    if ($raw === []) {
        return [];
    }

    $colors = admin_foundations_chart_colors();
    $display = [];
    $otherAmount = 0.0;
    $otherCount = 0;

    foreach ($raw as $i => $item) {
        if ($i < $maxSlices - 1 || count($raw) <= $maxSlices) {
            $display[] = $item;
            continue;
        }
        $otherAmount += (float)$item['amount'];
        $otherCount += (int)$item['count'];
    }

    if ($otherAmount > 0 || $otherCount > 0) {
        $display[] = [
            'foundation_id' => 0,
            'name' => 'มูลนิธิอื่นๆ (' . (count($raw) - ($maxSlices - 1)) . ' แห่ง)',
            'amount' => $otherAmount,
            'count' => $otherCount,
        ];
    }

    $grandTotal = array_sum(array_column($display, 'amount'));
    $rows = [];
    foreach ($display as $i => $item) {
        $amt = (float)$item['amount'];
        $rows[] = [
            'foundation_id' => (int)$item['foundation_id'],
            'name' => (string)$item['name'],
            'amount' => $amt,
            'count' => (int)$item['count'],
            'pct' => $grandTotal > 0 ? ($amt / $grandTotal) * 100 : 0.0,
            'color' => $colors[$i % count($colors)],
        ];
    }

    return $rows;
}

/**
 * @param list<array{foundation_id:int,name:string,amount:float,count:int,child:float,project:float,need:float}> $raw
 * @return list<array{
 *   foundation_id:int,
 *   name:string,
 *   total:float,
 *   channels:array{
 *     child:array{amount:float,pct:float},
 *     project:array{amount:float,pct:float},
 *     need:array{amount:float,pct:float}
 *   }
 * }>
 */
function admin_foundations_channel_breakdown_cards(array $raw): array
{
    $labels = admin_foundations_channel_labels();
    $cards = [];

    foreach ($raw as $item) {
        $fid = (int)($item['foundation_id'] ?? 0);
        if ($fid <= 0) {
            continue;
        }
        $total = (float)($item['amount'] ?? 0);
        if ($total <= 0) {
            continue;
        }

        $channels = [];
        foreach (array_keys($labels) as $key) {
            $amt = (float)($item[$key] ?? 0);
            $channels[$key] = [
                'amount' => $amt,
                'pct' => $total > 0 ? ($amt / $total) * 100 : 0.0,
            ];
        }

        $cards[] = [
            'foundation_id' => $fid,
            'name' => (string)$item['name'],
            'total' => $total,
            'channels' => $channels,
        ];
    }

    return $cards;
}

function admin_foundations_donation_chart_cache_path(): string
{
    return dirname(__DIR__) . '/config/admin_foundations_chart_cache.json';
}

function admin_foundations_donation_chart_cache_bust(): void
{
    $path = admin_foundations_donation_chart_cache_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * @return array{
 *   rows:list<array{foundation_id:int,name:string,amount:float,count:int,pct:float,color:string}>,
 *   channel_cards:list<array<string,mixed>>,
 *   channel_colors:array{child:string,project:string,need:string},
 *   channel_labels:array{child:string,project:string,need:string},
 *   foundation_count:int,
 *   total:float,
 *   total_count:int,
 *   pie_title:string,
 *   pie_insight:string,
 *   top:array{name:string,amount:float,count:int,pct:float}|null,
 *   top_by_count:array{name:string,amount:float,count:int,pct:float}|null
 * }
 */
function admin_foundations_donation_chart_payload_compute(mysqli $conn): array
{
    $raw = admin_foundations_donation_raw_rows($conn);
    $rows = admin_foundations_donation_pie_rows_from_raw($raw);
    $channelCards = admin_foundations_channel_breakdown_cards($raw);
    $total = array_sum(array_map(static fn (array $r): float => (float)$r['amount'], $rows));
    $totalCount = array_sum(array_map(static fn (array $r): int => (int)$r['count'], $rows));

    $top = $rows[0] ?? null;
    $topByCount = $rows;
    usort($topByCount, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']));
    $topCount = $topByCount[0] ?? null;

    $pieTitle = 'สัดส่วนยอดบริจาคตามมูลนิธิ';
    $pieInsight = 'ยังไม่มียอดบริจาคที่จัดสรรให้มูลนิธีในระบบ';

    if ($top !== null && $total > 0) {
        $pieTitle = sprintf(
            'เงิน %.1f%% อยู่ที่%s แต่มี %d รายการ',
            (float)$top['pct'],
            (string)$top['name'],
            (int)$top['count']
        );
        if ($topCount !== null && ($topCount['name'] ?? '') !== ($top['name'] ?? '')) {
            $pieInsight = sprintf(
                'ยอดเงินสูงสุดที่%s (%d ครั้ง) · บริจาคบ่อยสุดที่%s (%d ครั้ง)',
                (string)$top['name'],
                (int)$top['count'],
                (string)$topCount['name'],
                (int)$topCount['count']
            );
        } else {
            $pieInsight = sprintf(
                '%s ทั้งมียอดและจำนวนครั้งสูงสุด (%d ครั้ง · %s บาท)',
                (string)$top['name'],
                (int)$top['count'],
                number_format((float)$top['amount'], 0)
            );
        }
    }

    return [
        'rows' => $rows,
        'channel_cards' => $channelCards,
        'channel_colors' => admin_foundations_channel_colors(),
        'channel_labels' => admin_foundations_channel_labels(),
        'foundation_count' => count($channelCards),
        'total' => $total,
        'total_count' => $totalCount,
        'pie_title' => $pieTitle,
        'pie_insight' => $pieInsight,
        'top' => $top,
        'top_by_count' => $topCount,
    ];
}

function admin_foundations_donation_chart_payload(mysqli $conn, bool $forceRefresh = false): array
{
    $cachePath = admin_foundations_donation_chart_cache_path();
    $ttl = 120;
    if (!$forceRefresh && is_file($cachePath)) {
        $decoded = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($decoded)
            && isset($decoded['built_at'], $decoded['payload'])
            && is_array($decoded['payload'])
            && (time() - (int)$decoded['built_at']) < $ttl
        ) {
            return $decoded['payload'];
        }
    }

    $payload = admin_foundations_donation_chart_payload_compute($conn);
    @file_put_contents(
        $cachePath,
        json_encode(['built_at' => time(), 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    );

    return $payload;
}
