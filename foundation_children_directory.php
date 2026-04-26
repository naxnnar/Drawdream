<?php
// foundation_children_directory.php — รายการเด็กของมูลนิธิ (มุมมองตารางแบบแอดมิน)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: index.php');
    exit();
}

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

$stRows = $conn->prepare(
    'SELECT child_id, child_name, photo_child, approve_profile, status
     FROM foundation_children
     WHERE foundation_id = ? AND deleted_at IS NULL
     ORDER BY child_id DESC'
);
$rows = [];
if ($stRows) {
    $stRows->bind_param('i', $foundationId);
    $stRows->execute();
    $rows = $stRows->get_result()->fetch_all(MYSQLI_ASSOC);
}

function foundation_child_profile_status_th(string $ap): string
{
    $t = strtolower(trim($ap));
    return match ($t) {
        'approved', 'อนุมัติ', 'อนุมัติแล้ว' => 'อนุมัติแล้ว',
        'rejected', 'ไม่อนุมัติ' => 'ไม่อนุมัติ',
        default => 'รอดำเนินการ',
    };
}

function foundation_child_profile_status_class(string $ap): string
{
    $t = strtolower(trim($ap));
    if ($t === 'approved' || $ap === 'อนุมัติ' || $ap === 'อนุมัติแล้ว') {
        return 'admin-pill admin-pill--success';
    }
    if ($t === 'rejected' || $ap === 'ไม่อนุมัติ') {
        return 'admin-pill admin-pill--danger';
    }
    return 'admin-pill admin-pill--warning';
}

function foundation_child_sponsor_status_class(string $st): string
{
    $txt = trim($st);
    if ($txt === 'มีผู้อุปการะ') {
        return 'admin-pill admin-pill--success';
    }
    return 'admin-pill admin-pill--danger';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เด็กทั้งหมด | <?= htmlspecialchars($foundationName) ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_directory.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="admin-directory-page">
    <div class="admin-directory-head">
        <h1 class="admin-directory-title">เด็กทั้งหมด</h1>
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
            <?php if ($rows !== []): ?>
                <?php foreach ($rows as $r):
                    $cid = (int)($r['child_id'] ?? 0);
                    $img = trim((string)($r['photo_child'] ?? ''));
                    $approve = (string)($r['approve_profile'] ?? '');
                    $sponsor = trim((string)($r['status'] ?? ''));
                    if ($sponsor === '') {
                        $sponsor = 'รออุปการะ';
                    }
                    ?>
                    <tr>
                        <td>
                            <?php if ($img !== ''): ?>
                                <img class="admin-dir-thumb" src="uploads/childern/<?= htmlspecialchars($img) ?>" alt="">
                            <?php else: ?>
                                <span class="b--muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($r['child_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($foundationName) ?></td>
                        <td><span class="<?= htmlspecialchars(foundation_child_profile_status_class($approve)) ?>"><?= htmlspecialchars(foundation_child_profile_status_th($approve)) ?></span></td>
                        <td><span class="<?= htmlspecialchars(foundation_child_sponsor_status_class($sponsor)) ?>"><?= htmlspecialchars($sponsor) ?></span></td>
                        <td>
                            <a class="admin-dir-btn admin-dir-btn--primary" href="children_donate.php?id=<?= $cid ?>">โปรไฟล์เด็ก</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="b--muted">ยังไม่มีเด็กในระบบ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

