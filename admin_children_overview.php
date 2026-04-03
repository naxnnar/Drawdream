<?php
// เด็กทั้งหมดในระบบ — มุมมองแอดมิน
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$sql = "
SELECT c.child_id,
       c.child_name,
       c.foundation_name,
       c.approve_profile,
       c.status,
       c.photo_child,
       c.foundation_id,
       f.foundation_name AS fp_name
FROM foundation_children c
LEFT JOIN foundation_profile f ON c.foundation_id = f.foundation_id
WHERE c.deleted_at IS NULL
ORDER BY c.child_id DESC
";
$rows = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เด็กทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <a href="admin_dashboard.php" class="admin-directory-back">← กลับ Dashboard</a>
        <h1 class="admin-directory-title">เด็กทั้งหมด</h1>
        <p class="admin-directory-desc">
            รายชื่อเด็กจากมูลนิธิ: สถานะโปรไฟล์ การอนุมัติ และลิงก์ไปหน้าบริจาคสาธารณะ
            ใช้ตรวจเนื้อหาและประสานให้โปรไฟล์ที่อนุมัติแล้วแสดงถูกต้อง
        </p>
    </div>

    <div class="admin-directory-actions-hint">
        <strong>แอดมินทำอะไรได้จากหน้านี้:</strong>
        ตรวจสถานะอนุมัติโปรไฟล์ · เปิดเพจบริจาคเพื่อดูภาพและข้อความที่ donor → ใช้
        <a href="admin_approve_children.php">อนุมัติโปรไฟล์เด็ก</a> เมื่อมีคิวรอตรวจ
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>รูป</th>
                <th>ชื่อเด็ก</th>
                <th>มูลนิธิ</th>
                <th>สถานะโปรไฟล์</th>
                <th>สถานะอุปการะ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $cid = (int)$r['child_id'];
                    $photo = trim((string)($r['photo_child'] ?? ''));
                    $fn = trim((string)($r['foundation_name'] ?? ''));
                    if ($fn === '') {
                        $fn = trim((string)($r['fp_name'] ?? ''));
                    }
                    if ($fn === '') {
                        $fn = '—';
                    }
                    $ap = trim((string)($r['approve_profile'] ?? 'รอดำเนินการ'));
                    $st = trim((string)($r['status'] ?? ''));
                    if ($st === '') {
                        $st = '—';
                    }
                    $imgSrc = $photo !== '' ? 'uploads/Children/' . rawurlencode($photo) : '';
                    ?>
                    <tr>
                        <td>
                            <?php if ($imgSrc !== ''): ?>
                                <img class="admin-dir-thumb" src="<?= htmlspecialchars($imgSrc) ?>" alt="">
                            <?php else: ?>
                                <span class="b--muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($r['child_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($fn) ?></td>
                        <td><?= htmlspecialchars($ap) ?></td>
                        <td><?= htmlspecialchars($st) ?></td>
                        <td>
                            <div class="admin-dir-actions">
                                <a class="admin-dir-btn admin-dir-btn--primary"
                                   href="children_donate.php?id=<?= $cid ?>">หน้าบริจาค</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost"
                                   href="admin_approve_children.php">คิวอนุมัติ</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost" href="children_.php">รายการเด็ก</a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="b--muted">ยังไม่มีโปรไฟล์เด็ก</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
