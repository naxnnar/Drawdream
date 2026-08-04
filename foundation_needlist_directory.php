<?php
// foundation_needlist_directory.php — รายการสิ่งของของมูลนิธิ (มุมมองตารางแบบแอดมิน)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/includes/foundation_donor_preview.php';
require_once __DIR__ . '/includes/foundation_need_flash.php';

drawdream_foundation_require_management_access();

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$uid = (int)$_SESSION['user_id'];
$stFp = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFp) {
    header('Location: profile.php');
    exit();
}
$stFp->bind_param('i', $uid);
$stFp->execute();
$fp = $stFp->get_result()->fetch_assoc();
$foundationId = (int)($fp['foundation_id'] ?? 0);
$foundationName = trim((string)($fp['foundation_name'] ?? ''));
if ($foundationId <= 0) {
    header('Location: update_profile.php');
    exit();
}

$taskFocus = trim((string)($_GET['task'] ?? ''));

$stRows = $conn->prepare(
    'SELECT * FROM foundation_needlist WHERE foundation_id = ? ORDER BY item_id DESC'
);
$rows = [];
if ($stRows) {
    $stRows->bind_param('i', $foundationId);
    $stRows->execute();
    $rows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);
}

function foundation_need_status_th(string $ap): string
{
    $map = [
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        'purchasing' => 'กำลังจัดซื้อ',
        'done' => 'เสร็จสิ้น',
    ];
    return $map[strtolower(trim($ap))] ?? ($ap !== '' ? $ap : '-');
}

function foundation_need_status_class(string $ap): string
{
    $t = strtolower(trim($ap));
    if ($t === 'approved' || $t === 'done') {
        return 'admin-pill admin-pill--success';
    }
    if ($t === 'pending' || $t === 'purchasing') {
        return 'admin-pill admin-pill--warning';
    }
    if ($t === 'rejected') {
        return 'admin-pill admin-pill--danger';
    }
    return 'admin-pill admin-pill--neutral';
}

function foundation_need_progress_pct(float $current, float $goal): int
{
    if ($goal <= 0) {
        return 0;
    }
    return (int)max(0, min(100, round(($current / $goal) * 100)));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>รายการสิ่งของทั้งหมด | <?= htmlspecialchars($foundationName) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/foundation_manage.css?v=2">
</head>
<body class="foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <a href="foundation_dashboard.php" class="admin-directory-back" data-foundation-back>← กลับ</a>
        <h1 class="admin-directory-title">รายการสิ่งของทั้งหมด</h1>
    </div>
    <?= drawdream_foundation_need_flash_render_html() ?>
    <?php if ($taskFocus === 'service_charge'): ?>
    <div style="margin:0 0 14px;padding:12px 14px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;color:#92400e;font-size:.9rem;line-height:1.5;">
        ชำระค่าบริการระบบทำได้ทีละรายการ — เปิด «ขั้นตอน» ของรายการที่ครบเป้าแล้ว แล้วชำระค่าบริการ
    </div>
    <?php endif; ?>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>รูป</th>
                <th>รายการ</th>
                <th>มูลนิธิ</th>
                <th>สถานะ</th>
                <th class="admin-dir-num">จำนวน</th>
                <th class="admin-dir-num">ราคาโดยประมาณ</th>
                <th>ความสำเร็จ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows !== []): ?>
                <?php foreach ($rows as $r):
                    $iid = (int)($r['item_id'] ?? 0);
                    $ap = (string)($r['approve_item'] ?? '');
                    $imgs = foundation_needlist_item_filenames_from_row($r);
                    $thumb = $imgs[0] ?? '';
                    $qty = (float)($r['qty_needed'] ?? 0);
                    $goal = (float)($r['total_price'] ?? 0);
                    $price = $qty > 0 ? ($goal / $qty) : 0.0;
                    $cur = (float)($r['current_donate'] ?? 0);
                    $pct = foundation_need_progress_pct($cur, $goal);
                    $apLower = strtolower(trim($ap));
                    $needScDue = $apLower === 'approved' && $goal > 0 && $cur >= $goal - 0.01
                        && trim((string)($r['service_charge_paid_at'] ?? '')) === '';
                    ?>
                    <tr>
                        <td>
                            <?php if ($thumb !== ''): ?>
                                <img class="admin-dir-thumb" src="uploads/needs/<?= htmlspecialchars($thumb) ?>" alt="">
                            <?php else: ?>
                                <span class="b--muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($r['item_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($foundationName) ?></td>
                        <td>
                            <span class="<?= htmlspecialchars(foundation_need_status_class($ap)) ?>">
                                <?= htmlspecialchars(foundation_need_status_th($ap)) ?>
                            </span>
                        </td>
                        <td class="admin-dir-num"><?= htmlspecialchars((string)($r['qty_needed'] ?? '0')) ?></td>
                        <td class="admin-dir-num"><?= number_format($price, 0) ?></td>
                        <td>
                            <div style="min-width:140px;">
                                <div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
                                    <div style="height:8px;background:#4A5BA8;width:<?= $pct ?>%;"></div>
                                </div>
                                <div style="font-size:.85rem;color:#4b5563;margin-top:4px;"><?= $pct ?>%</div>
                            </div>
                        </td>
                        <td>
                            <a class="admin-dir-btn admin-dir-btn--primary" href="foundation_need_wizard.php?item_id=<?= $iid ?>">ขั้นตอน</a>
                            <?php if ($taskFocus === 'service_charge' && $needScDue): ?>
                            <a class="admin-dir-btn admin-dir-btn--ghost" href="payment/needlist_service_charge.php?item_id=<?= $iid ?>">ชำระค่าบริการ</a>
                            <?php endif; ?>
                            <a class="admin-dir-btn" href="foundation_need_view.php?id=<?= $iid ?>">รายละเอียด</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีรายการสิ่งของ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

