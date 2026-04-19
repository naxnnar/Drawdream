<?php
// children_donate.php — อุปการะเด็กแบบรายรอบ (Omise Charge Schedule) + Omise.js Token

session_start();
include 'db.php';
require_once __DIR__ . '/includes/foundation_account_verified.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/payment/config.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$role = $_SESSION['role'] ?? 'donor';
$isLoggedIn = isset($_SESSION['user_id']) && (int)($_SESSION['user_id'] ?? 0) > 0;
$loginRequiredDonateMsg = 'กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้';
$loginRequiredDonateUrl = 'login.php?page=login&error=' . rawurlencode($loginRequiredDonateMsg);
$isAdmin = ($role === 'admin');

$sql = "
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM foundation_children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ?
";
if (!$isAdmin) {
    $sql .= ' AND c.deleted_at IS NULL';
}
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    echo "<script>alert('ไม่พบข้อมูลเด็กที่ระบุ'); window.location='children_.php';</script>";
    exit();
}

// Donation stats for this child
$donationStats = ['donor_count' => 0, 'total_amount' => 0, 'cycle_amount' => 0];
$childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
$stmtDs = $conn->prepare(
    "SELECT COUNT(DISTINCT donor_id) AS donor_count, COALESCE(SUM(amount),0) AS total_amount
     FROM donation
     WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'"
);
$stmtDs->bind_param("ii", $childCategoryId, $child_id);
$stmtDs->execute();
$dsRow = $stmtDs->get_result()->fetch_assoc();
if ($dsRow) {
    $donationStats = $dsRow;
}
$donationStats['cycle_amount'] = drawdream_child_cycle_total($conn, $child_id, $child);

// รายชื่อผู้บริจาค + สถานะแผนรายรอบ (มูลนิธิ/แอดมิน — แยก ยกเลิก / กำลังอุปการะ / บริจาคครั้งเดียว)
$sponsorDisplayList = drawdream_child_foundation_sponsor_display_list($conn, $child_id, $childCategoryId);
$sponsorDisplayRows = $sponsorDisplayList['rows'] ?? [];

$birthDateText = '-';
if (!empty($child['birth_date'] ?? '')) {
    $birthDateText = date('d/m/Y', strtotime($child['birth_date']));
}

$reviewStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
$reviewStatusLabel = $reviewStatus;
if ($reviewStatus === 'กำลังดำเนินการ' && !empty($child['pending_edit_json'])) {
    $reviewStatusLabel = 'รอตรวจสอบการแก้ไข';
}
if ($reviewStatus === 'กำลังดำเนินการ') {
    $reviewStatus = 'รอดำเนินการ';
}

$canDonate = drawdream_child_can_receive_donation($conn, $child_id, $child);
$donorUid = (int)($_SESSION['user_id'] ?? 0);
$activeChildSub = null;
if ($role === 'donor' && $donorUid > 0) {
    $stActiveSub = $conn->prepare(
        "SELECT donate_id AS id, recurring_plan_code AS plan_code,
                recurring_next_charge_at AS next_charge_at, recurring_status AS status
         FROM donation
         WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription' AND recurring_status = 'active'
         ORDER BY donate_id DESC
         LIMIT 1"
    );
    if ($stActiveSub) {
        $stActiveSub->bind_param('ii', $child_id, $donorUid);
        $stActiveSub->execute();
        $activeChildSub = $stActiveSub->get_result()->fetch_assoc() ?: null;
        if (is_array($activeChildSub)) {
            $spec = drawdream_child_subscription_plan((string)($activeChildSub['plan_code'] ?? ''));
            $activeChildSub['amount_thb'] = is_array($spec) ? (float)($spec['amount_thb'] ?? 0) : 0.0;
        }
    }
}
$hasActiveChildSub = is_array($activeChildSub);
$canStartChildSub = ($role === 'donor' && $donorUid > 0)
    ? drawdream_child_can_start_omise_subscription($conn, $child_id, $child, $donorUid)
    : false;
$anyPlanSponsor = drawdream_child_has_any_active_subscription($conn, $child_id);
$planMapShowcase = drawdream_child_ids_with_active_plan_sponsorship($conn, [$child_id]);
$donorShowcaseSponsored = drawdream_child_is_showcase_sponsored(
    $conn,
    $child_id,
    $child,
    (float)($donationStats['cycle_amount'] ?? 0),
    $planMapShowcase
);
$latestCancelledSubAny = null;
$latestCancelledSubForDonor = null;
$planLabelMap = ['monthly' => 'รายเดือน', 'semiannual' => 'ราย 6 เดือน', 'yearly' => 'รายปี'];
$cycleTargetAmount = drawdream_child_cycle_target_amount($conn, $child_id);
$cycleMonthLabel = date('m/Y');
    $stLatestCancelledAny = $conn->prepare(
        "SELECT recurring_plan_code AS plan_code,
                recurring_next_charge_at AS last_charge_at, transfer_datetime AS created_at
         FROM donation
         WHERE target_id = ? AND donate_type = 'child_subscription' AND recurring_status = 'cancelled'
         ORDER BY COALESCE(recurring_next_charge_at, transfer_datetime) DESC, donate_id DESC
         LIMIT 1"
    );
    if ($stLatestCancelledAny) {
        $stLatestCancelledAny->bind_param('i', $child_id);
        $stLatestCancelledAny->execute();
        $latestCancelledSubAny = $stLatestCancelledAny->get_result()->fetch_assoc() ?: null;
    }
    if ($role === 'donor' && $donorUid > 0) {
        $stLatestCancelledForDonor = $conn->prepare(
            "SELECT recurring_plan_code AS plan_code,
                    recurring_next_charge_at AS last_charge_at, transfer_datetime AS created_at
             FROM donation
             WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription' AND recurring_status = 'cancelled'
             ORDER BY COALESCE(recurring_next_charge_at, transfer_datetime) DESC, donate_id DESC
             LIMIT 1"
        );
        if ($stLatestCancelledForDonor) {
            $stLatestCancelledForDonor->bind_param('ii', $child_id, $donorUid);
            $stLatestCancelledForDonor->execute();
            $latestCancelledSubForDonor = $stLatestCancelledForDonor->get_result()->fetch_assoc() ?: null;
        }
    }
if (is_array($latestCancelledSubAny)) {
    $sp = drawdream_child_subscription_plan((string)($latestCancelledSubAny['plan_code'] ?? ''));
    $latestCancelledSubAny['amount_thb'] = is_array($sp) ? (float)($sp['amount_thb'] ?? 0) : 0.0;
}
if (is_array($latestCancelledSubForDonor)) {
    $sp = drawdream_child_subscription_plan((string)($latestCancelledSubForDonor['plan_code'] ?? ''));
    $latestCancelledSubForDonor['amount_thb'] = is_array($sp) ? (float)($sp['amount_thb'] ?? 0) : 0.0;
}
$hasCancelledSubHistory = is_array($latestCancelledSubAny);
$hasDonorCancelledHistory = is_array($latestCancelledSubForDonor);
$displayCycleAmount = (float)($donationStats['cycle_amount'] ?? 0);
$displayTotalAmount = (float)($donationStats['total_amount'] ?? 0);
$displayDonorCount = (int)($donationStats['donor_count'] ?? 0);
$cancelledSubRef = $hasDonorCancelledHistory ? $latestCancelledSubForDonor : $latestCancelledSubAny;
$cancelledPlanCode = strtolower(trim((string)($cancelledSubRef['plan_code'] ?? '')));
$cancelledPlanText = $planLabelMap[$cancelledPlanCode] ?? ($cancelledPlanCode !== '' ? $cancelledPlanCode : 'รายรอบ');
$cancelledAmountText = number_format((float)($cancelledSubRef['amount_thb'] ?? 0), 0);
$cancelledDateSource = (string)($cancelledSubRef['last_charge_at'] ?? '');
if ($cancelledDateSource === '') {
    $cancelledDateSource = (string)($cancelledSubRef['created_at'] ?? '');
}
$cancelledDateText = '-';
if ($cancelledDateSource !== '') {
    $tsCancelled = strtotime($cancelledDateSource);
    if ($tsCancelled !== false) {
        $cancelledDateText = date('d/m/Y', $tsCancelled);
    }
}
$showContinueFromCycleNotice = ($role === 'donor')
    && $hasCancelledSubHistory
    && !$hasActiveChildSub
    && !$donorShowcaseSponsored
    && $canStartChildSub;
$coverageWindow = drawdream_child_plan_coverage_window($conn, $child_id);
$coverageEnd = $coverageWindow['end'] ?? null;
$nextMonthlyCycleLabel = '-';
$nextSemiannualCycleLabel = '-';
if ($coverageEnd instanceof DateTimeImmutable) {
    $nextMonthlyCycleLabel = $coverageEnd->format('m/Y');
    $nextSemiEnd = $coverageEnd->modify('+5 months');
    $nextSemiannualCycleLabel = $coverageEnd->format('m/Y') . ' - ' . $nextSemiEnd->format('m/Y');
}
$cycleAmountNow = $displayCycleAmount;
$cycleRemainingAmount = max(0.0, $cycleTargetAmount - $cycleAmountNow);
$cycleProgressPercent = $cycleTargetAmount > 0
    ? min(100.0, max(0.0, ($cycleAmountNow / $cycleTargetAmount) * 100.0))
    : 0.0;
/** บริจาครายวัน (PromptPay): ขั้นต่ำ 20 บาท ไม่จำกัดยอดสูงสุดต่อครั้ง — เป้ารอบเดือน (เช่น 700) เป็นแค่เกณฑ์ครบอุปการะ ไม่ใช่เพดานชำระ */
$dailyCanDonate = $canDonate;
$sponsorshipLabel = drawdream_child_is_cycle_sponsored($conn, $child_id, $child) ? 'อุปการะแล้ว' : 'รออุปการะ';
$foundationCanUpdateOutcome = ($role === 'foundation')
    && drawdream_foundation_account_is_verified($conn)
    && (
        drawdream_child_is_monthly_fully_sponsored($conn, $child_id, $child)
        || $anyPlanSponsor
        || $donorShowcaseSponsored
    );
$outcomePublic = trim((string)($child['update_text'] ?? ''));
$outcomeUpdatedAt = $child['update_at'] ?? null;
$outcomeImageList = drawdream_child_outcome_images_parse($child['update_images'] ?? null);
$outcomeHasContent = ($outcomePublic !== '' || $outcomeImageList !== []);
$impressionSlides = [];
foreach ($outcomeImageList as $imgFn) {
    $imgUrl = drawdream_child_outcome_image_url($imgFn);
    if ($imgUrl !== '') {
        $impressionSlides[] = $imgUrl;
    }
}
$impressionMainSrc = $impressionSlides[0] ?? null;
$educationLabel = trim((string)($child['education'] ?? ''));
$activePlanText = '-';
$activeNextText = '-';
if (is_array($activeChildSub)) {
    $planMap = ['monthly' => 'รายเดือน', 'semiannual' => 'ราย 6 เดือน', 'yearly' => 'รายปี'];
    $planKey = strtolower(trim((string)($activeChildSub['plan_code'] ?? '')));
    $activePlanText = $planMap[$planKey] ?? (string)($activeChildSub['plan_code'] ?? '-');
    if (!empty($activeChildSub['next_charge_at'])) {
        $tsNext = strtotime((string)$activeChildSub['next_charge_at']);
        if ($tsNext !== false) {
            $activeNextText = date('d/m/Y', $tsNext);
        }
    }
}
$childView = (string)($_GET['view'] ?? 'sponsor');
if (!in_array($childView, ['sponsor', 'outcome'], true)) {
    $childView = 'sponsor';
}
$showOutcomeTab = $donorShowcaseSponsored || $outcomeHasContent || $hasCancelledSubHistory;
if (!$showOutcomeTab) {
    $childView = 'sponsor';
}

/** ไอคอน PromptPay บนหน้าเด็ก — ใช้ path เดียวกับ payment_project.php */
$qrIconSrc = 'img/qr-code.png';
$qrDir = __DIR__ . '/img';
foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
    if (is_file($qrDir . '/qr-code' . $ext)) {
        $qrIconSrc = 'img/qr-code' . $ext;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <title>โปรไฟล์ - <?php echo htmlspecialchars($child['child_name']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=34">
    <?php if ($isAdmin): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
</head>
<body>

<?php include 'navbar.php'; ?>

<?php if (!empty($_GET['msg'] ?? '')): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    Swal.fire({ icon: 'info', title: <?php echo json_encode((string)$_GET['msg'], JSON_UNESCAPED_UNICODE); ?>, confirmButtonText: 'ตกลง' });
    </script>
<?php endif; ?>
<?php if (isset($_GET['sub_msg']) && $_GET['sub_msg'] !== ''): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    Swal.fire({
        icon: <?php echo (!empty($_GET['sub_ok'])) ? "'success'" : "'error'"; ?>,
        title: <?php echo json_encode((string)$_GET['sub_msg'], JSON_UNESCAPED_UNICODE); ?>,
        confirmButtonText: 'ตกลง'
    });
    </script>
<?php endif; ?>

<?php if ($isAdmin): ?>
<main class="container-fluid my-4">
    <div class="admin-review-card">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบโปรไฟล์เด็ก</h4>
                <div>มูลนิธิ: <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></div>
            </div>
        </div>

        <div class="admin-review-body">
            <div class="row g-4 admin-review-layout">
                <div class="col-lg-4 admin-image-col">
                    <img src="uploads/childern/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile" class="admin-child-image">
                </div>

                <div class="col-lg-8 admin-details-col">
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="label">ชื่อเด็ก</span>
                            <span class="value"><?php echo htmlspecialchars($child['child_name']); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">มูลนิธิ</span>
                            <span class="value"><?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันเกิด</span>
                            <span class="value"><?php echo htmlspecialchars($birthDateText); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">อายุ</span>
                            <span class="value"><?php echo (int)$child['age']; ?> ปี</span>
                        </div>
                        <div class="data-item">
                            <span class="label">ระดับการศึกษา</span>
                            <span class="value"><?php echo htmlspecialchars($child['education'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ความฝัน</span>
                            <span class="value"><?php echo htmlspecialchars($child['dream'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สิ่งที่ชอบ</span>
                            <span class="value"><?php echo htmlspecialchars($child['likes'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">หมวดหมู่สิ่งที่ต้องการ</span>
                            <span class="value"><?php echo htmlspecialchars($child['wish_cat'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">สิ่งที่อยากขอ / ความต้องการ</span>
                            <span class="value"><?php echo htmlspecialchars($child['wish'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ธนาคาร</span>
                            <span class="value"><?php echo htmlspecialchars($child['bank_name'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เลขบัญชี</span>
                            <span class="value"><?php echo htmlspecialchars($child['child_bank'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันที่อนุมัติ / ตรวจสอบล่าสุด</span>
                            <span class="value"><?php echo !empty($child['approve_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($child['approve_at']))) : '-'; ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการอุปการะ (เดือนปฏิทินปัจจุบัน)</span>
                            <span class="value"><?php echo htmlspecialchars($sponsorshipLabel); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการตรวจสอบ</span>
                            <?php
                              $cls = 'status-pending';
                              if ($reviewStatus === 'อนุมัติ') $cls = 'status-approved';
                              if ($reviewStatus === 'ไม่อนุมัติ') $cls = 'status-rejected';
                            ?>
                            <span class="status-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($reviewStatusLabel); ?></span>
                        </div>
                        <?php if ($reviewStatus === 'ไม่อนุมัติ'): ?>
                        <div class="data-item full">
                            <span class="label">เหตุผลไม่อนุมัติ</span>
                            <span class="value"><?php echo htmlspecialchars($child['reject_reason'] ?? '-'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                        $adminCycleAmount = (float)$displayCycleAmount;
                        $adminTotalAmount = (float)$displayTotalAmount;
                        $adminDonorCount = (int)$displayDonorCount;
                        $adminEducationFundTotal = drawdream_child_education_fund_total_thb($conn, $child_id);
                    ?>
                    <div class="donation-stats-panel" id="child-financial-overview" style="margin-top:14px;">
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-heart-fill"></i></div>
                                <div class="stat-num"><?php echo $adminDonorCount; ?></div>
                                <div class="stat-label">ผู้อุปการะทั้งหมด</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                                <div class="stat-num"><?php echo number_format($adminTotalAmount, 0); ?></div>
                                <div class="stat-label">ยอดสะสม (บาท)</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-icon"><i class="bi bi-stars"></i></div>
                                <div class="stat-num"><?php echo number_format($adminCycleAmount, 0); ?></div>
                                <div class="stat-label">เดือนนี้ (ปฏิทิน, บาท)</div>
                            </div>
                            <div class="stat-box stat-box--education-fund">
                                <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                                <div class="stat-num"><?php echo number_format($adminEducationFundTotal, 0); ?></div>
                                <div class="stat-label">ทุนการศึกษา (ส่วนเกิน 700 บ. / ครั้ง รายวัน)</div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $rawProfileForInspect = trim((string)($child['approve_profile'] ?? ''));
                    $showChildInspectActions = in_array($rawProfileForInspect, ['รอดำเนินการ', 'กำลังดำเนินการ'], true);
                    ?>
                    <?php if ($showChildInspectActions): ?>
                    <p class="admin-review-actions-note">การไม่อนุมัติจะอัปเดตสถานะโปรไฟล์เด็กในระบบ — มูลนิธิสามารถแก้ไขและส่งพิจารณาใหม่ได้</p>
                    <form method="post" action="admin_approve_children.php" class="admin-review-actions-form">
                        <input type="hidden" name="id" value="<?php echo (int)$child_id; ?>">
                        <input type="hidden" name="return" value="admin_notifications.php#admin-pending-children">
                        <div class="admin-review-actions-grid">
                            <textarea name="reject_reason" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ"></textarea>
                            <button type="submit" name="action" value="approve" class="btn btn-success admin-review-action-btn"
                                    onclick="return confirm('ยืนยันอนุมัติโปรไฟล์เด็กคนนี้?');">อนุมัติ</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger admin-review-action-btn"
                                    onclick="var t=this.form.querySelector('[name=reject_reason]');if(!t||!t.value.trim()){alert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ');if(t)t.focus();return false;}return confirm('ยืนยันไม่อนุมัติโปรไฟล์เด็กคนนี้?');">ไม่อนุมัติ</button>
                        </div>
                    </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>

<?php else: ?>
<main class="child-profile-main container my-5">
    <div class="custom-profile-card">
        <div class="profile-labels" role="tablist" aria-label="เมนูโปรไฟล์เด็ก">
            <a href="children_donate.php?id=<?php echo (int)$child['child_id']; ?>&view=sponsor" class="profile-label-tab<?php echo $childView === 'sponsor' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $childView === 'sponsor' ? 'true' : 'false'; ?>">เด็กรายบุคคล</a>
            <?php if ($showOutcomeTab): ?>
            <a href="children_donate.php?id=<?php echo (int)$child['child_id']; ?>&view=outcome" class="profile-label-tab<?php echo $childView === 'outcome' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $childView === 'outcome' ? 'true' : 'false'; ?>">ผลลัพธ์</a>
            <?php endif; ?>
        </div>

        <div class="profile-inner">
            <div class="col-left">
                <div class="child-img-container">
                    <img src="uploads/childern/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile">
                </div>
                <div class="child-details">
                    <p><strong>ชื่อ</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>มูลนิธิ</strong> <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></p>
                    <p><strong>วันเกิด</strong> <?php echo htmlspecialchars($birthDateText); ?></p>
                    <p><strong>ชั้น</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ</strong> <?php echo (int)$child['age']; ?> ปี</p>
                    <p><strong>อาชีพในฝัน</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
                    <?php if ($role === 'donor' && $hasActiveChildSub): ?>
                    <form method="post" action="payment/child_subscription_cancel.php" class="child-cancel-below-wish-form" onsubmit="return confirm('ยืนยันยกเลิกการอุปการะเด็กคนนี้? ระบบจะหยุดการตัดรอบถัดไป');">
                        <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                        <button type="submit" class="btn-subscription-cancel btn-subscription-cancel--large">ยกเลิกอุปการะเด็กคนนี้</button>
                    </form>
                    <?php endif; ?>
                    <?php if (($role === 'foundation' || $role === 'admin') && $reviewStatus === 'ไม่อนุมัติ' && !empty($child['reject_reason'] ?? '')): ?>
                    <p style="color:#b32525;"><strong>เหตุผลไม่อนุมัติ:</strong> <?php echo htmlspecialchars($child['reject_reason']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-right">
                <?php if ($childView !== 'outcome'): ?>
                <h1 class="brand-header">Drawdream</h1>
                <p class="donate-text">
                    โครงการนี้เป็นการบริจาคให้รายบุคคลซึ่งเงินที่บริจาค<br>
                    จะถูกจัดสรรให้ตรงกับความต้องการของเด็ก
                </p>
                <?php if ($showContinueFromCycleNotice): ?>
                <div class="child-sub-cancelled-notice">
                    <div class="child-sub-cancelled-notice__hint">
                        หากกดต่อ <strong>รายเดือน</strong> จะนับเป็นรอบ <strong><?php echo htmlspecialchars($nextMonthlyCycleLabel); ?></strong>
                    </div>
                    <div class="child-sub-cancelled-notice__hint">
                        หากกดต่อ <strong>ราย 6 เดือน</strong> จะนับช่วง <strong><?php echo htmlspecialchars($nextSemiannualCycleLabel); ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($role === 'donor' && $donorShowcaseSponsored): ?>
                <div class="child-subscription-box mt-2">
                    <div class="sub-plan-grid" role="group" aria-label="รูปแบบการบริจาค">
                        <button type="button" class="sub-plan-btn sub-mode-btn" disabled>รายวัน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn active" disabled>รายเดือน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn" disabled>รายปี</button>
                    </div>
                    <div class="sub-section">
                        <div class="sub-cycle-grid" role="group" aria-label="รอบอุปการะ">
                            <button type="button" class="sub-cycle-btn active" disabled>
                                <span class="sub-cycle-amt">700</span>
                                <span class="sub-cycle-note">บาท / รายเดือน</span>
                            </button>
                            <button type="button" class="sub-cycle-btn" disabled>
                                <span class="sub-cycle-amt">4200</span>
                                <span class="sub-cycle-note">บาท / ราย 6 เดือน</span>
                            </button>
                        </div>
                        <div class="sub-plan-summary sub-plan-summary--prominent" aria-live="polite">
                            <div class="sub-summary-main">
                                <span class="sub-summary-period">รายเดือน</span>
                            </div>
                            <div class="sub-summary-amt">700 บาท</div>
                        </div>
                    </div>
                    <button type="button" class="btn-submit-donation btn-submit-donation--sub" disabled aria-disabled="true" title="เด็กคนนี้มีผู้อุปการะแล้ว">
                        มีผู้อุปการะแล้ว
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($role === 'foundation'): ?>
                <div class="foundation-full-info">
                    <h4>ข้อมูลทั้งหมดที่กรอกไว้</h4>
                    <p><strong>หมวดที่ขอ</strong> <?php echo htmlspecialchars($child['wish_cat'] ?? '-'); ?></p>
                    <p><strong>สิ่งที่ชอบ</strong> <?php echo htmlspecialchars($child['likes'] ?? '-'); ?></p>
                    <p><strong>ธนาคาร</strong> <?php echo htmlspecialchars($child['bank_name'] ?? '-'); ?></p>
                    <p><strong>เลขบัญชี</strong> <?php echo htmlspecialchars($child['child_bank'] ?? '-'); ?></p>
                </div>
                <?php endif; ?>

                <?php
                    $cycleAmount = $displayCycleAmount;
                    $totalAmount = $displayTotalAmount;
                    $donorCount  = $displayDonorCount;
                ?>
                <?php if ($role === 'foundation' || $role === 'admin'): ?>
                <?php $educationFundTotal = drawdream_child_education_fund_total_thb($conn, $child_id); ?>
                <div class="donation-stats-panel" id="child-financial-overview">
                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="stat-icon"><i class="bi bi-heart-fill"></i></div>
                            <div class="stat-num"><?php echo $donorCount; ?></div>
                            <div class="stat-label">ผู้อุปการะทั้งหมด</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                            <div class="stat-num"><?php echo number_format($totalAmount, 0); ?></div>
                            <div class="stat-label">ยอดสะสม (บาท)</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-icon"><i class="bi bi-stars"></i></div>
                            <div class="stat-num"><?php echo number_format($cycleAmount, 0); ?></div>
                            <div class="stat-label">เดือนนี้ (ปฏิทิน, บาท)</div>
                        </div>
                        <div class="stat-box stat-box--education-fund">
                            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                            <div class="stat-num"><?php echo number_format($educationFundTotal, 0); ?></div>
                            <div class="stat-label">ทุนการศึกษา (ส่วนเกิน 700 บ. / ครั้ง รายวัน)</div>
                        </div>
                    </div>
                    <div class="foundation-sponsors-list">
                        <strong>ผู้อุปการะแบบรายรอบ (รายเดือน / 6 เดือน / รายปี)</strong>
                        <?php if ($sponsorDisplayRows !== []): ?>
                            <ul class="foundation-sponsors-status-list">
                                <?php foreach ($sponsorDisplayRows as $sr): ?>
                                <li class="foundation-sponsors-status-item">
                                    <span class="foundation-sponsors-status-item__name"><?php echo htmlspecialchars((string)($sr['name'] ?? '')); ?></span>
                                    <span class="foundation-sponsor-badge foundation-sponsor-badge--<?php echo htmlspecialchars((string)($sr['status'] ?? 'onetime')); ?>"><?php echo htmlspecialchars((string)($sr['status_label'] ?? '')); ?></span>
                                    <?php if (trim((string)($sr['detail_line'] ?? '')) !== ''): ?>
                                        <span class="foundation-sponsors-status-item__detail"><?php echo htmlspecialchars((string)$sr['detail_line']); ?></span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="mb-0">ยังไม่มีผู้อุปการะแบบรายรอบ</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($role === 'donor' && !$donorShowcaseSponsored && ($canStartChildSub || !$isLoggedIn)): ?>
                <div class="child-subscription-box mt-2">
                    <div class="sub-plan-grid" role="tablist" aria-label="เลือกรูปแบบการบริจาค">
                        <button type="button" class="sub-plan-btn sub-mode-btn" data-mode="daily">รายวัน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn active" data-mode="monthly">รายเดือน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn" data-mode="yearly">รายปี</button>
                    </div>

                    <div id="subSectionDaily" class="sub-section sub-section--hidden" hidden>
                        <form id="childDailyForm" method="post" action="<?php echo $isLoggedIn ? 'payment/child_donate.php' : htmlspecialchars($loginRequiredDonateUrl, ENT_QUOTES, 'UTF-8'); ?>" class="child-daily-form">
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <?php if ($isLoggedIn): ?>
                            <input type="hidden" name="pay" value="1">
                            <?php endif; ?>
                            <label class="visually-hidden" for="dailyAmountInput">จำนวนเงินบาท (ขั้นต่ำ 20)</label>
                            <input type="number" name="amount" id="dailyAmountInput" class="sub-daily-amount-input" min="20" step="1" inputmode="numeric" placeholder="<?php echo $dailyCanDonate ? 'ระบุจำนวนเงิน (ขั้นต่ำ 20 บาท)' : 'ไม่เปิดรับบริจาคในรอบนี้'; ?>" required autocomplete="off" <?php echo $dailyCanDonate ? '' : 'disabled'; ?>>
                            <div class="payment-method child-daily-payment-method">
                                <div class="method-card active" aria-label="ชำระด้วย PromptPay QR ผ่าน Omise">
                                    <img src="<?php echo htmlspecialchars($qrIconSrc); ?>" alt="" class="method-icon" width="30" height="30" decoding="async">
                                    <span>PromptPay QR</span>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit-donation btn-submit-donation--sub" <?php echo $dailyCanDonate ? '' : 'disabled'; ?> title="<?php echo $dailyCanDonate ? '' : 'ไม่เปิดรับบริจาคในรอบนี้'; ?>">บริจาค</button>
                        </form>
                    </div>

                    <div id="subSectionCard" class="sub-section">
                        <div id="subMonthlyCycles" class="sub-cycle-grid" role="group" aria-label="เลือกรอบอุปการะ">
                            <button type="button" class="sub-cycle-btn active" data-plan="monthly" data-baht="700" data-satang="70000" data-period-text="รายเดือน">
                                <span class="sub-cycle-amt">700</span>
                                <span class="sub-cycle-note">บาท / รายเดือน</span>
                            </button>
                            <button type="button" class="sub-cycle-btn" data-plan="semiannual" data-baht="4200" data-satang="420000" data-period-text="ราย 6 เดือน">
                                <span class="sub-cycle-amt">4200</span>
                                <span class="sub-cycle-note">บาท / ราย 6 เดือน</span>
                            </button>
                        </div>
                        <div class="sub-plan-summary sub-plan-summary--prominent" aria-live="polite">
                            <div class="sub-summary-main">
                                <span class="sub-summary-period" id="subPeriodLabel">รายเดือน</span>
                            </div>
                            <div class="sub-summary-amt"><span id="subAmountLabel">700</span> บาท</div>
                        </div>
                        <form id="childSubForm" method="post" action="<?php echo $isLoggedIn ? 'payment/child_subscription_create.php' : htmlspecialchars($loginRequiredDonateUrl, ENT_QUOTES, 'UTF-8'); ?>" class="child-sub-form">
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <input type="hidden" name="plan" id="subPlanField" value="monthly">
                            <input type="hidden" name="omiseToken" id="omiseTokenField" value="">
                            <button type="<?php echo $isLoggedIn ? 'button' : 'submit'; ?>" class="btn-submit-donation btn-submit-donation--sub" id="btnChildSubscribe">บริจาค</button>
                        </form>
                    </div>
                </div>
                <script type="text/javascript" src="https://cdn.omise.co/omise.js"></script>
                <script>
                (function () {
                    var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
                    var pk = <?php echo json_encode(OMISE_PUBLIC_KEY, JSON_UNESCAPED_UNICODE); ?>;
                    if (typeof OmiseCard !== 'undefined') {
                        OmiseCard.configure({ publicKey: pk });
                    }
                    var modeBtns = document.querySelectorAll('.sub-mode-btn');
                    var sectionDaily = document.getElementById('subSectionDaily');
                    var sectionCard = document.getElementById('subSectionCard');
                    var monthlyCycles = document.getElementById('subMonthlyCycles');
                    var cycleBtns = document.querySelectorAll('.sub-cycle-btn');
                    var planField = document.getElementById('subPlanField');
                    var amountLabel = document.getElementById('subAmountLabel');
                    var periodLabel = document.getElementById('subPeriodLabel');
                    var dailyInput = document.getElementById('dailyAmountInput');
                    var dailyForm = document.getElementById('childDailyForm');
                    var satang = 70000;

                    function setSectionVisibility(dailyOn) {
                        if (dailyOn) {
                            sectionDaily.removeAttribute('hidden');
                            sectionDaily.classList.remove('sub-section--hidden');
                            sectionCard.setAttribute('hidden', 'hidden');
                            sectionCard.classList.add('sub-section--hidden');
                        } else {
                            sectionDaily.setAttribute('hidden', 'hidden');
                            sectionDaily.classList.add('sub-section--hidden');
                            sectionCard.removeAttribute('hidden');
                            sectionCard.classList.remove('sub-section--hidden');
                        }
                    }

                    function syncFromCycleButton(btn) {
                        if (!btn || !planField) return;
                        planField.value = btn.getAttribute('data-plan') || 'monthly';
                        var b = btn.getAttribute('data-baht') || '700';
                        amountLabel.textContent = b;
                        if (periodLabel) {
                            periodLabel.textContent = btn.getAttribute('data-period-text') || '';
                        }
                        satang = parseInt(btn.getAttribute('data-satang') || '70000', 10);
                    }

                    function syncYearly() {
                        planField.value = 'yearly';
                        amountLabel.textContent = '8400';
                        if (periodLabel) periodLabel.textContent = 'รายปี';
                        satang = 840000;
                    }

                    function applyMode(mode) {
                        modeBtns.forEach(function (b) {
                            b.classList.toggle('active', b.getAttribute('data-mode') === mode);
                        });
                        if (mode === 'daily') {
                            setSectionVisibility(true);
                            return;
                        }
                        setSectionVisibility(false);
                        if (mode === 'yearly') {
                            if (monthlyCycles) monthlyCycles.setAttribute('hidden', 'hidden');
                            syncYearly();
                        } else {
                            if (monthlyCycles) monthlyCycles.removeAttribute('hidden');
                            var a = document.querySelector('.sub-cycle-btn.active');
                            syncFromCycleButton(a || cycleBtns[0]);
                        }
                    }

                    modeBtns.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            applyMode(btn.getAttribute('data-mode') || 'monthly');
                        });
                    });

                    cycleBtns.forEach(function (t) {
                        t.addEventListener('click', function () {
                            cycleBtns.forEach(function (x) { x.classList.remove('active'); });
                            t.classList.add('active');
                            if (document.querySelector('.sub-mode-btn.active') && document.querySelector('.sub-mode-btn.active').getAttribute('data-mode') === 'monthly') {
                                syncFromCycleButton(t);
                            }
                        });
                    });

                    if (dailyForm && dailyInput) {
                        dailyForm.addEventListener('submit', function (e) {
                            if (!isLoggedIn) {
                                return;
                            }
                            var n = parseInt(dailyInput.value, 10);
                            if (isNaN(n) || n < 20) {
                                e.preventDefault();
                                alert('กรุณาระบุจำนวนเงินอย่างน้อย 20 บาท');
                                dailyInput.focus();
                                return;
                            }
                        });
                    }

                    applyMode('monthly');

                    var form = document.getElementById('childSubForm');
                    var tok = document.getElementById('omiseTokenField');
                    var cardBtn = document.getElementById('btnChildSubscribe');
                    if (isLoggedIn && cardBtn && form && tok && typeof OmiseCard !== 'undefined') {
                        cardBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            OmiseCard.open({
                                amount: satang,
                                currency: 'THB',
                                defaultPaymentMethod: 'credit_card',
                                onCreateTokenSuccess: function (nonce) {
                                    if (typeof nonce === 'string' && nonce.indexOf('tokn_') === 0) {
                                        tok.value = nonce;
                                        form.submit();
                                    } else {
                                        alert('โทเค็นไม่ถูกต้อง กรุณาลองใหม่');
                                    }
                                }
                            });
                        });
                    }
                })();
                </script>
                <?php elseif ($role === 'donor' && !$donorShowcaseSponsored && !($canStartChildSub || !$isLoggedIn)): ?>
                <p class="text-muted mt-3">โปรไฟล์เด็กยังไม่อนุมัติหรือถูกซ่อน จึงยังไม่เปิดรับการสมัครอุปการะรายรอบ</p>
                <?php endif; ?>

                <?php else: ?>
                <h1 class="brand-header">ผลลัพธ์</h1>
                <?php if ($outcomeHasContent): ?>
                <div class="child-outcome-public child-outcome-public--tab">
                    <div class="child-outcome-public__label"><i class="bi bi-megaphone-fill" aria-hidden="true"></i> อัปเดตจากมูลนิธิ</div>
                    <div class="child-outcome-tab-layout">
                        <?php if ($impressionMainSrc): ?>
                        <div class="child-outcome-tab-layout__media">
                            <img src="<?php echo htmlspecialchars($impressionMainSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="child-outcome-tab-layout__img" loading="lazy" decoding="async">
                        </div>
                        <?php endif; ?>
                        <div class="child-outcome-tab-layout__content">
                            <?php if ($outcomePublic !== ''): ?>
                            <div class="child-outcome-public__text"><?php echo nl2br(htmlspecialchars($outcomePublic)); ?></div>
                            <?php else: ?>
                            <p class="child-outcome-public__placeholder mb-0">อยู่ในขั้นตอนดำเนินการ</p>
                            <?php endif; ?>
                            <p class="child-impression-card__attribution">น้อง<?php echo htmlspecialchars($child['child_name'] ?? ''); ?><?php echo $educationLabel !== '' ? ' · นักเรียนชั้น' . htmlspecialchars($educationLabel) : ''; ?></p>
                            <?php if (!empty($outcomeUpdatedAt)): ?>
                            <p class="child-outcome-public__meta">โพสต์เมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                            <?php endif; ?>
                            <?php if ($outcomeImageList !== [] && count($outcomeImageList) > 1): ?>
                            <?php echo drawdream_child_outcome_images_html(array_slice($outcomeImageList, 1)); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="child-outcome-public child-outcome-public--tab">
                    <div class="child-outcome-public__label"><i class="bi bi-megaphone-fill" aria-hidden="true"></i> ผลลัพธ์</div>
                    <p class="child-outcome-public__placeholder mb-0">กำลังดำเนินการ</p>
                    <p class="child-impression-card__attribution">น้อง<?php echo htmlspecialchars($child['child_name'] ?? ''); ?><?php echo $educationLabel !== '' ? ' · นักเรียนชั้น' . htmlspecialchars($educationLabel) : ''; ?></p>
                    <?php if (!empty($outcomeUpdatedAt)): ?>
                    <p class="child-outcome-public__meta">อัปเดตเมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($role === 'donor' && $hasActiveChildSub): ?>
                <div class="child-impression-card child-impression-card--subscriber-only mt-3">
                    <div class="child-subscription-manage">
                        <div class="child-subscription-manage__meta">
                            <div>แผนที่ใช้งาน: <strong><?php echo htmlspecialchars((string)$activePlanText); ?></strong></div>
                            <div>ยอดต่อรอบ: <strong><?php echo number_format((float)($activeChildSub['amount_thb'] ?? 0), 0); ?> บาท</strong></div>
                            <div>กำหนดตัดรอบถัดไป: <strong><?php echo htmlspecialchars($activeNextText); ?></strong></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php endif; ?>

</body>
</html>
