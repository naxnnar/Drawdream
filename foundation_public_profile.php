<?php
// foundation_public_profile.php — โปรไฟล์มูลนิธิแบบสาธารณะ
// หน้าโปรไฟล์มูลนิธิสาธารณะ (แสดงเฉพาะข้อมูลที่เหมาะให้ผู้บริจาคดู)
// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน public profile
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/address_helpers.php';
require_once __DIR__ . '/includes/utf8_helpers.php';

$fid = (int)($_GET['id'] ?? $_GET['fid'] ?? 0);
if ($fid <= 0) {
    header('Location: foundation.php');
    exit;
}

$stmt = $conn->prepare('SELECT foundation_id, foundation_name, foundation_desc, foundation_image, address, created_at, account_verified FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
if (!$stmt) {
    header('Location: foundation.php');
    exit;
}
$stmt->bind_param('i', $fid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fp) {
    header('Location: foundation.php');
    exit;
}

if ((int)($fp['account_verified'] ?? 0) !== 1) {
    header('Location: foundation.php');
    exit;
}

$foundationName = (string)($fp['foundation_name'] ?? '');

$projectCount = 0;
$stc = $conn->prepare(
    "SELECT COUNT(*) AS c FROM foundation_project
     WHERE deleted_at IS NULL
       AND project_status IN ('approved','completed','done')
       AND (foundation_id = ? OR (foundation_id IS NULL AND foundation_name = ?))"
);
if ($stc) {
    $stc->bind_param('is', $fid, $foundationName);
    $stc->execute();
    $crow = $stc->get_result()->fetch_assoc();
    $projectCount = (int)($crow['c'] ?? 0);
    $stc->close();
}

$joinYearBE = null;
$created = $fp['created_at'] ?? '';
if ($created !== '') {
    $ts = strtotime($created);
    if ($ts !== false) {
        $joinYearBE = (int)date('Y', $ts) + 543;
    }
}

$province = '';
$parsed = drawdream_parse_saved_thai_address($fp['address'] ?? null);
if ($parsed && ($parsed['province'] ?? '') !== '') {
    $province = $parsed['province'];
} else {
    $addr = trim((string)($fp['address'] ?? ''));
    if ($addr !== '') {
        $province = drawdream_utf8_strlen($addr) > 48
            ? drawdream_utf8_substr($addr, 0, 48) . '…'
            : $addr;
    }
}
if ($province === '') {
    $province = '—';
}

$desc = trim((string)($fp['foundation_desc'] ?? ''));
$img = trim((string)($fp['foundation_image'] ?? ''));
$pageTitle = htmlspecialchars($foundationName !== '' ? $foundationName : 'มูลนิธิ', ENT_QUOTES, 'UTF-8') . ' | มูลนิธิ DrawDream';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation_public_profile.css?v=1">
</head>
<body class="foundation-public-profile-page">

<?php include __DIR__ . '/navbar.php'; ?>

<main class="fpub-wrap">
    <a href="javascript:history.back()" class="fpub-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> ย้อนกลับ</a>

    <div class="fpub-card">
        <header class="fpub-header">
            <div class="fpub-header-inner">
                <div class="fpub-avatar<?= $img === '' ? ' fpub-avatar--empty' : '' ?>" aria-hidden="<?= $img === '' ? 'true' : 'false' ?>">
                    <?php if ($img !== ''): ?>
                        <img src="uploads/profiles/<?= htmlspecialchars($img) ?>" alt="">
                    <?php else: ?>
                        ?
                    <?php endif; ?>
                </div>
                <div class="fpub-head-text">
                    <h1><?= htmlspecialchars($foundationName !== '' ? $foundationName : 'มูลนิธิ') ?></h1>
                    <p class="fpub-loc">
                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                        <?= htmlspecialchars($province) ?>
                    </p>
                    <div class="fpub-chips">
                        <span class="fpub-chip">
                            <i class="bi bi-calendar3" aria-hidden="true"></i>
                            <?php if ($joinYearBE !== null): ?>
                                เข้าร่วมกับ DrawDream พ.ศ. <?= (int)$joinYearBE ?>
                            <?php else: ?>
                                เข้าร่วมกับ DrawDream
                            <?php endif; ?>
                        </span>
                        <span class="fpub-chip">
                            โครงการทั้งหมด <?= (int)$projectCount ?>
                        </span>
                    </div>
                </div>
            </div>
        </header>
        <section class="fpub-body">
            <h2>เกี่ยวกับองค์กร</h2>
            <div class="fpub-desc">
                <?php if ($desc !== ''): ?>
                    <?= nl2br(htmlspecialchars($desc)) ?>
                <?php else: ?>
                    <span style="color:#94a3b8;">มูลนิธินี้ยังไม่ได้เพิ่มคำอธิบายองค์กร</span>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

</body>
</html>
