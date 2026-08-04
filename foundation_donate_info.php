<?php
declare(strict_types=1);
// foundation_donate_info.php — หน้าข้อมูลบัญชี/ติดต่อมูลนิธิก่อนเข้าหน้าบริจาค

define('DRAWDREAM_DB_LIGHT', true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/foundation_banks.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) {
    header('Location: foundation.php');
    exit;
}

$stmt = $conn->prepare(
    'SELECT foundation_id, foundation_name, foundation_desc, foundation_image, phone, bank_name, bank_account_number, bank_account_name, account_verified
     FROM foundation_profile
     WHERE foundation_id = ?
     LIMIT 1'
);
if (!$stmt) {
    header('Location: foundation.php');
    exit;
}
$stmt->bind_param('i', $fid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fp || (int)($fp['account_verified'] ?? 0) !== 1) {
    header('Location: foundation.php');
    exit;
}

$foundationName = trim((string)($fp['foundation_name'] ?? ''));
$foundationDesc = trim((string)($fp['foundation_desc'] ?? ''));
$foundationImg = trim((string)($fp['foundation_image'] ?? ''));
$phone = trim((string)($fp['phone'] ?? ''));
$bankName = trim((string)($fp['bank_name'] ?? ''));
$bankAccountNumber = trim((string)($fp['bank_account_number'] ?? ''));
$bankAccountName = trim((string)($fp['bank_account_name'] ?? ''));

$bankList = drawdream_foundation_bank_list();
$bankDisplay = $bankName !== '' ? ($bankList[$bankName] ?? $bankName) : 'ไม่ระบุธนาคาร';
$accountNumberDisplay = $bankAccountNumber !== '' ? $bankAccountNumber : '-';
$accountNameDisplay = $bankAccountName !== '' ? $bankAccountName : ($foundationName !== '' ? $foundationName : '-');

$needOpen = drawdream_needlist_sql_open_for_donation();
$statsStmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(total_price), 0) AS goal,
        COALESCE(SUM(current_donate), 0) AS current,
        COUNT(*) AS cnt
     FROM foundation_needlist
     WHERE foundation_id = ? AND $needOpen"
);
$goal = 0.0;
$current = 0.0;
$itemCount = 0;
if ($statsStmt) {
    $statsStmt->bind_param('i', $fid);
    $statsStmt->execute();
    $statsRow = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();
    if ($statsRow) {
        $goal = (float)($statsRow['goal'] ?? 0);
        $current = (float)($statsRow['current'] ?? 0);
        $itemCount = (int)($statsRow['cnt'] ?? 0);
    }
}

$remainingNeed = ($goal > 0) ? max(0.0, $goal - $current) : 0.0;
$donateUrl = 'payment/foundation_donate.php?fid=' . $fid;
$donateReady = $itemCount > 0 && ($goal <= 0 || $current < $goal) && !($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20);
$donateDisabledReason = '';
if ($itemCount <= 0) {
    $donateDisabledReason = 'มูลนิธิยังไม่มีรายการสิ่งของที่เปิดรับบริจาค';
} elseif ($goal > 0 && $current >= $goal) {
    $donateDisabledReason = 'รายการสิ่งของครบเป้าหมายแล้ว';
} elseif ($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20) {
    $donateDisabledReason = 'ยอดที่เหลือไม่ถึงขั้นต่ำการบริจาค 20 บาท';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ข้อมูลบัญชีมูลนิธิ | <?= htmlspecialchars($foundationName !== '' ? $foundationName : 'มูลนิธิ', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation_donate_info.css?v=2">
</head>
<body class="foundation-donate-info-page">
<?php include __DIR__ . '/navbar.php'; ?>

<main class="fdi-wrap">
    <a href="foundation.php" class="fdi-back" data-foundation-back>← กลับ</a>

    <section class="fdi-card">
        <div class="fdi-top">
            <div class="fdi-logo-wrap<?= $foundationImg === '' ? ' fdi-logo-wrap--empty' : '' ?>">
                <?php if ($foundationImg !== ''): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($foundationImg, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    ไม่มีรูป
                <?php endif; ?>
            </div>
            <div class="fdi-head">
                <h1><?= htmlspecialchars($foundationName !== '' ? $foundationName : 'มูลนิธิ', ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($foundationDesc !== ''): ?>
                    <p><?= nl2br(htmlspecialchars($foundationDesc, ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="fdi-bank">
            <div class="fdi-bank-head">
                <strong><?= htmlspecialchars($bankDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                <span>ข้อมูลบัญชีสำหรับรับบริจาค</span>
            </div>
            <div class="fdi-bank-body">
                <div class="fdi-row">
                    <span class="fdi-label">เลขบัญชี</span>
                    <span class="fdi-value"><?= htmlspecialchars($accountNumberDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="fdi-row">
                    <span class="fdi-label">ชื่อบัญชี</span>
                    <span class="fdi-value"><?= htmlspecialchars($accountNameDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>

        <div class="fdi-contact">
            <h2>ติดต่อสอบถาม</h2>
            <p><?= htmlspecialchars($phone !== '' ? $phone : '-', ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="fdi-donate-cta">
            <h2>บริจาคสิ่งของผ่าน DrawDream</h2>
            <p>เลือกรายการสิ่งของที่ต้องการสมทบทุน แล้วชำระผ่าน PromptPay QR บนแพลตฟอร์ม</p>
            <?php if ($donateReady): ?>
                <a class="fdi-donate-btn" href="<?= htmlspecialchars($donateUrl, ENT_QUOTES, 'UTF-8') ?>">บริจาคสิ่งของ</a>
            <?php else: ?>
                <p class="fdi-donate-note"><?= htmlspecialchars($donateDisabledReason !== '' ? $donateDisabledReason : 'ยังไม่สามารถบริจาคได้ในขณะนี้', ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>

    </section>
</main>
</body>
</html>
