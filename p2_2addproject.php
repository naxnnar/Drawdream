<?php
session_start();
include 'db.php';

// ให้เข้าได้เฉพาะ foundation
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: p2_project.php");
    exit();
}

if (isset($_POST['submit'])) {

    $name    = trim($_POST['project_name'] ?? '');
    $desc    = trim($_POST['project_desc'] ?? '');
    $goal    = (int)($_POST['project_goal'] ?? 0);
    $enddate = $_POST['project_enddate'] ?? '';

    // ตรวจไฟล์รูป
    if (!isset($_FILES['project_image']) || $_FILES['project_image']['error'] !== 0) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพให้ถูกต้อง'); history.back();</script>";
        exit();
    }

    $imageName = $_FILES['project_image']['name'];
    $tmpName   = $_FILES['project_image']['tmp_name'];

    // เช็คชนิดไฟล์ (อนุญาตเฉพาะรูป)
    $ext = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) {
        echo "<script>alert('อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp เท่านั้น'); history.back();</script>";
        exit();
    }

    // กันชื่อไฟล์ซ้ำ + กันอักขระแปลก
    $newName = time() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "", $imageName);

    if (!move_uploaded_file($tmpName, "uploads/" . $newName)) {
        echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
        exit();
    }

    // ✅ INSERT พร้อม status = pending (รอแอดมินอนุมัติ)
    $stmt = $conn->prepare("
        INSERT INTO project (project_name, project_desc, project_goal, project_enddate, project_image, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("ssiss", $name, $desc, $goal, $enddate, $newName);

    if ($stmt->execute()) {
        echo "<script>alert('เสนอโครงการสำเร็จ (รอแอดมินอนุมัติ)'); window.location='p2_project.php';</script>";
        exit();
    } else {
        echo "<script>alert('บันทึกข้อมูลไม่สำเร็จ'); history.back();</script>";
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เสนอโครงการ</title>
<link rel="stylesheet" href="css/style.css">
<style>
.form-container{ display:flex; gap:60px; padding:50px; }
.left-box{ width:40%; }
.upload-box{ width:100%; height:300px; background:#ccc; border-radius:15px; display:flex; justify-content:center; align-items:center; }
.right-box{ width:50%; }
input, textarea, select{ width:100%; padding:10px; border-radius:10px; border:1px solid #aaa; margin-bottom:20px; }
button{ background:#e5c24c; padding:15px 40px; border:none; border-radius:15px; font-size:18px; cursor:pointer; }
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="form-container">

    <div class="left-box">
        <h2>เสนอโครงการ</h2>
        <div class="upload-box">
            เลือกรูปด้านขวา →
        </div>
    </div>

    <div class="right-box">
        <form method="POST" enctype="multipart/form-data">

            <label>หัวข้อโครงการ</label>
            <input type="text" name="project_name" required>

            <label>รายละเอียดโครงการ</label>
            <textarea name="project_desc" rows="5" required></textarea>

            <label>เป้าหมาย (บาท)</label>
            <input type="number" name="project_goal" required>

            <label>วันที่ปิดรับบริจาค</label>
            <input type="date" name="project_enddate" required>

            <label>อัปโหลดรูปภาพ</label>
            <input type="file" name="project_image" accept="image/*" required>

            <button type="submit" name="submit">เสนอโครงการ</button>

        </form>
    </div>

</div>

</body>
</html>