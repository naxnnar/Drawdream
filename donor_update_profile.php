<?php
// donor_update_profile.php — แก้ไขโปรไฟล์ผู้บริจาค + อัปโหลดรูป + ข้อมูลใบเสร็จ
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'donor') {
    header('Location: profile.php');
    exit();
}

$msg = '';
$error = '';

/** เบอร์มือถือไทยทั่วไป 10 หลัก เริ่ม 06/08/09 */
function donor_thai_mobile_ok(string $s): bool
{
    $s = preg_replace('/\s+/', '', $s);
    return (bool) preg_match('/^0[689]\d{8}$/', $s);
}

$uploadDir = __DIR__ . '/uploads/profiles/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$stmt = $conn->prepare('SELECT d.*, u.email FROM donor d 
                       JOIN `user` u ON d.user_id = u.user_id 
                       WHERE d.user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

if (!$profile) {
    die('ไม่พบข้อมูลโปรไฟล์');
}

function donor_ensure_receipt_schema(mysqli $conn): void
{
    $cols = [
        'receipt_type' => "VARCHAR(20) NOT NULL DEFAULT 'individual'",
        'receipt_email' => 'VARCHAR(191) NULL DEFAULT NULL',
        'receipt_mobile' => 'VARCHAR(20) NULL DEFAULT NULL',
        'receipt_company_name' => 'VARCHAR(255) NULL DEFAULT NULL',
        'receipt_company_tax_id' => 'VARCHAR(32) NULL DEFAULT NULL',
        'receipt_company_address' => 'TEXT NULL',
        'receipt_company_email' => 'VARCHAR(191) NULL DEFAULT NULL',
        'receipt_company_phone' => 'VARCHAR(20) NULL DEFAULT NULL',
    ];
    foreach ($cols as $name => $def) {
        $chk = @$conn->query("SHOW COLUMNS FROM donor LIKE '" . $conn->real_escape_string($name) . "'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE donor ADD COLUMN `{$name}` {$def}");
        }
    }
}

donor_ensure_receipt_schema($conn);

$receiptSchemaOk = false;
if ($chk = @$conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_type'")) {
    $receiptSchemaOk = $chk->num_rows > 0;
}
$hasPhoneCol = false;
if ($pc = @$conn->query("SHOW COLUMNS FROM donor LIKE 'phone'")) {
    $hasPhoneCol = $pc->num_rows > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    $tax_id = preg_replace('/\D/', '', trim($_POST['tax_id'] ?? ''));

    $receipt_type = ($_POST['receipt_type'] ?? 'individual') === 'juristic' ? 'juristic' : 'individual';

    $receipt_email = trim($_POST['receipt_email'] ?? '');
    $receipt_mobile = preg_replace('/\s+/', '', trim($_POST['receipt_mobile'] ?? ''));

    $receipt_company_name = trim($_POST['receipt_company_name'] ?? '');
    $receipt_company_tax_id = preg_replace('/\D/', '', trim((string)($_POST['receipt_company_tax_id'] ?? '')));
    $receipt_company_address = trim($_POST['receipt_company_address'] ?? '');
    $receipt_company_email = trim($_POST['receipt_company_email'] ?? '');
    $receipt_company_phone = preg_replace('/\s+/', '', trim($_POST['receipt_company_phone'] ?? ''));

    // โหมดบุคคล: อีเมลใบเสร็จต้องตามอีเมลบัญชีผู้บริจาคเสมอ (แก้ไขไม่ได้)
    if ($receipt_type === 'individual') {
        $receipt_email = trim((string)($profile['email'] ?? ''));
    }

    if ($tax_id !== '' && strlen($tax_id) !== 13) {
        $error = 'เลขประจำตัวผู้เสียภาษีต้องเป็นตัวเลข 13 หลัก';
    } elseif ($first_name === '' || $last_name === '') {
        $error = 'กรุณากรอกชื่อและนามสกุล';
    } elseif ($hasPhoneCol && $phone !== '' && !donor_thai_mobile_ok($phone)) {
        $error = 'เบอร์โทรศัพท์มือถือไทยไม่ถูกต้อง (ตัวอย่าง 0812345678)';
    } elseif ($receiptSchemaOk) {
        if ($receipt_type === 'individual') {
            if ($receipt_email === '' || !filter_var($receipt_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'กรุณากรอกอีเมลสำหรับใบเสร็จ (บุคคล) ให้ถูกต้อง';
            } elseif ($receipt_mobile === '' || !donor_thai_mobile_ok($receipt_mobile)) {
                $error = 'กรุณากรอกเบอร์มือถือสำหรับใบเสร็จ (บุคคล) ให้ครบ 10 หลัก';
            }
        } else {
            if ($receipt_company_name === '') {
                $error = 'กรุณากรอกชื่อนิติบุคคล / บริษัท';
            } elseif ($receipt_company_tax_id === '' || strlen($receipt_company_tax_id) !== 13) {
                $error = 'เลขทะเบียนนิติบุคคล / เลขผู้เสียภาษีต้องเป็นตัวเลขครบ 13 หลักเท่านั้น';
            } elseif ($receipt_company_address === '') {
                $error = 'กรุณากรอกที่อยู่นิติบุคคล';
            } elseif ($receipt_company_email === '' || !filter_var($receipt_company_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'กรุณากรอกอีเมลติดต่อ (นิติบุคคล) ให้ถูกต้อง';
            } elseif ($receipt_company_phone === '' || !donor_thai_mobile_ok($receipt_company_phone)) {
                $error = 'กรุณากรอกเบอร์โทรติดต่อ (นิติบุคคล) ให้ถูกต้อง';
            }
        }
    }

    $newImage = $profile['profile_image'] ?? '';

    if ($error === '' && !empty($_FILES['profile_image']['name']) && (int)($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['profile_image'];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) {
            $error = 'อัปโหลดรูปไม่สำเร็จ';
        } elseif ((int)$f['size'] > 2 * 1024 * 1024) {
            $error = 'ไฟล์รูปต้องไม่เกิน 2 MB';
        } else {
            $info = @getimagesize($f['tmp_name']);
            if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                $error = 'รองรับเฉพาะไฟล์รูป JPG, PNG, GIF หรือ WEBP';
            } else {
                $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
                $ext = $extMap[$info[2]];
                $basename = 'donor_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = $uploadDir . $basename;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $error = 'ไม่สามารถบันทึกไฟล์รูปได้';
                } else {
                    $old = $profile['profile_image'] ?? '';
                    if ($old !== '' && is_file($uploadDir . $old)) {
                        @unlink($uploadDir . $old);
                    }
                    $newImage = $basename;
                }
            }
        }
    }

    if ($error === '') {
        if ($receiptSchemaOk) {
            if ($receipt_type === 'individual') {
                $receipt_company_name = '';
                $receipt_company_tax_id = '';
                $receipt_company_address = '';
                $receipt_company_email = '';
                $receipt_company_phone = '';
            } else {
                $receipt_email = '';
                $receipt_mobile = '';
            }
        }

        $tax_save = $tax_id;
        $phone_save = $phone;

        if ($receiptSchemaOk && $hasPhoneCol) {
            $stmt = $conn->prepare('UPDATE donor SET 
                first_name=?, last_name=?, phone=?, tax_id=?,
                receipt_type=?, receipt_email=?, receipt_mobile=?,
                receipt_company_name=?, receipt_company_tax_id=?, receipt_company_address=?,
                receipt_company_email=?, receipt_company_phone=?,
                profile_image=?
                WHERE user_id=?');
            $stmt->bind_param(
                'sssssssssssssi',
                $first_name,
                $last_name,
                $phone_save,
                $tax_save,
                $receipt_type,
                $receipt_email,
                $receipt_mobile,
                $receipt_company_name,
                $receipt_company_tax_id,
                $receipt_company_address,
                $receipt_company_email,
                $receipt_company_phone,
                $newImage,
                $user_id
            );
        } elseif ($receiptSchemaOk) {
            $stmt = $conn->prepare('UPDATE donor SET 
                first_name=?, last_name=?, tax_id=?,
                receipt_type=?, receipt_email=?, receipt_mobile=?,
                receipt_company_name=?, receipt_company_tax_id=?, receipt_company_address=?,
                receipt_company_email=?, receipt_company_phone=?,
                profile_image=?
                WHERE user_id=?');
            $stmt->bind_param(
                'ssssssssssssi',
                $first_name,
                $last_name,
                $tax_save,
                $receipt_type,
                $receipt_email,
                $receipt_mobile,
                $receipt_company_name,
                $receipt_company_tax_id,
                $receipt_company_address,
                $receipt_company_email,
                $receipt_company_phone,
                $newImage,
                $user_id
            );
        } elseif ($hasPhoneCol) {
            $stmt = $conn->prepare('UPDATE donor SET first_name=?, last_name=?, phone=?, tax_id=?, profile_image=? WHERE user_id=?');
            $stmt->bind_param('sssssi', $first_name, $last_name, $phone_save, $tax_save, $newImage, $user_id);
        } else {
            $stmt = $conn->prepare('UPDATE donor SET first_name=?, last_name=?, tax_id=?, profile_image=? WHERE user_id=?');
            $stmt->bind_param('ssssi', $first_name, $last_name, $tax_save, $newImage, $user_id);
        }

        if ($stmt->execute()) {
            $msg = 'บันทึกข้อมูลสำเร็จ!';
            $stmt2 = $conn->prepare('SELECT d.*, u.email FROM donor d 
                                    JOIN `user` u ON d.user_id = u.user_id 
                                    WHERE d.user_id = ? LIMIT 1');
            $stmt2->bind_param('i', $user_id);
            $stmt2->execute();
            $profile = $stmt2->get_result()->fetch_assoc();
        } else {
            $error = 'เกิดข้อผิดพลาด: ' . $stmt->error;
        }
    }
}

$receiptType = $receiptSchemaOk && (($profile['receipt_type'] ?? 'individual') === 'juristic') ? 'juristic' : 'individual';
$juristicTaxIdDisplay = preg_replace('/\D/', '', (string)($profile['receipt_company_tax_id'] ?? ''));
$juristicTaxIdDisplay = strlen($juristicTaxIdDisplay) > 13 ? substr($juristicTaxIdDisplay, 0, 13) : $juristicTaxIdDisplay;
$lockedReceiptEmail = trim((string)($profile['email'] ?? ''));
$defaultReceiptMobile = trim((string)($profile['receipt_mobile'] ?? ''));
if ($defaultReceiptMobile === '') {
    $defaultReceiptMobile = trim((string)($profile['phone'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขโปรไฟล์ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/donor_update_profile.css?v=6">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="edit-container">
    <div class="edit-header">
        <div class="donor-avatar-wrap">
            <div class="donor-avatar-ring" id="avatarPreviewWrap">
                <?php if (!empty($profile['profile_image'])): ?>
                    <img src="uploads/profiles/<?= htmlspecialchars($profile['profile_image']) ?>" alt="" class="donor-avatar-img" id="avatarPreview">
                <?php else: ?>
                    <img src="img/donor-avatar-placeholder.svg" alt="" class="donor-avatar-img donor-avatar-img--placeholder" id="avatarPreview">
                <?php endif; ?>
            </div>
            <button type="button" class="donor-avatar-fab" id="avatarFab" title="อัปโหลดรูปโปรไฟล์" aria-label="อัปโหลดรูปโปรไฟล์">
                <i class="bi bi-pencil-square"></i>
            </button>
        </div>
        <h1>แก้ไขโปรไฟล์</h1>
        <p><?= htmlspecialchars($profile['email']) ?></p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
        <input type="file" name="profile_image" id="profile_image" class="visually-hidden" accept="image/jpeg,image/png,image/gif,image/webp">

        <div class="form-group">
            <label class="form-label required">ชื่อจริง</label>
            <input type="text" name="first_name" class="form-input"
                   value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label required">นามสกุล</label>
            <input type="text" name="last_name" class="form-input"
                   value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>" required>
        </div>

        <?php if ($hasPhoneCol): ?>
        <div class="form-group">
            <label class="form-label">เบอร์โทรศัพท์มือถือ</label>
            <input type="tel" name="phone" class="form-input"
                   value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" inputmode="numeric" maxlength="10" autocomplete="tel">
            <div class="form-help">10 หลัก ขึ้นต้นด้วย 06 / 08 / 09</div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">เลขประจำตัวผู้เสียภาษี</label>
            <input type="text" name="tax_id" class="form-input"
                   value="<?= htmlspecialchars($profile['tax_id'] ?? '') ?>"
                   maxlength="13"
                   pattern="\d{0,13}">
            <div class="form-help">13 หลัก (ถ้ามี)</div>
        </div>

        <div class="form-group">
            <label class="form-label">อีเมล</label>
            <input type="email" class="form-input"
                   value="<?= htmlspecialchars($profile['email']) ?>" disabled>
            <div class="form-help">ไม่สามารถแก้ไขอีเมลได้</div>
        </div>

        <?php if ($receiptSchemaOk): ?>
        <section class="receipt-block" aria-labelledby="receipt-heading">
            <div class="receipt-block-head">
                <span class="receipt-block-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                <h2 id="receipt-heading" class="receipt-block-title">ข้อมูลใบเสร็จรับเงิน</h2>
            </div>

            <input type="hidden" name="receipt_type" id="receipt_type" value="<?= htmlspecialchars($receiptType) ?>">

            <div class="receipt-toggle" role="tablist">
                <button type="button" class="receipt-tab<?= $receiptType === 'individual' ? ' is-active' : '' ?>" data-receipt="individual" role="tab" aria-selected="<?= $receiptType === 'individual' ? 'true' : 'false' ?>">บุคคล</button>
                <button type="button" class="receipt-tab<?= $receiptType === 'juristic' ? ' is-active' : '' ?>" data-receipt="juristic" role="tab" aria-selected="<?= $receiptType === 'juristic' ? 'true' : 'false' ?>">นิติบุคคล</button>
            </div>

            <div class="receipt-panel<?= $receiptType === 'individual' ? '' : ' is-hidden' ?>" id="panel-individual" role="tabpanel">
                <div class="form-row-2">
                    <div class="form-group form-group--compact">
                        <label class="form-label required">อีเมล</label>
                        <input type="email" name="receipt_email" class="form-input"
                               value="<?= htmlspecialchars($lockedReceiptEmail) ?>" autocomplete="email" readonly>
                        <div class="form-help">อีเมลยึดตามบัญชีผู้บริจาค (ไม่สามารถแก้ไขได้)</div>
                    </div>
                    <div class="form-group form-group--compact">
                        <label class="form-label required">เบอร์มือถือ</label>
                        <input type="tel" name="receipt_mobile" class="form-input"
                               value="<?= htmlspecialchars($defaultReceiptMobile) ?>"
                               maxlength="10" inputmode="numeric" autocomplete="tel">
                    </div>
                </div>
            </div>

            <div class="receipt-panel<?= $receiptType === 'juristic' ? '' : ' is-hidden' ?>" id="panel-juristic" role="tabpanel">
                <div class="form-group">
                    <label class="form-label required">ชื่อนิติบุคคล / บริษัท</label>
                    <input type="text" name="receipt_company_name" class="form-input"
                           value="<?= htmlspecialchars($profile['receipt_company_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label required">เลขทะเบียน / เลขผู้เสียภาษี</label>
                    <input type="text" name="receipt_company_tax_id" id="receipt_company_tax_id" class="form-input"
                           value="<?= htmlspecialchars($juristicTaxIdDisplay) ?>"
                           inputmode="numeric" maxlength="13" pattern="\d{13}" autocomplete="off"
                           title="ตัวเลข 13 หลัก">
                    <div class="form-help">กรอกตัวเลข 13 หลักเท่านั้น</div>
                </div>
                <div class="form-group">
                    <label class="form-label required">ที่อยู่</label>
                    <textarea name="receipt_company_address" class="form-input form-textarea" rows="3"><?= htmlspecialchars($profile['receipt_company_address'] ?? '') ?></textarea>
                </div>
                <div class="form-row-2">
                    <div class="form-group form-group--compact">
                        <label class="form-label required">อีเมลติดต่อ</label>
                        <input type="email" name="receipt_company_email" class="form-input"
                               value="<?= htmlspecialchars($profile['receipt_company_email'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--compact">
                        <label class="form-label required">เบอร์โทรติดต่อ</label>
                        <input type="tel" name="receipt_company_phone" class="form-input"
                               value="<?= htmlspecialchars($profile['receipt_company_phone'] ?? '') ?>"
                               maxlength="10" inputmode="numeric">
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <div class="btn-group btn-group--save-left">
            <button type="submit" name="update_profile" class="btn btn-primary">บันทึก</button>
            <a href="profile.php" class="btn btn-secondary">ยกเลิก</a>
        </div>
    </form>
</div>

<script>
(function() {
  var fileInput = document.getElementById('profile_image');
  var fab = document.getElementById('avatarFab');
  var preview = document.getElementById('avatarPreview');
  var lastBlobUrl = null;
  if (fab && fileInput) {
    fab.addEventListener('click', function() { fileInput.click(); });
  }
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

  var typeInput = document.getElementById('receipt_type');
  var tabs = document.querySelectorAll('.receipt-tab');
  var pInd = document.getElementById('panel-individual');
  var pJur = document.getElementById('panel-juristic');
  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      var v = tab.getAttribute('data-receipt');
      if (!v || !typeInput) return;
      typeInput.value = v;
      tabs.forEach(function(t) {
        var on = t.getAttribute('data-receipt') === v;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (pInd) pInd.classList.toggle('is-hidden', v !== 'individual');
      if (pJur) pJur.classList.toggle('is-hidden', v !== 'juristic');
    });
  });

  var jurTax = document.getElementById('receipt_company_tax_id');
  if (jurTax) {
    jurTax.addEventListener('input', function() {
      jurTax.value = jurTax.value.replace(/\D/g, '').slice(0, 13);
    });
  }
})();
</script>

</body>
</html>
