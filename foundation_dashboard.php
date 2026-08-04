<?php
// foundation_dashboard.php — แดชบอร์ดมูลนิธิ (กราฟโหลดพร้อมหน้า + ops แบบ async)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/foundation_donor_preview.php';

drawdream_foundation_require_management_access();

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

require_once __DIR__ . '/includes/foundation_outcome_compliance.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/foundation_dashboard_donations_load.php';
require_once __DIR__ . '/includes/foundation_dashboard_ops.php';
require_once __DIR__ . '/includes/foundation_analytics.php';

$uid = (int)$_SESSION['user_id'];
$stFp = $conn->prepare(
    'SELECT foundation_id, foundation_name, account_verified
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

$accountStatus = drawdream_foundation_sync_account_verified_from_db($conn, $uid);
$accountPaused = ($accountStatus === DRAWDREAM_FOUNDATION_ACCOUNT_PAUSED);

$childCat = drawdream_get_or_create_child_donate_category_id($conn);
$projCat = drawdream_get_or_create_project_donate_category_id($conn);
$needCat = drawdream_get_or_create_needitem_donate_category_id($conn);

$childMap = [];
$stC = $conn->prepare('SELECT child_id, child_name FROM foundation_children WHERE foundation_id = ?');
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
     WHERE (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
);
if ($stP) {
    $stP->bind_param('is', $foundationId, $foundationName);
    $stP->execute();
    $rp = $stP->get_result();
    while ($x = $rp->fetch_assoc()) {
        $projectMap[(int)$x['project_id']] = (string)($x['project_name'] ?? '');
    }
}

$chartBundle = foundation_dashboard_load_donation_bundle(
    $conn,
    $foundationId,
    $foundationName,
    $childCat,
    $projCat,
    $needCat,
    $childMap,
    $projectMap,
    500
);

$chartSummary = $chartBundle['summary'] ?? [];
$sumChild = (float)($chartSummary['sum_child'] ?? 0);
$sumProject = (float)($chartSummary['sum_project'] ?? 0);
$sumNeed = (float)($chartSummary['sum_need'] ?? 0);
$sumTotal = (float)($chartSummary['sum_total'] ?? ($sumChild + $sumProject + $sumNeed));
$rowCountChild = (int)($chartSummary['row_count_child'] ?? 0);
$rowCountProject = (int)($chartSummary['row_count_project'] ?? 0);
$rowCountNeed = (int)($chartSummary['row_count_need'] ?? 0);
$latestByCat = $chartSummary['latest_by_cat'] ?? ['child' => '', 'project' => '', 'need' => ''];
$donationYears = $chartBundle['donation_years'] ?? [(int)date('Y')];
$analysisTitles = $chartBundle['analysis_titles'] ?? [];
$fdAnalysis = [
    'line_title' => (string)($analysisTitles['line_title'] ?? 'แนวโน้มยอดบริจาครายสัปดาห์'),
    'line_insight' => (string)($analysisTitles['line_insight'] ?? ''),
    'pie_title' => (string)($analysisTitles['pie_title'] ?? 'สัดส่วนตามช่องทางบริจาค'),
    'pie_insight' => (string)($analysisTitles['pie_insight'] ?? ''),
];

$fdPeriodMeta = foundation_dashboard_donation_period_meta(
    $conn,
    $foundationId,
    $foundationName,
    $childCat,
    $projCat,
    $needCat
);
$fdSponsorship = drawdream_foundation_analytics_sponsorship($conn, $foundationId, $childCat, true);

$cntChildProfiles = count($childMap);
$cntProjects = count($projectMap);
$needItemCnt = 0;
$stNeedCnt = $conn->prepare('SELECT COUNT(*) AS c FROM foundation_needlist WHERE foundation_id = ?');
if ($stNeedCnt) {
    $stNeedCnt->bind_param('i', $foundationId);
    $stNeedCnt->execute();
    $needItemCnt = (int)($stNeedCnt->get_result()->fetch_assoc()['c'] ?? 0);
}

$activeSponsoredChildCnt = 0;
$projectOpenCnt = 0;
$projectCompletedCnt = 0;
$needApprovedCnt = 0;
$needFundedCnt = 0;
$recentCancelledChildren = [];
$donRows = [];

$fdDonPayloadJson = json_encode(
    $chartBundle['donations'] ?? [],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($fdDonPayloadJson === false) {
    $fdDonPayloadJson = '[]';
}
$fdWeekMetaJson = json_encode(
    $chartBundle['week_meta'] ?? ['labels' => [], 'keys' => []],
    JSON_UNESCAPED_UNICODE
);
if ($fdWeekMetaJson === false) {
    $fdWeekMetaJson = '{"labels":[],"keys":[]}';
}
$fdFilterCountsJson = json_encode(
    $chartBundle['filter_counts'] ?? null,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($fdFilterCountsJson === false) {
    $fdFilterCountsJson = 'null';
}

$fdStaticJson = json_encode(
    [
        'period' => $fdPeriodMeta,
        'sponsorship' => [
            'active' => (int)($fdSponsorship['monthly']['active'] ?? 0),
            'cancel_pct' => $fdSponsorship['monthly']['cancel_pct'] ?? null,
            'denom' => (int)($fdSponsorship['monthly']['denom'] ?? 0),
        ],
        'ops' => [
            'active_sponsors' => (int)($fdSponsorship['monthly']['active'] ?? 0),
            'escrow_pending_baht' => 0.0,
            'need_awaiting_delivery' => 0,
        ],
        'charts_limit' => 500,
        'list_per_page' => 50,
        'charts_preloaded' => true,
        'bootstrap_via_ajax' => true,
        'table_pagination' => true,
        'total_donation_count' => (int)($fdPeriodMeta['total_donation_count'] ?? 0),
    ],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($fdStaticJson === false) {
    $fdStaticJson = '{}';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>แดชบอร์ดมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/foundation_manage.css?v=6">
</head>
<body class="foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <?php if ($accountPaused): ?>
    <section class="fd-pause-banner" id="fdPauseBanner" aria-label="บัญชีถูกพักชั่วคราว">
        <p class="fd-pause-banner__label">บัญชีถูกพักชั่วคราว</p>
        <p class="fd-pause-banner__text" id="fdPauseBannerText">
            ยังไม่อัปเดตผลลัพธ์ค้างเกิน <?= (int)DRAWDREAM_OUTCOME_COMPLIANCE_PAUSE_DAYS ?> วัน — มูลนิธิจะไม่แสดงต่อสาธารณะและไม่สามารถเพิ่มเด็ก/โครงการ/สิ่งของใหม่ได้จนกว่าจะอัปเดตครบ
        </p>
        <a class="fd-pause-banner__btn" id="fdPauseBannerBtn" href="#" hidden>อัปเดตผลลัพธ์เพื่อปลดพัก</a>
    </section>
    <?php endif; ?>
    <section class="fd-next-action" id="fdNextActionSection" hidden aria-label="งานถัดไป">
        <p class="fd-next-action__label">งานถัดไปของคุณ</p>
        <p class="fd-next-action__text" id="fdNextActionText"></p>
        <a class="fd-next-action__btn" id="fdNextActionBtn" href="#">ทำเลย</a>
    </section>
    <div class="admin-directory-head">
        <div class="fd-head-layout">
            <div>
                <h1 class="admin-directory-title" style="margin-bottom:4px;">แดชบอร์ดมูลนิธิ</h1>
                <p style="margin:0;font-size:.9rem;color:#4b5563;"><?= htmlspecialchars($foundationName) ?></p>
            </div>
            <div class="admin-dir-actions fd-head-layout__tabs" style="flex-shrink:0;">
                <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-view-tab="charts">กราฟและแนวโน้ม</button>
                <button type="button" class="admin-dir-btn admin-dir-btn--analytics" data-view-tab="list">รายการบริจาค</button>
            </div>
        </div>
    </div>

    <section class="fd-ops-panel" id="fdOpsPanel" aria-label="สถานะงานปฏิบัติการ">
        <div class="fd-ops-loading" id="fdOpsLoading" aria-live="polite">กำลังโหลดสถานะงาน…</div>
        <div class="fd-ops-dynamic" id="fdOpsDynamic" aria-busy="true"></div>
    </section>

    <div id="foundationDashboardChartsView">
        <p class="fd-kpi-section-title">ยอดบริจาคตามตัวกรอง</p>
        <div class="fd-kpi-grid" id="fdKpiGrid">
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">ยอดบริจาครวม</div>
                <div class="fd-kpi-card__value" id="fdKpiTotal">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiTotalSub">ตามตัวกรอง</div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">จำนวนรายการ</div>
                <div class="fd-kpi-card__value" id="fdKpiCount">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiCountSub">ครั้งที่โอนสำเร็จ</div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">สัปดาห์ล่าสุด</div>
                <div class="fd-kpi-card__value" id="fdKpiWeek">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiWeekTrend">แนวโน้มรายสัปดาห์</div>
            </div>
        </div>

        <p class="fd-kpi-section-title" style="margin-top:4px;">รายได้ต่อเนื่องและบริบทเวลา</p>
        <div class="fd-kpi-grid fd-kpi-grid--secondary" id="fdKpiContextGrid">
            <div class="fd-kpi-card fd-kpi-card--accent">
                <div class="fd-kpi-card__label">ผู้อุปการะรายรอบ (active)</div>
                <div class="fd-kpi-card__value" id="fdKpiActiveSponsors">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiActiveSponsorsSub">แผนรายเดือน · จาก subscription</div>
            </div>
            <div class="fd-kpi-card fd-kpi-card--accent">
                <div class="fd-kpi-card__label">รายได้อุปการะรายรอบ</div>
                <div class="fd-kpi-card__value" id="fdKpiSubRevenue">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiSubRevenueSub">ตามตัวกรองวันที่</div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">อัตรายกเลิกอุปการะ (รายเดือน)</div>
                <div class="fd-kpi-card__value" id="fdKpiCancelRate">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiCancelRateSub">เทียบ active + paused + ยกเลิก</div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">เดือนนี้เทียบเดือนก่อน</div>
                <div class="fd-kpi-card__value" id="fdKpiMonthCompare">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiMonthCompareSub">ยอดรวมทั้งมูลนิธิ</div>
                <div class="fd-kpi-delta" id="fdKpiMonthDelta"></div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">รายการสิ่งของที่ยังไม่จัดส่ง</div>
                <div class="fd-kpi-card__value" id="fdKpiNeedUndelivered">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiNeedUndeliveredSub">มูลนิธิชำระค่าบริการแล้ว</div>
            </div>
            <div class="fd-kpi-card">
                <div class="fd-kpi-card__label">เงินค้างรับ</div>
                <div class="fd-kpi-card__value" id="fdKpiEscrow">—</div>
                <div class="fd-kpi-card__sub" id="fdKpiEscrowSub">คือยอดบริจาคโครงการที่ชำระค่าบริการแล้ว รอแอดมินโอนให้มูลนิธิ (ไม่รวมรายการสิ่งของ)</div>
            </div>
        </div>

        <div id="fdDateFilterAnchorCharts">
        <div id="fdDateFilterBar" class="foundation-donation-date-filter fd-shared-filter">
            <span style="font-size:.9rem;font-weight:600;color:#374151;">กรองตามวันที่</span>
            <span style="font-size:.8rem;color:#94a3b8;">ใช้กับกราฟและตาราง</span>
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
        </div>

        <div class="admin-dir-table-wrap fd-chart-panel">
            <h3 class="fd-insight-heading" id="fdLineTitle"><?= htmlspecialchars((string)$fdAnalysis['line_title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fd-insight-sub" id="fdLineInsight"><?= htmlspecialchars((string)$fdAnalysis['line_insight'], ENT_QUOTES, 'UTF-8') ?></p>
            <p style="margin:-6px 0 10px;">
                <a href="#" class="fd-chart-link" id="fdJumpPeakWeek" data-jump-list data-filter-cat="all" style="display:none;">ดูรายการในสัปดาห์ยอดสูง</a>
            </p>
            <div class="fd-chart-canvas">
                <canvas id="foundationWeeklyTrendChart"></canvas>
            </div>
        </div>

        <div class="admin-dir-table-wrap fd-chart-panel">
            <h3 class="fd-insight-heading" id="fdPieTitle"><?= htmlspecialchars((string)$fdAnalysis['pie_title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fd-insight-sub" id="fdPieInsight"><?= htmlspecialchars((string)$fdAnalysis['pie_insight'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="fd-pie-layout">
                <div class="fd-pie-chart-box">
                    <canvas id="foundationCategoryPieChart"></canvas>
                </div>
                <div class="fd-bar-chart-col">
                    <p>เปรียบเทียบยอดเงินกับจำนวนครั้ง (ไม่ให้ Pie ชวนเข้าใจผิด)</p>
                    <div class="fd-bar-chart-canvas">
                        <canvas id="foundationCategoryBarChart"></canvas>
                    </div>
                </div>
                <div class="fd-pie-side-col" id="fdPieSideCards">
                    <!-- เติมด้วย JS -->
                </div>
            </div>
        </div>
    </div>

    <div id="foundationDashboardListView" style="display:none;">
    <div id="fdDateFilterAnchorList"></div>
    <div class="fd-summary-grid">
        <a href="foundation_children_directory.php" class="fd-summary-card">
            <strong>เด็กในระบบ</strong><br>
            <span id="fdCountChildren"><?= (int)$cntChildProfiles ?></span> คน · ยอดบริจาค <span id="fdSummaryChildAmt"><?= number_format($sumChild, 2) ?></span> บาท
        </a>
        <a href="foundation_projects_directory.php" class="fd-summary-card">
            <strong>โครงการ</strong><br>
            <span id="fdCountProjects"><?= (int)$cntProjects ?></span> โครงการ · ยอดบริจาค <span id="fdSummaryProjectAmt"><?= number_format($sumProject, 2) ?></span> บาท
        </a>
        <a href="foundation_needlist_directory.php" class="fd-summary-card">
            <strong>รายการสิ่งของ</strong><br>
            <span id="fdCountNeedItems"><?= (int)$needItemCnt ?></span> รายการ · ยอดบริจาค <span id="fdSummaryNeedAmt"><?= number_format($sumNeed, 2) ?></span> บาท
        </a>
        <div class="fd-summary-card fd-summary-card--total">
            <strong>รวมทั้งมูลนิธิ</strong><br>
            <span id="fdSummaryTotalAmt"><?= number_format($sumTotal, 2) ?></span> บาท (<span id="fdSummaryDonationCnt"><?= (int)($chartSummary['donation_count'] ?? 0) ?></span> รายการ)
        </div>
    </div>

    <div class="fd-feature-panel-wrap">
        <div data-feature-panel="child">
            <strong>ภาพรวมฟีเจอร์เด็ก</strong>
            <div style="margin-top:8px;color:#374151;">
                โปรไฟล์เด็กทั้งหมด <span id="fdCountChildrenFeature"><?= $cntChildProfiles ?></span> คน · มีผู้อุปการะแบบรายรอบ <span id="fdCountActiveSponsors"><?= $activeSponsoredChildCnt ?></span> คน ·
                รายการบริจาค <span id="fdFeatureChildCnt"><?= $rowCountChild ?></span> รายการ · ยอดรวม <span id="fdFeatureChildAmt"><?= number_format($sumChild, 2) ?></span> บาท
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

    <div class="fd-cat-filters">
        <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-filter-cat="all">ทั้งหมด (…)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="child">เด็ก (…)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="project">โครงการ (…)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="need">รายการสิ่งของ (…)</button>
    </div>

    <div class="fd-list-toolbar">
        <label style="display:flex;align-items:center;gap:8px;flex:1 1 280px;font-size:.88rem;color:#475569;">
            <span style="white-space:nowrap;font-weight:600;">ค้นหา</span>
            <input type="search" id="fdListSearch" class="fd-list-search" placeholder="ชื่อผู้บริจาค, เลขรายการ, เลขผู้เสียภาษี, เป้าหมาย…" autocomplete="off">
        </label>
        <span style="font-size:.8rem;color:#94a3b8;">ใช้ร่วมกับตัวกรองวันที่และช่องทางด้านบน</span>
    </div>
    <p class="fd-list-summary" id="fdListSummary" aria-live="polite"></p>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>เวลาโอน</th>
                <th>เลขรายการ</th>
                <th>ผู้บริจาค</th>
                <th>เลขผู้เสียภาษี</th>
                <th>ช่องทาง</th>
                <th>เป้าหมาย</th>
                <th>แผน</th>
                <th class="admin-dir-num">จำนวนเงิน (บาท)</th>
            </tr>
            </thead>
            <tbody id="fdDonationTableBody">
            <?php if ($donRows === []): ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีประวัติการบริจาคที่เข้ามูลนิธินี้</td></tr>
            <?php endif; ?>
            <tr id="foundationDashboardNoRows" style="display:none;">
                <td colspan="8" class="b--muted">ไม่มีข้อมูลตามเงื่อนไขที่เลือก</td>
            </tr>
            </tbody>
        </table>
    </div>
    <div class="fd-table-pagination" id="fdTablePagination" hidden>
        <button type="button" id="fdTablePrev" disabled>ก่อนหน้า</button>
        <span class="fd-table-pagination__info" id="fdTablePageInfo" aria-live="polite"></span>
        <button type="button" id="fdTableNext" disabled>ถัดไป</button>
    </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_chartjs_tag('', true) . "\n" . drawdream_sweetalert2_js_tag('', true); ?>
<script src="js/drawdream-swal.js?v=1"></script>
<script>
window.FD_DONATIONS = <?= $fdDonPayloadJson ?>;
window.FD_WEEK_META = <?= $fdWeekMetaJson ?>;
window.FD_STATIC = <?= $fdStaticJson ?>;
window.FD_FILTER_COUNTS = <?= $fdFilterCountsJson ?>;
window.FD_DONATION_YEARS = <?= json_encode(array_values($donationYears), JSON_UNESCAPED_UNICODE) ?>;
window.FD_DATA_URL = 'foundation_dashboard_data.php';
</script>
<script src="js/foundation_dashboard_charts.js?v=12" defer></script>
</body>
</html>
