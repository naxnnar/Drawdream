<?php
// admin_needlist_directory.php — รายการสิ่งของทั้งหมด (มุมมองแอดมิน)

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
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <a href="admin_dashboard.php" class="admin-directory-back">← กลับ Dashboard</a>
        <h1 class="admin-directory-title">รายการสิ่งของทั้งหมด</h1>
        <p class="admin-directory-desc">
            รายการสิ่งของจากมูลนิธิทั้งหมด — สถานะการอนุมัติ และลิงก์ไปหน้าสาธารณะ / คิวอนุมัติ
        </p>
    </div>

    <div class="admin-directory-actions-hint">
        <strong>คำขอรออนุมัติ:</strong> ดูที่ไอคอนกระดิ่ง
        <a href="admin_notifications.php#admin-pending-needs">ศูนย์รวมคำขอ</a>
        หรือไปที่
        <a href="admin_approve_needlist.php">คิวอนุมัติสิ่งของ</a>
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
                    $isPending = $ap === 'pending';
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
                        <td class="admin-dir-num"><?= number_format((float)($r['price_estimate'] ?? 0), 0) ?></td>
                        <td>
                            <div class="admin-dir-actions">
                                <a class="admin-dir-btn admin-dir-btn--primary" href="foundation.php">หน้ามูลนิธิ</a>
                                <?php if ($isPending): ?>
                                    <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_approve_needlist.php">คิวอนุมัติ</a>
                                <?php endif; ?>
                                <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_dashboard.php">Dashboard</a>
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
