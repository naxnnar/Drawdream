<?php
// admin_approve_foundation.php — แอดมินอนุมัติ/ปฏิเสธคำขอสมัครมูลนิธิ (ไม่มีหน้ารายการ — เข้าจากศูนย์แจ้งเตือนเท่านั้น)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/foundation_banks.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: homepage.php');
    exit();
}

$notifFoundationUrl = 'admin_notifications.php#admin-pending-foundations';

// ======== ประมวลผล POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foundation_id = (int)($_POST['foundation_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($foundation_id && in_array($action, ['approve', 'reject'], true)) {
        $admin_id = (int)$_SESSION['user_id'];

        if ($action === 'approve') {
            $stmt = $conn->prepare('UPDATE foundation_profile SET account_verified = 1, verified_at = NOW(), verified_by = ? WHERE foundation_id = ? AND account_verified = 0');
            $stmt->bind_param('ii', $admin_id, $foundation_id);
            $stmt->execute();
            $fu = drawdream_foundation_user_id_by_foundation_id($conn, $foundation_id);
            drawdream_send_notification(
                $conn,
                $fu,
                'foundation_approved',
                'บัญชีมูลนิธิได้รับการอนุมัติ',
                'คุณสามารถเข้าใช้งานฟีเจอร์เต็มรูปแบบได้แล้ว',
                'project.php?view=foundation',
                'fdn_registration:' . $foundation_id
            );
            drawdream_log_admin_action($conn, $admin_id, 'Approve_Foundation', $foundation_id, '', $fu > 0 ? $fu : null, 'foundation_approved');
            header('Location: admin_notifications.php?done=foundation#admin-pending-foundations');
            exit();
        }
        if ($action === 'reject') {
            $stmt = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_id = ? AND account_verified = 0');
            $stmt->bind_param('i', $foundation_id);
            $stmt->execute();
            $fp = $stmt->get_result()->fetch_assoc();
            if ($fp) {
                $rejectUid = (int)$fp['user_id'];
                drawdream_send_notification(
                    $conn,
                    $rejectUid,
                    'foundation_rejected',
                    'คำขอสมัครมูลนิธิไม่ผ่านการอนุมัติ',
                    'บัญชีของคุณถูกปิดจากระบบตามผลพิจารณาของผู้ดูแล',
                    '',
                    'fdn_registration:' . $foundation_id
                );
                drawdream_log_admin_action($conn, $admin_id, 'Reject_Foundation', $foundation_id, 'บัญชีถูกลบ', $rejectUid > 0 ? $rejectUid : null, 'foundation_rejected');
                $stmt = $conn->prepare('DELETE FROM foundation_profile WHERE foundation_id = ?');
                $stmt->bind_param('i', $foundation_id);
                $stmt->execute();
                $stmt = $conn->prepare('DELETE FROM `user` WHERE user_id = ?');
                $stmt->bind_param('i', $fp['user_id']);
                $stmt->execute();
            }
            header('Location: admin_notifications.php?done=foundation#admin-pending-foundations');
            exit();
        }
    }
    header('Location: admin_notifications.php?err=foundation#admin-pending-foundations');
    exit();
}

// ======== รายละเอียดเท่านั้น (ต้องมี ?id=) ========
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . $notifFoundationUrl);
    exit();
}

$stmt = $conn->prepare(
    'SELECT f.*, u.email FROM foundation_profile f JOIN `user` u ON f.user_id = u.user_id
     WHERE f.foundation_id = ? AND f.account_verified = 0 LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header('Location: ' . $notifFoundationUrl);
    exit();
}

$bankKey = trim((string)($row['bank_name'] ?? ''));
$bankList = drawdream_foundation_bank_list();
$bankLabel = $bankKey !== '' ? ($bankList[$bankKey] ?? $bankKey) : '-';
$createdAtRaw = trim((string)($row['created_at'] ?? ''));
$createdAtLabel = $createdAtRaw !== '' ? date('d/m/Y H:i', strtotime($createdAtRaw)) : '-';
$foundationImg = trim((string)($row['foundation_image'] ?? ''));
$legacyProfileImg = trim((string)($row['profile_image'] ?? ''));
$imgFile = $foundationImg !== '' ? $foundationImg : $legacyProfileImg;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อนุมัติมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_foundation.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">

        <div class="detail-card">
            <div class="detail-header">
                <div class="detail-icon">🏛️</div>
                <div>
                    <div class="detail-title"><?= htmlspecialchars($row['foundation_name']) ?></div>
                    <div class="detail-subtitle"><?= htmlspecialchars($row['email']) ?></div>
                </div>
            </div>

            <div class="field-grid">
                <div class="field">
                    <label>วันที่สมัคร</label>
                    <div class="field-value"><?= htmlspecialchars($createdAtLabel) ?></div>
                </div>
                <div class="field">
                    <label>รหัส foundation_id</label>
                    <div class="field-value"><?= (int)($row['foundation_id'] ?? 0) ?></div>
                </div>
                <div class="field">
                    <label>ชื่อมูลนิธิ</label>
                    <div class="field-value"><?= htmlspecialchars((string)($row['foundation_name'] ?? '')) ?></div>
                </div>
                <div class="field">
                    <label>อีเมล (เข้าสู่ระบบ)</label>
                    <div class="field-value"><?= htmlspecialchars((string)($row['email'] ?? '')) ?></div>
                </div>
                <div class="field">
                    <label>เลขประจำตัวนิติบุคคล</label>
                    <div class="field-value"><?= htmlspecialchars(trim((string)($row['registration_number'] ?? '')) !== '' ? (string)$row['registration_number'] : '-') ?></div>
                </div>
                <div class="field">
                    <label>เบอร์โทรศัพท์</label>
                    <div class="field-value"><?= htmlspecialchars(trim((string)($row['phone'] ?? '')) !== '' ? (string)$row['phone'] : '-') ?></div>
                </div>
                <div class="field field-full">
                    <label>ที่อยู่มูลนิธิ</label>
                    <div class="field-value tall"><?= htmlspecialchars(trim((string)($row['address'] ?? '')) !== '' ? (string)$row['address'] : '-') ?></div>
                </div>
                <div class="field">
                    <label>ชื่อธนาคาร</label>
                    <div class="field-value"><?= htmlspecialchars($bankLabel) ?></div>
                </div>
                <div class="field">
                    <label>เลขบัญชีธนาคาร</label>
                    <div class="field-value"><?= htmlspecialchars(trim((string)($row['bank_account_number'] ?? '')) !== '' ? (string)$row['bank_account_number'] : '-') ?></div>
                </div>
                <div class="field field-full">
                    <label>ชื่อบัญชีธนาคาร</label>
                    <div class="field-value"><?= htmlspecialchars(trim((string)($row['bank_account_name'] ?? '')) !== '' ? (string)$row['bank_account_name'] : '-') ?></div>
                </div>
                <?php if (trim((string)($row['foundation_desc'] ?? '')) !== ''): ?>
                <div class="field field-full">
                    <label>คำอธิบายมูลนิธิ</label>
                    <div class="field-value tall"><?= nl2br(htmlspecialchars((string)$row['foundation_desc'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if (trim((string)($row['website'] ?? '')) !== ''): ?>
                <div class="field field-full">
                    <label>เว็บไซต์</label>
                    <div class="field-value"><?= htmlspecialchars((string)$row['website']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (trim((string)($row['facebook_url'] ?? '')) !== ''): ?>
                <div class="field field-full">
                    <label>Facebook / โซเชียล</label>
                    <div class="field-value"><?= htmlspecialchars((string)$row['facebook_url']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($imgFile !== ''): ?>
                <div class="field field-full">
                    <label>รูปโปรไฟล์มูลนิธิ</label>
                    <div class="field-value">
                        <img src="uploads/profiles/<?= htmlspecialchars($imgFile) ?>" alt="" class="approval-foundation-profile-img" width="200" height="200" loading="lazy" decoding="async" style="object-fit:cover;border-radius:12px;max-width:min(280px,100%);height:auto;aspect-ratio:1;border:1px solid #e5e7eb;">
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="btn-row">
                <form method="POST" class="btn-row__form">
                    <input type="hidden" name="foundation_id" value="<?= (int)$row['foundation_id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn-approve">อนุมัติ</button>
                </form>
                <form method="POST" class="btn-row__form">
                    <input type="hidden" name="foundation_id" value="<?= (int)$row['foundation_id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn-reject"
                        onclick="return confirm('ยืนยันไม่อนุมัติ? บัญชีนี้จะถูกลบออกจากระบบ')">
                        ไม่อนุมัติ
                    </button>
                </form>
            </div>
        </div>
</div>
</body>
</html>
