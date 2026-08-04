<?php
// admin_donations.php — แอดมิน: ประวัติการบริจาคทั้งหมด / วันนี้ + ค้นหา/กรองวันที่
declare(strict_types=1);

include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';
require_once __DIR__ . '/includes/admin_donations_filter.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$filters = admin_donations_parse_filters($_GET);
$filterSql = admin_donations_filter_sql($filters);
$donationYears = admin_donations_year_options($conn);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$baseFrom = '
FROM donation d
JOIN donate_category dc ON d.category_id = dc.category_id
LEFT JOIN donor dn ON dn.user_id = d.donor_id
LEFT JOIN `user` u ON u.user_id = d.donor_id
LEFT JOIN foundation_children fc ON fc.child_id = d.target_id
LEFT JOIN foundation_project fp ON fp.project_id = d.target_id
LEFT JOIN foundation_profile fpn ON fpn.foundation_id = d.target_id
WHERE LOWER(TRIM(COALESCE(d.payment_status, \'\'))) = \'completed\'';

$whereExtra = $filterSql['sql'];

$countSql = '
SELECT COALESCE(SUM(agg.amount), 0) AS total, COUNT(*) AS cnt
FROM (
    SELECT d.donate_id, MAX(d.amount) AS amount
    ' . $baseFrom . $whereExtra . '
    GROUP BY d.donate_id
) agg';
$stCount = $conn->prepare($countSql);
if (!$stCount) {
    die('ไม่สามารถเตรียมคำสั่ง SQL');
}
if ($filterSql['types'] !== '') {
    $stCount->bind_param($filterSql['types'], ...$filterSql['params']);
}
$stCount->execute();
$sumRow = $stCount->get_result()->fetch_assoc() ?: ['total' => 0, 'cnt' => 0];
$sumTotal = (float)($sumRow['total'] ?? 0);
$totalRows = (int)($sumRow['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = '
SELECT d.donate_id, d.amount, d.transfer_datetime, d.omise_charge_id, d.donate_type,
       d.target_id,
       dc.project_donate, dc.needitem_donate, dc.child_donate,
       dn.first_name, dn.last_name, u.email AS donor_email, dn.tax_id,
       fc.child_name, fp.project_name, fpn.foundation_name AS need_foundation_name '
    . $baseFrom . $whereExtra . '
ORDER BY d.transfer_datetime DESC, d.donate_id DESC
LIMIT ? OFFSET ?';

$stList = $conn->prepare($listSql);
if (!$stList) {
    die('ไม่สามารถเตรียมคำสั่ง SQL');
}
$listTypes = $filterSql['types'] . 'ii';
$listParams = array_merge($filterSql['params'], [$perPage, $offset]);
$stList->bind_param($listTypes, ...$listParams);
$stList->execute();
$donRows = $stList->get_result()->fetch_all(MYSQLI_ASSOC);

function admin_donation_type_label(array $row): string
{
    if (drawdream_donate_cat_label_is_active($row['project_donate'] ?? null)) {
        return 'บริจาคโครงการ';
    }
    if (drawdream_donate_cat_label_is_active($row['needitem_donate'] ?? null)) {
        return 'บริจาคเงินเพื่อสมทบทุนจัดซื้อสิ่งของ';
    }
    if (drawdream_donate_cat_label_is_active($row['child_donate'] ?? null)) {
        return 'บริจาคให้เด็ก';
    }

    return drawdream_donate_type_label_thai($row['donate_type'] ?? '');
}

function admin_donation_target_label(array $row): string
{
    if (drawdream_donate_cat_label_is_active($row['project_donate'] ?? null)) {
        $name = trim((string)($row['project_name'] ?? ''));
        return $name !== '' ? $name : ('โครงการ #' . (int)($row['target_id'] ?? 0));
    }
    if (drawdream_donate_cat_label_is_active($row['needitem_donate'] ?? null)) {
        $name = trim((string)($row['need_foundation_name'] ?? ''));
        return $name !== '' ? $name : ('มูลนิธิ #' . (int)($row['target_id'] ?? 0));
    }
    if (drawdream_donate_cat_label_is_active($row['child_donate'] ?? null)) {
        $name = trim((string)($row['child_name'] ?? ''));
        return $name !== '' ? $name : ('เด็ก #' . (int)($row['target_id'] ?? 0));
    }

    return '-';
}

function admin_donation_donor_label(array $row): string
{
    $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }
    $email = trim((string)($row['donor_email'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    return 'ผู้บริจาคไม่ระบุตัวตน';
}

$pageTitle = $filters['period_today'] ? 'ยอดบริจาควันนี้' : 'ประวัติการบริจาคทั้งหมด';
$filterLabel = $filters['label'];
$summaryLabels = admin_donations_summary_labels($filters);
$todayTabUrl = 'admin_donations.php?period=today' . ($filters['q'] !== '' ? '&q=' . rawurlencode($filters['q']) : '');
$allTabUrl = 'admin_donations.php' . ($filters['q'] !== '' ? '?q=' . rawurlencode($filters['q']) : '');
$showDateRange = $filters['date_mode'] === 'day' && !$filters['period_today'];
$showDateMonth = $filters['date_mode'] === 'month';
$showDateYear = $filters['date_mode'] === 'year';
$formDateMode = $filters['period_today'] ? 'day' : $filters['date_mode'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/admin_donations.css">
    <?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_bootstrap_icons_link(); ?>
</head>
<body class="admin-donations-page">
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <div class="admin-directory-head__inner">
            <div>
                <h1 class="admin-directory-title"><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="admin-directory-subtitle">รายการบริจาคที่โอนสำเร็จ</p>
                <span class="admin-donations-filter-chip">
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <?= htmlspecialchars($filterLabel) ?>
                </span>
            </div>
            <div class="admin-dir-actions">
                <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_dashboard.php">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับ Dashboard
                </a>
            </div>
        </div>
    </div>

    <nav class="admin-donations-segments" aria-label="มุมมองยอดบริจาค">
        <a class="admin-donations-segment admin-donations-segment--today<?= $filters['period_today'] ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($todayTabUrl) ?>">
            <i class="bi bi-calendar2-check" aria-hidden="true"></i>
            ยอดวันนี้
        </a>
        <a class="admin-donations-segment admin-donations-segment--all<?= !$filters['period_today'] && $filters['date_mode'] === 'all' && $filters['q'] === '' ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($allTabUrl) ?>">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            ประวัติทั้งหมด
        </a>
    </nav>

    <section class="admin-donations-toolbar" aria-labelledby="admin-donations-filter-heading">
        <div class="admin-donations-toolbar__head">
            <span class="admin-donations-toolbar__icon" aria-hidden="true"><i class="bi bi-funnel"></i></span>
            <div>
                <h2 class="admin-donations-toolbar__title" id="admin-donations-filter-heading">ค้นหาและกรอง</h2>
                <p class="admin-donations-toolbar__desc">ค้นหาจากชื่อ อีเมล เป้าหมาย หรือเลือกช่วงเวลาแบบรายวัน / เดือน / ปี</p>
            </div>
        </div>

        <form method="get" action="admin_donations.php" class="admin-donations-filter" id="adminDonationsFilterForm">
            <div class="admin-donations-filter__grid">
                <label class="admin-donations-filter__field">
                    <span class="admin-donations-filter__label">ค้นหา</span>
                    <span class="admin-donations-filter__control admin-donations-filter__control--search">
                        <i class="bi bi-search admin-donations-filter__control-icon" aria-hidden="true"></i>
                        <input type="search" name="q" class="admin-donations-filter__input"
                               value="<?= htmlspecialchars($filters['q']) ?>"
                               placeholder="ชื่อ อีเมล เป้าหมาย อ้างอิง Omise">
                    </span>
                </label>
                <label class="admin-donations-filter__field">
                    <span class="admin-donations-filter__label">ช่วงเวลา</span>
                    <select name="date_mode" id="adminDonDateMode" class="admin-donations-filter__select">
                        <option value="all"<?= $formDateMode === 'all' ? ' selected' : '' ?>>ทั้งหมด</option>
                        <option value="day"<?= $formDateMode === 'day' ? ' selected' : '' ?>>รายวัน / ช่วงวันที่</option>
                        <option value="month"<?= $formDateMode === 'month' ? ' selected' : '' ?>>รายเดือน</option>
                        <option value="year"<?= $formDateMode === 'year' ? ' selected' : '' ?>>รายปี</option>
                    </select>
                </label>
            </div>

            <div class="admin-donations-filter__panel" id="adminDonDateRangeWrap"<?= $showDateRange ? '' : ' hidden' ?>>
                <div class="admin-donations-filter__panel-grid">
                    <label class="admin-donations-filter__field">
                        <span class="admin-donations-filter__label">จากวันที่</span>
                        <input type="date" name="date_from" class="admin-donations-filter__input"
                               value="<?= htmlspecialchars($filters['date_from']) ?>">
                    </label>
                    <label class="admin-donations-filter__field">
                        <span class="admin-donations-filter__label">ถึงวันที่</span>
                        <input type="date" name="date_to" class="admin-donations-filter__input"
                               value="<?= htmlspecialchars($filters['date_to']) ?>"
                               title="เว้นว่าง = วันเดียวกับ «จากวันที่»">
                    </label>
                    <p class="admin-donations-filter__hint">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        เว้น «ถึงวันที่» = กรองเฉพาะวัน «จาก»
                    </p>
                </div>
            </div>

            <div class="admin-donations-filter__panel" id="adminDonDateMonthWrap"<?= $showDateMonth ? '' : ' hidden' ?>>
                <label class="admin-donations-filter__field">
                    <span class="admin-donations-filter__label">เดือน</span>
                    <input type="month" name="date_month" class="admin-donations-filter__input"
                           value="<?= htmlspecialchars($filters['date_month']) ?>">
                </label>
            </div>

            <div class="admin-donations-filter__panel" id="adminDonDateYearWrap"<?= $showDateYear ? '' : ' hidden' ?>>
                <label class="admin-donations-filter__field">
                    <span class="admin-donations-filter__label">ปี</span>
                    <select name="date_year" class="admin-donations-filter__select">
                        <option value="">— เลือกปี —</option>
                        <?php foreach ($donationYears as $y): ?>
                            <option value="<?= (int)$y ?>"<?= (string)$filters['date_year'] === (string)$y ? ' selected' : '' ?>>
                                <?= (int)$y + 543 ?> (<?= (int)$y ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="admin-donations-filter__actions">
                <button type="submit" class="admin-dir-btn admin-dir-btn--primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> กรอง
                </button>
                <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_donations.php">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> ล้างตัวกรอง
                </a>
            </div>
        </form>
    </section>

    <div class="admin-donations-summary" aria-live="polite" aria-atomic="true">
        <div class="admin-donations-summary__card admin-donations-summary__card--amount">
            <span class="admin-donations-summary__label"><?= htmlspecialchars($summaryLabels['amount']) ?></span>
            <div class="admin-donations-summary__value">
                <?= number_format($sumTotal, 2) ?>
                <span class="admin-donations-summary__unit">บาท</span>
            </div>
        </div>
        <div class="admin-donations-summary__card admin-donations-summary__card--count">
            <span class="admin-donations-summary__label"><?= htmlspecialchars($summaryLabels['count']) ?></span>
            <div class="admin-donations-summary__value">
                <?= number_format($totalRows, 0) ?>
                <span class="admin-donations-summary__unit">รายการ</span>
            </div>
        </div>
        <p class="admin-donations-summary__note"><?= htmlspecialchars($summaryLabels['note']) ?></p>
    </div>

    <section class="admin-donations-table-section" aria-labelledby="admin-donations-table-heading">
        <div class="admin-donations-table-section__head">
            <h2 class="admin-donations-table-section__title" id="admin-donations-table-heading">รายการบริจาค</h2>
            <span class="admin-donations-table-section__scroll-hint">เลื่อนซ้าย–ขวาเพื่อดูคอลัมน์เพิ่มเติม</span>
        </div>
        <div class="admin-dir-table-wrap admin-dir-table-wrap--donations">
        <table class="admin-dir-table admin-dir-table--donations">
            <thead>
            <tr>
                <th>เวลาโอน</th>
                <th>ผู้บริจาค</th>
                <th>ประเภท</th>
                <th>เป้าหมาย</th>
                <th class="admin-dir-num">จำนวนเงิน (บาท)</th>
                <th>อ้างอิง</th>
                <th>เลขผู้เสียภาษี</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($donRows === []): ?>
                <tr><td colspan="7" class="b--muted">ไม่พบรายการบริจาคตามเงื่อนไขที่เลือก</td></tr>
            <?php else: ?>
                <?php foreach ($donRows as $row):
                    $dtRaw = trim((string)($row['transfer_datetime'] ?? ''));
                    $dtLabel = $dtRaw !== '' ? date('d/m/Y H:i:s', strtotime($dtRaw)) : '-';
                    $chargeId = trim((string)($row['omise_charge_id'] ?? ''));
                    $taxId = trim((string)($row['tax_id'] ?? ''));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($dtLabel) ?></td>
                        <td><?= htmlspecialchars(admin_donation_donor_label($row)) ?></td>
                        <td><?= htmlspecialchars(admin_donation_type_label($row)) ?></td>
                        <td><?= htmlspecialchars(admin_donation_target_label($row)) ?></td>
                        <td class="admin-dir-num"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars($chargeId !== '' ? $chargeId : '-') ?></td>
                        <td><?= htmlspecialchars($taxId !== '' ? $taxId : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <?php if ($totalPages > 1): ?>
        <div class="admin-dir-pagination">
            <?php if ($page > 1): ?>
                <a class="admin-dir-btn admin-dir-btn--ghost"
                   href="<?= htmlspecialchars(admin_donations_page_url($filters, $page - 1)) ?>">← ก่อนหน้า</a>
            <?php endif; ?>
            <span class="admin-dir-pagination__label">หน้า <?= (int)$page ?> / <?= (int)$totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="admin-dir-btn admin-dir-btn--ghost"
                   href="<?= htmlspecialchars(admin_donations_page_url($filters, $page + 1)) ?>">ถัดไป →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var mode = document.getElementById('adminDonDateMode');
    var rangeWrap = document.getElementById('adminDonDateRangeWrap');
    var monthWrap = document.getElementById('adminDonDateMonthWrap');
    var yearWrap = document.getElementById('adminDonDateYearWrap');
    if (!mode) return;

    function syncDateFields() {
        var v = mode.value;
        if (rangeWrap) rangeWrap.hidden = v !== 'day';
        if (monthWrap) monthWrap.hidden = v !== 'month';
        if (yearWrap) yearWrap.hidden = v !== 'year';
    }

    mode.addEventListener('change', syncDateFields);
    syncDateFields();
})();
</script>
</body>
</html>
