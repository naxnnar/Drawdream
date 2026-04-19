<?php
// admin_needlist_totals.php — แอดมิน: ยอดบริจาคสิ่งของ (รายการเดียว — แสดงรายการชำระระดับมูลนิธิที่แบ่งยอดเข้ารายการนี้)
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

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    header('Location: admin_needlist_directory.php');
    exit();
}

$stItem = $conn->prepare(
    'SELECT nl.item_id, nl.item_name, nl.foundation_id, nl.total_price, nl.current_donate, nl.approve_item,
            fp.foundation_name
     FROM foundation_needlist nl
     JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
     WHERE nl.item_id = ?
     LIMIT 1'
);
$stItem->bind_param('i', $itemId);
$stItem->execute();
$item = $stItem->get_result()->fetch_assoc();
if (!$item) {
    header('Location: admin_needlist_directory.php?msg=' . urlencode('ไม่พบรายการสิ่งของ'));
    exit();
}

$foundationId = (int)($item['foundation_id'] ?? 0);
$needCat = drawdream_get_or_create_needitem_donate_category_id($conn);
if ($needCat <= 0) {
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
$stRows->bind_param('ii', $foundationId, $needCat);
$stRows->execute();
$donRows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);

$sumTable = 0.0;
foreach ($donRows as $row) {
    $sumTable += (float)($row['amount'] ?? 0);
}

$goalItem = (float)($item['total_price'] ?? 0);
$raisedItem = (float)($item['current_donate'] ?? 0);

function admin_needlist_totals_plan_label(string $code): string
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
    <title>ยอดสิ่งของ | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">ยอดสิ่งของ</h1>
        <p style="margin:6px 0 0;font-size:.9rem;color:#4b5563;">
            <?= htmlspecialchars((string)($item['item_name'] ?? '-')) ?>
            · <?= htmlspecialchars((string)($item['foundation_name'] ?? '-')) ?>
            · <?= htmlspecialchars((string)($item['approve_item'] ?? '-')) ?>
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
                <tr><td colspan="7" class="b--muted">ยังไม่มีประวัติการบริจาคสิ่งของของมูลนิธินี้</td></tr>
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
                    $planLabel = admin_needlist_totals_plan_label($planCodeRaw);
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
        <strong>ยอดรับแล้ว (รายการนี้):</strong> <?= number_format($raisedItem, 0) ?> บาท
        · <strong>เป้าหมาย:</strong> <?= number_format($goalItem, 0) ?> บาท
        · <strong>ยอดรวมในตาราง (มูลนิธิ):</strong> <?= number_format($sumTable, 0) ?> บาท
    </p>
</div>
</body>
</html>
