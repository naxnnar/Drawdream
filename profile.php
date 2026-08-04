<?php
// profile.php — โปรไฟล์ผู้ใช้และประวัติบริจาค

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน profile

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp 
                           JOIN `user` u ON fp.user_id = u.user_id 
                           WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

} elseif ($role === 'donor') {
    require_once __DIR__ . '/includes/donate_category_resolve.php';
    require_once __DIR__ . '/includes/child_omise_subscription.php';
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
            ON fc.child_id = d.target_id
        LEFT JOIN foundation_project p
            ON p.project_id = d.target_id
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

    $donor_active_child_subscriptions = drawdream_donor_list_active_child_subscriptions($conn, $user_id);

} elseif ($role === 'admin') {
    require_once __DIR__ . '/includes/admin_audit_migrate.php';
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
               nl.item_name,
               COALESCE(NULLIF(nl.desired_brand, ''), NULLIF(nl.note, '')) AS need_detail,
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
    require_once __DIR__ . '/includes/drawdream_user_error.php';
    drawdream_user_error_redirect('ไม่พบข้อมูลโปรไฟล์หรือสิทธิ์ไม่ถูกต้อง', 'homepage.php', 'profile.php unsupported role: ' . $role);
}

if (!$profile) {
    require_once __DIR__ . '/includes/drawdream_user_error.php';
    drawdream_user_error_redirect('ไม่พบข้อมูลโปรไฟล์', 'homepage.php', 'profile.php missing profile user_id=' . $user_id);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>โปรไฟล์ | DrawDream</title>
    <?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_bootstrap_icons_link(); ?>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/profile.css?v=22">
    <?php if ($role === 'foundation'): ?>
    <link rel="prefetch" href="update_profile.php">
    <?php endif; ?>
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
                    <span class="profile-avatar-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
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
        <?php
        $acctVerified = (int)($profile['account_verified'] ?? 0);
        if ($acctVerified !== 1):
            $step1Done = true;
            $step2Current = ($acctVerified === 0);
            $step3Todo = true;
        ?>
        <div class="foundation-account-stepper" style="margin:16px 0;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;">
            <div style="font-weight:600;margin-bottom:10px;color:#0f172a;">สถานะการยืนยันบัญชีมูลนิธิ</div>
            <ol style="margin:0;padding:0;list-style:none;display:grid;gap:8px;font-size:.9rem;">
                <li style="color:#166534;">✓ ขั้นที่ 1 — สมัครและกรอกโปรไฟล์แล้ว</li>
                <li style="color:<?= $step2Current ? '#b45309' : '#64748b' ?>;">
                    <?= $step2Current ? '⏳' : '○' ?> ขั้นที่ 2 — รอแอดมินตรวจสอบ (โดยทั่วไป 1–3 วันทำการ)
                </li>
                <li style="color:#94a3b8;">○ ขั้นที่ 3 — เปิดรับบริจาคได้เต็มรูปแบบ</li>
            </ol>
            <?php if ($acctVerified === 2): ?>
            <p style="margin:10px 0 0;font-size:.86rem;color:#9f1239;">บัญชียังไม่ผ่าน — แก้ไขโปรไฟล์แล้วบันทึกเพื่อส่งตรวจใหม่</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ((int)($profile['account_verified'] ?? 0) === 2): ?>
            <div class="alert-bank alert-bank--foundation" style="background:#fff1f2;border-color:#fecdd3;color:#9f1239;">
                โปรไฟล์มูลนิธิของคุณยังไม่ผ่านการอนุมัติ — ดูเหตุผลได้จากแจ้งเตือนในระบบ
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
            <button type="button" class="profile-menu-btn profile-menu-btn--history" id="openFoundationFinance" aria-controls="foundationFinancePanel" aria-expanded="false">
                <span class="profile-menu-icon"><i class="bi bi-cash-stack"></i></span>
                <span class="profile-menu-label">ยอดบริจาค</span>
                <span class="profile-menu-arrow">›</span>
            </button>
        </div>

        <div class="logs-section donor-history-panel foundation-projects-panel foundation-finance-panel" id="foundationFinancePanel" hidden>
            <h2>ยอดบริจาค</h2>
            <p class="foundation-finance-lead">สรุปตามช่องทางบริจาค (เด็ก / โครงการ / สิ่งของ) และรายการล่าสุดที่ระบบบันทึกได้</p>
            <div id="foundationFinanceContent" class="foundation-finance-content" data-loaded="0">
                <div class="foundation-finance-loading" role="status" aria-live="polite">กำลังโหลดยอดบริจาค…</div>
            </div>
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
                                    <?= drawdream_csrf_field() ?>
                                    <input type="hidden" name="return_to" value="profile">
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
                        <label class="donor-history-search-wrap" for="donation-history-search">
                            <span class="visually-hidden">ค้นหาประวัติ</span>
                            <input type="search" id="donation-history-search" class="donor-history-search" placeholder="ค้นหาชื่อ / ยอด / เลขอ้างอิง" autocomplete="off" enterkeyhint="search">
                        </label>
                        <label class="donor-year-filter-wrap" for="donation-type-filter">
                            <select id="donation-type-filter" class="donor-year-filter donor-type-filter" aria-label="กรองตามประเภท">
                                <option value="all">ทุกประเภท</option>
                                <option value="child">อุปการะเด็ก</option>
                                <option value="project">โครงการ</option>
                                <option value="need">สิ่งของ</option>
                            </select>
                        </label>
                        <label class="donor-year-filter-wrap" for="donation-year-filter">
                            <select id="donation-year-filter" class="donor-year-filter" aria-label="กรองตามปี">
                                <option value="all">ทุกปี</option>
                                <?php foreach ($year_options as $yr): ?>
                                    <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="button" class="btn-donation-all" id="btn-donation-all">ดูรายการทั้งหมด</button>
                    </div>
                </div>
                <p class="donor-history-filter-empty" id="donationHistoryFilterEmpty" hidden>ไม่พบรายการที่ตรงกับตัวกรอง</p>
                <div class="donation-summary">
                    <div class="donation-summary-primary">บริจาคทั้งหมด <strong><?= number_format($total_donated, 2) ?> บาท</strong></div>
                    <div class="donation-summary-secondary">จาก <?= $don_count ?> รายการ</div>
                </div>
                <?php foreach ($donation_history as $idx => $don): ?>
                    <?php
                    $yr = date('Y', strtotime((string)$don['transfer_datetime']));
                    $histChild = trim((string)($don['child_name_by_target'] ?? ''));
                    $histProject = trim((string)($don['project_name_by_target'] ?? ''));
                    $histFoundation = trim((string)($don['foundation_name_by_target'] ?? ''));
                    $histCatChild = drawdream_donate_cat_label_is_active($don['child_donate'] ?? null);
                    $histCatProject = drawdream_donate_cat_label_is_active($don['project_donate'] ?? null);
                    $histCatNeed = drawdream_donate_cat_label_is_active($don['needitem_donate'] ?? null);
                    if ($histCatChild || $histChild !== '') {
                        $histDonateType = 'child';
                    } elseif ($histCatProject || $histProject !== '') {
                        $histDonateType = 'project';
                    } elseif ($histCatNeed || $histFoundation !== '') {
                        $histDonateType = 'need';
                    } else {
                        $histDonateType = 'other';
                    }
                    $histSearchBlob = mb_strtolower(implode(' ', array_filter([
                        $histChild,
                        $histProject,
                        $histFoundation,
                        (string)($don['omise_charge_id'] ?? ''),
                        number_format((float)$don['amount'], 2, '.', ''),
                        (string)($don['donate_id'] ?? ''),
                    ])), 'UTF-8');
                    ?>
                    <div class="log-item log-item--donation" data-year="<?= htmlspecialchars($yr) ?>" data-donate-type="<?= htmlspecialchars($histDonateType) ?>" data-search="<?= htmlspecialchars($histSearchBlob, ENT_QUOTES, 'UTF-8') ?>"<?= $idx >= 5 ? ' hidden' : '' ?>>
                        <?php if ((int)($don['donate_id'] ?? 0) > 0): ?>
                        <a class="log-item-hit" href="donation_receipt.php?donate_id=<?= (int)$don['donate_id'] ?>" aria-label="ดูใบเสร็จการบริจาค"></a>
                        <?php endif; ?>
                        <div class="donor-donation-main">
                            <div class="log-action">
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
                                <span class="donor-donation-datetime"><?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?></span>
                                <?php if (!empty($don['omise_charge_id'])): ?>
                                    <span class="donor-donation-charge">· <?= htmlspecialchars($don['omise_charge_id']) ?></span>
                                <?php endif; ?>
                                <span class="donation-amount-num"><?= number_format((float)$don['amount'], 2) ?> บาท</span>
                            </div>
                        </div>
                        <?php if ((int)($don['donate_id'] ?? 0) > 0): ?>
                            <a class="log-receipt-link" href="donation_receipt.php?donate_id=<?= (int)$don['donate_id'] ?>" title="เปิดใบเสร็จและดาวน์โหลด PDF">
                                <i class="bi bi-download" aria-hidden="true"></i> ใบเสร็จ / PDF
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php elseif (!empty($donor_active_child_subscriptions)): ?>
                <p class="donor-history-hint">รายการแต่ละรอบจะแสดงด้านล่างเมื่อระบบบันทึกยอดสำเร็จ</p>
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

<?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_sweetalert2_js_tag('', false); ?>
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
        html += `<div class="modal-section"><div class="modal-label">แบรนด์ที่ต้องการ/รายละเอียด:</div><div class="modal-value">${data.need_detail || '-'}</div></div>`;
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
    function openDonationHistoryPanel() {
        if (!panel) return;
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (openBtn && panel) {
        openBtn.addEventListener('click', openDonationHistoryPanel);
    }
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('history') === '1') {
            openDonationHistoryPanel();
            var subMsg = params.get('sub_msg');
            if (subMsg) {
                var subOk = params.get('sub_ok') === '1';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: subOk ? 'success' : 'error',
                        title: subOk ? 'สำเร็จ' : 'ไม่สำเร็จ',
                        text: subMsg,
                        timer: subOk ? 2200 : undefined,
                        showConfirmButton: !subOk
                    });
                } else {
                    alert(subMsg);
                }
            }
        }
    } catch (e) { /* ignore */ }

    var openFin = document.getElementById('openFoundationFinance');
    var finPanel = document.getElementById('foundationFinancePanel');
    var finContent = document.getElementById('foundationFinanceContent');
    var finLoaded = false;
    var finLoading = false;

    function bindFoundationFinanceMore() {
        var finMoreBtn = document.getElementById('btn-foundation-finance-more');
        if (!finMoreBtn) return;
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

    function loadFoundationFinance(done) {
        if (finLoaded || finLoading || !finContent) {
            if (done) done();
            return;
        }
        finLoading = true;
        fetch('profile_foundation_finance.php', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(res) {
                if (!res.ok) throw new Error('load failed');
                return res.text();
            })
            .then(function(html) {
                finContent.innerHTML = html;
                finContent.setAttribute('data-loaded', '1');
                finLoaded = true;
                bindFoundationFinanceMore();
                if (done) done();
            })
            .catch(function() {
                finContent.innerHTML = '<div class="foundation-finance-empty">โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง</div>';
            })
            .finally(function() {
                finLoading = false;
                if (openFin) openFin.classList.remove('profile-menu-btn--pending');
            });
    }

    function openFoundationFinancePanel() {
        if (!finPanel) return;
        finPanel.hidden = false;
        if (openFin) openFin.setAttribute('aria-expanded', 'true');
        finPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (!finLoaded && !finLoading) {
            if (openFin) openFin.classList.add('profile-menu-btn--pending');
            loadFoundationFinance();
        }
    }

    if (openFin && finPanel) {
        openFin.addEventListener('click', openFoundationFinancePanel);
    }
    try {
        var finParams = new URLSearchParams(window.location.search);
        if (finParams.get('finance') === '1') {
            openFoundationFinancePanel();
        }
    } catch (eFin) { /* ignore */ }

    // ประวัติบริจาค:
    // - เริ่มต้นแสดง 5 รายการแรกของปี/ประเภทที่เลือก
    // - กด "ดูรายการทั้งหมด" แล้วค่อยแสดงครบทุกรายการที่ตรงตัวกรอง
    var yearFilter = document.getElementById('donation-year-filter');
    var typeFilter = document.getElementById('donation-type-filter');
    var searchInput = document.getElementById('donation-history-search');
    var filterEmpty = document.getElementById('donationHistoryFilterEmpty');
    var showAllBtn = document.getElementById('btn-donation-all');
    var items = [].slice.call(document.querySelectorAll('.log-item--donation'));
    var expandedAll = false;
    function applyFilter() {
        var year = yearFilter ? yearFilter.value : 'all';
        var dtype = typeFilter ? typeFilter.value : 'all';
        var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var visibleCount = 0;
        var hasMoreThanFive = false;
        var matchedAny = false;
        items.forEach(function(el) {
            var okYear = (year === 'all') || (el.getAttribute('data-year') === year);
            var elType = el.getAttribute('data-donate-type') || 'other';
            var okType = (dtype === 'all') || (elType === dtype);
            var blob = el.getAttribute('data-search') || '';
            var okSearch = q === '' || blob.indexOf(q) !== -1;
            var ok = okYear && okType && okSearch;
            if (!ok) {
                el.hidden = true;
                return;
            }
            matchedAny = true;
            if (!expandedAll && visibleCount >= 5) {
                el.hidden = true;
                hasMoreThanFive = true;
            } else {
                el.hidden = false;
            }
            visibleCount++;
        });
        if (filterEmpty) {
            filterEmpty.hidden = matchedAny;
        }
        if (showAllBtn) {
            showAllBtn.style.display = hasMoreThanFive ? '' : 'none';
        }
    }
    if (showAllBtn) {
        showAllBtn.addEventListener('click', function() {
            expandedAll = true;
            applyFilter();
            showAllBtn.style.display = 'none';
        });
    }
    if (yearFilter) {
        yearFilter.addEventListener('change', function() {
            expandedAll = false;
            applyFilter();
        });
    }
    if (typeFilter) {
        typeFilter.addEventListener('change', function() {
            expandedAll = false;
            applyFilter();
        });
    }
    if (searchInput) {
        var searchTimer = null;
        searchInput.addEventListener('input', function() {
            expandedAll = false;
            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            searchTimer = setTimeout(applyFilter, 180);
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

</body>
</html>