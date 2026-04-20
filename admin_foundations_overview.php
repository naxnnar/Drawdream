<?php
// admin_foundations_overview.php — ภาพรวมมูลนิธิ
// รายการมูลนิธิทั้งหมด — มุมมองแอดมิน
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน foundations overview
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$sql = "
SELECT f.foundation_id,
       f.foundation_name,
       f.account_verified,
       f.created_at,
       u.email AS owner_email,
       (SELECT COUNT(*) FROM foundation_project p
        WHERE p.foundation_id = f.foundation_id AND p.deleted_at IS NULL) AS project_cnt,
       (SELECT COUNT(*) FROM foundation_children c
        WHERE c.foundation_id = f.foundation_id AND c.deleted_at IS NULL) AS child_cnt,
       (SELECT COUNT(*) FROM foundation_needlist n WHERE n.foundation_id = f.foundation_id) AS need_cnt
FROM foundation_profile f
JOIN `user` u ON f.user_id = u.user_id
ORDER BY f.foundation_id DESC
";
$rows = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>มูลนิธิทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">มูลนิธิทั้งหมด</h1>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>ชื่อมูลนิธิ</th>
                <th>สถานะ</th>
                <th>อีเมลเจ้าของบัญชี</th>
                <th class="admin-dir-num">โครงการ</th>
                <th class="admin-dir-num">เด็ก</th>
                <th class="admin-dir-num">รายการสิ่งของ</th>
                <th>สมัครเมื่อ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $fid = (int)$r['foundation_id'];
                    $verifyVal = (int)($r['account_verified'] ?? 0);
                    $created = $r['created_at'] ?? '';
                    $createdStr = $created ? date('d/m/Y', strtotime((string)$created)) : '—';
                    $foundationDetailHref = 'admin_view_foundation.php?id=' . $fid;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r['foundation_name'] ?? '') ?></td>
                        <td>
                            <?php if ($verifyVal === 1): ?>
                                <span class="admin-pill admin-pill--success">ยืนยันแล้ว</span>
                            <?php elseif ($verifyVal === 2): ?>
                                <span class="admin-pill admin-pill--danger">ไม่อนุมัติ (รอแก้ไข)</span>
                            <?php else: ?>
                                <span class="admin-pill admin-pill--warning">รออนุมัติ</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($r['owner_email'] ?? '')) ?></td>
                        <td class="admin-dir-num"><?= (int)($r['project_cnt'] ?? 0) ?></td>
                        <td class="admin-dir-num"><?= (int)($r['child_cnt'] ?? 0) ?></td>
                        <td class="admin-dir-num"><?= (int)($r['need_cnt'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($createdStr) ?></td>
                        <td>
                            <div class="admin-dir-actions admin-dir-actions--foundation">
                                <a class="admin-dir-btn admin-dir-btn--primary"
                                   href="<?= htmlspecialchars($foundationDetailHref, ENT_QUOTES, 'UTF-8') ?>">มูลนิธิ</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost"
                                   href="admin_foundation_totals.php?foundation_id=<?= $fid ?>">ยอดมูลนิธิ</a>
                                <a class="admin-dir-btn admin-dir-btn--analytics"
                                   href="admin_foundation_analytics_view.php?foundation_id=<?= $fid ?>">รายงานเชิงวิเคราะห์</a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีมูลนิธิ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
