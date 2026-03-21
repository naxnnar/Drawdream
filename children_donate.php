<?php
// ไฟล์นี้: children_donate.php
// หน้าที่: หน้ารายละเอียดเด็กและการบริจาครายบุคคล
session_start();
include 'db.php';

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$role = $_SESSION['role'] ?? 'donor';
$isAdmin = ($role === 'admin');

$sql = "
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM Children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ?
";
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
$donationStats = ['donor_count' => 0, 'total_amount' => 0, 'month_amount' => 0];
$stmtDs = $conn->prepare("SELECT COUNT(DISTINCT donor_user_id) AS donor_count, COALESCE(SUM(amount),0) AS total_amount, COALESCE(SUM(CASE WHEN MONTH(donated_at)=MONTH(NOW()) AND YEAR(donated_at)=YEAR(NOW()) THEN amount ELSE 0 END),0) AS month_amount FROM child_donations WHERE child_id=?");
$stmtDs->bind_param("i", $child_id);
$stmtDs->execute();
$dsRow = $stmtDs->get_result()->fetch_assoc();
if ($dsRow) $donationStats = $dsRow;

$birthDateText = '-';
if (!empty($child['birth_date'] ?? '')) {
    $birthDateText = date('d/m/Y', strtotime($child['birth_date']));
}

$reviewStatus = $child['approve_profile'] ?? 'รอดำเนินการ';
if ($reviewStatus === 'กำลังดำเนินการ') {
    $reviewStatus = 'รอดำเนินการ';
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
    <link rel="stylesheet" href="css/children.css">
</head>
<body>

<?php include 'navbar.php'; ?>

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
                            <span class="label">สถานะการอุปการะ</span>
                            <span class="value"><?php echo htmlspecialchars($child['status'] ?? '-'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการตรวจสอบ</span>
                            <?php
                              $cls = 'status-pending';
                              if ($reviewStatus === 'อนุมัติ') $cls = 'status-approved';
                              if ($reviewStatus === 'ไม่อนุมัติ') $cls = 'status-rejected';
                            ?>
                            <span class="status-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($reviewStatus); ?></span>
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
                    <p><strong>ชื่อ:</strong> <?php echo htmlspecialchars($child['child_name']); ?></p>
                    <p><strong>มูลนิธิ:</strong> <?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></p>
                    <p><strong>วันเกิด:</strong> <?php echo htmlspecialchars($birthDateText); ?></p>
                    <p><strong>ชั้น:</strong> <?php echo htmlspecialchars($child['education']); ?></p>
                    <p><strong>อายุ:</strong> <?php echo (int)$child['age']; ?> ปี</p>
                    <p><strong>อาชีพในฝัน:</strong> <?php echo htmlspecialchars($child['dream']); ?></p>
                    <p><strong>พรที่ขอ:</strong> <?php echo htmlspecialchars($child['wish']); ?></p>
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

                <?php if ($role === 'foundation'): ?>
                <div class="foundation-full-info">
                    <h4>ข้อมูลทั้งหมดที่กรอกไว้</h4>
                    <p><strong>หมวดที่ขอ:</strong> <?php echo htmlspecialchars($child['wish_cat'] ?? '-'); ?></p>
                    <p><strong>สิ่งที่ชอบ:</strong> <?php echo htmlspecialchars($child['likes'] ?? '-'); ?></p>
                    <p><strong>ธนาคาร:</strong> <?php echo htmlspecialchars($child['bank_name'] ?? '-'); ?></p>
                    <p><strong>เลขบัญชี:</strong> <?php echo htmlspecialchars($child['child_bank'] ?? '-'); ?></p>
                    <?php if (!empty($child['qr_account_image'])): ?>
                        <div class="qr-preview" style="margin-top:10px;"><img src="uploads/Children/<?php echo htmlspecialchars($child['qr_account_image']); ?>" alt="QR PromptPay"></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                    $monthAmount = (float)$donationStats['month_amount'];
                    $totalAmount = (float)$donationStats['total_amount'];
                    $donorCount  = (int)$donationStats['donor_count'];
                ?>
                <?php if ($reviewStatus === 'อนุมัติ'): ?>
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
                            <div class="stat-num"><?php echo number_format($monthAmount, 0); ?></div>
                            <div class="stat-label">เดือนนี้ (บาท)</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($role === 'donor'): ?>
                <div class="money-row">
                    <button class="btn-money-choice" onclick="selectAmount(200, this)">200</button>
                    <button class="btn-money-choice" onclick="selectAmount(500, this)">500</button>
                    <button class="btn-money-choice" onclick="selectAmount(1000, this)">1000</button>
                </div>

                <div class="amount-box">
                    <input type="text" id="display-amount" readonly>
                    <span class="currency-label">บาท</span>
                </div>

                <button class="btn-submit-donation" onclick="processDonation(<?php echo $child['child_id']; ?>)">
                    บริจาค
                </button>
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

function selectAmount(amount, btn) {
    const amountInput = document.getElementById('display-amount');
    if (!amountInput) return;
    amountInput.value = amount;
    document.querySelectorAll('.btn-money-choice').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
}

function processDonation(id) {
    const amountInput = document.getElementById('display-amount');
    if (!amountInput || !amountInput.value) {
        alert("กรุณาเลือกจำนวนเงินก่อนบริจาค");
        return;
    }
    window.location.href = `payment.php?amount=${amountInput.value}&child_id=${id}`;
}
</script>

</body>
</html>
