<?php
// admin_child_donations.php — ตรวจสอบยอดและประวัติการรับบริจาคของเด็ก (ฝั่งแอดมิน)
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน child donations
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: index.php');
    exit();
}

$childId = (int)($_GET['child_id'] ?? 0);
if ($childId <= 0) {
    header('Location: children_.php');
    exit();
}

$stChild = $conn->prepare(
    "SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
     FROM foundation_children c
     LEFT JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
     WHERE c.child_id = ? LIMIT 1"
);
$stChild->bind_param('i', $childId);
$stChild->execute();
$child = $stChild->get_result()->fetch_assoc();
if (!$child) {
    header('Location: children_.php?msg=' . urlencode('ไม่พบข้อมูลเด็ก'));
    exit();
}

$childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);

// รายชื่อผู้บริจาค + สถานะ (เดียวกับหน้าโปรไฟล์เด็กฝั่งมูลนิธิ)
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
       AND (d.category_id = ? OR d.donate_type IN ('child_subscription', 'child_subscription_charge'))
     ORDER BY d.transfer_datetime DESC, d.donate_id DESC
     LIMIT 500"
);
$stRows->bind_param('ii', $childId, $childCategoryId);
$stRows->execute();
$donRows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);

$totalAmount = 0.0;
$todayAmount = 0.0;
$todayYmd = date('Y-m-d');
$donorMap = [];
foreach ($donRows as $row) {
    $amt = (float)($row['amount'] ?? 0);
    $totalAmount += $amt;
    $ts = trim((string)($row['transfer_datetime'] ?? ''));
    if ($ts !== '' && substr($ts, 0, 10) === $todayYmd) {
        $todayAmount += $amt;
    }
    $duid = (int)($row['donor_id'] ?? 0);
    if ($duid > 0) {
        $donorMap[$duid] = true;
    }
}

$cycleAmount = drawdream_child_cycle_total($conn, $childId, $child);
$educationFundTotal = drawdream_child_education_fund_total_thb($conn, $childId);
$donorCount = count($donorMap);

function admin_child_plan_label(string $code): string
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
    <title>ยอดบริจาคเด็ก | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page children-admin-directory">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">ยอดบริจาคเด็ก</h1>
        <p style="margin:6px 0 0;font-size:.9rem;color:#4b5563;">
            <?= htmlspecialchars((string)($child['child_name'] ?? '-')) ?>
            · <?= htmlspecialchars((string)($child['display_foundation_name'] ?? '-')) ?>
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
                <tr><td colspan="7" class="b--muted">ยังไม่มีประวัติการบริจาคของเด็กคนนี้</td></tr>
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
                    $isSub = in_array($dt, ['child_subscription', 'child_subscription_charge'], true);
                    $channel = drawdream_donate_type_label_thai($dt);
                    $planCodeRaw = (string)($row['recurring_plan_code'] ?? '');
                    $planSpec = $isSub ? drawdream_child_subscription_plan($planCodeRaw) : null;
                    $planLabel = $isSub
                        ? admin_child_plan_label($planCodeRaw)
                        : ($dt === 'child_one_time' ? admin_child_plan_label($planCodeRaw) : '-');
                    if ($isSub && is_array($planSpec) && ($planSpec['amount_thb'] ?? 0) > 0) {
                        $planLabel .= ' · ' . number_format((float)$planSpec['amount_thb'], 0) . ' บ.';
                    }
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
        <strong>ทุนการศึกษา (สะสมส่วนเกิน 700 บ./ครั้ง):</strong>
        <?= number_format($educationFundTotal, 0) ?> บาท
    </p>
</div>
</body>
</html>
