<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'donor') {
    header("Location: profile.php");
    exit();
}

$msg = "";
$error = "";

// ดึงข้อมูลปัจจุบัน
$stmt = $conn->prepare("SELECT d.*, u.email FROM donor d 
                       JOIN users u ON d.user_id = u.user_id 
                       WHERE d.user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

if (!$profile) {
    die("ไม่พบข้อมูลโปรไฟล์");
}

// บันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    
    // ตรวจสอบเลขประจำตัวผู้เสียภาษี (13 หลัก)
    if (!empty($tax_id) && !preg_match('/^\d{13}$/', $tax_id)) {
        $error = "เลขประจำตัวผู้เสียภาษีต้องเป็นตัวเลข 13 หลัก";
    } elseif (empty($first_name) || empty($last_name)) {
        $error = "กรุณากรอกชื่อและนามสกุล";
    } else {
        $stmt = $conn->prepare("UPDATE donor SET first_name=?, last_name=?, tax_id=? WHERE user_id=?");
        $stmt->bind_param("sssi", $first_name, $last_name, $tax_id, $user_id);
        
        if ($stmt->execute()) {
            $msg = "บันทึกข้อมูลสำเร็จ!";
            // รีเฟรชข้อมูล
            $stmt2 = $conn->prepare("SELECT d.*, u.email FROM donor d 
                                    JOIN users u ON d.user_id = u.user_id 
                                    WHERE d.user_id = ? LIMIT 1");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            $profile = $stmt2->get_result()->fetch_assoc();
        } else {
            $error = "เกิดข้อผิดพลาด: " . $stmt->error;
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
        
        .edit-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .edit-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .edit-header h1 {
            font-size: 32px;
            color: #333;
            margin: 0 0 10px 0;
        }
        
        .edit-header p {
            color: #666;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .form-label.required::after {
            content: ' *';
            color: #E57373;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #4A5BA8;
            box-shadow: 0 0 0 3px rgba(74, 91, 168, 0.1);
        }
        
        .form-input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 15px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #4A5BA8;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3d4d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 91, 168, 0.3);
        }
        
        .btn-secondary {
            background: #f5f5f5;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            color: #2e7d32;
        }
        
        .alert-error {
            background: #ffebee;
            border: 1px solid #ef9a9a;
            color: #c62828;
        }
        
        .profile-icon {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .icon-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            color: white;
            margin: 0 auto;
        }
        
        .form-help {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="edit-container">
    <div class="edit-header">
        <div class="profile-icon">
            <div class="icon-circle">👤</div>
        </div>
        <h1>แก้ไขโปรไฟล์</h1>
        <p><?= htmlspecialchars($profile['email']) ?></p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label class="form-label required">ชื่อจริง</label>
            <input type="text" name="first_name" class="form-input" 
                   value="<?= htmlspecialchars($profile['first_name']) ?>" 
                   placeholder="เช่น กิมวสุ" required>
        </div>

        <div class="form-group">
            <label class="form-label required">นามสกุล</label>
            <input type="text" name="last_name" class="form-input" 
                   value="<?= htmlspecialchars($profile['last_name']) ?>" 
                   placeholder="เช่น ไชยตี" required>
        </div>

        <div class="form-group">
            <label class="form-label">เลขประจำตัวผู้เสียภาษี</label>
            <input type="text" name="tax_id" class="form-input" 
                   value="<?= htmlspecialchars($profile['tax_id'] ?? '') ?>" 
                   placeholder="1100123456789" 
                   maxlength="13"
                   pattern="\d{13}">
            <div class="form-help">13 หลัก (ถ้ามี)</div>
        </div>

        <div class="form-group">
            <label class="form-label">อีเมล</label>
            <input type="email" class="form-input" 
                   value="<?= htmlspecialchars($profile['email']) ?>" disabled>
            <div class="form-help">ไม่สามารถแก้ไขอีเมลได้</div>
        </div>

        <div class="btn-group">
            <a href="profile.php" class="btn btn-secondary">ยกเลิก</a>
            <button type="submit" name="update_profile" class="btn btn-primary">💾 บันทึก</button>
        </div>
    </form>
</div>

</body>
</html>