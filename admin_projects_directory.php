<?php
// admin_projects_directory.php — โครงการทั้งหมด (มุมมองแอดมิน)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$sql = "
    SELECT p.project_id, p.project_name, p.foundation_name, p.project_status, p.goal_amount, p.current_donate,
           p.start_date, p.end_date
    FROM foundation_project p
    WHERE p.deleted_at IS NULL
    ORDER BY p.project_id DESC
";
$rows = $conn->query($sql);

function admin_project_status_label(string $st): string
{
    $t = strtolower(trim($st));
    $map = [
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        'completed' => 'สำเร็จ',
        'purchasing' => 'กำลังจัดซื้อ',
        'done' => 'เสร็จสิ้น',
    ];
    if (isset($map[$t])) {
        return $map[$t];
    }
    return $st !== '' ? $st : '-';
}

/** ป้ายสีตามสถานะอนุมัติ (เขียว = อนุมัติแล้ว) */
function admin_project_status_pill_class(string $st): string
{
    $raw = trim($st);
    $t = strtolower($raw);
    if ($t === 'approved' || $raw === 'อนุมัติ') {
        return 'admin-pill admin-pill--success';
    }
    if ($t === 'pending' || $raw === 'รอดำเนินการ' || $raw === 'รอดำนิการ') {
        return 'admin-pill admin-pill--warning';
    }
    if ($t === 'rejected' || $raw === 'ไม่อนุมัติ' || $raw === 'ปฏิเสธ') {
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
    <title>โครงการทั้งหมด | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">โครงการทั้งหมด</h1>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>ชื่อโครงการ</th>
                <th>มูลนิธิ</th>
                <th>สถานะ</th>
                <th class="admin-dir-num">เป้า (บาท)</th>
                <th class="admin-dir-num">ระดมแล้ว</th>
                <th>ปิดรับ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows && $rows->num_rows > 0): ?>
                <?php while ($r = $rows->fetch_assoc()):
                    $pid = (int)$r['project_id'];
                    $st = (string)($r['project_status'] ?? '');
                    $end = trim((string)($r['end_date'] ?? ''));
                    $endStr = $end !== '' ? date('d/m/Y', strtotime($end)) : '—';
                    $goal = (float)($r['goal_amount'] ?? 0);
                    $cur = (float)($r['current_donate'] ?? 0);
                    $detailUrl = 'admin_view_project.php?id=' . $pid;
                    $totalsUrl = 'admin_project_totals.php?project_id=' . $pid;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($r['project_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($r['foundation_name'] ?? '')) ?></td>
                        <td>
                            <span class="<?= htmlspecialchars(admin_project_status_pill_class($st)) ?>">
                                <?= htmlspecialchars(admin_project_status_label($st)) ?>
                            </span>
                        </td>
                        <td class="admin-dir-num"><?= number_format($goal, 0) ?></td>
                        <td class="admin-dir-num"><?= number_format($cur, 0) ?></td>
                        <td><?= htmlspecialchars($endStr) ?></td>
                        <td>
                            <div class="admin-dir-actions admin-dir-actions--pair">
                                <a class="admin-dir-btn admin-dir-btn--primary" href="<?= htmlspecialchars($detailUrl) ?>">โครงการ</a>
                                <a class="admin-dir-btn admin-dir-btn--ghost" href="<?= htmlspecialchars($totalsUrl) ?>">ยอดโครงการ</a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="b--muted">ยังไม่มีโครงการ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
