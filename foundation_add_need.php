<?php
// foundation_add_need.php — มูลนิธิเสนอรายการสิ่งของ

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'foundation') {
    header("Location: homepage.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) die("ไม่พบ user_id ใน session");

// ดึง foundation_id + ชื่อมูลนิธิ (ใช้แจ้งเตือน)
$stmt = $conn->prepare("SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id=? LIMIT 1");
if (!$stmt) die("Prepare failed: " . $conn->error);
$stmt->bind_param("i", $uid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
if (!$fp) die("ยังไม่มีโปรไฟล์มูลนิธิ กรุณาสร้างก่อน");
$foundation_id = (int)$fp['foundation_id'];
$foundation_display_name = trim((string)($fp['foundation_name'] ?? ''));

$editItemPg = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editItemPg > 0) {
    $es = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
    if ($es) {
        $es->bind_param('ii', $editItemPg, $foundation_id);
        $es->execute();
        $editRow = $es->get_result()->fetch_assoc();
    }
    if (!$editRow) {
        $editItemPg = 0;
    }
}

$error   = "";
$success = "";

if ($editRow && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $cats = preg_split('/\s*\|\s*/u', (string)($editRow['brand'] ?? ''));
    $cats = array_values(array_filter(array_map('trim', $cats), function ($s) {
        return $s !== '';
    }));
    $_POST['item_categories'] = $cats;
    $opts = preg_split('/\s*,\s*/u', (string)($editRow['item_name'] ?? ''));
    $_POST['item_options'] = array_values(array_filter(array_map('trim', $opts), function ($s) {
        return $s !== '';
    }));
    $_POST['item_desc'] = (string)($editRow['item_desc'] ?? '');
    $_POST['goal_amount'] = (string)(int)round((float)($editRow['total_price'] ?: $editRow['price_estimate'] ?? 0));
    $rawNote = (string)($editRow['note'] ?? '');
    $periodVal = '';
    $noteOnly = '';
    $lines = preg_split('/\R/u', $rawNote, 2);
    if (preg_match('/^ระยะเวลา:\s*(.+)$/u', $lines[0] ?? '', $pm)) {
        $periodVal = trim($pm[1]);
    }
    $noteOnly = isset($lines[1]) ? trim((string)$lines[1]) : '';
    $_POST['period'] = $periodVal;
    $_POST['note'] = $noteOnly;
    if ((int)($editRow['urgent'] ?? 0) === 1) {
        $_POST['urgent'] = '1';
    }
}

$itemCategories = [
    'อุปโภคบริโภค (ของกิน-ของใช้ประจำวัน)',
    'สุขภาพและเวชภัณฑ์พื้นฐาน',
    'เสื้อผ้าและเครื่องนุ่งห่ม',
    'อุปกรณ์ไฟฟ้าและไอที',
    'อื่นๆ ที่จำเป็นเฉพาะทาง'
];

$categoryItems = [
    'อุปโภคบริโภค (ของกิน-ของใช้ประจำวัน)' => [
        'ข้าวสาร', 'บะหมี่กึ่งสำเร็จรูป', 'ปลากระป๋อง', 'นมกล่อง UHT', 'น้ำมันพืช', 'เครื่องปรุงรส',
        'สบู่', 'ยาสีฟัน', 'แปรงสีฟัน', 'แชมพู', 'ผ้าอนามัย', 'แพมเพิส', 'กระดาษทิชชู่', 'น้ำดื่มบรรจุขวด'
    ],
    'สุขภาพและเวชภัณฑ์พื้นฐาน' => [
        'ยาพาราเซตามอล', 'ยาแก้ไอ', 'ผงเกลือแร่ ORS', 'ยาใส่แผล',
        'สำลี', 'แอลกอฮอล์ล้างแผล', 'พลาสเตอร์ปิดแผล', 'หน้ากากอนามัย',
        'รถเข็นผู้ป่วย', 'ไม้เท้า', 'แผ่นรองซับ'
    ],
    'เสื้อผ้าและเครื่องนุ่งห่ม' => [
        'เสื้อยืด', 'กางเกงขาสั้น', 'กางเกงขายาว', 'กางเกงในใหม่', 'เสื้อซับใหม่',
        'ผ้าห่ม', 'เสื้อกันหนาว', 'หมวกไหมพรม'
    ],
    'อุปกรณ์ไฟฟ้าและไอที' => [
        'คอมพิวเตอร์หรือโน้ตบุ๊ก', 'แท็บเล็ตเพื่อการเรียน', 'พัดลม', 'หม้อหุงข้าว', 'กระติกน้ำร้อน'
    ],
    'อื่นๆ ที่จำเป็นเฉพาะทาง' => [
        'อื่นๆ (ระบุในรายละเอียด)'
    ]
];

if (isset($_POST['submit'])) {
    $itemIdEdit = (int)($_POST['item_id'] ?? 0);
    $existingNeedRow = null;
    if ($itemIdEdit > 0) {
        $chkOwn = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
        if (!$chkOwn) {
            $error = 'Prepare failed: ' . $conn->error;
        } else {
            $chkOwn->bind_param('ii', $itemIdEdit, $foundation_id);
            $chkOwn->execute();
            $existingNeedRow = $chkOwn->get_result()->fetch_assoc();
            if (!$existingNeedRow) {
                $error = 'ไม่พบรายการสิ่งของหรือไม่มีสิทธิแก้ไข';
            }
        }
    }

    if ($error !== '') {
        // ข้าม validation เมื่อตรวจสิทธิ์แก้ไขไม่ผ่าน
    } else {

    $selectedCategories = $_POST['item_categories'] ?? [];
    if (!is_array($selectedCategories)) $selectedCategories = [];
    $selectedCategories = array_values(array_unique(array_filter(array_map('trim', $selectedCategories), function ($v) {
        return $v !== '';
    })));

    $itemOptions = $_POST['item_options'] ?? [];
    if (!is_array($itemOptions)) $itemOptions = [];
    $itemOptions = array_values(array_unique(array_filter(array_map('trim', $itemOptions), function ($v) {
        return $v !== '';
    })));

    $item_name   = implode(', ', $itemOptions);
    $item_desc   = trim($_POST['item_desc'] ?? '');
    $brand       = implode(' | ', $selectedCategories);
    $allow_other = 0;
    $urgent      = isset($_POST['urgent']) ? 1 : 0;
    $note        = trim($_POST['note'] ?? '');
    $period      = trim($_POST['period'] ?? '');
    $goal        = (float)($_POST['goal_amount'] ?? 0);
    // เก็บระยะเวลาไว้ใน note
    if ($period !== '') {
        $note = "ระยะเวลา: " . $period . ($note !== '' ? "\n" . $note : '');
    }
    $qty         = 1;   // ใช้ค่าคงที่แทน ไม่รับจาก user
    $price       = $goal;

    // Validation
    if (count($selectedCategories) < 1) {
        $error = "กรุณาเลือกหมวดหมู่สิ่งของอย่างน้อย 1 หมวด";
    } elseif (count(array_diff($selectedCategories, $itemCategories)) > 0) {
        $error = "หมวดหมู่สิ่งของไม่ถูกต้อง";
    } elseif (count($itemOptions) < 1) {
        $error = "กรุณาเลือกรายการสิ่งของอย่างน้อย 1 รายการ";
    } elseif (count($itemOptions) > 5) {
        $error = "เลือกรายการสิ่งของได้สูงสุด 5 รายการ";
    } else {
        $allowedOptions = [];
        foreach ($selectedCategories as $cat) {
            if (isset($categoryItems[$cat])) {
                $allowedOptions = array_merge($allowedOptions, $categoryItems[$cat]);
            }
        }
        $allowedOptions = array_values(array_unique($allowedOptions));
        if ($itemIdEdit > 0 && $existingNeedRow) {
            $oldOpts = [];
            foreach (preg_split('/\s*,\s*/u', (string)($existingNeedRow['item_name'] ?? '')) as $p) {
                $t = trim($p);
                if ($t !== '') {
                    $oldOpts[] = $t;
                }
            }
            $allowedOptions = array_values(array_unique(array_merge($allowedOptions, $oldOpts)));
        }
        foreach ($itemOptions as $opt) {
            if (!in_array($opt, $allowedOptions, true)) {
                $error = "มีรายการสิ่งของที่ไม่ตรงกับหมวดที่เลือก";
                break;
            }
        }
    }

    if ($error === "") {
        if ($goal <= 0) {
            $error = "ยอดเป้าหมายเงินบริจาคต้องมากกว่า 0";
        } elseif ($period === '') {
            $error = "กรุณาเลือกระยะเวลา";
        }
    }

    // อัปโหลดรูป (ไม่บังคับ, สูงสุด 3 รูป)
    $uploadedImages = [];
    if ($error === "" && isset($_FILES['item_image']) && is_array($_FILES['item_image']['name'])) {
        $uploadDir = "uploads/needs/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $names = $_FILES['item_image']['name'];
        $tmpNames = $_FILES['item_image']['tmp_name'];
        $sizes = $_FILES['item_image']['size'];
        $errors = $_FILES['item_image']['error'];

        $pickedCount = 0;
        foreach ($names as $nm) {
            if (trim((string)$nm) !== '') $pickedCount++;
        }

        if ($pickedCount > 3) {
            $error = "อัปโหลดได้สูงสุด 3 รูป";
        } else {
            foreach ($names as $idx => $imageName) {
                if (trim((string)$imageName) === '') continue;

                $errCode = (int)$errors[$idx];
                if ($errCode === UPLOAD_ERR_NO_FILE) continue;
                if ($errCode !== UPLOAD_ERR_OK) {
                    $error = "ข้อผิดพลาดการอัปโหลด (Error code: " . $errCode . ")";
                    break;
                }

                $fileSize = (int)$sizes[$idx];
                if ($fileSize > 5 * 1024 * 1024) {
                    $error = "แต่ละไฟล์ต้องไม่เกิน 5MB";
                    break;
                }

                $ext = strtolower(pathinfo((string)$imageName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = "อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp";
                    break;
                }

                $safeName = time() . "_" . uniqid() . "_" . $idx . "." . $ext;
                $targetPath = $uploadDir . $safeName;
                if (!move_uploaded_file((string)$tmpNames[$idx], $targetPath)) {
                    $error = "อัปโหลดรูปไม่สำเร็จ";
                    break;
                }

                $uploadedImages[] = $safeName;
            }
        }
    }

    $needFoundationImageDb = '';
    if ($error === "" && isset($_FILES['foundation_need_image'])) {
        $ff = $_FILES['foundation_need_image'];
        $errF = (int)($ff['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errF === UPLOAD_ERR_OK) {
            $uploadDir = "uploads/needs/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $imgName = (string)($ff['name'] ?? '');
            $tmpF = (string)($ff['tmp_name'] ?? '');
            $sizeF = (int)($ff['size'] ?? 0);
            if ($sizeF > 5 * 1024 * 1024) {
                $error = "รูปมูลนิธิต้องไม่เกิน 5MB";
            } else {
                $ext = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = "รูปมูลนิธิ อนุญาตเฉพาะ jpg/jpeg/png/gif/webp";
                } elseif ($tmpF === '' || !is_uploaded_file($tmpF)) {
                    $error = "อัปโหลดรูปมูลนิธิไม่สำเร็จ";
                } else {
                    $safeF = time() . "_" . uniqid('', true) . "_fdn." . $ext;
                    if (!move_uploaded_file($tmpF, $uploadDir . $safeF)) {
                        $error = "บันทึกไฟล์รูปมูลนิธิไม่สำเร็จ";
                    } else {
                        $needFoundationImageDb = $safeF;
                    }
                }
            }
        } elseif ($errF !== UPLOAD_ERR_NO_FILE) {
            $error = "ข้อผิดพลาดอัปโหลดรูปมูลนิธิ (รหัส " . $errF . ")";
        }
    }

    } // end skip validation when $error set early

    // บันทึก
    if ($error === "") {
        $total_price = $goal;

        $slot0 = $uploadedImages[0] ?? '';
        $slot1 = $uploadedImages[1] ?? '';
        $slot2 = $uploadedImages[2] ?? '';

        if ($itemIdEdit > 0 && $existingNeedRow) {
            $merged = foundation_needlist_item_filenames_from_row($existingNeedRow);
            while (count($merged) < 3) {
                $merged[] = '';
            }
            $merged = array_slice($merged, 0, 3);
            if ($slot0 !== '') {
                $merged[0] = $slot0;
            }
            if ($slot1 !== '') {
                $merged[1] = $slot1;
            }
            if ($slot2 !== '') {
                $merged[2] = $slot2;
            }
            $im0 = $merged[0] ?? '';
            $im1 = $merged[1] ?? '';
            $im2 = $merged[2] ?? '';

            $nfFinal = trim((string)($existingNeedRow['need_foundation_image'] ?? ''));
            if ($needFoundationImageDb !== '') {
                $nfFinal = $needFoundationImageDb;
            }

            $sqlU = "UPDATE foundation_needlist SET
                item_name = ?, item_desc = ?, brand = ?, allow_other_brand = ?,
                qty_needed = ?, price_estimate = ?, urgent = ?,
                item_image = ?, item_image_2 = ?, item_image_3 = ?, need_foundation_image = ?,
                note = ?, total_price = ?
                WHERE item_id = ? AND foundation_id = ?";
            $stmt = $conn->prepare($sqlU);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $updTypes = 'sss' . 'iidi' . str_repeat('s', 5) . 'd' . 'ii';
                $stmt->bind_param(
                    $updTypes,
                    $item_name, $item_desc, $brand,
                    $allow_other, $qty, $price, $urgent,
                    $im0, $im1, $im2, $nfFinal,
                    $note, $total_price,
                    $itemIdEdit, $foundation_id
                );

                if ($stmt->execute()) {
                    if (($existingNeedRow['approve_item'] ?? '') === 'approved') {
                        require_once __DIR__ . '/includes/needlist_donate_window.php';
                        $periodLabel = drawdream_needlist_period_label_from_note($note);
                        $rv = trim((string)($existingNeedRow['reviewed_at'] ?? ''));
                        try {
                            $from = ($rv !== '' && !str_starts_with($rv, '0000-00-00'))
                                ? new DateTimeImmutable($rv)
                                : new DateTimeImmutable('now');
                        } catch (Throwable $e) {
                            $from = new DateTimeImmutable('now');
                        }
                        $end = drawdream_needlist_compute_donate_window_end($periodLabel, $from);
                        $eid = (int)$itemIdEdit;
                        if ($end === null) {
                            $zu = $conn->prepare('UPDATE foundation_needlist SET donate_window_end_at = NULL WHERE item_id = ? AND foundation_id = ?');
                            if ($zu) {
                                $zu->bind_param('ii', $eid, $foundation_id);
                                $zu->execute();
                            }
                        } else {
                            $stE = $conn->prepare('UPDATE foundation_needlist SET donate_window_end_at = ? WHERE item_id = ? AND foundation_id = ?');
                            if ($stE) {
                                $stE->bind_param('sii', $end, $eid, $foundation_id);
                                $stE->execute();
                            }
                        }
                    }
                    header('Location: foundation.php?need_updated=1#my-needlist-section');
                    exit;
                }
                $error = "บันทึกไม่สำเร็จ: " . $stmt->error;
            }
        } else {
            $im0 = $slot0;
            $im1 = $slot1;
            $im2 = $slot2;

            $sql  = "INSERT INTO foundation_needlist 
                 (foundation_id, item_name, item_desc, brand, allow_other_brand,
                  qty_needed, price_estimate, urgent, item_image, item_image_2, item_image_3, need_foundation_image, created_by_user_id, note, total_price, approve_item)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param(
                    "isssiidissssisd",
                    $foundation_id, $item_name, $item_desc, $brand,
                    $allow_other, $qty, $price, $urgent, $im0, $im1, $im2, $needFoundationImageDb, $uid, $note, $total_price
                );

                if ($stmt->execute()) {
                    $newItemId = (int)$conn->insert_id;
                    if ($newItemId > 0) {
                        require_once __DIR__ . '/includes/notification_audit.php';
                        drawdream_ensure_notifications_table($conn);
                        drawdream_record_foundation_submitted_need($conn, $uid, $newItemId, $item_name, $total_price, $foundation_display_name, $urgent === 1);
                        drawdream_notify_admins_need_submitted($conn, $newItemId, $item_name, $foundation_display_name, $total_price, $urgent === 1);
                    }
                    $success = "เสนอรายการสำเร็จ รอแอดมินอนุมัติ";
                    $_POST   = [];
                } else {
                    $error = "บันทึกไม่สำเร็จ: " . $stmt->error;
                }
            }
        }
    }
}

$hiddenItemId = (int)($_POST['item_id'] ?? $editItemPg);
$isEditForm = $hiddenItemId > 0;
$thumbRow = null;
if ($hiddenItemId > 0) {
    $trTh = $conn->prepare('SELECT item_image, item_image_2, item_image_3, need_foundation_image FROM foundation_needlist WHERE item_id = ? AND foundation_id = ? LIMIT 1');
    if ($trTh) {
        $trTh->bind_param('ii', $hiddenItemId, $foundation_id);
        $trTh->execute();
        $thumbRow = $trTh->get_result()->fetch_assoc();
    }
}
$pageTitle = $isEditForm ? 'แก้ไขรายการสิ่งของมูลนิธิ' : 'เสนอสิ่งของมูลนิธิ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=23">
</head>
<body class="foundation-add-need-page">

<?php include 'navbar.php'; ?>

<div class="add-need-container">
    <p class="add-need-back"><a href="foundation.php" class="add-need-back-link">← กลับหน้ามูลนิธิ</a></p>
    <h2><?= htmlspecialchars($pageTitle) ?></h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?php if ($hiddenItemId > 0): ?>
        <input type="hidden" name="item_id" value="<?= (int)$hiddenItemId ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-col">

                <div class="form-group">
                    <label>หมวดหมู่สิ่งของ * (เลือกได้หลายหมวด)</label>
                    <?php $selectedCategories = $_POST['item_categories'] ?? []; if (!is_array($selectedCategories)) $selectedCategories = []; ?>
                    <div class="category-check-grid" id="categoryCheckGrid">
                        <?php foreach ($itemCategories as $category): ?>
                            <label class="category-check-item">
                                <input type="checkbox" name="item_categories[]" value="<?= htmlspecialchars($category) ?>" class="category-check" <?= in_array($category, $selectedCategories, true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($category) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#6b7280;">เลือกหลายหมวดได้ แล้วติ๊กรายการที่ต้องการรวมกันได้สูงสุด 5 รายการ</small>
                </div>

                <div class="form-group" id="itemSectionGroup" style="display:none;">
                    <label>รายการสิ่งของ * (เลือกได้สูงสุด 5 รายการ)</label>
                    <?php $selectedItems = $_POST['item_options'] ?? []; if (!is_array($selectedItems)) $selectedItems = []; ?>
                    <div class="item-check-groups" id="itemCheckGroups">
                        <?php foreach ($categoryItems as $catName => $options): ?>
                            <div class="item-check-group" data-category="<?= htmlspecialchars($catName) ?>">
                                <div class="item-check-group-title"><?= htmlspecialchars($catName) ?></div>
                                <div class="item-check-grid">
                                    <?php foreach ($options as $option): ?>
                                        <label class="item-check-item">
                                            <input type="checkbox" name="item_options[]" value="<?= htmlspecialchars($option) ?>" class="item-option-check" <?= in_array($option, $selectedItems, true) ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small id="selectedItemCount" style="color:#6b7280;">เลือกแล้ว 0/5 รายการ</small>
                </div>

            </div>

            <div class="form-col">

                <div class="total-box" id="totalBox">
                    เป้าหมาย: 0 บาท
                </div>

                <div class="form-group">
                    <label>ยอดเป้าหมายเงินบริจาค (บาท)</label>
                    <input type="number" name="goal_amount" id="goalAmount" min="1" step="1" value="<?= htmlspecialchars($_POST['goal_amount'] ?? '') ?>" placeholder="เช่น 50000" required>
                </div>

                <div class="form-group">
                    <label>ระยะเวลา </label>
                    <select name="period" id="period" required>
                        <option value="" disabled <?= empty($_POST['period']) ? 'selected' : '' ?>>-- เลือกระยะเวลา --</option>
                        <?php foreach (['ต่อสัปดาห์','ต่อเดือน','ต่อ 6 เดือน','ต่อปี','ครั้งเดียว (ไม่ซ้ำ)'] as $p): ?>
                            <option value="<?= $p ?>" <?= (($_POST['period'] ?? '') === $p) ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($thumbRow && foundation_needlist_item_filenames_from_row($thumbRow) !== []): ?>
                <div class="form-group need-current-files">
                    <label>รูปสิ่งของที่มีอยู่</label>
                    <div class="need-current-thumbs">
                        <?php foreach (foundation_needlist_item_filenames_from_row($thumbRow) as $fn): ?>
                            <img src="uploads/needs/<?= htmlspecialchars($fn) ?>" alt="" class="need-current-thumb">
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#6b7280;">อัปโหลดรูปใหม่เพื่อแทนที่ตามลำดับ (ช่อง 1–3)</small>
                </div>
                <?php endif; ?>
                <?php if ($thumbRow && trim((string)($thumbRow['need_foundation_image'] ?? '')) !== ''): ?>
                <div class="form-group need-current-files">
                    <label>รูปมูลนิธิปัจจุบัน</label>
                    <div class="need-current-thumbs">
                        <img src="uploads/needs/<?= htmlspecialchars($thumbRow['need_foundation_image']) ?>" alt="" class="need-current-thumb">
                    </div>
                </div>
                <?php endif; ?>

                <div class="foundation-need-images form-group foundation-need-images--prominent">
                    <div class="need-images-duo">
                        <div class="need-images-duo__col">
                            <label class="need-images-duo__label">รูปสิ่งของ <span class="need-img-optional">(ไม่บังคับ · สูงสุด 3 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">สินค้า / แพ็กที่ต้องการให้ผู้บริจาคเห็น</p>
                            <div class="image-upload-box">
                                <input type="file" name="item_image[]" id="fileInput" class="need-image-file-input" accept="image/*" multiple>
                                <div class="upload-label" id="uploadLabel">
                                    <div class="upload-icon">📷</div>
                                    <div>เลือกรูปได้สูงสุด 3 รูป</div>
                                    <div class="upload-hint">JPG, PNG, GIF, WEBP — ไฟล์ละไม่เกิน 5MB</div>
                                </div>
                                <div class="image-upload-toolbar">
                                    <button type="button" class="btn-need-pick-img" id="btnNeedPickImg">เลือก / เพิ่มรูป</button>
                                </div>
                            </div>
                            <div id="imagePreviewList" class="upload-preview-list"></div>
                        </div>
                        <div class="need-images-duo__col need-images-duo__col--fdn">
                            <label class="need-images-duo__label">รูปมูลนิธิ <span class="need-img-optional">(ไม่บังคับ · 1 รูป)</span></label>
                            <p class="need-img-lead need-img-lead--compact">แยกจากรูปสิ่งของ — โลโก้ ทีมงาน หรือภาพกิจกรรม</p>
                            <div class="image-upload-box image-upload-box--compact">
                                <input type="file" name="foundation_need_image" id="foundationNeedImageInput" class="need-image-file-input" accept="image/*">
                                <div class="upload-label upload-label--compact" id="foundationUploadLabel">
                                    <div class="upload-icon upload-icon--sm">🏛️</div>
                                    <div class="upload-hint">ไฟล์ละไม่เกิน 5MB</div>
                                </div>
                                <div class="image-upload-toolbar">
                                    <button type="button" class="btn-need-pick-img btn-need-pick-img--secondary" id="btnFoundationNeedImg">เลือกรูปมูลนิธิ</button>
                                </div>
                            </div>
                            <div id="foundationNeedPreview" class="foundation-need-fdn-preview"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>รายละเอียด</label>
                    <textarea name="item_desc" rows="4" placeholder="ระบุเงื่อนไขที่จำเป็น"><?= htmlspecialchars($_POST['item_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: กรุณาระบุยี่ห้อที่ต้องการถ้ายี่ห้อนี้ไม่ได้"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="urgent" id="urgent" <?= !empty($_POST['urgent']) ? 'checked' : '' ?>>
                    <label for="urgent">ต้องการด่วน</label>
                </div>

            </div>
        </div>

        <button type="submit" name="submit" class="btn-submit"><?= $isEditForm ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
    </form>
</div>

<script>
const goalAmount  = document.getElementById('goalAmount');
const periodSelect = document.getElementById('period');
const totalBox = document.getElementById('totalBox');
const urgentCheckbox = document.getElementById('urgent');
const fileInput = document.getElementById('fileInput');
const btnNeedPickImg = document.getElementById('btnNeedPickImg');
const previewList = document.getElementById('imagePreviewList');
const MAX_NEED_IMAGES = 3;
const MAX_NEED_IMAGE_BYTES = 5 * 1024 * 1024;
const categoryChecks = Array.from(document.querySelectorAll('.category-check'));
const itemChecks = Array.from(document.querySelectorAll('.item-option-check'));
const itemGroups = Array.from(document.querySelectorAll('.item-check-group'));
const selectedItemCount = document.getElementById('selectedItemCount');
/** @type {File[]} */
let selectedFiles = [];

function needImageSignature(file) {
    return `${file.name}|${file.size}|${file.lastModified}`;
}

function syncNeedFileInput() {
    const dt = new DataTransfer();
    selectedFiles.forEach((f) => dt.items.add(f));
    fileInput.files = dt.files;
}

function defaultNeedUploadLabelHtml() {
    return `
            <div class="upload-icon">📷</div>
            <div>เลือกรูปได้สูงสุด 3 รูป</div>
            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP (ไฟล์ละไม่เกิน 5MB) · กด «เพิ่มรูป» เพื่อเลือกต่อโดยไม่ล้างรูปเดิม</div>`;
}

function updateNeedUploadChrome() {
    const label = document.getElementById('uploadLabel');
    const n = selectedFiles.length;
    if (!label || !btnNeedPickImg) return;

    if (n === 0) {
        label.innerHTML = defaultNeedUploadLabelHtml();
        btnNeedPickImg.textContent = 'เลือกรูป';
        btnNeedPickImg.disabled = false;
        return;
    }

    const names = selectedFiles.map((f) => f.name).join(', ');
    label.innerHTML = `
            <div class="upload-icon">✓</div>
            <div class="need-upload-count">เลือกแล้ว ${n} / ${MAX_NEED_IMAGES} รูป</div>
            <div class="upload-hint need-upload-names">${names}</div>`;

    if (n >= MAX_NEED_IMAGES) {
        btnNeedPickImg.textContent = 'ครบ 3 รูปแล้ว';
        btnNeedPickImg.disabled = true;
    } else {
        btnNeedPickImg.textContent = `เพิ่มรูป (เหลือได้อีก ${MAX_NEED_IMAGES - n})`;
        btnNeedPickImg.disabled = false;
    }
}

function addNeedFilesFromPicker(incoming) {
    const arr = Array.from(incoming || []);
    if (!arr.length) return;

    const room = MAX_NEED_IMAGES - selectedFiles.length;
    if (room <= 0) {
        alert('อัปโหลดได้สูงสุด 3 รูป\nกด «นำออก» บนรูปเพื่อลบแล้วเลือกใหม่');
        fileInput.value = '';
        return;
    }

    const existing = new Set(selectedFiles.map(needImageSignature));
    const toPush = [];
    let skipped = 0;

    for (const f of arr) {
        if (toPush.length >= room) {
            break;
        }
        if (!f.type.startsWith('image/')) {
            alert('ข้ามไฟล์ที่ไม่ใช่รูป: ' + f.name);
            skipped++;
            continue;
        }
        if (f.size > MAX_NEED_IMAGE_BYTES) {
            alert('ไฟล์เกิน 5MB: ' + f.name);
            skipped++;
            continue;
        }
        const sig = needImageSignature(f);
        if (existing.has(sig)) {
            skipped++;
            continue;
        }
        existing.add(sig);
        toPush.push(f);
    }

    if (arr.length > room) {
        alert('เลือกครั้งนี้มีมากกว่าที่เหลือ — เพิ่มได้อีกสูงสุด ' + room + ' รูป (รวมไม่เกิน 3 รูป)');
    } else if (skipped > 0 && toPush.length === 0) {
        alert('ไม่มีไฟล์ที่เพิ่มได้ (ซ้ำ ชนิดไฟล์ หรือเกินขนาด)');
    }

    selectedFiles = selectedFiles.concat(toPush);
    syncNeedFileInput();
    updateNeedUploadChrome();
    renderPreviews();
    /* ห้าม fileInput.value = '' — จะล้างรายการไฟล์ก่อน submit ทำให้ item_image[] ไม่ถูกส่งขึ้นเซิร์ฟเวอร์ */
}

function removeNeedFileAt(index) {
    if (index < 0 || index >= selectedFiles.length) return;
    selectedFiles.splice(index, 1);
    syncNeedFileInput();
    updateNeedUploadChrome();
    renderPreviews();
}

const itemSectionGroup = document.getElementById('itemSectionGroup');

function updateVisibleItemGroups() {
    const selectedCategories = new Set(categoryChecks.filter(chk => chk.checked).map(chk => chk.value));
    // แสดง/ซ่อน section รายการสิ่งของทั้งหมดตามว่าเลือกหมวดหรือยัง
    if (itemSectionGroup) {
        itemSectionGroup.style.display = selectedCategories.size === 0 ? 'none' : 'block';
    }
    // กรองแสดงเฉพาะกลุ่มที่ตรงหมวดที่เลือก
    itemGroups.forEach((group) => {
        const cat = group.getAttribute('data-category') || '';
        group.style.display = selectedCategories.has(cat) ? 'block' : 'none';
    });
}

function updateSelectedItemCounter() {
    const selectedCount = itemChecks.filter(chk => chk.checked).length;
    if (selectedItemCount) {
        selectedItemCount.textContent = `เลือกแล้ว ${selectedCount}/5 รายการ`;
    }
}

function enforceMaxItemSelection(event) {
    const selected = itemChecks.filter(chk => chk.checked);
    if (selected.length > 5 && event && event.target) {
        event.target.checked = false;
        alert('เลือกรายการสิ่งของได้สูงสุด 5 รายการ');
    }
    updateSelectedItemCounter();
}

function updateTotal() {
    const g = parseFloat(goalAmount.value || 0);
    const p = periodSelect ? periodSelect.value : '';
    const periodText = p ? ' / ' + p : '';
    totalBox.textContent = "เป้าหมาย: " + g.toLocaleString('th-TH', { minimumFractionDigits: 0 }) + " บาท" + periodText;
}

goalAmount.addEventListener('input', updateTotal);
if (periodSelect) periodSelect.addEventListener('change', updateTotal);
categoryChecks.forEach((chk) => chk.addEventListener('change', updateVisibleItemGroups));
itemChecks.forEach((chk) => chk.addEventListener('change', enforceMaxItemSelection));

function renderPreviews() {
    previewList.innerHTML = '';
    if (!selectedFiles.length) return;

    selectedFiles.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = 'upload-preview-item';

        const img = document.createElement('img');
        img.className = 'upload-preview-img';
        img.alt = 'preview';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'upload-preview-remove';
        removeBtn.title = 'นำรูปนี้ออก';
        removeBtn.setAttribute('aria-label', 'นำรูปนี้ออก');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', () => removeNeedFileAt(index));

        const badge = document.createElement('span');
        badge.className = 'upload-preview-urgent';
        badge.textContent = 'ต้องการด่วน';
        if (!urgentCheckbox.checked) badge.style.display = 'none';

        const cap = document.createElement('div');
        cap.className = 'upload-preview-cap';
        cap.textContent = `รูปที่ ${index + 1}`;

        const reader = new FileReader();
        reader.onload = function(evt) {
            img.src = evt.target.result;
        };
        reader.readAsDataURL(file);

        item.appendChild(img);
        item.appendChild(removeBtn);
        item.appendChild(badge);
        item.appendChild(cap);
        previewList.appendChild(item);
    });
}

fileInput.addEventListener('change', function(e) {
    addNeedFilesFromPicker(e.target.files);
});

if (btnNeedPickImg) {
    btnNeedPickImg.addEventListener('click', () => fileInput.click());
}

const foundationNeedImageInput = document.getElementById('foundationNeedImageInput');
const btnFoundationNeedImg = document.getElementById('btnFoundationNeedImg');
const foundationNeedPreview = document.getElementById('foundationNeedPreview');

function clearFoundationNeedPreview() {
    if (foundationNeedPreview) {
        foundationNeedPreview.innerHTML = '';
    }
}

if (btnFoundationNeedImg && foundationNeedImageInput) {
    btnFoundationNeedImg.addEventListener('click', () => foundationNeedImageInput.click());
}

if (foundationNeedImageInput) {
    foundationNeedImageInput.addEventListener('change', function() {
        clearFoundationNeedPreview();
        const f = this.files && this.files[0];
        if (!f) {
            return;
        }
        if (!f.type.startsWith('image/')) {
            alert('กรุณาเลือกไฟล์รูปเท่านั้น');
            this.value = '';
            return;
        }
        if (f.size > MAX_NEED_IMAGE_BYTES) {
            alert('รูปมูลนิธิต้องไม่เกิน 5MB');
            this.value = '';
            return;
        }
        const wrap = document.createElement('div');
        wrap.className = 'upload-preview-item foundation-fdn-preview-item';
        const img = document.createElement('img');
        img.className = 'upload-preview-img';
        img.alt = '';
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'upload-preview-remove';
        rm.title = 'นำรูปมูลนิธิออก';
        rm.setAttribute('aria-label', 'นำรูปมูลนิธิออก');
        rm.textContent = '×';
        rm.addEventListener('click', () => {
            foundationNeedImageInput.value = '';
            clearFoundationNeedPreview();
        });
        const reader = new FileReader();
        reader.onload = (evt) => { img.src = evt.target.result; };
        reader.readAsDataURL(f);
        wrap.appendChild(img);
        wrap.appendChild(rm);
        foundationNeedPreview.appendChild(wrap);
    });
}

urgentCheckbox.addEventListener('change', renderPreviews);

const needForm = document.querySelector('form[method="post"][enctype="multipart/form-data"]');
if (needForm && fileInput) {
    needForm.addEventListener('submit', function() {
        if (selectedFiles.length) syncNeedFileInput();
    });
}

updateNeedUploadChrome();
updateVisibleItemGroups();
updateSelectedItemCounter();
updateTotal();
</script>

</body>
</html>