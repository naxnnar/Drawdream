<?php
// admin_approve_foundation.php — แอดมิน: ตรวจสอบโปรไฟล์มูลนิธิ (อ่านอย่างเดียว — อนุมัติ/ไม่อนุมัติที่ศูนย์แจ้งเตือน)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/foundation_banks.php';
require_once __DIR__ . '/includes/foundation_review_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: homepage.php');
    exit();
}

$notifFoundationUrl = 'admin_notifications.php#admin-pending-foundations';
drawdream_foundation_review_ensure_schema($conn);

// ======== ประมวลผล POST (จากศูนย์แจ้งเตือน) ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foundation_id = (int)($_POST['foundation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reject_reason = trim((string)($_POST['reject_reason'] ?? ''));

    if ($foundation_id && in_array($action, ['approve', 'reject'], true)) {
        $admin_id = (int)$_SESSION['user_id'];

        if ($action === 'approve') {
            $stmt = $conn->prepare(
                'UPDATE foundation_profile
                 SET account_verified = 1, verified_at = NOW(), verified_by = ?, review_note = NULL, reviewed_at = NOW()
                 WHERE foundation_id = ? AND account_verified = 0'
            );
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
            if ($reject_reason === '') {
                header('Location: admin_notifications.php?err=foundation#admin-pending-foundations');
                exit();
            }
            $stmt = $conn->prepare('SELECT user_id FROM foundation_profile WHERE foundation_id = ? AND account_verified = 0');
            $stmt->bind_param('i', $foundation_id);
            $stmt->execute();
            $fp = $stmt->get_result()->fetch_assoc();
            if ($fp) {
                $rejectUid = (int)$fp['user_id'];
                $stUpd = $conn->prepare(
                    'UPDATE foundation_profile
                     SET account_verified = 2, review_note = ?, reviewed_at = NOW(), verified_by = ?
                     WHERE foundation_id = ? AND account_verified = 0'
                );
                $stUpd->bind_param('sii', $reject_reason, $admin_id, $foundation_id);
                $stUpd->execute();
                drawdream_send_notification(
                    $conn,
                    $rejectUid,
                    'foundation_rejected',
                    'คำขอสมัครมูลนิธิไม่ผ่านการอนุมัติ',
                    'เหตุผล: ' . $reject_reason . ' กรุณาแก้ไขข้อมูลในหน้าโปรไฟล์ แล้วส่งตรวจสอบใหม่',
                    'update_profile.php',
                    'fdn_registration:' . $foundation_id
                );
                drawdream_log_admin_action($conn, $admin_id, 'Reject_Foundation', $foundation_id, $reject_reason, $rejectUid > 0 ? $rejectUid : null, 'foundation_rejected');
            }
            header('Location: admin_notifications.php?done=foundation#admin-pending-foundations');
            exit();
        }
    }
    header('Location: admin_notifications.php?err=foundation#admin-pending-foundations');
    exit();
}

// ======== รายละเอียด (ต้องมี ?id=) ========
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
$imgUrl = $imgFile !== '' ? ('uploads/profiles/' . htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8')) : '';
$fname = htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string)($row['email'] ?? '—'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบโปรไฟล์มูลนิธิ | DrawDream Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/children.css?v=35">
</head>
<body class="admin-approve-projects-page">

<?php include 'navbar.php'; ?>

<main class="container-fluid my-4">
    <div class="admin-review-card admin-project-review">
        <div class="admin-review-header">
            <div class="admin-review-title">
                <h4 class="mb-1">ตรวจสอบโปรไฟล์มูลนิธิ</h4>
                <div><?= $fname ?> · <?= $email ?></div>
            </div>
        </div>

        <div class="admin-review-body">
            <div class="row g-4 admin-review-layout">
                <div class="col-lg-4 admin-image-col">
                    <?php if ($imgUrl !== ''): ?>
                        <img src="<?= $imgUrl ?>" alt="รูปโปรไฟล์มูลนิธิ" class="admin-project-cover">
                    <?php else: ?>
                        <div class="admin-project-cover admin-project-cover--empty" role="img" aria-label="ไม่มีรูปโปรไฟล์">ไม่มีรูปโปรไฟล์</div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-8 admin-details-col">
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="label">รหัสมูลนิธิ</span>
                            <span class="value"><?= (int)($row['foundation_id'] ?? 0) ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">สถานะบัญชี</span>
                            <span class="value">รออนุมัติ</span>
                        </div>
                        <div class="data-item">
                            <span class="label">วันที่สมัคร</span>
                            <span class="value"><?= htmlspecialchars($createdAtLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ชื่อมูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars((string)($row['foundation_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">อีเมล (เข้าสู่ระบบ)</span>
                            <span class="value"><?= htmlspecialchars((string)($row['email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เลขประจำตัวนิติบุคคล</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['registration_number'] ?? '')) !== '' ? (string)$row['registration_number'] : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เบอร์โทรศัพท์</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['phone'] ?? '')) !== '' ? (string)$row['phone'] : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">ที่อยู่มูลนิธิ</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['address'] ?? '')) !== '' ? (string)$row['address'] : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">ชื่อธนาคาร</span>
                            <span class="value"><?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item">
                            <span class="label">เลขบัญชีธนาคาร</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['bank_account_number'] ?? '')) !== '' ? (string)$row['bank_account_number'] : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="data-item full">
                            <span class="label">ชื่อบัญชีธนาคาร</span>
                            <span class="value"><?= htmlspecialchars(trim((string)($row['bank_account_name'] ?? '')) !== '' ? (string)$row['bank_account_name'] : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (trim((string)($row['foundation_desc'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">คำอธิบายมูลนิธิ</span>
                            <span class="value"><?= nl2br(htmlspecialchars((string)$row['foundation_desc'], ENT_QUOTES, 'UTF-8')) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (trim((string)($row['website'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">เว็บไซต์</span>
                            <span class="value"><?= htmlspecialchars((string)$row['website'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (trim((string)($row['facebook_url'] ?? '')) !== ''): ?>
                        <div class="data-item full">
                            <span class="label">Facebook / โซเชียล</span>
                            <span class="value"><?= htmlspecialchars((string)$row['facebook_url'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <p class="admin-review-actions-note">หากไม่อนุมัติ ระบบจะเก็บบัญชีผู้ใช้และข้อมูลเดิมไว้ เพื่อให้มูลนิธิแก้ไขข้อมูลแล้วส่งตรวจสอบใหม่ได้</p>
                    <form method="post" action="admin_approve_foundation.php" class="admin-review-actions-form">
                        <input type="hidden" name="foundation_id" value="<?= (int)($row['foundation_id'] ?? 0) ?>">
                        <div class="admin-review-actions-grid">
                            <textarea name="reject_reason" maxlength="1000" placeholder="กรอกเหตุผลเมื่อไม่อนุมัติ"></textarea>
                            <button type="submit" name="action" value="approve" class="btn btn-success admin-review-action-btn"
                                    onclick="return confirm('ยืนยันอนุมัติมูลนิธินี้?');">อนุมัติ</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger admin-review-action-btn"
                                    onclick="var t=this.form.querySelector('[name=reject_reason]');if(!t||!t.value.trim()){alert('กรุณากรอกเหตุผลที่ไม่อนุมัติ');if(t)t.focus();return false;}return confirm('ยืนยันไม่อนุมัติและส่งเหตุผลให้มูลนิธิ?');">ไม่อนุมัติ</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
