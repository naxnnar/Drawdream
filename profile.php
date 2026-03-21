<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// ======== ฟังก์ชันเช็คโครงการสำเร็จ ========
function checkCompletedProjects($conn) {
    // เช็คโครงการที่ครบเป้าหมาย
    $conn->query("
        UPDATE project 
        SET project_status = 'completed'
        WHERE project_status = 'approved'
        AND current_donate >= goal_amount
        AND goal_amount > 0
    ");

    // เช็คโครงการที่หมดเวลา
    $conn->query("
        UPDATE project 
        SET project_status = 'completed'
        WHERE project_status = 'approved'
        AND end_date < CURDATE()
    ");
}

// เรียกเช็คทุกครั้งที่โหลดหน้า
checkCompletedProjects($conn);

if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp 
                           JOIN users u ON fp.user_id = u.user_id 
                           WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

    // ดึงโครงการทั้งหมดของมูลนิธินี้
    $fid = (int)($profile['foundation_id'] ?? 0);
    $stmt_proj = $conn->prepare("
         SELECT project_id, project_name, goal_amount, current_donate,
            project_status, end_date, project_image, category
        FROM project 
        WHERE foundation_id = ?
         ORDER BY project_status DESC, project_id DESC
    ");
    $stmt_proj->bind_param("i", $fid);
    $stmt_proj->execute();
    $foundation_projects = $stmt_proj->get_result()->fetch_all(MYSQLI_ASSOC);

    // ดึงโครงการที่ completed แต่ยังไม่มี project_updates (ต้องอัปเดต)
    $stmt_need_update = $conn->prepare("
           SELECT p.project_id, p.project_name, p.end_date AS completed_at,
                DATEDIFF(DATE_ADD(COALESCE(p.end_date, NOW()), INTERVAL 30 DAY), NOW()) AS days_left,
               (SELECT COUNT(*) FROM project_updates pu WHERE pu.project_id = p.project_id) AS update_count
        FROM project p
        WHERE p.foundation_id = ? 
          AND p.project_status = 'completed'
           ORDER BY p.end_date ASC
    ");
    $stmt_need_update->bind_param("i", $fid);
    $stmt_need_update->execute();
    $need_updates = $stmt_need_update->get_result()->fetch_all(MYSQLI_ASSOC);

} elseif ($role === 'donor') {
    $stmt = $conn->prepare("SELECT d.*, u.email FROM donor d 
                           JOIN users u ON d.user_id = u.user_id 
                           WHERE d.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

    $stmt_don = $conn->prepare("
        SELECT 
            d.donate_id,
            d.amount,
            d.payment_status,
            d.transfer_datetime,
            dc.project_donate,
            dc.needitem_donate,
            pt.omise_charge_id
        FROM donation d
        JOIN donate_category dc ON d.category_id = dc.category_id
        LEFT JOIN payment_transaction pt ON pt.donate_id = d.donate_id
        WHERE d.payment_status = 'completed'
        ORDER BY d.transfer_datetime DESC
        LIMIT 20
    ");
    $stmt_don->execute();
    $donation_history = $stmt_don->get_result()->fetch_all(MYSQLI_ASSOC);

} elseif ($role === 'admin') {
    $stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $profile = [
        'email'      => $user_data['email'] ?? '',
        'first_name' => 'Admin',
        'last_name'  => 'System'
    ];
    $stmt3 = $conn->prepare("
        SELECT a.*, 
               nl.item_name, nl.item_desc, nl.qty_needed, nl.price_estimate, nl.item_image, nl.foundation_id,
               p.project_name, p.project_desc,
               fp.foundation_name
        FROM admin a
        LEFT JOIN foundation_needlist nl ON a.target_id = nl.item_id AND a.action_type IN ('Approve_Need', 'Reject_Need')
        LEFT JOIN project p ON a.target_id = p.project_id AND a.action_type IN ('Approve_Project', 'Reject_Project')
        LEFT JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
        WHERE a.admin_id = ?
        ORDER BY a.action_at DESC LIMIT 50
    ");
    $stmt3->bind_param("i", $user_id);
    $stmt3->execute();
    $logs = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    die("Role ไม่รองรับ");
}

if (!$profile) die("ไม่พบข้อมูลโปรไฟล์");

function translateAction($action) {
    $map = [
        'Login'          => 'เข้าสู่ระบบ',
        'Approve_Child'  => 'อนุมัติเด็ก',
        'Reject_Project' => 'ปฏิเสธโครงการ',
        'Approve_Need'   => 'อนุมัติรายการสิ่งของ',
        'Reject_Need'    => 'ปฏิเสธรายการสิ่งของ',
        'Approve_Project'=> 'อนุมัติโครงการ',
        'Other'          => 'อื่นๆ'
    ];
    return $map[$action] ?? $action;
}

function statusLabel($status) {
    $map = [
        'pending'   => ['label' => 'รออนุมัติ',    'color' => '#FFC107'],
        'approved'  => ['label' => 'กำลังระดมทุน', 'color' => '#4CAF50'],
        'completed' => ['label' => 'สำเร็จแล้ว',   'color' => '#4A5BA8'],
        'rejected'  => ['label' => 'ไม่ผ่าน',      'color' => '#E57373'],
    ];
    return $map[$status] ?? ['label' => $status, 'color' => '#999'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/profile.css">
    
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-container">
    <div class="profile-header">

        <?php if ($role === 'foundation'): ?>
            <div class="profile-image-placeholder">
                <?php if (!empty($profile['foundation_image'])): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($profile['foundation_image']) ?>" alt="รูปโปรไฟล์">
                <?php else: ?>
                    <img src="img/newfoundation.jpg" alt="รูปโปรไฟล์มูลนิธิ">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['foundation_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
            </div>

        <?php elseif ($role === 'admin'): ?>
            <div class="profile-image-placeholder">
                <img src="img/user.png" alt="รูปโปรไฟล์แอดมิน">
            </div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
                <p class="badge-admin">ผู้ดูแลระบบ</p>
            </div>

        <?php else: ?>
            <div class="profile-image-placeholder">
                <?php if (!empty($profile['profile_image'])): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" alt="รูปโปรไฟล์">
                <?php else: ?>
                    <img src="img/user.png" alt="รูปโปรไฟล์ผู้บริจาค">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p><?= htmlspecialchars($profile['email']) ?></p>
                <?php if (!empty($profile['tax_id'])): ?>
                    <div class="info-row">
                        <span class="info-label">เลขประจำตัวผู้เสียภาษี:</span>
                        <?= htmlspecialchars($profile['tax_id']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($role === 'foundation' && !empty($profile['account_verified']) && empty($profile['bank_account_number'])): ?>
        <div class="alert-bank">
            บัญชีของคุณได้รับการยืนยันแล้ว กรุณาเพิ่มข้อมูลบัญชีธนาคารในหน้าแก้ไขโปรไฟล์เพื่อรับการโอนเงินบริจาค
        </div>
    <?php endif; ?>

    <?php if ($role === 'foundation'): ?>
        <a href="update_profile.php" class="btn-edit">แก้ไขโปรไฟล์</a>

        <!-- ===== โครงการที่ต้องอัปเดต ===== -->
        <?php if (!empty($need_updates)): ?>
            <?php foreach ($need_updates as $nu):
                $days_left    = (int)$nu['days_left'];
                $update_count = (int)$nu['update_count'];
                $is_overdue   = $days_left < 0;
                $is_urgent    = $days_left <= 7 && $days_left >= 0;
            ?>
                <?php if ($update_count === 0): ?>
                    <div style="
                        background: <?= $is_overdue ? '#fdecea' : ($is_urgent ? '#fff8e1' : '#f0f4ff') ?>;
                        border: 1.5px solid <?= $is_overdue ? '#ef9a9a' : ($is_urgent ? '#ffe082' : '#b3c2f0') ?>;
                        border-radius: 12px; padding: 16px 20px; margin-bottom: 16px;
                        display: flex; justify-content: space-between; align-items: center; gap: 16px;
                    ">
                        <div>
                            <?php if ($is_overdue): ?>
                                <div style="font-size:13px; font-weight:700; color:#c62828; margin-bottom:4px;">⚠️ เกินกำหนดอัปเดตแล้ว!</div>
                            <?php elseif ($is_urgent): ?>
                                <div style="font-size:13px; font-weight:700; color:#f57f17; margin-bottom:4px;">⏰ ใกล้ครบกำหนดอัปเดต!</div>
                            <?php else: ?>
                                <div style="font-size:13px; font-weight:700; color:#4A5BA8; margin-bottom:4px;">📋 โครงการรอการอัปเดต</div>
                            <?php endif; ?>
                            <div style="font-size:15px; font-weight:700; color:#222;"><?= htmlspecialchars($nu['project_name']) ?></div>
                            <div style="font-size:12px; color:#666; margin-top:4px;">
                                <?php if ($is_overdue): ?>
                                    เกินกำหนด <?= abs($days_left) ?> วันแล้ว กรุณาโพสต์ความคืบหน้าโดยด่วน
                                <?php else: ?>
                                    เหลือเวลาอีก <strong><?= $days_left ?> วัน</strong> ในการโพสต์ความคืบหน้า
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="foundation_post_update.php?project_id=<?= $nu['project_id'] ?>"
                           style="background:#4A5BA8; color:white; padding:10px 18px; border-radius:8px;
                                  text-decoration:none; font-size:13px; font-weight:600; white-space:nowrap; flex-shrink:0;">
                            โพสต์ความคืบหน้า
                        </a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- โครงการของมูลนิธิ -->
        <div class="logs-section">
            <h2>โครงการของเรา</h2>

            <?php if (!empty($foundation_projects)): ?>
                <div class="project-list">
                <?php foreach ($foundation_projects as $proj): ?>
                    <?php
                        $st = statusLabel($proj['project_status']);
                        $goal = (float)($proj['goal_amount'] ?? 0);
                        $current = (float)($proj['current_donate'] ?? 0);
                        $percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
                    ?>
                    <div class="project-item <?= htmlspecialchars($proj['project_status']) ?>">
                        <?php if (!empty($proj['project_image'])): ?>
                            <img src="uploads/<?= htmlspecialchars($proj['project_image']) ?>" class="project-thumb" alt="">
                        <?php else: ?>
                            <div class="project-thumb-empty"></div>
                        <?php endif; ?>
                        <div class="project-detail">
                            <div class="project-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                            <span class="project-status" style="background:<?= $st['color'] ?>">
                                <?= $st['label'] ?>
                            </span>
                            <?php if ($proj['project_status'] !== 'pending' && $proj['project_status'] !== 'rejected'): ?>
                                <div class="project-bar-wrap">
                                    <div class="project-bar-fill" style="width:<?= (int)$percent ?>%"></div>
                                </div>
                                <div class="project-amount">
                                    ได้รับ <strong><?= number_format($current, 0) ?></strong> / 
                                    <?= number_format($goal, 0) ?> บาท
                                    (<?= round($percent) ?>%)
                                    <?php if ($proj['project_status'] === 'completed'): ?>
                                        — <span style="color:#4A5BA8; font-weight:600;">โครงการสำเร็จแล้ว</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($proj['end_date'])): ?>
                                <div style="font-size:12px; color:#999; margin-top:4px;">
                                    วันสิ้นสุด: <?= date('d/m/Y', strtotime($proj['end_date'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align:center; color:#999; padding:30px;">
                    ยังไม่มีโครงการ
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($role === 'donor'): ?>
        <a href="donor_update_profile.php" class="btn-edit">แก้ไขโปรไฟล์</a>

        <div class="logs-section">
            <h2>ประวัติการบริจาค</h2>
            <?php if (!empty($donation_history)): ?>
                <?php $total_donated = array_sum(array_column($donation_history, 'amount')); ?>
                <div class="donation-summary">
                    บริจาคทั้งหมด <strong><?= number_format($total_donated, 2) ?> บาท</strong>
                    จาก <?= count($donation_history) ?> รายการ
                </div>
                <?php foreach ($donation_history as $don): ?>
                    <div class="log-item">
                        <div class="log-action">
                            <?php if (!empty($don['project_donate'])): ?>
                                บริจาคให้โครงการ
                            <?php elseif (!empty($don['needitem_donate'])): ?>
                                บริจาครายการสิ่งของ
                            <?php else: ?>
                                บริจาค
                            <?php endif; ?>
                        </div>
                        <div class="log-details">
                            <strong>จำนวน:</strong>
                            <span style="color:#E74C3C; font-weight:700;">
                                <?= number_format((float)$don['amount'], 2) ?> บาท
                            </span>
                        </div>
                        <?php if (!empty($don['omise_charge_id'])): ?>
                            <div class="log-details" style="font-size:12px; color:#999;">
                                อ้างอิง: <?= htmlspecialchars($don['omise_charge_id']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="log-time">
                            <?= date('d/m/Y H:i', strtotime($don['transfer_datetime'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center; color:#999; padding:30px;">
                    ยังไม่มีประวัติการบริจาค
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($role === 'admin' && !empty($logs)): ?>
        <div class="logs-section">
            <h2>ประวัติการทำงาน</h2>
            <?php foreach ($logs as $log): ?>
                <?php
                    $isApprove = strpos($log['action_type'], 'Approve') !== false;
                    $isReject  = strpos($log['action_type'], 'Reject') !== false;
                    $class     = $isApprove ? 'approve' : ($isReject ? 'reject' : '');
                    $hasDetails = !empty($log['item_name']) || !empty($log['project_name']);
                ?>
                <div class="log-item <?= $class ?>" <?= $hasDetails ? 'onclick="showModal(' . htmlspecialchars(json_encode($log)) . ')"' : '' ?>>
                    <div class="log-action"><?= translateAction($log['action_type']) ?></div>
                    <div class="log-details">
                        <?php if ($log['target_id']): ?>
                            <strong>รหัสอ้างอิง:</strong> #<?= $log['target_id'] ?>
                            <?= $hasDetails ? ' <span style="color:#4A5BA8;">(คลิกดูรายละเอียด)</span>' : '' ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['remark'])): ?>
                        <div class="log-remark">
                            <strong>เหตุผล:</strong> <?= htmlspecialchars($log['remark']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="log-time"><?= date('d/m/Y H:i:s', strtotime($log['action_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="detailModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
function showModal(data) {
    const modal = document.getElementById('detailModal');
    const body  = document.getElementById('modalBody');
    let html = '';
    if (data.item_name) {
        if (data.item_image) html += `<img class="modal-image" src="uploads/needs/${data.item_image}" alt="">`;
        html += `<div class="modal-title">${data.item_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">มูลนิธิ:</div><div class="modal-value">${data.foundation_name || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รายละเอียด:</div><div class="modal-value">${data.item_desc || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">จำนวน:</div><div class="modal-value">${data.qty_needed} ชิ้น</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">ราคา/หน่วย:</div><div class="modal-value">${Number(data.price_estimate).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รวม:</div><div class="modal-value"><strong>${(data.qty_needed * data.price_estimate).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</strong></div></div>`;
    }
    if (data.project_name) {
        html += `<div class="modal-title">${data.project_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">รายละเอียด:</div><div class="modal-value">${data.project_desc || '-'}</div></div>`;
    }
    body.innerHTML = html;
    modal.classList.add('active');
}
function closeModal() {
    document.getElementById('detailModal').classList.remove('active');
}
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

</body>
</html>