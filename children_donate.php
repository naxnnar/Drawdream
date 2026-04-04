<?php
// children_donate.php — อุปการะเด็กแบบรายงวด (Omise Charge Schedule) + Omise.js Token

session_start();
include 'db.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/payment/config.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$role = $_SESSION['role'] ?? 'donor';
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

// Auto-migrate child_donations table
$conn->query("
    CREATE TABLE IF NOT EXISTS child_donations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        donor_user_id INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Donation stats for this child
$donationStats = ['donor_count' => 0, 'total_amount' => 0, 'cycle_amount' => 0];
$stmtDs = $conn->prepare("SELECT COUNT(DISTINCT donor_user_id) AS donor_count, COALESCE(SUM(amount),0) AS total_amount FROM child_donations WHERE child_id=?");
$stmtDs->bind_param("i", $child_id);
$stmtDs->execute();
$dsRow = $stmtDs->get_result()->fetch_assoc();
if ($dsRow) {
    $donationStats = $dsRow;
}
$donationStats['cycle_amount'] = drawdream_child_cycle_total($conn, $child_id, $child);

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
$hasActiveChildSub = ($role === 'donor' && $donorUid > 0)
    ? drawdream_child_has_active_omise_subscription($conn, $child_id, $donorUid)
    : false;
$canStartChildSub = ($role === 'donor' && $donorUid > 0)
    ? drawdream_child_can_start_omise_subscription($conn, $child_id, $child, $donorUid)
    : false;
$anyPlanSponsor = drawdream_child_has_any_active_subscription($conn, $child_id);
$sponsorshipLabel = drawdream_child_is_cycle_sponsored($conn, $child_id, $child) ? 'อุปการะแล้ว' : 'รออุปการะ';
$foundationCanUpdateOutcome = ($role === 'foundation')
    && (
        drawdream_child_is_monthly_fully_sponsored($conn, $child_id, $child)
        || $anyPlanSponsor
    );
$outcomePublic = trim((string)($child['sponsor_outcome_text'] ?? ''));
$outcomeUpdatedAt = $child['sponsor_outcome_updated_at'] ?? null;
$outcomeImageList = drawdream_child_outcome_images_parse($child['sponsor_outcome_images'] ?? null);
$outcomeHasContent = ($outcomePublic !== '' || $outcomeImageList !== []);
$firstOutcomeImg = $outcomeImageList[0] ?? null;
if ($firstOutcomeImg !== null) {
    $impressionMainSrc = 'uploads/Children/outcomes/' . $firstOutcomeImg;
    $impressionGalleryImages = array_slice($outcomeImageList, 1);
} elseif (!empty($child['photo_child'])) {
    $impressionMainSrc = 'uploads/Children/' . $child['photo_child'];
    $impressionGalleryImages = $outcomeImageList;
} else {
    $impressionMainSrc = null;
    $impressionGalleryImages = $outcomeImageList;
}
$educationLabel = trim((string)($child['education'] ?? ''));

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
    <title>โปรไฟล์ - <?php echo htmlspecialchars($child['child_name']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=26">
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
                    <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile" class="admin-child-image">
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
                            <span class="label">วันที่อนุมัติครั้งแรก</span>
                            <span class="value"><?php echo !empty($child['first_approved_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($child['first_approved_at']))) : '-'; ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ตรวจสอบล่าสุด</span>
                            <span class="value"><?php echo !empty($child['reviewed_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($child['reviewed_at']))) : '-'; ?></span>
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
                        <div class="data-item full">
                            <span class="label">QR PromptPay</span>
                            <span class="value"><?php echo !empty($child['qr_account_image']) ? 'มีไฟล์แนบ' : 'ไม่มีไฟล์แนบ'; ?></span>
                            <?php if (!empty($child['qr_account_image'])): ?>
                                <div class="qr-preview"><img src="uploads/Children/<?php echo htmlspecialchars($child['qr_account_image']); ?>" alt="QR PromptPay"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                                        <?php if ($reviewStatus !== 'อนุมัติ'): ?>
                    <p class="text-muted small mb-2">การไม่อนุมัติจะอัปเดตสถานะในระบบเท่านั้น ไม่มีการลบข้อมูลโปรไฟล์ออกจากฐานข้อมูล</p>
                    <form method="post" action="admin_approve_children.php" class="admin-actions" onsubmit="return submitChildReview(this, event)">
                        <input type="hidden" name="id" value="<?php echo (int)$child['child_id']; ?>">
                        <input type="hidden" name="return" value="children_donate.php?id=<?php echo (int)$child['child_id']; ?>">
                        <textarea name="reject_reason" data-role="reject-reason" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ" style="min-width:280px;min-height:96px;border-radius:12px;border:1px solid #e5e7eb;padding:10px 12px;"></textarea>
                        <button type="submit" name="action" value="approve" class="btn btn-primary" onclick="this.form.dataset.action='approve';">อนุมัติ</button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger" onclick="this.form.dataset.action='reject';">ไม่อนุมัติ</button>
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
        <div class="profile-label">เด็กรายบุคคล</div>

        <div class="profile-inner">
            <div class="col-left">
                <div class="child-img-container">
                    <img src="uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>" alt="Profile">
                </div>
                <div class="child-details">
                    <p><strong>ชื่อ</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>มูลนิธิ</strong> <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></p>
                    <p><strong>วันเกิด</strong> <?php echo htmlspecialchars($birthDateText); ?></p>
                    <p><strong>ชั้น</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ</strong> <?php echo (int)$child['age']; ?> ปี</p>
                    <p><strong>อาชีพในฝัน</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
                    <?php if (($role === 'foundation' || $role === 'admin') && $reviewStatus === 'ไม่อนุมัติ' && !empty($child['reject_reason'] ?? '')): ?>
                    <p style="color:#b32525;"><strong>เหตุผลไม่อนุมัติ:</strong> <?php echo htmlspecialchars($child['reject_reason']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-right">
                <h1 class="brand-header">Drawdream</h1>
                <p class="donate-text">
                    โครงการนี้เป็นการบริจาคให้รายบุคคลซึ่งเงินที่บริจาค<br>
                    จะถูกจัดสรรให้ตรงกับความต้องการของเด็ก
                </p>

                <?php if ($role === 'donor' && $anyPlanSponsor): ?>
                <div class="child-impression-card">
                    <div class="child-impression-card__label"><i class="bi bi-chat-heart-fill" aria-hidden="true"></i> บอกเล่าความประทับใจ</div>
                    <?php if ($outcomeHasContent || $impressionMainSrc): ?>
                    <div class="child-impression-card__body">
                        <?php if ($impressionMainSrc): ?>
                        <div class="child-impression-card__photo-wrap">
                            <img src="<?php echo htmlspecialchars($impressionMainSrc); ?>" alt="" class="child-impression-card__photo" loading="lazy" decoding="async">
                        </div>
                        <?php endif; ?>
                        <div class="child-impression-card__text-col">
                            <?php if ($outcomePublic !== ''): ?>
                            <div class="child-impression-card__quote"><?php echo nl2br(htmlspecialchars($outcomePublic)); ?></div>
                            <?php elseif ($outcomeHasContent): ?>
                            <p class="child-impression-card__empty-inline mb-0">มูลนิธิได้แชร์ภาพความประทับใจจากน้องไว้ด้านล่าง</p>
                            <?php else: ?>
                            <p class="child-impression-card__empty-inline mb-0">มูลนิธิจะอัปเดตเรื่องราวและภาพจากน้องเมื่อพร้อม</p>
                            <?php endif; ?>
                            <?php echo drawdream_child_outcome_images_html($impressionGalleryImages); ?>
                            <p class="child-impression-card__attribution">น้อง<?php echo htmlspecialchars($child['child_name'] ?? ''); ?><?php echo $educationLabel !== '' ? ' · นักเรียนชั้น' . htmlspecialchars($educationLabel) : ''; ?></p>
                            <?php if (!empty($outcomeUpdatedAt)): ?>
                            <p class="child-impression-card__meta mb-0">อัปเดตเมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="child-impression-card__empty mb-0">มูลนิธิจะอัปเดตเรื่องราวและภาพจากน้องเมื่อพร้อม — ขอบคุณที่ร่วมสนับสนุนเด็กคนนี้</p>
                    <?php endif; ?>
                    <?php if ($hasActiveChildSub): ?>
                    <p class="child-impression-card__subscriber"><strong>คุณสมัครอุปการะแบบรายงวดกับเด็กคนนี้แล้ว</strong> · ขอบคุณที่เคียงข้างน้อง</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($role === 'foundation'): ?>
                <div class="foundation-full-info">
                    <h4>ข้อมูลทั้งหมดที่กรอกไว้</h4>
                    <p><strong>หมวดที่ขอ</strong> <?php echo htmlspecialchars($child['wish_cat'] ?? '-'); ?></p>
                    <p><strong>สิ่งที่ชอบ</strong> <?php echo htmlspecialchars($child['likes'] ?? '-'); ?></p>
                    <p><strong>ธนาคาร</strong> <?php echo htmlspecialchars($child['bank_name'] ?? '-'); ?></p>
                    <p><strong>เลขบัญชี</strong> <?php echo htmlspecialchars($child['child_bank'] ?? '-'); ?></p>
                    <?php if (!empty($child['qr_account_image'])): ?>
                        <div class="qr-preview" style="margin-top:10px;"><img src="uploads/Children/<?php echo htmlspecialchars($child['qr_account_image']); ?>" alt="QR PromptPay"></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                    $cycleAmount = (float)$donationStats['cycle_amount'];
                    $totalAmount = (float)$donationStats['total_amount'];
                    $donorCount  = (int)$donationStats['donor_count'];
                ?>
                <?php if ($role === 'foundation'): ?>
                <div class="donation-stats-panel">
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
                    </div>
                </div>
                <?php if ($foundationCanUpdateOutcome): ?>
                <div class="foundation-outcome-cta">
                    <a href="foundation_child_outcome.php?id=<?php echo (int)$child_id; ?>" class="btn-foundation-outcome">อัปเดตผลลัพธ์</a>
                    <?php if ($outcomeHasContent): ?>
                    <p class="foundation-outcome-cta__hint">แสดงผลลัพธ์ (ข้อความ/รูป) ให้ผู้บริจาคบนหน้านี้แล้ว · กดเพื่อแก้ไข</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($role === 'donor' && !$anyPlanSponsor && $hasActiveChildSub): ?>
                <div class="child-impression-card child-impression-card--subscriber-only mt-3">
                    <p class="child-impression-card__subscriber mb-0"><strong>คุณสมัครอุปการะแบบรายงวดกับเด็กคนนี้แล้ว</strong> · ขอบคุณที่เคียงข้างน้อง</p>
                </div>
                <?php elseif ($role === 'donor' && !$anyPlanSponsor && $canStartChildSub): ?>
                <div class="child-subscription-box mt-2">
                    <div class="sub-plan-grid" role="tablist" aria-label="เลือกรูปแบบการบริจาค">
                        <button type="button" class="sub-plan-btn sub-mode-btn" data-mode="daily">รายวัน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn active" data-mode="monthly">รายเดือน</button>
                        <button type="button" class="sub-plan-btn sub-mode-btn" data-mode="yearly">รายปี</button>
                    </div>

                    <div id="subSectionDaily" class="sub-section sub-section--hidden" hidden>
                        <form id="childDailyForm" method="post" action="payment/child_donate.php" class="child-daily-form">
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <input type="hidden" name="pay" value="1">
                            <label class="visually-hidden" for="dailyAmountInput">จำนวนเงินบาท (ขั้นต่ำ 20)</label>
                            <input type="number" name="amount" id="dailyAmountInput" class="sub-daily-amount-input" min="20" step="1" inputmode="numeric" placeholder="ระบุจำนวนเงิน (ขั้นต่ำ 20 บาท)" required autocomplete="off">
                            <div class="payment-method child-daily-payment-method">
                                <div class="method-card active" aria-label="ชำระด้วย PromptPay QR ผ่าน Omise">
                                    <img src="<?php echo htmlspecialchars($qrIconSrc); ?>" alt="" class="method-icon" width="30" height="30" decoding="async">
                                    <span>PromptPay QR</span>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit-donation btn-submit-donation--sub">บริจาค</button>
                        </form>
                    </div>

                    <div id="subSectionCard" class="sub-section">
                        <div id="subMonthlyCycles" class="sub-cycle-grid" role="group" aria-label="เลือกงวดอุปการะ">
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
                        <form id="childSubForm" method="post" action="payment/child_subscription_create.php" class="child-sub-form">
                            <input type="hidden" name="child_id" value="<?php echo (int)$child['child_id']; ?>">
                            <input type="hidden" name="plan" id="subPlanField" value="monthly">
                            <input type="hidden" name="omiseToken" id="omiseTokenField" value="">
                            <button type="button" class="btn-submit-donation btn-submit-donation--sub" id="btnChildSubscribe">บริจาค</button>
                        </form>
                    </div>
                </div>
                <script type="text/javascript" src="https://cdn.omise.co/omise.js"></script>
                <script>
                (function () {
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
                            var n = parseInt(dailyInput.value, 10);
                            if (isNaN(n) || n < 20) {
                                e.preventDefault();
                                alert('กรุณาระบุจำนวนเงินอย่างน้อย 20 บาท');
                                dailyInput.focus();
                            }
                        });
                    }

                    applyMode('monthly');

                    var form = document.getElementById('childSubForm');
                    var tok = document.getElementById('omiseTokenField');
                    var cardBtn = document.getElementById('btnChildSubscribe');
                    if (cardBtn && form && tok && typeof OmiseCard !== 'undefined') {
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
                <?php elseif ($role === 'donor' && !$anyPlanSponsor): ?>
                <p class="text-muted mt-3">โปรไฟล์เด็กยังไม่อนุมัติหรือถูกซ่อน จึงยังไม่เปิดรับการสมัครอุปการะรายงวด</p>
                <?php endif; ?>

                <?php if ($outcomeHasContent && !($role === 'donor' && $anyPlanSponsor)): ?>
                <div class="child-outcome-public">
                    <div class="child-outcome-public__label"><i class="bi bi-megaphone-fill" aria-hidden="true"></i> อัปเดตจากมูลนิธิ</div>
                    <?php if ($outcomePublic !== ''): ?>
                    <div class="child-outcome-public__text"><?php echo nl2br(htmlspecialchars($outcomePublic)); ?></div>
                    <?php endif; ?>
                    <?php if ($outcomeImageList !== []): ?>
                    <?php echo drawdream_child_outcome_images_html($outcomeImageList); ?>
                    <?php endif; ?>
                    <?php if (!empty($outcomeUpdatedAt)): ?>
                    <p class="child-outcome-public__meta">โพสต์เมื่อ <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string)$outcomeUpdatedAt))); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php endif; ?>

<script>
function submitChildReview(form) {
    const action = form.dataset.action || '';
    if (action === 'approve') {
        return confirm('ยืนยันอนุมัติโปรไฟล์เด็กคนนี้?');
    }
    if (action === 'reject') {
        const reasonEl = form.querySelector('[data-role="reject-reason"]');
        const reason = reasonEl ? reasonEl.value.trim() : '';
        if (!reason) {
            alert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ');
            if (reasonEl) reasonEl.focus();
            return false;
        }
        return confirm('ยืนยันไม่อนุมัติโปรไฟล์เด็กคนนี้?');
    }
    return true;
}

</script>

</body>
</html>
