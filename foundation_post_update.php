<?php
// foundation_post_update.php — โพสต์อัปเดตความคืบหน้าโครงการ


if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
require_once __DIR__ . '/includes/admin_audit_migrate.php';

// กำหนดค่า default ให้ $readonly ก่อนใช้งาน
$readonly = isset($_GET['readonly']) && $_GET['readonly'] == '1';

// ถ้าเป็น readonly (ผู้บริจาคดูผลลัพธ์) ไม่ต้องเช็ค session/role
if (!$readonly) {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'foundation') {
        header("Location: login.php");
        exit();
    }
}


$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;


// ดึง foundation_id เฉพาะถ้าไม่ใช่ readonly
if (!$readonly) {
    $stmt = $conn->prepare("SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $foundation = $stmt->get_result()->fetch_assoc();
    if (!$foundation) { header("Location: profile.php"); exit(); }
    $fid = (int)$foundation['foundation_id'];
} else {
    // readonly: ดึง foundation_name จาก project
    $foundation = null;
    $fid = 0;
}

// ✅ รับ project_id จาก URL (มาจากแจ้งเตือน)
$locked_project_id = (int)($_GET['project_id'] ?? 0);
$locked_project    = null;

if ($locked_project_id > 0) {
    if (!$readonly) {
        // เฉพาะ foundation: ตรวจสอบว่าเป็นของตัวเอง
        $lk = $conn->prepare("
            SELECT project_id, project_name, current_donate, goal_amount, project_status
            FROM foundation_project 
            WHERE project_id = ? AND foundation_name = ? AND project_status IN ('completed','done') AND deleted_at IS NULL
            LIMIT 1
        ");
        $lk->bind_param("is", $locked_project_id, $foundation['foundation_name']);
        $lk->execute();
        $locked_project = $lk->get_result()->fetch_assoc();
    } else {
        // readonly: ดึง project เฉย ๆ
        $lk = $conn->prepare("
            SELECT project_id, project_name, current_donate, goal_amount, project_status
            FROM foundation_project 
            WHERE project_id = ? AND project_status IN ('completed','done') AND deleted_at IS NULL
            LIMIT 1
        ");
        $lk->bind_param("i", $locked_project_id);
        $lk->execute();
        $locked_project = $lk->get_result()->fetch_assoc();
    }
}


// ดึงโครงการทั้งหมด (กรณีไม่ได้มาจาก notification)
$projects = [];
if ($locked_project_id > 0 && $locked_project) {
    $projects[] = $locked_project;
} else {
    $stmt2 = $conn->prepare("
        SELECT project_id, project_name, current_donate, goal_amount, project_status
        FROM foundation_project 
        WHERE foundation_id = ? AND project_status IN ('completed', 'done') AND deleted_at IS NULL
        ORDER BY project_id DESC
    ");
    $stmt2->bind_param("i", $fid);
    $stmt2->execute();
    $projects = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
}

$success = "";
$error   = "";

// ======== POST: บันทึก update ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readonly) {
    $project_id  = (int)($_POST['project_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $image_name  = '';

    // ตรวจสอบว่าโครงการนี้เป็นของมูลนิธินี้จริง (ใช้ foundation_name แทน foundation_id)
    $chk = $conn->prepare("SELECT project_id, project_name FROM foundation_project WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL");
    $chk->bind_param("is", $project_id, $foundation['foundation_name']);
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

            // สรุปให้ผู้บริจาคเห็นในหน้าชำระเงิน/รายละเอียดโครงการ (คอลัมน์ update_info — ไม่ใช้ในฟอร์มเสนอโครงการ)
            $donorSummary = $title . "\n\n" . $description;
            $stmtUi = $conn->prepare(
                "UPDATE foundation_project SET update_info = ? WHERE project_id = ? AND foundation_name = ? AND LOWER(TRIM(COALESCE(project_status,''))) IN ('completed','done') AND deleted_at IS NULL"
            );
            if ($stmtUi) {
                $stmtUi->bind_param("sis", $donorSummary, $project_id, $foundation['foundation_name']);
                $stmtUi->execute();
            }

            // ===== แจ้งเตือน donor ที่บริจาคให้โครงการนี้โดยตรง =====
            $donors_q = $conn->prepare("
                SELECT DISTINCT dn.user_id
                FROM donation d
                JOIN donate_category dc ON d.category_id = dc.category_id
                JOIN payment_transaction pt ON pt.donate_id = d.donate_id
                JOIN donor dn ON pt.tax_id = dn.tax_id
                WHERE TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
                AND d.payment_status = 'completed'
                AND d.target_id = ?
            ");
            $donors_q->bind_param('i', $project_id);
            $donors_q->execute();
            $donor_users = $donors_q->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($donor_users as $du) {
                $notif_type_th = drawdream_normalize_notif_type_to_th('project_update');
                $notif_stmt = $conn->prepare('
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $notif_title = "อัปเดตโครงการ: " . $proj_row['project_name'];
                $notif_msg   = $foundation['foundation_name'] . " อัปเดตผลลัพธ์: " . $title;
                $notif_link  = "project.php";
                $notif_stmt->bind_param("issss", $du['user_id'], $notif_type_th, $notif_title, $notif_msg, $notif_link);
                $notif_stmt->execute();
            }

            // แจ้งเตือนมูลนิธิตัวเองด้วย (ยืนยันการโพสต์)
            $self_type_th = drawdream_normalize_notif_type_to_th('post_success');
            $self_notif = $conn->prepare('
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, ?, ?, ?, ?)
            ');
            $self_title = "โพสต์ผลลัพธ์สำเร็จ";
            $self_msg   = "คุณได้โพสต์ผลลัพธ์โครงการ \"" . $proj_row['project_name'] . "\" เรียบร้อยแล้ว";
            $self_link  = "profile.php";
            $self_notif->bind_param("issss", $user_id, $self_type_th, $self_title, $self_msg, $self_link);
            $self_notif->execute();

            $success = "โพสต์ผลลัพธ์สำเร็จแล้วค่ะ!";
        }
    }
}


// ดึง updates เฉพาะของโครงการนี้ (ถ้าเลือก project_id)
$updates_list = [];
if ($locked_project_id > 0 && $locked_project) {
    $prev_updates = $conn->prepare("
        SELECT pu.*, p.project_name 
        FROM project_updates pu
        JOIN foundation_project p ON p.project_id = pu.project_id AND p.deleted_at IS NULL
        WHERE pu.project_id = ?
        ORDER BY pu.update_id DESC
        LIMIT 20
    ");
    $prev_updates->bind_param("i", $locked_project_id);
    $prev_updates->execute();
    $updates_list = $prev_updates->get_result()->fetch_all(MYSQLI_ASSOC);
} else if (!$readonly) {
    $prev_updates = $conn->prepare("
        SELECT pu.*, p.project_name 
        FROM project_updates pu
        JOIN foundation_project p ON p.project_id = pu.project_id AND p.deleted_at IS NULL
        WHERE p.foundation_id = ?
        ORDER BY pu.update_id DESC
        LIMIT 20
    ");
    $prev_updates->bind_param("i", $fid);
    $prev_updates->execute();
    $updates_list = $prev_updates->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตโครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
</head>
<body class="foundation-post-update-page">
<?php include 'navbar.php'; ?>

<div class="wrap">
    <a href="profile.php" class="back-link">← กลับหน้าโปรไฟล์</a>
    <div class="page-title">📢 อัปเดตผลลัพธ์โครงการ</div>

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
        <?php if (!$readonly): ?>
        <div class="form-box">
            <h2>โพสต์ผลลัพธ์ใหม่</h2>
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
                    <label>คำอธิบายผลลัพธ์ *</label>
                    <textarea name="description" placeholder="อธิบายสิ่งที่ดำเนินการไปแล้ว เงินถูกนำไปใช้ยังไง ฯลฯ" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>รูปภาพ (ถ้ามี)</label>
                    <input type="file" name="update_image" accept="image/*">
                </div>
                <button type="submit" class="btn-submit">โพสต์ผลลัพธ์</button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- updates ที่เคยโพสต์แล้ว -->
    <?php if (!empty($updates_list)): ?>
        <div class="updates-title">ผลลัพธ์ที่โพสต์ไปแล้ว</div>
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