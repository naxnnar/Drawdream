<?php
// foundation_dashboard.php — แดชบอร์ดมูลนิธิ (ยอดรวมเด็ก/โครงการ/สิ่งของ + ทางลัดรายงานเชิงวิเคราะห์)
declare(strict_types=1);

include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/child_sponsorship.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];
$stFp = $conn->prepare(
    'SELECT foundation_id, foundation_name, account_verified, created_at
     FROM foundation_profile WHERE user_id = ? LIMIT 1'
);
if (!$stFp) {
    header('Location: profile.php');
    exit();
}
$stFp->bind_param('i', $uid);
$stFp->execute();
$fp = $stFp->get_result()->fetch_assoc();
if (!$fp) {
    header('Location: profile.php');
    exit();
}

$foundationId = (int)($fp['foundation_id'] ?? 0);
$foundationName = trim((string)($fp['foundation_name'] ?? ''));
if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$childCat = drawdream_get_or_create_child_donate_category_id($conn);
$projCat = drawdream_get_or_create_project_donate_category_id($conn);
$needCat = drawdream_get_or_create_needitem_donate_category_id($conn);
if ($childCat <= 0 || $projCat <= 0 || $needCat <= 0) {
    die('ระบบหมวดบริจาคยังไม่พร้อม');
}

$childMap = [];
$stC = $conn->prepare(
    'SELECT child_id, child_name FROM foundation_children
     WHERE foundation_id = ? AND deleted_at IS NULL'
);
if ($stC) {
    $stC->bind_param('i', $foundationId);
    $stC->execute();
    $rc = $stC->get_result();
    while ($x = $rc->fetch_assoc()) {
        $childMap[(int)$x['child_id']] = (string)($x['child_name'] ?? '');
    }
}

$projectMap = [];
$stP = $conn->prepare(
    "SELECT project_id, project_name FROM foundation_project
     WHERE deleted_at IS NULL
       AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
);
if ($stP) {
    $stP->bind_param('is', $foundationId, $foundationName);
    $stP->execute();
    $rp = $stP->get_result();
    while ($x = $rp->fetch_assoc()) {
        $projectMap[(int)$x['project_id']] = (string)($x['project_name'] ?? '');
    }
}

$sql = "
SELECT d.donate_id, d.amount, d.transfer_datetime, d.payment_status,
       d.omise_charge_id, dn.tax_id, d.donor_id,
       d.donate_type,
       d.category_id, d.target_id,
       dn.first_name, dn.last_name, u.email AS donor_email
FROM donation d
LEFT JOIN donor dn ON dn.user_id = d.donor_id
LEFT JOIN `user` u ON u.user_id = d.donor_id
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
ORDER BY d.transfer_datetime DESC, d.donate_id DESC
LIMIT 500
";
$stRows = $conn->prepare($sql);
if (!$stRows) {
    die('ไม่สามารถเตรียมคำสั่ง SQL');
}
$stRows->bind_param(
    'iiiiisi',
    $childCat,
    $foundationId,
    $projCat,
    $foundationId,
    $foundationName,
    $needCat,
    $foundationId
);
$stRows->execute();
$donRows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);

$sumChild = 0.0;
$sumProject = 0.0;
$sumNeed = 0.0;
$rowCountChild = 0;
$rowCountProject = 0;
$rowCountNeed = 0;
foreach ($donRows as $r) {
    $cid = (int)($r['category_id'] ?? 0);
    $amt = (float)($r['amount'] ?? 0);
    if ($cid === $childCat) {
        $sumChild += $amt;
        $rowCountChild++;
    } elseif ($cid === $projCat) {
        $sumProject += $amt;
        $rowCountProject++;
    } elseif ($cid === $needCat) {
        $sumNeed += $amt;
        $rowCountNeed++;
    }
}
$sumTotal = $sumChild + $sumProject + $sumNeed;
$piePercentChild = $sumTotal > 0 ? ($sumChild / $sumTotal) * 100 : 0.0;
$piePercentProject = $sumTotal > 0 ? ($sumProject / $sumTotal) * 100 : 0.0;
$piePercentNeed = $sumTotal > 0 ? ($sumNeed / $sumTotal) * 100 : 0.0;
$pieBreakdown = [
    ['key' => 'child', 'label' => 'เด็ก', 'amount' => $sumChild, 'pct' => $piePercentChild, 'count' => $rowCountChild, 'color' => '#4A5BA8'],
    ['key' => 'project', 'label' => 'โครงการ', 'amount' => $sumProject, 'pct' => $piePercentProject, 'count' => $rowCountProject, 'color' => '#22c55e'],
    ['key' => 'need', 'label' => 'สิ่งของ', 'amount' => $sumNeed, 'pct' => $piePercentNeed, 'count' => $rowCountNeed, 'color' => '#f59e0b'],
];
usort($pieBreakdown, static fn (array $a, array $b): int => ($b['amount'] <=> $a['amount']));
$pieTop = $pieBreakdown[0] ?? ['label' => '-', 'amount' => 0.0, 'pct' => 0.0];
$donationCountTotal = $rowCountChild + $rowCountProject + $rowCountNeed;
$avgPerDonation = $donationCountTotal > 0 ? $sumTotal / $donationCountTotal : 0.0;

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

$cntChildProfiles = count($childMap);
$cntProjects = count($projectMap);
$stNeedCnt = $conn->prepare('SELECT COUNT(*) AS c FROM foundation_needlist WHERE foundation_id = ?');
$needItemCnt = 0;
if ($stNeedCnt) {
    $stNeedCnt->bind_param('i', $foundationId);
    $stNeedCnt->execute();
    $needItemCnt = (int)($stNeedCnt->get_result()->fetch_assoc()['c'] ?? 0);
}

$childIds = array_keys($childMap);
$activeSponsoredChildCnt = 0;
if ($childIds !== []) {
    $activeMap = drawdream_child_ids_with_active_plan_sponsorship($conn, $childIds);
    $activeSponsoredChildCnt = count($activeMap);
}
$recentCancelledChildren = [];
if ($childIds !== []) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $types = str_repeat('i', count($childIds));
    $sqlCancelled = "
        SELECT h.child_id, h.recurring_plan_code, h.created_at
        FROM child_subscription_history h
        INNER JOIN (
            SELECT child_id, MAX(history_id) AS max_id
            FROM child_subscription_history
            WHERE LOWER(TRIM(COALESCE(current_status,''))) IN ('cancelled','cancle','canceled')
              AND child_id IN ($ph)
            GROUP BY child_id
        ) x ON x.max_id = h.history_id
        ORDER BY h.created_at DESC
        LIMIT 8
    ";
    $stCancelled = $conn->prepare($sqlCancelled);
    if ($stCancelled) {
        $stCancelled->bind_param($types, ...$childIds);
        $stCancelled->execute();
        $rowsCancelled = $stCancelled->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rowsCancelled as $rc) {
            $cid = (int)($rc['child_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $coverage = drawdream_child_plan_coverage_window($conn, $cid);
            $nextOpen = (($coverage['end'] ?? null) instanceof DateTimeImmutable) ? $coverage['end'] : null;
            $recentCancelledChildren[] = [
                'child_id' => $cid,
                'child_name' => (string)($childMap[$cid] ?? ('เด็ก #' . $cid)),
                'plan_label' => foundation_dashboard_plan_label((string)($rc['recurring_plan_code'] ?? '')),
                'cancelled_at' => (string)($rc['created_at'] ?? ''),
                'next_open_at' => $nextOpen ? $nextOpen->format('d/m/Y H:i') : 'ได้ทันที',
            ];
        }
    }
}

$projectOpenCnt = 0;
$projectCompletedCnt = 0;
$stProjectStatus = $conn->prepare(
    "SELECT
        SUM(CASE WHEN project_status = 'completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN project_status IN ('approved','pending','purchasing') THEN 1 ELSE 0 END) AS open_cnt
     FROM foundation_project
     WHERE deleted_at IS NULL
       AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
);
if ($stProjectStatus) {
    $stProjectStatus->bind_param('is', $foundationId, $foundationName);
    $stProjectStatus->execute();
    $stRow = $stProjectStatus->get_result()->fetch_assoc() ?: [];
    $projectCompletedCnt = (int)($stRow['completed_cnt'] ?? 0);
    $projectOpenCnt = (int)($stRow['open_cnt'] ?? 0);
}

$needApprovedCnt = 0;
$needFundedCnt = 0;
$stNeedStatus = $conn->prepare(
    "SELECT
        SUM(CASE WHEN approve_item = 'approved' THEN 1 ELSE 0 END) AS approved_cnt,
        SUM(CASE WHEN COALESCE(current_donate,0) >= COALESCE(total_price,0) AND COALESCE(total_price,0) > 0 THEN 1 ELSE 0 END) AS funded_cnt
     FROM foundation_needlist
     WHERE foundation_id = ?"
);
if ($stNeedStatus) {
    $stNeedStatus->bind_param('i', $foundationId);
    $stNeedStatus->execute();
    $ns = $stNeedStatus->get_result()->fetch_assoc() ?: [];
    $needApprovedCnt = (int)($ns['approved_cnt'] ?? 0);
    $needFundedCnt = (int)($ns['funded_cnt'] ?? 0);
}

$now = new DateTimeImmutable('now');
$baseWeekStart = $now->modify('monday this week');
$weeklyLabels = [];
$weeklySums = [];
$weekKeyToIndex = [];
for ($i = 7; $i >= 0; $i--) {
    $start = $baseWeekStart->modify('-' . $i . ' week');
    $end = $start->modify('+6 day');
    $key = $start->format('o-W');
    $weeklyLabels[] = $start->format('d/m') . ' - ' . $end->format('d/m');
    $weeklySums[] = 0.0;
    $weekKeyToIndex[$key] = count($weeklyLabels) - 1;
}
foreach ($donRows as $r) {
    $tsRaw = trim((string)($r['transfer_datetime'] ?? ''));
    if ($tsRaw === '') {
        continue;
    }
    $ts = strtotime($tsRaw);
    if ($ts === false) {
        continue;
    }
    $key = date('o-W', $ts);
    if (!array_key_exists($key, $weekKeyToIndex)) {
        continue;
    }
    $idx = $weekKeyToIndex[$key];
    $weeklySums[$idx] += (float)($r['amount'] ?? 0);
}

function foundation_dashboard_plan_label(string $code): string
{
    $m = [
        'monthly' => 'รายเดือน',
        'semiannual' => 'ราย 6 เดือน',
        'yearly' => 'รายปี',
        'daily' => 'รายวัน (QR)',
        'one_time' => 'ครั้งเดียว',
    ];
    $k = strtolower(trim($code));

    return $m[$k] ?? ($k !== '' ? $k : '-');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div>
                <h1 class="admin-directory-title" style="margin-bottom:4px;">แดชบอร์ดมูลนิธิ</h1>
                <p style="margin:0;font-size:.9rem;color:#4b5563;"><?= htmlspecialchars($foundationName) ?></p>
            </div>
            <div class="admin-dir-actions" style="flex-shrink:0;">
                <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-view-tab="overview">กราฟ</button>
                <button type="button" class="admin-dir-btn admin-dir-btn--analytics" data-view-tab="trends">ภาพรวม</button>
            </div>
        </div>
    </div>

    <div id="foundationDashboardOverviewView" style="display:none;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:14px 0 20px;">
        <a href="foundation_children_directory.php" style="text-decoration:none;color:inherit;text-align:left;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;cursor:pointer;">
            <strong>เด็กในระบบ</strong><br>
            <?= (int)$cntChildProfiles ?> คน · ยอดบริจาค <?= number_format($sumChild, 2) ?> บาท
        </a>
        <a href="foundation_projects_directory.php" style="text-decoration:none;color:inherit;text-align:left;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;cursor:pointer;">
            <strong>โครงการ</strong><br>
            <?= (int)$cntProjects ?> โครงการ · ยอดบริจาค <?= number_format($sumProject, 2) ?> บาท
        </a>
        <a href="foundation_needlist_directory.php" style="text-decoration:none;color:inherit;text-align:left;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;cursor:pointer;">
            <strong>รายการสิ่งของ</strong><br>
            <?= (int)$needItemCnt ?> รายการ · ยอดบริจาค <?= number_format($sumNeed, 2) ?> บาท
        </a>
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f3f4ff;">
            <strong>รวมทั้งมูลนิธิ</strong><br>
            <?= number_format($sumTotal, 2) ?> บาท (<?= count($donRows) ?> รายการ)
        </div>
    </div>

    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin:0 0 14px;">
        <div data-feature-panel="child">
            <strong>ภาพรวมฟีเจอร์เด็ก</strong>
            <div style="margin-top:8px;color:#374151;">
                โปรไฟล์เด็กทั้งหมด <?= $cntChildProfiles ?> คน · มีผู้อุปการะแบบรายรอบ <?= $activeSponsoredChildCnt ?> คน ·
                รายการบริจาค <?= $rowCountChild ?> รายการ · ยอดรวม <?= number_format($sumChild, 2) ?> บาท
                <?php if ($latestByCat['child'] !== ''): ?>
                    · อัปเดตล่าสุด <?= date('d/m/Y H:i', strtotime($latestByCat['child'])) ?>
                <?php endif; ?>
            </div>
            <div style="margin-top:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;padding:10px 12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <strong style="font-size:.95rem;color:#1f2937;">เด็กที่ถูกยกเลิกล่าสุด</strong>
                    <a href="foundation_children_directory.php" style="font-size:.82rem;color:#3c5099;text-decoration:none;">ดูทั้งหมด</a>
                </div>
                <?php if ($recentCancelledChildren !== []): ?>
                    <div style="margin-top:8px;display:grid;gap:8px;">
                        <?php foreach ($recentCancelledChildren as $cc): ?>
                            <div style="border:1px dashed #d1d5db;border-radius:8px;padding:8px 10px;background:#fff;">
                                <div style="font-weight:700;color:#111827;"><?= htmlspecialchars((string)$cc['child_name']) ?></div>
                                <div style="font-size:.83rem;color:#475569;">
                                    แผน <?= htmlspecialchars((string)$cc['plan_label']) ?>
                                    · ยกเลิกเมื่อ <?= htmlspecialchars($cc['cancelled_at'] !== '' ? date('d/m/Y H:i', strtotime((string)$cc['cancelled_at'])) : '-') ?>
                                </div>
                                <div style="font-size:.83rem;color:#9a3412;">
                                    เปิดอุปการะรอบถัดไป: <?= htmlspecialchars((string)$cc['next_open_at']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="b--muted" style="margin-top:8px;">ยังไม่มีรายการยกเลิกอุปการะล่าสุด</div>
                <?php endif; ?>
            </div>
        </div>
        <div data-feature-panel="project" style="display:none;">
            <strong>ภาพรวมฟีเจอร์โครงการ</strong>
            <div style="margin-top:8px;color:#374151;">
                โครงการทั้งหมด <?= $cntProjects ?> โครงการ · กำลังดำเนินการ <?= $projectOpenCnt ?> · สำเร็จแล้ว <?= $projectCompletedCnt ?> ·
                รายการบริจาค <?= $rowCountProject ?> รายการ · ยอดรวม <?= number_format($sumProject, 2) ?> บาท
                <?php if ($latestByCat['project'] !== ''): ?>
                    · อัปเดตล่าสุด <?= date('d/m/Y H:i', strtotime($latestByCat['project'])) ?>
                <?php endif; ?>
            </div>
        </div>
        <div data-feature-panel="need" style="display:none;">
            <strong>ภาพรวมฟีเจอร์รายการสิ่งของ</strong>
            <div style="margin-top:8px;color:#374151;">
                รายการสิ่งของทั้งหมด <?= $needItemCnt ?> รายการ · อนุมัติแล้ว <?= $needApprovedCnt ?> · ครบเป้าแล้ว <?= $needFundedCnt ?> ·
                รายการบริจาค <?= $rowCountNeed ?> รายการ · ยอดรวม <?= number_format($sumNeed, 2) ?> บาท
                <?php if ($latestByCat['need'] !== ''): ?>
                    · อัปเดตล่าสุด <?= date('d/m/Y H:i', strtotime($latestByCat['need'])) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="foundation-donation-date-filter" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;">
        <span style="font-size:.9rem;font-weight:600;color:#374151;">กรองตามวันที่</span>
        <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;color:#475569;">
            <span>ช่วง</span>
            <select id="fdDateMode" class="foundation-date-filter-select" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;background:#fff;">
                <option value="all">ทั้งหมด</option>
                <option value="day">รายวัน</option>
                <option value="month">รายเดือน</option>
                <option value="year">รายปี</option>
            </select>
        </label>
        <span id="fdDateRangeWrap" class="foundation-date-range-wrap" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;color:#475569;">
                <span>จาก</span>
                <input type="date" id="fdDateFrom" class="foundation-date-filter-input" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;">
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;color:#475569;">
                <span>ถึง</span>
                <input type="date" id="fdDateTo" class="foundation-date-filter-input" title="เว้นว่าง = วันเดียวกับ «จาก»" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;">
            </label>
            <span style="font-size:.8rem;color:#94a3b8;">เว้น «ถึง» = วันเดียว</span>
        </span>
        <input type="month" id="fdDateMonth" class="foundation-date-filter-input" style="display:none;padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;">
        <select id="fdDateYear" class="foundation-date-filter-select" style="display:none;padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;background:#fff;">
            <?php foreach ($donationYears as $y): ?>
                <option value="<?= (int)$y ?>"><?= (int)$y + 543 ?> (<?= (int)$y ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="fdDateClear" class="admin-dir-btn admin-dir-btn--ghost" style="display:none;">ล้างตัวกรองวันที่</button>
        <span id="fdDateSummary" style="font-size:.86rem;color:#64748b;margin-left:auto;"></span>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px;">
        <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-filter-cat="all">ทั้งหมด (<?= count($donRows) ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="child">เด็ก (<?= $rowCountChild ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="project">โครงการ (<?= $rowCountProject ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="need">รายการสิ่งของ (<?= $rowCountNeed ?>)</button>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>เวลาโอน</th>
                <th>ผู้บริจาค</th>
                <th>ช่องทาง</th>
                <th>เป้าหมาย</th>
                <th>แผน</th>
                <th class="admin-dir-num">จำนวนเงิน (บาท)</th>
                <th>อ้างอิง</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($donRows === []): ?>
                <tr><td colspan="7" class="b--muted">ยังไม่มีประวัติการบริจาคที่เข้ามูลนิธินี้</td></tr>
            <?php else: ?>
                <?php foreach ($donRows as $row):
                    $dtRaw = trim((string)($row['transfer_datetime'] ?? ''));
                    $dtTs = $dtRaw !== '' ? strtotime($dtRaw) : false;
                    $dtLabel = $dtTs !== false ? date('d/m/Y H:i:s', $dtTs) : '-';
                    $dataDonateDate = $dtTs !== false ? date('Y-m-d', $dtTs) : '';
                    $dataDonateMonth = $dtTs !== false ? date('Y-m', $dtTs) : '';
                    $dataDonateYear = $dtTs !== false ? date('Y', $dtTs) : '';
                    $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = trim((string)($row['donor_email'] ?? ''));
                    }
                    if ($fullName === '') {
                        $fullName = 'ผู้บริจาคไม่ระบุตัวตน';
                    }
                    $dt = strtolower(trim((string)($row['donate_type'] ?? '')));
                    $channel = drawdream_donate_type_label_thai($dt);
                    $catId = (int)($row['category_id'] ?? 0);
                    $tid = (int)($row['target_id'] ?? 0);
                    if ($catId === $childCat) {
                        $targetKind = 'เด็ก';
                        $rowCat = 'child';
                        $targetDetail = $childMap[$tid] ?? ('#' . $tid);
                    } elseif ($catId === $projCat) {
                        $targetKind = 'โครงการ';
                        $rowCat = 'project';
                        $targetDetail = $projectMap[$tid] ?? ('#' . $tid);
                    } elseif ($catId === $needCat) {
                        $targetKind = 'สิ่งของ';
                        $rowCat = 'need';
                        $targetDetail = 'ระดมมูลนิธิ (รวมรายการสิ่งของ)';
                    } else {
                        $targetKind = '-';
                        $rowCat = 'other';
                        $targetDetail = '-';
                    }
                    $targetCell = $targetKind . ': ' . $targetDetail;
                    $isSub = in_array($dt, ['child_subscription', 'child_subscription_charge'], true);
                    $amtRow = (float)($row['amount'] ?? 0);
                    $planCodeRaw = abs($amtRow - 4200.0) < 0.01 ? 'semiannual' : (abs($amtRow - 8400.0) < 0.01 ? 'yearly' : 'monthly');
                    $planSpec = $isSub ? drawdream_child_subscription_plan($planCodeRaw) : null;
                    $planLabel = $isSub ? foundation_dashboard_plan_label($planCodeRaw) : 'ครั้งเดียว';
                    if ($isSub && is_array($planSpec) && ($planSpec['amount_thb'] ?? 0) > 0) {
                        $planLabel .= ' · ' . number_format((float)$planSpec['amount_thb'], 0) . ' บ.';
                    }
                    $chargeId = trim((string)($row['omise_charge_id'] ?? ''));
                    ?>
                    <tr data-cat="<?= htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-date="<?= htmlspecialchars($dataDonateDate, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-month="<?= htmlspecialchars($dataDonateMonth, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-year="<?= htmlspecialchars($dataDonateYear, ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= htmlspecialchars($dtLabel) ?></td>
                        <td><?= htmlspecialchars($fullName) ?></td>
                        <td><?= htmlspecialchars($channel) ?></td>
                        <td><?= htmlspecialchars($targetCell) ?></td>
                        <td><?= htmlspecialchars($planLabel) ?></td>
                        <td class="admin-dir-num"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars($chargeId !== '' ? $chargeId : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr id="foundationDashboardNoRows" style="display:none;">
                <td colspan="7" class="b--muted">ไม่มีข้อมูลตามเงื่อนไขที่เลือก</td>
            </tr>
            </tbody>
        </table>
    </div>
    </div>

    <div id="foundationDashboardTrendsView">
        <div class="admin-dir-table-wrap" style="padding:18px;margin-bottom:14px;">
            <h3 style="margin:0 0 8px;font-family:'Prompt',sans-serif;color:#1f2937;">กราฟเส้นยอดบริจาครายสัปดาห์</h3>
            <p style="margin:0 0 14px;color:#64748b;font-size:.92rem;">ดูแนวโน้มช่วงที่มียอดบริจาคสูงในแต่ละสัปดาห์ล่าสุด 8 สัปดาห์</p>
            <div style="height:260px;max-width:100%;">
                <canvas id="foundationWeeklyTrendChart"></canvas>
            </div>
        </div>
        <div class="admin-dir-table-wrap" style="padding:18px;">
            <h3 style="margin:0 0 8px;font-family:'Prompt',sans-serif;color:#1f2937;">สัดส่วนประเภทการบริจาค</h3>
            <p style="margin:0 0 14px;color:#64748b;font-size:.92rem;">สัดส่วนยอดบริจาคตามฟีเจอร์ เด็ก / โครงการ / สิ่งของ</p>
            <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start;">
                <div style="max-width:320px;height:320px;flex:0 0 320px;">
                    <canvas id="foundationCategoryPieChart"></canvas>
                </div>
                <div style="flex:1 1 260px;min-width:250px;display:grid;gap:10px;">
                    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f8fafc;">
                        <div style="font-size:.86rem;color:#64748b;margin-bottom:4px;">หมวดที่มีสัดส่วนสูงสุด</div>
                        <div style="font-family:'Prompt',sans-serif;font-weight:700;color:#0f172a;">
                            <?= htmlspecialchars((string)$pieTop['label']) ?>
                            (<?= number_format((float)$pieTop['pct'], 1) ?>%)
                        </div>
                        <div style="font-size:.88rem;color:#334155;">
                            <?= number_format((float)$pieTop['amount'], 2) ?> บาท
                        </div>
                    </div>
                    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">
                        <div style="font-size:.86rem;color:#64748b;margin-bottom:8px;">สรุปยอดช่วงเวลาปัจจุบัน</div>
                        <div style="display:flex;justify-content:space-between;gap:10px;font-size:.9rem;margin-bottom:4px;">
                            <span style="color:#475569;">ยอดบริจาครวม</span>
                            <strong style="color:#0f172a;"><?= number_format($sumTotal, 2) ?> บาท</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:10px;font-size:.9rem;margin-bottom:4px;">
                            <span style="color:#475569;">จำนวนรายการ</span>
                            <strong style="color:#0f172a;"><?= number_format($donationCountTotal) ?> รายการ</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:10px;font-size:.9rem;">
                            <span style="color:#475569;">เฉลี่ยต่อรายการ</span>
                            <strong style="color:#0f172a;"><?= number_format($avgPerDonation, 2) ?> บาท</strong>
                        </div>
                    </div>
                    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;background:#fff;">
                        <?php foreach ($pieBreakdown as $i => $b): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed #e2e8f0;<?= ($i === array_key_last($pieBreakdown)) ? 'border-bottom:none;' : '' ?>">
                                <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                                    <span style="width:10px;height:10px;border-radius:999px;background:<?= htmlspecialchars((string)$b['color']) ?>;"></span>
                                    <span style="color:#334155;font-size:.9rem;"><?= htmlspecialchars((string)$b['label']) ?></span>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:.88rem;color:#0f172a;font-weight:700;"><?= number_format((float)$b['pct'], 1) ?>%</div>
                                    <div style="font-size:.8rem;color:#64748b;"><?= number_format((int)$b['count']) ?> รายการ</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const tabButtons = Array.from(document.querySelectorAll('[data-view-tab]'));
    const overviewView = document.getElementById('foundationDashboardOverviewView');
    const trendsView = document.getElementById('foundationDashboardTrendsView');
    const buttons = Array.from(document.querySelectorAll('[data-filter-cat]'));
    const featurePanels = Array.from(document.querySelectorAll('[data-feature-panel]'));
    const rows = Array.from(document.querySelectorAll('tr[data-cat]'));
    const noRows = document.getElementById('foundationDashboardNoRows');
    const dateModeEl = document.getElementById('fdDateMode');
    const dateRangeWrapEl = document.getElementById('fdDateRangeWrap');
    const dateFromEl = document.getElementById('fdDateFrom');
    const dateToEl = document.getElementById('fdDateTo');
    const dateMonthEl = document.getElementById('fdDateMonth');
    const dateYearEl = document.getElementById('fdDateYear');
    const dateClearEl = document.getElementById('fdDateClear');
    const dateSummaryEl = document.getElementById('fdDateSummary');

    let activeCat = 'all';
    let dateMode = 'all';
    let dateValue = '';
    let dateFrom = '';
    let dateTo = '';

    const pad2 = (n) => String(n).padStart(2, '0');
    const todayLocal = () => {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    };
    const monthLocal = () => {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1);
    };

    const setActive = (cat) => {
        buttons.forEach((btn) => {
            const isActive = btn.getAttribute('data-filter-cat') === cat;
            btn.classList.toggle('admin-dir-btn--primary', isActive);
            btn.classList.toggle('admin-dir-btn--ghost', !isActive);
        });
    };

    const formatDayLabel = (ymd) => {
        const p = (ymd || '').split('-');
        if (p.length !== 3) {
            return ymd;
        }
        return p[2] + '/' + p[1] + '/' + p[0];
    };

    const matchesDate = (row) => {
        if (dateMode === 'all') {
            return true;
        }
        if (dateMode === 'day') {
            if (dateFrom === '' && dateTo === '') {
                return true;
            }
            const d = row.getAttribute('data-donate-date') || '';
            if (d === '') {
                return false;
            }
            const from = dateFrom || dateTo;
            const to = dateTo || dateFrom;
            return d >= from && d <= to;
        }
        if (dateValue === '') {
            return true;
        }
        if (dateMode === 'month') {
            return (row.getAttribute('data-donate-month') || '') === dateValue;
        }
        if (dateMode === 'year') {
            return (row.getAttribute('data-donate-year') || '') === dateValue;
        }
        return true;
    };

    const formatDateSummary = () => {
        if (dateMode === 'all') {
            return '';
        }
        if (dateMode === 'day') {
            if (dateFrom === '' && dateTo === '') {
                return '';
            }
            const from = dateFrom || dateTo;
            const to = dateTo || dateFrom;
            if (from === to) {
                return 'วันที่ ' + formatDayLabel(from);
            }
            return 'ช่วง ' + formatDayLabel(from) + ' – ' + formatDayLabel(to);
        }
        if (dateValue === '') {
            return '';
        }
        if (dateMode === 'month') {
            const p = dateValue.split('-');
            if (p.length === 2) {
                return 'เดือน ' + p[1] + '/' + p[0];
            }
        }
        if (dateMode === 'year') {
            return 'ปี ' + dateValue + ' (พ.ศ. ' + (parseInt(dateValue, 10) + 543) + ')';
        }
        return '';
    };

    const syncDateInputsVisibility = () => {
        if (!dateModeEl) {
            return;
        }
        const mode = dateModeEl.value || 'all';
        if (dateRangeWrapEl) dateRangeWrapEl.style.display = mode === 'day' ? 'flex' : 'none';
        if (dateMonthEl) dateMonthEl.style.display = mode === 'month' ? '' : 'none';
        if (dateYearEl) dateYearEl.style.display = mode === 'year' ? '' : 'none';
        if (dateClearEl) dateClearEl.style.display = mode === 'all' ? 'none' : '';
    };

    const readDateFilter = () => {
        if (!dateModeEl) {
            dateMode = 'all';
            dateValue = '';
            return;
        }
        dateMode = dateModeEl.value || 'all';
        if (dateMode === 'day') {
            dateValue = '';
            let from = dateFromEl ? (dateFromEl.value || '') : '';
            let to = dateToEl ? (dateToEl.value || '') : '';
            if (from !== '' && to !== '' && to < from) {
                const swap = from;
                from = to;
                to = swap;
                if (dateFromEl) dateFromEl.value = from;
                if (dateToEl) dateToEl.value = to;
            }
            dateFrom = from;
            dateTo = to;
        } else if (dateMode === 'month' && dateMonthEl) {
            dateFrom = '';
            dateTo = '';
            dateValue = dateMonthEl.value || '';
        } else if (dateMode === 'year' && dateYearEl) {
            dateValue = dateYearEl.value || '';
        } else {
            dateValue = '';
            dateFrom = '';
            dateTo = '';
        }
    };

    const applyFilter = () => {
        if (!noRows) {
            return;
        }
        let visible = 0;
        rows.forEach((row) => {
            const rowCat = row.getAttribute('data-cat') || '';
            const catOk = activeCat === 'all' || rowCat === activeCat;
            const dateOk = matchesDate(row);
            const show = catOk && dateOk;
            row.style.display = show ? '' : 'none';
            if (show) {
                visible++;
            }
        });
        noRows.style.display = (rows.length > 0 && visible === 0) ? '' : 'none';
        setActive(activeCat);
        if (dateSummaryEl) {
            const period = formatDateSummary();
            if (period === '') {
                dateSummaryEl.textContent = rows.length > 0 ? 'แสดงทุกวันที่ (สูงสุด ' + rows.length + ' รายการล่าสุด)' : '';
            } else {
                dateSummaryEl.textContent = period + ' · แสดง ' + visible + ' รายการ';
            }
        }
    };

    const showFeaturePanel = (cat) => {
        featurePanels.forEach((panel) => {
            const pCat = panel.getAttribute('data-feature-panel') || '';
            panel.style.display = pCat === cat ? '' : 'none';
        });
    };

    const setActiveView = (view) => {
        // ตามคำขอ: ปุ่ม "ภาพรวม" ให้แสดงส่วนกราฟ
        const showTrends = view === 'overview';
        overviewView.style.display = showTrends ? 'none' : '';
        trendsView.style.display = showTrends ? '' : 'none';
        tabButtons.forEach((btn) => {
            const active = (btn.getAttribute('data-view-tab') === view);
            btn.classList.toggle('admin-dir-btn--primary', active);
            btn.classList.toggle('admin-dir-btn--analytics', !active);
        });
    };

    if (dateFromEl && !dateFromEl.value) {
        dateFromEl.value = todayLocal();
    }
    if (dateMonthEl && !dateMonthEl.value) {
        dateMonthEl.value = monthLocal();
    }

    if (dateModeEl) {
        dateModeEl.addEventListener('change', () => {
            const mode = dateModeEl.value || 'all';
            if (mode === 'day' && dateFromEl && !dateFromEl.value) {
                dateFromEl.value = todayLocal();
            }
            if (mode === 'month' && dateMonthEl && !dateMonthEl.value) {
                dateMonthEl.value = monthLocal();
            }
            syncDateInputsVisibility();
            readDateFilter();
            applyFilter();
        });
    }
    [dateFromEl, dateToEl, dateMonthEl, dateYearEl].forEach((el) => {
        if (!el) {
            return;
        }
        el.addEventListener('change', () => {
            readDateFilter();
            applyFilter();
        });
    });
    if (dateClearEl) {
        dateClearEl.addEventListener('click', () => {
            if (dateModeEl) {
                dateModeEl.value = 'all';
            }
            dateMode = 'all';
            dateValue = '';
            dateFrom = '';
            dateTo = '';
            if (dateFromEl) dateFromEl.value = '';
            if (dateToEl) dateToEl.value = '';
            syncDateInputsVisibility();
            applyFilter();
        });
    }
    syncDateInputsVisibility();

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            activeCat = btn.getAttribute('data-filter-cat') || 'all';
            applyFilter();
        });
    });

    if (buttons.length && rows.length) {
        applyFilter();
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const view = btn.getAttribute('data-view-tab') || 'overview';
            setActiveView(view);
        });
    });

    const weeklyCtx = document.getElementById('foundationWeeklyTrendChart');
    if (weeklyCtx && window.Chart) {
        const weeklyLabels = <?= json_encode($weeklyLabels, JSON_UNESCAPED_UNICODE) ?>;
        const weeklySums = <?= json_encode(array_map(static fn ($x) => round((float)$x, 2), $weeklySums), JSON_UNESCAPED_UNICODE) ?>;
        new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: weeklyLabels,
                datasets: [{
                    label: 'ยอดบริจาค (บาท)',
                    data: weeklySums,
                    borderColor: '#4A5BA8',
                    backgroundColor: 'rgba(74,91,168,.15)',
                    fill: true,
                    tension: .3,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        ticks: {
                            callback: (v) => Number(v).toLocaleString('th-TH')
                        }
                    }
                }
            }
        });
    }

    const pieCtx = document.getElementById('foundationCategoryPieChart');
    if (pieCtx && window.Chart) {
        const pieValues = [<?= round($sumChild, 2) ?>, <?= round($sumProject, 2) ?>, <?= round($sumNeed, 2) ?>];
        const pieLabels = ['เด็ก', 'โครงการ', 'สิ่งของ'];
        const pieTotal = pieValues.reduce((acc, n) => acc + Number(n || 0), 0);
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: pieLabels,
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#4A5BA8', '#22c55e', '#f59e0b']
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            generateLabels: (chart) => {
                                const ds = chart.data.datasets[0] || { data: [] };
                                const data = Array.isArray(ds.data) ? ds.data : [];
                                return pieLabels.map((label, i) => {
                                    const value = Number(data[i] || 0);
                                    const pct = pieTotal > 0 ? ((value / pieTotal) * 100) : 0;
                                    const meta = chart.getDatasetMeta(0);
                                    const style = chart.data.datasets[0].backgroundColor[i];
                                    return {
                                        text: `${label} (${pct.toFixed(1)}%)`,
                                        fillStyle: style,
                                        strokeStyle: style,
                                        lineWidth: 0,
                                        hidden: !!(meta.data[i] && meta.data[i].hidden),
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const value = Number(ctx.raw || 0);
                                const pct = pieTotal > 0 ? ((value / pieTotal) * 100) : 0;
                                return `${ctx.label}: ${value.toLocaleString('th-TH')} บาท (${pct.toFixed(1)}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    setActiveView('overview');
    showFeaturePanel('child');
})();
</script>
</body>
</html>

