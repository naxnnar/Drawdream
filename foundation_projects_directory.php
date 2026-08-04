<?php
// foundation_projects_directory.php — โครงการของมูลนิธิ (มุมมองตารางแบบแอดมิน)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/drawdream_project_service_charge.php';
require_once __DIR__ . '/includes/foundation_donor_preview.php';

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
    "SELECT p.project_id, p.project_name, p.project_status, p.goal_amount, p.current_donate, p.end_date,
            p.service_charge_paid_at, p.update_text, p.update_images
     FROM foundation_project p
     WHERE (p.foundation_id = ? OR (p.foundation_id IS NULL AND p.foundation_name = ?))
     ORDER BY p.project_id DESC"
);
$rows = [];
if ($stRows) {
    $stRows->bind_param('is', $foundationId, $foundationName);
    $stRows->execute();
    $rows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);
}

function foundation_progress_pct(float $current, float $goal): int
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
    <title>โครงการทั้งหมด | <?= htmlspecialchars($foundationName) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
    <link rel="stylesheet" href="css/project.css?v=46">
    <link rel="stylesheet" href="css/foundation_manage.css?v=1">
</head>
<body class="foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <a href="foundation_dashboard.php" class="admin-directory-back" data-foundation-back>← กลับ</a>
        <h1 class="admin-directory-title">โครงการทั้งหมด</h1>
    </div>
    <?php if ($taskFocus === 'service_charge'): ?>
    <div style="margin:0 0 14px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e40af;font-size:.9rem;line-height:1.5;">
        ชำระค่าบริการระบบทำได้ทีละโครงการ — เปิดโครงการที่ครบเป้าแล้ว แล้วกดชำระค่าบริการ
    </div>
    <?php endif; ?>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>ชื่อโครงการ</th>
                <th>มูลนิธิ</th>
                <th>สถานะ</th>
                <th class="admin-dir-num">เป้า (บาท)</th>
                <th class="admin-dir-num">ระดมแล้ว</th>
                <th>ความสำเร็จ</th>
                <th>ปิดรับ</th>
                <th>การดำเนินการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows !== []): ?>
                <?php foreach ($rows as $r):
                    $pid = (int)($r['project_id'] ?? 0);
                    $pill = drawdream_foundation_project_workflow_pill($r);
                    $end = trim((string)($r['end_date'] ?? ''));
                    $endStr = $end !== '' ? date('d/m/Y', strtotime($end)) : '—';
                    $goal = (float)($r['goal_amount'] ?? 0);
                    $cur = (float)($r['current_donate'] ?? 0);
                    $pct = foundation_progress_pct($cur, $goal);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($r['project_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($foundationName) ?></td>
                        <td>
                            <span class="foundation-status-pill <?= htmlspecialchars($pill['class'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($pill['label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="admin-dir-num"><?= number_format($goal, 0) ?></td>
                        <td class="admin-dir-num"><?= number_format($cur, 0) ?></td>
                        <td>
                            <div style="min-width:140px;">
                                <div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
                                    <div style="height:8px;background:#4A5BA8;width:<?= $pct ?>%;"></div>
                                </div>
                                <div style="font-size:.85rem;color:#4b5563;margin-top:4px;"><?= $pct ?>%</div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($endStr) ?></td>
                        <td>
                            <a class="admin-dir-btn admin-dir-btn--primary" href="foundation_project_view.php?id=<?= $pid ?>">โครงการ</a>
                            <?php
                            $scDue = drawdream_project_service_charge_due_from_row($r);
                            if ($taskFocus === 'service_charge' && $scDue): ?>
                            <a class="admin-dir-btn admin-dir-btn--ghost" href="payment/project_service_charge.php?project_id=<?= $pid ?>">ชำระค่าบริการ</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีโครงการ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
