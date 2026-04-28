<?php
// admin_view_needlist.php — ดูข้อมูลรายการสิ่งของ (จากไดเรกทอรี — ไม่มีอนุมัติ/ไม่อนุมัติ)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน view needlist

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/notification_audit.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

drawdream_ensure_needlist_schema($conn);

function admin_view_needlist_label_th(string $raw): string
{
    $t = strtolower(trim($raw));

    return match ($t) {
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        default => $raw !== '' ? $raw : '—',
    };
}

function admin_view_needlist_fmt(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '') {
        return '—';
    }
    $t = strtotime($d);
    if ($t === false) {
        return htmlspecialchars($d, ENT_QUOTES, 'UTF-8');
    }
    if (strlen($d) > 12) {
        return date('d/m/Y H:i', $t);
    }

    return date('d/m/Y', $t);
}

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    header('Location: admin_needlist_directory.php');
    exit();
}

$sql = "
    SELECT nl.*, fp.foundation_name, fp.user_id AS foundation_user_id, fp.phone AS fp_phone, fp.address AS fp_address,
           fp.registration_number AS fp_reg, u.email AS fp_email
    FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    LEFT JOIN `user` u ON u.user_id = fp.user_id
    WHERE nl.item_id = ?
    LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param('i', $itemId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    header('Location: admin_needlist_directory.php');
    exit();
}

$flashOk = '';
$flashErr = '';

// Parse need_items_json สำหรับแก้ราคาทีละชิ้น
function admin_view_parse_line_items(array $row): array {
    $raw = trim((string)($row['need_items_json'] ?? ''));
    if ($raw === '') return [];
    try {
        $dec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { return []; }
    if (!is_array($dec)) return [];
    $out = [];
    foreach ($dec as $li) {
        if (!is_array($li)) continue;
        $slot = (int)($li['slot'] ?? 0);
        $qty  = (float)($li['qty_needed'] ?? ($li['qty'] ?? 0));
        if ($slot <= 0 || $qty <= 0) continue;
        $price = (float)($li['price_estimate'] ?? ($li['price'] ?? 0));
        $out[] = [
            'slot'       => $slot,
            'category'   => (string)($li['category'] ?? ''),
            'qty'        => $qty,
            'price'      => $price,
            'line_total' => (float)($li['line_total'] ?? ($qty * $price)),
        ];
    }
    return $out;
}
$adminLineItems = admin_view_parse_line_items($row);
$adminItemNames = array_values(array_filter(array_map('trim', explode(',', (string)($row['item_name'] ?? '')))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_need_price') {
    $postedItemId     = (int)($_POST['item_id'] ?? 0);
    $foundationUserId = (int)($row['foundation_user_id'] ?? 0);
    $apStatus         = strtolower(trim((string)($row['approve_item'] ?? '')));

    if ($postedItemId !== $itemId) {
        $flashErr = 'ไม่พบรายการที่ต้องการแก้ไข';
    } elseif ($apStatus === 'rejected') {
        $flashErr = 'รายการที่ไม่อนุมัติแล้วไม่สามารถแก้ราคาได้';
    } elseif (count($adminLineItems) > 0) {
        // แก้ราคาทีละชิ้น
        $newLineItemsForJson = [];
        $newTotal   = 0.0;
        $priceError = '';
        foreach ($adminLineItems as $li) {
            $slot     = $li['slot'];
            $qty      = $li['qty'];
            $rawPrice = str_replace([',', ' '], '', trim((string)($_POST['item_price_' . $slot] ?? '')));
            $newPrice = (float)$rawPrice;
            if ($newPrice <= 0) {
                $priceError = "ราคารายการที่ {$slot} ต้องมากกว่า 0";
                break;
            }
            $lineTotal  = $qty * $newPrice;
            $newTotal  += $lineTotal;
            $newLineItemsForJson[] = [
                'slot'           => $slot,
                'category'       => $li['category'],
                'qty_needed'     => $qty,
                'price_estimate' => $newPrice,
                'line_total'     => $lineTotal,
            ];
        }
        if ($priceError !== '') {
            $flashErr = $priceError;
        } else {
            $newJsonStr = json_encode($newLineItemsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $adminUid   = (int)($_SESSION['user_id'] ?? 0);
            $upd = $conn->prepare(
                "UPDATE foundation_needlist
                 SET submitted_total_price  = COALESCE(submitted_total_price, total_price),
                     submitted_items_json   = COALESCE(submitted_items_json, need_items_json),
                     total_price            = ?,
                     approved_total_price   = ?,
                     need_items_json        = ?,
                     price_reviewed_by_user_id = ?,
                     price_reviewed_at      = NOW()
                 WHERE item_id = ?
                 LIMIT 1"
            );
            if (!$upd) {
                $flashErr = 'Prepare failed: ' . $conn->error;
            } else {
                $upd->bind_param('ddsii', $newTotal, $newTotal, $newJsonStr, $adminUid, $itemId);
                if ($upd->execute()) {
                    if ($foundationUserId > 0) {
                        $oldTotal = (float)($row['approved_total_price'] ?? ($row['total_price'] ?? 0));
                        $msg  = 'แอดมินอัปเดตราคาสิ่งของรายการ "' . (string)($row['item_name'] ?? '') . '"';
                        $msg .= ' ราคารวมใหม่ ' . number_format($newTotal, 2) . ' บาท';
                        if (abs($oldTotal - $newTotal) > 0.0001) {
                            $msg .= ' (เดิม ' . number_format($oldTotal, 2) . ' บาท)';
                        }
                        drawdream_send_notification(
                            $conn, $foundationUserId,
                            'need_price_updated', 'แอดมินอัปเดตราคาสิ่งของ',
                            $msg, 'foundation.php', 'fdn_need_price:' . $itemId
                        );
                    }
                    header('Location: admin_view_needlist.php?item_id=' . $itemId . '&price_updated=1');
                    exit();
                }
                $flashErr = 'บันทึกราคาไม่สำเร็จ: ' . $upd->error;
            }
        }
    } else {
        // Fallback: แก้ราคารวม (รายการเก่าที่ไม่มี need_items_json)
        $priceInput           = str_replace([',', ' '], '', trim((string)($_POST['admin_total_price'] ?? '')));
        $newApprovedTotal     = (float)$priceInput;
        $currentApprovedTotal = (float)($row['approved_total_price'] ?? ($row['total_price'] ?? 0));
        if ($newApprovedTotal <= 0) {
            $flashErr = 'ราคาที่ต้องการแก้ไขต้องมากกว่า 0';
        } else {
            $upd = $conn->prepare(
                "UPDATE foundation_needlist
                 SET submitted_total_price = COALESCE(submitted_total_price, total_price),
                     total_price = ?,
                     approved_total_price = ?,
                     price_reviewed_by_user_id = ?,
                     price_reviewed_at = NOW()
                 WHERE item_id = ?
                 LIMIT 1"
            );
            if (!$upd) {
                $flashErr = 'Prepare failed: ' . $conn->error;
            } else {
                $adminUid = (int)($_SESSION['user_id'] ?? 0);
                $upd->bind_param('ddii', $newApprovedTotal, $newApprovedTotal, $adminUid, $itemId);
                if ($upd->execute()) {
                    if ($foundationUserId > 0) {
                        $msg  = 'แอดมินอัปเดตราคาสิ่งของรายการ "' . (string)($row['item_name'] ?? '') . '"';
                        $msg .= ' เป็น ' . number_format($newApprovedTotal, 2) . ' บาท';
                        if (abs($currentApprovedTotal - $newApprovedTotal) > 0.0001) {
                            $msg .= ' (เดิม ' . number_format($currentApprovedTotal, 2) . ' บาท)';
                        }
                        drawdream_send_notification(
                            $conn, $foundationUserId,
                            'need_price_updated', 'แอดมินอัปเดตราคาสิ่งของ',
                            $msg, 'foundation.php', 'fdn_need_price:' . $itemId
                        );
                    }
                    header('Location: admin_view_needlist.php?item_id=' . $itemId . '&price_updated=1');
                    exit();
                }
                $flashErr = 'บันทึกราคาไม่สำเร็จ: ' . $upd->error;
            }
        }
    }
}

if (isset($_GET['price_updated']) && $_GET['price_updated'] === '1') {
    $flashOk = 'บันทึกราคาใหม่เรียบร้อยแล้ว';
    $st = $conn->prepare($sql);
    $st->bind_param('i', $itemId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: $row;
    // Re-parse หลัง re-fetch
    $adminLineItems = admin_view_parse_line_items($row);
    $adminItemNames = array_values(array_filter(array_map('trim', explode(',', (string)($row['item_name'] ?? '')))));
}

$endRaw = trim((string)($row['donate_window_end_at'] ?? ''));
$endStatLabel = $endRaw !== '' ? admin_view_needlist_fmt($endRaw) : '—';
$goalNl = (float)($row['total_price'] ?? 0);
$raisedNl = (float)($row['current_donate'] ?? 0);
$pctNl = ($goalNl > 0) ? (int)min(100, round(($raisedNl / $goalNl) * 100)) : 0;
$imgs = foundation_needlist_item_filenames_from_row($row);
$mainImg = $imgs[0] ?? '';
$imgUrl = $mainImg !== '' ? 'uploads/needs/' . htmlspecialchars($mainImg, ENT_QUOTES, 'UTF-8') : '';
$urgent = (int)($row['urgent'] ?? 0) === 1;
$apRaw = trim((string)($row['approve_item'] ?? ''));
$apLower = strtolower($apRaw);
$apLabel = admin_view_needlist_label_th($apRaw);
$createdRaw = trim((string)($row['created_at'] ?? ''));
$createdLabel = ($createdRaw !== '' && strpos($createdRaw, '0000-00-00') !== 0)
    ? admin_view_needlist_fmt($createdRaw)
    : '—';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลรายการสิ่งของ | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
    <link rel="stylesheet" href="css/admin_record_view.css">
    <style>
        .admin-need-price-panel {
            margin: 18px 0 0;
            padding: 18px;
            border-radius: 18px;
            background: #f8f7ff;
            border: 1px solid #e7e2ff;
        }
        .admin-need-price-panel h3 {
            margin: 0 0 8px;
            font-size: 1.05rem;
            color: #31245b;
        }
        .admin-need-price-panel p {
            margin: 0 0 12px;
            color: #6b7280;
            font-size: .93rem;
        }
        .admin-need-price-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .admin-need-price-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 12px;
            background: #fff;
            border: 1px solid #ddd6fe;
            font-size: .88rem;
            color: #4b5563;
        }
        .admin-need-price-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
        }
        .admin-need-price-form label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-weight: 600;
            color: #374151;
        }
        .admin-need-price-form input {
            min-width: 180px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font: inherit;
        }
        .admin-need-price-btn {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            background: #4e3b84;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .admin-need-price-flash {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: .95rem;
            font-weight: 600;
        }
        .admin-need-price-flash--ok {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .admin-need-price-flash--err {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .admin-need-price-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: .93rem;
        }
        .admin-need-price-table th,
        .admin-need-price-table td {
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .admin-need-price-table thead th {
            background: #f3f0ff;
            color: #4e3b84;
            font-weight: 700;
        }
        .admin-need-price-table tfoot td {
            background: #f9fafb;
            font-weight: 700;
        }
        .admin-need-price-input {
            width: 120px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 6px 10px;
            font: inherit;
        }
    </style>
</head>
<body class="admin-record-view-page">
<?php include 'navbar.php'; ?>

<div class="admin-record-shell">
    <div class="admin-record-back">
        <a href="admin_needlist_directory.php">← กลับไปรายการสิ่งของทั้งหมด</a>
    </div>
    <article class="admin-record-sheet">
        <header class="admin-record-sheet__head">
            <h1>ข้อมูลรายการสิ่งของ</h1>
            <p class="admin-record-sheet__sub"><?= htmlspecialchars((string)($row['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <?php if ($flashOk !== ''): ?>
            <div class="admin-need-price-flash admin-need-price-flash--ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== ''): ?>
            <div class="admin-need-price-flash admin-need-price-flash--err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="admin-record-body">
            <div class="admin-record-media">
                <?php if ($imgUrl !== ''): ?>
                    <img src="<?= $imgUrl ?>" alt="รูปสิ่งของ">
                <?php else: ?>
                    <div class="admin-record-ph">ไม่มีรูป</div>
                <?php endif; ?>
            </div>
            <div class="admin-record-grid">
                <div class="admin-record-field">
                    <div class="admin-record-k">รหัสรายการ</div>
                    <div class="admin-record-v"><?= (int)($row['item_id'] ?? 0) ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">สถานะการตรวจสอบ</div>
                    <div class="admin-record-v"><?= htmlspecialchars($apLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ความเร่งด่วน</div>
                    <div class="admin-record-v"><?= $urgent ? 'ต้องการด่วน' : 'ปกติ' ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันที่เสนอรายการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ชื่อรายการ / สิ่งของ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">อีเมลติดต่อมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['fp_email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เบอร์โทรมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['fp_phone'] ?? '')) !== '' ? (string)$row['fp_phone'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ที่อยู่มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['fp_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if (trim((string)($row['fp_reg'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">เลขทะเบียนมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['fp_reg'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if (array_key_exists('category', $row) && trim((string)($row['category'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">หมวด / ประเภท</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['category'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">แบรนด์ที่ต้องการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['desired_brand'] ?? '')) !== '' ? (string)$row['desired_brand'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">หมวดหมู่สิ่งของ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['brand'] ?? '')) !== '' ? (string)$row['brand'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ยอดเงินเป้าหมายรวม (บาท)</div>
                    <?php
                        $vTotal = (float)($row['total_price'] ?? 0);
                    ?>
                    <div class="admin-record-v"><?= number_format($vTotal, 2) ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">หมายเหตุจากมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['note'] ?? '')) !== '' ? (string)$row['note'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันตรวจสอบล่าสุด</div>
                    <div class="admin-record-v"><?= htmlspecialchars(admin_view_needlist_fmt(isset($row['reviewed_at']) ? (string)$row['reviewed_at'] : ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ปิดรับบริจาคอัตโนมัติ (รอบ 1 เดือน)</div>
                    <div class="admin-record-v"><?= htmlspecialchars($endStatLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if ($apLower !== 'pending' && trim((string)($row['review_note'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">หมายเหตุจากแอดมิน</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['review_note'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="admin-record-stats donation-stats-panel" aria-label="สรุปตัวเลขรายการสิ่งของ">
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-bullseye"></i></div>
                        <div class="stat-num"><?= number_format($goalNl, 0, '.', ',') ?></div>
                        <div class="stat-label">เป้าหมายรายการ (บาท)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
                        <div class="stat-num"><?= number_format($raisedNl, 0, '.', ',') ?></div>
                        <div class="stat-label">ยอดรับแล้ว (บาท)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="stat-num"><?= $pctNl ?>%</div>
                        <div class="stat-label">ความคืบหน้า</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
                        <div class="stat-num stat-num--date"><?= htmlspecialchars($endStatLabel) ?></div>
                        <div class="stat-label">ปิดรับอัตโนมัติ (1 เดือน)</div>
                    </div>
                </div>
            </div>

            <?php
                $submittedTotalView = (float)($row['submitted_total_price'] ?? ($row['total_price'] ?? 0));
                $approvedTotalView = (float)($row['approved_total_price'] ?? ($row['total_price'] ?? 0));
                $reviewPriceRaw = trim((string)($row['price_reviewed_at'] ?? ''));
                $reviewPriceLabel = ($reviewPriceRaw !== '' && !str_starts_with($reviewPriceRaw, '0000-00-00') && strtotime($reviewPriceRaw) !== false)
                    ? date('d/m/Y H:i', strtotime($reviewPriceRaw))
                    : 'ยังไม่เคยแก้ราคา';
            ?>
            <section class="admin-need-price-panel">
                <h3>ปรับราคาสิ่งของโดยแอดมิน</h3>
                <p>แก้ราคาได้แม้รายการจะอนุมัติแล้ว ไม่ต้องกลับไปสถานะ pending</p>
                <div class="admin-need-price-meta">
                    <span class="admin-need-price-chip">ราคาที่มูลนิธิเสนอ: <?= number_format($submittedTotalView, 2) ?> บาท</span>
                    <span class="admin-need-price-chip">ราคาที่ใช้ปัจจุบัน: <?= number_format($approvedTotalView, 2) ?> บาท</span>
                    <span class="admin-need-price-chip">อัปเดตราคาล่าสุด: <?= htmlspecialchars($reviewPriceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_need_price">
                    <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
                    <?php if (count($adminLineItems) > 0): ?>
                        <table class="admin-need-price-table">
                            <thead>
                                <tr>
                                    <th>รายการ</th>
                                    <th style="text-align:center">จำนวน (ชิ้น)</th>
                                    <th style="text-align:right">ราคา/ชิ้น (เดิม)</th>
                                    <th style="text-align:right">ราคา/ชิ้น (แอดมินปรับ)</th>
                                    <th style="text-align:right">รวม</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminLineItems as $idx => $li): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($adminItemNames[$idx] ?? $li['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="text-align:center"><?= number_format($li['qty'], 0) ?></td>
                                        <td style="text-align:right"><?= number_format($li['price'], 2) ?> บาท</td>
                                        <td style="text-align:right">
                                            <input type="number"
                                                   name="item_price_<?= (int)$li['slot'] ?>"
                                                   class="admin-need-price-input admin-need-line-price"
                                                   data-qty="<?= (float)$li['qty'] ?>"
                                                   min="0.01" step="0.01"
                                                   value="<?= htmlspecialchars(number_format($li['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                                   required>
                                        </td>
                                        <td style="text-align:right" class="admin-need-line-total">
                                            <?= number_format($li['line_total'], 2) ?> บาท
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" style="text-align:right">ราคารวมใหม่ทั้งหมด:</td>
                                    <td style="text-align:right" id="admin-price-grand-total">
                                        <?= number_format(array_sum(array_column($adminLineItems, 'line_total')), 2) ?> บาท
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php else: ?>
                        <div class="admin-need-price-form">
                            <label>
                                ราคาสุดท้าย (แอดมิน)
                                <input type="number" name="admin_total_price" min="0.01" step="0.01"
                                       value="<?= htmlspecialchars(number_format($approvedTotalView, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </label>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="admin-need-price-btn" style="margin-top:12px">บันทึกราคาใหม่</button>
                </form>
            </section>
        </div>
    </article>
</div>
<script>
(function () {
    var lineInputs = document.querySelectorAll('.admin-need-line-price');
    var grandTotal = document.getElementById('admin-price-grand-total');
    if (!lineInputs.length || !grandTotal) return;
    function recalc() {
        var total = 0;
        lineInputs.forEach(function (inp) {
            var qty = parseFloat(inp.dataset.qty || '0');
            var price = parseFloat(inp.value || '0');
            var lineCell = inp.closest('tr').querySelector('.admin-need-line-total');
            var lineTotal = qty * price;
            total += lineTotal;
            if (lineCell) {
                lineCell.textContent = lineTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท';
            }
        });
        grandTotal.textContent = total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท';
    }
    lineInputs.forEach(function (inp) { inp.addEventListener('input', recalc); });
})();
</script>
</body>
</html>
