<?php
/**
 * มูลนิธิ: อัปเดตข้อความผลลัพธ์ให้เด็กที่อุปการะครบยอดในเดือนปัจจุบัน
 */
session_start();
include 'db.php';
require_once __DIR__ . '/includes/child_sponsorship.php';

drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: homepage.php');
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$foundationId = 0;
$stmtFP = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmtFP->bind_param('i', $currentUserId);
$stmtFP->execute();
$rowFP = $stmtFP->get_result()->fetch_assoc();
if ($rowFP) {
    $foundationId = (int)$rowFP['foundation_id'];
}

if ($foundationId <= 0) {
    die('ไม่พบข้อมูลมูลนิธิ');
}

$childId = (int)($_GET['id'] ?? $_POST['child_id'] ?? 0);
if ($childId <= 0) {
    header('Location: children_.php?msg=' . rawurlencode('ไม่พบโปรไฟล์เด็ก'));
    exit;
}

$sqlGet = 'SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? AND deleted_at IS NULL LIMIT 1';
$stmtGet = $conn->prepare($sqlGet);
$stmtGet->bind_param('ii', $childId, $foundationId);
$stmtGet->execute();
$child = $stmtGet->get_result()->fetch_assoc();

if (!$child) {
    header('Location: children_.php?msg=' . rawurlencode('ไม่พบข้อมูลโปรไฟล์หรือไม่มีสิทธิ์'));
    exit;
}

if (!drawdream_child_is_monthly_fully_sponsored($conn, $childId, $child)) {
    header('Location: children_donate.php?id=' . $childId . '&msg=' . rawurlencode('อัปเดตผลลัพธ์ได้เฉพาะเด็กที่อุปการะครบยอดในเดือนนี้เท่านั้น'));
    exit;
}

$error = '';
$success = isset($_GET['saved']) && $_GET['saved'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $text = trim((string)($_POST['outcome_text'] ?? ''));
    if ($text === '') {
        $error = 'กรุณากรอกข้อความผลลัพธ์';
    } elseif (mb_strlen($text) > 8000) {
        $error = 'ข้อความยาวเกิน 8,000 ตัวอักษร';
    } else {
        $upd = $conn->prepare('UPDATE foundation_children SET sponsor_outcome_text = ?, sponsor_outcome_updated_at = NOW() WHERE child_id = ? AND foundation_id = ?');
        $upd->bind_param('sii', $text, $childId, $foundationId);
        $upd->execute();
        header('Location: foundation_child_outcome.php?id=' . $childId . '&saved=1');
        exit;
    }
}

$existing = (string)($child['sponsor_outcome_text'] ?? '');
$childName = htmlspecialchars($child['child_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตผลลัพธ์ — <?php echo $childName; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=16">
</head>
<body class="outcome-form-page">

<?php include 'navbar.php'; ?>

<main class="outcome-form-shell">
    <a class="outcome-back-link" href="children_donate.php?id=<?php echo (int)$childId; ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> กลับหน้าโปรไฟล์เด็ก</a>

    <div class="outcome-hero-card">
        <div class="outcome-hero-card__sky">
            <div class="outcome-dream-stars" aria-hidden="true">
                <span class="outcome-star outcome-star--1"></span>
                <span class="outcome-star outcome-star--2"></span>
                <span class="outcome-star outcome-star--3"></span>
                <span class="outcome-star outcome-star--4"></span>
                <span class="outcome-star outcome-star--5"></span>
                <span class="outcome-star outcome-star--6"></span>
                <span class="outcome-star outcome-star--7"></span>
                <span class="outcome-star outcome-star--8"></span>
            </div>
            <div class="outcome-hero-card__deco outcome-hero-card__deco--ring"></div>
            <div class="outcome-dream-rainbow" aria-hidden="true">
                <svg class="outcome-rainbow-svg" viewBox="0 0 320 90" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMax meet">
                    <path d="M20 88 Q160 -8 300 88" fill="none" stroke="#fecdd3" stroke-width="10" stroke-linecap="round" opacity="0.95"/>
                    <path d="M32 88 Q160 8 288 88" fill="none" stroke="#fde68a" stroke-width="9" stroke-linecap="round" opacity="0.95"/>
                    <path d="M44 88 Q160 22 276 88" fill="none" stroke="#bbf7d0" stroke-width="8" stroke-linecap="round" opacity="0.95"/>
                    <path d="M56 88 Q160 34 264 88" fill="none" stroke="#bfdbfe" stroke-width="7" stroke-linecap="round" opacity="0.95"/>
                    <path d="M68 88 Q160 46 252 88" fill="none" stroke="#e9d5ff" stroke-width="6" stroke-linecap="round" opacity="0.9"/>
                </svg>
            </div>
            <div class="outcome-dream-kids" aria-hidden="true">
                <svg class="outcome-kids-svg" viewBox="0 0 120 48" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="28" cy="14" r="9" fill="#fff5e6"/>
                    <ellipse cx="28" cy="34" rx="12" ry="14" fill="#fff"/>
                    <circle cx="60" cy="12" r="10" fill="#ffe8f0"/>
                    <ellipse cx="60" cy="34" rx="13" ry="15" fill="#fff"/>
                    <circle cx="92" cy="14" r="9" fill="#e8f4ff"/>
                    <ellipse cx="92" cy="34" rx="12" ry="14" fill="#fff"/>
                </svg>
            </div>
            <div class="outcome-hero-card__cloud outcome-hero-card__cloud--1"></div>
            <div class="outcome-hero-card__cloud outcome-hero-card__cloud--2"></div>
            <div class="outcome-hero-card__cloud outcome-hero-card__cloud--3"></div>
        </div>
        <div class="outcome-hero-card__body">
            <?php if ($success): ?>
                <h1 class="outcome-hero-card__title">ส่งแล้ว!</h1>
                <p class="outcome-hero-card__lead">บันทึกผลลัพธ์สำหรับ <strong><?php echo $childName; ?></strong> เรียบร้อย ผู้บริจาคจะเห็นข้อความนี้บนหน้าโปรไฟล์เด็ก</p>
                <a class="outcome-hero-card__btn" href="children_donate.php?id=<?php echo (int)$childId; ?>">กลับไปที่โปรไฟล์</a>
            <?php else: ?>
                <h1 class="outcome-hero-card__title">อัปเดตผลลัพธ์</h1>
                <p class="outcome-hero-card__lead">เล่าให้ผู้บริจาคทราบว่าเงินหรือการสนับสนุนมีผลกับน้อง <strong><?php echo $childName; ?></strong> อย่างไร (เช่น ได้รับของ เรียนต่อ กิจกรรมที่ทำแล้ว)</p>

                <?php if ($error !== ''): ?>
                    <p class="outcome-hero-card__error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>

                <form method="post" action="foundation_child_outcome.php?id=<?php echo (int)$childId; ?>" class="outcome-hero-form">
                    <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">
                    <label class="outcome-hero-form__label" for="outcome_text">ข้อความผลลัพธ์</label>
                    <textarea id="outcome_text" name="outcome_text" class="outcome-hero-form__textarea" rows="8" required><?php echo htmlspecialchars($existing); ?></textarea>
                    <div class="outcome-hero-form__actions">
                        <button type="submit" class="outcome-hero-card__btn outcome-hero-card__btn--primary">บันทึกผลลัพธ์</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
