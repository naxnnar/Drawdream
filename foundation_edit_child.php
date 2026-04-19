<?php
// foundation_edit_child.php — มูลนิธิแก้ไขโปรไฟล์เด็ก

session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: homepage.php');
    exit();
}

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_account_verified($conn);

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$foundationId = 0;
$stmtFP = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
$stmtFP->bind_param('i', $currentUserId);
$stmtFP->execute();
$resFP = $stmtFP->get_result();
if ($rowFP = $resFP->fetch_assoc()) {
    $foundationId = (int)$rowFP['foundation_id'];
}

if ($foundationId <= 0) {
    die('ไม่พบข้อมูลมูลนิธิ');
}

$childId = (int)($_GET['id'] ?? $_POST['child_id'] ?? 0);
if ($childId <= 0) {
    die('ไม่พบรหัสโปรไฟล์เด็ก');
}

$sqlGet = "SELECT * FROM foundation_children WHERE child_id = ? AND foundation_id = ? AND deleted_at IS NULL LIMIT 1";
$stmtGet = $conn->prepare($sqlGet);
$stmtGet->bind_param('ii', $childId, $foundationId);
$stmtGet->execute();
$child = $stmtGet->get_result()->fetch_assoc();

if (!$child) {
    die('ไม่พบข้อมูลโปรไฟล์นี้ หรือไม่มีสิทธิ์แก้ไข');
}

$chkPend = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'pending_edit_json'");
if ($chkPend && $chkPend->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_children ADD COLUMN pending_edit_json LONGTEXT NULL AFTER approve_profile");
}

if (!empty($child['pending_edit_json'])) {
    $pj = json_decode((string)$child['pending_edit_json'], true);
    if (is_array($pj)) {
        foreach (['child_name', 'birth_date', 'age', 'education', 'dream', 'likes', 'wish', 'wish_cat', 'bank_name', 'child_bank', 'photo_child'] as $k) {
            if (array_key_exists($k, $pj)) {
                $child[$k] = $pj[$k];
            }
        }
    }
}

require_once __DIR__ . '/includes/child_sponsorship.php';
$lockAp = (string)($child['approve_profile'] ?? '');
$lockCycle = drawdream_child_cycle_total($conn, $childId, $child);
$lockTarget = drawdream_child_cycle_target_amount($conn, $childId);
if (in_array($lockAp, ['อนุมัติ', 'กำลังดำเนินการ'], true)
    && $lockCycle >= $lockTarget) {
    header('Location: children_.php?msg=' . rawurlencode('เด็กที่ได้รับการอุปการะครบยอดในเดือนนี้ ไม่สามารถแก้ไขโปรไฟล์ได้'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child_name = trim($_POST['child_name'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $dream = trim($_POST['dream'] ?? '');
    $likes = trim($_POST['likes'] ?? '');
    $wish = trim($_POST['wish'] ?? '');
    $wish_cat = trim($_POST['wish_cat'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $child_bank = trim($_POST['child_bank'] ?? '');

    if ($child_name === '' || $birth_date === '' || $education === '' || $dream === '' || $wish === '' || $wish_cat === '' || $bank_name === '') {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
    }

    $dob = DateTime::createFromFormat('Y-m-d', $birth_date);
    $today = new DateTime('today');
    if ($error === '' && (!$dob || $dob->format('Y-m-d') !== $birth_date)) {
        $error = 'กรุณาระบุวันเกิดให้ถูกต้อง';
    }
    if ($error === '' && $dob > $today) {
        $error = 'วันเกิดต้องไม่เป็นอนาคต';
    }

    $age = 0;
    if ($error === '') {
        $age = (int)$today->diff($dob)->y;
        if ($age < 6 || $age > 18) {
            $error = 'อายุต้องอยู่ในช่วง 6-18 ปี';
        }
    }

    if ($error === '' && !preg_match('/^\d{10}$/', $child_bank)) {
        $error = 'เลขบัญชีต้องเป็นตัวเลข 10 หลัก';
    }

    $allowed = ['jpg','jpeg','png','gif','webp'];
    $photoName = $child['photo_child'] ?? null;

    if ($error === '' && isset($_FILES['photo_child']) && $_FILES['photo_child']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['photo_child']['error'] !== 0) {
            $error = 'อัปโหลดรูปเด็กไม่สำเร็จ';
        } else {
            $ext = strtolower(pathinfo($_FILES['photo_child']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $error = 'รูปเด็กต้องเป็นไฟล์รูปภาพเท่านั้น';
            } else {
                $photoName = 'child_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                if (!move_uploaded_file($_FILES['photo_child']['tmp_name'], 'uploads/childern/' . $photoName)) {
                    $error = 'บันทึกรูปเด็กไม่สำเร็จ';
                }
            }
        }
    }

    if ($error === '') {
        $currentAp = (string)($child['approve_profile'] ?? '');
        $isPublished = in_array($currentAp, ['อนุมัติ', 'กำลังดำเนินการ'], true);

        if ($isPublished) {
            $payload = [
                'child_name' => $child_name,
                'birth_date' => $birth_date,
                'age' => $age,
                'education' => $education,
                'dream' => $dream,
                'likes' => $likes,
                'wish' => $wish,
                'wish_cat' => $wish_cat,
                'bank_name' => $bank_name,
                'child_bank' => $child_bank,
                'photo_child' => $photoName,
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $sqlUpd = "UPDATE foundation_children SET pending_edit_json=?, approve_profile='กำลังดำเนินการ', reject_reason=NULL WHERE child_id=? AND foundation_id=?";
            $stmtUpd = $conn->prepare($sqlUpd);
            $stmtUpd->bind_param('sii', $json, $childId, $foundationId);
            if ($stmtUpd->execute()) {
                header('Location: children_.php?msg=' . urlencode('ส่งคำขอแก้ไขให้แอดมินตรวจสอบแล้ว — ข้อมูลที่แสดงต่อสาธารณะยังเป็นชุดเดิมจนกว่าจะได้รับการอนุมัติ'));
                exit();
            }
            $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        } else {
            $sqlUpd = "UPDATE foundation_children
                       SET child_name=?, birth_date=?, age=?, education=?, dream=?, likes=?, wish=?, wish_cat=?, bank_name=?, child_bank=?, photo_child=?,
                           approve_profile='รอดำเนินการ', reject_reason=NULL, pending_edit_json=NULL
                       WHERE child_id=? AND foundation_id=?";
            $stmtUpd = $conn->prepare($sqlUpd);
            $stmtUpd->bind_param(
                'ssissssssssii',
                $child_name,
                $birth_date,
                $age,
                $education,
                $dream,
                $likes,
                $wish,
                $wish_cat,
                $bank_name,
                $child_bank,
                $photoName,
                $childId,
                $foundationId
            );

            if ($stmtUpd->execute()) {
                header('Location: children_.php?msg=' . urlencode('แก้ไขโปรไฟล์เด็กสำเร็จ'));
                exit();
            }
            $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        }
    }

    $child['child_name'] = $child_name;
    $child['birth_date'] = $birth_date;
    $child['age'] = $age;
    $child['education'] = $education;
    $child['dream'] = $dream;
    $child['likes'] = $likes;
    $child['wish'] = $wish;
    $child['wish_cat'] = $wish_cat;
    $child['bank_name'] = $bank_name;
    $child['child_bank'] = $child_bank;
    $child['photo_child'] = $photoName;
}
?>
<!doctype html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>แก้ไขโปรไฟล์เด็ก</title>
<link rel="stylesheet" href="css/navbar.css">
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/children.css">
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="wrap">
  <h2 style="margin-top:0;color:#24314a;">แก้ไขโปรไฟล์เด็ก</h2>
  <?php if (!empty($child['pending_edit_json']) && (($child['approve_profile'] ?? '') === 'กำลังดำเนินการ')): ?>
  <div class="alert" style="background:#fff8e6;border:1px solid #e8d48b;color:#5c4a1a;padding:12px 14px;border-radius:10px;margin-bottom:14px;">
    คำขอแก้ไขของคุณรอแอดมินตรวจสอบ — ข้อมูลด้านล่างเป็นชุดที่ส่งรออนุมัติ
  </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">
    <div class="grid">
      <div class="group">
        <label>ชื่อเล่นเด็ก</label>
        <input type="text" name="child_name" required value="<?php echo htmlspecialchars($child['child_name'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>วันเกิด</label>
        <input type="date" name="birth_date" required value="<?php echo htmlspecialchars($child['birth_date'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>ระดับการศึกษา</label>
        <input type="text" name="education" required value="<?php echo htmlspecialchars($child['education'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>ความฝัน</label>
        <input type="text" name="dream" required value="<?php echo htmlspecialchars($child['dream'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>สิ่งที่ชอบ</label>
        <input type="text" name="likes" value="<?php echo htmlspecialchars($child['likes'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>หมวดหมู่สิ่งที่ต้องการ</label>
        <input type="text" name="wish_cat" required value="<?php echo htmlspecialchars($child['wish_cat'] ?? ''); ?>">
      </div>
      <div class="group full">
        <label>สิ่งที่อยากขอ / ความต้องการ</label>
        <textarea name="wish" required><?php echo htmlspecialchars($child['wish'] ?? ''); ?></textarea>
      </div>
      <div class="group">
        <label>ธนาคาร</label>
        <input type="text" name="bank_name" required value="<?php echo htmlspecialchars($child['bank_name'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>เลขบัญชี 10 หลัก</label>
        <input type="text" name="child_bank" required maxlength="10" pattern="\d{10}" value="<?php echo htmlspecialchars($child['child_bank'] ?? ''); ?>">
      </div>
      <div class="group">
        <label>เปลี่ยนรูปภาพเด็ก (ถ้ามี)</label>
        <input type="file" name="photo_child" accept="image/*">
      </div>
    </div>

    <div class="actions">
      <button type="submit" class="btn btn-save">บันทึกการแก้ไข</button>
      <a href="children_.php" class="btn btn-back">ย้อนกลับ</a>
    </div>
  </form>
</div>
</body>
</html>
