<?php
// children_donate.php — อุปการะเด็กแบบรายรอบ (Omise Charge Schedule) + Omise.js Token

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน children donate

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/foundation_account_verified.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/payment/config.php';
require_once __DIR__ . '/payment/omise_helpers.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/child_outcome_history.php';
require_once __DIR__ . '/includes/return_to.php';
require_once __DIR__ . '/includes/donate_type.php';

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (isset($_GET['notif_read']) && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/notification_audit.php';
    $notifReadId = (int)$_GET['notif_read'];
    if ($notifReadId > 0) {
        drawdream_notifications_mark_read($conn, (int)$_SESSION['user_id'], $notifReadId);
    }
}
$role = $_SESSION['role'] ?? 'donor';
$isLoggedIn = isset($_SESSION['user_id']) && (int)($_SESSION['user_id'] ?? 0) > 0;
$loginRequiredDonateMsg = 'กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้';
$childReturnTo = drawdream_return_to_path('children_donate.php', ['id' => $child_id]);
$loginRequiredDonateUrl = drawdream_login_url('login', $loginRequiredDonateMsg, $childReturnTo);
$prefillAmount = 0;
if (isset($_GET['amount'])) {
    $prefillAmount = (int)$_GET['amount'];
    if ($prefillAmount < 20) {
        $prefillAmount = 0;
    }
}
$isAdmin = ($role === 'admin');

$sql = "
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM foundation_children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ?
";
if (!$isAdmin) {
    $sql .= '';
}
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    require_once __DIR__ . '/includes/drawdream_user_error.php';
    drawdream_user_error_redirect('ไม่พบข้อมูลเด็กที่ระบุ', 'children_.php', 'children_donate missing child_id=' . $child_id);
}

// Donation stats + ยอดรอบเดือนในคิวรีเดียว (ลด round-trip ไป Aiven)
$donationStats = ['donor_count' => 0, 'total_amount' => 0, 'cycle_amount' => 0];
$childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
$cycleBounds = drawdream_child_current_cycle_bounds(drawdream_child_anchor_datetime($child));
$dtOne = DRAWDREAM_DONATE_TYPE_CHILD_ONE_TIME;
if ($cycleBounds !== null) {
    [$cycleEffectiveStart, $cycleMonthEnd] = $cycleBounds;
    $cycleStartStr = $cycleEffectiveStart->format('Y-m-d H:i:s');
    $cycleEndStr = $cycleMonthEnd->format('Y-m-d H:i:s');
    $stmtDs = $conn->prepare(
        "SELECT COUNT(DISTINCT donor_id) AS donor_count,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(
                    CASE
                        WHEN transfer_datetime >= ? AND transfer_datetime < ?
                             AND COALESCE(donate_type, '') <> ?
                        THEN amount ELSE 0
                    END
                ), 0) AS cycle_amount
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'"
    );
    $stmtDs->bind_param('sssii', $cycleStartStr, $cycleEndStr, $dtOne, $childCategoryId, $child_id);
} else {
    $stmtDs = $conn->prepare(
        "SELECT COUNT(DISTINCT donor_id) AS donor_count,
                COALESCE(SUM(amount), 0) AS total_amount,
                0 AS cycle_amount
         FROM donation
         WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'"
    );
    $stmtDs->bind_param('ii', $childCategoryId, $child_id);
}
$stmtDs->execute();
$dsRow = $stmtDs->get_result()->fetch_assoc();
if ($dsRow) {
    $donationStats = $dsRow;
}

// รายชื่อผู้บริจาค + สถานะแผนรายรอบ — เฉพาะมูลนิธิ/แอดมิน (ผู้บริจาคไม่ใช้ ลด query ไป Aiven)
$sponsorDisplayRows = [];
if ($role === 'foundation' || $role === 'admin') {
    $sponsorDisplayList = drawdream_child_foundation_sponsor_display_list($conn, $child_id, $childCategoryId);
    $sponsorDisplayRows = $sponsorDisplayList['rows'] ?? [];
}

$birthDateText = '-';
if (!empty($child['birth_date'] ?? '')) {
    $birthDateText = date('d/m/Y', strtotime($child['birth_date']));
}

$reviewStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
$reviewStatusLabel = $reviewStatus;

$childRejectReasonUi = '';
if ($reviewStatus === 'ไม่อนุมัติ') {
    $childRejectReasonUi = drawdream_foundation_child_profile_reject_reason_for_ui(
        $conn,
        $child_id,
        (string)($child['child_name'] ?? '')
    );
}

$cycleAmountCached = (float)($donationStats['cycle_amount'] ?? 0);
$cycleTargetAmount = drawdream_child_cycle_target_amount($conn, $child_id);
$coverageWindow = drawdream_child_plan_coverage_window($conn, $child_id);
$hasPlanCoverageNow = (bool)($coverageWindow['current'] ?? false);
$planCoverageMapOne = $hasPlanCoverageNow ? [$child_id => true] : [];
$planMapShowcase = drawdream_child_ids_with_active_plan_sponsorship($conn, [$child_id]);
$isCycleSponsored = $hasPlanCoverageNow
    || ($cycleTargetAmount > 0 && $cycleAmountCached >= $cycleTargetAmount);
$anyPlanSponsor = !empty($planMapShowcase[$child_id]);
$profileOpenForDonation = in_array(
    (string)($child['approve_profile'] ?? ''),
    ['อนุมัติ', 'กำลังดำเนินการ'],
    true
);

$canDonate = $profileOpenForDonation
    && !$anyPlanSponsor
    && !$isCycleSponsored;
$donorUid = (int)($_SESSION['user_id'] ?? 0);
$activeChildSub = null;
$hasActiveChildSub = false;
$canStartChildSub = false;
$recurringBlockedForDonor = $anyPlanSponsor;
if ($role === 'donor' && $donorUid > 0) {
    if ($anyPlanSponsor) {
        $recurringBlockedForDonor = true;
    } else {
        $reserveHolder = drawdream_child_subscription_reserving_holder_user_id($conn, $child_id);
        $recurringBlockedForDonor = $reserveHolder > 0 && $reserveHolder !== $donorUid;
    }
    $donorSubStatus = drawdream_child_donor_latest_subscription_status($conn, $child_id, $donorUid);
    $hasActiveChildSub = ($donorSubStatus === 'active');
    if ($hasActiveChildSub) {
        $activeChildSub = drawdream_donor_active_child_subscription_for_child($conn, $donorUid, $child_id);
        if (!is_array($activeChildSub)) {
            $hasActiveChildSub = false;
        }
    }
    $canStartChildSub = $profileOpenForDonation
        && !$anyPlanSponsor
        && !$isCycleSponsored
        && !$hasActiveChildSub;
}

// หลังอุปการะสำเร็จแต่ยังไปหน้าเดิม (sub_ok) — พาไปใบเสร็จทันที ไม่ค้างแค่ Swal
if (
    $donorUid > 0
    && $child_id > 0
    && isset($_GET['sub_ok'])
    && (string)$_GET['sub_ok'] === '1'
    && !isset($_GET['success'])
) {
    require_once __DIR__ . '/includes/e_receipt.php';
    $stSubReceipt = $conn->prepare(
        "SELECT donate_id FROM donation
         WHERE donor_id = ? AND target_id = ?
           AND payment_status = 'completed'
           AND LOWER(TRIM(COALESCE(donate_type, ''))) IN ('child_subscription', 'child_subscription_charge')
         ORDER BY donate_id DESC
         LIMIT 1"
    );
    if ($stSubReceipt) {
        $stSubReceipt->bind_param('ii', $donorUid, $child_id);
        $stSubReceipt->execute();
        $subReceiptRow = $stSubReceipt->get_result()->fetch_assoc();
        $subDonateId = (int)($subReceiptRow['donate_id'] ?? 0);
        if ($subDonateId > 0 && drawdream_donation_eligible_for_e_receipt($conn, $subDonateId)) {
            $subDetail = trim((string)($_GET['sub_msg'] ?? ''));
            $receiptUrl = drawdream_payment_success_receipt_query(
                $subDonateId,
                'อุปการะสำเร็จ',
                'children_donate.php?id=' . $child_id,
                $subDetail
            );
            if ($receiptUrl !== '') {
                header('Location: ' . $receiptUrl);
                exit;
            }
        }
    }
}

$donorShowcaseSponsored = drawdream_child_is_showcase_sponsored(
    $conn,
    $child_id,
    $child,
    (float)($donationStats['cycle_amount'] ?? 0),
    $planMapShowcase,
    $planCoverageMapOne
);
$latestCancelledSubAny = null;
$latestCancelledSubForDonor = null;
$planLabelMap = ['monthly' => 'รายเดือน', 'semiannual' => 'ราย 6 เดือน', 'yearly' => 'รายปี'];
$cycleMonthLabel = date('m/Y');
// ประวัติยกเลิกอุปการะ — เฉพาะผู้บริจาค/มูลนิธิที่ใช้แสดงแท็บผลลัพธ์ (ลด query สำหรับแอดมินตรวจโปรไฟล์)
$needCancelledSubHistory = ($role === 'donor' && $donorUid > 0)
    || $role === 'foundation'
    || ($role === 'admin' && isset($_GET['view']) && (string)$_GET['view'] === 'outcome');
if ($needCancelledSubHistory) {
    $stCancelledHistory = $conn->prepare(
        "SELECT donor_user_id, recurring_plan_code AS plan_code,
                recurring_next_charge_at AS last_charge_at, created_at
         FROM child_subscription_history
         WHERE child_id = ? AND LOWER(TRIM(current_status)) IN ('cancelled','cancle','canceled')
         ORDER BY history_id DESC
         LIMIT 12"
    );
    if ($stCancelledHistory) {
        $stCancelledHistory->bind_param('i', $child_id);
        $stCancelledHistory->execute();
        $cancelRows = $stCancelledHistory->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($cancelRows as $cancelRow) {
            if ($latestCancelledSubAny === null) {
                $latestCancelledSubAny = $cancelRow;
            }
            if ($latestCancelledSubForDonor === null
                && $role === 'donor'
                && $donorUid > 0
                && (int)($cancelRow['donor_user_id'] ?? 0) === $donorUid) {
                $latestCancelledSubForDonor = $cancelRow;
            }
            if ($latestCancelledSubAny !== null
                && ($latestCancelledSubForDonor !== null || $role !== 'donor' || $donorUid <= 0)) {
                break;
            }
        }
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
// $coverageWindow คำนวณไว้แล้วด้านบน
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
/** บริจาครายวัน (PromptPay): ขั้นต่ำ 20 บาท ไม่จำกัดยอดสูงสุดต่อครั้ง — เปิดได้แม้มีผู้อุปการะรายรอบแล้ว */
$dailyCanDonate = $profileOpenForDonation;
$childHasPlanSponsor = $donorShowcaseSponsored || $hasActiveChildSub || $anyPlanSponsor || $recurringBlockedForDonor;
/** ล็อกแท็บรายเดือน/รายปีสำหรับผู้บริจาคอื่น — ไม่แสดง "มีผู้อุปการะแล้ว" กับผู้อุปการะคนปัจจุบัน */
$childRecurringLockedForViewer = !$hasActiveChildSub
    && ($anyPlanSponsor || $recurringBlockedForDonor || $donorShowcaseSponsored);
$childSubFormHidden = $childRecurringLockedForViewer || $hasActiveChildSub;
$showDonorDonationBox = ($role === 'donor')
    && ($canStartChildSub || !$isLoggedIn || ($childHasPlanSponsor && $dailyCanDonate));
$sponsorshipLabel = $isCycleSponsored ? 'อุปการะแล้ว' : 'รออุปการะ';
$foundationCanUpdateOutcome = ($role === 'foundation')
    && drawdream_foundation_account_is_verified($conn)
    && (
        ($isCycleSponsored && in_array((string)($child['approve_profile'] ?? ''), ['อนุมัติ', 'กำลังดำเนินการ'], true))
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
$letterParam = trim((string)($_GET['letter'] ?? ''));
$donorIncomingLetter = ($role === 'donor' && in_array($letterParam, ['1', 'open'], true));

$childView = (string)($_GET['view'] ?? 'sponsor');
if (!in_array($childView, ['sponsor', 'outcome'], true)) {
    $childView = 'sponsor';
}
if ($donorIncomingLetter) {
    $childView = 'outcome';
}
$showOutcomeTab = $donorShowcaseSponsored || $outcomeHasContent || $hasCancelledSubHistory;
if ($donorIncomingLetter) {
    $showOutcomeTab = true;
}
if (!$showOutcomeTab) {
    $childView = 'sponsor';
}
$childOutcomeHeading = 'ข้อความจากน้อง' . trim((string)($child['child_name'] ?? ''));
$childOutcomeHistory = [];
if ($childView === 'outcome' && ($showOutcomeTab || $role === 'foundation' || $role === 'admin')) {
    $childOutcomeHistory = drawdream_child_outcome_history_list($child_id);
}
/** เปิดจากแจ้งเตือนจดหมาย — แสดงกระดาษข้อความทันที (ไม่มีซอง) */
$donorLetterExperience = $donorIncomingLetter && $childView === 'outcome';

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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="css/navbar.css">
    <?php require_once __DIR__ . '/includes/vendor_assets.php'; drawdream_foundation_page_assets_head(); ?>
    <link rel="stylesheet" href="css/children.css?v=57">
    <?php if ($isAdmin): ?>
    <link rel="stylesheet" href="css/admin_directory.css">
    <?php endif; ?>
    <?php require_once __DIR__ . '/includes/vendor_assets.php'; echo drawdream_sweetalert2_js_tag('', false); ?>
    <script src="js/drawdream-swal.js?v=1"></script>
</head>
<body class="<?php
    echo $isAdmin ? 'admin-child-review-page' : '';
    echo $donorLetterExperience ? ' child-letter-notif-view' : '';
?>">

<?php include 'navbar.php'; ?>

<?php if (!empty($_GET['msg'] ?? '')): ?>
    <?php echo drawdream_sweetalert2_js_tag('', true); ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({ icon: 'info', title: <?php echo json_encode((string)$_GET['msg'], JSON_UNESCAPED_UNICODE); ?>, confirmButtonText: 'ตกลง' });
    });
    </script>
<?php endif; ?>
<?php if (isset($_GET['sub_msg']) && $_GET['sub_msg'] !== ''): ?>
    <?php echo drawdream_sweetalert2_js_tag('', true); ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            icon: <?php echo (!empty($_GET['sub_ok'])) ? "'success'" : "'error'"; ?>,
            title: <?php echo json_encode((string)$_GET['sub_msg'], JSON_UNESCAPED_UNICODE); ?>,
            confirmButtonText: 'ตกลง'
        });
    });
    </script>
<?php endif; ?>

<?php if ($isAdmin): ?>
<main class="container-fluid my-4 admin-child-review-main">
    <div class="admin-review-card">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบโปรไฟล์เด็ก</h4>
                <div>มูลนิธิ: <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></div>
            </div>
        </div>

        <div class="admin-review-body">
            <div class="admin-review-layout">
                <div class="admin-image-col">
                    <img src="uploads/childern/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile" class="admin-child-image">
                </div>

                <div class="admin-details-col">
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
                            <span class="value"><?php echo htmlspecialchars($childRejectReasonUi !== '' ? $childRejectReasonUi : '-'); ?></span>
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
                                <div class="stat-label">ทุนการศึกษา (บริจาครายวัน)</div>
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
                        <?= drawdream_csrf_field() ?>
                        <input type="hidden" name="id" value="<?php echo (int)$child_id; ?>">
                        <input type="hidden" name="return" value="admin_notifications.php#admin-pending-children">
                        <div class="admin-review-actions-grid">
                            <textarea name="reject_reason" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ"></textarea>
                            <button type="submit" name="action" value="approve" class="btn btn-success admin-review-action-btn"
                                    onclick="return confirm('ยืนยันอนุมัติโปรไฟล์เด็กคนนี้?');">อนุมัติ</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger admin-review-action-btn"
                                    onclick="var t=this.form.querySelector('[name=reject_reason]');if(!t||!t.value.trim()){drawdreamAlert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ');if(t)t.focus();return false;}return confirm('ยืนยันไม่อนุมัติโปรไฟล์เด็กคนนี้?');">ไม่อนุมัติ</button>
                        </div>
                    </form>
                    <?php endif; ?>

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
            <a href="children_donate.php?id=<?php echo (int)$child['child_id']; ?>&view=outcome" class="profile-label-tab<?php echo $childView === 'outcome' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $childView === 'outcome' ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($childOutcomeHeading); ?></a>
            <?php endif; ?>
        </div>

        <div class="profile-inner">
            <div class="col-left">
                <div class="child-img-container child-img-container--with-back">
                    <a href="children_.php" class="donor-flow-back-circle" aria-label="กลับรายชื่อเด็ก"><span aria-hidden="true">←</span></a>
                    <img src="uploads/childern/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile" width="320" height="400" decoding="async" fetchpriority="high">
                </div>
                <?php if ($role === 'donor' && $hasActiveChildSub): ?>
                <form method="post" action="payment/child_subscription_cancel.php" class="child-cancel-below-photo-form js-confirm-cancel-sub" data-child-name="<?php echo htmlspecialchars((string)($child['child_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= drawdream_csrf_field() ?>
                    <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                    <button type="submit" class="btn-subscription-cancel btn-subscription-cancel--compact">ยกเลิกอุปการะเด็กคนนี้</button>
                </form>
                <?php endif; ?>
                <div class="child-details">
                    <p><strong>ชื่อ</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>มูลนิธิ</strong> <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></p>
                    <p><strong>วันเกิด</strong> <?php echo htmlspecialchars($birthDateText); ?></p>
                    <p><strong>ชั้น</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ</strong> <?php echo (int)$child['age']; ?> ปี</p>
                    <p><strong>อาชีพในฝัน</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
                    <?php if (($role === 'foundation' || $role === 'admin') && $reviewStatus === 'ไม่อนุมัติ' && $childRejectReasonUi !== ''): ?>
                    <p style="color:#b32525;"><strong>เหตุผลไม่อนุมัติ:</strong> <?php echo htmlspecialchars($childRejectReasonUi); ?></p>
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
                            <div class="stat-label">ทุนการศึกษา (บริจาครายวัน)</div>
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

                <?php if ($showDonorDonationBox): ?>
                <div class="child-subscription-box mt-2">
                    <?php if (drawdream_omise_is_test_mode()): ?>
                    <div class="child-omise-test-hint" role="note">
                        <strong>โหมดทดสอบ Omise</strong> — กดบริจาคแล้วระบบใส่เลขบัตรทดสอบให้อัตโนมัติ
                        (<span class="child-omise-test-hint__mono">4242…</span>, <span class="child-omise-test-hint__mono">12/29</span>, CVV <span class="child-omise-test-hint__mono">123</span>)
                        กรอกแค่<strong>ชื่อบนบัตร</strong>แล้วกด Pay
                    </div>
                    <?php endif; ?>
                    <div class="sub-plan-grid" role="tablist" aria-label="เลือกรูปแบบการบริจาค">
                        <button type="button" class="sub-plan-btn sub-mode-btn<?php echo $childHasPlanSponsor ? ' active' : ''; ?>" data-mode="daily">รายวัน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn<?php echo $childHasPlanSponsor ? '' : ' active'; ?>" data-mode="monthly">รายเดือน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn" data-mode="yearly">รายปี</button>
                    </div>

                    <div id="subSectionDaily" class="sub-section<?php echo $childHasPlanSponsor ? '' : ' sub-section--hidden'; ?>"<?php echo $childHasPlanSponsor ? '' : ' hidden'; ?>>
                        <form id="childDailyForm" method="post" action="<?php echo $isLoggedIn ? 'payment/child_donate.php' : htmlspecialchars($loginRequiredDonateUrl, ENT_QUOTES, 'UTF-8'); ?>" class="child-daily-form">
                            <?= $isLoggedIn ? drawdream_csrf_field() : '' ?>
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <?php if ($isLoggedIn): ?>
                            <input type="hidden" name="pay" value="1">
                            <?php endif; ?>
                            <label class="visually-hidden" for="dailyAmountInput">จำนวนเงินบาท (ขั้นต่ำ 20)</label>
                            <input type="number" name="amount" id="dailyAmountInput" class="sub-daily-amount-input" min="20" step="1" inputmode="numeric" placeholder="<?php echo $dailyCanDonate ? 'ระบุจำนวนเงิน (ขั้นต่ำ 20 บาท)' : 'ไม่เปิดรับบริจาคในรอบนี้'; ?>" value="<?php echo $prefillAmount > 0 ? (int)$prefillAmount : ''; ?>" required autocomplete="off" <?php echo $dailyCanDonate ? '' : 'disabled'; ?>>
                            <div class="payment-method child-daily-payment-method">
                                <div class="method-card active" aria-label="ชำระด้วย PromptPay QR ผ่าน Omise">
                                    <img src="<?php echo htmlspecialchars($qrIconSrc); ?>" alt="" class="method-icon" width="30" height="30" decoding="async">
                                    <span>PromptPay QR</span>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit-donation btn-submit-donation--sub" <?php echo $dailyCanDonate ? '' : 'disabled'; ?> title="<?php echo $dailyCanDonate ? '' : 'ไม่เปิดรับบริจาคในรอบนี้'; ?>">บริจาค</button>
                        </form>
                    </div>

                    <div id="subSectionCard" class="sub-section<?php echo $childHasPlanSponsor ? ' sub-section--hidden' : ''; ?>"<?php echo $childHasPlanSponsor ? ' hidden' : ''; ?>>
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
                        <form id="childSubForm" method="post" action="<?php echo $isLoggedIn ? 'payment/child_subscription_create.php' : htmlspecialchars($loginRequiredDonateUrl, ENT_QUOTES, 'UTF-8'); ?>" class="child-sub-form"<?php echo $childSubFormHidden ? ' hidden' : ''; ?>>
                            <?= $isLoggedIn ? drawdream_csrf_field() : '' ?>
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <input type="hidden" name="plan" id="subPlanField" value="monthly">
                            <input type="hidden" name="omiseToken" id="omiseTokenField" value="">
                            <button type="<?php echo $isLoggedIn ? 'button' : 'submit'; ?>" class="btn-submit-donation btn-submit-donation--sub" id="btnChildSubscribe">บริจาค</button>
                        </form>
                        <?php if ($childRecurringLockedForViewer): ?>
                        <button type="button" class="btn-submit-donation btn-submit-donation--sub" id="btnChildSponsorLocked" hidden disabled aria-disabled="true" title="เด็กคนนี้มีผู้อุปการะแล้ว">มีผู้อุปการะแล้ว</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isLoggedIn && $showDonorDonationBox): ?>
                <div class="drawdream-omise-modal" id="childSubCardModal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="childSubCardModalTitle">
                    <div class="drawdream-omise-modal__backdrop" data-close-card-modal tabindex="-1"></div>
                    <div class="drawdream-omise-modal__panel">
                        <div class="drawdream-omise-modal__topbar">
                            <div class="drawdream-omise-modal__brand">
                                <span class="drawdream-omise-modal__brand-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </span>
                                <div class="drawdream-omise-modal__brand-text">
                                    <div class="drawdream-omise-modal__brand-name">Omise</div>
                                    <div class="drawdream-omise-modal__brand-secure">Secured by Omise</div>
                                </div>
                            </div>
                            <button type="button" class="drawdream-omise-modal__close" data-close-card-modal aria-label="ปิด">&times;</button>
                        </div>
                        <h2 class="drawdream-omise-modal__section-title" id="childSubCardModalTitle">Credit / Debit</h2>
                        <div class="drawdream-omise-modal__fields">
                            <div class="drawdream-omise-modal__field drawdream-omise-modal__field--card">
                                <input type="text" id="childSubCardNumber" class="drawdream-omise-modal__input" autocomplete="cc-number" inputmode="numeric" placeholder=" ">
                                <label class="drawdream-omise-modal__float-label" for="childSubCardNumber">Card number</label>
                                <span class="drawdream-omise-modal__card-brand" id="childSubCardBrand" aria-hidden="true">VISA</span>
                            </div>
                            <div class="drawdream-omise-modal__field">
                                <input type="text" id="childSubCardName" class="drawdream-omise-modal__input" autocomplete="cc-name" placeholder=" " inputmode="text">
                                <label class="drawdream-omise-modal__float-label" for="childSubCardName">Name on card</label>
                            </div>
                            <div class="drawdream-omise-modal__row">
                                <div class="drawdream-omise-modal__field drawdream-omise-modal__col">
                                    <input type="text" id="childSubCardExp" class="drawdream-omise-modal__input" autocomplete="cc-exp" inputmode="numeric" placeholder=" " maxlength="5">
                                    <label class="drawdream-omise-modal__float-label" for="childSubCardExp">Expiry date</label>
                                </div>
                                <div class="drawdream-omise-modal__field drawdream-omise-modal__col drawdream-omise-modal__field--cvv">
                                    <input type="text" id="childSubCardCvv" class="drawdream-omise-modal__input" autocomplete="cc-csc" inputmode="numeric" placeholder=" " maxlength="4">
                                    <label class="drawdream-omise-modal__float-label" for="childSubCardCvv">Security code</label>
                                    <span class="drawdream-omise-modal__cvv-help" title="รหัส 3 หลักหลังบัตร" aria-hidden="true">?</span>
                                </div>
                            </div>
                            <div class="drawdream-omise-modal__field drawdream-omise-modal__field--select">
                                <select id="childSubCardCountry" class="drawdream-omise-modal__input drawdream-omise-modal__select" disabled aria-readonly="true">
                                    <option selected>Thailand</option>
                                </select>
                                <label class="drawdream-omise-modal__float-label" for="childSubCardCountry">Country or region</label>
                                <span class="drawdream-omise-modal__select-chevron" aria-hidden="true"></span>
                            </div>
                        </div>
                        <button type="button" class="drawdream-omise-modal__pay" id="childSubCardModalPay">
                            Pay <span id="childSubCardModalAmt">700.00</span> THB
                        </button>
                    </div>
                </div>
                <div class="drawdream-pay-loading" id="childSubPayLoading" hidden aria-hidden="true" role="status" aria-live="polite">
                    <div class="drawdream-pay-loading__panel">
                        <div class="drawdream-pay-loading__spinner" aria-hidden="true"></div>
                        <p class="drawdream-pay-loading__title">กำลังดำเนินการชำระเงิน</p>
                        <p class="drawdream-pay-loading__hint">กรุณารอสักครู่ อย่าปิดหน้านี้</p>
                    </div>
                </div>
                <?php endif; ?>
                <script>
                (function () {
                    var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
                    var childId = <?php echo (int)$child_id; ?>;
                    var loginRequiredMsg = <?php echo json_encode($loginRequiredDonateMsg, JSON_UNESCAPED_UNICODE); ?>;
                    var planSponsorLocked = <?php echo $childRecurringLockedForViewer ? 'true' : 'false'; ?>;
                    var preferDailyDonateTab = <?php echo $childHasPlanSponsor ? 'true' : 'false'; ?>;
                    var viewerHasActiveChildSub = <?php echo $hasActiveChildSub ? 'true' : 'false'; ?>;
                    var pk = <?php echo json_encode(OMISE_PUBLIC_KEY, JSON_UNESCAPED_UNICODE); ?>;
                    var omiseLoadPromise = null;
                    function ensureOmiseReady(done) {
                        if (typeof Omise !== 'undefined' && typeof Omise.setPublicKey === 'function') {
                            Omise.setPublicKey(pk);
                            done();
                            return;
                        }
                        if (!omiseLoadPromise) {
                            omiseLoadPromise = new Promise(function (resolve, reject) {
                                var s = document.createElement('script');
                                s.src = 'https://cdn.omise.co/omise.js';
                                s.async = true;
                                s.onload = function () {
                                    if (typeof Omise !== 'undefined' && typeof Omise.setPublicKey === 'function') {
                                        Omise.setPublicKey(pk);
                                        resolve();
                                        return;
                                    }
                                    reject(new Error('omise'));
                                };
                                s.onerror = function () { reject(new Error('omise')); };
                                document.head.appendChild(s);
                            });
                        }
                        omiseLoadPromise.then(done).catch(function () {
                            drawdreamAlert('ระบบชำระเงินยังโหลดไม่สมบูรณ์ กรุณารีเฟรชหน้า');
                        });
                    }
                    if (!planSponsorLocked) {
                        var preloadOmise = function () {
                            ensureOmiseReady(function () {});
                        };
                        if (typeof requestIdleCallback === 'function') {
                            requestIdleCallback(preloadOmise, { timeout: 4000 });
                        } else {
                            window.setTimeout(preloadOmise, 1800);
                        }
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
                    var subForm = document.getElementById('childSubForm');
                    var lockedBtn = document.getElementById('btnChildSponsorLocked');
                    var satang = 70000;
                    var cardModal = document.getElementById('childSubCardModal');
                    var cardModalPay = document.getElementById('childSubCardModalPay');
                    var cardModalAmt = document.getElementById('childSubCardModalAmt');
                    var payLoading = document.getElementById('childSubPayLoading');
                    var payLoadingTitle = payLoading ? payLoading.querySelector('.drawdream-pay-loading__title') : null;
                    var payLoadingHint = payLoading ? payLoading.querySelector('.drawdream-pay-loading__hint') : null;
                    var omiseTestMode = <?php echo drawdream_omise_is_test_mode() ? 'true' : 'false'; ?>;
                    var childSubPaySubmitting = false;
                    var omiseTestCardDefaults = {
                        number: '4242 4242 4242 4242',
                        expiry: '12/29',
                        cvv: '123'
                    };

                    function showChildSubPayLoading(title, hint) {
                        if (payLoadingTitle && title) {
                            payLoadingTitle.textContent = title;
                        }
                        if (payLoadingHint && hint) {
                            payLoadingHint.textContent = hint;
                        }
                        if (payLoading) {
                            payLoading.hidden = false;
                            payLoading.setAttribute('aria-hidden', 'false');
                        }
                        document.body.classList.add('drawdream-pay-loading-open');
                    }

                    function hideChildSubPayLoading() {
                        if (payLoading) {
                            payLoading.hidden = true;
                            payLoading.setAttribute('aria-hidden', 'true');
                        }
                        document.body.classList.remove('drawdream-pay-loading-open');
                        if (payLoadingTitle) {
                            payLoadingTitle.textContent = 'กำลังดำเนินการชำระเงิน';
                        }
                        if (payLoadingHint) {
                            payLoadingHint.textContent = 'กรุณารอสักครู่ อย่าปิดหน้านี้';
                        }
                    }

                    function resetChildSubPayUi() {
                        childSubPaySubmitting = false;
                        hideChildSubPayLoading();
                        cardBtn.disabled = false;
                        if (cardModalPay) {
                            cardModalPay.disabled = false;
                        }
                    }

                    function buildChildSubReceiptUrl(data) {
                        if (!data) {
                            return '';
                        }
                        var url = data.redirect ? String(data.redirect).trim() : '';
                        if (url.indexOf('donation_receipt.php') >= 0) {
                            return url.split('#')[0];
                        }
                        var donateId = parseInt(String(data.donate_id || '0'), 10);
                        if (donateId > 0) {
                            return 'donation_receipt.php?donate_id=' + encodeURIComponent(String(donateId))
                                + '&success=1&success_title=' + encodeURIComponent('อุปการะสำเร็จ')
                                + '&return=' + encodeURIComponent('children_donate.php?id=' + childId);
                        }
                        return '';
                    }

                    function goChildSubReceipt(data) {
                        var url = buildChildSubReceiptUrl(data);
                        if (!url) {
                            return false;
                        }
                        if (typeof closeChildSubCardModal === 'function') {
                            closeChildSubCardModal();
                        }
                        hideChildSubPayLoading();
                        document.body.classList.remove('drawdream-omise-modal-open');
                        document.body.classList.remove('drawdream-pay-loading-open');
                        window.location.href = url;
                        return true;
                    }

                    function submitChildSubscriptionForm(tokenId) {
                        if (!form || childSubPaySubmitting) {
                            return;
                        }
                        childSubPaySubmitting = true;
                        showChildSubPayLoading('กำลังบันทึกการอุปการะ', 'เชื่อมต่อ Omise และบันทึกข้อมูล…');
                        var fd = new FormData(form);
                        fd.set('omiseToken', tokenId);
                        fd.append('ajax', '1');
                        var payTimer = window.setTimeout(function () {
                            if (payLoadingHint) {
                                payLoadingHint.textContent = 'ใช้เวลานานกว่าปกติ กรุณารอต่ออีกสักครู่…';
                            }
                        }, 12000);
                        fetch(form.action, {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' }
                        })
                            .then(function (res) {
                                return res.json().catch(function () { return null; }).then(function (data) {
                                    return { res: res, data: data };
                                });
                            })
                            .then(function (pack) {
                                window.clearTimeout(payTimer);
                                var data = pack.data;
                                if (data && data.ok === false) {
                                    resetChildSubPayUi();
                                    drawdreamAlert((data.message) ? data.message : 'ไม่สามารถบันทึกการอุปการะได้ กรุณาลองใหม่');
                                    return;
                                }
                                if (data && data.ok !== false && (data.status === 'success' || data.redirect || data.donate_id)) {
                                    if (goChildSubReceipt(data)) {
                                        return;
                                    }
                                }
                                resetChildSubPayUi();
                                drawdreamAlert((data && data.message) ? data.message : 'ไม่สามารถบันทึกการอุปการะได้ กรุณาลองใหม่');
                            })
                            .catch(function () {
                                window.clearTimeout(payTimer);
                                resetChildSubPayUi();
                                drawdreamAlert('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองใหม่');
                            });
                    }

                    function formatModalAmount(st) {
                        return (st / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }

                    function syncModalAmountLabel() {
                        if (cardModalAmt) {
                            cardModalAmt.textContent = formatModalAmount(satang);
                        }
                    }

                    function openChildSubCardModal() {
                        if (!cardModal) {
                            return;
                        }
                        syncModalAmountLabel();
                        var numEl = document.getElementById('childSubCardNumber');
                        var nameEl = document.getElementById('childSubCardName');
                        var expEl = document.getElementById('childSubCardExp');
                        var cvvEl = document.getElementById('childSubCardCvv');
                        if (omiseTestMode) {
                            if (numEl) {
                                numEl.value = omiseTestCardDefaults.number;
                                formatCardNumberInput(numEl);
                            }
                            if (expEl) {
                                expEl.value = omiseTestCardDefaults.expiry;
                            }
                            if (cvvEl) {
                                cvvEl.value = omiseTestCardDefaults.cvv;
                            }
                            if (nameEl) {
                                nameEl.value = '';
                            }
                        } else {
                            if (numEl) numEl.value = '';
                            if (nameEl) nameEl.value = '';
                            if (expEl) expEl.value = '';
                            if (cvvEl) cvvEl.value = '';
                            var brandEl = document.getElementById('childSubCardBrand');
                            if (brandEl) brandEl.classList.remove('is-visible');
                        }
                        cardModal.hidden = false;
                        cardModal.setAttribute('aria-hidden', 'false');
                        document.body.classList.add('drawdream-omise-modal-open');
                        applySlotCheckToPayButton();
                        var focusEl = (omiseTestMode && nameEl) ? nameEl : numEl;
                        if (focusEl) {
                            focusEl.focus();
                        }
                    }

                    function formatCardNumberInput(el) {
                        if (!el) return;
                        var digits = digitsOnly(el.value).slice(0, 19);
                        var parts = [];
                        for (var i = 0; i < digits.length; i += 4) {
                            parts.push(digits.slice(i, i + 4));
                        }
                        el.value = parts.join(' ');
                        var brandEl = document.getElementById('childSubCardBrand');
                        if (brandEl) {
                            brandEl.classList.toggle('is-visible', digits.charAt(0) === '4' && digits.length >= 1);
                        }
                    }

                    function formatCardExpiryInput(el) {
                        if (!el) return;
                        var digits = digitsOnly(el.value).slice(0, 4);
                        if (digits.length >= 3) {
                            var month = parseInt(digits.slice(0, 2), 10);
                            if (month === 0) {
                                digits = '01' + digits.slice(2);
                            }
                        }
                        if (digits.length >= 3) {
                            el.value = digits.slice(0, 2) + '/' + digits.slice(2);
                        } else {
                            el.value = digits;
                        }
                    }

                    function validateCardExpiry(raw) {
                        var s = String(raw || '').replace(/\s/g, '');
                        if (!s) {
                            return { ok: false, message: 'กรุณากรอกวันหมดอายุ MM/YY (เช่น 04/29)' };
                        }
                        if (s.indexOf('/') === -1) {
                            return { ok: false, message: 'กรุณากรอกวันหมดอายุแบบ MM/YY (เช่น 04/29)' };
                        }
                        var parts = s.split('/');
                        if (parts[1] === undefined || parts[1] === '') {
                            return { ok: false, message: 'กรุณากรอกปีหมดอายุ 2 หลัก (เช่น 04/29)' };
                        }
                        var month = parseInt(parts[0], 10);
                        var year = parseInt(parts[1], 10);
                        if (isNaN(month) || isNaN(year)) {
                            return { ok: false, message: 'วันหมดอายุไม่ถูกต้อง กรุณาใช้รูปแบบ MM/YY (เช่น 04/29)' };
                        }
                        if (month < 1 || month > 12) {
                            return { ok: false, message: 'เดือนหมดอายุต้องเป็น 01–12 (คุณใส่ ' + parts[0] + ') — ตัวอย่าง: 04/29' };
                        }
                        if (parts[1].length < 2) {
                            return { ok: false, message: 'กรุณากรอกปีหมดอายุ 2 หลัก (เช่น 04/29)' };
                        }
                        if (year < 100) {
                            year += 2000;
                        }
                        return { ok: true, exp: { month: month, year: year } };
                    }

                    (function bindCardModalInputs() {
                        var numEl = document.getElementById('childSubCardNumber');
                        var expEl = document.getElementById('childSubCardExp');
                        var cvvEl = document.getElementById('childSubCardCvv');
                        if (numEl) {
                            numEl.addEventListener('input', function () { formatCardNumberInput(numEl); });
                        }
                        if (expEl) {
                            expEl.addEventListener('input', function () { formatCardExpiryInput(expEl); });
                        }
                        if (cvvEl) {
                            cvvEl.addEventListener('input', function () {
                                cvvEl.value = digitsOnly(cvvEl.value).slice(0, 4);
                            });
                        }
                    })();

                    function closeChildSubCardModal() {
                        if (!cardModal) {
                            return;
                        }
                        cardModal.hidden = true;
                        cardModal.setAttribute('aria-hidden', 'true');
                        document.body.classList.remove('drawdream-omise-modal-open');
                    }

                    if (cardModal) {
                        cardModal.querySelectorAll('[data-close-card-modal]').forEach(function (el) {
                            el.addEventListener('click', closeChildSubCardModal);
                        });
                        document.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Escape' && !cardModal.hidden) {
                                closeChildSubCardModal();
                            }
                        });
                    }

                    function buildChildDonateLoginUrl(amount) {
                        var path = 'children_donate.php?id=' + childId;
                        var amt = parseInt(String(amount || ''), 10);
                        if (!isNaN(amt) && amt >= 20) {
                            path += '&amount=' + amt;
                        }
                        return 'login.php?page=login&return_to=' + encodeURIComponent(path)
                            + '&error=' + encodeURIComponent(loginRequiredMsg);
                    }

                    if (!isLoggedIn && dailyForm) {
                        dailyForm.addEventListener('submit', function (e) {
                            e.preventDefault();
                            window.location.href = buildChildDonateLoginUrl(dailyInput ? dailyInput.value : '');
                        });
                    }

                    if (!isLoggedIn && subForm) {
                        subForm.addEventListener('submit', function (e) {
                            e.preventDefault();
                            window.location.href = buildChildDonateLoginUrl(null);
                        });
                    }

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
                        syncModalAmountLabel();
                    }

                    function syncYearly() {
                        planField.value = 'yearly';
                        amountLabel.textContent = '8400';
                        if (periodLabel) periodLabel.textContent = 'รายปี';
                        satang = 840000;
                        syncModalAmountLabel();
                    }

                    function syncSubscriptionLockedUi(mode) {
                        var recurringLocked = planSponsorLocked && mode !== 'daily';
                        cycleBtns.forEach(function (x) {
                            x.disabled = recurringLocked;
                        });
                        if (subForm) {
                            subForm.hidden = recurringLocked;
                        }
                        if (lockedBtn) {
                            lockedBtn.hidden = !recurringLocked;
                        }
                    }

                    function applyMode(mode) {
                        modeBtns.forEach(function (b) {
                            b.classList.toggle('active', b.getAttribute('data-mode') === mode);
                        });
                        if (mode === 'daily') {
                            setSectionVisibility(true);
                            syncSubscriptionLockedUi(mode);
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
                        syncSubscriptionLockedUi(mode);
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
                                drawdreamAlert('กรุณาระบุจำนวนเงินอย่างน้อย 20 บาท');
                                dailyInput.focus();
                                return;
                            }
                        });
                    }

                    applyMode(preferDailyDonateTab ? 'daily' : 'monthly');

                    var form = document.getElementById('childSubForm');
                    var tok = document.getElementById('omiseTokenField');
                    var cardBtn = document.getElementById('btnChildSubscribe');
                    var childIdForSlot = <?php echo (int)$child['child_id']; ?>;
                    var slotCheckOk = <?php echo ($canStartChildSub && !$childRecurringLockedForViewer) ? 'true' : 'null'; ?>;

                    function applySlotCheckToPayButton() {
                        if (!cardModalPay) {
                            return;
                        }
                        cardModalPay.disabled = slotCheckOk !== true;
                    }

                    function fetchChildSponsorSlotStatus() {
                        var url = 'payment/child_sponsorship_slot_check.php?child_id='
                            + encodeURIComponent(String(childIdForSlot));
                        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                slotCheckOk = !!(data && data.can_subscribe);
                                return { ok: slotCheckOk, data: data };
                            })
                            .catch(function () {
                                slotCheckOk = false;
                                return { ok: false, data: null };
                            });
                    }

                    function handleSlotUnavailable(data) {
                        var msg = (data && data.message)
                            ? data.message
                            : 'ไม่สามารถสมัครอุปการะได้ในขณะนี้';
                        drawdreamAlert(msg);
                        if (data && (data.reason === 'taken' || data.reason === 'reserving')) {
                            window.location.reload();
                        }
                    }

                    function refreshChildSponsorSlotUi() {
                        fetchChildSponsorSlotStatus().then(function (result) {
                            if (!result.ok
                                && result.data
                                && result.data.reason !== 'already_active'
                                && (result.data.reason === 'taken' || result.data.reason === 'reserving')) {
                                if (sectionCard && !sectionCard.hasAttribute('hidden')) {
                                    applyMode('daily');
                                    if (typeof drawdreamAlert === 'function') {
                                        drawdreamAlert(result.data.message || 'มีผู้อุปการะสมัครไปก่อนแล้ว', 'warning');
                                    }
                                }
                            }
                            if (cardModal && !cardModal.hidden) {
                                applySlotCheckToPayButton();
                                if (!result.ok) {
                                    closeChildSubCardModal();
                                    handleSlotUnavailable(result.data);
                                }
                            }
                        });
                    }

                    function digitsOnly(val) {
                        return String(val || '').replace(/\D/g, '');
                    }

                    function parseCardExpiry(raw) {
                        var result = validateCardExpiry(raw);
                        return result.ok ? result.exp : null;
                    }

                    function submitChildSubscriptionCard() {
                        if (slotCheckOk !== true) {
                            drawdreamAlert('กำลังตรวจสอบสิทธิ์อุปการะ กรุณารอสักครู่แล้วลองอีกครั้ง');
                            return;
                        }
                        var nameEl = document.getElementById('childSubCardName');
                        var numEl = document.getElementById('childSubCardNumber');
                        var expEl = document.getElementById('childSubCardExp');
                        var cvvEl = document.getElementById('childSubCardCvv');
                        var name = nameEl ? nameEl.value.trim() : '';
                        var number = digitsOnly(numEl ? numEl.value : '');
                        var expiryCheck = validateCardExpiry(expEl ? expEl.value : '');
                        var exp = expiryCheck.ok ? expiryCheck.exp : null;
                        var cvv = digitsOnly(cvvEl ? cvvEl.value : '');

                        if (!name) {
                            drawdreamAlert('กรุณากรอกชื่อบนบัตร');
                            if (nameEl) nameEl.focus();
                            return;
                        }
                        if (number.length < 13) {
                            drawdreamAlert('กรุณากรอกเลขบัตรให้ครบ');
                            if (numEl) numEl.focus();
                            return;
                        }
                        if (!expiryCheck.ok) {
                            drawdreamAlert(expiryCheck.message);
                            if (expEl) expEl.focus();
                            return;
                        }
                        if (cvv.length < 3) {
                            drawdreamAlert('กรุณากรอกรหัส CVV');
                            if (cvvEl) cvvEl.focus();
                            return;
                        }
                        if (typeof Omise === 'undefined' || typeof Omise.createToken !== 'function') {
                            ensureOmiseReady(function () {
                                submitChildSubscriptionCard();
                            });
                            return;
                        }
                        if (childSubPaySubmitting) {
                            return;
                        }

                        cardBtn.disabled = true;
                        if (cardModalPay) {
                            cardModalPay.disabled = true;
                        }
                        showChildSubPayLoading('กำลังยืนยันบัตร', 'กรุณารอสักครู่…');
                        Omise.createToken('card', {
                            name: name,
                            number: number,
                            expiration_month: exp.month,
                            expiration_year: exp.year,
                            security_code: cvv
                        }, function (statusCode, response) {
                            if (statusCode === 200 && response && response.id) {
                                tok.value = response.id;
                                submitChildSubscriptionForm(response.id);
                                return;
                            }
                            resetChildSubPayUi();
                            var msg = (response && response.message)
                                ? response.message
                                : 'ไม่สามารถยืนยันบัตรได้ กรุณาตรวจสอบข้อมูลอีกครั้ง';
                            drawdreamAlert(msg);
                        });
                    }

                    if (isLoggedIn && cardBtn && form && tok) {
                        cardBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            if (planSponsorLocked) {
                                return;
                            }
                            openChildSubCardModal();
                            if (slotCheckOk !== true) {
                                fetchChildSponsorSlotStatus().then(function (result) {
                                    applySlotCheckToPayButton();
                                    if (!result.ok) {
                                        closeChildSubCardModal();
                                        handleSlotUnavailable(result.data);
                                    }
                                });
                            }
                        });
                    }

                    if (isLoggedIn && cardModalPay) {
                        cardModalPay.addEventListener('click', function () {
                            submitChildSubscriptionCard();
                        });
                    }

                    if (isLoggedIn && !planSponsorLocked && !viewerHasActiveChildSub && childIdForSlot > 0) {
                        fetchChildSponsorSlotStatus();
                        window.setInterval(function () {
                            if (document.hidden) {
                                return;
                            }
                            var cardOpen = cardModal && !cardModal.hidden;
                            var monthlyOpen = sectionCard && !sectionCard.hasAttribute('hidden');
                            if (!cardOpen && !monthlyOpen) {
                                return;
                            }
                            refreshChildSponsorSlotUi();
                        }, 20000);
                    }
                })();
                </script>
                <?php elseif ($role === 'donor' && !$showDonorDonationBox): ?>
                <p class="text-muted mt-3">โปรไฟล์เด็กยังไม่อนุมัติหรือถูกซ่อน จึงยังไม่เปิดรับการสมัครอุปการะรายรอบ</p>
                <?php endif; ?>

                <?php else: ?>
                <?php if ($donorLetterExperience): ?>
                <div class="child-mail-experience child-mail-experience--paper-only" id="childMailExperience">
                    <div id="childMailLetterPaper" class="child-mail-letter-paper child-mail-letter-paper--donor is-visible">
                <div class="child-mail-letter-airmail">
                <div class="child-mail-letter-airmail__inner">
                <?php if ($outcomeHasContent): ?>
                <div class="child-outcome-public child-outcome-public--tab child-outcome-public--letter">
                    <div class="child-outcome-public__label"><i class="bi bi-envelope-heart-fill" aria-hidden="true"></i> ข้อความจากมูลนิธิ</div>
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
                <div class="child-outcome-public child-outcome-public--tab child-outcome-public--letter">
                    <div class="child-outcome-public__label"><i class="bi bi-envelope-heart-fill" aria-hidden="true"></i> ข้อความจากมูลนิธิ</div>
                    <p class="child-outcome-public__placeholder mb-0">กำลังดำเนินการ</p>
                    <p class="child-impression-card__attribution">น้อง<?php echo htmlspecialchars($child['child_name'] ?? ''); ?><?php echo $educationLabel !== '' ? ' · นักเรียนชั้น' . htmlspecialchars($educationLabel) : ''; ?></p>
                    <?php if (!empty($outcomeUpdatedAt)): ?>
                    <p class="child-outcome-public__meta">อัปเดตเมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($childOutcomeHistory !== []): ?>
                <?php echo drawdream_child_outcome_history_html(
                    $childOutcomeHistory,
                    trim((string)($child['child_name'] ?? '')),
                    $educationLabel
                ); ?>
                <?php endif; ?>
                </div>
                </div>
                </div>
                </div>
                <?php else: ?>
                <div id="childMailLetterPaper" class="child-mail-letter-paper<?php echo ($role === 'donor') ? ' child-mail-letter-paper--donor' : ''; ?>">
                <h1 class="brand-header"><?php echo htmlspecialchars($childOutcomeHeading); ?></h1>
                <div class="child-mail-letter-airmail">
                <div class="child-mail-letter-airmail__inner">
                <?php if ($outcomeHasContent): ?>
                <div class="child-outcome-public child-outcome-public--tab child-outcome-public--letter">
                    <div class="child-outcome-public__label"><i class="bi bi-envelope-heart-fill" aria-hidden="true"></i> ข้อความจากมูลนิธิ</div>
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
                <div class="child-outcome-public child-outcome-public--tab child-outcome-public--letter">
                    <div class="child-outcome-public__label"><i class="bi bi-envelope-heart-fill" aria-hidden="true"></i> ข้อความจากมูลนิธิ</div>
                    <p class="child-outcome-public__placeholder mb-0">กำลังดำเนินการ</p>
                    <p class="child-impression-card__attribution">น้อง<?php echo htmlspecialchars($child['child_name'] ?? ''); ?><?php echo $educationLabel !== '' ? ' · นักเรียนชั้น' . htmlspecialchars($educationLabel) : ''; ?></p>
                    <?php if (!empty($outcomeUpdatedAt)): ?>
                    <p class="child-outcome-public__meta">อัปเดตเมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($childOutcomeHistory !== []): ?>
                <?php echo drawdream_child_outcome_history_html(
                    $childOutcomeHistory,
                    trim((string)($child['child_name'] ?? '')),
                    $educationLabel
                ); ?>
                <?php endif; ?>
                </div>
                </div>
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

<?php if ($donorLetterExperience): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var target = document.getElementById('childMailExperience');
    if (target && target.scrollIntoView) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
<?php endif; ?>
<script>
(function () {
    var forms = document.querySelectorAll('.js-confirm-cancel-sub');
    forms.forEach(function (form) {
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
