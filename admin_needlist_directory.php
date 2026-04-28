<?php
// admin_needlist_directory.php — รายการสิ่งของทั้งหมด (มุมมองแอดมิน)

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน needlist directory

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/drawdream_needlist_schema.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

drawdream_ensure_needlist_schema($conn);

$sql = "
    SELECT nl.*, fp.foundation_name
    FROM foundation_needlist nl
    JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
    ORDER BY nl.item_id DESC
";
$rows = $conn->query($sql);

function admin_needlist_status_th(string $ap): string
{
    $map = [
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
    ];
    return $map[$ap] ?? ($ap !== '' ? $ap : '-');
}

function admin_needlist_status_pill_class(string $ap): string
{
    $t = strtolower(trim($ap));
    if ($t === 'approved') {
        return 'admin-pill admin-pill--success';
    }
    if ($t === 'pending') {
        return 'admin-pill admin-pill--warning';
    }
    if ($t === 'rejected') {
        return 'admin-pill admin-pill--danger';
    }
    return 'admin-pill admin-pill--neutral';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการสิ่งของทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <style>
        .admin-needlist-head-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .admin-needlist-head-note {
            font-size: 13px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">รายการสิ่งของทั้งหมด</h1>
        <div class="admin-needlist-head-actions">
            <a class="admin-dir-btn admin-dir-btn--primary" href="admin_approve_needlist.php">อนุมัติสิ่งของ</a>
            <span class="admin-needlist-head-note">กดปุ่ม "สิ่งของ / แก้ราคา" ในแต่ละแถวเพื่อแก้ราคาทีละชิ้น</span>
        </div>
    </div>

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
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $iid = (int)$r['item_id'];
                    $ap = (string)($r['approve_item'] ?? '');
                    $imgs = foundation_needlist_item_filenames_from_row($r);
                    $thumb = $imgs[0] ?? '';
                    $detailUrl = 'admin_view_needlist.php?item_id=' . $iid;
                    $totalsUrl = 'admin_needlist_totals.php?item_id=' . $iid;
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
                        <td><?= htmlspecialchars((string)($r['foundation_name'] ?? '')) ?></td>
                        <td>
                            <span class="<?= htmlspecialchars(admin_needlist_status_pill_class($ap)) ?>">
                                <?= htmlspecialchars(admin_needlist_status_th($ap)) ?>
                            </span>
                        </td>
                        <td class="admin-dir-num"><?= htmlspecialchars((string)($r['qty_needed'] ?? '0')) ?></td>
                        <?php
                            $dQty = (float)($r['qty_needed'] ?? 0);
                            $dTotal = (float)($r['total_price'] ?? 0);
                            $dUnit = $dQty > 0 ? ($dTotal / $dQty) : 0.0;
                        ?>
                        <td class="admin-dir-num"><?= number_format($dUnit, 0) ?></td>
                        <td>
                            <div class="admin-dir-actions admin-dir-actions--pair">
                                <a class="admin-dir-btn admin-dir-btn--primary" href="<?= htmlspecialchars($detailUrl) ?>">สิ่งของ / แก้ราคา</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost" href="<?= htmlspecialchars($totalsUrl) ?>">ยอดสิ่งของ</a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="b--muted">ยังไม่มีรายการสิ่งของ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
