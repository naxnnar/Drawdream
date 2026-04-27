<?php
// profile.php — โปรไฟล์ผู้ใช้และประวัติบริจาค

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน profile

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

    $childDonateCategoryId = drawdream_get_or_create_child_donate_category_id($conn);

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

    if ($foundationId > 0 && $childDonateCategoryId > 0) {
        $cq = $conn->prepare("
            SELECT COALESCE(SUM(d.amount), 0) AS t
            FROM donation d
            INNER JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.foundation_id = ?
            WHERE d.category_id = ? AND d.payment_status = 'completed'
        ");
        $cq->bind_param("ii", $foundationId, $childDonateCategoryId);
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
        ");
        $lr->bind_param("s", $foundationName);
        $lr->execute();
        foreach ($lr->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $foundation_finance_rows[] = $row;
        }
    }

    if ($foundationId > 0) {
        $lrNeed = $conn->prepare("
            SELECT d.transfer_datetime AS ts, d.amount, 'need' AS cat_key, COALESCE(fp.foundation_name, 'มูลนิธิของคุณ') AS title
            FROM donation d
            INNER JOIN donate_category dc ON dc.category_id = d.category_id
                AND TRIM(COALESCE(dc.needitem_donate, '')) NOT IN ('', '-')
            LEFT JOIN foundation_profile fp ON fp.foundation_id = d.target_id
            WHERE d.payment_status = 'completed'
              AND d.target_id = ?
            ORDER BY d.transfer_datetime DESC
        ");
        $lrNeed->bind_param("i", $foundationId);
        $lrNeed->execute();
        foreach ($lrNeed->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $foundation_finance_rows[] = $row;
        }
    }

    if ($foundationId > 0 && $childDonateCategoryId > 0) {
        $lr2 = $conn->prepare("
            SELECT d.transfer_datetime AS ts, d.amount, 'child' AS cat_key, fc.child_name AS title
            FROM donation d
            INNER JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.foundation_id = ?
            WHERE d.category_id = ? AND d.payment_status = 'completed'
            ORDER BY d.transfer_datetime DESC
        ");
        $lr2->bind_param("ii", $foundationId, $childDonateCategoryId);
        $lr2->execute();
        foreach ($lr2->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $foundation_finance_rows[] = $row;
        }
    }

    usort($foundation_finance_rows, static function ($a, $b) {
        return strtotime((string)$b['ts']) <=> strtotime((string)$a['ts']);
    });

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
            d.omise_charge_id AS omise_charge_id,
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

    usort(
        $donation_history,
        static function ($a, $b) {
            return strtotime((string)($b['transfer_datetime'])) <=> strtotime((string)($a['transfer_datetime']));
        }
    );
    $donation_history = array_slice($donation_history, 0, 200);

    $stSub = $conn->prepare(
        "SELECT d.donate_id AS id, d.target_id AS child_id,
                d.recurring_plan_code AS plan_code,
                fc.child_name
         FROM donation d
         INNER JOIN foundation_children fc ON fc.child_id = d.target_id AND fc.deleted_at IS NULL
         WHERE d.donor_id = ? AND d.donate_type = 'child_subscription' AND d.recurring_status = ?
         ORDER BY fc.child_name ASC"
    );
    if ($stSub) {
        $active = 'active';
        $stSub->bind_param('is', $user_id, $active);
        $stSub->execute();
        $donor_active_child_subscriptions = $stSub->get_result()->fetch_all(MYSQLI_ASSOC);
        require_once __DIR__ . '/includes/child_omise_subscription.php';
        foreach ($donor_active_child_subscriptions as &$subRow) {
            $spec = drawdream_child_subscription_plan((string)($subRow['plan_code'] ?? ''));
            $subRow['amount_thb'] = is_array($spec) ? (float)($spec['amount_thb'] ?? 0) : 0.0;
        }
        unset($subRow);
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
               CASE
                   WHEN COALESCE(nl.qty_needed, 0) > 0 THEN COALESCE(nl.total_price, 0) / nl.qty_needed
                   ELSE 0
               END AS item_price,
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
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/profile.css?v=15">
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
            <div class="profile-image-placeholder profile-image-placeholder--admin">
                <img src="img/icoprofile.png" alt="">
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
        <?php if ((int)($profile['account_verified'] ?? 0) === 2): ?>
            <div class="alert-bank alert-bank--foundation" style="background:#fff1f2;border-color:#fecdd3;color:#9f1239;">
                โปรไฟล์มูลนิธิของคุณยังไม่ผ่านการอนุมัติ
                <?php if (trim((string)($profile['review_note'] ?? '')) !== ''): ?>
                    <div style="margin-top:6px;">
                        <strong>เหตุผล:</strong> <?= nl2br(htmlspecialchars((string)$profile['review_note'])) ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top:8px;">กรุณาแก้ไขข้อมูล แล้วบันทึกเพื่อส่งตรวจสอบใหม่</div>
            </div>
        <?php elseif (!empty($profile['account_verified']) && empty($profile['bank_account_number'])): ?>
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
            <a href="foundation_dashboard.php" class="profile-menu-btn profile-menu-btn--dashboard">
                <span class="profile-menu-icon"><i class="bi bi-grid-1x2-fill"></i></span>
                <span class="profile-menu-label">แดชบอร์ด</span>
                <span class="profile-menu-arrow">›</span>
            </a>
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
            <h3 class="foundation-finance-subhead">รายการล่าสุด</h3>
            <?php if (!empty($foundation_finance_rows)): ?>
                <div class="foundation-finance-list">
                    <?php foreach ($foundation_finance_rows as $idx => $fr): ?>
                        <?php
                            $is_extra_fin = $idx >= 5;
                            $ck = $fr['cat_key'] ?? 'project';
                            $meta = $foundation_cat_labels[$ck] ?? $foundation_cat_labels['project'];
                            $ts = $fr['ts'] ?? '';
                            $title = trim((string)($fr['title'] ?? ''));
                            if ($title === '') {
                                $title = '—';
                            }
                        ?>
                        <div class="foundation-finance-row<?= $is_extra_fin ? ' foundation-finance-row--extra' : '' ?>"<?= $is_extra_fin ? ' style="display:none;"' : '' ?>>
                            <span class="foundation-finance-badge foundation-finance-badge--<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($meta['short']) ?></span>
                            <div class="foundation-finance-row__body">
                                <div class="foundation-finance-row__title"><?= htmlspecialchars($title) ?></div>
                                <div class="foundation-finance-row__time"><?= $ts ? date('d/m/Y H:i', strtotime((string)$ts)) : '—' ?></div>
                            </div>
                            <span class="foundation-finance-row__amount"><?= number_format((float)($fr['amount'] ?? 0), 2) ?> ฿</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($foundation_finance_rows) > 5): ?>
                <div class="donation-more-wrap">
                    <button type="button" class="btn-donation-more" id="btn-foundation-finance-more">ดูเพิ่มเติม</button>
                </div>
                <?php endif; ?>
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
                            default => $pl !== '' ? $pl : 'รายรอบ',
                        };
                        $subChildId = (int)($sub['child_id'] ?? 0);
                        ?>
                        <li>
                            <div class="donor-sponsorship-info">
                                <?php if ($subChildId > 0): ?>
                                    <a class="donor-sponsorship-child-link" href="children_donate.php?id=<?= $subChildId ?>"><strong><?= htmlspecialchars((string)($sub['child_name'] ?? '')) ?></strong></a>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars((string)($sub['child_name'] ?? '')) ?></strong>
                                <?php endif; ?>
                                <span class="donor-sponsorship-active-banner__meta"><?= htmlspecialchars($planTh) ?> <?= number_format((float)($sub['amount_thb'] ?? 0), 0) ?> บาท</span>
                            </div>
                            <?php if ($subChildId > 0): ?>
                                <form method="post" action="payment/child_subscription_cancel.php" class="donor-sponsorship-cancel-form js-confirm-cancel-sub" data-child-name="<?= htmlspecialchars((string)($sub['child_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="child_id" value="<?= $subChildId ?>">
                                    <button type="submit" class="donor-sponsorship-cancel-btn">ยกเลิกอุปการะ</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($donation_history)): ?>
                <div class="donor-summary-head">
                    <div class="donor-summary-head-actions">
                        <label class="donor-year-filter-wrap" for="donation-year-filter">
                            <select id="donation-year-filter" class="donor-year-filter">
                                <option value="all">ทุกปี</option>
                                <?php foreach ($year_options as $yr): ?>
                                    <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="button" class="btn-donation-all" id="btn-donation-all">ดูรายการทั้งหมด</button>
                    </div>
                </div>
                <div class="donation-summary">
                    บริจาคทั้งหมด <strong><?= number_format($total_donated, 2) ?> บาท</strong> จาก <?= $don_count ?> รายการ
                </div>
                <?php foreach ($donation_history as $idx => $don): ?>
                    <?php $yr = date('Y', strtotime((string)$don['transfer_datetime'])); ?>
                    <div class="log-item log-item--donation" data-year="<?= htmlspecialchars($yr) ?>"<?= $idx >= 5 ? ' hidden' : '' ?>>
                        <div class="donor-donation-main">
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
                                    <?php /* category_id ผิดแต่ target_id ชี้เด็กจริง (เช่น QR/รอบ Omise) */ ?>
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
                            <div class="donor-donation-meta">
                                <span><?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?></span>
                                <?php if (!empty($don['omise_charge_id'])): ?>
                                    <span> · <?= htmlspecialchars($don['omise_charge_id']) ?></span>
                                <?php endif; ?>
                                <span class="donation-amount-num"><?= number_format((float)$don['amount'], 2) ?> บาท</span>
                            </div>
                        </div>
                        <?php if ((int)($don['donate_id'] ?? 0) > 0): ?>
                            <a class="log-receipt-link" href="donation_receipt.php?donate_id=<?= (int)$don['donate_id'] ?>">
                                ดูใบเสร็จอิเล็กทรอนิกส์
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php elseif (!empty($donor_active_child_subscriptions)): ?>
                <p class="donor-history-hint">รายการแต่ละรอบจะแสดงด้านล่างเมื่อระบบบันทึกยอดสำเร็จ (Webhook Omise → <code>payment/omise_webhook.php</code> หรือ cron รอบ)</p>
            <?php else: ?>
                <div style="text-align:center; color:#999; padding:30px;">
                    ยังไม่มีประวัติการบริจาค
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($role === 'admin' && !empty($logs)): ?>
        <?php $admin_log_total = count($logs); ?>
        <div class="logs-section logs-section--admin-work">
            <h2>ประวัติการทำงาน</h2>
            <?php foreach ($logs as $idx => $log): ?>
                <?php
                    $nt = (string)($log['notif_type'] ?? '');
                    $ntBucket = drawdream_normalize_notif_type_to_th($nt);
                    $isApprove = ($ntBucket === 'อนุมัติ');
                    $isReject  = ($ntBucket === 'ไม่อนุมัติ');
                    $class     = $isApprove ? 'approve' : ($isReject ? 'reject' : '');
                    $hasDetails = !empty($log['item_name']) || !empty($log['project_name']) || !empty($log['audit_foundation_name']);
                    $log_is_extra = $idx >= 3;
                ?>
                <div class="log-item<?= $class !== '' ? ' ' . $class : '' ?><?= $log_is_extra ? ' admin-log-item-extra' : '' ?>"<?= $log_is_extra ? ' hidden' : '' ?><?= $hasDetails ? ' onclick="showModal(' . htmlspecialchars(json_encode($log, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')"' : '' ?>>
                    <div class="log-action"><?= htmlspecialchars(drawdream_admin_notif_type_label_th($nt)) ?></div>
                    <div class="log-details">
                        <?php if ($log['target_id']): ?>
                            <strong>รหัสอ้างอิง:</strong> #<?= $log['target_id'] ?>
                            <?= $hasDetails ? ' <span class="log-details-hint">(คลิกดูรายละเอียด)</span>' : '' ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['remark'])): ?>
                        <div class="log-remark">
                            <strong>ข้อมูล:</strong> <?= htmlspecialchars($log['remark']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($log['notif_recipient_user_id'])): ?>
                        <div class="log-details log-details--notif">
                            แจ้งเตือนผู้ใช้ #<?= (int)$log['notif_recipient_user_id'] ?>
                            <?php if (!empty($log['notif_type'])): ?><span> — <?= htmlspecialchars(drawdream_admin_notif_type_label_th($nt)) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="log-time"><?= date('d/m/Y H:i:s', strtotime($log['action_at'])) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($admin_log_total > 3): ?>
            <div class="admin-log-more-wrap">
                <button type="button" class="btn-admin-log-more" id="btnAdminLogMore">ดูทั้งหมด</button>
            </div>
            <?php endif; ?>
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

    // ประวัติบริจาค:
    // - เริ่มต้นแสดง 5 รายการแรกของปีที่เลือก
    // - กด "ดูรายการทั้งหมด" แล้วค่อยแสดงครบทุกรายการของปีนั้น
    var yearFilter = document.getElementById('donation-year-filter');
    var showAllBtn = document.getElementById('btn-donation-all');
    var items = [].slice.call(document.querySelectorAll('.log-item--donation'));
    var expandedAll = false;
    function applyFilter() {
        var year = yearFilter ? yearFilter.value : 'all';
        var visibleCount = 0;
        var hasMoreThanFive = false;
        items.forEach(function(el) {
            var ok = (year === 'all') || (el.getAttribute('data-year') === year);
            if (!ok) {
                el.hidden = true;
                return;
            }
            if (!expandedAll && visibleCount >= 5) {
                el.hidden = true;
                hasMoreThanFive = true;
            } else {
                el.hidden = false;
            }
            visibleCount++;
        });
        if (showAllBtn) {
            showAllBtn.style.display = hasMoreThanFive ? '' : 'none';
        }
    }
    if (showAllBtn) {
        showAllBtn.addEventListener('click', function() {
            // กดแล้วขยายรายการทั้งหมดของปีที่เลือก
            expandedAll = true;
            applyFilter();
            showAllBtn.style.display = 'none';
        });
    }
    if (yearFilter) {
        yearFilter.addEventListener('change', function() {
            // เปลี่ยนปีให้กลับไปโหมดเริ่มต้น (เห็น 5 รายการก่อน)
            expandedAll = false;
            applyFilter();
        });
    }
    applyFilter();

    (function() {
        var btnAdminLog = document.getElementById('btnAdminLogMore');
        if (!btnAdminLog) return;
        btnAdminLog.addEventListener('click', function() {
            [].slice.call(document.querySelectorAll('.admin-log-item-extra')).forEach(function(el) {
                el.hidden = false;
            });
            var wrap = btnAdminLog.closest('.admin-log-more-wrap');
            if (wrap) wrap.style.display = 'none';
        });
    })();

    var finMoreBtn = document.getElementById('btn-foundation-finance-more');
    if (finMoreBtn) {
        finMoreBtn.addEventListener('click', function() {
            [].slice.call(document.querySelectorAll('.foundation-finance-row--extra')).forEach(function(el) {
                el.style.display = 'flex';
            });
            var finMoreWrap = finMoreBtn.closest('.donation-more-wrap');
            if (finMoreWrap) {
                finMoreWrap.style.display = 'none';
            } else {
                finMoreBtn.style.display = 'none';
            }
        });
    }

    var cancelForms = document.querySelectorAll('.js-confirm-cancel-sub');
    cancelForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var childName = (form.getAttribute('data-child-name') || '').trim();
            var titleText = childName !== '' ? ('ยืนยันยกเลิกอุปการะ ' + childName + ' ?') : 'ยืนยันยกเลิกการอุปการะเด็กคนนี้?';
            if (typeof Swal === 'undefined') {
                if (window.confirm(titleText + '\nระบบจะหยุดการตัดรอบถัดไป')) {
                    form.submit();
                }
                return;
            }
            Swal.fire({
                icon: 'warning',
                title: titleText,
                text: 'ระบบจะหยุดการตัดรอบถัดไป',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#b32525'
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</body>
</html>