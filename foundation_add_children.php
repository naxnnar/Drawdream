<?php
// foundation_add_children.php — มูลนิธิเพิ่มโปรไฟล์เด็ก

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน add children

session_start();
include 'db.php';

// ให้เข้าได้เฉพาะ foundation
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$sql = "SELECT * FROM `foundation_profile` WHERE `user_id` = ?";
$stmtFP = $conn->prepare($sql);
$stmtFP->bind_param("i", $_SESSION['user_id']);
$stmtFP->execute();

$result = $stmtFP->get_result(); 

if ($fetchArr = $result->fetch_assoc()) {
    $f_id   = $fetchArr['foundation_id']; 
    $f_name = $fetchArr['foundation_name'];
} else {
    $f_id   = 0;
    $f_name = "ไม่พบชื่อมูลนิธิ";
}

// ─── Auto-migrate: เพิ่มคอลัมน์ที่โค้ดใช้งานจริงในตาราง foundation_children ───────────────
// หมายเหตุ: ไม่ใช้ AFTER เพื่อลดปัญหาเมื่อ schema เดิมไม่ตรงกัน
$needed_columns = [
    'foundation_name' => "ALTER TABLE foundation_children ADD COLUMN foundation_name VARCHAR(255) NULL",
    'child_name' => "ALTER TABLE foundation_children ADD COLUMN child_name VARCHAR(255) NULL",
    'birth_date' => "ALTER TABLE foundation_children ADD COLUMN birth_date DATE NULL",
    'age' => "ALTER TABLE foundation_children ADD COLUMN age INT NULL",
    'education' => "ALTER TABLE foundation_children ADD COLUMN education VARCHAR(255) NULL",
    'dream' => "ALTER TABLE foundation_children ADD COLUMN dream VARCHAR(255) NULL",
    'likes' => "ALTER TABLE foundation_children ADD COLUMN likes VARCHAR(100) NULL",
    'wish' => "ALTER TABLE foundation_children ADD COLUMN wish VARCHAR(255) NULL",
    'wish_cat' => "ALTER TABLE foundation_children ADD COLUMN wish_cat VARCHAR(100) NULL",
    'bank_name' => "ALTER TABLE foundation_children ADD COLUMN bank_name VARCHAR(100) NULL",
    'child_bank' => "ALTER TABLE foundation_children ADD COLUMN child_bank VARCHAR(100) NULL",
    'status' => "ALTER TABLE foundation_children ADD COLUMN status VARCHAR(100) NULL",
    'photo_child' => "ALTER TABLE foundation_children ADD COLUMN photo_child VARCHAR(255) NULL",
    'approve_profile' => "ALTER TABLE foundation_children ADD COLUMN approve_profile VARCHAR(50) DEFAULT 'รอดำเนินการ'",
    'pending_edit_json' => "ALTER TABLE foundation_children ADD COLUMN pending_edit_json LONGTEXT NULL",
    'reject_reason' => "ALTER TABLE foundation_children ADD COLUMN reject_reason TEXT NULL",
    'approve_at' => "ALTER TABLE foundation_children ADD COLUMN approve_at DATETIME NULL",
    'update_text' => "ALTER TABLE foundation_children ADD COLUMN update_text LONGTEXT NULL",
    'update_at' => "ALTER TABLE foundation_children ADD COLUMN update_at DATETIME NULL",
    'update_images' => "ALTER TABLE foundation_children ADD COLUMN update_images LONGTEXT NULL",
    'deleted_at' => "ALTER TABLE foundation_children ADD COLUMN deleted_at DATETIME NULL",
    'delete_reason' => "ALTER TABLE foundation_children ADD COLUMN delete_reason TEXT NULL",
];
foreach ($needed_columns as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}
$has_birth_date_column = true; // migration ensures it exists
$dreamChoices = ['คุณหมอ', 'คุณครู', 'พยาบาล', 'ทหาร', 'ตำรวจ', 'นักบิน', 'นักร้อง', 'นักเต้น', 'จิตรกร', 'แม่ค้า'];
$editChildId = (int)($_GET['edit'] ?? $_POST['child_id'] ?? 0);
$isEditForm = false;
$editChild = null;

if ($editChildId > 0) {
    $stmtEdit = $conn->prepare("SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? AND deleted_at IS NULL LIMIT 1");
    if ($stmtEdit) {
        $stmtEdit->bind_param("ii", $editChildId, $f_id);
        $stmtEdit->execute();
        $editChild = $stmtEdit->get_result()->fetch_assoc();
    }
    if ($editChild) {
        if (!empty($editChild['pending_edit_json'])) {
            $pj = json_decode((string)$editChild['pending_edit_json'], true);
            if (is_array($pj)) {
                foreach (['child_name', 'birth_date', 'age', 'education', 'dream', 'likes', 'wish', 'wish_cat', 'bank_name', 'child_bank', 'photo_child'] as $k) {
                    if (array_key_exists($k, $pj)) {
                        $editChild[$k] = $pj[$k];
                    }
                }
            }
        }

        require_once __DIR__ . '/includes/child_sponsorship.php';
        $lockAp = (string)($editChild['approve_profile'] ?? '');
        $lockCycle = drawdream_child_cycle_total($conn, $editChildId, $editChild);
        $lockTarget = drawdream_child_cycle_target_amount($conn, $editChildId);
        if (in_array($lockAp, ['อนุมัติ', 'กำลังดำเนินการ'], true) && $lockCycle >= $lockTarget) {
            header('Location: children_.php?msg=' . rawurlencode('เด็กที่ได้รับการอุปการะครบยอดในเดือนนี้ ไม่สามารถแก้ไขโปรไฟล์ได้'));
            exit;
        }
        $isEditForm = true;
    } else {
        $editChildId = 0;
    }
}

if ($isEditForm && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $_POST['child_name'] = (string)($editChild['child_name'] ?? '');
    $_POST['birth_date'] = (string)($editChild['birth_date'] ?? '');
    $_POST['education'] = (string)($editChild['education'] ?? '');
    $_POST['likes'] = (string)($editChild['likes'] ?? '');
    $_POST['wish'] = (string)($editChild['wish'] ?? '');
    $_POST['wish_cat'] = (string)($editChild['wish_cat'] ?? '');
    $_POST['bank_name'] = (string)($editChild['bank_name'] ?? '');
    $_POST['child_bank'] = (string)($editChild['child_bank'] ?? '');
    $dreamValue = trim((string)($editChild['dream'] ?? ''));
    if ($dreamValue !== '' && !in_array($dreamValue, $dreamChoices, true)) {
        $_POST['dream'] = 'อื่นๆ';
        $_POST['dream_other'] = $dreamValue;
    } else {
        $_POST['dream'] = $dreamValue;
    }
    $_POST['policy_consent'] = '1';
}

if (isset($_POST['submit'])) {
    $child_name    = trim($_POST['child_name'] ?? '');
    $birth_date_raw = trim($_POST['birth_date'] ?? '');
    $age           = 0;
    $education     = trim($_POST['education'] ?? '');
    $dream         = trim($_POST['dream'] ?? '');
    $dream_other   = trim($_POST['dream_other'] ?? '');
    $likes         = trim($_POST['likes'] ?? '');
    $wish          = trim($_POST['wish'] ?? '');
    $wish_cat      = trim($_POST['wish_cat'] ?? '');
    $bank_name     = trim($_POST['bank_name'] ?? '');
    $child_bank    = trim($_POST['child_bank'] ?? '');
    $status        = "รออุปการะ";
    $approve_status = "รอดำเนินการ";
    $policy_consent = isset($_POST['policy_consent']) && $_POST['policy_consent'] === '1';

    if (!$policy_consent) {
        echo "<script>alert('กรุณายินยอมนโยบายก่อนบันทึกข้อมูล'); history.back();</script>";
        exit();
    }

    // ถ้าเลือก "อื่นๆ" ให้บันทึกค่าที่ระบุเองแทน
    if ($dream === 'อื่นๆ' && $dream_other !== '') {
        $dream = $dream_other;
    }

    if ($wish_cat === '') {
        echo "<script>alert('กรุณาเลือกหมวดหมู่สิ่งที่ต้องการ'); history.back();</script>";
        exit();
    }
    if ($wish === '') {
        echo "<script>alert('กรุณาเลือกรายการสิ่งของ หรือระบุเอง'); history.back();</script>";
        exit();
    }

    if (!preg_match('/^\d{10}$/', $child_bank)) {
        echo "<script>alert('เลขบัญชีต้องเป็นตัวเลข 10 หลัก'); history.back();</script>";
        exit();
    }

    // คำนวณอายุจากวันเกิด
    $dob = DateTime::createFromFormat('Y-m-d', $birth_date_raw);
    $today = new DateTime('today');
    if (!$dob || $dob->format('Y-m-d') !== $birth_date_raw) {
        echo "<script>alert('กรุณาเลือกวันเกิดให้ถูกต้อง'); history.back();</script>";
        exit();
    }
    if ($dob > $today) {
        echo "<script>alert('วันเกิดต้องไม่เป็นวันที่ในอนาคต'); history.back();</script>";
        exit();
    }
    $age = (int)$today->diff($dob)->y;

    if ($age < 6 || $age > 18) {
        echo "<script>alert('อายุ {$age} ปี ไม่อยู่ในเกณฑ์ที่รับได้ (6-18 ปี) กรุณาตรวจสอบวันเกิด'); history.back();</script>";
        exit();
    }

    $allowed   = ['jpg','jpeg','png','gif','webp'];
    $newName = (string)($editChild['photo_child'] ?? '');
    if (isset($_FILES['photo_child']) && (int)($_FILES['photo_child']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int)$_FILES['photo_child']['error'] !== 0) {
            echo "<script>alert('อัปโหลดรูปเด็กไม่สำเร็จ'); history.back();</script>";
            exit();
        }
        $imageName = $_FILES['photo_child']['name'];
        $tmpName   = $_FILES['photo_child']['tmp_name'];
        $ext       = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            echo "<script>alert('อนุญาตเฉพาะไฟล์รูปภาพเท่านั้น'); history.back();</script>";
            exit();
        }
        $newName = "child_" . time() . "." . $ext;
        if (!move_uploaded_file($tmpName, "uploads/childern/" . $newName)) {
            echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
            exit();
        }
    } elseif (!$isEditForm) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพเด็ก'); history.back();</script>";
        exit();
    }

    if ($isEditForm && $editChildId > 0 && $editChild) {
        $currentAp = (string)($editChild['approve_profile'] ?? '');
        $isPublished = in_array($currentAp, ['อนุมัติ', 'กำลังดำเนินการ'], true);
        if ($isPublished) {
            $payload = [
                'child_name' => $child_name,
                'birth_date' => $birth_date_raw,
                'age' => $age,
                'education' => $education,
                'dream' => $dream,
                'likes' => $likes,
                'wish' => $wish,
                'wish_cat' => $wish_cat,
                'bank_name' => $bank_name,
                'child_bank' => $child_bank,
                'photo_child' => $newName,
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $stEd = $conn->prepare("UPDATE foundation_children SET pending_edit_json=?, approve_profile='กำลังดำเนินการ', reject_reason=NULL WHERE child_id=? AND foundation_id=?");
            if (!$stEd) {
                die("MySQL Error: " . $conn->error);
            }
            $stEd->bind_param('sii', $json, $editChildId, $f_id);
            if ($stEd->execute()) {
                header('Location: children_.php?msg=' . urlencode('ส่งคำขอแก้ไขให้แอดมินตรวจสอบแล้ว — ข้อมูลที่แสดงต่อสาธารณะยังเป็นชุดเดิมจนกว่าจะได้รับการอนุมัติ'));
                exit();
            }
            die("MySQL Error: " . $stEd->error);
        }

        $stEd = $conn->prepare(
            "UPDATE foundation_children
             SET child_name=?, birth_date=?, age=?, education=?, dream=?, likes=?, wish=?, wish_cat=?, bank_name=?, child_bank=?, photo_child=?, approve_profile='รอดำเนินการ', reject_reason=NULL, pending_edit_json=NULL
             WHERE child_id=? AND foundation_id=?"
        );
        if (!$stEd) {
            die("MySQL Error: " . $conn->error);
        }
        $stEd->bind_param(
            'ssissssssssii',
            $child_name,
            $birth_date_raw,
            $age,
            $education,
            $dream,
            $likes,
            $wish,
            $wish_cat,
            $bank_name,
            $child_bank,
            $newName,
            $editChildId,
            $f_id
        );
        if ($stEd->execute()) {
            header('Location: children_.php?msg=' . urlencode('แก้ไขโปรไฟล์เด็กสำเร็จ'));
            exit();
        }
        die("MySQL Error: " . $stEd->error);
    } else {
        if ($has_birth_date_column) {
            $sql = "INSERT INTO foundation_children (foundation_id, foundation_name, child_name, birth_date, age, education, dream, likes, wish, wish_cat, bank_name, child_bank, status, photo_child, approve_profile)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'isssi' . str_repeat('s', 10),
                $f_id,
                $f_name,
                $child_name,
                $birth_date_raw,
                $age,
                $education,
                $dream,
                $likes,
                $wish,
                $wish_cat,
                $bank_name,
                $child_bank,
                $status,
                $newName,
                $approve_status
            );
        } else {
            $sql = "INSERT INTO foundation_children (foundation_id, foundation_name, child_name, age, education, dream, likes, wish, wish_cat, bank_name, child_bank, status, photo_child, approve_profile)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('issi' . str_repeat('s', 10), $f_id, $f_name, $child_name, $age, $education, $dream, $likes, $wish, $wish_cat, $bank_name, $child_bank, $status, $newName, $approve_status);
        }

        if ($stmt->execute()) {
            $newChildId = (int)$conn->insert_id;
            if ($newChildId > 0) {
                require_once __DIR__ . '/includes/notification_audit.php';
                drawdream_record_foundation_submitted_child($conn, (int)$_SESSION['user_id'], $newChildId, $child_name);
                drawdream_notify_admins_child_submitted($conn, $newChildId, $child_name, $f_name);
            }
            echo "<script>alert('เพิ่มข้อมูลเด็กสำเร็จ'); window.location='children_.php';</script>";
            exit();
        }
        die("MySQL Error: " . $stmt->error);
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
<meta charset="UTF-8">
<title><?= $isEditForm ? 'แก้ไขโปรไฟล์เด็ก' : 'สร้างโปรไฟล์เด็ก' ?> - Children Profile</title>
<link rel="stylesheet" href="css/navbar.css">
<link rel="stylesheet" href="css/children.css">
<link rel="stylesheet" href="css/policy_consent.css">
</head>
<body>

<div id="topAlert" class="top-alert"></div>

<?php include 'navbar.php'; ?>

<div class="form-container">
    <!-- ── ซ้าย: preview รูป ──────────────────── -->
    <div class="left-box">
        <h2 class="left-panel-title"><?= $isEditForm ? 'แก้ไขโปรไฟล์เด็ก' : 'สร้างโปรไฟล์เด็ก' ?></h2>
        <p class="left-foundation-name">มูลนิธิ <?php echo htmlspecialchars($f_name); ?></p>
        <div class="upload-preview" id="preview-container" onclick="document.getElementById('photo_child_input').click()">
            <?php if ($isEditForm && !empty($editChild['photo_child'])): ?>
                <img src="uploads/childern/<?= htmlspecialchars((string)$editChild['photo_child']) ?>" alt="">
            <?php else: ?>
                <span id="preview-text">ตัวอย่างรูปภาพ</span>
            <?php endif; ?>
        </div>
        <div class="left-info-card" style="text-align:left;">
            <h3>รูปภาพเด็ก</h3>
            <input type="file" id="photo_child_input" name="photo_child" form="mainForm" accept="image/*" <?= $isEditForm ? '' : 'required' ?> onchange="previewImage(this)" style="display:block;">
        </div>
        <div class="left-info-card">
            <h3>ยินยอมนโยบาย</h3>
            <label class="consent-check">
                <input type="checkbox" id="policy_consent" name="policy_consent" value="1" form="mainForm" <?= !empty($_POST['policy_consent']) ? 'checked' : '' ?>>
                <button type="button" class="consent-link consent-link--btn" id="openPolicyModal">นโยบายความเป็นส่วนตัว</button>
            </label>
            <div class="consent-note">**ต้องกดยินยอมก่อนจึงจะบันทึกโปรไฟล์ได้**</div>
        </div>
    </div>

    <!-- ── ขวา: ฟอร์ม ──────────────────────────── -->
    <div class="right-box">
        <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
            <?php if ($isEditForm): ?>
                <input type="hidden" name="child_id" value="<?= (int)$editChildId ?>">
            <?php endif; ?>
            <div class="grid-inputs">

                <!-- ชื่อเล่นเด็ก -->
                <div class="field-group">
                    <label>ชื่อเล่นเด็ก</label>
                    <input type="text" name="child_name" placeholder="ตัวอย่าง: น้องฟ้า" required value="<?= htmlspecialchars((string)($_POST['child_name'] ?? '')) ?>">
                </div>

                <!-- วันเกิด -->
                <div class="field-group">
                    <label>วันเกิดเด็ก</label>
                    <div class="date-input-wrap">
                        <?php $birthDateValue = (string)($_POST['birth_date'] ?? ''); ?>
                        <?php $birthDateDisplay = ''; if ($birthDateValue !== '') { $tmpTs = strtotime($birthDateValue); if ($tmpTs !== false) { $birthDateDisplay = date('n/j/Y', $tmpTs); } } ?>
                        <input type="text" id="birth_date_display" placeholder="m/d/y" inputmode="numeric" maxlength="10" required value="<?= htmlspecialchars($birthDateDisplay) ?>" oninput="handleBirthDateInput()" onblur="normalizeBirthDateInput()">
                        <button type="button" class="date-picker-btn" aria-label="เลือกวันเกิด" tabindex="-1">📅</button>
                        <input type="date" id="birth_date_picker" class="native-date-picker" max="<?php echo date('Y-m-d'); ?>" value="<?= htmlspecialchars($birthDateValue) ?>" onchange="handleBirthDatePickerChange()">
                    </div>
                    <input type="hidden" id="birth_date" name="birth_date" value="<?= htmlspecialchars($birthDateValue) ?>">
                </div>

                <!-- อายุ -->
                <div class="field-group">
                    <label>อายุ (คำนวณอัตโนมัติ)</label>
                    <input type="number" id="age" name="age_preview" readonly style="background:#f5f5f5;">
                </div>

                <!-- ระดับการศึกษา -->
                <div class="field-group">
                    <label>ระดับการศึกษา</label>
                    <input type="text" id="education" name="education" placeholder="ตัวอย่าง: ม.3" required value="<?= htmlspecialchars((string)($_POST['education'] ?? '')) ?>">
                    <!-- <small>ระบบแนะนำตามอายุ แก้ไขได้</small> -->
                </div>

                <!-- ความฝัน (dropdown) -->
                <div class="field-group">
                    <label>ความฝัน</label>
                    <select name="dream" id="dream_select" class="dream-sel" required onchange="toggleDreamOther()">
                        <option value="">— เลือกความฝัน —</option>
                        <option value="คุณหมอ" <?= (($_POST['dream'] ?? '') === 'คุณหมอ') ? 'selected' : '' ?>>👨‍⚕️ คุณหมอ</option>
                        <option value="คุณครู" <?= (($_POST['dream'] ?? '') === 'คุณครู') ? 'selected' : '' ?>>👩‍🏫 คุณครู</option>
                        <option value="พยาบาล" <?= (($_POST['dream'] ?? '') === 'พยาบาล') ? 'selected' : '' ?>>💊 พยาบาล</option>
                        <option value="ทหาร" <?= (($_POST['dream'] ?? '') === 'ทหาร') ? 'selected' : '' ?>>🪖 ทหาร</option>
                        <option value="ตำรวจ" <?= (($_POST['dream'] ?? '') === 'ตำรวจ') ? 'selected' : '' ?>>👮 ตำรวจ</option>
                        <option value="นักบิน" <?= (($_POST['dream'] ?? '') === 'นักบิน') ? 'selected' : '' ?>>✈️ นักบิน</option>
                        <option value="นักร้อง" <?= (($_POST['dream'] ?? '') === 'นักร้อง') ? 'selected' : '' ?>>🎤 นักร้อง</option>
                        <option value="นักเต้น" <?= (($_POST['dream'] ?? '') === 'นักเต้น') ? 'selected' : '' ?>>💃 นักเต้น</option>
                        <option value="จิตรกร" <?= (($_POST['dream'] ?? '') === 'จิตรกร') ? 'selected' : '' ?>>🎨 จิตรกร</option>
                        <option value="แม่ค้า" <?= (($_POST['dream'] ?? '') === 'แม่ค้า') ? 'selected' : '' ?>>🛒 แม่ค้า</option>
                        <option value="อื่นๆ" <?= (($_POST['dream'] ?? '') === 'อื่นๆ') ? 'selected' : '' ?>>✏️ อื่นๆ (ระบุเอง)</option>
                    </select>
                    <div class="dream-other-wrap" id="dream_other_wrap">
                        <input type="text" id="dream_other_input" name="dream_other" placeholder="ระบุความฝัน เช่น นักพัฒนาเกม" value="<?= htmlspecialchars((string)($_POST['dream_other'] ?? '')) ?>">
                    </div>
                </div>

                <!-- สิ่งที่ชอบ (ใหม่) -->
                <div class="field-group">
                    <label>สิ่งที่ชอบ</label>
                    <input type="text" name="likes" id="likes_input" placeholder="ตัวอย่าง: วาดรูป, ฟุตบอล" maxlength="100" value="<?= htmlspecialchars((string)($_POST['likes'] ?? '')) ?>">
                </div>

                <!-- หมวดหมู่สิ่งของที่ต้องการ (tag pills) -->
                <div class="field-group full-width">
                    <label>หมวดหมู่สิ่งที่ต้องการ</label>
                    <div class="cat-tags" id="catTags">
                        <span class="cat-tag" data-val="ของเล่น"><span class="cat-icon">🎮</span> ของเล่น</span>
                        <span class="cat-tag" data-val="แฟชั่น"><span class="cat-icon">👟</span>แฟชั่น  </span>
                        <span class="cat-tag" data-val="มื้อกลางวัน"><span class="cat-icon">🍜</span>อาหาร &amp; ขนม</span>
                        <span class="cat-tag" data-val="ไอทีและเกม"><span class="cat-icon">📱</span>ไอที &amp; เกม</span>
                        <span class="cat-tag" data-val="ศิลปะและงานอดิเรก"><span class="cat-icon">🎨</span>ศิลปะ &amp; งานอดิเรก</span>
                        <span class="cat-tag" data-val="อุปกรณ์การเรียน"><span class="cat-icon">📚</span>อุปกรณ์การเรียน</span>
                    </div>
                    <input type="hidden" name="wish_cat" id="wish_cat_input" value="<?= htmlspecialchars((string)($_POST['wish_cat'] ?? '')) ?>">
                    <!-- <small>เลือกได้ 1 หมวด</small> -->
                </div>

                <div class="field-group full-width">
                    <label>รายการสิ่งของตามหมวด</label>
                    <div class="item-select-wrap">
                        <select id="wish_item_select">
                            <option value="">— เลือกหมวดหมู่ก่อน —</option>
                        </select>
                        <div class="wish-custom-wrap" id="wish_custom_wrap">
                            <input type="text" id="wish_item_custom" placeholder="ระบุสิ่งของเอง เช่น หูฟังบลูทูธ">
                        </div>
                        <!-- Size picker: เสื้อผ้า S-XL -->
                        <div class="size-pick-wrap" id="clothing_size_wrap" style="display:none">
                            <span class="size-pick-label">📏 ไซส์เสื้อผ้า</span>
                            <div class="size-btn-group" id="clothing_size_btns">
                                <button type="button" class="size-btn" data-size="S"  onclick="selectSize('clothing','S')">S</button>
                                <button type="button" class="size-btn" data-size="M"  onclick="selectSize('clothing','M')">M</button>
                                <button type="button" class="size-btn" data-size="L"  onclick="selectSize('clothing','L')">L</button>
                                <button type="button" class="size-btn" data-size="XL" onclick="selectSize('clothing','XL')">XL</button>
                            </div>
                            <input type="hidden" id="clothing_size_hidden">
                        </div>
                        <!-- Size picker: รองเท้า 30-44 -->
                        <div class="size-pick-wrap" id="shoe_size_wrap" style="display:none">
                            <span class="size-pick-label">👟 เบอร์รองเท้า</span>
                            <div class="size-btn-group" id="shoe_type_btns" style="margin-bottom:8px;">
                                <button type="button" class="size-btn" data-type="รองเท้าผ้าใบ" onclick="selectShoeType('รองเท้าผ้าใบ')">รองเท้าผ้าใบ</button>
                                <button type="button" class="size-btn" data-type="รองเท้าแตะ" onclick="selectShoeType('รองเท้าแตะ')">รองเท้าแตะ</button>
                            </div>
                            <div class="size-btn-group" id="shoe_size_btns"></div>
                            <input type="hidden" id="shoe_type_hidden">
                            <input type="hidden" id="shoe_size_hidden">
                        </div>
                        <small class="item-hint">ถ้าต้องการกรอกเอง ให้เลือก อื่นๆ (ระบุเอง)</small>
                    </div>
                    <input type="hidden" name="wish" id="wish_hidden_input" required value="<?= htmlspecialchars((string)($_POST['wish'] ?? '')) ?>">
                </div>

                <!-- ธนาคาร (dropdown พร้อมโลโก้) -->
                <div class="field-group">
                    <label>ธนาคาร</label>
                    <div class="bank-select-wrapper">
                        <img id="bank-logo" class="bank-logo-preview" src="" alt="">
                        <select name="bank_name" id="bank_select" required onchange="updateBankLogo()">
                            <option value="">— เลือกธนาคาร —</option>
                            <option value="กสิกรไทย" data-logo="img/bank-kbank.png" <?= (($_POST['bank_name'] ?? '') === 'กสิกรไทย') ? 'selected' : '' ?>>กสิกรไทย (KBank)</option>
                            <option value="กรุงเทพ" data-logo="img/bank-bbl.png" <?= (($_POST['bank_name'] ?? '') === 'กรุงเทพ') ? 'selected' : '' ?>>กรุงเทพ (BBL)</option>
                            <option value="กรุงไทย" data-logo="img/bank-ktb.png" <?= (($_POST['bank_name'] ?? '') === 'กรุงไทย') ? 'selected' : '' ?>>กรุงไทย (KTB)</option>
                            <option value="กรุงศรี" data-logo="img/bank-bay.png" <?= (($_POST['bank_name'] ?? '') === 'กรุงศรี') ? 'selected' : '' ?>>กรุงศรี (BAY)</option>
                            <option value="ไทยพาณิชย์" data-logo="img/bank-scb.png" <?= (($_POST['bank_name'] ?? '') === 'ไทยพาณิชย์') ? 'selected' : '' ?>>ไทยพาณิชย์ (SCB)</option>
                            <option value="ออมสิน" data-logo="img/bank-gsb.png" <?= (($_POST['bank_name'] ?? '') === 'ออมสิน') ? 'selected' : '' ?>>ออมสิน (GSB)</option>
                        </select>
                    </div>
                </div>

                <!-- เลขบัญชี -->
                <div class="field-group">
                    <label>เลขบัญชีธนาคาร</label>
                    <input type="text" id="child_bank_input" name="child_bank" placeholder="ตัวเลข 10 หลัก" inputmode="numeric" maxlength="10" pattern="\d{10}" required value="<?= htmlspecialchars((string)($_POST['child_bank'] ?? '')) ?>">
                </div>

            </div><!-- end grid -->

            <button type="submit" name="submit" class="btn-submit" id="submitBtn" <?= !empty($_POST['policy_consent']) ? '' : 'disabled' ?>><?= $isEditForm ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
        </form>
    </div>
</div>

<script>
const IS_EDIT_FORM = <?= $isEditForm ? 'true' : 'false' ?>;
const PRESET_WISH_CAT = <?= json_encode((string)($_POST['wish_cat'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
const PRESET_WISH = <?= json_encode((string)($_POST['wish'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

function showTopAlert(message) {
    const alertEl = document.getElementById('topAlert');
    alertEl.textContent = message;
    alertEl.classList.add('show');
    clearTimeout(showTopAlert._timer);
    showTopAlert._timer = setTimeout(() => alertEl.classList.remove('show'), 2600);
}

const WISH_ITEMS_BY_CAT = {
    'ของเล่น': [
        'Pop-it / ของเล่นคลายเครียด', 'สไลม์', 'สกุชชี่', 'เลโก้',
        'โมเดล', 'ตุ๊กตา', 'การ์ด Uno / บอร์ดเกม'
    ],
    'แฟชั่น': [
        'เสื้อผ้า', 'ชุดนักเรียน', 'เครื่องประดับ',
        'รองเท้า', 'กระเป๋าเป้'
    ],
    'มื้อกลางวัน': [
        'พิซซ่า', 'ขนมนำเข้า', 'ไก่ทอด',
        'เค้ก', 'โดนัท', 'ไอศกรีม'
    ],
    'ไอทีและเกม': [
        'เคสมือถือ', 'หูฟังบลูทูธ', 'สายชาร์จ',
        'บัตรเติมเกม', 'พาวเวอร์แบงค์'
    ],
    'ศิลปะและงานอดิเรก': [
        'สีไม้/สีเทียน', 'หนังสือ',
        'ชุด DIY ', 'ลูกบาส', 'ลูกบอล','จักรยาน'
    ],
    'อุปกรณ์การเรียน': [
        'สมุดโน้ต', 'ชุดดินสอ-ปากกา', 'กระเป๋านักเรียน',
        'อุปกรณ์เรขาคณิต', 'แท็บเล็ตเพื่อการเรียน'
    ]
};

const CLOTHING_ITEMS = new Set(['เสื้อผ้า', 'ชุดนักเรียน']);
const SHOE_ITEMS     = new Set(['รองเท้า']);

// สร้างปุ่มเบอร์รองเท้า 30-44
(function initShoeSizeBtns() {
    const container = document.getElementById('shoe_size_btns');
    if (!container) return;
    for (let n = 30; n <= 44; n++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'size-btn';
        btn.dataset.size = String(n);
        btn.textContent = String(n);
        btn.onclick = () => selectSize('shoe', String(n));
        container.appendChild(btn);
    }
})();

// ── Preview รูปภาพเมื่อเลือกไฟล์ ──────────────────────
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('preview-container').innerHTML = `<img src="${e.target.result}">`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function toggleDreamOther() {
    const dreamSelect = document.getElementById('dream_select');
    const wrap = document.getElementById('dream_other_wrap');
    const otherInput = document.getElementById('dream_other_input');
    if (dreamSelect.value === 'อื่นๆ') {
        wrap.style.display = 'block';
        otherInput.required = true;
    } else {
        wrap.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}

// ── คำนวณอายุ + แนะนำชั้นเรียน ────────────────────────
let educationManuallyEdited = false;
document.getElementById('education').addEventListener('input', () => { educationManuallyEdited = true; });

function parseBirthDateDisplay(value) {
    const match = value.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{1,4})$/);
    if (!match) return null;

    let month = parseInt(match[1], 10);
    let day = parseInt(match[2], 10);
    let year = parseInt(match[3], 10);

    if (match[3].length === 2) {
        year += 2000;
    }

    if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1900) {
        return null;
    }

    const candidate = new Date(year, month - 1, day);
    if (
        candidate.getFullYear() !== year ||
        candidate.getMonth() !== month - 1 ||
        candidate.getDate() !== day
    ) {
        return null;
    }

    return {
        year,
        month,
        day,
        iso: `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`
    };
}

function handleBirthDateInput() {
    const displayInput = document.getElementById('birth_date_display');
    displayInput.value = displayInput.value.replace(/[^\d/]/g, '').slice(0, 10);
    normalizeBirthDateInput(false);
}

function formatIsoToDisplay(isoDate) {
    if (!isoDate) return '';
    const parts = isoDate.split('-');
    if (parts.length !== 3) return '';
    return `${parseInt(parts[1], 10)}/${parseInt(parts[2], 10)}/${parts[0]}`;
}

function handleBirthDatePickerChange() {
    const picker = document.getElementById('birth_date_picker');
    const displayInput = document.getElementById('birth_date_display');
    const hiddenInput = document.getElementById('birth_date');

    if (!picker.value) {
        displayInput.value = '';
        hiddenInput.value = '';
        syncAgeAndEducation();
        return;
    }

    displayInput.value = formatIsoToDisplay(picker.value);
    hiddenInput.value = picker.value;
    syncAgeAndEducation();
}

function normalizeBirthDateInput(showError = false) {
    const displayInput = document.getElementById('birth_date_display');
    const hiddenInput = document.getElementById('birth_date');
    const pickerInput = document.getElementById('birth_date_picker');
    const parsed = parseBirthDateDisplay(displayInput.value);

    if (!displayInput.value.trim()) {
        hiddenInput.value = '';
        pickerInput.value = '';
        syncAgeAndEducation();
        return;
    }

    if (!parsed) {
        hiddenInput.value = '';
        pickerInput.value = '';
        syncAgeAndEducation();
        if (showError) {
            showTopAlert('กรุณากรอกวันเกิดเป็นรูปแบบ m/d/y');
        }
        return;
    }

    hiddenInput.value = parsed.iso;
    pickerInput.value = parsed.iso;
    syncAgeAndEducation();
}

function getSuggestedEducation(age) {
    if (age <= 5)  return 'อนุบาล';
    if (age === 6) return 'ป.1';
    if (age === 7) return 'ป.2';
    if (age === 8) return 'ป.3';
    if (age === 9) return 'ป.4';
    if (age === 10) return 'ป.5';
    if (age === 11) return 'ป.6';
    if (age === 12) return 'ม.1';
    if (age === 13) return 'ม.2';
    if (age === 14) return 'ม.3';
    if (age === 15) return 'ม.4';
    if (age === 16) return 'ม.5';
    if (age === 17) return 'ม.6';
    return 'อุดมศึกษา/อาชีวะ';
}

function syncAgeAndEducation() {
    const bd    = document.getElementById('birth_date');
    const ageEl = document.getElementById('age');
    const edu   = document.getElementById('education');
    const disp  = document.getElementById('birth_date_display');
    if (!bd.value) { ageEl.value = ''; return; }
    const today = new Date(), dob = new Date(bd.value + 'T00:00:00');
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    if (age < 0) { ageEl.value = ''; return; }
    if (age < 6 || age > 18) {
        showTopAlert(`อายุ ${age} ปี ไม่อยู่ในเกณฑ์ที่รับได้ (6–18 ปี) กรุณาตรวจสอบวันเกิด`);
        bd.value = '';
        document.getElementById('birth_date_picker').value = '';
        disp.value = '';
        disp.closest('.field-group').classList.add('has-error');
        ageEl.value = '';
        return;
    }
    disp.closest('.field-group').classList.remove('has-error');
    ageEl.value = age;
    if (!educationManuallyEdited || !edu.value.trim()) edu.value = getSuggestedEducation(age);
}

// ── Bank logo preview ──────────────────────────────────
function updateBankLogo() {
    const sel  = document.getElementById('bank_select');
    const logo = document.getElementById('bank-logo');
    const opt  = sel.options[sel.selectedIndex];
    const url  = opt ? opt.dataset.logo : '';
    if (url) {
        logo.src   = url;
        logo.style.display = 'block';
        sel.style.paddingLeft = '54px';
    } else {
        logo.style.display = 'none';
        sel.style.paddingLeft = '14px';
    }
}

// ── Category tag pills (single-select) ────────────────
document.querySelectorAll('.cat-tag').forEach(tag => {
    tag.addEventListener('click', () => {
        document.querySelectorAll('.cat-tag').forEach(t => t.classList.remove('active'));
        tag.classList.add('active');
        document.getElementById('wish_cat_input').value = tag.dataset.val;
        loadWishItemsByCategory(tag.dataset.val);
    });
});

function resetSizePickers() {
    ['clothing', 'shoe'].forEach(type => {
        const wrap   = document.getElementById(type + '_size_wrap');
        const hidden = document.getElementById(type + '_size_hidden');
        if (wrap)   { wrap.style.display = 'none'; wrap.classList.remove('has-error'); }
        if (hidden) hidden.value = '';
        document.querySelectorAll(`#${type}_size_btns .size-btn`).forEach(b => b.classList.remove('active'));
    });
    document.querySelectorAll('#shoe_type_btns .size-btn').forEach(b => b.classList.remove('active'));
    const shoeType = document.getElementById('shoe_type_hidden');
    if (shoeType) shoeType.value = '';
}

function loadWishItemsByCategory(category) {
    const select      = document.getElementById('wish_item_select');
    const customWrap  = document.getElementById('wish_custom_wrap');
    const customInput = document.getElementById('wish_item_custom');
    const wishHidden  = document.getElementById('wish_hidden_input');

    select.innerHTML = '<option value="">— เลือกรายการสิ่งของ —</option>';
    wishHidden.value = '';
    customInput.value = '';
    customWrap.style.display = 'none';
    resetSizePickers();

    const items = WISH_ITEMS_BY_CAT[category] || [];
    items.forEach(item => {
        const op = document.createElement('option');
        op.value = item;
        op.textContent = item;
        select.appendChild(op);
    });

    const other = document.createElement('option');
    other.value = '__other__';
    other.textContent = 'อื่นๆ (ระบุเอง)';
    select.appendChild(other);
}

document.getElementById('wish_item_select').addEventListener('change', function() {
    const customWrap  = document.getElementById('wish_custom_wrap');
    const customInput = document.getElementById('wish_item_custom');
    const wishHidden  = document.getElementById('wish_hidden_input');

    resetSizePickers();
    customWrap.style.display = 'none';
    customInput.required = false;
    customInput.value = '';
    wishHidden.value = '';
    if (!this.value) return;

    if (this.value === '__other__') {
        customWrap.style.display = 'block';
        customInput.required = true;
        customInput.focus();
        return;
    }

    if (CLOTHING_ITEMS.has(this.value)) {
        document.getElementById('clothing_size_wrap').style.display = 'block';
        return; // wish_hidden จะถูกอัปเดตเมื่อเลือกไซส์
    }

    if (SHOE_ITEMS.has(this.value)) {
        document.getElementById('shoe_size_wrap').style.display = 'block';
        return; // wish_hidden จะถูกอัปเดตเมื่อเลือกเบอร์
    }

    wishHidden.value = this.value;
});

document.getElementById('wish_item_custom').addEventListener('input', function() {
    const wishHidden = document.getElementById('wish_hidden_input');
    wishHidden.value = this.value.trim();
    this.closest('.field-group').classList.remove('has-error');
});

document.getElementById('child_bank_input').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
    const fg = this.closest('.field-group');
    if (fg) fg.classList.remove('has-error');
});

function selectSize(type, val) {
    const btnsId   = type + '_size_btns';
    const hiddenId = type + '_size_hidden';
    const wrapId   = type + '_size_wrap';
    document.querySelectorAll(`#${btnsId} .size-btn`).forEach(btn =>
        btn.classList.toggle('active', btn.dataset.size === val)
    );
    document.getElementById(hiddenId).value = val;
    document.getElementById(wrapId).classList.remove('has-error');
    // อัปเดต wish_hidden ให้รวมไซส์
    const itemSel = document.getElementById('wish_item_select');
    if (itemSel.value && itemSel.value !== '__other__') {
        document.getElementById('wish_hidden_input').value = type === 'clothing'
            ? `${itemSel.value} (ไซส์ ${val})`
            : `${itemSel.value} (${document.getElementById('shoe_type_hidden').value || '-'} เบอร์ ${val})`;
        itemSel.closest('.field-group').classList.remove('has-error');
    }
}

function selectShoeType(typeLabel) {
    document.querySelectorAll('#shoe_type_btns .size-btn').forEach(btn =>
        btn.classList.toggle('active', btn.dataset.type === typeLabel)
    );
    document.getElementById('shoe_type_hidden').value = typeLabel;
    const shoeWrap = document.getElementById('shoe_size_wrap');
    shoeWrap.classList.remove('has-error');

    const itemSel = document.getElementById('wish_item_select');
    const shoeSize = document.getElementById('shoe_size_hidden').value;
    if (itemSel.value && shoeSize) {
        document.getElementById('wish_hidden_input').value = `${itemSel.value} (${typeLabel} เบอร์ ${shoeSize})`;
        itemSel.closest('.field-group').classList.remove('has-error');
    }
}

function validateForm() {
    let firstError = null;
    let hasError   = false;
    let customErrorMessage = '';
    document.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
    const leftInfoCard = document.querySelector('.left-info-card');
    if (leftInfoCard) leftInfoCard.classList.remove('has-error');

    function mark(el) {
        const fg = el.closest('.field-group') || el;
        fg.classList.add('has-error');
        if (!firstError) firstError = fg;
        hasError = true;
    }

    normalizeBirthDateInput(true);

    // ยินยอมนโยบาย
    const consentInput = document.getElementById('policy_consent');
    if (!consentInput.checked) {
        if (leftInfoCard) {
            leftInfoCard.classList.add('has-error');
            if (!firstError) firstError = leftInfoCard;
        }
        hasError = true;
    }

    // ชื่อเล่นเด็ก
    const childNameInput = document.querySelector('input[name="child_name"]');
    if (!childNameInput.value.trim()) mark(childNameInput);

    // วันเกิด
    if (!document.getElementById('birth_date').value.trim())
        mark(document.getElementById('birth_date_display'));

    // การศึกษา
    const eduInput = document.getElementById('education');
    if (!eduInput.value.trim()) mark(eduInput);

    // ความฝัน
    const dreamSel = document.getElementById('dream_select');
    if (!dreamSel.value) {
        mark(dreamSel);
    } else if (dreamSel.value === 'อื่นๆ') {
        const dreamOther = document.getElementById('dream_other_input');
        if (!dreamOther.value.trim()) mark(dreamOther);
    }

    // หมวดหมู่
    if (!document.getElementById('wish_cat_input').value.trim()) {
        const fg = document.getElementById('catTags').closest('.field-group');
        fg.classList.add('has-error');
        if (!firstError) firstError = fg;
        hasError = true;
    }

    // รายการสิ่งของ + ไซส์
    const clothingWrap  = document.getElementById('clothing_size_wrap');
    const shoeWrap      = document.getElementById('shoe_size_wrap');
    const shoeTypeVal   = document.getElementById('shoe_type_hidden').value.trim();
    const shoeSizeVal   = document.getElementById('shoe_size_hidden').value.trim();
    const wishHiddenVal = document.getElementById('wish_hidden_input').value.trim();
    if (!wishHiddenVal) {
        if (clothingWrap.style.display !== 'none') {
            clothingWrap.classList.add('has-error');
            if (!firstError) firstError = clothingWrap;
            hasError = true;
        } else if (shoeWrap.style.display !== 'none') {
            shoeWrap.classList.add('has-error');
            if (!firstError) firstError = shoeWrap;
            hasError = true;
        } else {
            mark(document.getElementById('wish_item_select'));
        }
    }

    if (shoeWrap.style.display !== 'none' && (!shoeTypeVal || !shoeSizeVal)) {
        shoeWrap.classList.add('has-error');
        if (!firstError) firstError = shoeWrap;
        hasError = true;
    }

    // ธนาคาร
    if (!document.getElementById('bank_select').value)
        mark(document.getElementById('bank_select'));

    // เลขบัญชี
    const bankAccInput = document.getElementById('child_bank_input');
    if (!/^\d{10}$/.test(bankAccInput.value.trim())) {
        mark(bankAccInput);
        customErrorMessage = 'เลขบัญชีต้องเป็นตัวเลข 10 หลัก';
    }

    // รูปภาพ
    const photoInput = document.getElementById('photo_child_input');
    if (!IS_EDIT_FORM && (!photoInput.files || photoInput.files.length === 0)) mark(photoInput);

    if (hasError) {
        showTopAlert(customErrorMessage || 'กรุณากรอกข้อมูลให้ครบถ้วน');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusTarget = firstError.matches('input, select, textarea, button')
                ? firstError
                : firstError.querySelector('input, select, textarea, button');
            if (focusTarget) focusTarget.focus({ preventScroll: true });
        }
        return false;
    }
    return true;
}

// ล้าง error อัตโนมัติเมื่อผู้ใช้แก้ไข
document.getElementById('mainForm').addEventListener('input', e => {
    const fg = e.target.closest && e.target.closest('.field-group');
    if (fg) fg.classList.remove('has-error');
}, true);
document.getElementById('mainForm').addEventListener('change', e => {
    const fg = e.target.closest && e.target.closest('.field-group');
    if (fg) fg.classList.remove('has-error');
}, true);
document.getElementById('catTags').addEventListener('click', function() {
    this.closest('.field-group').classList.remove('has-error');
});

document.getElementById('mainForm').addEventListener('submit', function(event) {
    if (!validateForm()) event.preventDefault();
});

document.getElementById('policy_consent').addEventListener('change', function() {
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = !this.checked;
    if (this.checked) {
        const leftInfoCard = document.querySelector('.left-info-card');
        if (leftInfoCard) leftInfoCard.classList.remove('has-error');
    }
});

function initWishPrefill() {
    if (!PRESET_WISH_CAT) return;
    let activeTag = null;
    document.querySelectorAll('.cat-tag').forEach((tag) => {
        if (!activeTag && (tag.dataset.val || '') === PRESET_WISH_CAT) {
            activeTag = tag;
        }
    });
    if (activeTag) {
        activeTag.click();
    }
    if (!PRESET_WISH) return;

    const select = document.getElementById('wish_item_select');
    const customInput = document.getElementById('wish_item_custom');
    if (!select) return;

    const raw = PRESET_WISH.trim();
    const hasExact = Array.from(select.options).some((op) => op.value === raw);
    if (hasExact) {
        select.value = raw;
        select.dispatchEvent(new Event('change'));
        return;
    }

    const clothMatch = raw.match(/^(.+)\s+\(ไซส์\s+([A-Za-z0-9]+)\)$/u);
    if (clothMatch) {
        const base = clothMatch[1].trim();
        const size = clothMatch[2].trim().toUpperCase();
        const hasBase = Array.from(select.options).some((op) => op.value === base);
        if (hasBase) {
            select.value = base;
            select.dispatchEvent(new Event('change'));
            selectSize('clothing', size);
            return;
        }
    }

    const shoeMatch = raw.match(/^(.+)\s+\((.+)\s+เบอร์\s+([0-9]+)\)$/u);
    if (shoeMatch) {
        const base = shoeMatch[1].trim();
        const shoeType = shoeMatch[2].trim();
        const shoeSize = shoeMatch[3].trim();
        const hasBase = Array.from(select.options).some((op) => op.value === base);
        if (hasBase) {
            select.value = base;
            select.dispatchEvent(new Event('change'));
            selectShoeType(shoeType);
            selectSize('shoe', shoeSize);
            return;
        }
    }

    select.value = '__other__';
    select.dispatchEvent(new Event('change'));
    if (customInput) {
        customInput.value = raw;
        customInput.dispatchEvent(new Event('input'));
    }
}

// ── fallback โลโก้ธนาคาร หากไม่มีไฟล์ local ───────────
document.getElementById('bank-logo').addEventListener('error', function() {
    const map = {
        'กสิกรไทย': 'https://upload.wikimedia.org/wikipedia/th/thumb/3/35/KBank_Logo.svg/120px-KBank_Logo.svg.png',
        'กรุงเทพ': 'https://upload.wikimedia.org/wikipedia/th/thumb/5/5b/Bangkok_Bank.svg/120px-Bangkok_Bank.svg.png',
        'กรุงไทย': 'https://upload.wikimedia.org/wikipedia/th/thumb/6/6c/KTB_Logo.svg/120px-KTB_Logo.svg.png',
        'กรุงศรี': 'https://upload.wikimedia.org/wikipedia/th/thumb/2/2a/Krungsri_Logo.svg/120px-Krungsri_Logo.svg.png',
        'ไทยพาณิชย์': 'https://upload.wikimedia.org/wikipedia/th/thumb/4/4d/SCB_emblem_2024.png/120px-SCB_emblem_2024.png',
        'ออมสิน': 'https://upload.wikimedia.org/wikipedia/th/thumb/0/0f/GSB_Logo.svg/120px-GSB_Logo.svg.png'
    };
    const bank = document.getElementById('bank_select').value;
    if (map[bank]) {
        this.src = map[bank];
    }
});

toggleDreamOther();
updateBankLogo();
initWishPrefill();
syncAgeAndEducation();

document.addEventListener('DOMContentLoaded', function () {
  var openBtn = document.getElementById('openPolicyModal');
  var closeBtn = document.getElementById('closePolicyModal');
  var backdrop = document.getElementById('policyModalBackdrop');
  function openM() {
    var m = document.getElementById('policyModal');
    if (!m) return;
    m.classList.add('policy-modal--open');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function closeM() {
    var m = document.getElementById('policyModal');
    if (!m) return;
    m.classList.remove('policy-modal--open');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  if (openBtn) openBtn.addEventListener('click', function (e) { e.preventDefault(); openM(); });
  if (closeBtn) closeBtn.addEventListener('click', closeM);
  if (backdrop) backdrop.addEventListener('click', closeM);
  document.addEventListener('keydown', function (e) {
    var m = document.getElementById('policyModal');
    if (e.key === 'Escape' && m && m.classList.contains('policy-modal--open')) closeM();
  });
});
</script>

<div id="policyModal" class="policy-modal" aria-hidden="true">
  <div class="policy-modal__backdrop" id="policyModalBackdrop"></div>
  <div class="policy-modal__panel" role="dialog" aria-modal="true" aria-labelledby="policyModalTitle">
    <div class="policy-modal__head">
      <h2 id="policyModalTitle" class="policy-modal__title">นโยบายความเป็นส่วนตัว</h2>
      <button type="button" class="policy-modal__close" id="closePolicyModal" aria-label="ปิด">&times;</button>
    </div>
    <div class="policy-modal__body">
      <div class="paper policy-modal__paper">
        <div class="policy-consent-prose">
          <?php include __DIR__ . '/includes/policy_consent_content.php'; ?>
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>