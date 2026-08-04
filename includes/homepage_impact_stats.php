<?php
// includes/homepage_impact_stats.php — ตัวเลขผลกระทบสำหรับหน้าแรก
// total_donors = ผู้ใช้ role donor ที่มีอย่างน้อย 1 รายการชำระ completed (ยอด > 0) ไม่รวมค่าบริการระบบมูลนิธิ

require_once __DIR__ . '/drawdream_project_status.php';

/** ล้าง cache ตัวเลขหน้าแรก (เรียกหลังบริจาคสำเร็จถ้าต้องการตัวเลขทันที) */
function drawdream_homepage_impact_stats_cache_bust(): void
{
    $cacheFile = dirname(__DIR__) . '/config/homepage_impact_cache.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * @return array{
 *   total_donation_baht: float,
 *   total_donors: int,
 *   children_sponsored: int,
 *   projects_completed: int,
 *   foundations_items_received: int,
 *   total_foundations: int
 * }
 */
function drawdream_homepage_impact_stats(mysqli $conn): array
{
    $cacheFile = dirname(__DIR__) . '/config/homepage_impact_cache.json';
    $ttl = 90;
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false && $raw !== '') {
            $cached = json_decode($raw, true);
            if (is_array($cached)
                && isset($cached['ts'], $cached['data'])
                && is_array($cached['data'])
                && (time() - (int)$cached['ts']) < $ttl) {
                return array_merge(drawdream_homepage_impact_stats_defaults(), $cached['data']);
            }
        }
    }

    $out = drawdream_homepage_impact_stats_compute($conn);
    @file_put_contents(
        $cacheFile,
        json_encode(['ts' => time(), 'data' => $out], JSON_UNESCAPED_UNICODE)
    );
    return $out;
}

/**
 * @return array{
 *   total_donation_baht: float,
 *   total_donors: int,
 *   children_sponsored: int,
 *   projects_completed: int,
 *   foundations_items_received: int,
 *   total_foundations: int
 * }
 */
function drawdream_homepage_impact_stats_defaults(): array
{
    return [
        'total_donation_baht' => 0.0,
        'total_donors' => 0,
        'children_sponsored' => 0,
        'projects_completed' => 0,
        'foundations_items_received' => 0,
        'total_foundations' => 0,
    ];
}

/**
 * @return array{
 *   total_donation_baht: float,
 *   total_donors: int,
 *   children_sponsored: int,
 *   projects_completed: int,
 *   foundations_items_received: int,
 *   total_foundations: int
 * }
 */
function drawdream_homepage_impact_stats_compute(mysqli $conn): array
{
    $out = drawdream_homepage_impact_stats_defaults();

    $qDonationTotal = mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0) AS t
        FROM donation
        WHERE LOWER(TRIM(COALESCE(payment_status, ''))) = 'completed'
    ");
    if ($qDonationTotal && ($row = mysqli_fetch_assoc($qDonationTotal))) {
        $out['total_donation_baht'] = (float)($row['t'] ?? 0);
    }

    $qDonors = mysqli_query($conn, "
        SELECT COUNT(DISTINCT d.donor_id) AS c
        FROM donation d
        INNER JOIN `user` u ON u.user_id = d.donor_id
        WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
          AND d.donor_id > 0
          AND d.amount > 0
          AND LOWER(TRIM(COALESCE(u.role, ''))) = 'donor'
          AND LOWER(TRIM(COALESCE(d.donate_type, ''))) NOT IN (
              'need_service_charge',
              'project_service_charge'
          )
    ");
    if ($qDonors && ($row = mysqli_fetch_assoc($qDonors))) {
        $out['total_donors'] = (int)($row['c'] ?? 0);
    }

    $qChildren = mysqli_query($conn, "
        SELECT COUNT(*) AS c
        FROM foundation_children
        WHERE approve_profile IN ('อนุมัติ', 'กำลังดำเนินการ')
          AND TRIM(COALESCE(status, '')) = 'อุปการะแล้ว'
    ");
    if ($qChildren && ($row = mysqli_fetch_assoc($qChildren))) {
        $out['children_sponsored'] = (int)($row['c'] ?? 0);
    }

    $out['projects_completed'] = drawdream_count_donor_completed_projects($conn);

    $qFoundations = mysqli_query($conn, "
        SELECT COUNT(DISTINCT foundation_id) AS c
        FROM foundation_needlist
        WHERE approve_item = 'done'
          AND foundation_id > 0
    ");
    if ($qFoundations && ($row = mysqli_fetch_assoc($qFoundations))) {
        $out['foundations_items_received'] = (int)($row['c'] ?? 0);
    }

    $qTotalFoundations = mysqli_query($conn, "
        SELECT COUNT(*) AS c
        FROM foundation_profile
        WHERE account_verified = 1
    ");
    if ($qTotalFoundations && ($row = mysqli_fetch_assoc($qTotalFoundations))) {
        $out['total_foundations'] = (int)($row['c'] ?? 0);
    }

    return $out;
}

function drawdream_homepage_impact_stat_display(int $value): string
{
    return number_format(max(0, $value), 0, '.', ',');
}

function drawdream_homepage_impact_stat_display_baht(float $value): string
{
    return number_format(max(0, $value), 0, '.', ',');
}
