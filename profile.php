<?php
// profile.php — โปรไฟล์ผู้ใช้และประวัติบริจาค

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// ======== ฟังก์ชันเช็คโครงการสำเร็จ ========
function checkCompletedProjects($conn) {
    // เช็คโครงการที่ครบเป้าหมาย
    $conn->query("
        UPDATE foundation_project 
        SET project_status = 'completed'
        WHERE project_status = 'approved'
        AND current_donate >= goal_amount
        AND goal_amount > 0
        AND deleted_at IS NULL
    ");

    // เช็คโครงการที่หมดเวลา
    $conn->query("
        UPDATE foundation_project 
        SET project_status = 'completed'
        WHERE project_status = 'approved'
        AND end_date < CURDATE()
        AND deleted_at IS NULL
    ");
}

// เรียกเช็คทุกครั้งที่โหลดหน้า
checkCompletedProjects($conn);

if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp 
                           JOIN `user` u ON fp.user_id = u.user_id 
                           WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

    $foundationName = trim((string)($profile['foundation_name'] ?? ''));
    $foundationId   = (int)($profile['foundation_id'] ?? 0);

    // --- สรุปยอดบริจาคตามหมวด (เด็ก / โครงการ / สิ่งของ) ---
    $finance_child_total  = 0.0;
    $finance_project_total = 0.0;
    $finance_need_total   = 0.0;
    $foundation_finance_rows = [];

    $has_child_donations_tbl = (bool)($conn->query("SHOW TABLES LIKE 'child_donations'")->num_rows);

    if ($foundationId > 0) {
        $nq = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS t FROM foundation_needlist WHERE foundation_id = ?");
        $nq->bind_param("i", $foundationId);
        $nq->execute();
        $finance_need_total = (float)($nq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    if ($foundationName !== '') {
        $pq = $conn->prepare("
            SELECT COALESCE(SUM(d.amount), 0) AS t
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            INNER JOIN foundation_project p ON p.project_id = d.target_id AND p.foundation_name = ?
            WHERE d.payment_status = 'completed'
        ");
        $pq->bind_param("s", $foundationName);
        $pq->execute();
        $finance_project_total = (float)($pq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    if ($has_child_donations_tbl && $foundationId > 0) {
        $cq = $conn->prepare("
            SELECT COALESCE(SUM(cd.amount), 0) AS t
            FROM child_donations cd
            INNER JOIN foundation_children fc ON fc.child_id = cd.child_id AND fc.foundation_id = ?
        ");
        $cq->bind_param("i", $foundationId);
        $cq->execute();
        $finance_child_total = (float)($cq->get_result()->fetch_assoc()['t'] ?? 0);
    }

    $finance_grand_total = $finance_child_total + $finance_project_total + $finance_need_total;

    if ($foundationName !== '') {
        $lr = $conn->prepare("
            SELECT d.transfer_datetime AS ts, d.amount, 'project' AS cat_key, p.project_name AS title
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
            INNER JOIN foundation_project p ON p.project_id = d.target_id AND p.foundation_name = ?
            WHERE d.payment_status = 'completed'
            ORDER BY d.transfer_datetime DESC
            LIMIT 80
        ");
        $lr->bind_param("s", $foundationName);
        $lr->execute();
        foreach ($lr->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $foundation_finance_rows[] = $row;
        }
    }

    if ($has_child_donations_tbl && $foundationId > 0) {
        $lr2 = $conn->prepare("
            SELECT cd.donated_at AS ts, cd.amount, 'child' AS cat_key, fc.child_name AS title
            FROM child_donations cd
            INNER JOIN foundation_children fc ON fc.child_id = cd.child_id AND fc.foundation_id = ?
            ORDER BY cd.donated_at DESC
            LIMIT 80
        ");
        $lr2->bind_param("i", $foundationId);
        $lr2->execute();
        foreach ($lr2->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $foundation_finance_rows[] = $row;
        }
    }

    usort($foundation_finance_rows, static function ($a, $b) {
        return strtotime((string)$b['ts']) <=> strtotime((string)$a['ts']);
    });
    $foundation_finance_rows = array_slice($foundation_finance_rows, 0, 50);

} elseif ($role === 'donor') {
    $donor_active_child_subscriptions = [];
    $stmt = $conn->prepare("SELECT d.*, u.email FROM donor d 
                           JOIN `user` u ON d.user_id = u.user_id 
                           WHERE d.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

    $stmt_don = $conn->prepare("
        SELECT 
            d.donate_id,
            d.amount,
            d.payment_status,
            d.transfer_datetime,
            dc.project_donate,
            dc.needitem_donate,
            dc.child_donate,
            (SELECT pt2.omise_charge_id FROM payment_transaction pt2
             WHERE pt2.donate_id = d.donate_id
             ORDER BY pt2.log_id DESC LIMIT 1) AS omise_charge_id,
            fc.child_name AS child_name_by_target,
            p.project_name AS project_name_by_target,
            fp.foundation_name AS foundation_name_by_target
        FROM donation d
        INNER JOIN donate_category dc ON d.category_id = dc.category_id
        LEFT JOIN foundation_children fc
            ON fc.child_id = d.target_id AND fc.deleted_at IS NULL
        LEFT JOIN foundation_project p
            ON p.project_id = d.target_id AND p.deleted_at IS NULL
        LEFT JOIN foundation_profile fp
            ON fp.foundation_id = d.target_id
        WHERE d.donor_id = ? AND LOWER(TRIM(d.payment_status)) = 'completed'
        ORDER BY d.transfer_datetime DESC
        LIMIT 200
    ");
    $stmt_don->bind_param('i', $user_id);
    $stmt_don->execute();
    $donation_history = $stmt_don->get_result()->fetch_all(MYSQLI_ASSOC);

    // รายการที่บันทึกเฉพาะ child_donations (งวด Omise เก่า / webhook ยังไม่สร้างแถว donation) — แสดงในประวัติเมื่อไม่มีคู่ใน donation
    if ((bool)($conn->query("SHOW TABLES LIKE 'child_donations'")->num_rows)) {
        $stCd = $conn->prepare(
            'SELECT cd.amount, cd.donated_at, fc.child_name
             FROM child_donations cd
             INNER JOIN foundation_children fc ON fc.child_id = cd.child_id AND fc.deleted_at IS NULL
             WHERE cd.donor_user_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM donation d
                   WHERE d.donor_id = cd.donor_user_id
                     AND d.target_id = cd.child_id
                     AND LOWER(TRIM(COALESCE(d.payment_status, \'\'))) = \'completed\'
                     AND ABS(d.amount - cd.amount) < 0.00001
                     AND d.transfer_datetime BETWEEN cd.donated_at - INTERVAL 10 MINUTE AND cd.donated_at + INTERVAL 10 MINUTE
               )
             ORDER BY cd.donated_at DESC
             LIMIT 150'
        );
        if ($stCd) {
            $stCd->bind_param('i', $user_id);
            $stCd->execute();
            foreach ($stCd->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
                $donation_history[] = [
                    'donate_id' => 0,
                    'amount' => (float)($cr['amount'] ?? 0),
                    'payment_status' => 'completed',
                    'transfer_datetime' => $cr['donated_at'],
                    'project_donate' => '-',
                    'needitem_donate' => '-',
                    'child_donate' => 'อุปการะเด็ก',
                    'omise_charge_id' => '',
                    'child_name_by_target' => trim((string)($cr['child_name'] ?? '')),
                    'project_name_by_target' => '',
                    'foundation_name_by_target' => '',
                ];
            }
        }
    }

    usort(
        $donation_history,
        static function ($a, $b) {
            return strtotime((string)($b['transfer_datetime'])) <=> strtotime((string)($a['transfer_datetime']));
        }
    );
    $donation_history = array_slice($donation_history, 0, 200);

    if ((bool)($conn->query("SHOW TABLES LIKE 'child_omise_subscription'")->num_rows)) {
        $stSub = $conn->prepare(
            'SELECT cos.plan_code, cos.amount_thb, fc.child_name
             FROM child_omise_subscription cos
             INNER JOIN foundation_children fc ON fc.child_id = cos.child_id AND fc.deleted_at IS NULL
             WHERE cos.donor_user_id = ? AND cos.status = ?
             ORDER BY fc.child_name ASC'
        );
        if ($stSub) {
            $active = 'active';
            $stSub->bind_param('is', $user_id, $active);
            $stSub->execute();
            $donor_active_child_subscriptions = $stSub->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

} elseif ($role === 'admin') {
    $stmt = $conn->prepare("SELECT email FROM `user` WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $profile = [
        'email'      => $user_data['email'] ?? '',
        'first_name' => 'Admin',
        'last_name'  => 'System'
    ];

    $stmt3 = $conn->prepare("
        SELECT a.*, 
               nl.item_name, nl.item_desc,
               nl.qty_needed AS quantity_required,
               nl.price_estimate AS item_price,
               nl.item_image AS photo_item,
               nl.foundation_id,
               p.project_name, p.project_desc,
               fp.foundation_name,
               fp_audit.foundation_name AS audit_foundation_name
        FROM admin a
        LEFT JOIN foundation_needlist nl ON a.target_entity = 'need' AND a.target_id = nl.item_id
        LEFT JOIN foundation_project p ON a.target_entity = 'project' AND a.target_id = p.project_id
        LEFT JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
        LEFT JOIN foundation_profile fp_audit ON a.target_entity = 'foundation' AND a.target_id = fp_audit.foundation_id
        WHERE a.admin_id = ?
        ORDER BY a.action_at DESC LIMIT 50
    ");
    $stmt3->bind_param("i", $user_id);
    $stmt3->execute();
    $logs = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    die("Role ไม่รองรับ");
}

if (!$profile) die("ไม่พบข้อมูลโปรไฟล์");

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/profile.css?v=12">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-container">
    <div class="profile-header <?= ($role === 'donor' || $role === 'foundation') ? 'profile-header--donor' : '' ?>">

        <?php if ($role === 'foundation'): ?>
            <div class="profile-image-placeholder profile-image-placeholder--donor">
                <?php if (!empty($profile['foundation_image'])): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($profile['foundation_image']) ?>" alt="รูปโปรไฟล์">
                <?php else: ?>
                    <img src="img/newfoundation.jpg" alt="รูปโปรไฟล์มูลนิธิ">
                <?php endif; ?>
            </div>
            <div class="profile-info profile-info--donor">
                <h1><?= htmlspecialchars($profile['foundation_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
                <?php if (!empty($profile['phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">โทรศัพท์:</span>
                        <?= htmlspecialchars($profile['phone']) ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($role === 'admin'): ?>
            <div class="profile-image-placeholder">
                <img src="img/user.png" alt="รูปโปรไฟล์แอดมิน">
            </div>
            <div class="profile-info profile-info--donor">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
                <p class="badge-admin">ผู้ดูแลระบบ</p>
            </div>

        <?php else: ?>
            <div class="profile-image-placeholder profile-image-placeholder--donor">
                <?php if (!empty($profile['profile_image'])): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" alt="รูปโปรไฟล์">
                <?php else: ?>
                    <img src="img/donor-avatar-placeholder.svg" alt="รูปโปรไฟล์ผู้บริจาคเริ่มต้น" class="profile-image-default">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
                <?php if (!empty($profile['phone'])): ?>
                    <div class="info-row">
                        <span class="info-label">โทรศัพท์:</span>
                        <?= htmlspecialchars($profile['phone']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($profile['tax_id'])): ?>
                    <div class="info-row">
                        <span class="info-label">เลขประจำตัวผู้เสียภาษี:</span>
                        <?= htmlspecialchars($profile['tax_id']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($role === 'foundation'): ?>
        <?php if (!empty($profile['account_verified']) && empty($profile['bank_account_number'])): ?>
            <div class="alert-bank alert-bank--foundation">
                บัญชีของคุณได้รับการยืนยันแล้ว กรุณาเพิ่มข้อมูลบัญชีธนาคารในหน้าแก้ไขโปรไฟล์เพื่อรับการโอนเงินบริจาค
            </div>
        <?php endif; ?>

        <div class="donor-menu foundation-donor-menu">
            <a href="update_profile.php" class="profile-menu-btn profile-menu-btn--edit">
                <span class="profile-menu-icon"><i class="bi bi-person-fill"></i></span>
                <span class="profile-menu-label">แก้ไขโปรไฟล์</span>
                <span class="profile-menu-arrow">›</span>
            </a>
            <button type="button" class="profile-menu-btn profile-menu-btn--history" id="openFoundationFinance">
                <span class="profile-menu-icon"><i class="bi bi-cash-stack"></i></span>
                <span class="profile-menu-label">ยอดบริจาค</span>
                <span class="profile-menu-arrow">›</span>
            </button>
        </div>

        <?php
        $foundation_cat_labels = [
            'child'   => ['label' => 'เด็ก', 'short' => 'เด็ก'],
            'project' => ['label' => 'โครงการ', 'short' => 'โครงการ'],
            'need'    => ['label' => 'สิ่งของ', 'short' => 'สิ่งของ'],
        ];
        ?>
        <div class="logs-section donor-history-panel foundation-projects-panel foundation-finance-panel" id="foundationFinancePanel" hidden>
            <h2>ยอดบริจาค</h2>
            <p class="foundation-finance-lead">สรุปตามช่องทางบริจาค (เด็ก / โครงการ / สิ่งของ) และรายการล่าสุดที่ระบบบันทึกได้</p>

            <div class="foundation-finance-summary">
                <div class="foundation-finance-card foundation-finance-card--child">
                    <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['child']['label']) ?></span>
                    <span class="foundation-finance-card__amount"><?= number_format($finance_child_total, 2) ?> <small>บาท</small></span>
                </div>
                <div class="foundation-finance-card foundation-finance-card--project">
                    <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['project']['label']) ?></span>
                    <span class="foundation-finance-card__amount"><?= number_format($finance_project_total, 2) ?> <small>บาท</small></span>
                </div>
                <div class="foundation-finance-card foundation-finance-card--need">
                    <span class="foundation-finance-card__cat"><?= htmlspecialchars($foundation_cat_labels['need']['label']) ?></span>
                    <span class="foundation-finance-card__amount"><?= number_format($finance_need_total, 2) ?> <small>บาท</small></span>
                </div>
            </div>
            <div class="foundation-finance-total-row">
                รวมทั้งหมด <strong><?= number_format($finance_grand_total, 2) ?> บาท</strong>
            </div>
            <?php if ($finance_need_total > 0): ?>
                <p class="foundation-finance-note">หมายเหตุ: ยอด &ldquo;สิ่งของ&rdquo; เป็นยอดสะสมที่กระจายเข้ารายการสิ่งของที่อนุมัติแล้วของมูลนิธิคุณ (ไม่แสดงเป็นทีละรายการด้านล่าง)</p>
            <?php endif; ?>

            <h3 class="foundation-finance-subhead">รายการล่าสุด</h3>
            <?php if (!empty($foundation_finance_rows)): ?>
                <div class="foundation-finance-list">
                    <?php foreach ($foundation_finance_rows as $fr): ?>
                        <?php
                            $ck = $fr['cat_key'] ?? 'project';
                            $meta = $foundation_cat_labels[$ck] ?? $foundation_cat_labels['project'];
                            $ts = $fr['ts'] ?? '';
                            $title = trim((string)($fr['title'] ?? ''));
                            if ($title === '') {
                                $title = '—';
                            }
                        ?>
                        <div class="foundation-finance-row">
                            <span class="foundation-finance-badge foundation-finance-badge--<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($meta['short']) ?></span>
                            <div class="foundation-finance-row__body">
                                <div class="foundation-finance-row__title"><?= htmlspecialchars($title) ?></div>
                                <div class="foundation-finance-row__time"><?= $ts ? date('d/m/Y H:i', strtotime((string)$ts)) : '—' ?></div>
                            </div>
                            <span class="foundation-finance-row__amount"><?= number_format((float)($fr['amount'] ?? 0), 2) ?> ฿</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="foundation-empty-projects foundation-finance-empty">
                    <?php if ($finance_grand_total > 0): ?>
                        ยังไม่มีรายการแยกรายครั้ง (เช่น บริจาคเฉพาะสิ่งของจะแสดงเฉพาะในช่องสรุปด้านบน)
                    <?php else: ?>
                        ยังไม่มียอดบริจาคที่บันทึกในระบบ
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($role === 'donor'): ?>
        <?php
            $total_donated = array_sum(array_column($donation_history, 'amount'));
            $don_count = count($donation_history);
            $years = [];
            foreach ($donation_history as $d) {
                $years[date('Y', strtotime((string)$d['transfer_datetime']))] = true;
            }
            $year_options = array_keys($years);
            rsort($year_options);
        ?>
        <div class="donor-menu">
            <a href="donor_update_profile.php" class="profile-menu-btn profile-menu-btn--edit">
                <span class="profile-menu-icon"><i class="bi bi-person-fill"></i></span>
                <span class="profile-menu-label">แก้ไขโปรไฟล์</span>
                <span class="profile-menu-arrow">›</span>
            </a>
            <button type="button" class="profile-menu-btn profile-menu-btn--history" id="openDonationHistory">
                <span class="profile-menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                <span class="profile-menu-label">ประวัติการบริจาค</span>
                <span class="profile-menu-arrow">›</span>
            </button>
        </div>

        <div class="logs-section donor-history-panel" id="donationHistoryPanel" hidden>
            <h2>ประวัติการบริจาค</h2>
            <?php if (!empty($donor_active_child_subscriptions)): ?>
            <div class="donor-sponsorship-active-banner" role="region" aria-label="เด็กที่กำลังอุปการะ">
                <div class="donor-sponsorship-active-banner__title"><i class="bi bi-heart-fill" aria-hidden="true"></i> กำลังอุปการะเด็ก</div>
                <ul class="donor-sponsorship-active-banner__list">
                    <?php foreach ($donor_active_child_subscriptions as $sub): ?>
                        <?php
                        $pl = strtolower(trim((string)($sub['plan_code'] ?? '')));
                        $planTh = match ($pl) {
                            'monthly' => 'รายเดือน',
                            'semiannual' => 'ราย 6 เดือน',
                            'yearly' => 'รายปี',
                            default => $pl !== '' ? $pl : 'รายงวด',
                        };
                        ?>
                        <li>
                            <strong><?= htmlspecialchars((string)($sub['child_name'] ?? '')) ?></strong>
                            <span class="donor-sponsorship-active-banner__meta"> · <?= htmlspecialchars($planTh) ?> <?= number_format((float)($sub['amount_thb'] ?? 0), 0) ?> บาท/งวด</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($donation_history)): ?>
                <div class="donor-summary-head">
                    <label class="donor-year-filter-wrap" for="donation-year-filter">
                        <select id="donation-year-filter" class="donor-year-filter">
                            <option value="all">ทุกปี</option>
                            <?php foreach ($year_options as $yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="donation-summary">
                    บริจาคทั้งหมด <strong><?= number_format($total_donated, 2) ?> บาท</strong> จาก <?= $don_count ?> รายการ
                </div>
                <?php foreach ($donation_history as $idx => $don): ?>
                    <?php $is_extra = $idx >= 3; $yr = date('Y', strtotime((string)$don['transfer_datetime'])); ?>
                    <div class="log-item log-item--donation<?= $is_extra ? ' log-item--extra' : '' ?>" data-year="<?= htmlspecialchars($yr) ?>"<?= $is_extra ? ' hidden' : '' ?>>
                        <div class="log-action">
                            <?php
                            $histChild = trim((string)($don['child_name_by_target'] ?? ''));
                            $histProject = trim((string)($don['project_name_by_target'] ?? ''));
                            $histFoundation = trim((string)($don['foundation_name_by_target'] ?? ''));
                            $histCatChild = drawdream_donate_cat_label_is_active($don['child_donate'] ?? null);
                            $histCatProject = drawdream_donate_cat_label_is_active($don['project_donate'] ?? null);
                            $histCatNeed = drawdream_donate_cat_label_is_active($don['needitem_donate'] ?? null);
                            ?>
                            <?php if ($histCatChild && $histChild !== ''): ?>
                                อุปการะเด็ก — <?= htmlspecialchars($histChild) ?>
                            <?php elseif ($histCatProject && $histProject !== ''): ?>
                                บริจาคให้โครงการ — <?= htmlspecialchars($histProject) ?>
                            <?php elseif ($histCatNeed && $histFoundation !== ''): ?>
                                บริจาครายการสิ่งของ — <?= htmlspecialchars($histFoundation) ?>
                            <?php elseif ($histChild !== ''): ?>
                                <?php /* category_id ผิดแต่ target_id ชี้เด็กจริง (เช่น QR/งวด Omise) */ ?>
                                อุปการะเด็ก — <?= htmlspecialchars($histChild) ?>
                            <?php elseif ($histProject !== ''): ?>
                                บริจาคให้โครงการ — <?= htmlspecialchars($histProject) ?>
                            <?php elseif ($histFoundation !== ''): ?>
                                บริจาคมูลนิธิ (สิ่งของ) — <?= htmlspecialchars($histFoundation) ?>
                            <?php elseif ($histCatChild): ?>
                                อุปการะเด็ก<?php
                                echo $histChild !== '' ? ' — ' . htmlspecialchars($histChild) : '';
                                ?>
                            <?php elseif ($histCatProject): ?>
                                บริจาคให้โครงการ<?php
                                echo $histProject !== '' ? ' — ' . htmlspecialchars($histProject) : '';
                                ?>
                            <?php elseif ($histCatNeed): ?>
                                บริจาครายการสิ่งของ<?php
                                echo $histFoundation !== '' ? ' — ' . htmlspecialchars($histFoundation) : '';
                                ?>
                            <?php else: ?>
                                บริจาค
                            <?php endif; ?>
                        </div>
                        <div class="log-details">
                            <strong>จำนวน:</strong>
                            <span class="donation-amount-num">
                                <?= number_format((float)$don['amount'], 2) ?> บาท
                            </span>
                        </div>
                        <?php if (!empty($don['omise_charge_id'])): ?>
                            <div class="log-details log-ref">
                                อ้างอิง: <?= htmlspecialchars($don['omise_charge_id']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="log-time">
                            <?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($don_count > 3): ?>
                <div class="donation-more-wrap">
                    <button type="button" class="btn-donation-more" id="btn-donation-more">ดูเพิ่มเติม</button>
                </div>
                <?php endif; ?>
            <?php elseif (!empty($donor_active_child_subscriptions)): ?>
                <p class="donor-history-hint">รายการแต่ละงวดจะแสดงด้านล่างเมื่อระบบบันทึกยอดสำเร็จ (Webhook Omise → <code>payment/omise_webhook.php</code> หรือ cron งวด)</p>
            <?php else: ?>
                <div style="text-align:center; color:#999; padding:30px;">
                    ยังไม่มีประวัติการบริจาค
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($role === 'admin' && !empty($logs)): ?>
        <div class="logs-section">
            <h2>ประวัติการทำงาน</h2>
            <?php foreach ($logs as $log): ?>
                <?php
                    $nt = (string)($log['notif_type'] ?? '');
                    $ntBucket = drawdream_normalize_notif_type_to_th($nt);
                    $isApprove = ($ntBucket === 'อนุมัติ');
                    $isReject  = ($ntBucket === 'ไม่อนุมัติ');
                    $class     = $isApprove ? 'approve' : ($isReject ? 'reject' : '');
                    $hasDetails = !empty($log['item_name']) || !empty($log['project_name']) || !empty($log['audit_foundation_name']);
                ?>
                <div class="log-item <?= $class ?>" <?= $hasDetails ? 'onclick="showModal(' . htmlspecialchars(json_encode($log)) . ')"' : '' ?>>
                    <div class="log-action"><?= htmlspecialchars(drawdream_admin_notif_type_label_th($nt)) ?></div>
                    <div class="log-details">
                        <?php if ($log['target_id']): ?>
                            <strong>รหัสอ้างอิง:</strong> #<?= $log['target_id'] ?>
                            <?= $hasDetails ? ' <span style="color:#4A5BA8;">(คลิกดูรายละเอียด)</span>' : '' ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['remark'])): ?>
                        <div class="log-remark">
                            <strong>เหตุผล:</strong> <?= htmlspecialchars($log['remark']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($log['notif_recipient_user_id'])): ?>
                        <div class="log-details" style="font-size:0.9em;color:#555;">
                            แจ้งเตือนผู้ใช้ #<?= (int)$log['notif_recipient_user_id'] ?>
                            <?php if (!empty($log['notif_type'])): ?><span> — <?= htmlspecialchars(drawdream_admin_notif_type_label_th($nt)) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="log-time"><?= date('d/m/Y H:i:s', strtotime($log['action_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="detailModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
function showModal(data) {
    const modal = document.getElementById('detailModal');
    const body  = document.getElementById('modalBody');
    let html = '';
    if (data.item_name) {
        const firstNeedImage = (data.photo_item || '').split('|').filter(Boolean)[0] || '';
        if (firstNeedImage) html += `<img class="modal-image" src="uploads/needs/${firstNeedImage}" alt="">`;
        html += `<div class="modal-title">${data.item_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">มูลนิธิ:</div><div class="modal-value">${data.foundation_name || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รายละเอียด:</div><div class="modal-value">${data.item_desc || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">จำนวน:</div><div class="modal-value">${data.quantity_required} ชิ้น</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">ราคา/หน่วย:</div><div class="modal-value">${Number(data.item_price).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รวม:</div><div class="modal-value"><strong>${(data.quantity_required * data.item_price).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</strong></div></div>`;
    }
    if (data.project_name) {
        html += `<div class="modal-title">${data.project_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">รายละเอียด:</div><div class="modal-value">${data.project_desc || '-'}</div></div>`;
    }
    if (data.audit_foundation_name) {
        html += `<div class="modal-title">มูลนิธิ: ${data.audit_foundation_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">คำขอสมัคร / อนุมัติบัญชี</div><div class="modal-value">รหัสอ้างอิง foundation_id #${data.target_id}</div></div>`;
    }
    body.innerHTML = html;
    modal.classList.add('active');
}
function closeModal() {
    document.getElementById('detailModal').classList.remove('active');
}
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

(function() {
    var openBtn = document.getElementById('openDonationHistory');
    var panel = document.getElementById('donationHistoryPanel');
    if (openBtn && panel) {
        openBtn.addEventListener('click', function() {
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    var openFin = document.getElementById('openFoundationFinance');
    var finPanel = document.getElementById('foundationFinancePanel');
    if (openFin && finPanel) {
        openFin.addEventListener('click', function() {
            finPanel.hidden = false;
            finPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    var btn = document.getElementById('btn-donation-more');
    var yearFilter = document.getElementById('donation-year-filter');
    var items = [].slice.call(document.querySelectorAll('.log-item--donation'));
    var wrap = btn ? btn.closest('.donation-more-wrap') : null;
    function applyFilter() {
        var year = yearFilter ? yearFilter.value : 'all';
        var shown = 0;
        var hiddenCount = 0;
        items.forEach(function(el) {
            var ok = (year === 'all') || (el.getAttribute('data-year') === year);
            if (!ok) {
                el.hidden = true;
                return;
            }
            if (shown < 3) {
                el.hidden = false;
            } else {
                el.hidden = true;
                hiddenCount++;
            }
            shown++;
        });
        if (wrap) wrap.style.display = hiddenCount > 0 ? '' : 'none';
    }
    if (btn) {
        btn.addEventListener('click', function() {
            var year = yearFilter ? yearFilter.value : 'all';
            items.forEach(function(el) {
                var ok = (year === 'all') || (el.getAttribute('data-year') === year);
                if (ok) el.hidden = false;
            });
            if (wrap) wrap.style.display = 'none';
        });
    }
    if (yearFilter) {
        yearFilter.addEventListener('change', applyFilter);
    }
    applyFilter();
})();
</script>

</body>
</html>