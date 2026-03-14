<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// ดึงข้อมูลตาม role
if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp 
                           JOIN users u ON fp.user_id = u.user_id 
                           WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    
} elseif ($role === 'donor') {
    $stmt = $conn->prepare("SELECT d.*, u.email FROM donor d 
                           JOIN users u ON d.user_id = u.user_id 
                           WHERE d.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    
    // ไม่ดึงประวัติบริจาคในหน้านี้ (ถ้าต้องการให้เพิ่มทีหลัง)
    $donations = [];
    
} elseif ($role === 'admin') {
    $stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    
    $profile = [
        'email' => $user_data['email'] ?? '',
        'first_name' => 'Admin',
        'last_name' => 'System'
    ];
    
    // ดึงประวัติการทำงานพร้อมรายละเอียด
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

if (!$profile) {
    die("ไม่พบข้อมูลโปรไฟล์");
}

function translateAction($action) {
    $map = [
        'Login' => '🔐 เข้าสู่ระบบ',
        'Approve_Child' => '✅ อนุมัติเด็ก',
        'Reject_Project' => '❌ ปฏิเสธโครงการ',
        'Approve_Need' => '✅ อนุมัติรายการสิ่งของ',
        'Reject_Need' => '❌ ปฏิเสธรายการสิ่งของ',
        'Approve_Project' => '✅ อนุมัติโครงการ',
        'Other' => 'อื่นๆ'
    ];
    return $map[$action] ?? $action;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .profile-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .profile-image-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
        }
        
        .profile-info h1 {
            font-size: 32px;
            color: #333;
            margin: 0 0 10px 0;
        }
        
        .profile-info p {
            color: #666;
            margin: 5px 0;
        }
        
        .info-row {
            margin: 10px 0;
            color: #666;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
        }
        
        .btn-edit {
            background: #4A5BA8;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .btn-edit:hover {
            background: #3d4d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 91, 168, 0.3);
        }
        
        .logs-section {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
        }
        
        .logs-section h2 {
            font-size: 24px;
            color: #333;
            margin-bottom: 20px;
        }
        
        .log-item {
            padding: 20px;
            background: #f9f9f9;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #4A5BA8;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .log-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .log-item.approve {
            border-left-color: #4CAF50;
            background: #f1f8f4;
        }
        
        .log-item.reject {
            border-left-color: #E57373;
            background: #fef5f5;
        }
        
        .log-action {
            font-weight: bold;
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .log-details {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .log-remark {
            margin-top: 10px;
            padding: 10px 15px;
            background: #fff;
            border-radius: 5px;
            color: #E57373;
            font-style: italic;
        }
        
        .log-time {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .modal-close:hover {
            color: #333;
        }
        
        .modal-image {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }
        
        .modal-section {
            margin-bottom: 15px;
        }
        
        .modal-label {
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
        }
        
        .modal-value {
            color: #333;
            line-height: 1.6;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-container">
    <div class="profile-header">
        <?php if ($role === 'foundation'): ?>
            <div class="profile-image-placeholder">🏛️</div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['foundation_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($profile['email']) ?></p>
            </div>
            
        <?php elseif ($role === 'admin'): ?>
            <div class="profile-image-placeholder">👨‍💼</div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($profile['email']) ?></p>
                <p style="color: #E57373; font-weight: bold;">🔑 ผู้ดูแลระบบ</p>
            </div>
            
        <?php else: ?>
            <div class="profile-image-placeholder">👤</div>
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($profile['email']) ?></p>
                <?php if (!empty($profile['tax_id'])): ?>
                    <div class="info-row">
                        <span class="info-label">เลขประจำตัวผู้เสียภาษี:</span> 
                        <?= htmlspecialchars($profile['tax_id']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($role === 'foundation'): ?>
        <a href="update_profile.php" class="btn-edit">✏️ แก้ไขโปรไฟล์</a>
    <?php elseif ($role === 'donor'): ?>
        <a href="donor_update_profile.php" class="btn-edit">✏️ แก้ไขโปรไฟล์</a>
    <?php endif; ?>

    <?php if ($role === 'admin' && !empty($logs)): ?>
        <div class="logs-section">
            <h2>📋 ประวัติการทำงาน</h2>
            <?php foreach ($logs as $log): ?>
                <?php
                    $isApprove = strpos($log['action_type'], 'Approve') !== false;
                    $isReject = strpos($log['action_type'], 'Reject') !== false;
                    $class = $isApprove ? 'approve' : ($isReject ? 'reject' : '');
                    $hasDetails = !empty($log['item_name']) || !empty($log['project_name']);
                ?>
                <div class="log-item <?= $class ?>" <?= $hasDetails ? 'onclick="showModal(' . htmlspecialchars(json_encode($log)) . ')"' : '' ?>>
                    <div class="log-action">
                        <?= translateAction($log['action_type']) ?>
                    </div>
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
                    <div class="log-time">
                        🕒 <?= date('d/m/Y H:i:s', strtotime($log['action_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="detailModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
function showModal(data) {
    const modal = document.getElementById('detailModal');
    const body = document.getElementById('modalBody');
    
    let html = '';
    
    // รายการสิ่งของ
    if (data.item_name) {
        if (data.item_image) {
            html += `<img class="modal-image" src="uploads/needs/${data.item_image}" alt="">`;
        }
        html += `<div class="modal-title">${data.item_name}</div>`;
        html += `<div class="modal-section"><div class="modal-label">มูลนิธิ:</div><div class="modal-value">${data.foundation_name || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รายละเอียด:</div><div class="modal-value">${data.item_desc || '-'}</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">จำนวน:</div><div class="modal-value">${data.qty_needed} ชิ้น</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">ราคา/หน่วย:</div><div class="modal-value">${Number(data.price_estimate).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</div></div>`;
        html += `<div class="modal-section"><div class="modal-label">รวม:</div><div class="modal-value"><strong>${(data.qty_needed * data.price_estimate).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</strong></div></div>`;
    }
    
    // โครงการ
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

// ปิด modal เมื่อคลิกนอก content
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

</body>
</html>