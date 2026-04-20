<?php
// admin_needlist_view.php — แอดมิน: ตรวจสอบรายการสิ่งของ (อนุมัติ/ไม่อนุมัติที่หน้านี้เมื่อสถานะ pending)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน needlist view

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

$dirUrl = 'admin_needlist_directory.php';

function admin_needlist_view_approve_label_th(string $raw): string
{
    $t = strtolower(trim($raw));

    return match ($t) {
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        default => $raw !== '' ? $raw : '—',
    };
}

function admin_needlist_view_approve_badge_class(string $raw): string
{
    $t = strtolower(trim($raw));
    if ($t === 'approved') {
        return 'status-approved';
    }
    if ($t === 'rejected') {
        return 'status-rejected';
    }

    return 'status-pending';
}

/** @param array<string,mixed> $row */
function admin_needlist_view_format_date(?string $d): string
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
    header('Location: ' . $dirUrl);
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
    header('Location: ' . $dirUrl);
    exit();
}

$endRaw = trim((string)($row['donate_window_end_at'] ?? ''));
$endStatLabel = $endRaw !== '' ? admin_needlist_view_format_date($endRaw) : '—';

$goalNl = (float)($row['total_price'] ?? 0);
$raisedNl = (float)($row['current_donate'] ?? 0);
$pctNl = ($goalNl > 0) ? (int)min(100, round(($raisedNl / $goalNl) * 100)) : 0;

$imgs = foundation_needlist_item_filenames_from_row($row);
$mainImg = $imgs[0] ?? '';
$imgUrl = $mainImg !== '' ? 'uploads/needs/' . $mainImg : '';

$allowOther = (int)($row['allow_other_brand'] ?? 0) === 1;
$urgent = (int)($row['urgent'] ?? 0) === 1;
$apRaw = trim((string)($row['approve_item'] ?? ''));
$apLower = strtolower($apRaw);
$apLabel = admin_needlist_view_approve_label_th($apRaw);
$apBadgeClass = admin_needlist_view_approve_badge_class($apRaw);
$createdRaw = trim((string)($row['created_at'] ?? ''));
$createdLabel = ($createdRaw !== '' && strpos($createdRaw, '0000-00-00') !== 0)
    ? admin_needlist_view_format_date($createdRaw)
    : '—';

?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบรายการสิ่งของ | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
</head>
<body class="admin-approve-projects-page">

<?php include 'navbar.php'; ?>

<main class="container-fluid my-4">
    <div class="admin-review-card admin-project-review">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบข้อมูลสิ่งของมูลนิธิ</h4>
                <div>มูลนิธิ: <?= htmlspecialchars((string)($row['foundation_name'] ?? '—')) ?></div>
            </div>
        </div>

        <div class="admin-review-body">
            <div class="row g-4 admin-review-layout">
                <div class="col-lg-4 admin-image-col">
                    <?php if ($imgUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปสิ่งของ" class="admin-child-image">
                    <?php else: ?>
                        <div class="admin-child-image admin-project-cover--empty d-flex align-items-center justify-content-center text-muted border rounded-3" style="min-height:220px;background:#f3f4f6;" role="img" aria-label="ไม่มีรูป">ไม่มีรูป</div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-8 admin-details-col">
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="label">ชื่อรายการ / สิ่งของ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['item_name'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">มูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันที่เสนอรายการ</span>
                            <span class="value"><?= htmlspecialchars($createdLabel) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ความเร่งด่วน</span>
                            <span class="value"><?= $urgent ? 'ต้องการด่วน' : 'ปกติ' ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">รหัสรายการ</span>
                            <span class="value"><?= (int)($row['item_id'] ?? 0) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะการตรวจสอบ</span>
                            <span class="status-badge <?= htmlspecialchars($apBadgeClass) ?>"><?= htmlspecialchars($apLabel) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">อีเมลติดต่อมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['fp_email'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เบอร์โทรมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['fp_phone'] ?? '')) !== '' ? (string)$row['fp_phone'] : '—') ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">ที่อยู่มูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['fp_address'] ?? '—')) ?></span>
                        </div>
                        <?php if (trim((string)($row['fp_reg'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">เลขทะเบียนมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)$row['fp_reg']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (array_key_exists('category', $row) && trim((string)($row['category'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">หมวด / ประเภท</span>
                            <span class="value"><?= htmlspecialchars((string)$row['category']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="data-item full">
                            <span class="label">รายละเอียดสิ่งของ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['item_desc'] ?? '—')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ยี่ห้อที่ต้องการ</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['brand'] ?? '')) !== '' ? (string)$row['brand'] : '—') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">รับยี่ห้ออื่นแทนได้</span>
                            <span class="value"><?= $allowOther ? 'ได้' : 'ไม่ได้' ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">จำนวนที่ต้องการ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['qty_needed'] ?? '0')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ราคาโดยประมาณต่อหน่วย (บาท)</span>
                            <span class="value"><?= number_format((float)($row['price_estimate'] ?? 0), 2) ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">หมายเหตุ / ระยะเวลารับบริจาค (จากมูลนิธิ)</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['note'] ?? '')) !== '' ? (string)$row['note'] : '—') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันตรวจสอบล่าสุด</span>
                            <span class="value"><?= htmlspecialchars(admin_needlist_view_format_date(isset($row['reviewed_at']) ? (string)$row['reviewed_at'] : '')) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ปิดรับบริจาค (ระบบ)</span>
                            <span class="value"><?= htmlspecialchars($endStatLabel) ?></span>
                        </div>
                        <?php if ($apLower !== 'pending' && trim((string)($row['review_note'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">หมายเหตุจากแอดมิน</span>
                            <span class="value"><?= htmlspecialchars((string)$row['review_note']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="donation-stats-panel" aria-label="สรุปตัวเลขรายการสิ่งของ">
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

                    <?php if ($apLower === 'pending'): ?>
                    <p class="admin-review-actions-note">การไม่อนุมัติจะอัปเดตสถานะรายการในระบบ — มูลนิธิสามารถแก้ไขและส่งพิจารณาใหม่ได้</p>
                    <form method="post" action="admin_approve_needlist.php" class="admin-review-actions-form">
                        <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
                        <div class="admin-review-actions-grid">
                            <textarea name="note" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ"></textarea>
                            <button type="submit" name="action" value="approve" class="btn btn-success admin-review-action-btn"
                                    onclick="return confirm('ยืนยันอนุมัติรายการนี้?');">อนุมัติ</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger admin-review-action-btn"
                                    onclick="var t=this.form.querySelector('[name=note]');if(!t||!t.value.trim()){alert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ');if(t)t.focus();return false;}return confirm('ยืนยันไม่อนุมัติรายการนี้?');">ไม่อนุมัติ</button>
                        </div>
                    </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
