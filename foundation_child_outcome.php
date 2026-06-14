<?php
// foundation_child_outcome.php — บันทึกผลลัพธ์/ผลกระทบเด็ก
// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน child outcome
/**
 * มูลนิธิ: อัปเดตข้อความผลลัพธ์ให้เด็กที่อุปการะครบยอดในเดือนปัจจุบัน หรือมีผู้อุปการะแบบรายรอบ (Omise) แล้ว
 */
include 'db.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/child_outcome_history.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: homepage.php');
    exit;
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$foundationId = 0;
$stmtFP = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmtFP->bind_param('i', $currentUserId);
$stmtFP->execute();
$rowFP = $stmtFP->get_result()->fetch_assoc();
if ($rowFP) {
    $foundationId = (int)$rowFP['foundation_id'];
}

if ($foundationId <= 0) {
    die('ไม่พบข้อมูลมูลนิธิ');
}

$childId = (int)($_GET['id'] ?? $_POST['child_id'] ?? 0);
if ($childId <= 0) {
    header('Location: children_.php?msg=' . rawurlencode('ไม่พบโปรไฟล์เด็ก'));
    exit;
}

$sqlGet = 'SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? LIMIT 1';
$stmtGet = $conn->prepare($sqlGet);
$stmtGet->bind_param('ii', $childId, $foundationId);
$stmtGet->execute();
$child = $stmtGet->get_result()->fetch_assoc();

if (!$child) {
    header('Location: children_.php?msg=' . rawurlencode('ไม่พบข้อมูลโปรไฟล์หรือไม่มีสิทธิ์'));
    exit;
}

$mayEditOutcome = drawdream_child_is_monthly_fully_sponsored($conn, $childId, $child)
    || drawdream_child_has_any_active_subscription($conn, $childId);
if (!$mayEditOutcome) {
    header('Location: children_donate.php?id=' . $childId . '&msg=' . rawurlencode('อัปเดตผลลัพธ์ได้เฉพาะเด็กที่อุปการะครบยอดในเดือนนี้ หรือมีผู้อุปการะแบบรายรอบแล้วเท่านั้น'));
    exit;
}

$outcomeDir = __DIR__ . '/uploads/evidence';
$legacyOutcomeDir = __DIR__ . '/uploads/childern';
if (!is_dir($outcomeDir)) {
    @mkdir($outcomeDir, 0755, true);
}

$error = '';
$success = isset($_GET['saved']) && $_GET['saved'] === '1';

function drawdream_outcome_image_existing_path(string $basename, string $primaryDir, string $legacyDir): string
{
    $safe = basename($basename);
    if ($safe === '') {
        return '';
    }
    $primary = $primaryDir . DIRECTORY_SEPARATOR . $safe;
    if (is_file($primary)) {
        return $primary;
    }
    $legacy = $legacyDir . DIRECTORY_SEPARATOR . $safe;
    if (is_file($legacy)) {
        return $legacy;
    }
    return $primary;
}

/**
 * @return string|null นามสกุลไฟล์ (jpg/png/webp/gif) หรือ null
 */
function drawdream_outcome_upload_ext(string $tmpPath, int $maxBytes): ?string
{
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }

    $sz = @filesize($tmpPath);
    if ($sz === false || $sz > $maxBytes || $sz < 32) {
        return null;
    }
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($tmpPath);

        return $map[$mime] ?? null;
    }
    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info[2])) {
        return null;
    }
    $itype = (int)$info[2];
    $fromGd = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    return $fromGd[$itype] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    drawdream_csrf_require_valid('foundation_child_outcome.php?id=' . (int)$childId);
    $text = trim((string)($_POST['outcome_text'] ?? ''));
    // บางเครื่องยังเป็น utf8mb3: ตัดอักขระ 4-byte (เช่น emoji) เพื่อกัน SQL collation/charset exception
    $text = (string)preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    $currentList = drawdream_child_outcome_images_parse($child['update_images'] ?? null);

    $remove = [];
    if (!empty($_POST['remove_outcome_images']) && is_array($_POST['remove_outcome_images'])) {
        foreach ($_POST['remove_outcome_images'] as $rm) {
            $b = basename((string)$rm);
            if ($b !== '' && in_array($b, $currentList, true)) {
                $remove[] = $b;
            }
        }
        $remove = array_values(array_unique($remove));
    }

    $newList = array_values(array_diff($currentList, $remove));

    $maxBytes = 4 * 1024 * 1024;
    $maxTotal = 8;
    $pendingUploads = [];

    if (!empty($_FILES['outcome_images']['name']) && is_array($_FILES['outcome_images']['name'])) {
        $names = $_FILES['outcome_images']['name'];
        $tmps = $_FILES['outcome_images']['tmp_name'];
        $errs = $_FILES['outcome_images']['error'];
        $n = count($names);
        for ($i = 0; $i < $n; $i++) {
            if (count($newList) + count($pendingUploads) >= $maxTotal) {
                break;
            }
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)($tmps[$i] ?? '');
            $ext = drawdream_outcome_upload_ext($tmp, $maxBytes);
            if ($ext === null) {
                $error = 'รูปต้องเป็น JPG, PNG, WebP หรือ GIF และขนาดไม่เกิน 4 MB ต่อไฟล์';
                break;
            }
            $finalName = 'children_' . $childId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = $outcomeDir . DIRECTORY_SEPARATOR . $finalName;
            if (!move_uploaded_file($tmp, $dest)) {
                $error = 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่';
                break;
            }
            $pendingUploads[] = $finalName;
        }
    }

    if ($error !== '') {
        foreach ($pendingUploads as $f) {
            $p = drawdream_outcome_image_existing_path($f, $outcomeDir, $legacyOutcomeDir);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    } elseif ($text === '' && $newList === [] && $pendingUploads === []) {
        $error = 'กรุณากรอกข้อความหรือแนบรูปอย่างน้อย 1 รายการ';
    } elseif (drawdream_utf8_strlen($text) > 8000) {
        $error = 'ข้อความยาวเกิน 8,000 ตัวอักษร';
    } else {
        $merged = array_slice(array_values(array_unique(array_merge($newList, $pendingUploads))), 0, $maxTotal);
        $json = drawdream_child_outcome_images_json($merged);
        $oldText = trim((string)($child['update_text'] ?? ''));
        $oldJson = drawdream_child_outcome_images_json(
            drawdream_child_outcome_images_parse($child['update_images'] ?? null)
        );
        $contentChanged = ($text !== $oldText) || ($json !== $oldJson);
        if ($contentChanged) {
            drawdream_child_outcome_archive_snapshot(
                $childId,
                (string)($child['update_text'] ?? ''),
                (string)($child['update_images'] ?? ''),
                trim((string)($child['update_at'] ?? '')) !== '' ? (string)$child['update_at'] : null
            );
        }
        $upd = $conn->prepare(
            'UPDATE foundation_children SET update_text = ?, update_images = ?, update_at = NOW() WHERE child_id = ? AND foundation_id = ?'
        );
        $upd->bind_param('ssii', $text, $json, $childId, $foundationId);
        try {
            $ok = $upd->execute();
        } catch (mysqli_sql_exception $e) {
            $ok = false;
            $error = 'บันทึกไม่สำเร็จ: กรุณาลองลบอีโมจิหรืออักขระพิเศษ แล้วบันทึกอีกครั้ง';
        }
        if ($ok) {
            foreach ($remove as $b) {
                $p = drawdream_outcome_image_existing_path($b, $outcomeDir, $legacyOutcomeDir);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            // แจ้งเตือนเฉพาะ "ผู้อุปการะปัจจุบัน" (active/paused หรือ cancelled แต่ coverage ยังไม่หมด)
            $notifyUserIds = drawdream_child_current_sponsor_user_ids($conn, $childId);
            if ($notifyUserIds !== []) {
                $childNameText = trim((string)($child['child_name'] ?? 'เด็กคนนี้'));
                $notifTitle = 'จดหมายจากเด็ก';
                $notifMsg = 'น้อง' . $childNameText . ' ส่งจดหมายถึงคุณแล้ว — แตะเพื่อเปิดอ่าน';
                $notifLink = 'children_donate.php?id=' . $childId . '&view=outcome&letter=open&m=' . date('YmdHis');
                foreach (array_keys($notifyUserIds) as $uid) {
                    drawdream_send_notification(
                        $conn,
                        (int)$uid,
                        'child_outcome_update',
                        $notifTitle,
                        $notifMsg,
                        $notifLink,
                        'child_outcome:' . $childId
                    );
                }
            }
            header('Location: foundation_child_outcome.php?id=' . $childId . '&saved=1');
            exit;
        }
        if ($error === '') {
            $error = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
        }
        foreach ($pendingUploads as $f) {
            $p = drawdream_outcome_image_existing_path($f, $outcomeDir, $legacyOutcomeDir);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmtGet->execute();
        $child = $stmtGet->get_result()->fetch_assoc();
    }
}

$existing = (string)($child['update_text'] ?? '');
$existingImages = drawdream_child_outcome_images_parse($child['update_images'] ?? null);
$childNameRaw = trim((string)($child['child_name'] ?? ''));
$childName = htmlspecialchars($childNameRaw, ENT_QUOTES, 'UTF-8');
$letterPhotoSrc = '';
if ($existingImages !== []) {
    $letterPhotoSrc = drawdream_child_outcome_image_url((string)$existingImages[0]);
}
if ($letterPhotoSrc === '' && !empty($child['photo_child'])) {
    $letterPhotoSrc = 'uploads/childern/' . basename((string)$child['photo_child']);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>อัปเดตจดหมายเด็ก — <?php echo $childName; ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=46">
</head>
<body class="foundation-child-letter-page">
<?php include 'navbar.php'; ?>

<div class="child-letter-wrap">
    <a href="children_donate.php?id=<?php echo (int)$childId; ?>" class="child-letter-back" aria-label="ย้อนกลับ" title="ย้อนกลับ" onclick="if (window.history.length > 1) { event.preventDefault(); history.back(); }">←</a>
    <h1 class="child-letter-page-title">✉️ อัปเดตจดหมายเด็ก</h1>

    <?php if ($success): ?>
        <div class="child-letter-alert child-letter-alert--ok">บันทึกข้อความจากเด็กเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="child-letter-alert child-letter-alert--err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="child-letter-airmail">
    <div class="child-letter-paper">
        <a href="children_donate.php?id=<?php echo (int)$childId; ?>" class="child-letter-paper__close" aria-label="ปิด" title="ปิด">&times;</a>

        <p class="child-letter-paper__kicker">โพสต์ข้อความจากเด็ก</p>
        <h2 class="child-letter-paper__hello">
            <?php echo $childNameRaw !== '' ? 'น้อง' . $childName . '!' : 'จดหมายเด็ก'; ?>
        </h2>

        <form class="child-letter-form" method="post" action="foundation_child_outcome.php?id=<?php echo (int)$childId; ?>" enctype="multipart/form-data">
            <?= drawdream_csrf_field() ?>
            <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">

            <div class="child-letter-compose">
                <div class="child-letter-polaroid">
                    <span class="child-letter-paperclip" aria-hidden="true">
                        <svg class="child-letter-paperclip__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                            <defs>
                                <linearGradient id="clipMetal" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#f3f4f6"/>
                                    <stop offset="40%" stop-color="#d1d5db"/>
                                    <stop offset="100%" stop-color="#6b7280"/>
                                </linearGradient>
                            </defs>
                            <path fill="url(#clipMetal)" stroke="#9ca3af" stroke-width="0.6" stroke-linejoin="round" d="M16.5 6v11.5a4.5 4.5 0 1 1-9 0V8a2.5 2.5 0 0 1 5 0v9.5a2 2 0 1 1-4 0V8"/>
                        </svg>
                    </span>
                    <?php if ($letterPhotoSrc !== ''): ?>
                    <img src="<?php echo htmlspecialchars($letterPhotoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="child-letter-polaroid__img" loading="lazy" decoding="async">
                    <?php else: ?>
                    <div class="child-letter-polaroid__placeholder" aria-hidden="true">📷</div>
                    <?php endif; ?>
                </div>

                <div class="child-letter-fields">
                    <label class="child-letter-fields__label" for="outcome_text">ข้อความจากเด็ก *</label>
                    <textarea
                        id="outcome_text"
                        name="outcome_text"
                        class="child-letter-fields__textarea"
                        rows="7"
                        placeholder="เขียนข้อความถึงผู้อุปการะ เช่น ขอบคุณที่ช่วยเหลือ หนูมีความสุขมากค่ะ…"
                        required
                    ><?php echo htmlspecialchars($existing, ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <label class="child-letter-fields__label child-letter-fields__label--file" for="outcome_images">รูปภาพแนบในจดหมาย (ถ้ามี)</label>
                    <input type="file" id="outcome_images" class="child-letter-fields__file" name="outcome_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                </div>
            </div>

            <div class="child-letter-deco child-letter-deco--postmark" aria-hidden="true">
                <svg viewBox="0 0 120 48" xmlns="http://www.w3.org/2000/svg" class="child-letter-deco__svg">
                    <path d="M4 28 Q30 8 58 26 T116 22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                    <path d="M8 38 Q36 22 64 36 T112 30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.55"/>
                </svg>
            </div>
            <div class="child-letter-deco child-letter-deco--seal" aria-hidden="true">
                <img src="img/letter.png" alt="" class="child-letter-deco__seal-img" loading="lazy" decoding="async">
            </div>

            <button type="submit" class="child-letter-submit">โพสต์ข้อความจากเด็ก</button>
        </form>
    </div>
    </div>
</div>
</body>
</html>
