<?php
// admin_foundation_totals.php — แอดมิน: ยอดบริจาครวมของมูลนิธิ (เด็ก / โครงการ / สิ่งของ) + ประวัติรายการ
// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน foundation totals
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/donate_category_resolve.php';
require_once __DIR__ . '/includes/donate_type.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

$foundationId = (int)($_GET['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$stFp = $conn->prepare(
    'SELECT foundation_id, foundation_name, account_verified, created_at
     FROM foundation_profile WHERE foundation_id = ? LIMIT 1'
);
$stFp->bind_param('i', $foundationId);
$stFp->execute();
$fp = $stFp->get_result()->fetch_assoc();
if (!$fp) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$foundationName = trim((string)($fp['foundation_name'] ?? ''));
$childCat = drawdream_get_or_create_child_donate_category_id($conn);
$projCat = drawdream_get_or_create_project_donate_category_id($conn);
$needCat = drawdream_get_or_create_needitem_donate_category_id($conn);
if ($childCat <= 0 || $projCat <= 0 || $needCat <= 0) {
    die('ระบบหมวดบริจาคยังไม่พร้อม');
}

$childMap = [];
$stC = $conn->prepare(
    'SELECT child_id, child_name FROM foundation_children
     WHERE foundation_id = ? AND deleted_at IS NULL'
);
if ($stC) {
    $stC->bind_param('i', $foundationId);
    $stC->execute();
    $rc = $stC->get_result();
    while ($x = $rc->fetch_assoc()) {
        $childMap[(int)$x['child_id']] = (string)($x['child_name'] ?? '');
    }
}

$projectMap = [];
$stP = $conn->prepare(
    "SELECT project_id, project_name FROM foundation_project
     WHERE deleted_at IS NULL
       AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
);
if ($stP) {
    $stP->bind_param('is', $foundationId, $foundationName);
    $stP->execute();
    $rp = $stP->get_result();
    while ($x = $rp->fetch_assoc()) {
        $projectMap[(int)$x['project_id']] = (string)($x['project_name'] ?? '');
    }
}

$sql = "
SELECT d.donate_id, d.amount, d.transfer_datetime, d.payment_status,
       d.omise_charge_id, dn.tax_id, d.donor_id,
       d.donate_type, d.recurring_plan_code, d.recurring_status,
       d.category_id, d.target_id,
       dn.first_name, dn.last_name, u.email AS donor_email
FROM donation d
LEFT JOIN donor dn ON dn.user_id = d.donor_id
LEFT JOIN `user` u ON u.user_id = d.donor_id
WHERE LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
  AND (
    (d.category_id = ? AND d.target_id IN (
        SELECT child_id FROM foundation_children
        WHERE foundation_id = ? AND deleted_at IS NULL
    ))
    OR (d.category_id = ? AND d.target_id IN (
        SELECT project_id FROM foundation_project
        WHERE deleted_at IS NULL
          AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))
    ))
    OR (d.category_id = ? AND d.target_id = ?)
  )
ORDER BY d.transfer_datetime DESC, d.donate_id DESC
LIMIT 500
";
$stRows = $conn->prepare($sql);
if (!$stRows) {
    die('ไม่สามารถเตรียมคำสั่ง SQL');
}
$stRows->bind_param(
    'iiiiisi',
    $childCat,
    $foundationId,
    $projCat,
    $foundationId,
    $foundationName,
    $needCat,
    $foundationId
);
$stRows->execute();
$donRows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);

$sumChild = 0.0;
$sumProject = 0.0;
$sumNeed = 0.0;
foreach ($donRows as $r) {
    $cid = (int)($r['category_id'] ?? 0);
    $amt = (float)($r['amount'] ?? 0);
    if ($cid === $childCat) {
        $sumChild += $amt;
    } elseif ($cid === $projCat) {
        $sumProject += $amt;
    } elseif ($cid === $needCat) {
        $sumNeed += $amt;
    }
}
$sumTotal = $sumChild + $sumProject + $sumNeed;

$cntChildProfiles = count($childMap);
$cntProjects = count($projectMap);
$stNeedCnt = $conn->prepare('SELECT COUNT(*) AS c FROM foundation_needlist WHERE foundation_id = ?');
$needItemCnt = 0;
if ($stNeedCnt) {
    $stNeedCnt->bind_param('i', $foundationId);
    $stNeedCnt->execute();
    $needItemCnt = (int)($stNeedCnt->get_result()->fetch_assoc()['c'] ?? 0);
}

function admin_foundation_plan_label(string $code): string
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

$verifiedLabel = (int)($fp['account_verified'] ?? 0) === 1 ? 'ยืนยันบัญชีแล้ว' : 'รออนุมัติบัญชี';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยอดมูลนิธิ | Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div>
                <h1 class="admin-directory-title" style="margin-bottom:4px;">ยอดมูลนิธิ</h1>
                <p style="margin:0;font-size:.9rem;color:#4b5563;">
                    <?= htmlspecialchars($foundationName) ?>
                    · <?= htmlspecialchars($verifiedLabel) ?>
                </p>
            </div>
            <div class="admin-dir-actions" style="flex-shrink:0;">
                <a class="admin-dir-btn admin-dir-btn--ghost" href="admin_foundations_overview.php">← มูลนิธิทั้งหมด</a>
                <a class="admin-dir-btn admin-dir-btn--analytics" href="admin_foundation_analytics_view.php?foundation_id=<?= (int)$foundationId ?>">รายงานเชิงวิเคราะห์</a>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:14px 0 20px;">
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;">
            <strong>เด็กในระบบ</strong><br>
            <?= (int)$cntChildProfiles ?> คน · ยอดบริจาค <?= number_format($sumChild, 2) ?> บาท
        </div>
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;">
            <strong>โครงการ</strong><br>
            <?= (int)$cntProjects ?> โครงการ · ยอดบริจาค <?= number_format($sumProject, 2) ?> บาท
        </div>
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fafafa;">
            <strong>รายการสิ่งของ</strong><br>
            <?= (int)$needItemCnt ?> รายการ · ยอดบริจาค <?= number_format($sumNeed, 2) ?> บาท
        </div>
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f3f4ff;">
            <strong>รวมทั้งมูลนิธิ</strong><br>
            <?= number_format($sumTotal, 2) ?> บาท (<?= count($donRows) ?> รายการในตาราง)
        </div>
    </div>

    <div class="admin-dir-table-wrap">
        <table class="admin-dir-table">
            <thead>
            <tr>
                <th>เวลาโอน</th>
                <th>ผู้บริจาค</th>
                <th>ช่องทาง</th>
                <th>เป้าหมาย</th>
                <th>แผน</th>
                <th class="admin-dir-num">จำนวนเงิน (บาท)</th>
                <th>อ้างอิง</th>
                <th>เลขผู้เสียภาษี</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($donRows === []): ?>
                <tr><td colspan="8" class="b--muted">ยังไม่มีประวัติการบริจาคที่เข้ามูลนิธินี้</td></tr>
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
                    $catId = (int)($row['category_id'] ?? 0);
                    $tid = (int)($row['target_id'] ?? 0);
                    if ($catId === $childCat) {
                        $targetKind = 'เด็ก';
                        $targetDetail = $childMap[$tid] ?? ('#' . $tid);
                    } elseif ($catId === $projCat) {
                        $targetKind = 'โครงการ';
                        $targetDetail = $projectMap[$tid] ?? ('#' . $tid);
                    } else                    if ($catId === $needCat) {
                        $targetKind = 'สิ่งของ';
                        $targetDetail = 'ระดมมูลนิธิ (รวมรายการสิ่งของ)';
                    } else {
                        $targetKind = '-';
                        $targetDetail = '-';
                    }
                    $targetCell = $targetKind . ': ' . $targetDetail;
                    $isSub = in_array($dt, ['child_subscription', 'child_subscription_charge'], true);
                    $planCodeRaw = (string)($row['recurring_plan_code'] ?? '');
                    $planSpec = $isSub ? drawdream_child_subscription_plan($planCodeRaw) : null;
                    $planLabel = admin_foundation_plan_label($planCodeRaw);
                    if ($planLabel === '-' && $planCodeRaw === '' && in_array($dt, ['project', 'need_item'], true)) {
                        $planLabel = 'ครั้งเดียว';
                    }
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
                        <td><?= htmlspecialchars($targetCell) ?></td>
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

    <p style="margin:14px 0 0;font-size:.9rem;color:#374151;">
        <strong>สรุป:</strong>
        เด็ก <?= number_format($sumChild, 2) ?> บาท ·
        โครงการ <?= number_format($sumProject, 2) ?> บาท ·
        สิ่งของ <?= number_format($sumNeed, 2) ?> บาท ·
        <strong>รวม <?= number_format($sumTotal, 2) ?> บาท</strong>
    </p>
</div>
</body>
</html>
