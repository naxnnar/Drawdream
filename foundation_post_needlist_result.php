<?php
// foundation_post_needlist_result.php — มูลนิธิโพสต์ผลลัพธ์การระดมสิ่งของ (หลังครบเป้าหมาย)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT foundation_id, foundation_name, needlist_result_text, needlist_result_images FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) {
    header('Location: profile.php');
    exit;
}

$fid = (int)$foundation['foundation_id'];
$needOpen = drawdream_needlist_sql_open_for_donation();
$agg = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS c, COALESCE(SUM(total_price), 0) AS g FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
$agg->bind_param('i', $fid);
$agg->execute();
$rowAgg = $agg->get_result()->fetch_assoc();
$current = (float)($rowAgg['c'] ?? 0);
$goal = (float)($rowAgg['g'] ?? 0);
$goalMet = $goal > 0 && $current >= $goal;

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $goalMet) {
    $description = trim((string)($_POST['description'] ?? ''));
    $newImageNames = [];

    if ($description === '') {
        $error = 'กรุณากรอกคำอธิบาย';
    } else {
        if (isset($_FILES['result_images']['name']) && is_array($_FILES['result_images']['name'])) {
            $uploadDir = 'uploads/evidence/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $names = $_FILES['result_images']['name'];
            $tmpNames = $_FILES['result_images']['tmp_name'];
            $errors = $_FILES['result_images']['error'];
            $countFiles = count($names);
            for ($i = 0; $i < $countFiles; $i++) {
                if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower(pathinfo((string)$names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = 'อนุญาตเฉพาะไฟล์รูปเท่านั้น';
                    break;
                }
                $newName = 'needlist_' . $fid . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file((string)$tmpNames[$i], $uploadDir . $newName)) {
                    $newImageNames[] = $newName;
                }
            }
        }

        if (!$error) {
            $existingImages = [];
            $rawImages = trim((string)($foundation['needlist_result_images'] ?? ''));
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

            $up = $conn->prepare('UPDATE foundation_profile SET needlist_result_text = ?, needlist_result_at = NOW(), needlist_result_images = ? WHERE foundation_id = ? AND user_id = ? LIMIT 1');
            $up->bind_param('ssii', $description, $finalImagesJson, $fid, $user_id);
            $up->execute();

            $success = 'บันทึกผลลัพธ์แล้ว';
            $foundation['needlist_result_text'] = $description;
            $foundation['needlist_result_images'] = $finalImagesJson;
        }
    }
}

$prefillDesc = (string)($foundation['needlist_result_text'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    $prefillDesc = trim((string)($_POST['description'] ?? ''));
}

?><!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลลัพธ์สิ่งของมูลนิธิ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
</head>
<body class="foundation-post-update-page">
<?php include 'navbar.php'; ?>

<div class="wrap">
    <a href="foundation.php" class="back-link" aria-label="ย้อนกลับ" title="ย้อนกลับ">←</a>
    <div class="page-title">ผลลัพธ์การระดมสิ่งของมูลนิธิ</div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$goalMet): ?>
        <div class="no-project">
            ยังไม่ครบเป้าหมายการระดมทุนสิ่งของตามรายการที่เปิดรับบริจาคอยู่<br>
            <small style="color:#bbb;">เมื่อยอดรวมครบเป้าหมายแล้ว คุณจะโพสต์ผลลัพธ์ได้ที่หน้านี้</small>
        </div>
    <?php else: ?>
        <div class="form-box">
            <div class="outcome-target-card">
                <div class="outcome-target-card__label">ยอดระดมทุนสิ่งของ</div>
                <div class="outcome-target-card__meta">
                    ได้รับเงิน <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท
                </div>
            </div>
            <p style="color:#666;font-size:0.95rem;margin-bottom:1rem;">ข้อความและรูปจะแสดงในหน้า <a href="needlist_result.php?fid=<?= (int)$fid ?>">ผลลัพธ์ของมูลนิธิ (สาธารณะ)</a></p>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>คำอธิบายผลลัพธ์ *</label>
                    <textarea name="description" placeholder="เช่น ภาพบรรยากาศการมอบสิ่งของ หรือสรุปผลการดำเนินการ" required><?= htmlspecialchars($prefillDesc) ?></textarea>
                </div>
                <div class="form-group">
                    <label>รูปภาพ (ถ้ามี)</label>
                    <input type="file" name="result_images[]" accept="image/*" multiple>
                </div>
                <button type="submit" class="btn-submit">บันทึกผลลัพธ์</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
