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

$error = "";
$success = "";

// ดึงข้อมูลปัจจุบัน
if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
} else {
    $stmt = $conn->prepare("SELECT * FROM donor WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
}

if (!$profile) die("ไม่พบข้อมูลโปรไฟล์");

// อัปเดตข้อมูล
if (isset($_POST['update'])) {
    
    // อัปโหลดรูปโปรไฟล์
    $newProfileImage = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $uploadDir = "uploads/profiles/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        
        if (in_array($ext, $allowed, true)) {
            $safeName = time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadDir . $safeName)) {
                $newProfileImage = $safeName;
            }
        }
    }
    
    if ($role === 'foundation') {
        $foundation_name = trim($_POST['foundation_name'] ?? '');
        $registration_number = trim($_POST['registration_number'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $facebook_url = trim($_POST['facebook_url'] ?? '');
        $foundation_desc = trim($_POST['foundation_desc'] ?? '');
        
        if (empty($foundation_name)) {
            $error = "กรุณากรอกชื่อมูลนิธิ";
        } else {
            $sql = "UPDATE foundation_profile SET 
                    foundation_name = ?,
                    registration_number = ?,
                    phone = ?,
                    address = ?,
                    website = ?,
                    facebook_url = ?,
                    foundation_desc = ?";
            
            if (!empty($newProfileImage)) {
                $sql .= ", foundation_image = ?";
            }
            
            $sql .= " WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($newProfileImage)) {
                $stmt->bind_param("ssssssssi", $foundation_name, $registration_number, $phone, 
                                 $address, $website, $facebook_url, $foundation_desc, $newProfileImage, $user_id);
            } else {
                $stmt->bind_param("sssssssi", $foundation_name, $registration_number, $phone, 
                                 $address, $website, $facebook_url, $foundation_desc, $user_id);
            }
            
            if ($stmt->execute()) {
                $success = "✅ อัปเดตโปรไฟล์สำเร็จ!";
                header("refresh:2;url=profile.php");
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt->error;
            }
        }
        
    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($first_name) || empty($last_name)) {
            $error = "กรุณากรอกชื่อ-นามสกุล";
        } else {
            $sql = "UPDATE donor SET 
                    first_name = ?,
                    last_name = ?,
                    phone = ?";
            
            if (!empty($newProfileImage)) {
                $sql .= ", profile_image = ?";
            }
            
            $sql .= " WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($newProfileImage)) {
                $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $newProfileImage, $user_id);
            } else {
                $stmt->bind_param("sssi", $first_name, $last_name, $phone, $user_id);
            }
            
            if ($stmt->execute()) {
                $success = "✅ อัปเดตโปรไฟล์สำเร็จ!";
                header("refresh:2;url=profile.php");
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขโปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .update-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        h2 { color: #333; margin-bottom: 30px; }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }
        
        .form-group input[type="text"],
        .form-group input[type="tel"],
        .form-group input[type="url"],
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        textarea { resize: vertical; min-height: 100px; }
        
        .image-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin: 20px 0;
        }
        
        .btn-submit {
            background: #4CAF50;
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 25px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background: #45a049;
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            background: #999;
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 25px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-left: 15px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="update-container">
    <h2>แก้ไขโปรไฟล์</h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        
        <?php if ($role === 'foundation'): ?>
            <!-- ฟอร์มมูลนิธิ -->
            
            <?php if (!empty($profile['foundation_image'])): ?>
                <img src="uploads/profiles/<?= htmlspecialchars($profile['foundation_image']) ?>" class="image-preview">
            <?php endif; ?>
            
            <div class="form-group">
                <label>รูปโปรไฟล์มูลนิธิ</label>
                <input type="file" name="profile_image" accept="image/*">
            </div>
            
            <div class="form-group">
                <label>ชื่อมูลนิธิ *</label>
                <input type="text" name="foundation_name" value="<?= htmlspecialchars($profile['foundation_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>เลขทะเบียน</label>
                <input type="text" name="registration_number" value="<?= htmlspecialchars($profile['registration_number'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>ที่อยู่</label>
                <textarea name="address"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group">
                <label>เว็บไซต์</label>
                <input type="url" name="website" value="<?= htmlspecialchars($profile['website'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Facebook URL</label>
                <input type="url" name="facebook_url" value="<?= htmlspecialchars($profile['facebook_url'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>คำอธิบายมูลนิธิ</label>
                <textarea name="foundation_desc"><?= htmlspecialchars($profile['foundation_desc'] ?? '') ?></textarea>
            </div>
            
        <?php else: ?>
            <!-- ฟอร์มผู้บริจาค -->
            
            <?php if (!empty($profile['profile_image'])): ?>
                <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" class="image-preview">
            <?php endif; ?>
            
            <div class="form-group">
                <label>รูปโปรไฟล์</label>
                <input type="file" name="profile_image" accept="image/*">
            </div>
            
            <div class="form-group">
                <label>ชื่อ *</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($profile['first_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>นามสกุล *</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($profile['last_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
            </div>
            
        <?php endif; ?>

        <button type="submit" name="update" class="btn-submit">💾 บันทึกข้อมูล</button>
        <a href="profile.php" class="btn-cancel">❌ ยกเลิก</a>
    </form>
</div>

</body>
</html>