<?php
// admin_donors.php — ภาพรวมผู้บริจาค
// รายการผู้บริจาค — มุมมองแอดมิน (ยอดสะสม, ความถี่, ช่องทางติดต่อ)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$donorCols = [];
$cr = @$conn->query('SHOW COLUMNS FROM donor');
if ($cr) {
    while ($c = $cr->fetch_assoc()) {
        $donorCols[(string)($c['Field'] ?? '')] = true;
    }
}
$selPhone = isset($donorCols['phone']) ? 'd.phone' : "NULL AS phone";
$selTax = isset($donorCols['tax_id']) ? 'd.tax_id' : "NULL AS tax_id";

$sql = "
SELECT d.user_id,
       d.first_name,
       d.last_name,
       u.email,
       {$selPhone},
       {$selTax},
       COALESCE((
           SELECT SUM(amount) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ), 0) AS total_given,
       COALESCE((
           SELECT COUNT(*) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ), 0) AS donation_count,
       (
           SELECT MAX(transfer_datetime) FROM donation
           WHERE donor_id = d.user_id AND payment_status = 'completed'
       ) AS last_donation
FROM donor d
JOIN `user` u ON d.user_id = u.user_id
ORDER BY (last_donation IS NULL), last_donation DESC, d.user_id DESC
";
$rows = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผู้บริจาคทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <a href="admin_dashboard.php" class="admin-directory-back">← กลับ Dashboard</a>
        <h1 class="admin-directory-title">ผู้บริจาคทั้งหมด</h1>
        <p class="admin-directory-desc">
            ข้อมูลสำหรับแอดมิน: ตรวจยอดบริจาคสะสม ความถี่ และติดต่อประสานงาน (อีเมล / โทร)
            การระงับหรือแก้ไขบัญชีผู้ใช้ยังไม่เปิดในหน้านี้ — ใช้ฐานข้อมูลหรือนโยบายภายนอกเมื่อจำเป็น
        </p>
    </div>

    <div class="admin-directory-actions-hint">
        <strong>แอดมินทำอะไรได้จากหน้านี้:</strong>
        ตรวจสอบผู้บริจาคที่มีปริมาณบริจาคผิดปกติ · ใช้อีเมล/โทรติดต่อขอหลักฐานหักภาษี · เทียบกับรายการใน
        <a href="admin_dashboard.php">Dashboard</a> (การบริจาคล่าสุด) และ <a href="admin_escrow.php">Escrow</a>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>ชื่อ</th>
                <th>อีเมล</th>
                <th>โทรศัพท์</th>
                <th class="admin-dir-num">ยอดสะสม (บาท)</th>
                <th class="admin-dir-num">ครั้ง</th>
                <th>บริจาคล่าสุด</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                    if ($name === '') {
                        $name = '—';
                    }
                    $email = trim((string)($r['email'] ?? ''));
                    $phone = trim((string)($r['phone'] ?? ''));
                    if ($phone === '') {
                        $phone = '—';
                    }
                    $total = (float)($r['total_given'] ?? 0);
                    $cnt = (int)($r['donation_count'] ?? 0);
                    $last = $r['last_donation'] ?? null;
                    $lastStr = $last ? date('d/m/Y H:i', strtotime((string)$last)) : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($email) ?></td>
                        <td><?= htmlspecialchars($phone) ?></td>
                        <td class="admin-dir-num"><?= number_format($total, 0) ?></td>
                        <td class="admin-dir-num"><?= number_format($cnt, 0) ?></td>
                        <td><?= htmlspecialchars($lastStr) ?></td>
                        <td>
                            <div class="admin-dir-actions">
                                <?php if ($email !== ''): ?>
                                    <a class="admin-dir-btn admin-dir-btn--primary"
                                       href="mailto:<?= htmlspecialchars($email) ?>">ส่งอีเมล</a>
                                <?php endif; ?>
                                <?php if ($phone !== '' && $phone !== '—'): ?>
                                    <a class="admin-dir-btn admin-dir-btn--ghost"
                                       href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $phone)) ?>">โทร</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="b--muted">ยังไม่มีข้อมูลผู้บริจาค</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
