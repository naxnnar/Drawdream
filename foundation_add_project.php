<?php
// ไฟล์นี้: foundation_add_project.php
// หน้าที่: หน้ามูลนิธิสำหรับสร้างหรือแก้ไขโครงการ
session_start();
include 'db.php';
require_once __DIR__ . '/includes/address_helpers.php';

// วันที่เสนอโครงการ / ช่วงแสดงใน UI
$chkStart = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'start_date'");
if ($chkStart && $chkStart->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN start_date DATE NULL DEFAULT NULL");
}

// เลิกใช้คอลัมน์ donation_start_date — ย้ายค่าไป start_date ก่อนลบคอลัมน์
$chkDonStart = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'donation_start_date'");
if ($chkDonStart && $chkDonStart->num_rows > 0) {
    $conn->query('UPDATE foundation_project SET start_date = DATE(donation_start_date) WHERE start_date IS NULL AND donation_start_date IS NOT NULL');
    $conn->query('ALTER TABLE foundation_project DROP COLUMN donation_start_date');
}

$chkFid = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'foundation_id'");
if ($chkFid && $chkFid->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN foundation_id INT UNSIGNED NULL DEFAULT NULL AFTER foundation_name");
}

$chkPe = $conn->query("SHOW COLUMNS FROM foundation_project WHERE Field = 'pending_edit_json'");
if ($chkPe && $chkPe->num_rows > 0) {
    $conn->query('ALTER TABLE foundation_project DROP COLUMN pending_edit_json');
}

$chkMrg = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'merged_into_project_id'");
if ($chkMrg && $chkMrg->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN merged_into_project_id INT UNSIGNED NULL DEFAULT NULL");
}

$chkNeed = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'need_info'");
if ($chkNeed && $chkNeed->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN need_info TEXT NULL DEFAULT NULL");
}
$chkUpdInf = $conn->query("SHOW COLUMNS FROM foundation_project LIKE 'update_info'");
if ($chkUpdInf && $chkUpdInf->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN update_info TEXT NULL DEFAULT NULL");
}

$chkProjLoc = $conn->query("SHOW COLUMNS FROM foundation_project WHERE Field = 'location'");
if ($chkProjLoc && $chkProjLoc->num_rows === 0) {
    $conn->query("ALTER TABLE foundation_project ADD COLUMN location TEXT NULL DEFAULT NULL AFTER need_info");
}
// เคยบันทึกพื้นที่ผิดลง update_info — ย้ายไป location ถ้ายังว่าง แล้วล้าง update_info บนโครงการที่ยังไม่จบ
$conn->query(
    "UPDATE foundation_project SET location = TRIM(update_info)
     WHERE LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('completed','done')
       AND (location IS NULL OR TRIM(COALESCE(location,'')) = '')
       AND update_info IS NOT NULL AND TRIM(update_info) <> ''"
);
$conn->query("UPDATE foundation_project SET update_info = NULL WHERE LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('completed','done')");

$conn->query(
    "UPDATE foundation_project p
     INNER JOIN foundation_profile f ON f.foundation_name = p.foundation_name AND f.foundation_id IS NOT NULL
     SET p.foundation_id = f.foundation_id
     WHERE p.foundation_id IS NULL OR p.foundation_id = 0"
);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: project.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFp = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp JOIN `user` u ON u.user_id = fp.user_id WHERE fp.user_id = ? LIMIT 1");
$stmtFp->bind_param("i", $uid);
$stmtFp->execute();
$fp = $stmtFp->get_result()->fetch_assoc();

$foundationName = trim((string)($fp['foundation_name'] ?? ''));
$foundationId = (int)($fp['foundation_id'] ?? 0);
if ($foundationName === '') {
    echo "<script>alert('ไม่พบข้อมูลมูลนิธิ กรุณาอัปเดตโปรไฟล์ก่อน'); window.location='update_profile.php';</script>";
    exit();
}
if ($foundationId <= 0) {
    echo "<script>alert('ไม่พบรหัสมูลนิธิในระบบ กรุณาอัปเดตโปรไฟล์หรือติดต่อผู้ดูแล'); window.location='update_profile.php';</script>";
    exit();
}

$categories = ['การศึกษา', 'สุขภาพและอนามัย', 'อาหารและโภชนาการ', 'สิ่งอำนวยความสะดวก'];

$targetGroupOptions = [
    'เด็กและเยาวชน',
    'สถานศึกษา',
    'ค่ายผู้ลี้ภัย',
    'โรงพยาบาล',
    'ชุมชน',
    'องค์กรการกุศล',
];

$editProjectId = (int)($_GET['edit'] ?? 0);
$isEditMode = false;
$editingProject = [
    'project_id' => 0,
    'project_name' => '',
    'project_desc' => '',
    'project_image' => '',
    'goal_amount' => '',
    'end_date' => '',
    'category' => '',
    'target_group' => '',
    'project_quote' => '',
    'need_info' => '',
    'location' => '',
];

if ($editProjectId > 0) {
    $stmtEdit = $conn->prepare(
    "SELECT *
         FROM foundation_project
         WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmtEdit->bind_param("is", $editProjectId, $foundationName);
    $stmtEdit->execute();
    $editRow = $stmtEdit->get_result()->fetch_assoc();

    if (!$editRow) {
        echo "<script>alert('ไม่พบโครงการที่ต้องการแก้ไข'); window.location='project.php?view=foundation';</script>";
        exit();
    }

    $isEditMode = true;
    $editingProject = array_merge($editingProject, $editRow);
}

$project_thai_addr_init_json = 'null';
$project_addr_parsed = null;
if ($isEditMode && trim((string)($editingProject['location'] ?? '')) !== '') {
    $project_addr_parsed = drawdream_parse_saved_thai_address($editingProject['location']);
    if ($project_addr_parsed) {
        $project_thai_addr_init_json = json_encode([
            'province' => $project_addr_parsed['province'],
            'amphoe'   => $project_addr_parsed['amphoe'],
            'tambon'   => $project_addr_parsed['tambon'],
            'zip'      => $project_addr_parsed['zip'],
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (isset($_POST['submit'])) {
    $editingId = (int)($_POST['edit_project_id'] ?? 0);
    $isEditSubmit = $editingId > 0;

    // ข้อมูลหลักโครงการ
    $category = $_POST['category'] ?? '';
    $targetGroup = trim($_POST['target_group'] ?? '');
    $name = trim($_POST['project_name'] ?? '');
    $desc = trim($_POST['project_desc'] ?? '');
    $quote = trim($_POST['project_quote'] ?? '');
    $goal = (int)($_POST['goal_amount'] ?? 0);
    $enddate = trim($_POST['end_date'] ?? '');

    // ข้อมูลกล่องรายละเอียดหน้าโครงการ (พื้นที่ = คอลัมน์ location รูปแบบ ต./อ./จ. — ใช้ค้นกับตัวกรองจังหวัดในหน้าโครงการ)
    $needInfo = trim($_POST['need_info'] ?? '');
    $projectLocation = drawdream_merge_foundation_address_from_post($_POST);


    if (!in_array($category, $categories, true)) {
        echo "<script>alert('กรุณาเลือกประเภทโครงการ'); history.back();</script>";
        exit();
    }

    if (!in_array($targetGroup, $targetGroupOptions, true)) {
        echo "<script>alert('กรุณาเลือกกลุ่มเป้าหมายที่ได้รับประโยชน์จากโครงการ'); history.back();</script>";
        exit();
    }

    if ($name === '' || $desc === '' || $quote === '' || $goal <= 0 || $enddate === '') {
        echo "<script>alert('กรุณากรอกข้อมูลโครงการให้ครบ'); history.back();</script>";
        exit();
    }

    $tzBangkok = new DateTimeZone('Asia/Bangkok');
    $dEndDt = DateTimeImmutable::createFromFormat('Y-m-d', $enddate, $tzBangkok);
    if (!$dEndDt || $dEndDt->format('Y-m-d') !== $enddate) {
        echo "<script>alert('รูปแบบวันที่ไม่ถูกต้อง'); history.back();</script>";
        exit();
    }

    if ($needInfo === '') {
        echo "<script>alert('กรุณากรอกแผนการดำเนินงาน'); history.back();</script>";
        exit();
    }
    if ($projectLocation === '') {
        echo "<script>alert('กรุณากรอกพื้นที่ดำเนินโครงการ'); history.back();</script>";
        exit();
    }

    $currentProjectImage = '';
    $projectStatus = '';
    if ($isEditSubmit) {
        $stmtCurrent = $conn->prepare("SELECT project_image, start_date, project_status FROM foundation_project WHERE project_id = ? AND foundation_name = ? AND deleted_at IS NULL LIMIT 1");
        $stmtCurrent->bind_param("is", $editingId, $foundationName);
        $stmtCurrent->execute();
        $currentProjectRow = $stmtCurrent->get_result()->fetch_assoc();

        if (!$currentProjectRow) {
            echo "<script>alert('ไม่พบโครงการที่ต้องการแก้ไข'); window.location='project.php?view=foundation';</script>";
            exit();
        }
        $currentProjectImage = (string)($currentProjectRow['project_image'] ?? '');
        $projectStatus = strtolower(trim((string)($currentProjectRow['project_status'] ?? '')));
    }

    $enddateNorm = $dEndDt->format('Y-m-d');

    $newName = $currentProjectImage;
    if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] === 0) {
        $imageName = $_FILES['project_image']['name'];
        $tmpName = $_FILES['project_image']['tmp_name'];
        $ext = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed, true)) {
            echo "<script>alert('อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp เท่านั้น'); history.back();</script>";
            exit();
        }

        $newName = time() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "", $imageName);
        if (!move_uploaded_file($tmpName, "uploads/" . $newName)) {
            echo "<script>alert('อัปโหลดรูปไม่สำเร็จ'); history.back();</script>";
            exit();
        }
    } elseif (!$isEditSubmit) {
        echo "<script>alert('กรุณาอัปโหลดรูปภาพให้ถูกต้อง'); history.back();</script>";
        exit();
    }

    if ($isEditSubmit && in_array($projectStatus, ['completed', 'done'], true)) {
        echo "<script>alert('ไม่สามารถแก้ไขโครงการที่สำเร็จแล้ว'); history.back();</script>";
        exit();
    }

    mysqli_begin_transaction($conn);
    try {
        $goalDec = (float)$goal;
        $successMessage = 'เสนอโครงการสำเร็จ (รอแอดมินอนุมัติ)';

        if ($isEditSubmit) {
            // คำสั่งเดียว: อัปเดตคอลัมน์จริงเสมอ (ไม่ใช้คิว pending_edit)
            // ไม่เช็ก affected_rows — ถ้าค่าในฟอร์มเหมือนเดิม MySQL จะคืน 0 แถวที่เปลี่ยน แต่ถือว่าบันทึกสำเร็จ
            $stmtPre = $conn->prepare(
                "SELECT 1 FROM foundation_project
                 WHERE project_id = ? AND foundation_name = ?
                   AND deleted_at IS NULL
                   AND LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('completed','done')
                 LIMIT 1"
            );
            $stmtPre->bind_param("is", $editingId, $foundationName);
            $stmtPre->execute();
            if (!$stmtPre->get_result()->fetch_row()) {
                throw new Exception('ไม่พบโครงการหรือไม่สามารถแก้ไขสถานะนี้ได้');
            }

            $stmtProject = $conn->prepare(
                "UPDATE foundation_project
                 SET project_name = ?, project_desc = ?, project_image = ?,
                     goal_amount = ?, end_date = ?,
                     category = ?, target_group = ?,
                     project_quote = ?,
                     need_info = ?, location = ?,
                     foundation_id = ?,
                     project_status = CASE
                         WHEN LOWER(TRIM(COALESCE(project_status,''))) = 'rejected' THEN 'pending'
                         ELSE project_status
                     END
                 WHERE project_id = ? AND foundation_name = ?
                   AND deleted_at IS NULL
                   AND LOWER(TRIM(COALESCE(project_status,''))) NOT IN ('completed','done')"
            );
            $stmtProject->bind_param(
                "sssdssssssiis",
                $name, $desc, $newName,
                $goalDec, $enddateNorm,
                $category, $targetGroup,
                $quote,
                $needInfo, $projectLocation,
                $foundationId,
                $editingId, $foundationName
            );
            if (!$stmtProject->execute()) {
                throw new Exception($stmtProject->error ?: 'แก้ไขโครงการไม่สำเร็จ');
            }
            $projectId = $editingId;
            $successMessage = ($projectStatus === 'rejected')
                ? 'บันทึกและส่งโครงการให้แอดมินพิจารณาใหม่แล้ว'
                : 'บันทึกการแก้ไขโครงการสำเร็จ';
        } else {
            // สร้างโครงการใหม่ — บันทึกทุก field ลงตาราง foundation_project เดียว (ไม่มี donation_option_1,2,3)
            $stmtProject = $conn->prepare(
                "INSERT INTO foundation_project
                    (project_name, project_desc, project_image, goal_amount, end_date,
                     project_status, current_donate, start_date, foundation_id, foundation_name,
                     category, target_group, project_quote,
                     need_info, location)
                 VALUES (?, ?, ?, ?, ?, 'pending', 0, CURDATE(), ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtProject->bind_param(
                "sssdsissssss",
                $name, $desc, $newName, $goalDec, $enddateNorm,
                $foundationId, $foundationName,
                $category, $targetGroup, $quote,
                $needInfo, $projectLocation
            );
            if (!$stmtProject->execute()) {
                throw new Exception($stmtProject->error ?: 'บันทึกโครงการไม่สำเร็จ');
            }
            $projectId = (int)$conn->insert_id;
        }

        mysqli_commit($conn);

        if (!$isEditSubmit) {
            require_once __DIR__ . '/includes/notification_audit.php';
            drawdream_ensure_notifications_table($conn);
            foreach (drawdream_admin_user_ids($conn) as $adminUid) {
                drawdream_send_notification(
                    $conn,
                    $adminUid,
                    'admin_project_pending',
                    'มีโครงการรออนุมัติ',
                    'มูลนิธิส่งคำขอโครงการ: ' . $name,
                    'admin_approve_projects.php',
                    'adm_pending_project:' . $projectId
                );
            }
            drawdream_record_foundation_submitted_project($conn, $uid, $projectId, $name);
        }

        echo "<script>alert('" . addslashes($successMessage) . "'); window.location='project.php?view=foundation';</script>";
        exit();
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo "<script>alert('บันทึกข้อมูลไม่สำเร็จ: " . addslashes($e->getMessage()) . "'); history.back();</script>";
        exit();
    }
}

$missingContact = [];
if (empty($fp['phone'])) $missingContact[] = 'เบอร์โทร';
if (empty($fp['address'])) $missingContact[] = 'ที่อยู่';
if (empty($fp['email'])) $missingContact[] = 'อีเมล';
if (empty($fp['website']) && empty($fp['facebook_url']) && empty($fp['line_id'])) {
    $missingContact[] = 'ช่องทางติดต่อออนไลน์ (เว็บไซต์/Facebook/Line)';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEditMode ? 'แก้ไขโครงการ' : 'เสนอโครงการ' ?> | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/project.css">
    <link rel="stylesheet" href="css/thai_address.css?v=1">
</head>
<body class="project-form-page">

<?php include 'navbar.php'; ?>

<?php if (!empty($missingContact)): ?>
    <div style="max-width:1100px;margin:20px auto 0;padding:12px 16px;border-left:4px solid #E8A020;background:#fff8e8;border-radius:10px;font-family:'Sarabun',sans-serif;color:#6a5400;">
        ข้อมูลติดต่อในโปรไฟล์มูลนิธิยังไม่ครบ: <?= htmlspecialchars(implode(', ', $missingContact)) ?>
        <a href="update_profile.php" style="margin-left:10px;color:#3C5099;font-weight:700;">ไปอัปเดตโปรไฟล์</a>
    </div>
<?php endif; ?>

<div class="form-container">
    <!-- ── ซ้าย: preview รูป + ข้อมูลติดต่อ ── -->
    <div class="left-box">
        <h2><?= $isEditMode ? 'แก้ไขโครงการ' : 'เสนอโครงการ' ?></h2>
        <p class="left-foundation-name">มูลนิธิ <?= htmlspecialchars($foundationName) ?></p>

        <div class="upload-box" id="imagePreviewBox"<?php if (!empty($editingProject['project_image'])): ?> style="background-image:url('uploads/<?= htmlspecialchars($editingProject['project_image']) ?>');"<?php endif; ?>>
            <?= empty($editingProject['project_image']) ? 'ตัวอย่างรูปภาพโครงการ' : '' ?>
        </div>

        <div class="left-info-card">
            <h3>ภาพโครงการ<?= $isEditMode ? '' : ' *' ?></h3>
            <input type="file" name="project_image" id="projectImageInput" accept="image/*" form="projectForm" <?= $isEditMode ? '' : 'required' ?>>
        </div>

        <div class="left-info-card">
            <h3>ข้อมูลติดต่อที่จะโชว์ในหน้าโครงการ</h3>
            <p>👤 ผู้ติดต่อ <?= htmlspecialchars($fp['contact_person'] ?? '-') ?></p>
            <p>📞 เบอร์หลัก <?= htmlspecialchars($fp['phone'] ?? '-') ?></p>
            <p>📱 เบอร์รอง <?= htmlspecialchars($fp['phone_secondary'] ?? '-') ?></p>
            <p>✉️ อีเมล <?= htmlspecialchars($fp['email'] ?? '-') ?></p>
            <p>🌐 เว็บไซต์ <?= htmlspecialchars($fp['website'] ?? '-') ?></p>
            <p>📘 Facebook <?= htmlspecialchars($fp['facebook_url'] ?? '-') ?></p>
            <p>💬 Line <?= htmlspecialchars($fp['line_id'] ?? '-') ?></p>
            <p>📍 ที่อยู่ <?= htmlspecialchars($fp['address'] ?? '-') ?></p>
        </div>
    </div>

    <!-- ── ขวา: ฟอร์มกรอกข้อมูล ── -->
    <div class="right-box">
        <form method="POST" enctype="multipart/form-data" id="projectForm">
            <?php if ($isEditMode): ?>
                <input type="hidden" name="edit_project_id" value="<?= (int)$editingProject['project_id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label>ประเภทโครงการ </label>
                <select name="category" required>
                    <option value="">-- เลือกประเภท --</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= (($editingProject['category'] ?? '') === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>กลุ่มเป้าหมายที่ได้รับประโยชน์จากโครงการ </label>
                <select name="target_group" required>
                    <option value="">-- เลือกกลุ่มเป้าหมาย --</option>
                    <?php foreach ($targetGroupOptions as $tg): ?>
                        <option value="<?= htmlspecialchars($tg) ?>" <?= (($editingProject['target_group'] ?? '') === $tg) ? 'selected' : '' ?>><?= htmlspecialchars($tg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>หัวข้อโครงการ </label>
                <input type="text" name="project_name" placeholder="ชื่อโครงการที่ต้องการนำเสนอ" value="<?= htmlspecialchars($editingProject['project_name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>คำโปรย / ข้อความนำเสนอ </label>
                <textarea name="project_quote" rows="3" placeholder="ประโยคสั้นๆ ดึงดูดใจผู้บริจาค" required><?= htmlspecialchars($editingProject['project_quote'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>รายละเอียดโครงการ </label>
                <textarea name="project_desc" rows="5" placeholder="อธิบายรายละเอียดโครงการ" required><?= htmlspecialchars($editingProject['project_desc'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>เป้าหมาย (บาท) </label>
                <input type="number" name="goal_amount" min="1" placeholder="จำนวนเงินที่ต้องการระดมทุน" value="<?= htmlspecialchars((string)($editingProject['goal_amount'] ?? '')) ?>" required>
            </div>


            <div class="form-group">
                <label>วันสิ้นสุดรับบริจาค </label>
                <input type="date" name="end_date" id="donationEndDate" value="<?= htmlspecialchars(substr((string)($editingProject['end_date'] ?? ''), 0, 10)) ?>" required>
            </div>

            <div class="form-group">
                <label>แผนการดำเนินงาน </label>
                <textarea name="need_info" rows="3" placeholder="สิ่งที่มูลนิธิวางแผนในการดำเนินงาน" required><?= htmlspecialchars($editingProject['need_info'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>พื้นที่ดำเนินโครงการ</label>
                <?php
                $thai_address_options = ['require' => true];
                include __DIR__ . '/includes/thai_address_fields.php';
                ?>
                <?php if ($project_addr_parsed === null && $isEditMode && trim((string)($editingProject['location'] ?? '')) !== ''): ?>
                    <p class="form-hint" style="margin:8px 0 0;font-size:0.9em;color:#555;">ที่อยู่เดิม (ข้อความ): <?= htmlspecialchars($editingProject['location']) ?> — กรุณาเลือกจากรายการด้านบนให้ครบเพื่อเชื่อมกับการกรองตำแหน่งในหน้าโครงการ</p>
                <?php endif; ?>
            </div>

            <button type="submit" name="submit" class="btn-submit">บันทึกข้อมูล</button>
        </form>
    </div>
</div>

<script>
// แสดงตัวอย่างรูปในกล่องซ้ายเพื่อให้เห็นภาพก่อนส่ง
(function() {
    var input = document.getElementById('projectImageInput');
    var previewBox = document.getElementById('imagePreviewBox');
    if (!input || !previewBox) return;
    input.addEventListener('change', function() {
        var file = this.files && this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            previewBox.style.backgroundImage = 'url(' + e.target.result + ')';
            previewBox.style.backgroundSize = 'cover';
            previewBox.style.backgroundPosition = 'center';
            previewBox.textContent = '';
        };
        reader.readAsDataURL(file);
    });
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
            initial: <?= $project_thai_addr_init_json ?>
        });
    }
});
</script>

</body>
</html>
