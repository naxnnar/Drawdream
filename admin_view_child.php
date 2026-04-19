<?php
// admin_view_child.php — ดูข้อมูลโปรไฟล์เด็ก (จากไดเรกทอรี — ไม่มีอนุมัติ/ไม่อนุมัติ)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

$child_id = (int)($_GET['id'] ?? 0);
if ($child_id <= 0) {
    header('Location: children_.php');
    exit();
}

$sql = "
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM foundation_children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $child_id);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc();
if (!$child) {
    header('Location: children_.php');
    exit();
}

$donationStats = ['donor_count' => 0, 'total_amount' => 0, 'cycle_amount' => 0];
$childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
$stmtDs = $conn->prepare(
    "SELECT COUNT(DISTINCT donor_id) AS donor_count, COALESCE(SUM(amount),0) AS total_amount
     FROM donation
     WHERE category_id = ? AND target_id = ? AND payment_status = 'completed'"
);
$stmtDs->bind_param('ii', $childCategoryId, $child_id);
$stmtDs->execute();
$dsRow = $stmtDs->get_result()->fetch_assoc();
if ($dsRow) {
    $donationStats = $dsRow;
}
$donationStats['cycle_amount'] = drawdream_child_cycle_total($conn, $child_id, $child);

$birthDateText = '-';
if (!empty($child['birth_date'] ?? '')) {
    $birthDateText = date('d/m/Y', strtotime((string)$child['birth_date']));
}

$rawApprove = trim((string)($child['approve_profile'] ?? ''));
$reviewStatusLabel = $rawApprove !== '' ? $rawApprove : 'รอดำเนินการ';
if ($rawApprove === 'กำลังดำเนินการ' && !empty($child['pending_edit_json'])) {
    $reviewStatusLabel = 'รอตรวจสอบการแก้ไข';
}

$sponsorshipLabel = drawdream_child_is_cycle_sponsored($conn, $child_id, $child) ? 'อุปการะแล้ว' : 'รออุปการะ';
$displayCycleAmount = (float)($donationStats['cycle_amount'] ?? 0);
$displayTotalAmount = (float)($donationStats['total_amount'] ?? 0);
$displayDonorCount = (int)($donationStats['donor_count'] ?? 0);
$adminEducationFundTotal = drawdream_child_education_fund_total_thb($conn, $child_id);
$photo = htmlspecialchars((string)($child['photo_child'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลโปรไฟล์เด็ก | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
    <link rel="stylesheet" href="css/admin_record_view.css">
</head>
<body class="admin-record-view-page">
<?php include 'navbar.php'; ?>

<div class="admin-record-shell">
    <div class="admin-record-back">
        <a href="children_.php">← กลับไปรายชื่อเด็ก</a>
    </div>
    <article class="admin-record-sheet">
        <header class="admin-record-sheet__head">
            <h1>ข้อมูลโปรไฟล์เด็ก</h1>
            <p class="admin-record-sheet__sub"><?= htmlspecialchars((string)($child['child_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($child['display_foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <div class="admin-record-body">
            <div class="admin-record-media">
                <?php if ($photo !== ''): ?>
                    <img src="uploads/childern/<?= $photo ?>" alt="รูปเด็ก">
                <?php else: ?>
                    <div class="admin-record-ph">ไม่มีรูปโปรไฟล์</div>
                <?php endif; ?>
            </div>
            <div class="admin-record-grid">
                <div class="admin-record-field">
                    <div class="admin-record-k">ชื่อเด็ก</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['child_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['display_foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันเกิด</div>
                    <div class="admin-record-v"><?= htmlspecialchars($birthDateText, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">อายุ</div>
                    <div class="admin-record-v"><?= (int)($child['age'] ?? 0) ?> ปี</div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ระดับการศึกษา</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['education'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ความฝัน</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['dream'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">สิ่งที่ชอบ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['likes'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">หมวดหมู่สิ่งที่ต้องการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['wish_cat'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">สิ่งที่อยากขอ / ความต้องการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['wish'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ธนาคาร</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['bank_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เลขบัญชี</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($child['child_bank'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันที่อนุมัติ / ตรวจสอบล่าสุด</div>
                    <div class="admin-record-v"><?= !empty($child['approve_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string)$child['approve_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">สถานะการอุปการะ (เดือนปฏิทินปัจจุบัน)</div>
                    <div class="admin-record-v"><?= htmlspecialchars($sponsorshipLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">สถานะการตรวจสอบ</div>
                    <div class="admin-record-v"><?= htmlspecialchars($reviewStatusLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if ($rawApprove === 'ไม่อนุมัติ' && trim((string)($child['reject_reason'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">เหตุผลไม่อนุมัติ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$child['reject_reason'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="admin-record-stats donation-stats-panel" aria-label="สรุปตัวเลขการบริจาค">
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-heart-fill"></i></div>
                        <div class="stat-num"><?= $displayDonorCount ?></div>
                        <div class="stat-label">ผู้อุปการะทั้งหมด</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                        <div class="stat-num"><?= number_format($displayTotalAmount, 0, '.', ',') ?></div>
                        <div class="stat-label">ยอดสะสม (บาท)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-stars"></i></div>
                        <div class="stat-num"><?= number_format($displayCycleAmount, 0, '.', ',') ?></div>
                        <div class="stat-label">เดือนนี้ (ปฏิทิน, บาท)</div>
                    </div>
                    <div class="stat-box stat-box--education-fund">
                        <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                        <div class="stat-num"><?= number_format($adminEducationFundTotal, 0, '.', ',') ?></div>
                        <div class="stat-label">ทุนการศึกษา (ส่วนเกิน 700 บ. / ครั้ง รายวัน)</div>
                    </div>
                </div>
            </div>
        </div>
    </article>
</div>
</body>
</html>
