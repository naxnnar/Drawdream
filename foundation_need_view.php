<?php
// foundation_need_view.php — มูลนิธิดูรายละเอียดรายการสิ่งของ (อ่านอย่างเดียว) โครง UI เดียวกับ foundation_project_view.php

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน need view

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
        'purchasing' => ['label' => 'กำลังจัดซื้อ', 'class' => 'st-purchasing'],
        'done' => ['label' => 'จัดส่งเสร็จแล้ว', 'class' => 'st-done'],
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
$nlFdn = foundation_needlist_normalize_filename((string)($n['need_foundation_image'] ?? ''));
$needUploadDirAbs = drawdream_needlist_upload_dir();
$heroFile = '';
if ($nlFdn !== '' && is_file($needUploadDirAbs . $nlFdn)) {
    $heroFile = $nlFdn;
}
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

$catLine = '';

$dweRaw = trim((string)($n['donate_window_end_at'] ?? ''));
$donateWindowExpired = (($n['approve_item'] ?? '') === 'approved'
    && $dweRaw !== ''
    && !str_starts_with($dweRaw, '0000-00-00')
    && strtotime($dweRaw) !== false
    && strtotime($dweRaw) < time());

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

$adminDelivery = foundation_needlist_admin_delivery_from_row($n);
$evidenceDirAbs = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence' . DIRECTORY_SEPARATOR;
$deliveryImages = [];
foreach ($adminDelivery['images'] as $evFn) {
    if ($evFn !== '' && is_file($evidenceDirAbs . $evFn)) {
        $deliveryImages[] = $evFn;
    }
}
$showDeliveryEvidence = $adminDelivery['has'] && ($deliveryImages !== [] || $adminDelivery['text'] !== '');
$isDoneStatus = strtolower(trim((string)($n['approve_item'] ?? ''))) === 'done';

$serviceChargeItem = (float)($n['service_charge'] ?? 0);
if ($serviceChargeItem <= 0 && $goal > 0 && $raised >= $goal) {
    $serviceChargeItem = drawdream_needlist_compute_service_charge($raised);
}
$goalMet = drawdream_needlist_item_goal_met($raised, $goal);
$serviceChargePaid = !empty($n['service_charge_paid_at']);
$serviceChargePayAmount = (int) round($serviceChargeItem);
$showServiceChargeBlock = $goalMet && ($serviceChargeItem > 0 || $serviceChargePaid);
$canPayServiceCharge = $goalMet && !$serviceChargePaid && $serviceChargeItem > 0 && $serviceChargePayAmount >= 20;
$waitingAdminAfterScPaid = $goalMet && $serviceChargePaid && !$isDoneStatus;
$serviceChargePctLabel = (int) round(drawdream_needlist_service_charge_rate() * 100);
$scFlash = '';
if (isset($_GET['sc_paid'])) {
    $scFlash = 'ชำระค่าบริการระบบสำหรับรายการนี้เรียบร้อยแล้ว';
} elseif (isset($_GET['sc_err'])) {
    $scErr = (string)($_GET['sc_err'] ?? '');
    $scFlash = match ($scErr) {
        'min' => 'ยอดค่าบริการต่ำกว่าขั้นต่ำการชำระ (20 บาท) — ติดต่อแอดมิน',
        'no_amount' => 'ยังไม่มียอดค่าบริการให้ชำระ',
        'not_ready' => 'ชำระค่าบริการได้เมื่อผู้บริจาคบริจาคครบเป้าหมายแล้วเท่านั้น',
        default => 'ไม่สามารถเริ่มชำระค่าบริการได้ กรุณาลองใหม่',
    };
}

$scPaidFmt = '';
$scPaidRaw = trim((string)($n['service_charge_paid_at'] ?? ''));
if ($scPaidRaw !== '') {
    $ts = strtotime($scPaidRaw);
    if ($ts !== false) {
        $scPaidFmt = date('d/m/Y H:i', $ts);
    }
}

$pageTitle = 'รายการสิ่งของ';
$titleShort = trim((string)($n['item_name'] ?? ''));
if ($titleShort !== '') {
    $pageTitle = mb_strlen($titleShort, 'UTF-8') > 48 ? (mb_substr($titleShort, 0, 48, 'UTF-8') . '…') : $titleShort;
}

// ราคา
$submittedTotal   = (float)($n['submitted_total_price'] ?? ($n['total_price'] ?? 0));
$currentTotal     = (float)($n['total_price'] ?? 0);
$approvedTotal    = $currentTotal;
$priceReviewedRaw = trim((string)($n['price_reviewed_at'] ?? ''));
$priceReviewedFmt = '';
if ($priceReviewedRaw !== '' && !str_starts_with($priceReviewedRaw, '0000-00-00') && strtotime($priceReviewedRaw) !== false) {
    $priceReviewedFmt = date('d/m/Y H:i', strtotime($priceReviewedRaw));
}
$adminChangedPrice = $priceReviewedFmt !== '' || abs($submittedTotal - $currentTotal) > 0.01;

// ราคาต่อรายการ: หลังอนุมัติใช้ need_items_pricing_json (ราคาแอดมิน) ไม่เฉลี่ยยอดรวม
$lineItemsView = foundation_needlist_submitted_line_items_from_row($n);
$lineItemsCurrent = foundation_needlist_admin_line_items_from_row($n);
$needStatus = strtolower(trim((string)($n['approve_item'] ?? '')));
$useAdminLinePrices = in_array($needStatus, ['approved', 'purchasing', 'done'], true);
$lineItemsTable = ($useAdminLinePrices && $lineItemsCurrent !== [])
    ? $lineItemsCurrent
    : ($lineItemsView !== [] ? $lineItemsView : $lineItemsCurrent);
$lineItemsTableTotal = $currentTotal > 0 ? $currentTotal : 0.0;
if ($lineItemsTableTotal <= 0) {
    foreach ($lineItemsTable as $liRow) {
        $lineItemsTableTotal += (float)($liRow['line_total'] ?? 0);
    }
}
if ($lineItemsTableTotal <= 0) {
    $lineItemsTableTotal = $useAdminLinePrices ? $currentTotal : $submittedTotal;
}
$lineCatsView = [];
foreach ($lineItemsTable as $lv) {
    $cv = trim((string)($lv['category'] ?? ''));
    if ($cv !== '') {
        $lineCatsView[] = $cv;
    }
}
$catLine = implode(' | ', array_values(array_unique($lineCatsView)));
$itemNamesView = array_values(array_filter(array_map('trim', explode(',', (string)($n['item_name'] ?? '')))));

// สร้าง slot-keyed map สำหรับเปรียบเทียบราคา
$newBySlot = [];
foreach ($lineItemsTable as $li) { $newBySlot[$li['slot']] = $li; }
$hasPriceComparison = false;

// timeline
$createdRaw  = trim((string)($n['created_at'] ?? ''));
$createdFmt  = ($createdRaw !== '' && !str_starts_with($createdRaw, '0000-00-00') && strtotime($createdRaw) !== false)
    ? date('d/m/Y H:i', strtotime($createdRaw)) : '';
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
        .foundation-status-pill.st-done {
            background: #597D57;
            color: #fff;
        }
        /* หลักฐานการจัดส่งจากแอดมิน */
        .fnv-delivery {
            margin: 24px 0 0;
            padding: 0;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(89, 125, 87, 0.35);
            background: linear-gradient(165deg, #f4f7f4 0%, #fafbf9 42%, #fff 100%);
            box-shadow: 0 12px 32px rgba(89, 125, 87, 0.12);
        }
        .fnv-delivery__head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 22px;
            background: #597D57;
            color: #fff;
        }
        .fnv-delivery__head h2 {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .fnv-delivery__badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.22);
            font-size: .86rem;
            font-weight: 600;
        }
        .fnv-delivery__body {
            padding: 22px;
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }
        .fnv-delivery__body--solo {
            grid-template-columns: 1fr;
        }
        @media (max-width: 768px) {
            .fnv-delivery__body {
                grid-template-columns: 1fr;
            }
        }
        .fnv-delivery__gallery {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .fnv-delivery__main-img {
            display: block;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid rgba(89, 125, 87, 0.28);
            background: #fff;
            box-shadow: 0 8px 24px rgba(89, 125, 87, 0.14);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .fnv-delivery__main-img:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(89, 125, 87, 0.2);
        }
        .fnv-delivery__main-img img {
            display: block;
            width: 100%;
            max-height: 360px;
            object-fit: cover;
        }
        .fnv-delivery__caption {
            font-size: .8rem;
            color: #6b7280;
            text-align: center;
        }
        .fnv-delivery__info {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .fnv-delivery__status-card {
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid rgba(89, 125, 87, 0.28);
        }
        .fnv-delivery__status-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #597D57;
            margin: 0 0 6px;
        }
        .fnv-delivery__status-text {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #3d563c;
        }
        .fnv-delivery__desc {
            margin: 0;
            padding: 16px 18px;
            border-radius: 14px;
            background: #fff;
            border-left: 4px solid #597D57;
            font-size: .95rem;
            line-height: 1.65;
            color: #374151;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .fnv-delivery__hint {
            margin: 0;
            font-size: .86rem;
            color: #6b7280;
            line-height: 1.5;
        }
        .fnv-delivery__cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 4px;
            padding: 11px 18px;
            border-radius: 12px;
            background: #CC583F;
            color: #fff !important;
            font-size: .9rem;
            font-weight: 700;
            text-decoration: none;
            width: fit-content;
            transition: background .2s ease;
        }
        .fnv-delivery__cta:hover {
            background: #b34d36;
        }
        .fnv-delivery__pay-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
            padding: 12px 20px;
            border-radius: 12px;
            background: #597D57;
            color: #fff !important;
            font-size: .92rem;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: background .2s ease;
        }
        .fnv-delivery__pay-btn:hover {
            background: #4a6a48;
        }
        .fnv-delivery__fee-paid {
            margin: 12px 0 0;
            font-size: .88rem;
            font-weight: 700;
            color: #3d563c;
        }
        .fnv-delivery__sc-flash {
            margin: 0 0 16px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: .88rem;
            line-height: 1.45;
        }
        .fnv-delivery__sc-flash--ok {
            background: #ecfdf3;
            color: #166534;
            border: 1px solid #86efac;
        }
        .fnv-delivery__sc-flash--err {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .fnv-delivery__fee {
            padding: 16px 18px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid rgba(89, 125, 87, 0.35);
            box-shadow: 0 4px 14px rgba(89, 125, 87, 0.08);
        }
        .fnv-delivery__fee-title {
            margin: 0 0 12px;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #597D57;
        }
        .fnv-delivery__fee-rows {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .fnv-delivery__fee-rows li {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            font-size: .9rem;
            color: #4b5563;
        }
        .fnv-delivery__fee-rows li span:last-child {
            font-weight: 600;
            color: #1f2937;
            white-space: nowrap;
        }
        .fnv-delivery__fee-total {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px dashed rgba(89, 125, 87, 0.35);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .fnv-delivery__fee-total-label {
            font-size: .95rem;
            font-weight: 700;
            color: #3d563c;
        }
        .fnv-delivery__fee-total-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: #CC583F;
        }
        .fnv-sc-section {
            margin: 28px 0;
            padding: 20px 22px;
            border-radius: 16px;
            background: linear-gradient(180deg, #f4f8f4 0%, #fff 100%);
            border: 1px solid rgba(89, 125, 87, 0.4);
        }
        .fnv-sc-section__title {
            margin: 0 0 8px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #3d563c;
        }
        .fnv-sc-section__lead {
            margin: 0 0 16px;
            font-size: .88rem;
            color: #4b5563;
            line-height: 1.55;
        }
        .fnv-sc-section__wait {
            margin: 12px 0 0;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fffbeb;
            color: #92400e;
            font-size: .86rem;
            line-height: 1.5;
            border: 1px solid #fcd34d;
        }
        .fnv-delivery__fee-note {
            margin: 10px 0 0;
            font-size: .8rem;
            color: #6b7280;
            line-height: 1.45;
        }
        .fnv-delivery__fee-foundation {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(89, 125, 87, 0.08);
            font-size: .85rem;
            color: #3d563c;
        }
        .fnv-delivery__fee-foundation strong {
            color: #597D57;
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

    <?php if ($showServiceChargeBlock): ?>
    <section class="fnv-sc-section" aria-labelledby="fnv-sc-title">
        <?php if ($scFlash !== ''): ?>
        <p class="fnv-delivery__sc-flash <?= isset($_GET['sc_paid']) ? 'fnv-delivery__sc-flash--ok' : 'fnv-delivery__sc-flash--err' ?>">
            <?= htmlspecialchars($scFlash, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php endif; ?>
        <h2 id="fnv-sc-title" class="fnv-sc-section__title">💳 ค่าบริการระบบ (<?= $serviceChargePctLabel ?>%)</h2>
        <p class="fnv-sc-section__lead">
            ชำระหลังจากผู้บริจาคบริจาคครบยอดเป้าหมายสิ่งของแล้ว
            — แอดมินจะดำเนินการจัดซื้อและจัดส่งให้หลังมูลนิธิชำระค่าบริการเรียบร้อย
        </p>
        <div class="fnv-delivery__fee" style="margin:0;">
            <ul class="fnv-delivery__fee-rows">
                <li>
                    <span>ยอดบริจาคที่ได้รับ (รายการนี้)</span>
                    <span><?= number_format($raised, 2) ?> บาท</span>
                </li>
                <li>
                    <span>ค่าบริการ <?= $serviceChargePctLabel ?>%</span>
                    <span><?= number_format($serviceChargeItem, 2) ?> บาท</span>
                </li>
            </ul>
            <div class="fnv-delivery__fee-total">
                <span class="fnv-delivery__fee-total-label">ยอดที่ต้องชำระหลังครบเป้าหมาย</span>
                <span class="fnv-delivery__fee-total-value"><?= number_format($serviceChargeItem, 2) ?> บาท</span>
            </div>
            <?php if ($serviceChargePaid): ?>
            <p class="fnv-delivery__fee-paid">✅ ชำระค่าบริการระบบแล้ว — รอแอดมินดำเนินการจัดส่ง</p>
            <?php elseif ($canPayServiceCharge): ?>
            <a href="payment/needlist_service_charge.php?item_id=<?= (int) $itemId ?>" class="fnv-delivery__pay-btn">
                💳 ชำระค่าบริการ <?= number_format($serviceChargeItem, 2) ?> บาท
            </a>
            <?php endif; ?>
        </div>
        <?php if ($waitingAdminAfterScPaid): ?>
        <p class="fnv-sc-section__wait">แอดมินได้รับแจ้งแล้วว่าคุณชำระค่าบริการ — จะเริ่มจัดซื้อและจัดส่งให้ในลำดับถัดไป</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

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
        <div class="foundation-project-view-row foundation-project-view-row--end-date">
            <dt>วันสิ้นสุดรับบริจาคอัตโนมัติ (รอบ 1 เดือน)</dt>
            <dd><?= htmlspecialchars($dweFmt) ?></dd>
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

        <?php if (count($lineItemsTable) > 0): ?>
        <table class="fnv-price-table">
            <thead>
                <tr>
                    <th>รายการ</th>
                    <th style="text-align:center">จำนวน</th>
                    <th style="text-align:right">ราคา/ชิ้น</th>
                    <th style="text-align:right">รวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lineItemsTable as $idx => $li): ?>
                <tr>
                    <td><?= htmlspecialchars($itemNamesView[$idx] ?? $li['category'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align:center"><?= number_format($li['qty'], 0) ?></td>
                    <td style="text-align:right"><?= number_format($li['price'], 2) ?> บาท</td>
                    <td style="text-align:right"><?= number_format($li['line_total'], 2) ?> บาท</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right">รวมทั้งหมด</td>
                    <td style="text-align:right"><?= number_format($lineItemsTableTotal, 2) ?> บาท</td>
                </tr>
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

            <?php if ($priceReviewedFmt !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--price">💰</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">แอดมินปรับราคาสิ่งของ</div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($priceReviewedFmt) ?></div>
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

            <?php if ($goalMet): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--approve">🎉</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">ผู้บริจาคบริจาคครบเป้าหมายแล้ว</div>
                    <div class="fnv-timeline-date"><?= $serviceChargePaid ? 'ชำระค่าบริการแล้ว' : 'กรุณาชำระค่าบริการระบบ' ?></div>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($serviceChargePaid && $scPaidFmt !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--approve">💳</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">ชำระค่าบริการระบบแล้ว</div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($scPaidFmt, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($showDeliveryEvidence && $adminDelivery['at_fmt'] !== ''): ?>
            <li class="fnv-timeline-item">
                <span class="fnv-timeline-dot fnv-timeline-dot--approve">📦</span>
                <div class="fnv-timeline-body">
                    <div class="fnv-timeline-label">แอดมินยืนยันจัดส่งแล้ว</div>
                    <div class="fnv-timeline-date"><?= htmlspecialchars($adminDelivery['at_fmt'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($adminDelivery['text'] !== ''): ?>
                    <p style="margin:6px 0 0;font-size:.84rem;color:#4b5563;"><?= htmlspecialchars(mb_strlen($adminDelivery['text'], 'UTF-8') > 120 ? (mb_substr($adminDelivery['text'], 0, 120, 'UTF-8') . '…') : $adminDelivery['text'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <?php if ($showDeliveryEvidence): ?>
    <section class="fnv-delivery" aria-labelledby="fnv-delivery-title">
        <div class="fnv-delivery__head">
            <h2 id="fnv-delivery-title">📦 หลักฐานการจัดส่งจากแอดมิน</h2>
        </div>
        <div class="fnv-delivery__body<?= $deliveryImages === [] ? ' fnv-delivery__body--solo' : '' ?>">
            <?php if ($deliveryImages !== []): ?>
            <div class="fnv-delivery__gallery">
                <?php $mainEv = $deliveryImages[0]; ?>
                <a class="fnv-delivery__main-img"
                   href="uploads/evidence/<?= htmlspecialchars($mainEv, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="เปิดรูปหลักฐานขนาดเต็ม">
                    <img src="uploads/evidence/<?= htmlspecialchars($mainEv, ENT_QUOTES, 'UTF-8') ?>"
                         alt="หลักฐานการจัดส่ง"
                         loading="lazy"
                         decoding="async">
                </a>
                <p class="fnv-delivery__caption">คลิกรูปเพื่อดูขนาดเต็ม</p>
            </div>
            <?php endif; ?>
            <div class="fnv-delivery__info">
                <div class="fnv-delivery__status-card">
                    <p class="fnv-delivery__status-title">สถานะการจัดส่ง</p>
                    <p class="fnv-delivery__status-text">
                        <?= $isDoneStatus ? 'แอดมินจัดซื้อและจัดส่งสิ่งของให้มูลนิธิเรียบร้อยแล้ว' : 'แอดมินแนบหลักฐานการจัดส่งแล้ว' ?>
                    </p>
                </div>
                <?php if ($adminDelivery['text'] !== ''): ?>
                <blockquote class="fnv-delivery__desc"><?= nl2br(htmlspecialchars($adminDelivery['text'], ENT_QUOTES, 'UTF-8')) ?></blockquote>
                <?php endif; ?>
                <?php if ($isDoneStatus): ?>
                <p class="fnv-delivery__hint">คุณสามารถโพสต์ผลลัพธ์การระดมสิ่งของให้ผู้บริจาคเห็นได้ในขั้นตอนถัดไป</p>
                <a href="foundation_post_needlist_result.php" class="fnv-delivery__cta">📝 อัปเดตผลลัพธ์ให้ผู้บริจาค</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

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
