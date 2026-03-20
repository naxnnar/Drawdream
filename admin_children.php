<?php
session_start();
include 'db.php';

// ให้เข้าได้เฉพาะ foundation
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: login.php");
    exit();
}


$sql = "SELECT * FROM `foundation_profile` WHERE `user_id` = ?";
$stmtFP = $conn->prepare($sql);
$stmtFP->bind_param("i", $_SESSION['user_id']);
$stmtFP->execute();

$result = $stmtFP->get_result(); 

if ($fetchArr = $result->fetch_assoc()) {
    $f_id   = $fetchArr['foundation_id']; 
    $f_name = $fetchArr['foundation_name'];
} else {
    $f_id   = 0;
    $f_name = "ไม่พบชื่อมูลนิธิ";
}

// รองรับคอลัมน์วันเกิด (กรณียังไม่มีในฐานข้อมูล ให้เพิ่มอัตโนมัติ)
$has_birth_date_column = false;
$colCheck = $conn->query("SHOW COLUMNS FROM Children LIKE 'birth_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    $has_birth_date_column = true;
} else {
    $conn->query("ALTER TABLE Children ADD COLUMN birth_date DATE NULL AFTER child_name");
    $colCheck = $conn->query("SHOW COLUMNS FROM Children LIKE 'birth_date'");
    $has_birth_date_column = ($colCheck && $colCheck->num_rows > 0);
}

if (isset($_POST['submit'])) {

    $child_name    = trim($_POST['child_name'] ?? '');
    $birth_date_raw = trim($_POST['birth_date'] ?? '');
    $age           = 0;
    $education     = trim($_POST['education'] ?? '');
    $dream         = trim($_POST['dream'] ?? '');
    $wish          = trim($_POST['wish'] ?? '');
    $bank_name     = trim($_POST['bank_name'] ?? '');
    $child_bank    = trim($_POST['child_bank'] ?? '');
    $status        = "ยังไม่มีผู้อุปการะ"; // ค่าเริ่มต้นตามตัวอย่าง
    $approve_status = "รอดำเนินการ";

    // คำนวณอายุจากวันเกิด
    $dob = DateTime::createFromFormat('Y-m-d', $birth_date_raw);
    $today = new DateTime('today');
    if (!$dob || $dob->format('Y-m-d') !== $birth_date_raw) {
        echo "<script>alert('กรุณาเลือกวันเกิดให้ถูกต้อง'); history.back();</script>";
        exit();
    }
    if ($dob > $today) {
        echo "<script>alert('วันเกิดต้องไม่เป็นวันที่ในอนาคต'); history.back();</script>";
        exit();
    }
    $age = (int)$today->diff($dob)->y;

    // จัดการไฟล์รูปภาพ
    if (!isset($_FILES['photo_child']) || $_FILES['photo_child']['error'] !== 0) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพเด็ก'); history.back();</script>";
        exit();
    }

    $imageName = $_FILES['photo_child']['name'];
    $tmpName   = $_FILES['photo_child']['tmp_name'];
    $ext       = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
    $allowed   = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($ext, $allowed)) {
        echo "<script>alert('อนุญาตเฉพาะไฟล์รูปภาพเท่านั้น'); history.back();</script>";
        exit();
    }

    $newName = "child_" . time() . "." . $ext;

    if (!move_uploaded_file($tmpName, "uploads/Children/" . $newName)) {
        echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
        exit();
    }


    if ($has_birth_date_column) {
        $sql = "INSERT INTO Children (foundation_id, foundation_name, child_name, birth_date, age, education, dream, wish, bank_name, child_bank, status, photo_child, approve_profile) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "isssissssssss",
            $f_id,
            $f_name,
            $child_name,
            $birth_date_raw,
            $age,
            $education,
            $dream,
            $wish,
            $bank_name,
            $child_bank,
            $status,
            $newName,
            $approve_status
        );
    } else {
        $sql = "INSERT INTO Children (foundation_id, foundation_name, child_name, age, education, dream, wish, bank_name, child_bank, status, photo_child, approve_profile) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ississssssss", $f_id, $f_name, $child_name, $age, $education, $dream, $wish, $bank_name, $child_bank, $status, $newName, $approve_status);
    }

    if ($stmt->execute()) {
        echo "<script>alert('เพิ่มข้อมูลเด็กสำเร็จ'); window.location='children_.php';</script>";
        exit();
    } else {
        // เปลี่ยนตรงนี้เพื่อดู Error จริงๆ จาก MySQL
        die("MySQL Error: " . $stmt->error); 
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มข้อมูลเด็ก - Children Profile</title>
<link rel="stylesheet" href="css/navbar.css">
<link rel="stylesheet" href="css/style.css">
<style>
    .form-container { display:flex; gap:60px; padding:40px; max-width: 1200px; margin: auto; }
    .left-box { width:35%; }
    .upload-preview { width:100%; height:350px; background:#f0f0f0; border: 2px dashed #bbb; border-radius:15px; display:flex; justify-content:center; align-items:center; overflow:hidden; }
    .upload-preview img { width: 100%; height: 100%; object-fit: cover; }
    .right-box { width:65%; background: #fff; padding: 30px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .grid-inputs { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    label { font-weight: bold; display: block; margin-bottom: 5px; color: #555; }
    input, textarea, select { width:100%; padding:12px; border-radius:10px; border:1px solid #ddd; margin-bottom:15px; box-sizing: border-box; }
    .full-width { grid-column: span 2; }
    button { background:#e5c24c; padding:15px 50px; border:none; border-radius:15px; font-size:18px; cursor:pointer; font-weight:bold; width: 100%; margin-top: 10px; }
    button:hover { background: #d4b13a; }
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="form-container">
    <div class="left-box">
        <h2 style="color: #333;">เพิ่มโปรไฟล์เด็ก</h2>
        <p style="color: #777;">มูลนิธิ: <?php echo $f_name; ?></p>
        <div class="upload-preview" id="preview-container">
            <span id="preview-text">ตัวอย่างรูปภาพ</span>
        </div>
    </div>

    <div class="right-box">
        <form method="POST" enctype="multipart/form-data">
            <div class="grid-inputs">
                <div>
                    <label>ชื่อเล่นเด็ก</label>
                    <input type="text" name="child_name" placeholder="ตัวอย่าง: น้องฟ้า" required>
                </div>
                <div>
                    <label>วันเกิดเด็ก</label>
                    <input type="date" id="birth_date" name="birth_date" max="<?php echo date('Y-m-d'); ?>" required onchange="syncAgeAndEducation()">
                </div>
                <div>
                    <label>อายุ (คำนวณอัตโนมัติ)</label>
                    <input type="number" id="age" name="age_preview" readonly>
                </div>
                <div>
                    <label>ระดับการศึกษา</label>
                    <input type="text" id="education" name="education" placeholder="ตัวอย่าง: ป.6" required>
                    <small style="color:#777;">ระบบจะแนะนำชั้นเรียนตามอายุ แต่สามารถแก้ไขเองได้หากเด็กเรียนช้ากว่ากำหนด</small>
                </div>
                <div>
                    <label>ความฝันในอนาคต</label>
                    <input type="text" name="dream" placeholder="ตัวอย่าง: คุณหมอ" required>
                </div>
                <div class="full-width">
                    <label>สิ่งที่อยากขอ / ความต้องการ</label>
                    <textarea name="wish" rows="3" placeholder="ระบุสิ่งที่เด็กต้องการ..." required></textarea>
                </div>
                <div>
                    <label>ธนาคาร</label>
                    <input type="text" name="bank_name" placeholder="ตัวอย่าง: กสิกร" required>
                </div>
                <div>
                    <label>เลขบัญชีธนาคาร</label>
                    <input type="text" name="child_bank" placeholder="000-0-00000-0" required>
                </div>
                <div class="full-width">
                    <label>รูปภาพเด็ก (photo_child)</label>
                    <input type="file" name="photo_child" accept="image/*" required onchange="previewImage(this)">
                </div>
            </div>

            <button type="submit" name="submit">บันทึกข้อมูลและส่งอนุมัติ</button>
        </form>
    </div>
</div>

<script>
function previewImage(input) {
    const preview = document.getElementById('preview-container');
    const text = document.getElementById('preview-text');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

let educationManuallyEdited = false;
document.getElementById('education').addEventListener('input', function() {
    educationManuallyEdited = true;
});

function getSuggestedEducation(age) {
    if (age <= 5) return 'อนุบาล';
    if (age === 6) return 'ป.1';
    if (age === 7) return 'ป.2';
    if (age === 8) return 'ป.3';
    if (age === 9) return 'ป.4';
    if (age === 10) return 'ป.5';
    if (age === 11) return 'ป.6';
    if (age === 12) return 'ม.1';
    if (age === 13) return 'ม.2';
    if (age === 14) return 'ม.3';
    if (age === 15) return 'ม.4';
    if (age === 16) return 'ม.5';
    if (age === 17) return 'ม.6';
    return 'อุดมศึกษา/อาชีวะ';
}

function syncAgeAndEducation() {
    const birthDateEl = document.getElementById('birth_date');
    const ageEl = document.getElementById('age');
    const educationEl = document.getElementById('education');

    if (!birthDateEl.value) {
        ageEl.value = '';
        return;
    }

    const today = new Date();
    const dob = new Date(birthDateEl.value + 'T00:00:00');

    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    const dayDiff = today.getDate() - dob.getDate();
    if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
        age--;
    }

    if (age < 0) {
        ageEl.value = '';
        return;
    }

    ageEl.value = age;
    if (!educationManuallyEdited || !educationEl.value.trim()) {
        educationEl.value = getSuggestedEducation(age);
    }
}
</script>

</body>
</html>