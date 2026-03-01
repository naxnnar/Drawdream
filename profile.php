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
    // ดึงข้อมูลมูลนิธิ
    $stmt = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp 
                           JOIN users u ON fp.user_id = u.user_id 
                           WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    
} elseif ($role === 'donor') {
    // ดึงข้อมูลผู้บริจาค
    $stmt = $conn->prepare("SELECT d.*, u.email FROM donor d 
                           JOIN users u ON d.user_id = u.user_id 
                           WHERE d.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    
    // ดึงประวัติการบริจาค
    $stmt2 = $conn->prepare("SELECT fd.*, fp.foundation_name 
                            FROM foundation_donations fd
                            LEFT JOIN foundation_profile fp ON fd.foundation_id = fp.foundation_id
                            WHERE fd.donor_id = (SELECT donor_id FROM donor WHERE user_id = ?)
                            ORDER BY fd.donated_at DESC LIMIT 10");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $donations = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    die("Role ไม่รองรับ");
}

if (!$profile) {
    die("ไม่พบข้อมูลโปรไฟล์");
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
        
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #E57373;
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
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-item {
            padding: 20px;
            background: #f9f9f9;
            border-radius: 10px;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }
        
        .info-value {
            color: #333;
            font-size: 16px;
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
        }
        
        .btn-edit:hover {
            background: #3d4d8f;
            transform: translateY(-2px);
        }
        
        .donations-section {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
        }
        
        .donations-section h2 {
            font-size: 24px;
            color: #333;
            margin-bottom: 20px;
        }
        
        .donation-item {
            padding: 15px 20px;
            background: #f9f9f9;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .donation-amount {
            font-weight: bold;
            color: #E57373;
            font-size: 18px;
        }
        
        .donation-status {
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-verified {
            background: #4CAF50;
            color: white;
        }
        
        .status-pending {
            background: #FFC107;
            color: #333;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-container">
    <div class="profile-header">
        <?php if ($role === 'foundation'): ?>
            <?php if (!empty($profile['foundation_image'])): ?>
                <img src="uploads/profiles/<?= htmlspecialchars($profile['foundation_image']) ?>" alt="รูปโปรไฟล์" class="profile-image">
            <?php else: ?>
                <div class="profile-image-placeholder">🏛️</div>
            <?php endif; ?>
            
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['foundation_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($profile['email']) ?></p>
                <p>☎️ <?= htmlspecialchars($profile['phone'] ?? '-') ?></p>
            </div>
        <?php else: ?>
            <?php if (!empty($profile['profile_image'])): ?>
                <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" alt="รูปโปรไฟล์" class="profile-image">
            <?php else: ?>
                <div class="profile-image-placeholder">👤</div>
            <?php endif; ?>
            
            <div class="profile-info">
                <h1><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($profile['email']) ?></p>
                <p>☎️ <?= htmlspecialchars($profile['phone'] ?? '-') ?></p>
            </div>
        <?php endif; ?>
    </div>

    <div class="info-grid">
        <?php if ($role === 'foundation'): ?>
            <div class="info-item">
                <div class="info-label">เลขทะเบียน</div>
                <div class="info-value"><?= htmlspecialchars($profile['registration_number'] ?? '-') ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Facebook</div>
                <div class="info-value"><?= htmlspecialchars($profile['facebook_url'] ?? '-') ?></div>
            </div>
            
            <div class="info-item" style="grid-column: span 2;">
                <div class="info-label">ที่อยู่</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($profile['address'] ?? '-')) ?></div>
            </div>
            
            <div class="info-item" style="grid-column: span 2;">
                <div class="info-label">คำอธิบายมูลนิธิ</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($profile['foundation_desc'] ?? '-')) ?></div>
            </div>
        <?php else: ?>
            <div class="info-item">
                <div class="info-label">ชื่อ</div>
                <div class="info-value"><?= htmlspecialchars($profile['first_name']) ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">นามสกุล</div>
                <div class="info-value"><?= htmlspecialchars($profile['last_name']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <a href="update_profile.php" class="btn-edit">✏️ แก้ไขโปรไฟล์</a>

    <?php if ($role === 'donor' && !empty($donations)): ?>
        <div class="donations-section">
            <h2>ประวัติการบริจาค</h2>
            <?php foreach ($donations as $d): ?>
                <div class="donation-item">
                    <div>
                        <strong><?= htmlspecialchars($d['foundation_name'] ?? 'มูลนิธิ') ?></strong><br>
                        <small><?= date('d/m/Y H:i', strtotime($d['donated_at'])) ?></small>
                    </div>
                    <div>
                        <span class="donation-amount"><?= number_format($d['amount'], 0) ?> บาท</span>
                        <span class="donation-status status-<?= $d['status'] ?>">
                            <?= $d['status'] === 'verified' ? 'ยืนยันแล้ว' : 'รอตรวจสอบ' ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>