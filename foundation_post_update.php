<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'foundation') {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ดึง foundation_id
$stmt = $conn->prepare("SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) { header("Location: profile.php"); exit(); }
$fid = (int)$foundation['foundation_id'];

// ✅ รับ project_id จาก URL (มาจากแจ้งเตือน)
$locked_project_id = (int)($_GET['project_id'] ?? 0);
$locked_project    = null;

if ($locked_project_id > 0) {
    // ดึงเฉพาะโครงการนั้น และตรวจสอบว่าเป็นของมูลนิธินี้จริง
    $lk = $conn->prepare("
        SELECT project_id, project_name, current_donate, goal_amount, project_status
        FROM project 
        WHERE project_id = ? AND foundation_id = ? AND project_status IN ('completed','done')
        LIMIT 1
    ");
    $lk->bind_param("ii", $locked_project_id, $fid);
    $lk->execute();
    $locked_project = $lk->get_result()->fetch_assoc();
}

// ดึงโครงการทั้งหมด (กรณีไม่ได้มาจาก notification)
$stmt2 = $conn->prepare("
    SELECT project_id, project_name, current_donate, goal_amount, project_status
    FROM project 
    WHERE foundation_id = ? AND project_status IN ('completed', 'done')
    ORDER BY project_id DESC
");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$projects = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

$success = "";
$error   = "";

// ======== POST: บันทึก update ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id  = (int)($_POST['project_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $image_name  = '';

    // ตรวจสอบว่าโครงการนี้เป็นของมูลนิธินี้จริง
    $chk = $conn->prepare("SELECT project_id, project_name FROM project WHERE project_id = ? AND foundation_id = ?");
    $chk->bind_param("ii", $project_id, $fid);
    $chk->execute();
    $proj_row = $chk->get_result()->fetch_assoc();

    if (!$proj_row) {
        $error = "ไม่พบโครงการนี้";
    } elseif (empty($title)) {
        $error = "กรุณากรอกหัวข้อ";
    } elseif (empty($description)) {
        $error = "กรุณากรอกคำอธิบาย";
    } else {
        // อัปโหลดรูป
        if (isset($_FILES['update_image']) && $_FILES['update_image']['error'] === 0) {
            $uploadDir = "uploads/updates/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext     = strtolower(pathinfo($_FILES['update_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $newName = time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['update_image']['tmp_name'], $uploadDir . $newName)) {
                    $image_name = $newName;
                }
            } else {
                $error = "อนุญาตเฉพาะไฟล์รูปเท่านั้น";
            }
        }

        if (!$error) {
            // บันทึก project_updates
            $stmt3 = $conn->prepare("
                INSERT INTO project_updates (project_id, title, description, update_image)
                VALUES (?, ?, ?, ?)
            ");
            $stmt3->bind_param("isss", $project_id, $title, $description, $image_name);
            $stmt3->execute();
            $update_id = $conn->insert_id;

            // ===== แจ้งเตือน donor ที่บริจาคให้โครงการนี้โดยตรง =====
            $donors_q = $conn->prepare("
                SELECT DISTINCT dn.user_id
                FROM donation d
                JOIN donate_category dc ON d.category_id = dc.category_id
                JOIN payment_transaction pt ON pt.donate_id = d.donate_id
                JOIN donor dn ON pt.tax_id = dn.tax_id
                WHERE dc.project_donate IS NOT NULL
                AND d.payment_status = 'completed'
            ");
            $donors_q->execute();
            $donor_users = $donors_q->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($donor_users as $du) {
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, 'project_update', ?, ?, ?)
                ");
                $notif_title = "อัปเดตโครงการ: " . $proj_row['project_name'];
                $notif_msg   = $foundation['foundation_name'] . " โพสต์ความคืบหน้า: " . $title;
                $notif_link  = "project.php";
                $notif_stmt->bind_param("isss", $du['user_id'], $notif_title, $notif_msg, $notif_link);
                $notif_stmt->execute();
            }

            // แจ้งเตือนมูลนิธิตัวเองด้วย (ยืนยันการโพสต์)
            $self_notif = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'post_success', ?, ?, ?)
            ");
            $self_title = "โพสต์ความคืบหน้าสำเร็จ";
            $self_msg   = "คุณได้โพสต์ความคืบหน้าโครงการ \"" . $proj_row['project_name'] . "\" เรียบร้อยแล้ว";
            $self_link  = "profile.php";
            $self_notif->bind_param("isss", $user_id, $self_title, $self_msg, $self_link);
            $self_notif->execute();

            $success = "โพสต์ความคืบหน้าสำเร็จแล้วค่ะ!";
        }
    }
}

// ดึง updates ที่เคยโพสต์ไปแล้ว
$prev_updates = $conn->prepare("
    SELECT pu.*, p.project_name 
    FROM project_updates pu
    JOIN project p ON p.project_id = pu.project_id
    WHERE p.foundation_id = ?
    ORDER BY pu.update_id DESC
    LIMIT 20
");
$prev_updates->bind_param("i", $fid);
$prev_updates->execute();
$updates_list = $prev_updates->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตโครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation_post_update.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="wrap">
    <a href="profile.php" class="back-link">← กลับหน้าโปรไฟล์</a>
    <div class="page-title">📢 อัปเดตความคืบหน้าโครงการ</div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($projects)): ?>
        <div class="no-project">
            ยังไม่มีโครงการที่สำเร็จและพร้อมอัปเดต<br>
            <small style="color:#bbb;">โครงการจะปรากฏที่นี่เมื่อได้รับเงินครบหรือหมดระยะเวลาระดมทุน</small>
        </div>
    <?php else: ?>
        <div class="form-box">
            <h2>โพสต์ความคืบหน้าใหม่</h2>

            <?php if ($locked_project): ?>
                <!-- มาจากการแจ้งเตือน: แสดงชื่อโครงการ lock ไว้เลย -->
                <div style="background:#f0f4ff; border-radius:10px; padding:14px 18px; margin-bottom:20px; border-left:4px solid #4A5BA8;">
                    <div style="font-size:13px; color:#4A5BA8; font-weight:600; margin-bottom:4px;">โครงการที่ต้องอัปเดต</div>
                    <div style="font-size:16px; font-weight:700; color:#222;"><?= htmlspecialchars($locked_project['project_name']) ?></div>
                    <div style="font-size:13px; color:#666; margin-top:4px;">
                        ได้รับเงิน <?= number_format((float)$locked_project['current_donate'], 0) ?> / <?= number_format((float)$locked_project['goal_amount'], 0) ?> บาท
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?php if ($locked_project): ?>
                    <!-- hidden field ส่ง project_id ไปเลย ไม่ต้องเลือก -->
                    <input type="hidden" name="project_id" value="<?= $locked_project['project_id'] ?>">
                <?php else: ?>
                <div class="form-group">
                    <label>เลือกโครงการ *</label>
                    <select name="project_id" required>
                        <option value="">-- เลือกโครงการ --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" <?= (isset($_POST['project_id']) && $_POST['project_id'] == $p['project_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?>
                                (<?= number_format((float)$p['current_donate'], 0) ?> / <?= number_format((float)$p['goal_amount'], 0) ?> บาท)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>หัวข้อ *</label>
                    <input type="text" name="title" placeholder="เช่น: ได้รับเงินและเริ่มดำเนินการแล้ว" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>คำอธิบายความคืบหน้า *</label>
                    <textarea name="description" placeholder="อธิบายสิ่งที่ดำเนินการไปแล้ว เงินถูกนำไปใช้ยังไง ฯลฯ" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>รูปภาพ (ถ้ามี)</label>
                    <input type="file" name="update_image" accept="image/*">
                </div>
                <button type="submit" class="btn-submit">โพสต์ความคืบหน้า</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- updates ที่เคยโพสต์แล้ว -->
    <?php if (!empty($updates_list)): ?>
        <div class="updates-title">ความคืบหน้าที่โพสต์ไปแล้ว</div>
        <?php foreach ($updates_list as $u): ?>
            <div class="update-card">
                <div class="update-proj"><?= htmlspecialchars($u['project_name']) ?></div>
                <div class="update-title"><?= htmlspecialchars($u['title']) ?></div>
                <?php if (!empty($u['update_image'])): ?>
                    <img src="uploads/updates/<?= htmlspecialchars($u['update_image']) ?>" class="update-img" alt="">
                <?php endif; ?>
                <div class="update-desc"><?= nl2br(htmlspecialchars($u['description'])) ?></div>
                <div class="update-date">โพสต์เมื่อ: <?= !empty($u['created_at']) ? date('d/m/Y H:i', strtotime($u['created_at'])) : '-' ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>