<?php
// admin_view_needlist.php — ดูข้อมูลรายการสิ่งของ (จากไดเรกทอรี — ไม่มีอนุมัติ/ไม่อนุมัติ)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน view needlist

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';

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

/** @param array<string,mixed> $row */
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
    SELECT nl.*, fp.foundation_name, fp.phone AS fp_phone, fp.address AS fp_address,
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

$endRaw = trim((string)($row['donate_window_end_at'] ?? ''));
$endStatLabel = $endRaw !== '' ? admin_view_needlist_fmt($endRaw) : '—';
$goalNl = (float)($row['total_price'] ?? 0);
$raisedNl = (float)($row['current_donate'] ?? 0);
$pctNl = ($goalNl > 0) ? (int)min(100, round(($raisedNl / $goalNl) * 100)) : 0;
$imgs = foundation_needlist_item_filenames_from_row($row);
$mainImg = $imgs[0] ?? '';
$imgUrl = $mainImg !== '' ? 'uploads/needs/' . htmlspecialchars($mainImg, ENT_QUOTES, 'UTF-8') : '';
$allowOther = (int)($row['allow_other_brand'] ?? 0) === 1;
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
                    <div class="admin-record-k">รายละเอียดสิ่งของ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['item_desc'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ยี่ห้อที่ต้องการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['brand'] ?? '')) !== '' ? (string)$row['brand'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">รับยี่ห้ออื่นแทนได้</div>
                    <div class="admin-record-v"><?= $allowOther ? 'ได้' : 'ไม่ได้' ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">จำนวนที่ต้องการ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['qty_needed'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ราคาโดยประมาณต่อหน่วย (บาท)</div>
                    <div class="admin-record-v"><?= number_format((float)($row['price_estimate'] ?? 0), 2) ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">หมายเหตุ / ระยะเวลารับบริจาค (จากมูลนิธิ)</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['note'] ?? '')) !== '' ? (string)$row['note'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันตรวจสอบล่าสุด</div>
                    <div class="admin-record-v"><?= htmlspecialchars(admin_view_needlist_fmt(isset($row['reviewed_at']) ? (string)$row['reviewed_at'] : ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ปิดรับบริจาค (ระบบ)</div>
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
                        <div class="stat-label">ปิดรับบริจาค</div>
                    </div>
                </div>
            </div>
        </div>
    </article>
</div>
</body>
</html>
