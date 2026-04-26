<?php
// foundation_dashboard.php — แดชบอร์ดมูลนิธิ (ยอดรวมเด็ก/โครงการ/สิ่งของ + ทางลัดรายงานเชิงวิเคราะห์)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';

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
       d.donate_type, d.recurring_plan_code, d.recurring_status,
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
  OR (
    d.donate_type = 'child_subscription'
    AND LOWER(TRIM(COALESCE(d.recurring_status, ''))) = 'cancelled'
    AND d.target_id IN (
        SELECT child_id FROM foundation_children
        WHERE foundation_id = ? AND deleted_at IS NULL
    )
  )
ORDER BY d.transfer_datetime DESC, d.donate_id DESC
LIMIT 500
";
$stRows = $conn->prepare($sql);
if (!$stRows) {
    die('ไม่สามารถเตรียมคำสั่ง SQL');
}
$stRows->bind_param(
    'iiiiisii',
    $childCat,
    $foundationId,
    $projCat,
    $foundationId,
    $foundationName,
    $needCat,
    $foundationId,
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
                <a class="admin-dir-btn admin-dir-btn--ghost" href="foundation_analytics_report.php">รายงาน</a>
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
                    $dtLabel = $dtRaw !== '' ? date('d/m/Y H:i:s', strtotime($dtRaw)) : '-';
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
                    $planCodeRaw = (string)($row['recurring_plan_code'] ?? '');
                    $planSpec = $isSub ? drawdream_child_subscription_plan($planCodeRaw) : null;
                    $planLabel = foundation_dashboard_plan_label($planCodeRaw);
                    if ($planLabel === '-' && $planCodeRaw === '' && in_array($dt, ['project', 'need_item'], true)) {
                        $planLabel = 'ครั้งเดียว';
                    }
                    if ($isSub && is_array($planSpec) && ($planSpec['amount_thb'] ?? 0) > 0) {
                        $planLabel .= ' · ' . number_format((float)$planSpec['amount_thb'], 0) . ' บ.';
                    }
                    $subStatus = strtolower(trim((string)($row['recurring_status'] ?? '')));
                    if ($dt === 'child_subscription' && $subStatus === 'cancelled') {
                        $planLabel .= ' (ยกเลิกแล้ว)';
                    }
                    $chargeId = trim((string)($row['omise_charge_id'] ?? ''));
                    ?>
                    <tr data-cat="<?= htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') ?>">
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
                <td colspan="7" class="b--muted">ไม่มีข้อมูลในหมวดที่เลือก</td>
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
            <div style="max-width:320px;height:320px;">
                <canvas id="foundationCategoryPieChart"></canvas>
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
    if (!buttons.length || !rows.length || !noRows) return;

    const setActive = (activeCat) => {
        buttons.forEach((btn) => {
            const isActive = btn.getAttribute('data-filter-cat') === activeCat;
            btn.classList.toggle('admin-dir-btn--primary', isActive);
            btn.classList.toggle('admin-dir-btn--ghost', !isActive);
        });
    };

    const applyFilter = (cat) => {
        let visible = 0;
        rows.forEach((row) => {
            const rowCat = row.getAttribute('data-cat') || '';
            const show = (cat === 'all') || (rowCat === cat);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        noRows.style.display = visible > 0 ? 'none' : '';
        setActive(cat);
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

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const cat = btn.getAttribute('data-filter-cat') || 'all';
            applyFilter(cat);
        });
    });

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

