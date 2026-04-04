<?php
// admin_approve_foundation.php — แอดมินอนุมัติ/ปฏิเสธคำขอสมัครมูลนิธิ

if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/notification_audit.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: homepage.php");
    exit();
}

$success_msg = '';
if (isset($_GET['success'])) {
    $success_msg = $_GET['success'] === 'approved' ? '✅ อนุมัติมูลนิธิเรียบร้อยแล้ว' : '🗑️ ปฏิเสธและลบบัญชีเรียบร้อยแล้ว';
}

// ======== ประมวลผล POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foundation_id = intval($_POST['foundation_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($foundation_id && in_array($action, ['approve', 'reject'])) {
        $admin_id = $_SESSION['user_id']; // ใช้ user_id แทนเลย

        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE foundation_profile SET account_verified = 1, verified_at = NOW(), verified_by = ? WHERE foundation_id = ?");
            $stmt->bind_param("ii", $admin_id, $foundation_id);
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
            drawdream_log_admin_action($conn, (int)$admin_id, 'Approve_Foundation', $foundation_id, '', $fu > 0 ? $fu : null, 'foundation_approved');
            header("Location: admin_approve_foundation.php?success=approved");
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("SELECT user_id FROM foundation_profile WHERE foundation_id = ?");
            $stmt->bind_param("i", $foundation_id);
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
                drawdream_log_admin_action($conn, (int)$admin_id, 'Reject_Foundation', $foundation_id, 'บัญชีถูกลบ', $rejectUid > 0 ? $rejectUid : null, 'foundation_rejected');
                $stmt = $conn->prepare("DELETE FROM foundation_profile WHERE foundation_id = ?");
                $stmt->bind_param("i", $foundation_id);
                $stmt->execute();
                $stmt = $conn->prepare("DELETE FROM `user` WHERE user_id = ?");
                $stmt->bind_param("i", $fp['user_id']);
                $stmt->execute();
            }
            header("Location: admin_approve_foundation.php?success=rejected");
        }
        exit();
    }
}

// ======== ดึงข้อมูล ========
$view = isset($_GET['id']) ? 'detail' : 'list';

if ($view === 'detail') {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT f.*, u.email FROM foundation_profile f JOIN `user` u ON f.user_id = u.user_id WHERE f.foundation_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { header("Location: admin_approve_foundation.php"); exit(); }
} else {
    $result = mysqli_query($conn, "SELECT f.*, u.email FROM foundation_profile f JOIN `user` u ON f.user_id = u.user_id WHERE f.account_verified = 0 ORDER BY f.created_at DESC");
    $total = mysqli_num_rows($result);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อนุมัติมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/admin_foundation.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= $success_msg ?></div>
    <?php endif; ?>

    <?php if ($view === 'list'): ?>

        <div class="page-title">
            คำขออนุมัติมูลนิธิ
            <?php if ($total > 0): ?>
                <span class="badge-pending"><?= $total ?> รายการ</span>
            <?php endif; ?>
        </div>
        <div class="page-subtitle">ตรวจสอบและอนุมัติบัญชีมูลนิธิที่สมัครเข้ามา</div>

        <div class="card-list">
            <?php if ($total === 0): ?>
                <div class="empty">
                    <div class="empty-icon">✅</div>
                    ไม่มีคำขออนุมัติในขณะนี้
                </div>
            <?php else: ?>
                <?php while ($f = mysqli_fetch_assoc($result)): ?>
                    <div class="card-item">
                        <div class="item-left">
                            <div class="item-icon">🏛️</div>
                            <div>
                                <div class="item-name">
                                    มีคำขออนุมัติบัญชีจาก <?= htmlspecialchars($f['foundation_name']) ?>
                                </div>
                                <div class="item-meta">
                                    สมัครเมื่อ <?= date('d/m/Y เวลา H:i', strtotime($f['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <a href="?id=<?= $f['foundation_id'] ?>" class="btn-check">ตรวจสอบ</a>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <a href="admin_approve_foundation.php" class="back-link">← กลับรายการ</a>

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
                    <label>ชื่อมูลนิธิ</label>
                    <div class="field-value"><?= htmlspecialchars($row['foundation_name']) ?></div>
                </div>
                <div class="field">
                    <label>email</label>
                    <div class="field-value"><?= htmlspecialchars($row['email']) ?></div>
                </div>
                <div class="field">
                    <label>เลขทะเบียนมูลนิธิ</label>
                    <div class="field-value"><?= htmlspecialchars($row['registration_number'] ?: '-') ?></div>
                </div>
                <div class="field">
                    <label>เบอร์โทรศัพท์</label>
                    <div class="field-value"><?= htmlspecialchars($row['phone'] ?: '-') ?></div>
                </div>
                <div class="field field-full">
                    <label>ที่อยู่มูลนิธิ</label>
                    <div class="field-value tall"><?= htmlspecialchars($row['address'] ?: '-') ?></div>
                </div>
            </div>

            <div class="btn-row">
                <form method="POST" class="btn-row__form">
                    <input type="hidden" name="foundation_id" value="<?= $row['foundation_id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn-approve">อนุมัติ</button>
                </form>
                <form method="POST" class="btn-row__form">
                    <input type="hidden" name="foundation_id" value="<?= $row['foundation_id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn-reject"
                        onclick="return confirm('ยืนยันไม่อนุมัติ? บัญชีนี้จะถูกลบออกจากระบบ')">
                        ไม่อนุมัติ
                    </button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>
</body>
</html>