<?php
// update_profile.php — อัปเดตโปรไฟล์ (ทั่วไป)

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';
require_once __DIR__ . '/includes/address_helpers.php';
require_once __DIR__ . '/includes/foundation_banks.php';
require_once __DIR__ . '/includes/notification_audit.php';
require_once __DIR__ . '/includes/foundation_review_schema.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// ผู้บริจาคใช้หน้าเฉพาะที่รองรับข้อมูลใบเสร็จบุคคล/นิติบุคคลครบถ้วน
if ($role === 'donor') {
    header('Location: donor_update_profile.php');
    exit();
}

$error = "";
$success = "";

// ดึงข้อมูลปัจจุบัน
if ($role === 'foundation') {
    $stmt = $conn->prepare("SELECT fp.*, u.email AS user_email FROM foundation_profile fp 
        JOIN `user` u ON fp.user_id = u.user_id WHERE fp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
} else {
    $stmt = $conn->prepare("SELECT * FROM donor WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
}

if (!$profile) die("ไม่พบข้อมูลโปรไฟล์");
if ($role === 'foundation') {
    drawdream_foundation_review_ensure_schema($conn);
}

$thai_addr_parsed = null;
$thai_addr_init_json = 'null';
if ($role === 'foundation') {
    $thai_addr_parsed = drawdream_parse_saved_thai_address($profile['address'] ?? '');
    if ($thai_addr_parsed) {
        $thai_addr_init_json = json_encode([
            'province' => $thai_addr_parsed['province'],
            'amphoe'   => $thai_addr_parsed['amphoe'],
            'tambon'   => $thai_addr_parsed['tambon'],
            'zip'      => $thai_addr_parsed['zip'],
        ], JSON_UNESCAPED_UNICODE);
    }
}

// อัปเดตข้อมูล
if (isset($_POST['update'])) {

    // อัปโหลดรูปโปรไฟล์
    $newProfileImage = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $uploadDir = "uploads/profiles/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed, true)) {
            $prefix = ($role === 'foundation') ? 'foundation' : 'profile';
            $safeName = $prefix . "_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadDir . $safeName)) {
                $newProfileImage = $safeName;
            }
        }
    }

    if ($role === 'foundation') {
        $foundation_name    = trim($_POST['foundation_name'] ?? '');
        $registration_number = trim($_POST['registration_number'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $website            = trim($_POST['website'] ?? '');
        $facebook_url       = trim($_POST['facebook_url'] ?? '');
        $foundation_desc    = trim($_POST['foundation_desc'] ?? '');
        $bank_name          = trim($_POST['bank_name'] ?? '');
        $bank_account_number = preg_replace('/\D/', '', trim($_POST['bank_account_number'] ?? ''));
        $bank_account_name  = trim($_POST['bank_account_name'] ?? '');

        if (empty($foundation_name)) {
            $error = "กรุณากรอกชื่อมูลนิธิ";
        }

        $addrP = trim((string)($_POST['addr_province'] ?? ''));
        $addrA = trim((string)($_POST['addr_amphoe'] ?? ''));
        $addrT = trim((string)($_POST['addr_tambon'] ?? ''));
        $addrZ = trim((string)($_POST['addr_zip'] ?? ''));
        $addrAllEmpty = ($addrP === '' && $addrA === '' && $addrT === '' && $addrZ === '');

        if ($error === '') {
            if ($addrAllEmpty) {
                $address = trim((string)($profile['address'] ?? ''));
            } else {
                $address = drawdream_merge_foundation_address_from_post($_POST);
                if ($address === '' || !preg_match('/\d{5}\s*$/u', $address)) {
                    $error = 'กรุณาเลือกจังหวัด อำเภอ ตำบล และรหัสไปรษณีย์ ให้ครบ';
                }
            }
        }

        if ($error === '') {
            $banksAllowed = array_keys(drawdream_foundation_bank_list());
            $prevBankName = trim((string)($profile['bank_name'] ?? ''));
            if ($bank_name !== '' && !in_array($bank_name, $banksAllowed, true) && $bank_name !== $prevBankName) {
                $error = 'กรุณาเลือกธนาคารจากรายการ';
            }
            if ($bank_account_number !== '' && strlen($bank_account_number) !== 10) {
                $error = 'เลขบัญชีต้องเป็นตัวเลขครบ 10 หลัก';
            }
        }

        if ($error === '') {
            $sql = "UPDATE foundation_profile SET 
                    foundation_name = ?,
                    registration_number = ?,
                    phone = ?,
                    address = ?,
                    website = ?,
                    facebook_url = ?,
                    foundation_desc = ?,
                    bank_name = ?,
                    bank_account_number = ?,
                    bank_account_name = ?";

            if (!empty($newProfileImage)) {
                $sql .= ", foundation_image = ?";
            }
            $sql .= " WHERE user_id = ?";

            $stmt = $conn->prepare($sql);
            if (!empty($newProfileImage)) {
                $stmt->bind_param("sssssssssssi",
                    $foundation_name, $registration_number, $phone,
                    $address, $website, $facebook_url, $foundation_desc,
                    $bank_name, $bank_account_number, $bank_account_name,
                    $newProfileImage, $user_id);
            } else {
                $stmt->bind_param("ssssssssssi",
                    $foundation_name, $registration_number, $phone,
                    $address, $website, $facebook_url, $foundation_desc,
                    $bank_name, $bank_account_number, $bank_account_name,
                    $user_id);
            }

            if ($stmt->execute()) {
                $wasRejected = ((int)($profile['account_verified'] ?? 0) === 2);
                $isVerifiedNow = ((int)($profile['account_verified'] ?? 0) === 1);

                if (!$isVerifiedNow) {
                    // บัญชียังไม่ผ่านอนุมัติ: เมื่อแก้ไขแล้วให้กลับเข้าคิว pending เพื่อตรวจสอบใหม่
                    $foundationId = (int)($profile['foundation_id'] ?? 0);
                    if ($foundationId > 0) {
                        $rst = $conn->prepare(
                            'UPDATE foundation_profile
                             SET account_verified = 0, verified_at = NULL, verified_by = NULL, review_note = NULL, reviewed_at = NULL
                             WHERE foundation_id = ?'
                        );
                        if ($rst) {
                            $rst->bind_param('i', $foundationId);
                            $rst->execute();
                        }
                        $foundationNameForNotif = trim($foundation_name) !== '' ? $foundation_name : (string)($profile['foundation_name'] ?? '');
                        foreach (drawdream_admin_user_ids($conn) as $adminUid) {
                            drawdream_send_notification(
                                $conn,
                                (int)$adminUid,
                                'admin_foundation_pending',
                                'มีมูลนิธิส่งโปรไฟล์รอตรวจสอบ',
                                'มูลนิธิ "' . $foundationNameForNotif . '" แก้ไขข้อมูลและส่งให้ตรวจสอบใหม่',
                                'admin_approve_foundation.php?id=' . $foundationId,
                                'adm_pending_foundation:' . $foundationId
                            );
                        }
                    }
                    $success = $wasRejected
                        ? 'บันทึกสำเร็จ และส่งโปรไฟล์ให้แอดมินตรวจสอบใหม่แล้ว'
                        : 'บันทึกสำเร็จ และคงสถานะรอตรวจสอบไว้';
                } else {
                    $success = "อัปเดตโปรไฟล์สำเร็จ!";
                }
                header("refresh:2;url=profile.php");
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt->error;
            }
        }

    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            $error = "กรุณากรอกชื่อ-นามสกุล";
        } else {
            $sql = "UPDATE donor SET first_name = ?, last_name = ?, phone = ?";
            if (!empty($newProfileImage)) $sql .= ", profile_image = ?";
            $sql .= " WHERE user_id = ?";

            $stmt = $conn->prepare($sql);
            if (!empty($newProfileImage)) {
                $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $newProfileImage, $user_id);
            } else {
                $stmt->bind_param("sssi", $first_name, $last_name, $phone, $user_id);
            }

            if ($stmt->execute()) {
                $success = "อัปเดตโปรไฟล์สำเร็จ!";
                header("refresh:2;url=profile.php");
            } else {
                $error = "เกิดข้อผิดพลาด: " . $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $role === 'foundation' ? 'แก้ไขข้อมูลมูลนิธิ' : 'แก้ไขโปรไฟล์' ?> | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/donor_update_profile.css?v=5">
    <link rel="stylesheet" href="css/thai_address.css?v=1">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="edit-container">
    <form method="post" enctype="multipart/form-data">

        <?php if ($role === 'foundation'): ?>
            <div class="edit-header">
                <div class="donor-avatar-wrap">
                    <div class="donor-avatar-ring" id="avatarPreviewWrap">
                        <?php if (!empty($profile['foundation_image'])): ?>
                            <img src="uploads/profiles/<?= htmlspecialchars($profile['foundation_image']) ?>" alt="" class="donor-avatar-img" id="avatarPreview">
                        <?php else: ?>
                            <img src="img/donor-avatar-placeholder.svg" alt="" class="donor-avatar-img donor-avatar-img--placeholder" id="avatarPreview">
                        <?php endif; ?>
                    </div>
                    <button type="button" class="donor-avatar-fab" id="avatarFab" title="อัปโหลดรูปโปรไฟล์มูลนิธิ" aria-label="อัปโหลดรูปโปรไฟล์มูลนิธิ">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </div>
                <h1>แก้ไขข้อมูลมูลนิธิ</h1>
                <p><?= htmlspecialchars($profile['user_email'] ?? $profile['email'] ?? '') ?></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <input type="hidden" name="MAX_FILE_SIZE" value="8388608">
            <input type="file" name="profile_image" id="profile_image" class="visually-hidden" accept="image/jpeg,image/png,image/gif,image/webp">

            <div class="form-group">
                <label class="form-label required">ชื่อมูลนิธิ</label>
                <input type="text" name="foundation_name" class="form-input" value="<?= htmlspecialchars($profile['foundation_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">เลขทะเบียน</label>
                <input type="text" name="registration_number" class="form-input" value="<?= htmlspecialchars($profile['registration_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
            </div>

            <div class="update-form-section-title">ข้อมูลบัญชีธนาคาร</div>

            <div class="form-group">
                <label class="form-label">ชื่อธนาคาร</label>
                <?php
                $bankList = drawdream_foundation_bank_list();
                $curBank = trim((string)($profile['bank_name'] ?? ''));
                ?>
                <select name="bank_name" id="foundation_bank_name" class="form-input">
                    <option value="">— เลือกธนาคาร —</option>
                    <?php foreach ($bankList as $bval => $blabel): ?>
                        <option value="<?= htmlspecialchars($bval) ?>"<?= ($curBank === $bval) ? ' selected' : '' ?>><?= htmlspecialchars($blabel) ?></option>
                    <?php endforeach; ?>
                    <?php if ($curBank !== '' && !array_key_exists($curBank, $bankList)): ?>
                        <option value="<?= htmlspecialchars($curBank) ?>" selected><?= htmlspecialchars($curBank) ?> (ข้อมูลเดิม)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">เลขบัญชี</label>
                <input type="text" name="bank_account_number" id="foundation_bank_account" class="form-input" inputmode="numeric" autocomplete="off"
                       maxlength="10" pattern="\d{10}"
                       value="<?= htmlspecialchars(preg_replace('/\D/', '', (string)($profile['bank_account_number'] ?? ''))) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">ชื่อบัญชี</label>
                <input type="text" name="bank_account_name" class="form-input" value="<?= htmlspecialchars($profile['bank_account_name'] ?? '') ?>">
            </div>

            <div class="update-form-section-title">ข้อมูลเพิ่มเติม</div>

            <?php
            $thai_address_options = ['require' => false];
            include __DIR__ . '/includes/thai_address_fields.php';
            ?>
            <?php if ($thai_addr_parsed === null && trim((string)($profile['address'] ?? '')) !== ''): ?>
                <p class="thai-address-legacy-note">ที่อยู่เดิม (ข้อความ): <?= htmlspecialchars($profile['address']) ?> — กรุณาเลือกจากรายการด้านบนเพื่ออัปเดตเป็นรูปแบบมาตรฐาน</p>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">เว็บไซต์</label>
                <input type="url" name="website" class="form-input" value="<?= htmlspecialchars($profile['website'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Facebook URL</label>
                <input type="url" name="facebook_url" class="form-input" value="<?= htmlspecialchars($profile['facebook_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">คำอธิบายมูลนิธิ</label>
                <textarea name="foundation_desc" class="form-input form-textarea" rows="4"><?= htmlspecialchars($profile['foundation_desc'] ?? '') ?></textarea>
            </div>

            <div class="btn-group btn-group--save-left">
                <button type="submit" name="update" class="btn btn-primary">บันทึก</button>
                <a href="profile.php" class="btn btn-secondary">ยกเลิก</a>
            </div>

        <?php else: ?>

            <div class="edit-header">
                <div class="donor-avatar-wrap">
                    <div class="donor-avatar-ring" id="avatarPreviewWrapLegacy">
                        <?php if (!empty($profile['profile_image'])): ?>
                            <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" alt="" class="donor-avatar-img" id="avatarPreviewLegacy">
                        <?php else: ?>
                            <img src="img/icoprofile.png" alt="" class="donor-avatar-img donor-avatar-img--placeholder" id="avatarPreviewLegacy">
                        <?php endif; ?>
                    </div>
                    <button type="button" class="donor-avatar-fab" id="avatarFabLegacy" title="อัปโหลดรูปโปรไฟล์" aria-label="อัปโหลดรูปโปรไฟล์">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </div>
                <h1>แก้ไขโปรไฟล์</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <input type="hidden" name="MAX_FILE_SIZE" value="8388608">
            <input type="file" name="profile_image" id="profile_image_legacy" class="visually-hidden" accept="image/jpeg,image/png,image/gif,image/webp">

            <div class="form-group">
                <label class="form-label required">ชื่อ</label>
                <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($profile['first_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label required">นามสกุล</label>
                <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($profile['last_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
            </div>

            <div class="btn-group btn-group--save-left">
                <button type="submit" name="update" class="btn btn-primary">บันทึก</button>
                <a href="profile.php" class="btn btn-secondary">ยกเลิก</a>
            </div>

        <?php endif; ?>
    </form>
</div>

<?php if ($role === 'foundation'): ?>
<script>
(function() {
  var fileInput = document.getElementById('profile_image');
  var fab = document.getElementById('avatarFab');
  var preview = document.getElementById('avatarPreview');
  var lastBlobUrl = null;
  if (fab && fileInput) fab.addEventListener('click', function() { fileInput.click(); });
  if (fileInput && preview) {
    fileInput.addEventListener('change', function() {
      var f = fileInput.files && fileInput.files[0];
      if (!f || !f.type.match(/^image\//)) return;
      if (lastBlobUrl) URL.revokeObjectURL(lastBlobUrl);
      lastBlobUrl = URL.createObjectURL(f);
      preview.src = lastBlobUrl;
      preview.classList.remove('donor-avatar-img--placeholder');
    });
  }
})();
</script>
<script src="js/thai_address_select.js?v=1"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof ThaiAddressSelect !== 'undefined') {
    ThaiAddressSelect.mount({
      province: '#addr_province',
      amphoe: '#addr_amphoe',
      tambon: '#addr_tambon',
      zip: '#addr_zip',
      initial: <?= $thai_addr_init_json ?>
    });
  }
  var acc = document.getElementById('foundation_bank_account');
  if (acc) {
    acc.addEventListener('input', function () {
      acc.value = acc.value.replace(/\D/g, '').slice(0, 10);
    });
  }
});
</script>
<?php else: ?>
<script>
(function() {
  var fileInput = document.getElementById('profile_image_legacy');
  var fab = document.getElementById('avatarFabLegacy');
  var preview = document.getElementById('avatarPreviewLegacy');
  var lastBlobUrl2 = null;
  if (fab && fileInput) fab.addEventListener('click', function() { fileInput.click(); });
  if (fileInput && preview) {
    fileInput.addEventListener('change', function() {
      var f = fileInput.files && fileInput.files[0];
      if (!f || !f.type.match(/^image\//)) return;
      if (lastBlobUrl2) URL.revokeObjectURL(lastBlobUrl2);
      lastBlobUrl2 = URL.createObjectURL(f);
      preview.src = lastBlobUrl2;
      preview.classList.remove('donor-avatar-img--placeholder');
    });
  }
})();
</script>
<?php endif; ?>

</body>
</html>