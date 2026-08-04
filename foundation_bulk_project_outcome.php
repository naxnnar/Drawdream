<?php
// foundation_bulk_project_outcome.php — อัปเดตผลลัพธ์โครงการหลายโครงการพร้อมกัน (หลังชำระค่าบริการแล้ว)

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/foundation_bulk_tasks.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) {
    header('Location: profile.php');
    exit;
}
$fid = (int)$foundation['foundation_id'];
$foundationName = trim((string)($foundation['foundation_name'] ?? ''));

$dueProjects = foundation_bulk_projects_outcome_due($conn, $fid, $foundationName);
$uploadDir = __DIR__ . '/uploads/evidence';
$error = '';
$success = '';
$savedCount = 0;

function drawdream_project_allow_outcome_update(array $project): bool
{
    $status = strtolower(trim((string)($project['project_status'] ?? '')));

    return in_array($status, ['purchasing', 'done'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dueProjects !== []) {
    drawdream_csrf_require_valid('foundation_bulk_project_outcome.php');
    $descriptions = is_array($_POST['description'] ?? null) ? $_POST['description'] : [];
    $errors = [];

    foreach ($dueProjects as $proj) {
        $projectId = (int)($proj['project_id'] ?? 0);
        if ($projectId <= 0 || !drawdream_project_allow_outcome_update($proj)) {
            continue;
        }
        $description = trim((string)($descriptions[$projectId] ?? ''));
        $fileKey = 'update_images_' . $projectId;
        $hasFiles = isset($_FILES[$fileKey]['name'])
            && is_array($_FILES[$fileKey]['name'])
            && array_filter($_FILES[$fileKey]['name'], static fn ($n) => trim((string)$n) !== '') !== [];

        if ($description === '' && !$hasFiles) {
            $errors[] = 'กรุณากรอกคำอธิบายสำหรับ ' . trim((string)($proj['project_name'] ?? 'โครงการ'));
            continue;
        }
        if ($description === '') {
            $errors[] = 'กรุณากรอกคำอธิบายสำหรับ ' . trim((string)($proj['project_name'] ?? 'โครงการ'));
            continue;
        }

        $newImageNames = [];
        if ($hasFiles) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $names = $_FILES[$fileKey]['name'];
            $tmpNames = $_FILES[$fileKey]['tmp_name'];
            $fileErrs = $_FILES[$fileKey]['error'];
            for ($i = 0; $i < count($names); $i++) {
                if (($fileErrs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower(pathinfo((string)$names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $errors[] = 'รูปของ ' . trim((string)($proj['project_name'] ?? 'โครงการ')) . ' ต้องเป็นไฟล์รูปเท่านั้น';
                    continue 2;
                }
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newName = 'project_' . $projectId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file((string)$tmpNames[$i], $uploadDir . DIRECTORY_SEPARATOR . $newName)) {
                    $newImageNames[] = $newName;
                }
            }
        }

        $existingImages = [];
        $rawImages = trim((string)($proj['update_images'] ?? ''));
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

        $stmt3 = $conn->prepare(
            "UPDATE foundation_project
             SET update_text = ?, update_at = NOW(), update_images = ?
             WHERE project_id = ? AND foundation_name = ?
               AND LOWER(TRIM(COALESCE(project_status,''))) IN ('purchasing','done')
             LIMIT 1"
        );
        $stmt3->bind_param('ssis', $description, $finalImagesJson, $projectId, $foundationName);
        $stmt3->execute();
        if ($stmt3->affected_rows <= 0) {
            $errors[] = 'บันทึก ' . trim((string)($proj['project_name'] ?? 'โครงการ')) . ' ไม่สำเร็จ';
            continue;
        }

        $savedCount++;
        $donorsQ = $conn->prepare(
            "SELECT DISTINCT dn.user_id
             FROM donation d
             JOIN donate_category dc ON d.category_id = dc.category_id
             JOIN donor dn ON dn.user_id = d.donor_id
             WHERE TRIM(COALESCE(dc.project_donate, '')) NOT IN ('', '-')
               AND d.payment_status = 'completed'
               AND d.target_id = ?
               AND dn.tax_id IS NOT NULL AND TRIM(dn.tax_id) != ''"
        );
        $donorsQ->bind_param('i', $projectId);
        $donorsQ->execute();
        $donorUsers = $donorsQ->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($donorUsers as $du) {
            $notifTitle = 'อัปเดตโครงการ: ' . (string)($proj['project_name'] ?? '');
            $notifSnip = mb_strlen($description) > 160 ? mb_substr($description, 0, 160) . '…' : $description;
            $notifMsg = $foundationName . ' อัปเดตผลลัพธ์: ' . $notifSnip;
            drawdream_send_notification($conn, (int)$du['user_id'], '', $notifTitle, $notifMsg, 'project.php');
        }
    }

    if ($errors !== []) {
        $error = implode(' · ', array_unique($errors));
    } elseif ($savedCount > 0) {
        $success = 'โพสต์ผลลัพธ์โครงการเรียบร้อยแล้ว ' . $savedCount . ' โครงการ';
        $dueProjects = foundation_bulk_projects_outcome_due($conn, $fid, $foundationName);
    } else {
        $error = 'ไม่มีรายการที่บันทึกได้';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>อัปเดตโครงการ (หลายรายการ) | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
    <link rel="stylesheet" href="css/foundation_manage.css?v=1">
</head>
<body class="foundation-post-update-page foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="bulk-wrap">
    <a href="foundation_dashboard.php" class="bulk-back" data-foundation-back>← กลับ</a>
    <div class="bulk-head">
        <h1>📢 อัปเดตผลลัพธ์โครงการ (<?= count($dueProjects) ?> โครงการ)</h1>
        <p>ชำระค่าบริการต้องทำทีละโครงการ — แต่หลังชำระแล้วสามารถโพสต์ผลหลายโครงการพร้อมกันได้ที่หน้านี้</p>
    </div>
    <div class="bulk-note">ชำระค่าบริการระบบ: ไปที่ <a href="foundation_projects_directory.php">รายการโครงการ</a> แล้วเปิดแต่ละโครงการเพื่อชำระทีละรายการ</div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($dueProjects === []): ?>
        <div class="bulk-card"><p class="b--muted" style="margin:0;">ไม่มีโครงการที่ต้องอัปเดตผลลัพธ์ในขณะนี้</p></div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <?= drawdream_csrf_field() ?>
            <?php foreach ($dueProjects as $p):
                $pid = (int)($p['project_id'] ?? 0);
                $pname = trim((string)($p['project_name'] ?? ''));
                $prefill = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '')
                    ? trim((string)(($_POST['description'][$pid] ?? '')))
                    : trim((string)($p['update_text'] ?? ''));
                ?>
            <div class="bulk-card">
                <h2 style="margin:0 0 6px;font-size:1.05rem;"><?= htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="bulk-card__meta">
                    ได้รับ <?= number_format((float)($p['current_donate'] ?? 0), 0) ?> / <?= number_format((float)($p['goal_amount'] ?? 0), 0) ?> บาท
                </div>
                <label for="desc_<?= $pid ?>">คำอธิบายผลลัพธ์ *</label>
                <textarea id="desc_<?= $pid ?>" name="description[<?= $pid ?>]" placeholder="อธิบายสิ่งที่ดำเนินการไปแล้ว…" required><?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?></textarea>
                <label for="img_<?= $pid ?>" style="display:block;margin-top:10px;font-size:.88rem;color:#475569;">รูปภาพ (ถ้ามี)</label>
                <input type="file" id="img_<?= $pid ?>" name="update_images_<?= $pid ?>[]" accept="image/*" multiple>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="bulk-submit">โพสต์ผลลัพธ์ทั้งหมด (<?= count($dueProjects) ?> โครงการ)</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
