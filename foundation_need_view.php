<?php
// foundation_need_view.php — มูลนิธิดูรายละเอียดรายการสิ่งของ (อ่านอย่างเดียว) โครง UI เดียวกับ foundation_project_view.php

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน need view

session_start();
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: foundation.php');
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFn = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stmtFn) {
    header('Location: foundation.php');
    exit();
}
$stmtFn->bind_param('i', $uid);
$stmtFn->execute();
$foundationId = (int)($stmtFn->get_result()->fetch_assoc()['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) {
    header('Location: foundation.php#my-needlist-section');
    exit();
}

$st = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
if (!$st) {
    header('Location: foundation.php#my-needlist-section');
    exit();
}
$st->bind_param('ii', $itemId, $foundationId);
$st->execute();
$n = $st->get_result()->fetch_assoc();
if (!$n) {
    header('Location: foundation.php#my-needlist-section');
    exit();
}

/**
 * @return array{label:string,class:string}
 */
function foundation_need_view_status_meta(string $approve): array
{
    $k = strtolower(trim($approve));
    $map = [
        'pending' => ['label' => 'รอดำเนินการ', 'class' => 'st-pending'],
        'approved' => ['label' => 'อนุมัติแล้ว', 'class' => 'st-approved'],
        'rejected' => ['label' => 'ไม่ผ่านการอนุมัติ', 'class' => 'st-rejected'],
    ];

    return $map[$k] ?? ['label' => $approve !== '' ? $approve : '—', 'class' => 'st-pending'];
}

$statusMeta = foundation_need_view_status_meta((string)($n['approve_item'] ?? 'pending'));
$reviewNote = trim((string)($n['review_note'] ?? ''));

$goal = (float)($n['total_price'] ?? 0);
if ($goal <= 0) {
    $goal = (float)($n['price_estimate'] ?? 0);
}
$raised = (float)($n['current_donate'] ?? 0);
$progress = ($goal > 0) ? min(100.0, ($raised / $goal) * 100.0) : 0.0;
$remainingToGoal = ($goal > 0) ? max(0.0, $goal - $raised) : 0.0;

$nlImages = foundation_needlist_item_filenames_from_row($n);
$nlImgItem = $nlImages[0] ?? '';
$nlFdn = trim((string)($n['need_foundation_image'] ?? ''));
$heroFile = $nlFdn !== '' ? $nlFdn : $nlImgItem;
$heroUrl = $heroFile !== '' ? ('uploads/needs/' . $heroFile) : '';

$rawNote = trim((string)($n['note'] ?? ''));
$periodLabel = '';
$noteFree = '';
if ($rawNote !== '') {
    $noteLines = preg_split('/\R/u', $rawNote, 2);
    $firstLine = trim((string)($noteLines[0] ?? ''));
    if (preg_match('/^ระยะเวลา:\s*(.+)$/u', $firstLine, $pm)) {
        $periodLabel = trim((string)($pm[1] ?? ''));
        $noteFree = isset($noteLines[1]) ? trim((string)$noteLines[1]) : '';
    } else {
        $noteFree = $rawNote;
    }
}

$brand = trim((string)($n['brand'] ?? ''));
$catLine = $brand !== '' ? str_replace(' | ', ' · ', $brand) : '';
$legacyCat = trim((string)($n['category'] ?? ''));
if ($catLine === '' && $legacyCat !== '') {
    $catLine = $legacyCat;
}

$dweRaw = trim((string)($n['donate_window_end_at'] ?? ''));
$donateWindowExpired = (($n['approve_item'] ?? '') === 'approved'
    && $dweRaw !== ''
    && !str_starts_with($dweRaw, '0000-00-00')
    && strtotime($dweRaw) !== false
    && strtotime($dweRaw) < time());

$reviewedAt = trim((string)($n['reviewed_at'] ?? ''));
$reviewedAtFmt = '';
if ($reviewedAt !== '' && !str_starts_with($reviewedAt, '0000-00-00') && strtotime($reviewedAt) !== false) {
    $reviewedAtFmt = date('d/m/Y H:i', strtotime($reviewedAt));
}

$dweFmt = '';
if ($dweRaw !== '' && !str_starts_with($dweRaw, '0000-00-00') && strtotime($dweRaw) !== false) {
    $dweFmt = date('d/m/Y H:i', strtotime($dweRaw));
}

$allThumbs = [];
foreach ($nlImages as $fn) {
    if ($fn !== '') {
        $allThumbs[] = $fn;
    }
}
if ($nlFdn !== '' && !in_array($nlFdn, $allThumbs, true)) {
    $allThumbs[] = $nlFdn;
}

$pageTitle = 'รายการสิ่งของ';
$titleShort = trim((string)($n['item_name'] ?? ''));
if ($titleShort !== '') {
    $pageTitle = mb_strlen($titleShort, 'UTF-8') > 48 ? (mb_substr($titleShort, 0, 48, 'UTF-8') . '…') : $titleShort;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดรายการสิ่งของ — <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css?v=40">
</head>
<body class="foundation-project-view-page">

<?php include 'navbar.php'; ?>

<div class="foundation-project-view-wrap">
    <a href="foundation.php#my-needlist-section" class="foundation-project-view-back">← กลับไปรายการสิ่งของ</a>

    <article class="foundation-project-view-panel">
    <header class="foundation-project-view-hero">
        <?php if ($heroUrl !== ''): ?>
            <div class="foundation-project-view-hero-img">
                <img src="<?= htmlspecialchars($heroUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
            </div>
        <?php endif; ?>
        <div class="foundation-project-view-hero-text">
            <span class="foundation-status-pill <?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
            <h1 class="foundation-project-view-title"><?= htmlspecialchars((string)($n['item_name'] ?? '')) ?></h1>
            <?php if ($catLine !== ''): ?>
                <p class="foundation-project-view-quote"><?= htmlspecialchars($catLine) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <?php if (($n['approve_item'] ?? '') === 'pending'): ?>
        <div class="foundation-status-alert st-pending">รายการนี้รอแอดมินตรวจสอบ</div>
    <?php elseif (($n['approve_item'] ?? '') === 'rejected'): ?>
        <div class="foundation-status-alert st-rejected">รายการนี้ไม่ผ่านการอนุมัติ<?= $reviewNote !== '' ? ': ' . htmlspecialchars($reviewNote) : '' ?></div>
    <?php endif; ?>

    <?php if ($donateWindowExpired): ?>
        <div class="foundation-project-view-note foundation-project-view-note--merge">
            <strong>ปิดรับบริจาคแล้ว</strong> — ครบระยะเวลาตามที่กำหนดในระบบ
        </div>
    <?php endif; ?>

    <div class="foundation-project-view-progress">
        <div class="foundation-progress-meta">
            <span>ได้รับ <?= number_format($raised, 0) ?> บาท</span>
            <span>เป้าหมาย <?= number_format($goal, 0) ?> บาท (<?= (int)round($progress) ?>%)</span>
        </div>
        <?php if ($goal > 0): ?>
            <?php if ($remainingToGoal > 0): ?>
                <p class="foundation-project-view-remaining">เหลืออีก <?= number_format($remainingToGoal, 0) ?> บาทจะครบเป้าหมาย</p>
            <?php else: ?>
                <p class="foundation-project-view-remaining foundation-project-view-remaining--done">ครบเป้าหมายตามยอดที่ตั้งไว้แล้ว</p>
            <?php endif; ?>
        <?php endif; ?>
        <div class="foundation-progress-bar foundation-progress-bar--view">
            <div class="foundation-progress-fill" style="width: <?= (float)$progress ?>%"></div>
        </div>
    </div>

    <?php if (count($allThumbs) > 1): ?>
        <div class="foundation-need-view-thumbs">
            <p class="foundation-need-view-thumbs__label">รูปประกอบ</p>
            <div class="foundation-need-view-thumbs__grid">
                <?php foreach ($allThumbs as $tf): ?>
                    <a href="uploads/needs/<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <img src="uploads/needs/<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <dl class="foundation-project-view-dl">
        <?php if ((int)($n['urgent'] ?? 0) === 1): ?>
        <div class="foundation-project-view-row">
            <dt>ความเร่งด่วน</dt>
            <dd>ต้องการด่วน</dd>
        </div>
        <?php endif; ?>
        <?php if ($periodLabel !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>ระยะเวลารับบริจาค (ตามที่เสนอ)</dt>
            <dd><?= htmlspecialchars($periodLabel) ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($dweFmt !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>วันสิ้นสุดรับบริจาค (ระบบ)</dt>
            <dd><?= htmlspecialchars($dweFmt) ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($reviewedAtFmt !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>วันที่ตรวจสอบล่าสุด</dt>
            <dd><?= htmlspecialchars($reviewedAtFmt) ?></dd>
        </div>
        <?php endif; ?>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>รายละเอียดรายการ</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars((string)($n['item_desc'] ?? ''))) ?></dd>
        </div>
        <?php if ($noteFree !== ''): ?>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>หมายเหตุเพิ่มเติม</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars($noteFree)) ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($reviewNote !== '' && ($n['approve_item'] ?? '') === 'approved'): ?>
        <div class="foundation-project-view-row foundation-project-view-row--block">
            <dt>บันทึกจากแอดมิน (ตอนอนุมัติ)</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars($reviewNote)) ?></dd>
        </div>
        <?php endif; ?>
    </dl>
    </article>
</div>

</body>
</html>
