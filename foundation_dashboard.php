<?php
declare(strict_types=1);
// foundation_dashboard.php — แดชบอร์ดมูลนิธิ (ยอดรวมเด็ก/โครงการ/สิ่งของ + กราฟ/รายการบริจาค)

include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/foundation_dashboard_insights.php';
require_once __DIR__ . '/includes/foundation_dashboard_todos.php';
require_once __DIR__ . '/includes/foundation_dashboard_ops.php';
require_once __DIR__ . '/includes/foundation_analytics.php';
require_once __DIR__ . '/includes/e_receipt.php';

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
     WHERE foundation_id = ?'
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

$selCompanyTax = 'NULL AS receipt_company_tax_id';
$selCompanyName = 'NULL AS receipt_company_name';
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_company_tax_id'");
if ($colChk && $colChk->num_rows > 0) {
    $selCompanyTax = 'dn.receipt_company_tax_id';
}
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_company_name'");
if ($colChk && $colChk->num_rows > 0) {
    $selCompanyName = 'dn.receipt_company_name';
}

$sql = "
SELECT d.donate_id, d.amount, d.transfer_datetime, d.payment_status,
       d.omise_charge_id, dn.tax_id, d.donor_id,
       d.donate_type,
       d.category_id, d.target_id,
       dn.first_name, dn.last_name, u.email AS donor_email,
       {$selCompanyTax}, {$selCompanyName}
FROM donation d
LEFT JOIN donor dn ON dn.user_id = d.donor_id
LEFT JOIN `user` u ON u.user_id = d.donor_id
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
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

$fdAnalysis = foundation_dashboard_analyze_donations(
    $donRows,
    $childCat,
    $projCat,
    $needCat,
    $childMap,
    $projectMap
);
$sumChild = (float)$fdAnalysis['sum_child'];
$sumProject = (float)$fdAnalysis['sum_project'];
$sumNeed = (float)$fdAnalysis['sum_need'];
$rowCountChild = (int)$fdAnalysis['row_count_child'];
$rowCountProject = (int)$fdAnalysis['row_count_project'];
$rowCountNeed = (int)$fdAnalysis['row_count_need'];
$sumTotal = (float)$fdAnalysis['sum_total'];
$pieBreakdown = $fdAnalysis['pie_breakdown'];
$pieTop = $fdAnalysis['pie_top'];
$donationCountTotal = (int)$fdAnalysis['donation_count_total'];
$avgPerDonation = (float)$fdAnalysis['avg_per_donation'];
$weeklyLabels = $fdAnalysis['weekly_labels'];
$weeklySums = $fdAnalysis['weekly_sums'];
$fdDonPayloadJson = json_encode(
    foundation_dashboard_donations_json_payload($donRows, $childCat, $projCat, $needCat, $childMap, $projectMap),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($fdDonPayloadJson === false) {
    $fdDonPayloadJson = '[]';
}
$fdWeekMetaJson = json_encode(
    [
        'labels' => $fdAnalysis['weekly_labels'],
        'keys' => $fdAnalysis['weekly_keys'],
    ],
    JSON_UNESCAPED_UNICODE
);
if ($fdWeekMetaJson === false) {
    $fdWeekMetaJson = '{"labels":[],"keys":[]}';
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
     WHERE (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
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

$accountVerified = !empty($fp['account_verified']);
$fdTodos = foundation_dashboard_build_todos($conn, $foundationId, $foundationName, $accountVerified);
$fdOps = foundation_dashboard_build_ops_stats($conn, $foundationId, $foundationName);
$fdPeriodMeta = foundation_dashboard_donation_period_meta(
    $conn,
    $foundationId,
    $foundationName,
    $childCat,
    $projCat,
    $needCat
);
$fdSponsorship = drawdream_foundation_analytics_sponsorship($conn, $foundationId, $childCat);
$fdCancelPct = $fdSponsorship['monthly']['cancel_pct'];
$fdStaticJson = json_encode(
    [
        'period' => $fdPeriodMeta,
        'sponsorship' => [
            'active' => (int)($fdSponsorship['monthly']['active'] ?? 0),
            'cancel_pct' => $fdCancelPct,
            'denom' => (int)($fdSponsorship['monthly']['denom'] ?? 0),
        ],
        'ops' => [
            'active_sponsors' => (int)($fdOps['active_sponsors'] ?? 0),
            'escrow_pending_baht' => (float)($fdOps['escrow_pending_baht'] ?? 0),
            'need_awaiting_delivery' => (int)($fdOps['need_awaiting_delivery'] ?? 0),
        ],
        'list_limit' => 500,
        'list_loaded' => count($donRows),
        'total_donation_count' => (int)($fdPeriodMeta['total_donation_count'] ?? 0),
    ],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
if ($fdStaticJson === false) {
    $fdStaticJson = '{}';
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>แดชบอร์ดมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <style>
        .fd-kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px; margin:0 0 16px; }
        .fd-kpi-card { border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px; background:#fff; }
        .fd-kpi-card__label { font-size:.82rem; color:#64748b; margin-bottom:4px; }
        .fd-kpi-card__value { font-family:'Prompt',sans-serif; font-size:1.15rem; font-weight:700; color:#0f172a; }
        .fd-kpi-card__sub { font-size:.8rem; color:#64748b; margin-top:4px; }
        .fd-insight-heading { margin:0 0 6px; font-family:'Prompt',sans-serif; font-size:1.05rem; font-weight:700; color:#0f172a; line-height:1.45; }
        .fd-insight-sub { margin:0 0 14px; color:#475569; font-size:.92rem; line-height:1.55; }
        .fd-chart-link { font-size:.86rem; color:#3c5099; text-decoration:none; }
        .fd-chart-link:hover { text-decoration:underline; }
        .fd-pie-side-card { border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#f8fafc; }
        .fd-pie-side-card__label { font-size:.86rem; color:#64748b; margin-bottom:4px; }
        .fd-pie-side-card__value { font-weight:700; font-size:.95rem; color:#0f172a; }
        .fd-pie-side-card__sub { font-size:.88rem; color:#475569; margin-top:2px; }
        .fd-pie-side-breakdown { list-style:none; margin:8px 0 0; padding:0; display:grid; gap:6px; }
        .fd-pie-side-breakdown li { display:flex; align-items:center; gap:8px; font-size:.88rem; color:#334155; }
        .fd-pie-side-breakdown__dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .fd-list-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:10px 14px; margin:0 0 12px; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; }
        .fd-list-search { flex:1 1 220px; min-width:180px; padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:.9rem; }
        .fd-list-summary { margin:0 0 12px; font-size:.9rem; color:#374151; }
        .fd-list-summary strong { color:#0f172a; }
        .fd-receipt-ref { font-size:.82rem; font-family:ui-monospace,Consolas,monospace; color:#334155; }
        .fd-shared-filter { margin:12px 0 16px; }
        .fd-head-layout { display:grid; grid-template-columns:1fr minmax(260px,320px); gap:14px 18px; align-items:start; width:100%; }
        @media (max-width:960px) { .fd-head-layout { grid-template-columns:1fr; } }
        .fd-head-layout__tabs { grid-column:1 / -1; }
        .fd-todo-panel { border:1px solid #fde68a; border-radius:12px; padding:12px 14px; background:linear-gradient(180deg,#fffbeb 0%,#fff 100%); box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .fd-todo-panel__title { margin:0 0 8px; font-size:.92rem; font-weight:700; color:#92400e; display:flex; align-items:center; gap:6px; }
        .fd-todo-panel__badge { display:inline-flex; min-width:1.25rem; height:1.25rem; padding:0 6px; border-radius:999px; background:#f59e0b; color:#fff; font-size:.75rem; font-weight:700; align-items:center; justify-content:center; }
        .fd-todo-panel__list { margin:0; padding:0; list-style:none; }
        .fd-todo-panel__item + .fd-todo-panel__item { margin-top:8px; padding-top:8px; border-top:1px solid #fef3c7; }
        .fd-todo-panel__link { display:block; font-size:.86rem; line-height:1.45; color:#334155; text-decoration:none; }
        .fd-todo-panel__link:hover { color:#1e40af; text-decoration:underline; }
        .fd-todo-panel__link--high::before { content:''; display:inline-block; width:6px; height:6px; border-radius:50%; background:#ef4444; margin-right:6px; vertical-align:middle; }
        .fd-todo-panel__empty { margin:0; font-size:.86rem; color:#64748b; line-height:1.5; }
        .fd-todo-panel__hint { margin:8px 0 0; font-size:.78rem; color:#94a3b8; }
        .fd-ops-panel { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin:0 0 16px; }
        @media (max-width:900px) { .fd-ops-panel { grid-template-columns:1fr; } }
        .fd-ops-group { border:1px solid #e5e7eb; border-radius:14px; background:#fff; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,.04); }
        .fd-ops-group__head { display:flex; align-items:center; gap:8px; padding:10px 14px; font-size:.82rem; font-weight:700; letter-spacing:.02em; text-transform:none; border-bottom:1px solid #f1f5f9; }
        .fd-ops-group--project .fd-ops-group__head { background:linear-gradient(90deg,#ecfdf5 0%,#fff 100%); color:#166534; }
        .fd-ops-group--child .fd-ops-group__head { background:linear-gradient(90deg,#eff6ff 0%,#fff 100%); color:#1e40af; }
        .fd-ops-group--need .fd-ops-group__head { background:linear-gradient(90deg,#fffbeb 0%,#fff 100%); color:#92400e; }
        .fd-ops-group__dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .fd-ops-group--project .fd-ops-group__dot { background:#22c55e; }
        .fd-ops-group--child .fd-ops-group__dot { background:#4A5BA8; }
        .fd-ops-group--need .fd-ops-group__dot { background:#f59e0b; }
        .fd-ops-chips { display:flex; flex-wrap:wrap; gap:8px; padding:12px 14px 14px; }
        .fd-ops-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 11px; border-radius:999px; font-size:.82rem; text-decoration:none; border:1px solid #e5e7eb; background:#f8fafc; color:#475569; transition:background .15s,border-color .15s,transform .1s; }
        .fd-ops-chip:hover { background:#fff; border-color:#cbd5e1; transform:translateY(-1px); }
        .fd-ops-chip__num { display:inline-flex; min-width:1.5rem; height:1.5rem; padding:0 6px; border-radius:999px; align-items:center; justify-content:center; font-weight:700; font-size:.78rem; background:#e2e8f0; color:#334155; }
        .fd-ops-chip--idle { opacity:.72; }
        .fd-ops-chip--idle .fd-ops-chip__num { background:#f1f5f9; color:#94a3b8; }
        .fd-ops-chip--warn { border-color:#fecaca; background:#fff5f5; color:#991b1b; }
        .fd-ops-chip--warn .fd-ops-chip__num { background:#ef4444; color:#fff; }
        .fd-ops-chip--ok { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
        .fd-ops-chip--ok .fd-ops-chip__num { background:#22c55e; color:#fff; }
        .fd-kpi-section-title { margin:0 0 10px; font-size:.88rem; font-weight:600; color:#64748b; }
        .fd-kpi-grid--secondary { margin-top:0; margin-bottom:16px; }
        .fd-kpi-card--accent { border-color:#dbeafe; background:linear-gradient(180deg,#f8fafc 0%,#fff 100%); }
        .fd-kpi-card--warn { border-color:#fde68a; background:linear-gradient(180deg,#fffbeb 0%,#fff 100%); }
        .fd-kpi-delta { font-size:.78rem; margin-top:4px; }
        .fd-kpi-delta--up,
        .fd-kpi-card__sub.fd-kpi-trend--up { color:#597D57; }
        .fd-kpi-delta--down,
        .fd-kpi-card__sub.fd-kpi-trend--down { color:#CC583F; }
        .fd-kpi-delta--flat { color:#64748b; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <div class="fd-head-layout">
            <div>
                <h1 class="admin-directory-title" style="margin-bottom:4px;">แดชบอร์ดมูลนิธิ</h1>
                <p style="margin:0;font-size:.9rem;color:#4b5563;"><?= htmlspecialchars($foundationName) ?></p>
            </div>
            <aside class="fd-todo-panel" aria-label="งานที่ควรทำวันนี้">
                <h2 class="fd-todo-panel__title">
                    งานวันนี้
                    <?php if ($fdTodos !== []): ?>
                    <span class="fd-todo-panel__badge"><?= count($fdTodos) ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($fdTodos === []): ?>
                <p class="fd-todo-panel__empty">ไม่มีงานค้างที่ระบบตรวจพบ — ดีมาก ลองดูแนวโน้มบริจาคด้านล่างได้</p>
                <?php else: ?>
                <ul class="fd-todo-panel__list">
                    <?php foreach ($fdTodos as $todo): ?>
                    <?php
                    $todoHref = (string)($todo['href'] ?? '#');
                    $todoText = (string)($todo['text'] ?? '');
                    $prio = (string)($todo['priority'] ?? 'normal');
                    $linkClass = 'fd-todo-panel__link' . ($prio === 'high' ? ' fd-todo-panel__link--high' : '');
                    ?>
                    <li class="fd-todo-panel__item">
                        <a class="<?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($todoHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($todoText, ENT_QUOTES, 'UTF-8') ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="fd-todo-panel__hint">อัปเดตจากข้อมูลในระบบ · คลิกแต่ละข้อเพื่อไปทำงาน</p>
                <?php endif; ?>
            </aside>
            <div class="admin-dir-actions fd-head-layout__tabs" style="flex-shrink:0;">
                <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-view-tab="charts">กราฟและแนวโน้ม</button>
                <button type="button" class="admin-dir-btn admin-dir-btn--analytics" data-view-tab="list">รายการบริจาค</button>
            </div>
        </div>
    </div>

    <section class="fd-ops-panel" aria-label="สถานะงานปฏิบัติการ">
        <?php foreach ($fdOps['groups'] as $group):
            $gKey = (string)($group['key'] ?? '');
            $gTitle = (string)($group['title'] ?? '');
            ?>
        <div class="fd-ops-group fd-ops-group--<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="fd-ops-group__head">
                <span class="fd-ops-group__dot" aria-hidden="true"></span>
                <?= htmlspecialchars($gTitle, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="fd-ops-chips">
                <?php foreach (($group['items'] ?? []) as $item):
                    $cnt = (int)($item['count'] ?? 0);
                    $urgent = !empty($item['urgent']) && $cnt > 0;
                    $isInfo = $cnt > 0 && empty($item['urgent']);
                    $chipClass = 'fd-ops-chip';
                    if ($cnt <= 0) {
                        $chipClass .= ' fd-ops-chip--idle';
                    } elseif ($urgent) {
                        $chipClass .= ' fd-ops-chip--warn';
                    } elseif ($isInfo) {
                        $chipClass .= ' fd-ops-chip--ok';
                    }
                    ?>
                <a class="<?= htmlspecialchars($chipClass, ENT_QUOTES, 'UTF-8') ?>"
                   href="<?= htmlspecialchars((string)($item['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="fd-ops-chip__num"><?= $cnt ?></span>
                    <span><?= htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
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
        <div id="fdDateFilterBar" class="foundation-donation-date-filter fd-shared-filter" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;margin:0 0 14px;">
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

        <div class="admin-dir-table-wrap" style="padding:18px;margin-bottom:14px;">
            <h3 class="fd-insight-heading" id="fdLineTitle"><?= htmlspecialchars((string)$fdAnalysis['line_title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fd-insight-sub" id="fdLineInsight"><?= htmlspecialchars((string)$fdAnalysis['line_insight'], ENT_QUOTES, 'UTF-8') ?></p>
            <p style="margin:-6px 0 10px;">
                <a href="#" class="fd-chart-link" id="fdJumpPeakWeek" data-jump-list data-filter-cat="all" style="display:none;">ดูรายการในสัปดาห์ยอดสูง</a>
            </p>
            <div style="height:260px;max-width:100%;">
                <canvas id="foundationWeeklyTrendChart"></canvas>
            </div>
        </div>

        <div class="admin-dir-table-wrap" style="padding:18px;margin-bottom:14px;">
            <h3 class="fd-insight-heading" id="fdPieTitle"><?= htmlspecialchars((string)$fdAnalysis['pie_title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fd-insight-sub" id="fdPieInsight"><?= htmlspecialchars((string)$fdAnalysis['pie_insight'], ENT_QUOTES, 'UTF-8') ?></p>
            <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start;">
                <div style="max-width:300px;height:300px;flex:0 0 300px;">
                    <canvas id="foundationCategoryPieChart"></canvas>
                </div>
                <div style="flex:1 1 280px;min-width:260px;">
                    <p style="margin:0 0 8px;font-size:.88rem;color:#64748b;">เปรียบเทียบยอดเงินกับจำนวนครั้ง (ไม่ให้ Pie ชวนเข้าใจผิด)</p>
                    <div style="height:220px;">
                        <canvas id="foundationCategoryBarChart"></canvas>
                    </div>
                </div>
                <div style="flex:1 1 220px;min-width:200px;display:grid;gap:10px;" id="fdPieSideCards">
                    <!-- เติมด้วย JS -->
                </div>
            </div>
        </div>
    </div>

    <div id="foundationDashboardListView" style="display:none;">
    <div id="fdDateFilterAnchorList"></div>
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

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px;">
        <button type="button" class="admin-dir-btn admin-dir-btn--primary" data-filter-cat="all">ทั้งหมด (<?= count($donRows) ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="child">เด็ก (<?= $rowCountChild ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="project">โครงการ (<?= $rowCountProject ?>)</button>
        <button type="button" class="admin-dir-btn admin-dir-btn--ghost" data-filter-cat="need">รายการสิ่งของ (<?= $rowCountNeed ?>)</button>
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
            <tbody>
            <?php if ($donRows === []): ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีประวัติการบริจาคที่เข้ามูลนิธินี้</td></tr>
            <?php else: ?>
                <?php foreach ($donRows as $row):
                    $dtRaw = trim((string)($row['transfer_datetime'] ?? ''));
                    $dtTs = $dtRaw !== '' ? strtotime($dtRaw) : false;
                    $dtLabel = $dtTs !== false ? date('d/m/Y H:i:s', $dtTs) : '-';
                    $dataDonateDate = $dtTs !== false ? date('Y-m-d', $dtTs) : '';
                    $dataDonateMonth = $dtTs !== false ? date('Y-m', $dtTs) : '';
                    $dataDonateYear = $dtTs !== false ? date('Y', $dtTs) : '';
                    $dataDonateWeek = $dtTs !== false ? date('o-W', $dtTs) : '';
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
                    $donateId = (int)($row['donate_id'] ?? 0);
                    $receiptRef = drawdream_donation_receipt_ref_from_row($donateId, $dtRaw);
                    $profileTaxId = trim((string)($row['tax_id'] ?? ''));
                    $companyName = trim((string)($row['receipt_company_name'] ?? ''));
                    $companyTaxId = trim((string)($row['receipt_company_tax_id'] ?? ''));
                    if ($companyName !== '' && $companyTaxId !== '') {
                        $taxDisplay = $companyTaxId;
                    } else {
                        $taxDisplay = $profileTaxId;
                    }
                    $searchBlob = implode(' ', array_filter([
                        $fullName,
                        $receiptRef,
                        $taxDisplay,
                        $targetCell,
                        $channel,
                        $planLabel,
                    ], static fn ($v) => trim((string)$v) !== ''));
                    $rowAmount = (float)($row['amount'] ?? 0);
                    ?>
                    <tr data-cat="<?= htmlspecialchars($rowCat, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-date="<?= htmlspecialchars($dataDonateDate, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-month="<?= htmlspecialchars($dataDonateMonth, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-year="<?= htmlspecialchars($dataDonateYear, ENT_QUOTES, 'UTF-8') ?>"
                        data-donate-week="<?= htmlspecialchars($dataDonateWeek, ENT_QUOTES, 'UTF-8') ?>"
                        data-amount="<?= htmlspecialchars((string)$rowAmount, ENT_QUOTES, 'UTF-8') ?>"
                        data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= htmlspecialchars($dtLabel) ?></td>
                        <td class="fd-receipt-ref"><?= htmlspecialchars($receiptRef) ?></td>
                        <td><?= htmlspecialchars($fullName) ?></td>
                        <td class="<?= $taxDisplay === '' ? 'b--muted' : '' ?>"><?= htmlspecialchars($taxDisplay !== '' ? $taxDisplay : 'ยังไม่ระบุ') ?></td>
                        <td><?= htmlspecialchars($channel) ?></td>
                        <td><?= htmlspecialchars($targetCell) ?></td>
                        <td><?= htmlspecialchars($planLabel) ?></td>
                        <td class="admin-dir-num"><?= number_format($rowAmount, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr id="foundationDashboardNoRows" style="display:none;">
                <td colspan="8" class="b--muted">ไม่มีข้อมูลตามเงื่อนไขที่เลือก</td>
            </tr>
            </tbody>
        </table>
    </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.FD_DONATIONS = <?= $fdDonPayloadJson ?>;
window.FD_WEEK_META = <?= $fdWeekMetaJson ?>;
window.FD_STATIC = <?= $fdStaticJson ?>;
</script>
<script src="js/foundation_dashboard_charts.js"></script>
</body>
</html>
