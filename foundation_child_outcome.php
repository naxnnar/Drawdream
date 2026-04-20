<?php
// foundation_child_outcome.php — บันทึกผลลัพธ์/ผลกระทบเด็ก
// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน child outcome
/**
 * มูลนิธิ: อัปเดตข้อความผลลัพธ์ให้เด็กที่อุปการะครบยอดในเดือนปัจจุบัน หรือมีผู้อุปการะแบบรายรอบ (Omise) แล้ว
 */
session_start();
include 'db.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/notification_audit.php';

drawdream_child_sponsorship_ensure_columns($conn);
drawdream_child_outcome_ensure_columns($conn);
drawdream_child_omise_subscription_ensure_schema($conn);

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

$sqlGet = 'SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? AND deleted_at IS NULL LIMIT 1';
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
    $text = trim((string)($_POST['outcome_text'] ?? ''));
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
        $upd = $conn->prepare(
            'UPDATE foundation_children SET update_text = ?, update_images = ?, update_at = NOW() WHERE child_id = ? AND foundation_id = ?'
        );
        $upd->bind_param('ssii', $text, $json, $childId, $foundationId);
        if ($upd->execute()) {
            foreach ($remove as $b) {
                $p = drawdream_outcome_image_existing_path($b, $outcomeDir, $legacyOutcomeDir);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            // แจ้งเตือนเฉพาะผู้บริจาคที่เป็นผู้อุปการะเด็กคนนี้เท่านั้น
            $notifyUserIds = [];
            $stNotify = $conn->prepare("
                SELECT DISTINCT donor_id AS uid
                FROM donation
                WHERE category_id = ? AND target_id = ? AND payment_status = 'completed' AND donor_id IS NOT NULL
                UNION
                SELECT DISTINCT donor_id AS uid
                FROM donation
                WHERE target_id = ? AND donate_type = 'child_subscription'
                  AND donor_id IS NOT NULL AND recurring_status IN ('active', 'paused')
            ");
            if ($stNotify) {
                require_once __DIR__ . '/includes/donate_category_resolve.php';
                $childCategoryId = drawdream_get_or_create_child_donate_category_id($conn);
                $stNotify->bind_param('iii', $childCategoryId, $childId, $childId);
                $stNotify->execute();
                $rsNotify = $stNotify->get_result();
                while ($nr = $rsNotify->fetch_assoc()) {
                    $uid = (int)($nr['uid'] ?? 0);
                    if ($uid > 0) {
                        $notifyUserIds[$uid] = true;
                    }
                }
            }
            if ($notifyUserIds !== []) {
                $childNameText = trim((string)($child['child_name'] ?? 'เด็กคนนี้'));
                $notifTitle = 'อัปเดตผลลัพธ์เด็กที่คุณอุปการะ';
                $notifMsg = 'มูลนิธิอัปเดตผลลัพธ์ของ ' . $childNameText . ' แล้ว';
                $notifLink = 'children_donate.php?id=' . $childId . '&view=outcome';
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
        $error = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
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
$childName = htmlspecialchars($child['child_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตผลลัพธ์ — <?php echo $childName; ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css">
</head>
<body class="foundation-post-update-page">
<?php include 'navbar.php'; ?>

<div class="wrap">
    <a href="children_donate.php?id=<?php echo (int)$childId; ?>" class="back-link" aria-label="ย้อนกลับ" title="ย้อนกลับ" onclick="if (window.history.length > 1) { event.preventDefault(); history.back(); }">←</a>
    <div class="page-title">📢 อัปเดตผลลัพธ์เด็ก</div>

    <?php if ($success): ?>
        <div class="alert alert-success">บันทึกผลลัพธ์เรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

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
        <div class="outcome-target-card">
            <div class="outcome-target-card__label">เด็กที่ต้องอัปเดต</div>
            <div class="outcome-target-card__name"><?php echo $childName; ?></div>
        </div>

        <form method="post" action="foundation_child_outcome.php?id=<?php echo (int)$childId; ?>" enctype="multipart/form-data">
            <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">
            <div class="form-group">
                <label for="outcome_text">คำอธิบายผลลัพธ์ *</label>
                <textarea id="outcome_text" name="outcome_text" rows="8" placeholder="อธิบายผลลัพธ์ที่เกิดขึ้นกับเด็กจากการสนับสนุน"><?php echo htmlspecialchars($existing); ?></textarea>
            </div>
            <div class="form-group">
                <label for="outcome_images">รูปภาพ (ถ้ามี)</label>
                <input type="file" id="outcome_images" name="outcome_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
            </div>
            <button type="submit" class="btn-submit">โพสต์ผลลัพธ์</button>
        </form>
    </div>
</div>
</body>
</html>
