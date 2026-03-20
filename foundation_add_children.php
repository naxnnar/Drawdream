<?php
session_start();
include 'db.php';

// ให้เข้าได้เฉพาะ foundation
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: login.php");
    exit();
}


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

// ─── Auto-migrate: เพิ่มคอลัมน์ที่ยังไม่มีในตาราง Children ───────────────
$needed_columns = [
    'birth_date' => "ALTER TABLE Children ADD COLUMN birth_date DATE         NULL AFTER child_name",
    'likes'      => "ALTER TABLE Children ADD COLUMN likes      VARCHAR(100)  NULL AFTER dream",
    'wish_cat'   => "ALTER TABLE Children ADD COLUMN wish_cat   VARCHAR(100)  NULL AFTER wish",
    'qr_account_image' => "ALTER TABLE Children ADD COLUMN qr_account_image VARCHAR(255) NULL AFTER child_bank",
];
foreach ($needed_columns as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM Children LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}
$has_birth_date_column = true; // migration ensures it exists

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
    $status        = "ยังไม่มีผู้อุปการะ";
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

    // จัดการไฟล์รูปภาพ
    if (!isset($_FILES['photo_child']) || $_FILES['photo_child']['error'] !== 0) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพเด็ก'); history.back();</script>";
        exit();
    }

    $imageName = $_FILES['photo_child']['name'];
    $tmpName   = $_FILES['photo_child']['tmp_name'];
    $ext       = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
    $allowed   = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($ext, $allowed)) {
        echo "<script>alert('อนุญาตเฉพาะไฟล์รูปภาพเท่านั้น'); history.back();</script>";
        exit();
    }

    $newName = "child_" . time() . "." . $ext;

    if (!move_uploaded_file($tmpName, "uploads/Children/" . $newName)) {
        echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
        exit();
    }

    // จัดการไฟล์รูป QR บัญชีเด็ก (อายุ 15-18 บังคับ, 6-14 ไม่บังคับ)
    $newQrName = null;
    $hasQrUpload = isset($_FILES['qr_account_image']) && $_FILES['qr_account_image']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($age >= 15 && !$hasQrUpload) {
        echo "<script>alert('เด็กอายุ 15-18 ปี ต้องอัปโหลดภาพสแกน QR บัญชีเด็ก'); history.back();</script>";
        exit();
    }

    if ($hasQrUpload) {
        if ($_FILES['qr_account_image']['error'] !== 0) {
            echo "<script>alert('อัปโหลดภาพ QR ไม่สำเร็จ'); history.back();</script>";
            exit();
        }
        $qrImageName = $_FILES['qr_account_image']['name'];
        $qrTmpName   = $_FILES['qr_account_image']['tmp_name'];
        $qrExt       = strtolower(pathinfo($qrImageName, PATHINFO_EXTENSION));
        if (!in_array($qrExt, $allowed)) {
            echo "<script>alert('ไฟล์ QR ต้องเป็นไฟล์รูปภาพเท่านั้น'); history.back();</script>";
            exit();
        }
        $newQrName = "qr_" . time() . "_" . mt_rand(1000, 9999) . "." . $qrExt;
        if (!move_uploaded_file($qrTmpName, "uploads/Children/" . $newQrName)) {
            echo "<script>alert('อัปโหลดภาพ QR ไม่สำเร็จ'); history.back();</script>";
            exit();
        }
    }


    if ($has_birth_date_column) {
        // i=foundation_id, s values include child fields + qr image + status/photo/approve
        $sql = "INSERT INTO Children (foundation_id, foundation_name, child_name, birth_date, age, education, dream, likes, wish, wish_cat, bank_name, child_bank, qr_account_image, status, photo_child, approve_profile) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        // foundation_id(i), birth_date before age(i), then string fields
        $stmt->bind_param(
            "isssisssssssssss",
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
            $newQrName,
            $status,
            $newName,
            $approve_status
        );
    } else {
        $sql = "INSERT INTO Children (foundation_id, foundation_name, child_name, age, education, dream, likes, wish, wish_cat, bank_name, child_bank, qr_account_image, status, photo_child, approve_profile) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        // i,s,s,i then string fields including qr image
        $stmt->bind_param("ississsssssssss", $f_id, $f_name, $child_name, $age, $education, $dream, $likes, $wish, $wish_cat, $bank_name, $child_bank, $newQrName, $status, $newName, $approve_status);
    }

    if ($stmt->execute()) {
        echo "<script>alert('เพิ่มข้อมูลเด็กสำเร็จ'); window.location='children_.php';</script>";
        exit();
    } else {
        // เปลี่ยนตรงนี้เพื่อดู Error จริงๆ จาก MySQL
        die("MySQL Error: " . $stmt->error); 
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สร้างโปรไฟล์เด็ก - Children Profile</title>
<link rel="stylesheet" href="css/navbar.css">
<link rel="stylesheet" href="css/style.css">
<style>
    :root {
        --theme-primary: #3C5099;
        --theme-primary-dark: #2e407f;
        --theme-soft: #F1CF54;
        --theme-accent: #18a999;
        --theme-ink: #27364b;
        --theme-bg: #F7ECDE;
        --theme-card: #fff;
        --theme-border: #F1CF54;
    }
    body {
        background: var(--theme-bg);
        color: var(--theme-ink);
        font-size: 17px;
    }
    .form-container { display:flex; gap:42px; padding:42px 40px; max-width:1280px; margin:auto; }
    .left-box { width:32%; flex-shrink:0; }
    .left-panel-title {
        color: #24314a;
        margin-bottom: 8px;
        font-size: 2rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .left-foundation-name {
        color: #000;
        font-size: 1.2rem;
        margin-bottom: 18px;
        font-weight: 600;
        text-align: center;
    }
    .upload-preview {
        width:100%; aspect-ratio:1; background:#de8168;
        border:4px dashed #597D57; border-radius:18px; display:flex; justify-content:center;
        align-items:center; overflow:hidden; cursor:pointer;
    }
    .upload-preview img { width:100%; height:100%; object-fit:cover; }
    .upload-preview span { color:#fff; font-size:16px; font-weight:600; }
    .left-info-card {
        margin-top: 14px;
        background: #fff;
        border: 1.5px solid var(--theme-border);
        border-radius: 14px;
        padding: 14px 14px 10px;
        box-shadow: 0 4px 12px rgba(16, 53, 90, 0.05);
        text-align: center;
    }
    .left-info-card.has-error {
        border-color: #CE573F;
        box-shadow: 0 0 0 3px rgba(206, 87, 63, 0.15);
    }
    .left-info-card h3 {
        margin: 0 0 9px;
        font-size: 1.35rem;
        color: #17406a;
        font-weight: 800;
    }

    .consent-check {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin: 6px 0 10px;
        font-size: 1.18rem;
        font-weight: 700;
    }
    .consent-check input[type="checkbox"] {
        width: 20px;
        height: 20px;
        accent-color: #2c7be5;
        cursor: pointer;
    }
    .consent-link {
        color: #3C5099;
        text-decoration: underline;
        text-underline-offset: 3px;
        font-weight: 700;
    }
    .consent-link:hover {
        color: #304083;
    }
    .consent-note {
        margin-top: 8px;
        font-size: 1rem;
        color: #CE573F;
        line-height: 1.45;
        font-weight: 700;
    }

    .top-alert {
        position: fixed;
        top: 16px;
        left: 50%;
        transform: translate(-50%, -16px);
        background: #CE573F;
        color: #fff;
        padding: 12px 18px;
        border-radius: 12px;
        box-shadow: 0 10px 20px rgba(206, 87, 63, 0.34);
        font-size: 1rem;
        font-weight: 700;
        z-index: 9999;
        opacity: 0;
        pointer-events: none;
        transition: all .25s ease;
        max-width: min(92vw, 720px);
    }
    .top-alert.show {
        opacity: 1;
        transform: translate(-50%, 0);
    }
    .right-box {
        flex:1; background:var(--theme-card); padding:34px; border-radius:22px;
        border: 1.5px solid var(--theme-border); box-shadow:0 10px 26px rgba(25, 48, 77, 0.08);
    }
    .right-box h2 { margin:0 0 22px; font-size:1.45rem; color:var(--theme-ink); }
    .grid-inputs { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .field-group { display:flex; flex-direction:column; }
    .field-group label { font-weight:700; font-size:1rem; margin-bottom:6px; color:#1f3654; }
    .field-group input,
    .field-group textarea,
    .field-group select {
        width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid var(--theme-border);
        font-size:1.08rem; box-sizing:border-box; background:#fff;
    }
    .field-group input::placeholder,
    .field-group textarea::placeholder {
        font-size: 1.02rem;
    }
    .date-input-wrap {
        position: relative;
    }
    .date-input-wrap #birth_date_display {
        padding-right: 54px;
    }
    .date-picker-btn {
        position: absolute;
        top: 50%;
        right: 12px;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        border: none;
        border-radius: 10px;
        background: transparent;
        color: #27364b;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }
    .date-picker-btn:hover {
        background: rgba(60, 80, 153, 0.08);
    }
    .native-date-picker {
        position: absolute;
        top: 50%;
        right: 12px;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        opacity: 0;
        cursor: pointer;
        z-index: 2;
        border: 0;
    }
    .field-group input:focus,
    .field-group textarea:focus,
    .field-group select:focus {
        border-color: var(--theme-primary);
        outline: none;
        box-shadow: 0 0 0 4px rgba(60, 80, 153, .14);
    }
    .field-group textarea { resize:vertical; }
    .field-group small { color:#7c8aa0; font-size:0.85rem; margin-top:4px; }
    .full-width { grid-column:span 2; }

    .section-title {
        font-size: 1.06rem;
        margin: 4px 0 8px;
        color: #14385f;
        font-weight: 800;
    }

    /* ── Bank dropdown ─────────────────── */
    .bank-select-wrapper { position:relative; }
    .bank-select-wrapper select { padding-left:54px; }
    .bank-logo-preview {
        position:absolute; left:14px; top:50%; transform:translateY(-50%);
        width:28px; height:28px; border-radius:6px; object-fit:contain;
        pointer-events:none; display:none; background:#fff;
    }

    /* ── Wish category tag pills ──────── */
    .cat-tags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
    .cat-tag {
        display:inline-flex; align-items:center; gap:6px; padding:8px 15px; border-radius:22px;
        border:1.8px solid var(--theme-border); background:#fff; font-size:0.95rem; cursor:pointer;
        user-select:none; transition:all .16s; color:#324a68; font-weight:600;
    }
    .cat-tag:hover { border-color:var(--theme-primary); background:#eef2ff; }
    .cat-tag.active { border-color:var(--theme-primary); background:var(--theme-soft); color:var(--theme-primary); }
    .cat-tag .cat-icon { font-size:1.07rem; }

    .item-select-wrap {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .wish-custom-wrap { display: none; }

    .item-hint {
        color: #5f6f87;
        font-size: 0.9rem;
    }

    /* ── Dream select ─────────────────── */
    select.dream-sel { width:100%; }

    .dream-other-wrap { display:none; margin-top:8px; }

    /* ── Submit ───────────────────────── */
    .btn-submit {
        background: var(--theme-primary);
        color: #fff;
        padding:14px; border:none; border-radius:13px; font-size:1.1rem;
        cursor:pointer; font-weight:800; width:100%; margin-top:12px;
        transition: transform .15s ease, box-shadow .15s ease;
        box-shadow: 0 7px 14px rgba(60, 80, 153, 0.28);
    }
    .btn-submit:hover { transform: translateY(-1px); background: var(--theme-primary-dark); box-shadow:0 10px 18px rgba(60, 80, 153, 0.32); }
    .btn-submit:disabled {
        background: #F1CF54;
        color: #000;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
        opacity: .78;
    }

    /* ── Field error highlight ─────────────────────── */
    .has-error input,
    .has-error select,
    .has-error textarea {
        border-color: #CE573F !important;
        box-shadow: 0 0 0 3px rgba(206,87,63,.15) !important;
    }
    .has-error label { color: #CE573F; }
    .has-error .cat-tags { outline: 2.5px solid #CE573F; border-radius: 10px; }

    /* ── Size picker ─────────────────────────────────── */
    .size-pick-wrap {
        margin-top: 8px;
        background: #fdf8ee;
        border: 1.5px solid var(--theme-border);
        border-radius: 12px;
        padding: 10px 13px;
    }
    .size-pick-wrap.has-error { border-color: #CE573F; }
    .size-pick-wrap.has-error .size-pick-label { color: #CE573F; }
    .size-pick-label { display:block; font-weight:700; font-size:.95rem; color:#1f3654; margin-bottom:7px; }
    .size-btn-group { display:flex; flex-wrap:wrap; gap:8px; }
    .size-btn {
        min-width:42px; padding:7px 12px; border-radius:10px;
        border:1.8px solid var(--theme-border); background:#fff;
        font-size:.95rem; font-weight:700; cursor:pointer; color:#324a68;
        transition:all .14s; line-height:1;
    }
    .size-btn:hover { border-color:var(--theme-primary); background:#eef2ff; }
    .size-btn.active { border-color:var(--theme-primary); background:var(--theme-soft); color:var(--theme-primary); }

    @media(max-width:860px){
        .form-container { flex-direction:column; padding:20px; }
        .left-box { width:100%; }
        .grid-inputs { grid-template-columns:1fr; }
        .full-width { grid-column:span 1; }
    }
</style>
</head>
<body>

<div id="topAlert" class="top-alert"></div>

<?php include 'navbar.php'; ?>

<div class="form-container">
    <!-- ── ซ้าย: preview รูป ──────────────────── -->
    <div class="left-box">
        <h2 class="left-panel-title">สร้างโปรไฟล์เด็ก</h2>
        <p class="left-foundation-name">มูลนิธิ <?php echo htmlspecialchars($f_name); ?></p>
        <div class="upload-preview" id="preview-container" onclick="document.getElementById('photo_child_input').click()">
            <span id="preview-text">ตัวอย่างรูปภาพ</span>
        </div>
        <div class="field-group" style="margin-top:12px;">
            <label>รูปภาพเด็ก</label>
            <input type="file" id="photo_child_input" name="photo_child" form="mainForm" accept="image/*" required onchange="previewImage(this)" style="display:block;">
        </div>
        <div class="left-info-card">
            <h3>ยินยอมนโยบาย</h3>
            <label class="consent-check">
                <input type="checkbox" id="policy_consent" name="policy_consent" value="1" form="mainForm">
                <a class="consent-link" href="policy_consent.php" target="_blank" rel="noopener">นโยบายความเป็นส่วนตัว</a>
            </label>
            <div class="consent-note">**ต้องกดยินยอมก่อนจึงจะบันทึกโปรไฟล์ได้**</div>
        </div>
    </div>

    <!-- ── ขวา: ฟอร์ม ──────────────────────────── -->
    <div class="right-box">
        <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
            <div class="grid-inputs">

                <!-- ชื่อเล่นเด็ก -->
                <div class="field-group">
                    <label>ชื่อเล่นเด็ก</label>
                    <input type="text" name="child_name" placeholder="ตัวอย่าง: น้องฟ้า" required>
                </div>

                <!-- วันเกิด -->
                <div class="field-group">
                    <label>วันเกิดเด็ก</label>
                    <div class="date-input-wrap">
                        <input type="text" id="birth_date_display" placeholder="m/d/y" inputmode="numeric" maxlength="10" required oninput="handleBirthDateInput()" onblur="normalizeBirthDateInput()">
                        <button type="button" class="date-picker-btn" aria-label="เลือกวันเกิด" tabindex="-1">📅</button>
                        <input type="date" id="birth_date_picker" class="native-date-picker" max="<?php echo date('Y-m-d'); ?>" onchange="handleBirthDatePickerChange()">
                    </div>
                    <input type="hidden" id="birth_date" name="birth_date">
                </div>

                <!-- อายุ -->
                <div class="field-group">
                    <label>อายุ (คำนวณอัตโนมัติ)</label>
                    <input type="number" id="age" name="age_preview" readonly style="background:#f5f5f5;">
                </div>

                <!-- ระดับการศึกษา -->
                <div class="field-group">
                    <label>ระดับการศึกษา</label>
                    <input type="text" id="education" name="education" placeholder="ตัวอย่าง: ม.3" required>
                    <!-- <small>ระบบแนะนำตามอายุ แก้ไขได้</small> -->
                </div>

                <!-- ความฝัน (dropdown) -->
                <div class="field-group">
                    <label>ความฝัน</label>
                    <select name="dream" id="dream_select" class="dream-sel" required onchange="toggleDreamOther()">
                        <option value="">— เลือกความฝัน —</option>
                        <option value="คุณหมอ">👨‍⚕️ คุณหมอ</option>
                        <option value="คุณครู">👩‍🏫 คุณครู</option>
                        <option value="พยาบาล">💊 พยาบาล</option>
                        <option value="ทหาร">🪖 ทหาร</option>
                        <option value="ตำรวจ">👮 ตำรวจ</option>
                        <option value="นักบิน">✈️ นักบิน</option>
                        <option value="นักร้อง">🎤 นักร้อง</option>
                        <option value="นักเต้น">💃 นักเต้น</option>
                        <option value="จิตรกร">🎨 จิตรกร</option>
                        <option value="แม่ค้า">🛒 แม่ค้า</option>
                        <option value="อื่นๆ">✏️ อื่นๆ (ระบุเอง)</option>
                    </select>
                    <div class="dream-other-wrap" id="dream_other_wrap">
                        <input type="text" id="dream_other_input" name="dream_other" placeholder="ระบุความฝัน เช่น นักพัฒนาเกม">
                    </div>
                </div>

                <!-- สิ่งที่ชอบ (ใหม่) -->
                <div class="field-group">
                    <label>สิ่งที่ชอบ</label>
                    <input type="text" name="likes" id="likes_input" placeholder="ตัวอย่าง: วาดรูป, ฟุตบอล" maxlength="100">
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
                    <input type="hidden" name="wish_cat" id="wish_cat_input">
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
                    <input type="hidden" name="wish" id="wish_hidden_input" required>
                </div>

                <!-- ธนาคาร (dropdown พร้อมโลโก้) -->
                <div class="field-group">
                    <label>ธนาคาร</label>
                    <div class="bank-select-wrapper">
                        <img id="bank-logo" class="bank-logo-preview" src="" alt="">
                        <select name="bank_name" id="bank_select" required onchange="updateBankLogo()">
                            <option value="">— เลือกธนาคาร —</option>
                            <option value="กสิกรไทย"   data-logo="img/bank-kbank.png">กสิกรไทย (KBank)</option>
                            <option value="กรุงเทพ"    data-logo="img/bank-bbl.png">กรุงเทพ (BBL)</option>
                            <option value="กรุงไทย"    data-logo="img/bank-ktb.png">กรุงไทย (KTB)</option>
                            <option value="กรุงศรี"    data-logo="img/bank-bay.png">กรุงศรี (BAY)</option>
                            <option value="ไทยพาณิชย์" data-logo="img/bank-scb.png">ไทยพาณิชย์ (SCB)</option>
                            <option value="ออมสิน"     data-logo="img/bank-gsb.png">ออมสิน (GSB)</option>
                        </select>
                    </div>
                </div>

                <!-- เลขบัญชี -->
                <div class="field-group">
                    <label>เลขบัญชีธนาคาร</label>
                    <input type="text" id="child_bank_input" name="child_bank" placeholder="ตัวเลข 10 หลัก" inputmode="numeric" maxlength="10" pattern="\d{10}" required>
                </div>

                <!-- อัปโหลดรูป QR บัญชีเด็ก -->
                <div class="field-group full-width">
                    <label>ภาพสแกนคิวอาร์โค้ดบัญชีเด็ก</label>
                    <input type="file" id="qr_account_input" name="qr_account_image" accept="image/*" style="display:block;">
                    <small id="qr_age_rule_note">อายุ 6-14 ปี: ไม่อัปโหลดก็ได้ | อายุ 15-18 ปี: ต้องอัปโหลดภาพ QR</small>
                </div>

            </div><!-- end grid -->

            <button type="submit" name="submit" class="btn-submit" id="submitBtn" disabled>บันทึกข้อมูล</button>
        </form>
    </div>
</div>

<script>
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
    if (!bd.value) { ageEl.value = ''; updateQrRequirement(); return; }
    const today = new Date(), dob = new Date(bd.value + 'T00:00:00');
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    if (age < 0) { ageEl.value = ''; updateQrRequirement(); return; }
    if (age < 6 || age > 18) {
        showTopAlert(`อายุ ${age} ปี ไม่อยู่ในเกณฑ์ที่รับได้ (6–18 ปี) กรุณาตรวจสอบวันเกิด`);
        bd.value = '';
        document.getElementById('birth_date_picker').value = '';
        disp.value = '';
        disp.closest('.field-group').classList.add('has-error');
        ageEl.value = '';
        updateQrRequirement();
        return;
    }
    disp.closest('.field-group').classList.remove('has-error');
    ageEl.value = age;
    if (!educationManuallyEdited || !edu.value.trim()) edu.value = getSuggestedEducation(age);
    updateQrRequirement(age);
}

function updateQrRequirement(ageValue = null) {
    const qrInput = document.getElementById('qr_account_input');
    const noteEl  = document.getElementById('qr_age_rule_note');
    const ageEl   = document.getElementById('age');
    const age = ageValue === null ? parseInt(ageEl.value || '', 10) : parseInt(ageValue, 10);

    const requiresQr = Number.isFinite(age) && age >= 15;
    qrInput.required = requiresQr;

    if (noteEl) {
        noteEl.textContent = requiresQr
            ? 'อายุ 15-18 ปี: ต้องอัปโหลดภาพ QR'
            : 'อายุ 6-14 ปี: ไม่อัปโหลดก็ได้';
        noteEl.style.color = requiresQr ? '#CE573F' : '#5f6f87';
        noteEl.style.fontWeight = requiresQr ? '700' : '500';
    }
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

    // รูป QR บัญชีเด็ก (อายุ 15-18 บังคับ)
    const ageValue = parseInt(document.getElementById('age').value || '', 10);
    const qrInput = document.getElementById('qr_account_input');
    if (Number.isFinite(ageValue) && ageValue >= 15 && (!qrInput.files || qrInput.files.length === 0)) {
        mark(qrInput);
        customErrorMessage = 'เด็กอายุ 15-18 ปี ต้องอัปโหลดภาพสแกน QR';
    }

    // รูปภาพ
    const photoInput = document.getElementById('photo_child_input');
    if (!photoInput.files || photoInput.files.length === 0) mark(photoInput);

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
updateQrRequirement();
</script>

</body>
</html>