<?php
// foundation_bulk_child_outcome.php — อัปเดตจดหมายเด็กหลายคนพร้อมกัน

define('DRAWDREAM_DB_LIGHT', true);
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/utf8_helpers.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
require_once __DIR__ . '/includes/child_omise_subscription.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/child_outcome_history.php';
require_once __DIR__ . '/includes/foundation_bulk_tasks.php';

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

$dueChildren = drawdream_foundation_children_outcome_due_list($conn, $foundationId);
$outcomeDir = __DIR__ . '/uploads/evidence';
$legacyOutcomeDir = __DIR__ . '/uploads/childern';
if (!is_dir($outcomeDir)) {
    @mkdir($outcomeDir, 0755, true);
}

$error = '';
$success = '';
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dueChildren !== []) {
    drawdream_csrf_require_valid('foundation_bulk_child_outcome.php');
    $texts = is_array($_POST['outcome_text'] ?? null) ? $_POST['outcome_text'] : [];
    $errors = [];
    $pendingCleanup = [];

    foreach ($dueChildren as $child) {
        $childId = (int)($child['child_id'] ?? 0);
        if ($childId <= 0) {
            continue;
        }
        $text = trim((string)($texts[$childId] ?? ''));
        $text = (string)preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        $fileKey = 'outcome_images_' . $childId;
        $hasFiles = isset($_FILES[$fileKey]['name'])
            && is_array($_FILES[$fileKey]['name'])
            && array_filter($_FILES[$fileKey]['name'], static fn ($n) => trim((string)$n) !== '') !== [];

        if ($text === '' && !$hasFiles) {
            $errors[] = 'กรุณากรอกข้อความหรือแนบรูปสำหรับ ' . trim((string)($child['child_name'] ?? 'เด็ก'));
            continue;
        }
        if (drawdream_utf8_strlen($text) > 8000) {
            $errors[] = 'ข้อความของ ' . trim((string)($child['child_name'] ?? 'เด็ก')) . ' ยาวเกิน 8,000 ตัวอักษร';
            continue;
        }

        $mayEdit = drawdream_child_is_monthly_fully_sponsored($conn, $childId, $child)
            || drawdream_child_has_any_active_subscription($conn, $childId);
        if (!$mayEdit) {
            $errors[] = trim((string)($child['child_name'] ?? 'เด็ก')) . ' ยังไม่พร้อมอัปเดตจดหมาย';
            continue;
        }

        $currentList = drawdream_child_outcome_images_parse($child['update_images'] ?? null);
        $pendingUploads = [];
        if ($hasFiles) {
            $names = $_FILES[$fileKey]['name'];
            $tmps = $_FILES[$fileKey]['tmp_name'];
            $errs = $_FILES[$fileKey]['error'];
            $maxBytes = 4 * 1024 * 1024;
            $maxTotal = 8;
            $n = count($names);
            for ($i = 0; $i < $n; $i++) {
                if (count($currentList) + count($pendingUploads) >= $maxTotal) {
                    break;
                }
                if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmp = (string)($tmps[$i] ?? '');
                $ext = foundation_bulk_outcome_upload_ext($tmp, $maxBytes);
                if ($ext === null) {
                    $errors[] = 'รูปของ ' . trim((string)($child['child_name'] ?? 'เด็ก')) . ' ต้องเป็น JPG/PNG/WebP/GIF ไม่เกิน 4 MB';
                    break 2;
                }
                $finalName = 'children_' . $childId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dest = $outcomeDir . DIRECTORY_SEPARATOR . $finalName;
                if (!move_uploaded_file($tmp, $dest)) {
                    $errors[] = 'อัปโหลดรูปของ ' . trim((string)($child['child_name'] ?? 'เด็ก')) . ' ไม่สำเร็จ';
                    break 2;
                }
                $pendingUploads[] = $finalName;
                $pendingCleanup[] = $dest;
            }
        }

        $merged = array_slice(array_values(array_unique(array_merge($currentList, $pendingUploads))), 0, 8);
        $json = drawdream_child_outcome_images_json($merged);
        $oldText = trim((string)($child['update_text'] ?? ''));
        $oldJson = drawdream_child_outcome_images_json(
            drawdream_child_outcome_images_parse($child['update_images'] ?? null)
        );
        if ($text !== $oldText || $json !== $oldJson) {
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
            $errors[] = 'บันทึก ' . trim((string)($child['child_name'] ?? 'เด็ก')) . ' ไม่สำเร็จ — ลองลบอีโมจิแล้วส่งใหม่';
        }
        if (!$ok) {
            foreach ($pendingUploads as $f) {
                $p = $outcomeDir . DIRECTORY_SEPARATOR . $f;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            continue;
        }

        $savedCount++;
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
    }

    if ($errors !== []) {
        $error = implode(' · ', array_unique($errors));
    } elseif ($savedCount > 0) {
        $success = 'โพสต์จดหมายเด็กเรียบร้อยแล้ว ' . $savedCount . ' คน';
        drawdream_foundation_clear_children_outcome_due_cache($foundationId);
        $dueChildren = drawdream_foundation_children_outcome_due_list($conn, $foundationId);
    } else {
        $error = 'ไม่มีรายการที่บันทึกได้ กรุณากรอกข้อความหรือแนบรูปให้ครบทุกคน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>อัปเดตจดหมายเด็ก (หลายคน) | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=48">
    <link rel="stylesheet" href="css/foundation_manage.css?v=1">
</head>
<body class="foundation-child-letter-page foundation-bulk-child-letter-page foundation-manage-page">
<?php include 'navbar.php'; ?>

<div class="child-letter-wrap">
    <a href="foundation_dashboard.php" class="child-letter-back" aria-label="กลับ" title="กลับ" data-foundation-back>←</a>
    <h1 class="child-letter-page-title">✉️ อัปเดตจดหมายเด็ก (<?= count($dueChildren) ?> คน)</h1>
    <p class="child-letter-bulk-intro">กรอกข้อความและแนบรูปให้ครบทุกคนที่ถึงกำหนด แล้วกดส่งพร้อมกัน — ผู้อุปการะจะได้รับแจ้งเตือนทีละคน</p>

    <?php if ($success !== ''): ?>
        <div class="child-letter-alert child-letter-alert--ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="child-letter-alert child-letter-alert--err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($dueChildren === []): ?>
        <div class="child-letter-airmail">
            <div class="child-letter-paper">
                <p class="b--muted" style="margin:0;">ไม่มีเด็กที่ถึงกำหนดส่งจดหมายในขณะนี้</p>
            </div>
        </div>
    <?php else: ?>
        <form class="child-letter-bulk-form" method="post" enctype="multipart/form-data">
            <?= drawdream_csrf_field() ?>
            <div class="child-letter-stack">
            <?php foreach ($dueChildren as $child):
                $cid = (int)($child['child_id'] ?? 0);
                $cnameRaw = trim((string)($child['child_name'] ?? ''));
                $cname = htmlspecialchars($cnameRaw, ENT_QUOTES, 'UTF-8');
                $existing = trim((string)($child['update_text'] ?? ''));
                $prefill = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '')
                    ? trim((string)(($_POST['outcome_text'][$cid] ?? '')))
                    : $existing;
                $existingImages = drawdream_child_outcome_images_parse($child['update_images'] ?? null);
                $letterPhotoSrc = '';
                if ($existingImages !== []) {
                    $letterPhotoSrc = drawdream_child_outcome_image_url((string)$existingImages[0]);
                }
                if ($letterPhotoSrc === '' && !empty($child['photo_child'])) {
                    $letterPhotoSrc = 'uploads/childern/' . basename((string)$child['photo_child']);
                }
                $clipId = 'clipMetal_' . $cid;
                ?>
            <div class="child-letter-airmail">
                <div class="child-letter-paper">
                    <p class="child-letter-paper__kicker">โพสต์ข้อความจากเด็ก</p>
                    <h2 class="child-letter-paper__hello">
                        <?= $cnameRaw !== '' ? 'น้อง' . $cname . '!' : 'จดหมายเด็ก #' . $cid ?>
                    </h2>

                    <div class="child-letter-compose">
                        <div class="child-letter-polaroid">
                            <span class="child-letter-paperclip" aria-hidden="true">
                                <svg class="child-letter-paperclip__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                    <defs>
                                        <linearGradient id="<?= htmlspecialchars($clipId, ENT_QUOTES, 'UTF-8') ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" stop-color="#f3f4f6"/>
                                            <stop offset="40%" stop-color="#d1d5db"/>
                                            <stop offset="100%" stop-color="#6b7280"/>
                                        </linearGradient>
                                    </defs>
                                    <path fill="url(#<?= htmlspecialchars($clipId, ENT_QUOTES, 'UTF-8') ?>)" stroke="#9ca3af" stroke-width="0.6" stroke-linejoin="round" d="M16.5 6v11.5a4.5 4.5 0 1 1-9 0V8a2.5 2.5 0 0 1 5 0v9.5a2 2 0 1 1-4 0V8"/>
                                </svg>
                            </span>
                            <?php if ($letterPhotoSrc !== ''): ?>
                            <img src="<?= htmlspecialchars($letterPhotoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" class="child-letter-polaroid__img" loading="lazy" decoding="async">
                            <?php else: ?>
                            <div class="child-letter-polaroid__placeholder" aria-hidden="true">📷</div>
                            <?php endif; ?>
                        </div>

                        <div class="child-letter-fields">
                            <label class="child-letter-fields__label" for="outcome_text_<?= $cid ?>">ข้อความจากเด็ก *</label>
                            <textarea
                                id="outcome_text_<?= $cid ?>"
                                name="outcome_text[<?= $cid ?>]"
                                class="child-letter-fields__textarea"
                                rows="7"
                                placeholder="เขียนข้อความถึงผู้อุปการะ เช่น ขอบคุณที่ช่วยเหลือ หนูมีความสุขมากค่ะ…"
                                required
                            ><?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?></textarea>

                            <label class="child-letter-fields__label child-letter-fields__label--file" for="outcome_images_<?= $cid ?>">รูปภาพแนบในจดหมาย (ถ้ามี)</label>
                            <input type="file" id="outcome_images_<?= $cid ?>" class="child-letter-fields__file" name="outcome_images_<?= $cid ?>[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
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
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="child-letter-submit child-letter-submit--bulk">โพสต์ข้อความจากเด็กทั้งหมด (<?= count($dueChildren) ?> คน)</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
