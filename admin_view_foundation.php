<?php
// admin_view_foundation.php — ดูข้อมูลมูลนิธิ (จากไดเรกทอรี — ไม่มีอนุมัติ/ไม่อนุมัติ)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/foundation_banks.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$stmt = $conn->prepare(
    'SELECT f.*, u.email FROM foundation_profile f JOIN `user` u ON f.user_id = u.user_id
     WHERE f.foundation_id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header('Location: admin_foundations_overview.php');
    exit();
}

$verified = (int)($row['account_verified'] ?? 0) === 1;
$bankKey = trim((string)($row['bank_name'] ?? ''));
$bankList = drawdream_foundation_bank_list();
$bankLabel = $bankKey !== '' ? ($bankList[$bankKey] ?? $bankKey) : '—';
$createdAtRaw = trim((string)($row['created_at'] ?? ''));
$createdAtLabel = $createdAtRaw !== '' ? date('d/m/Y H:i', strtotime($createdAtRaw)) : '—';
$foundationImg = trim((string)($row['foundation_image'] ?? ''));
$legacyProfileImg = trim((string)($row['profile_image'] ?? ''));
$imgFile = $foundationImg !== '' ? $foundationImg : $legacyProfileImg;
$imgUrl = $imgFile !== '' ? ('uploads/profiles/' . htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8')) : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลมูลนิธิ | DrawDream Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_record_view.css">
</head>
<body class="admin-record-view-page">
<?php include 'navbar.php'; ?>

<div class="admin-record-shell">
    <div class="admin-record-back">
        <a href="admin_foundations_overview.php">← กลับไปมูลนิธิทั้งหมด</a>
    </div>
    <article class="admin-record-sheet">
        <header class="admin-record-sheet__head">
            <h1>ข้อมูลมูลนิธิ</h1>
            <p class="admin-record-sheet__sub"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <div class="admin-record-body">
            <div class="admin-record-media">
                <?php if ($imgUrl !== ''): ?>
                    <img src="<?= $imgUrl ?>" alt="โลโก้/รูปมูลนิธิ">
                <?php else: ?>
                    <div class="admin-record-ph">ไม่มีรูปโปรไฟล์</div>
                <?php endif; ?>
            </div>
            <div class="admin-record-grid">
                <div class="admin-record-field">
                    <div class="admin-record-k">สถานะบัญชี</div>
                    <div class="admin-record-v"><?= $verified ? 'ยืนยันแล้ว' : 'รออนุมัติ' ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">รหัสมูลนิธิ</div>
                    <div class="admin-record-v"><?= (int)($row['foundation_id'] ?? 0) ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">วันที่สมัคร</div>
                    <div class="admin-record-v"><?= htmlspecialchars($createdAtLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">อีเมล (เข้าสู่ระบบ)</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ชื่อมูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เลขประจำตัวนิติบุคคล</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['registration_number'] ?? '')) !== '' ? (string)$row['registration_number'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เบอร์โทรศัพท์</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['phone'] ?? '')) !== '' ? (string)$row['phone'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ที่อยู่มูลนิธิ</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['address'] ?? '')) !== '' ? (string)$row['address'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">ชื่อธนาคาร</div>
                    <div class="admin-record-v"><?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field">
                    <div class="admin-record-k">เลขบัญชีธนาคาร</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['bank_account_number'] ?? '')) !== '' ? (string)$row['bank_account_number'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">ชื่อบัญชีธนาคาร</div>
                    <div class="admin-record-v"><?= htmlspecialchars(trim((string)($row['bank_account_name'] ?? '')) !== '' ? (string)$row['bank_account_name'] : '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if (trim((string)($row['foundation_desc'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">คำอธิบายมูลนิธิ</div>
                    <div class="admin-record-v"><?= nl2br(htmlspecialchars((string)$row['foundation_desc'], ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <?php endif; ?>
                <?php if (trim((string)($row['website'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">เว็บไซต์</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['website'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if (trim((string)($row['facebook_url'] ?? '')) !== ''): ?>
                <div class="admin-record-field admin-record-field--full">
                    <div class="admin-record-k">Facebook / โซเชียล</div>
                    <div class="admin-record-v"><?= htmlspecialchars((string)$row['facebook_url'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </article>
</div>
</body>
</html>
