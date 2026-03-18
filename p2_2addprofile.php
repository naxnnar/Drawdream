<?php
session_start();
include 'db.php';

// ให้เข้าได้เฉพาะ foundation
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: index.php");
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

if (isset($_POST['submit'])) {

    $child_name    = trim($_POST['child_name'] ?? '');
    $age           = (int)($_POST['age'] ?? 0);
    $education     = trim($_POST['education'] ?? '');
    $dream         = trim($_POST['dream'] ?? '');
    $wish          = trim($_POST['wish'] ?? '');
    $bank_name     = trim($_POST['bank_name'] ?? '');
    $child_bank    = trim($_POST['child_bank'] ?? '');
    $status        = "ยังไม่มีผู้อุปการะ"; // ค่าเริ่มต้นตามตัวอย่าง
    $approve_status = "กำลังดำเนินการ"; // ตามรูป approve_profile

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


    $sql = "INSERT INTO Children (foundation_id, foundation_name, child_name, age, education, dream, wish, bank_name, child_bank, status, photo_child, approve_profile) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ississssssss", $f_id, $f_name, $child_name, $age, $education, $dream, $wish, $bank_name, $child_bank, $status, $newName, $approve_status);

    if ($stmt->execute()) {
        echo "<script>alert('เพิ่มข้อมูลเด็กสำเร็จ'); window.location='donation.php';</script>";
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
                    <label>อายุ (ปี)</label>
                    <input type="number" name="age" required>
                </div>
                <div>
                    <label>ระดับการศึกษา</label>
                    <input type="text" name="education" placeholder="ตัวอย่าง: ป.6" required>
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
</script>

</body>
</html>