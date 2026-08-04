<?php
// foundation_bulk_needlist_outcome.php — โพสต์ผลการจัดส่งสิ่งของหลายรายการพร้อมกัน

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
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
$uploadDir = __DIR__ . '/uploads/evidence';

$dueItems = foundation_bulk_needlist_outcome_due($conn, $fid);
$error = '';
$success = '';
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dueItems !== []) {
    drawdream_csrf_require_valid('foundation_bulk_needlist_outcome.php');
    $texts = is_array($_POST['outcome_text'] ?? null) ? $_POST['outcome_text'] : [];
    $errors = [];

    foreach ($dueItems as $item) {
        $itemId = (int)($item['item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $description = trim((string)($texts[$itemId] ?? ''));
        $fileKey = 'outcome_images_' . $itemId;
        $hasFiles = isset($_FILES[$fileKey]['name'])
            && is_array($_FILES[$fileKey]['name'])
            && array_filter($_FILES[$fileKey]['name'], static fn ($n) => trim((string)$n) !== '') !== [];

        if ($description === '' && !$hasFiles) {
            $errors[] = 'กรุณากรอกข้อความหรือแนบรูปสำหรับ ' . trim((string)($item['item_name'] ?? 'รายการ'));
            continue;
        }
        if (drawdream_utf8_strlen($description) > 8000) {
            $errors[] = 'ข้อความของ ' . trim((string)($item['item_name'] ?? 'รายการ')) . ' ยาวเกิน 8,000 ตัวอักษร';
            continue;
        }

        $newImageNames = foundation_bulk_collect_uploaded_images(
            is_array($_FILES[$fileKey] ?? null) ? $_FILES[$fileKey] : [],
            $uploadDir,
            'needlist_' . $itemId,
            8
        );

        $existingImages = [];
        $rawImages = trim((string)($item['update_images'] ?? ''));
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
        $finalImages = $newImageNames !== [] ? array_merge($existingImages, $newImageNames) : $existingImages;
        $finalImages = array_slice(array_values(array_unique($finalImages)), 0, 8);
        if ($description === '' && $finalImages === []) {
            $errors[] = 'กรุณากรอกข้อความหรือแนบรูปสำหรับ ' . trim((string)($item['item_name'] ?? 'รายการ'));
            continue;
        }

        $finalImagesJson = json_encode($finalImages, JSON_UNESCAPED_UNICODE);
        $up = $conn->prepare(
            "UPDATE foundation_needlist
             SET update_text = ?, update_at = NOW(), update_images = ?
             WHERE item_id = ? AND foundation_id = ?
               AND LOWER(TRIM(COALESCE(approve_item,''))) = 'done'
             LIMIT 1"
        );
        $up->bind_param('ssii', $description, $finalImagesJson, $itemId, $fid);
        $up->execute();
        if ($up->affected_rows <= 0) {
            $errors[] = 'บันทึก ' . trim((string)($item['item_name'] ?? 'รายการ')) . ' ไม่สำเร็จ';
            continue;
        }
        $savedCount++;
    }

    if ($errors !== []) {
        $error = implode(' · ', array_unique($errors));
    } elseif ($savedCount > 0) {
        $success = 'โพสต์ผลการจัดส่งเรียบร้อยแล้ว ' . $savedCount . ' รายการ';
        $dueItems = foundation_bulk_needlist_outcome_due($conn, $fid);
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
    <title>อัปเดตผลสิ่งของ (หลายรายการ) | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
    <link rel="stylesheet" href="css/foundation_manage.css?v=1">
</head>
<body class="foundation-post-update-page foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="bulk-wrap">
    <a href="foundation_dashboard.php" class="bulk-back" data-foundation-back>← กลับ</a>
    <div class="bulk-head">
        <h1>📦 โพสต์ผลการจัดส่งสิ่งของ (<?= count($dueItems) ?> รายการ)</h1>
        <p>ชำระค่าบริการต้องทำทีละรายการ — หลังแอดมินจัดส่งแล้วสามารถโพสต์ผลหลายรายการพร้อมกันได้ที่หน้านี้</p>
    </div>
    <div class="bulk-note">ชำระค่าบริการ: ไปที่ <a href="foundation_needlist_directory.php">รายการสิ่งของ</a> แล้วเปิดแต่ละรายการเพื่อชำระทีละรายการ</div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($dueItems === []): ?>
        <div class="bulk-card"><p class="b--muted" style="margin:0;">ไม่มีรายการสิ่งของที่ต้องโพสต์ผลในขณะนี้</p></div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <?= drawdream_csrf_field() ?>
            <?php foreach ($dueItems as $item):
                $iid = (int)($item['item_id'] ?? 0);
                $iname = trim((string)($item['item_name'] ?? ''));
                $prefill = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '')
                    ? trim((string)(($_POST['outcome_text'][$iid] ?? '')))
                    : trim((string)($item['update_text'] ?? ''));
                ?>
            <div class="bulk-card">
                <h2 style="margin:0 0 8px;font-size:1.05rem;"><?= htmlspecialchars($iname !== '' ? $iname : 'รายการ #' . $iid, ENT_QUOTES, 'UTF-8') ?></h2>
                <label for="txt_<?= $iid ?>">คำอธิบายผลการจัดส่ง *</label>
                <textarea id="txt_<?= $iid ?>" name="outcome_text[<?= $iid ?>]" placeholder="เช่น ภาพบรรยากาศการมอบสิ่งของ…" required><?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?></textarea>
                <label for="img_<?= $iid ?>" style="display:block;margin-top:10px;font-size:.88rem;color:#475569;">รูปภาพ (ถ้ามี)</label>
                <input type="file" id="img_<?= $iid ?>" name="outcome_images_<?= $iid ?>[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="bulk-submit">โพสต์ผลทั้งหมด (<?= count($dueItems) ?> รายการ)</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
