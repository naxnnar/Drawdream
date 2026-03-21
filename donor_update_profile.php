<?php
// ไฟล์นี้: donor_update_profile.php
// หน้าที่: หน้าแก้ไขโปรไฟล์ผู้บริจาค
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/donor_update_profile.css">
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