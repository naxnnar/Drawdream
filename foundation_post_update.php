<?php
// foundation_post_update.php — โพสต์อัปเดตความคืบหน้าโครงการ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน post update


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
    require_once __DIR__ . '/includes/foundation_account_verified.php';
    drawdream_foundation_require_account_verified($conn);
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

/**
 * โครงการที่อนุญาตให้อัปเดตผลลัพธ์ (ให้ตรงกับเงื่อนไขใน project.php)
 * - completed/done/purchasing: อัปเดตได้ทันที
 * - approved: ต้องถึงเป้าและเลยวันปิดรับแล้ว
 */
function drawdream_project_allow_outcome_update(array $project): bool {
    $status = strtolower(trim((string)($project['project_status'] ?? '')));
    if (in_array($status, ['completed', 'done', 'purchasing'], true)) {
        return true;
    }

    if ($status !== 'approved') {
        return false;
    }

    $goal = (float)($project['goal_amount'] ?? 0);
    $raised = (float)($project['current_donate'] ?? 0);
    if ($goal <= 0 || $raised < $goal) {
        return false;
    }

    $endRaw = (string)($project['end_date'] ?? '');
    if ($endRaw === '') {
        return false;
    }

    try {
        $tz = new DateTimeZone('Asia/Bangkok');
        $endDate = new DateTimeImmutable(substr($endRaw, 0, 10), $tz);
        $today = new DateTimeImmutable('now', $tz);
        return $endDate->format('Y-m-d') <= $today->format('Y-m-d');
    } catch (Exception $e) {
        return false;
    }
}

// ✅ รับ project_id จาก URL (มาจากแจ้งเตือน)
$locked_project_id = (int)($_GET['project_id'] ?? 0);
$locked_project    = null;

if ($locked_project_id > 0) {
    if (!$readonly) {
        // เฉพาะ foundation: ตรวจสอบว่าเป็นของตัวเอง
        $lk = $conn->prepare("
            SELECT project_id, project_name, current_donate, goal_amount, project_status, end_date, update_text, update_images
            FROM foundation_project 
            WHERE project_id = ? AND foundation_name = ? AND project_status IN ('approved','completed','done','purchasing') AND deleted_at IS NULL
            LIMIT 1
        ");
        $lk->bind_param("is", $locked_project_id, $foundation['foundation_name']);
        $lk->execute();
        $locked_project = $lk->get_result()->fetch_assoc();
    } else {
        // readonly: ดึง project เฉย ๆ
        $lk = $conn->prepare("
            SELECT project_id, project_name, current_donate, goal_amount, project_status, end_date, update_text, update_images
            FROM foundation_project 
            WHERE project_id = ? AND project_status IN ('approved','completed','done','purchasing') AND deleted_at IS NULL
            LIMIT 1
        ");
        $lk->bind_param("i", $locked_project_id);
        $lk->execute();
        $locked_project = $lk->get_result()->fetch_assoc();
    }
    if ($locked_project && !drawdream_project_allow_outcome_update($locked_project)) {
        $locked_project = null;
    }
}


// ดึงโครงการทั้งหมด (กรณีไม่ได้มาจาก notification)
$projects = [];
if ($locked_project_id > 0 && $locked_project) {
    $projects[] = $locked_project;
} else {
    $stmt2 = $conn->prepare("
        SELECT project_id, project_name, current_donate, goal_amount, project_status, end_date, update_text, update_images
        FROM foundation_project 
        WHERE foundation_id = ? AND project_status IN ('approved','completed','done','purchasing') AND deleted_at IS NULL
        ORDER BY project_id DESC
    ");
    $stmt2->bind_param("i", $fid);
    $stmt2->execute();
    $allProjects = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($allProjects as $projectRow) {
        if (drawdream_project_allow_outcome_update($projectRow)) {
            $projects[] = $projectRow;
        }
    }
}

$success = "";
$error   = "";

// ======== POST: บันทึก update ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readonly) {
    $project_id  = (int)($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $newImageNames = [];

    // ตรวจสอบว่าโครงการนี้เป็นของมูลนิธินี้จริง (ใช้ foundation_name แทน foundation_id)
    $chk = $conn->prepare("
        SELECT project_id, project_name, goal_amount, current_donate, project_status, end_date, update_images
        FROM foundation_project
        WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL
    ");
    $chk->bind_param("is", $project_id, $foundation['foundation_name']);
    $chk->execute();
    $proj_row = $chk->get_result()->fetch_assoc();

    if (!$proj_row) {
        $error = "ไม่พบโครงการนี้";
    } elseif (!drawdream_project_allow_outcome_update($proj_row)) {
        $error = "โครงการนี้ยังไม่พร้อมอัปเดตผลลัพธ์";
    } elseif ($description === '') {
        $error = "กรุณากรอกคำอธิบาย";
    } else {
        // อัปโหลดรูป (รองรับหลายภาพ)
        if (isset($_FILES['update_images']['name']) && is_array($_FILES['update_images']['name'])) {
            $uploadDir = "uploads/evidence/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $allowed = ['jpg','jpeg','png','gif','webp'];
            $names = $_FILES['update_images']['name'];
            $tmpNames = $_FILES['update_images']['tmp_name'];
            $errors = $_FILES['update_images']['error'];
            $countFiles = count($names);
            for ($i = 0; $i < $countFiles; $i++) {
                if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower(pathinfo((string)$names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = "อนุญาตเฉพาะไฟล์รูปเท่านั้น";
                    break;
                }
                $newName = "project_" . $project_id . "_" . time() . "_" . $i . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                if (move_uploaded_file((string)$tmpNames[$i], $uploadDir . $newName)) {
                    $newImageNames[] = $newName;
                }
            }
        }

        if (!$error) {
            $existingImages = [];
            $rawImages = trim((string)($proj_row['update_images'] ?? ''));
            if ($rawImages !== '') {
                $arr = json_decode($rawImages, true);
                if (is_array($arr)) {
                    foreach ($arr as $img) {
                        $bn = basename((string)$img);
                        if ($bn !== '') {
                            $existingImages[] = $bn;
                        }
                    }
                }
            }

            $finalImages = $newImageNames !== [] ? $newImageNames : $existingImages;
            $finalImagesJson = json_encode(array_values(array_unique($finalImages)), JSON_UNESCAPED_UNICODE);

            $stmt3 = $conn->prepare("
                UPDATE foundation_project
                SET update_text = ?, update_at = NOW(), update_images = ?
                WHERE project_id = ? AND foundation_name = ?
                  AND LOWER(TRIM(COALESCE(project_status,''))) IN ('approved','completed','done','purchasing')
                  AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt3->bind_param("ssis", $description, $finalImagesJson, $project_id, $foundation['foundation_name']);
            $stmt3->execute();

            // ===== แจ้งเตือน donor ที่บริจาคให้โครงการนี้โดยตรง =====
            $donors_q = $conn->prepare("
                SELECT DISTINCT dn.user_id
                FROM donation d
                JOIN donate_category dc ON d.category_id = dc.category_id
                JOIN donor dn ON dn.user_id = d.donor_id
                WHERE TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
                AND d.payment_status = 'completed'
                AND d.target_id = ?
                AND dn.tax_id IS NOT NULL AND TRIM(dn.tax_id) != ''
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
                $notif_snip = mb_strlen($description) > 160 ? mb_substr($description, 0, 160) . '…' : $description;
                $notif_msg   = $foundation['foundation_name'] . " อัปเดตผลลัพธ์: " . $notif_snip;
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


?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตโครงการ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
</head>
<body class="foundation-post-update-page">
<?php include 'navbar.php'; ?>

<div class="wrap">
    <a href="profile.php" class="back-link" aria-label="ย้อนกลับ" title="ย้อนกลับ" onclick="if (window.history.length > 1) { event.preventDefault(); history.back(); }">←</a>
    <div class="page-title">📢 อัปเดตผลลัพธ์โครงการ</div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($projects)): ?>
        <div class="no-project">
            ยังไม่มีโครงการที่พร้อมอัปเดตผลลัพธ์<br>
            <small style="color:#bbb;">โครงการจะปรากฏเมื่อสถานะเป็นเสร็จสิ้น หรือระดมทุนครบและเลยวันปิดรับแล้ว</small>
        </div>
    <?php else: ?>
        <?php
        $prefillDesc = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $prefillDesc = (string)($_POST['description'] ?? '');
        } elseif ($locked_project) {
            $prefillDesc = (string)($locked_project['update_text'] ?? '');
        } else {
            $selPid = (int)($_POST['project_id'] ?? 0);
            foreach ($projects as $p) {
                if ($selPid > 0 && (int)$p['project_id'] === $selPid) {
                    $prefillDesc = (string)($p['update_text'] ?? '');
                    break;
                }
            }
        }
        ?>
        <?php if (!$readonly): ?>
        <div class="form-box">
            <div class="outcome-rainbow-strip" aria-hidden="true">
                <svg class="outcome-rainbow-strip__svg" viewBox="0 -26 320 124" overflow="hidden" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
                    <!-- cubic สมมาตรรอบ x=160; ปลายนอก viewBox แกน X ถูกตัด; viewBox รวม y ติดลบเพื่อไม่ตัดยอดโค้ง -->
                    <path d="M-40 90 C58 -13 262 -13 360 90" fill="none" stroke="#CC583F" stroke-width="11" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 -2 262 -2 360 90" fill="none" stroke="#F1CF54" stroke-width="10" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 9 262 9 360 90" fill="none" stroke="#6FA06C" stroke-width="9" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 20 262 20 360 90" fill="none" stroke="#6D86D6" stroke-width="8" stroke-linecap="butt" stroke-linejoin="round" opacity="0.96"/>
                    <path d="M-40 90 C58 31 262 31 360 90" fill="none" stroke="#3C5099" stroke-width="7" stroke-linecap="butt" stroke-linejoin="round" opacity="0.96"/>
                </svg>
            </div>
            <h2>โพสต์ผลลัพธ์ใหม่</h2>
            <?php if ($locked_project): ?>
                <div class="outcome-target-card">
                    <div class="outcome-target-card__label">โครงการที่ต้องอัปเดต</div>
                    <div class="outcome-target-card__name"><?= htmlspecialchars($locked_project['project_name']) ?></div>
                    <div class="outcome-target-card__meta">
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
                    <label>คำอธิบายผลลัพธ์ *</label>
                    <textarea name="description" placeholder="อธิบายสิ่งที่ดำเนินการไปแล้ว เงินถูกนำไปใช้ยังไง ฯลฯ" required><?= htmlspecialchars($prefillDesc) ?></textarea>
                </div>
                <div class="form-group">
                    <label>รูปภาพ (ถ้ามี)</label>
                    <input type="file" name="update_images[]" accept="image/*" multiple>
                </div>
                <button type="submit" class="btn-submit">โพสต์ผลลัพธ์</button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>