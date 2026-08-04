<?php
// admin_foundations_chart.php — แอดมิน: กราฟเปรียบเทียบยอดบริจาคทุกมูลนิธิ
include 'db.php';
require_once __DIR__ . '/includes/admin_foundations_donation_chart.php';
require_once __DIR__ . '/includes/vendor_assets.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$chart = admin_foundations_donation_chart_payload($conn);
$rows = $chart['rows'];
$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($chartJson)) {
    $chartJson = '{}';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>กราฟเปรียบเทียบมูลนิธิ | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/admin_foundations_chart.css?v=3">
    <?= drawdream_bootstrap_icons_link() ?>
    <?= drawdream_chartjs_tag('', true) ?>
</head>
<body class="admin-foundations-chart-page">
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-foundations-chart-head">
        <div>
            <h1 class="admin-directory-title">กราฟเปรียบเทียบยอดบริจาค</h1>
            <p class="admin-foundations-chart-sub">สัดส่วนยอดบริจาคที่โอนสำเร็จ — แยกตามมูลนิธิ</p>
        </div>
        <div class="admin-dir-actions">
            <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_foundations_overview.php">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับรายการมูลนิธิ
            </a>
        </div>
    </div>

    <div class="admin-foundations-chart-summary">
        <div class="admin-foundations-chart-summary__card">
            <span class="admin-foundations-chart-summary__label">ยอดรวมทุกมูลนิธิ</span>
            <strong><?= number_format((float)$chart['total'], 2) ?> บาท</strong>
        </div>
        <div class="admin-foundations-chart-summary__card">
            <span class="admin-foundations-chart-summary__label">จำนวนรายการ</span>
            <strong><?= number_format((int)$chart['total_count'], 0) ?> รายการ</strong>
        </div>
        <div class="admin-foundations-chart-summary__card">
            <span class="admin-foundations-chart-summary__label">มูลนิธิที่มียอด</span>
            <strong><?= (int)($chart['foundation_count'] ?? count($rows)) ?> แห่ง</strong>
        </div>
    </div>

    <section class="admin-foundations-chart-panel">
        <h2 class="admin-foundations-chart-panel__title" id="adminFoundationsPieTitle"><?= htmlspecialchars((string)$chart['pie_title']) ?></h2>
        <p class="admin-foundations-chart-panel__insight" id="adminFoundationsPieInsight"><?= htmlspecialchars((string)$chart['pie_insight']) ?></p>

        <?php if ($rows === []): ?>
            <p class="b--muted">ยังไม่มียอดบริจาคที่จัดสรรให้มูลนิธี — กราฟจะแสดงเมื่อมีรายการโอนสำเร็จ</p>
        <?php else: ?>
            <div class="admin-foundations-pie-layout">
                <div class="admin-foundations-pie-chart-box">
                    <canvas id="adminFoundationsPieChart" aria-label="กราฟวงกลมยอดบริจาคตามมูลนิธิ"></canvas>
                </div>
                <div class="admin-foundations-pie-side" id="adminFoundationsPieSide"></div>
                <div class="admin-foundations-bar-col">
                    <p class="admin-foundations-bar-col__hint">เปรียบเทียบยอดเงินกับจำนวนครั้ง (ไม่ให้ Pie ชวนเข้าใจผิด)</p>
                    <div class="admin-foundations-bar-canvas">
                        <canvas id="adminFoundationsBarChart" aria-label="กราฟแท่งเปรียบเทียบมูลนิธิ"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($rows !== []): ?>
    <section class="admin-foundations-chart-panel admin-foundations-channel-panel">
        <h2 class="admin-foundations-chart-panel__title">สัดส่วนช่องทางบริจาคต่อมูลนิธิ</h2>
        <p class="admin-foundations-chart-panel__insight">แต่ละมูลนิธิ — สัดส่วนยอดบริจาคระหว่างเด็ก / โครงการ / สิ่งของ (สีเดียวกับแดชบอร์ดมูลนิธิ)</p>
        <div class="admin-foundations-channel-legend" aria-hidden="true">
            <span class="admin-foundations-channel-legend__item"><i class="admin-foundations-channel-legend__dot admin-foundations-channel-legend__dot--child"></i> เด็ก</span>
            <span class="admin-foundations-channel-legend__item"><i class="admin-foundations-channel-legend__dot admin-foundations-channel-legend__dot--project"></i> โครงการ</span>
            <span class="admin-foundations-channel-legend__item"><i class="admin-foundations-channel-legend__dot admin-foundations-channel-legend__dot--need"></i> สิ่งของ</span>
        </div>
        <div class="admin-foundations-channel-grid" id="adminFoundationsChannelGrid"></div>
    </section>
    <?php endif; ?>

    <?php if ($rows !== []): ?>
    <div class="admin-dir-table-wrap admin-foundations-chart-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>มูลนิธิ</th>
                <th class="admin-dir-num">ยอดบริจาค (บาท)</th>
                <th class="admin-dir-num">สัดส่วน</th>
                <th class="admin-dir-num">จำนวนครั้ง</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <span class="admin-foundations-chart-dot" style="background:<?= htmlspecialchars((string)$row['color']) ?>;"></span>
                        <?= htmlspecialchars((string)$row['name']) ?>
                    </td>
                    <td class="admin-dir-num"><?= number_format((float)$row['amount'], 2) ?></td>
                    <td class="admin-dir-num"><?= number_format((float)$row['pct'], 1) ?>%</td>
                    <td class="admin-dir-num"><?= (int)$row['count'] ?></td>
                    <td>
                        <?php if ((int)$row['foundation_id'] > 0): ?>
                            <a class="admin-dir-btn admin-dir-btn--ghost admin-foundations-chart-table-btn"
                               href="admin_foundation_totals.php?foundation_id=<?= (int)$row['foundation_id'] ?>">ยอดมูลนิธิ</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($rows !== []): ?>
<script>
window.ADMIN_FOUNDATIONS_CHART = <?= $chartJson ?>;
</script>
<script src="js/admin_foundations_chart.js?v=3" defer></script>
<?php endif; ?>
</body>
</html>
