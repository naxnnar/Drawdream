<?php
session_start();
include 'db.php';
// ดึง foundation_id
$stmt_fp = $conn->prepare("SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1");
$stmt_fp->bind_param("i", $_SESSION['user_id']);
$stmt_fp->execute();
$fp = $stmt_fp->get_result()->fetch_assoc();
$foundation_id = (int)($fp['foundation_id'] ?? 0);
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: project.php");
    exit();
}

$categories = ['เด็กเล็ก', 'เด็กพิการ', 'เด็กด้อยโอกาส', 'เด็กป่วย', 'การศึกษา', 'อาหารและโภชนาการ'];

if (isset($_POST['submit'])) {
    $name     = trim($_POST['project_name'] ?? '');
    $desc     = trim($_POST['project_desc'] ?? '');
    $goal     = (int)($_POST['goal_amount'] ?? 0);
    $enddate  = $_POST['end_date'] ?? '';
    $category = $_POST['category'] ?? '';

    if (!in_array($category, $categories, true)) {
        echo "<script>alert('กรุณาเลือกประเภทโครงการ'); history.back();</script>";
        exit();
    }

    if (!isset($_FILES['project_image']) || $_FILES['project_image']['error'] !== 0) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพให้ถูกต้อง'); history.back();</script>";
        exit();
    }

    $imageName = $_FILES['project_image']['name'];
    $tmpName   = $_FILES['project_image']['tmp_name'];
    $ext       = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
    $allowed   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed)) {
        echo "<script>alert('อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp เท่านั้น'); history.back();</script>";
        exit();
    }

    $newName = time() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "", $imageName);
    if (!move_uploaded_file($tmpName, "uploads/" . $newName)) {
        echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
        exit();
    }

    $stmt = $conn->prepare("
    INSERT INTO project (foundation_id, project_name, project_desc, goal_amount, end_date, project_image, category, project_status)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
");
    $stmt->bind_param("ississs", $foundation_id, $name, $desc, $goal, $enddate, $newName, $category);

    if ($stmt->execute()) {
        echo "<script>alert('เสนอโครงการสำเร็จ (รอแอดมินอนุมัติ)'); window.location='project.php';</script>";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เสนอโครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/addproject.css">
</head>

<body>

    <?php include 'navbar.php'; ?>

    <div class="form-container">
        <div class="left-box">
            <h2>เสนอโครงการ</h2>
            <div class="upload-box">เลือกรูปด้านขวา</div>
        </div>

        <div class="right-box">
            <form method="POST" enctype="multipart/form-data">

                <div class="form-group">
                    <label>ประเภทโครงการ *</label>
                    <select name="category" required>
                        <option value="">-- เลือกประเภท --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>หัวข้อโครงการ *</label>
                    <input type="text" name="project_name" required>
                </div>

                <div class="form-group">
                    <label>รายละเอียดโครงการ *</label>
                    <textarea name="project_desc" rows="5" required></textarea>
                </div>

                <div class="form-group">
                    <label>เป้าหมาย (บาท) *</label>
                    <input type="number" name="goal_amount" min="1" required>
                </div>

                <div class="form-group">
                    <label>วันที่ปิดรับบริจาค *</label>
                    <input type="date" name="end_date" required>
                </div>

                <div class="form-group">
                    <label>อัปโหลดรูปภาพ *</label>
                    <input type="file" name="project_image" accept="image/*" required>
                </div>

                <button type="submit" name="submit" class="btn-submit">เสนอโครงการ</button>
            </form>
        </div>
    </div>

</body>

</html>