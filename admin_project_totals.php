<?php
// admin_project_totals.php — แอดมิน: ยอดบริจาคและประวัติรายการต่อโครงการ
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน project totals
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: index.php');
    exit();
}

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: admin_projects_directory.php');
    exit();
}

$stProj = $conn->prepare(
    'SELECT project_id, project_name, foundation_name, goal_amount, current_donate, project_status
     FROM foundation_project
     WHERE project_id = ? AND deleted_at IS NULL
     LIMIT 1'
);
$stProj->bind_param('i', $projectId);
$stProj->execute();
$proj = $stProj->get_result()->fetch_assoc();
if (!$proj) {
    header('Location: admin_projects_directory.php?msg=' . urlencode('ไม่พบโครงการ'));
    exit();
}

$projCat = drawdream_get_or_create_project_donate_category_id($conn);
if ($projCat <= 0) {
    die('ระบบหมวดบริจาคยังไม่พร้อม');
}

$stRows = $conn->prepare(
    "SELECT d.donate_id, d.amount, d.transfer_datetime, d.payment_status,
            d.omise_charge_id, dn.tax_id, d.donor_id,
            d.donate_type, d.recurring_plan_code, d.recurring_status,
            dn.first_name, dn.last_name, u.email AS donor_email
     FROM donation d
     LEFT JOIN donor dn ON dn.user_id = d.donor_id
     LEFT JOIN `user` u ON u.user_id = d.donor_id
     WHERE d.target_id = ?
       AND LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
       AND d.category_id = ?
     ORDER BY d.transfer_datetime DESC, d.donate_id DESC
     LIMIT 500"
);
$stRows->bind_param('ii', $projectId, $projCat);
$stRows->execute();
$donRows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);

$totalAmount = 0.0;
foreach ($donRows as $row) {
    $totalAmount += (float)($row['amount'] ?? 0);
}

$goal = (float)($proj['goal_amount'] ?? 0);
$currentSys = (float)($proj['current_donate'] ?? 0);

function admin_project_totals_plan_label(string $code): string
{
    $m = [
        'monthly' => 'รายเดือน',
        'semiannual' => 'ราย 6 เดือน',
        'yearly' => 'รายปี',
        'daily' => 'รายวัน (QR)',
        'one_time' => 'ครั้งเดียว',
    ];
    $k = strtolower(trim($code));

    return $m[$k] ?? ($k !== '' ? $k : '-');
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยอดโครงการ | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">ยอดโครงการ</h1>
        <p style="margin:6px 0 0;font-size:.9rem;color:#4b5563;">
            <?= htmlspecialchars((string)($proj['project_name'] ?? '-')) ?>
            · <?= htmlspecialchars((string)($proj['foundation_name'] ?? '-')) ?>
            · <?= htmlspecialchars((string)($proj['project_status'] ?? '-')) ?>
        </p>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>เวลาโอน</th>
                <th>ผู้บริจาค</th>
                <th>ช่องทาง</th>
                <th>แผน</th>
                <th class="admin-dir-num">จำนวนเงิน (บาท)</th>
                <th>อ้างอิง</th>
                <th>เลขผู้เสียภาษี</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($donRows === []): ?>
                <tr><td colspan="7" class="b--muted">ยังไม่มีประวัติการบริจาคของโครงการนี้</td></tr>
            <?php else: ?>
                <?php foreach ($donRows as $row):
                    $dtRaw = trim((string)($row['transfer_datetime'] ?? ''));
                    $dtLabel = $dtRaw !== '' ? date('d/m/Y H:i:s', strtotime($dtRaw)) : '-';
                    $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = trim((string)($row['donor_email'] ?? ''));
                    }
                    if ($fullName === '') {
                        $fullName = 'ผู้บริจาคไม่ระบุตัวตน';
                    }
                    $dt = strtolower(trim((string)($row['donate_type'] ?? '')));
                    $channel = drawdream_donate_type_label_thai($dt);
                    $planCodeRaw = (string)($row['recurring_plan_code'] ?? '');
                    $planLabel = admin_project_totals_plan_label($planCodeRaw);
                    $chargeId = trim((string)($row['omise_charge_id'] ?? ''));
                    $taxId = trim((string)($row['tax_id'] ?? ''));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($dtLabel) ?></td>
                        <td><?= htmlspecialchars($fullName) ?></td>
                        <td><?= htmlspecialchars($channel) ?></td>
                        <td><?= htmlspecialchars($planLabel) ?></td>
                        <td class="admin-dir-num"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars($chargeId !== '' ? $chargeId : '-') ?></td>
                        <td><?= htmlspecialchars($taxId !== '' ? $taxId : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p style="margin:12px 0 0;font-size:.9rem;color:#374151;">
        <strong>ยอดรับแล้ว (สะสมจากประวัติ):</strong> <?= number_format($totalAmount, 0) ?> บาท
        · <strong>ยอดในระบบ:</strong> <?= number_format($currentSys, 0) ?> บาท
        · <strong>เป้าหมาย:</strong> <?= number_format($goal, 0) ?> บาท
    </p>
</div>
</body>
</html>
