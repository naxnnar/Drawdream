<?php
// admin_donors.php — ภาพรวมผู้บริจาค
// รายการผู้บริจาค — มุมมองแอดมิน (ยอดสะสม, ความถี่, ช่องทางติดต่อ)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน donors

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

/** @param array<string,bool> $donorCols */
function admin_donors_sum_completed_by_category(mysqli $conn, int $categoryId): float
{
    if ($categoryId <= 0) {
        return 0.0;
    }
    $st = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS t FROM donation WHERE payment_status = ? AND category_id = ?');
    if (!$st) {
        return 0.0;
    }
    $ps = 'completed';
    $st->bind_param('si', $ps, $categoryId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();

    return (float)($r['t'] ?? 0);
}

function admin_donors_initials(string $first, string $last): string
{
    $pick = static function (string $s): string {
        $t = trim($s);
        if ($t === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($t, 0, 1);
        }

        return substr($t, 0, 1);
    };
    $a = $pick($first);
    $b = $pick($last);
    $s = $a . $b;
    if ($s === '') {
        return '?';
    }

    return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s);
}

$donorCols = [];
$cr = @$conn->query('SHOW COLUMNS FROM donor');
if ($cr) {
    while ($c = $cr->fetch_assoc()) {
        $donorCols[(string)($c['Field'] ?? '')] = true;
    }
}
$selPhone = isset($donorCols['phone']) ? 'd.phone' : "NULL AS phone";
$selTax = isset($donorCols['tax_id']) ? 'd.tax_id' : "NULL AS tax_id";
$selProfImgSelect = isset($donorCols['profile_image']) ? 'd.profile_image' : "''";
$groupProf = isset($donorCols['profile_image']) ? ', d.profile_image' : '';

$childCatId = drawdream_get_or_create_child_donate_category_id($conn);
$projectCatId = drawdream_get_or_create_project_donate_category_id($conn);
$needCatId = drawdream_get_or_create_needitem_donate_category_id($conn);

$sumChild = admin_donors_sum_completed_by_category($conn, $childCatId);
$sumProject = admin_donors_sum_completed_by_category($conn, $projectCatId);
$sumNeed = admin_donors_sum_completed_by_category($conn, $needCatId);
$maxFeature = max($sumChild, $sumProject, $sumNeed, 1.0);
$hChild = (int)round(($sumChild / $maxFeature) * 100);
$hProject = (int)round(($sumProject / $maxFeature) * 100);
$hNeed = (int)round(($sumNeed / $maxFeature) * 100);
$yMax = (int)ceil($maxFeature);
$yTicks = [
    0,
    (int)round($yMax * 0.25),
    (int)round($yMax * 0.5),
    (int)round($yMax * 0.75),
    $yMax,
];

$sqlTop = "
SELECT d.user_id,
       d.first_name,
       d.last_name,
       {$selProfImgSelect} AS profile_image,
       u.email,
       COALESCE(SUM(dn.amount), 0) AS total_given
FROM donor d
JOIN `user` u ON d.user_id = u.user_id
LEFT JOIN donation dn ON dn.donor_id = d.user_id AND dn.payment_status = 'completed'
GROUP BY d.user_id, d.first_name, d.last_name, u.email{$groupProf}
ORDER BY total_given DESC
LIMIT 3
";
$topDonors = [];
$qTop = $conn->query($sqlTop);
if ($qTop) {
    while ($tr = $qTop->fetch_assoc()) {
        $topDonors[] = $tr;
    }
}
while (count($topDonors) < 3) {
    $topDonors[] = null;
}

$sql = "
SELECT d.user_id,
       d.first_name,
       d.last_name,
       u.email,
       {$selPhone},
       {$selTax},
       COALESCE((
           SELECT SUM(amount) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ), 0) AS total_given,
       COALESCE((
           SELECT COUNT(*) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ), 0) AS donation_count,
       (
           SELECT MAX(transfer_datetime) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ) AS last_donation
FROM donor d
JOIN `user` u ON d.user_id = u.user_id
ORDER BY (last_donation IS NULL), last_donation DESC, d.user_id DESC
";
$rows = $conn->query($sql);

$rankClass = ['admin-top-donor--1', 'admin-top-donor--2', 'admin-top-donor--3'];
$rankLabel = ['1st', '2nd', '3rd'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผู้บริจาคทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/admin_donors_insights.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">ผู้บริจาคทั้งหมด</h1>
    </div>

    <div class="admin-donors-insights">
        <section class="admin-insight-card" aria-labelledby="compare-chart-title">
            <div class="admin-insight-card__head">
                <h2 id="compare-chart-title" class="admin-insight-card__title">เปรียบเทียบยอดบริจาคตามฟีเจอร์</h2>
                <span class="admin-insight-card__hint">ยอดรวม (บาท)</span>
            </div>
            <div class="admin-compare-chart">
                <div class="admin-compare-legend">
                    <div>
                        <span class="admin-compare-legend__row">
                            <span class="admin-compare-legend__dot admin-compare-legend__dot--child"></span>
                            เด็ก
                        </span>
                        <span class="admin-compare-legend__sub">ยอดบริจาคโปรไฟล์เด็ก</span>
                    </div>
                    <div>
                        <span class="admin-compare-legend__row">
                            <span class="admin-compare-legend__dot admin-compare-legend__dot--project"></span>
                            โครงการ
                        </span>
                        <span class="admin-compare-legend__sub">ยอดบริจาคโครงการ</span>
                    </div>
                    <div>
                        <span class="admin-compare-legend__row">
                            <span class="admin-compare-legend__dot admin-compare-legend__dot--need"></span>
                            สิ่งของ
                        </span>
                        <span class="admin-compare-legend__sub">ยอดบริจาครายการสิ่งของ</span>
                    </div>
                </div>
                <div class="admin-compare-bars-area">
                    <div class="admin-compare-y" aria-hidden="true">
                        <?php foreach (array_reverse($yTicks) as $yt): ?>
                            <span><?= number_format((int)$yt, 0, '', ',') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-compare-bars">
                        <div class="admin-compare-bar-wrap">
                            <div class="admin-compare-bar-val"><?= number_format($sumChild, 0, '.', ',') ?> บ.</div>
                            <div class="admin-compare-bar admin-compare-bar--child" style="height: <?= max(4, $hChild) ?>%;" title="เด็ก: <?= number_format($sumChild, 0, '.', ',') ?> บาท"></div>
                        </div>
                        <div class="admin-compare-bar-wrap">
                            <div class="admin-compare-bar-val"><?= number_format($sumProject, 0, '.', ',') ?> บ.</div>
                            <div class="admin-compare-bar admin-compare-bar--project" style="height: <?= max(4, $hProject) ?>%;" title="โครงการ: <?= number_format($sumProject, 0, '.', ',') ?> บาท"></div>
                        </div>
                        <div class="admin-compare-bar-wrap">
                            <div class="admin-compare-bar-val"><?= number_format($sumNeed, 0, '.', ',') ?> บ.</div>
                            <div class="admin-compare-bar admin-compare-bar--need" style="height: <?= max(4, $hNeed) ?>%;" title="สิ่งของ: <?= number_format($sumNeed, 0, '.', ',') ?> บาท"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-insight-card" aria-labelledby="top-donors-title">
            <div class="admin-insight-card__head">
                <h2 id="top-donors-title" class="admin-insight-card__title">Top 3 ผู้บริจาค</h2>
                <span class="admin-insight-card__hint">รวมทุกฟีเจอร์</span>
            </div>
            <div class="admin-top-donors">
                <?php for ($ti = 0; $ti < 3; $ti++): ?>
                    <?php
                    $td = $topDonors[$ti] ?? null;
                    $rc = $rankClass[$ti] ?? 'admin-top-donor--1';
                    $rl = $rankLabel[$ti] ?? '';
                    ?>
                    <div class="admin-top-donor <?= htmlspecialchars($rc, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (is_array($td)): ?>
                            <?php
                            $tname = trim((string)($td['first_name'] ?? '') . ' ' . (string)($td['last_name'] ?? ''));
                            if ($tname === '') {
                                $tname = '—';
                            }
                            $tamt = (float)($td['total_given'] ?? 0);
                            $pimg = trim((string)($td['profile_image'] ?? ''));
                            ?>
                            <?php if ($pimg !== ''): ?>
                                <img class="admin-top-donor__avatar" src="uploads/profiles/<?= htmlspecialchars($pimg, ENT_QUOTES, 'UTF-8') ?>" alt="">
                            <?php else: ?>
                                <div class="admin-top-donor__avatar-ph" aria-hidden="true"><?= htmlspecialchars(admin_donors_initials((string)($td['first_name'] ?? ''), (string)($td['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="admin-top-donor__name"><?= htmlspecialchars($tname, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="admin-top-donor__amt"><?= number_format($tamt, 0, '.', ',') ?> <small>บาท</small></div>
                        <?php else: ?>
                            <div class="admin-top-donor__avatar-ph">—</div>
                            <div class="admin-top-donor__name">ว่าง</div>
                            <div class="admin-top-donor__amt">—</div>
                        <?php endif; ?>
                        <span class="admin-top-donor__badge"><?= htmlspecialchars($rl, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>ชื่อ</th>
                <th>อีเมล</th>
                <th>โทรศัพท์</th>
                <th class="admin-dir-num">ยอดสะสม (บาท)</th>
                <th class="admin-dir-num">ครั้ง</th>
                <th>บริจาคล่าสุด</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                    if ($name === '') {
                        $name = '—';
                    }
                    $email = trim((string)($r['email'] ?? ''));
                    $phone = trim((string)($r['phone'] ?? ''));
                    if ($phone === '') {
                        $phone = '—';
                    }
                    $total = (float)($r['total_given'] ?? 0);
                    $cnt = (int)($r['donation_count'] ?? 0);
                    $last = $r['last_donation'] ?? null;
                    $lastStr = $last ? date('d/m/Y H:i', strtotime((string)$last)) : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($email) ?></td>
                        <td><?= htmlspecialchars($phone) ?></td>
                        <td class="admin-dir-num"><?= number_format($total, 0) ?></td>
                        <td class="admin-dir-num"><?= number_format($cnt, 0) ?></td>
                        <td><?= htmlspecialchars($lastStr) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="b--muted">ยังไม่มีข้อมูลผู้บริจาค</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
