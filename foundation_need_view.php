<?php
// foundation_need_view.php — มูลนิธิดูรายละเอียดรายการสิ่งของ (อ่านอย่างเดียว) โครง UI เดียวกับ foundation_project_view.php

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน need view

session_start();
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
drawdream_ensure_needlist_schema($conn);

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

// ราคา
$submittedTotal   = (float)($n['submitted_total_price'] ?? ($n['total_price'] ?? 0));
$approvedTotal    = (float)($n['approved_total_price']  ?? ($n['total_price'] ?? 0));
$currentTotal     = (float)($n['total_price'] ?? 0);
$priceReviewedRaw = trim((string)($n['price_reviewed_at'] ?? ''));
$priceReviewedFmt = '';
if ($priceReviewedRaw !== '' && !str_starts_with($priceReviewedRaw, '0000-00-00') && strtotime($priceReviewedRaw) !== false) {
    $priceReviewedFmt = date('d/m/Y H:i', strtotime($priceReviewedRaw));
}
$adminChangedPrice = $priceReviewedFmt !== '' || abs($submittedTotal - $currentTotal) > 0.01;

// helper: parse need_items_json / submitted_items_json
function fnv_parse_items(string $raw): array {
    if ($raw === '') return [];
    try { $dec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable $e) { return []; }
    if (!is_array($dec)) return [];
    $out = [];
    foreach ($dec as $li) {
        if (!is_array($li)) continue;
        $slot = (int)($li['slot'] ?? 0);
        $qty  = (float)($li['qty_needed'] ?? ($li['qty'] ?? 0));
        if ($slot <= 0 || $qty <= 0) continue;
        $price = (float)($li['price_estimate'] ?? ($li['price'] ?? 0));
        $out[$slot] = [
            'slot'       => $slot,
            'category'   => (string)($li['category'] ?? ''),
            'qty'        => $qty,
            'price'      => $price,
            'line_total' => (float)($li['line_total'] ?? ($qty * $price)),
        ];
    }
    return array_values($out);
}

// ราคาปัจจุบัน (admin-adjusted)
$lineItemsView = fnv_parse_items(trim((string)($n['need_items_json'] ?? '')));
// ราคาเก่าที่มูลนิธิเสนอ (snapshot ก่อน admin แก้)
$lineItemsOld  = fnv_parse_items(trim((string)($n['submitted_items_json'] ?? '')));
$itemNamesView = array_values(array_filter(array_map('trim', explode(',', (string)($n['item_name'] ?? '')))));

// สร้าง slot-keyed map สำหรับเปรียบเทียบราคา
$newBySlot = [];
foreach ($lineItemsView as $li) { $newBySlot[$li['slot']] = $li; }
$oldBySlot = [];
foreach ($lineItemsOld as $li) { $oldBySlot[$li['slot']] = $li; }
$hasPriceComparison = count($lineItemsOld) > 0 && $adminChangedPrice;

// timeline
$createdRaw  = trim((string)($n['created_at'] ?? ''));
$createdFmt  = ($createdRaw !== '' && !str_starts_with($createdRaw, '0000-00-00') && strtotime($createdRaw) !== false)
    ? date('d/m/Y H:i', strtotime($createdRaw)) : '';
$reviewedRaw = trim((string)($n['reviewed_at'] ?? ''));
$reviewedFmt2 = ($reviewedRaw !== '' && !str_starts_with($reviewedRaw, '0000-00-00') && strtotime($reviewedRaw) !== false)
    ? date('d/m/Y H:i', strtotime($reviewedRaw)) : '';
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
    <style>
        /* ตารางราคาสิ่งของ */
        .fnv-price-section {
            margin: 24px 0 0;
            padding: 20px;
            background: #f8f7ff;
            border: 1px solid #e7e2ff;
            border-radius: 16px;
        }
        .fnv-price-section h2 {
            margin: 0 0 4px;
            font-size: 1.05rem;
            color: #31245b;
            font-weight: 700;
        }
        .fnv-price-section p {
            margin: 0 0 14px;
            font-size: .88rem;
            color: #6b7280;
        }
        .fnv-price-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .fnv-price-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 13px;
            border-radius: 999px;
            font-size: .88rem;
            font-weight: 600;
            border: 1px solid #ddd6fe;
            background: #fff;
            color: #4b5563;
        }
        .fnv-price-chip--changed {
            background: #fffbeb;
            border-color: #fcd34d;
            color: #92400e;
        }
        .fnv-price-chip--new {
            background: #f0fdf4;
            border-color: #86efac;
            color: #166534;
        }
        .fnv-price-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }
        .fnv-price-table th,
        .fnv-price-table td {
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .fnv-price-table thead th {
            background: #ede9ff;
            color: #4e3b84;
            font-weight: 700;
        }
        .fnv-price-table tfoot td {
            background: #f9fafb;
            font-weight: 700;
        }
        .fnv-price-admin-note {
            margin-top: 10px;
            font-size: .83rem;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 8px 12px;
        }
        /* Timeline */
        .fnv-timeline {
            margin: 24px 0 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
        }
        .fnv-timeline h2 {
            margin: 0 0 16px;
            font-size: 1.05rem;
            color: #31245b;
            font-weight: 700;
        }
        .fnv-timeline-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .fnv-timeline-item {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding-bottom: 18px;
            position: relative;
        }
        .fnv-timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 32px;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        .fnv-timeline-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            z-index: 1;
        }
        .fnv-timeline-dot--submit  { background: #dbeafe; }
        .fnv-timeline-dot--approve { background: #dcfce7; }
        .fnv-timeline-dot--price   { background: #fef9c3; }
        .fnv-timeline-dot--close   { background: #fee2e2; }
        .fnv-timeline-body { flex: 1; }
        .fnv-timeline-label {
            font-weight: 700;
            font-size: .93rem;
            color: #1f2937;
        }
        .fnv-timeline-date {
            font-size: .82rem;
            color: #6b7280;
            margin-top: 2px;
        }
        /* Timeline details expandable */
        .fnv-timeline-details {
            margin-top: 8px;
        }
        .fnv-timeline-details summary {
            cursor: pointer;
            font-size: .83rem;
            color: #4e3b84;
            font-weight: 600;
            padding: 3px 0;
            user-select: none;
        }
        .fnv-timeline-details summary:hover {
            text-decoration: underline;
        }
        .fnv-timeline-change-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .83rem;
            margin-top: 8px;
        }
        .fnv-timeline-change-table th,
        .fnv-timeline-change-table td {
            padding: 5px 8px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .fnv-timeline-change-table thead th {
            background: #fef9c3;
            color: #78350f;
            font-weight: 700;
        }
        .fnv-timeline-change-table tfoot td {
            background: #f9fafb;
            font-weight: 700;
        }
        /* Price diff colors */
        .fnv-diff-up   { color: #b45309; font-weight: 600; }
        .fnv-diff-down { color: #15803d; font-weight: 600; }
        /* Action links */
        .fnv-actions {
            margin: 20px 0 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .fnv-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 12px;
            font-size: .92rem;
            font-weight: 700;
            text-decoration: none;
        }
        .fnv-action-btn--edit {
            background: #4e3b84;
            color: #fff;
        }
        .fnv-action-btn--share {
            background: #fff;
            color: #4e3b84;
            border: 1.5px solid #ddd6fe;
        }
    </style>
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
            <strong>ปิดรับบริจาคแล้ว</strong> — ครบกำหนดรอบ 1 เดือนของระบบ
        </div>
    <?php endif; ?>

    <?php
        $prevPrice = isset($n['previous_total_price']) && $n['previous_total_price'] !== null
            ? (float)$n['previous_total_price']
            : null;
        $priceWasEdited = $prevPrice !== null && $prevPrice > 0 && $prevPrice != $goal;
    ?>
    <?php if ($priceWasEdited): ?>
    <div class="foundation-project-view-note foundation-project-view-note--price-change">
        <strong>ราคาเป้าหมายถูกแก้ไข</strong>
        — ราคาเดิม <span class="price-old"><?= number_format($prevPrice, 0) ?> บาท</span>
        → ราคาใหม่ <span class="price-new"><?= number_format($goal, 0) ?> บาท</span>
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
        <?php if ($dweFmt !== ''): ?>
        <div class="foundation-project-view-row">
            <dt>วันสิ้นสุดรับบริจาคอัตโนมัติ (รอบ 1 เดือน)</dt>
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
            <dt>แบรนด์ที่ต้องการ</dt>
            <dd class="foundation-project-view-pre"><?= nl2br(htmlspecialchars((string)($n['desired_brand'] ?? ''))) ?></dd>
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

    <?php /* =================== ราคาสิ่งของ =================== */ ?>
    <div class="fnv-price-section">
        <h2>ราคาสิ่งของ</h2>
        <p>เปรียบเทียบราคาที่มูลนิธิเสนอกับราคาที่แอดมินกำหนด</p>
        <div class="fnv-price-chips">
            <span class="fnv-price-chip">ราคาที่เสนอ: <?= number_format($submittedTotal, 2) ?> บาท</span>
            <?php if ($adminChangedPrice): ?>
                <span class="fnv-price-chip fnv-price-chip--new">ราคาที่แอดมินกำหนด: <?= number_format($currentTotal, 2) ?> บาท</span>
                <?php $diff = $currentTotal - $submittedTotal; ?>
                <?php if (abs($diff) > 0.01): ?>
                    <span class="fnv-price-chip fnv-price-chip--changed">
                        <?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 2) ?> บาท
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <span class="fnv-price-chip fnv-price-chip--new">ราคาปัจจุบัน: <?= number_format($currentTotal, 2) ?> บาท</span>
            <?php endif; ?>
        </div>

        <?php if (count($lineItemsView) > 0): ?>
        <table class="fnv-price-table">
            <thead>
                <tr>
                    <th>รายการ</th>
                    <th style="text-align:center">จำนวน</th>
                    <?php if ($hasPriceComparison): ?>
                        <th style="text-align:right">ราคาเก่า/ชิ้น</th>
                        <th style="text-align:right">ราคาใหม่/ชิ้น</th>
                        <th style="text-align:right">ส่วนต่าง</th>
                    <?php else: ?>
                        <th style="text-align:right">ราคา/ชิ้น</th>
                    <?php endif; ?>
                    <th style="text-align:right">รวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lineItemsView as $idx => $li): ?>
                <?php
                    $slot     = $li['slot'];
                    $oldLi    = $oldBySlot[$slot] ?? null;
                    $oldPrice = $oldLi ? $oldLi['price'] : null;
                    $priceDiff = ($oldPrice !== null) ? ($li['price'] - $oldPrice) : null;
                    $priceChanged = $priceDiff !== null && abs($priceDiff) > 0.01;
                ?>
                <tr>
                    <td><?= htmlspecialchars($itemNamesView[$idx] ?? $li['category'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align:center"><?= number_format($li['qty'], 0) ?></td>
                    <?php if ($hasPriceComparison): ?>
                        <td style="text-align:right;color:#6b7280;<?= $priceChanged ? 'text-decoration:line-through' : '' ?>">
                            <?= $oldPrice !== null ? number_format($oldPrice, 2) . ' บาท' : '—' ?>
                        </td>
                        <td style="text-align:right;font-weight:600"><?= number_format($li['price'], 2) ?> บาท</td>
                        <td style="text-align:right">
                            <?php if ($priceChanged): ?>
                                <span class="<?= $priceDiff > 0 ? 'fnv-diff-up' : 'fnv-diff-down' ?>">
                                    <?= $priceDiff > 0 ? '+' : '' ?><?= number_format($priceDiff, 2) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#9ca3af">—</span>
                            <?php endif; ?>
                        </td>
                    <?php else: ?>
                        <td style="text-align:right"><?= number_format($li['price'], 2) ?> บาท</td>
                    <?php endif; ?>
                    <td style="text-align:right"><?= number_format($li['line_total'], 2) ?> บาท</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?= $hasPriceComparison ? 5 : 3 ?>" style="text-align:right">รวมทั้งหมด</td>
                    <td style="text-align:right"><?= number_format($currentTotal, 2) ?> บาท</td>
                </tr>
                <?php if ($hasPriceComparison && abs($submittedTotal - $currentTotal) > 0.01): ?>
                <tr style="font-size:.83rem">
                    <td colspan="5" style="text-align:right;color:#6b7280">ราคาที่เสนอ (เดิม)</td>
                    <td style="text-align:right;color:#6b7280;text-decoration:line-through"><?= number_format($submittedTotal, 2) ?> บาท</td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
        <?php endif; ?>

        <?php if ($adminChangedPrice && $priceReviewedFmt !== ''): ?>
        <div class="fnv-price-admin-note">
            แอดมินปรับราคาล่าสุดเมื่อ <?= htmlspecialchars($priceReviewedFmt) ?>
            — หากมีข้อสงสัยเกี่ยวกับราคาที่ปรับ กรุณาติดต่อทีมงาน DrawDream
        </div>
        <?php endif; ?>
    </div>

    <?php /* =================== Timeline =================== */ ?>
    <div class="fnv-timeline">
        <h2>ประวัติเหตุการณ์</h2>
        <ul class="fnv-timeline-list">
            <?php if ($createdFmt !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--submit">📋</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">เสนอรายการสิ่งของ</div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($createdFmt) ?></div>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($reviewedFmt2 !== '' && in_array($n['approve_item'] ?? '', ['approved', 'rejected'], true)): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot <?= ($n['approve_item'] ?? '') === 'approved' ? 'fnv-timeline-dot--approve' : 'fnv-timeline-dot--close' ?>">
                    <?= ($n['approve_item'] ?? '') === 'approved' ? '✅' : '⛔' ?>
                </span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">
                        <?= ($n['approve_item'] ?? '') === 'approved' ? 'แอดมินอนุมัติรายการ' : 'แอดมินไม่อนุมัติรายการ' ?>
                    </div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($reviewedFmt2) ?></div>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($priceReviewedFmt !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--price">💰</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">แอดมินปรับราคาสิ่งของ</div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($priceReviewedFmt) ?></div>
                    <?php if (count($lineItemsView) > 0 && count($lineItemsOld) > 0): ?>
                    <details class="fnv-timeline-details">
                        <summary>ดูรายละเอียดการเปลี่ยนแปลง ▸</summary>
                        <table class="fnv-timeline-change-table">
                            <thead>
                                <tr>
                                    <th>รายการ</th>
                                    <th style="text-align:right">ราคาเก่า/ชิ้น</th>
                                    <th style="text-align:right">ราคาใหม่/ชิ้น</th>
                                    <th style="text-align:right">ส่วนต่าง</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lineItemsView as $idx => $li): ?>
                                <?php
                                    $slot2      = $li['slot'];
                                    $oldLi2     = $oldBySlot[$slot2] ?? null;
                                    $oldPrice2  = $oldLi2 ? $oldLi2['price'] : null;
                                    $diff2      = ($oldPrice2 !== null) ? ($li['price'] - $oldPrice2) : null;
                                    $changed2   = $diff2 !== null && abs($diff2) > 0.01;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($itemNamesView[$idx] ?? $li['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="text-align:right;color:#6b7280;<?= $changed2 ? 'text-decoration:line-through' : '' ?>">
                                        <?= $oldPrice2 !== null ? number_format($oldPrice2, 2) . ' บาท' : '—' ?>
                                    </td>
                                    <td style="text-align:right;font-weight:600"><?= number_format($li['price'], 2) ?> บาท</td>
                                    <td style="text-align:right">
                                        <?php if ($changed2): ?>
                                            <span class="<?= $diff2 > 0 ? 'fnv-diff-up' : 'fnv-diff-down' ?>">
                                                <?= $diff2 > 0 ? '+' : '' ?><?= number_format($diff2, 2) ?> บาท
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af">ไม่เปลี่ยน</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php $totalDiff2 = $currentTotal - $submittedTotal; ?>
                            <?php if (abs($totalDiff2) > 0.01): ?>
                            <tfoot>
                                <tr>
                                    <td><strong>ราคารวม</strong></td>
                                    <td style="text-align:right;color:#6b7280;text-decoration:line-through"><?= number_format($submittedTotal, 2) ?> บาท</td>
                                    <td style="text-align:right;font-weight:700"><?= number_format($currentTotal, 2) ?> บาท</td>
                                    <td style="text-align:right">
                                        <span class="<?= $totalDiff2 > 0 ? 'fnv-diff-up' : 'fnv-diff-down' ?>">
                                            <?= $totalDiff2 > 0 ? '+' : '' ?><?= number_format($totalDiff2, 2) ?> บาท
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </details>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($dweFmt !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot <?= $donateWindowExpired ? 'fnv-timeline-dot--close' : 'fnv-timeline-dot--approve' ?>">
                    <?= $donateWindowExpired ? '🔒' : '⏰' ?>
                </span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label"><?= $donateWindowExpired ? 'ปิดรับบริจาคแล้ว' : 'กำหนดปิดรับบริจาค' ?></div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($dweFmt) ?></div>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <?php /* =================== Action links =================== */ ?>
    <?php $canEdit = in_array($n['approve_item'] ?? '', ['pending', 'rejected'], true); ?>
    <?php if ($canEdit): ?>
    <div class="fnv-actions">
        <a href="foundation_add_need.php?edit=<?= (int)$itemId ?>" class="fnv-action-btn fnv-action-btn--edit">
            ✏️ <?= ($n['approve_item'] ?? '') === 'rejected' ? 'แก้ไขและส่งใหม่' : 'แก้ไขรายการ' ?>
        </a>
    </div>
    <?php endif; ?>

    </article>
</div>

</body>
</html>
