<?php
// foundation_post_needlist_result.php — มูลนิธิโพสต์ผลลัพธ์การระดมสิ่งของ (หลังครบเป้าหมาย)

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน post needlist result

include 'db.php';
require_once __DIR__ . '/includes/needlist_donate_window.php';
require_once __DIR__ . '/includes/utf8_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'foundation') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) {
    header('Location: profile.php');
    exit;
}

$fid = (int)$foundation['foundation_id'];
$agg = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS c, COALESCE(SUM(total_price), 0) AS g FROM foundation_needlist WHERE foundation_id = ?");
$agg->bind_param('i', $fid);
$agg->execute();
$rowAgg = $agg->get_result()->fetch_assoc();
$current = (float)($rowAgg['c'] ?? 0);
$goal = (float)($rowAgg['g'] ?? 0);

$readyStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'done'");
$readyStmt->bind_param('i', $fid);
$readyStmt->execute();
$readyRow = $readyStmt->get_result()->fetch_assoc();
$resultReady = ((int)($readyRow['cnt'] ?? 0)) > 0;

$latestOutcome = null;
$latestStmt = $conn->prepare("
    SELECT update_text, update_images, update_at
    FROM foundation_needlist
    WHERE foundation_id = ? AND approve_item = 'done'
      AND (
        COALESCE(TRIM(update_text), '') <> ''
        OR (update_images IS NOT NULL AND TRIM(update_images) <> '' AND TRIM(update_images) <> '[]')
      )
    ORDER BY update_at DESC, item_id DESC
    LIMIT 1
");
if ($latestStmt) {
    $latestStmt->bind_param('i', $fid);
    $latestStmt->execute();
    $latestOutcome = $latestStmt->get_result()->fetch_assoc() ?: null;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resultReady) {
    drawdream_csrf_require_valid('foundation_post_needlist_result.php?item_id=' . (int)$itemId);
    $description = trim((string)($_POST['outcome_text'] ?? ''));
    $newImageNames = [];
    $uploadDir = __DIR__ . '/uploads/evidence';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    if (isset($_FILES['outcome_images']['name']) && is_array($_FILES['outcome_images']['name'])) {
        $allowedMimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $maxBytes = 4 * 1024 * 1024;
        $names = $_FILES['outcome_images']['name'];
        $tmpNames = $_FILES['outcome_images']['tmp_name'];
        $errors = $_FILES['outcome_images']['error'];
        $countFiles = count($names);
        for ($i = 0; $i < $countFiles; $i++) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpPath = (string)($tmpNames[$i] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                continue;
            }
            $sz = @filesize($tmpPath);
            if ($sz === false || $sz > $maxBytes || $sz < 32) {
                $error = 'รูปต้องเป็น JPG, PNG, WebP หรือ GIF และขนาดไม่เกิน 4 MB ต่อไฟล์';
                break;
            }
            $ext = null;
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->file($tmpPath);
                $ext = $allowedMimeToExt[$mime] ?? null;
            }
            if ($ext === null) {
                $info = @getimagesize($tmpPath);
                $itype = (int)($info[2] ?? 0);
                $byType = [
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_GIF => 'gif',
                    IMAGETYPE_WEBP => 'webp',
                ];
                $ext = $byType[$itype] ?? null;
            }
            if ($ext === null) {
                $error = 'รูปต้องเป็น JPG, PNG, WebP หรือ GIF และขนาดไม่เกิน 4 MB ต่อไฟล์';
                break;
            }
            $newName = 'needlist_' . $fid . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $uploadDir . DIRECTORY_SEPARATOR . $newName)) {
                $error = 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่';
                break;
            }
            $newImageNames[] = $newName;
        }
    }

    if (!$error) {
        $existingImages = [];
        $rawImages = trim((string)($latestOutcome['update_images'] ?? ''));
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
        if ($description === '' && $finalImages === []) {
            $error = 'กรุณากรอกข้อความหรือแนบรูปอย่างน้อย 1 รายการ';
        } elseif (drawdream_utf8_strlen($description) > 8000) {
            $error = 'ข้อความยาวเกิน 8,000 ตัวอักษร';
        } else {
            $finalImagesJson = json_encode(array_values(array_unique($finalImages)), JSON_UNESCAPED_UNICODE);

            $up = $conn->prepare("
                UPDATE foundation_needlist
                SET update_text = ?, update_at = NOW(), update_images = ?
                WHERE foundation_id = ? AND approve_item = 'done'
            ");
            $up->bind_param('ssi', $description, $finalImagesJson, $fid);
            $up->execute();

            $success = 'บันทึกผลลัพธ์เรียบร้อยแล้ว';
            $latestOutcome = [
                'update_text' => $description,
                'update_images' => $finalImagesJson,
                'update_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    if ($error !== '') {
        foreach ($newImageNames as $uploaded) {
            $uploadedPath = $uploadDir . DIRECTORY_SEPARATOR . basename($uploaded);
            if (is_file($uploadedPath)) {
                @unlink($uploadedPath);
            }
        }
    }
}

$prefillDesc = (string)($latestOutcome['update_text'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    $prefillDesc = trim((string)($_POST['outcome_text'] ?? ''));
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

    <?php if (!$resultReady): ?>
        <div class="no-project">
            ยังไม่ถึงขั้นอัปเดตผลลัพธ์สิ่งของ<br>
            <small style="color:#bbb;">หน้านี้จะเปิดให้โพสต์ได้เมื่อแอดมินยืนยันจัดส่งสิ่งของแล้ว (สถานะ done)</small>
        </div>
    <?php else: ?>
        <div class="form-box">
            <div class="outcome-rainbow-strip" aria-hidden="true">
                <svg class="outcome-rainbow-strip__svg" viewBox="0 -26 320 124" overflow="hidden" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
                    <path d="M-40 90 C58 -13 262 -13 360 90" fill="none" stroke="#CC583F" stroke-width="11" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 -2 262 -2 360 90" fill="none" stroke="#F1CF54" stroke-width="10" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 9 262 9 360 90" fill="none" stroke="#6FA06C" stroke-width="9" stroke-linecap="butt" stroke-linejoin="round" opacity="0.98"/>
                    <path d="M-40 90 C58 20 262 20 360 90" fill="none" stroke="#6D86D6" stroke-width="8" stroke-linecap="butt" stroke-linejoin="round" opacity="0.96"/>
                    <path d="M-40 90 C58 31 262 31 360 90" fill="none" stroke="#3C5099" stroke-width="7" stroke-linecap="butt" stroke-linejoin="round" opacity="0.96"/>
                </svg>
            </div>
            <h2>โพสต์ผลลัพธ์ใหม่</h2>
            <div class="outcome-target-card">
                <div class="outcome-target-card__label">รายการสิ่งของที่ครบเป้าหมาย</div>
                <div class="outcome-target-card__name"><?= htmlspecialchars((string)($foundation['foundation_name'] ?? 'มูลนิธิของคุณ')) ?></div>
                <div class="outcome-target-card__meta">
                    ยอดรวมรายการสิ่งของ <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท
                </div>
            </div>
            <p style="color:#666;font-size:0.95rem;margin-bottom:1rem;">ข้อความและรูปจะแสดงในหน้า <a href="needlist_result.php?fid=<?= (int)$fid ?>">ผลลัพธ์ของมูลนิธิ (สาธารณะ)</a></p>
            <form method="POST" enctype="multipart/form-data">
                <?= drawdream_csrf_field() ?>
                <div class="form-group">
                    <label for="outcome_text">คำอธิบายผลลัพธ์ *</label>
                    <textarea id="outcome_text" name="outcome_text" placeholder="เช่น ภาพบรรยากาศการมอบสิ่งของ หรือสรุปผลการดำเนินการ"><?= htmlspecialchars($prefillDesc) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="outcome_images">รูปภาพ (ถ้ามี)</label>
                    <input type="file" id="outcome_images" name="outcome_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                </div>
                <button type="submit" class="btn-submit">โพสต์ผลลัพธ์</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
