<?php
// foundation_add_project.php — มูลนิธิเสนอ/แก้ไขโครงการ

// สรุปสั้น: ไฟล์นี้จัดการงานมูลนิธิส่วน add project

include 'db.php';
require_once __DIR__ . '/includes/address_helpers.php';
require_once __DIR__ . '/includes/vendor_assets.php';
require_once __DIR__ . '/includes/drawdream_upload.php';
require_once __DIR__ . '/includes/drawdream_image_compress.php';

$projectMaxUploadBytes = drawdream_child_photo_max_upload_bytes();
$projectMaxUploadLabel = drawdream_format_bytes_mb_label($projectMaxUploadBytes);

function drawdream_foundation_add_project_is_post_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && (isset($_POST['foundation_project_save']) || isset($_POST['submit']));
}

/**
 * บันทึกข้อมูลฟอร์มชั่วคราวแล้ว redirect — ไม่ใช้ history.back() เพื่อไม่ให้ข้อมูลหาย
 */
function drawdream_foundation_add_project_fail(string $message, int $editId = 0): never
{
    $_SESSION['foundation_add_project_flash'] = $_POST;
    $_SESSION['foundation_add_project_flash_error'] = $message;
    $url = 'foundation_add_project.php';
    if ($editId > 0) {
        $url .= '?edit=' . $editId;
    }
    header('Location: ' . $url);
    exit;
}

/** @return array<string,string> */
function drawdream_foundation_add_project_upload_error_message(int $code): array
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'ไฟล์รูปใหญ่เกินกำหนดของเซิร์ฟเวอร์ — ลองบีบอัดรูปแล้วอัปโหลดใหม่',
        UPLOAD_ERR_FORM_SIZE => 'ไฟล์รูปใหญ่เกินกำหนดของฟอร์ม — ลองบีบอัดรูปแล้วอัปโหลดใหม่',
        UPLOAD_ERR_PARTIAL => 'อัปโหลดรูปไม่สมบูรณ์ — กรุณาลองใหม่',
        UPLOAD_ERR_NO_FILE => 'กรุณาอัปโหลดรูปภาพโครงการ',
        UPLOAD_ERR_NO_TMP_DIR => 'เซิร์ฟเวอร์ไม่พร้อมรับไฟล์ชั่วคราว — ติดต่อผู้ดูแลระบบ',
        UPLOAD_ERR_CANT_WRITE => 'บันทึกไฟล์รูปไม่สำเร็จ — ลองใหม่อีกครั้ง',
    ];

    return ['msg' => $map[$code] ?? 'อัปโหลดรูปไม่สำเร็จ (รหัส ' . $code . ')'];
}

require_once __DIR__ . '/includes/foundation_donor_preview.php';
drawdream_foundation_require_management_access();

require_once __DIR__ . '/includes/foundation_account_verified.php';
drawdream_foundation_require_active_account($conn);

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFp = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp JOIN `user` u ON u.user_id = fp.user_id WHERE fp.user_id = ? LIMIT 1");
$stmtFp->bind_param("i", $uid);
$stmtFp->execute();
$fp = $stmtFp->get_result()->fetch_assoc();

$foundationName = trim((string)($fp['foundation_name'] ?? ''));
$foundationId = (int)($fp['foundation_id'] ?? 0);
if ($foundationName === '') {
    header('Location: update_profile.php?msg=' . rawurlencode('ไม่พบข้อมูลมูลนิธิ กรุณาอัปเดตโปรไฟล์ก่อน'));
    exit();
}
if ($foundationId <= 0) {
    header('Location: update_profile.php?msg=' . rawurlencode('ไม่พบรหัสมูลนิธิในระบบ กรุณาอัปเดตโปรไฟล์หรือติดต่อผู้ดูแล'));
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
$formFlashError = '';
$formFlash = null;
if (!empty($_SESSION['foundation_add_project_flash']) && is_array($_SESSION['foundation_add_project_flash'])) {
    $formFlash = $_SESSION['foundation_add_project_flash'];
    unset($_SESSION['foundation_add_project_flash']);
}
if (!empty($_SESSION['foundation_add_project_flash_error'])) {
    $formFlashError = trim((string)$_SESSION['foundation_add_project_flash_error']);
    unset($_SESSION['foundation_add_project_flash_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !drawdream_foundation_add_project_is_post_request()
    && $formFlashError === ''
    && $_POST === []
    && (!isset($_FILES['project_image']) || (int)($_FILES['project_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
) {
    $formFlashError = 'ส่งข้อมูลไม่สำเร็จ (ไฟล์อาจใหญ่เกินกำหนดของเซิร์ฟเวอร์) กรุณาบีบอัดรูปแล้วลองใหม่';
}
if (is_array($formFlash) && (int)($formFlash['edit_project_id'] ?? 0) > 0) {
    $editProjectId = (int)$formFlash['edit_project_id'];
}

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
         WHERE project_id = ? AND foundation_name = ?
         LIMIT 1"
    );
    $stmtEdit->bind_param("is", $editProjectId, $foundationName);
    $stmtEdit->execute();
    $editRow = $stmtEdit->get_result()->fetch_assoc();

    if (!$editRow) {
        header('Location: project.php?view=foundation&msg=' . rawurlencode('ไม่พบโครงการที่ต้องการแก้ไข'));
        exit();
    }

    $isEditMode = true;
    $editingProject = array_merge($editingProject, $editRow);
}

if (is_array($formFlash)) {
    $editingProject = array_merge($editingProject, [
        'project_name' => trim((string)($formFlash['project_name'] ?? $editingProject['project_name'] ?? '')),
        'project_desc' => trim((string)($formFlash['project_desc'] ?? $editingProject['project_desc'] ?? '')),
        'project_quote' => trim((string)($formFlash['project_quote'] ?? $editingProject['project_quote'] ?? '')),
        'goal_amount' => trim((string)($formFlash['goal_amount'] ?? $editingProject['goal_amount'] ?? '')),
        'end_date' => trim((string)($formFlash['end_date'] ?? $editingProject['end_date'] ?? '')),
        'category' => trim((string)($formFlash['category'] ?? $editingProject['category'] ?? '')),
        'target_group' => trim((string)($formFlash['target_group'] ?? $editingProject['target_group'] ?? '')),
        'need_info' => trim((string)($formFlash['need_info'] ?? $editingProject['need_info'] ?? '')),
    ]);
    if (!$isEditMode && $editProjectId > 0) {
        $isEditMode = true;
        $editingProject['project_id'] = $editProjectId;
    }
}

$project_thai_addr_init_json = 'null';
$project_addr_parsed = null;
$project_addr_line = ['house_no' => '', 'soi' => '', 'road' => ''];
$project_addr_full_hidden = '';
$tzBangkok = new DateTimeZone('Asia/Bangkok');
$todayProposalMin = (new DateTimeImmutable('now', $tzBangkok))->format('Y-m-d');
if ($isEditMode && trim((string)($editingProject['location'] ?? '')) !== '') {
    $project_addr_parsed = drawdream_parse_saved_thai_address($editingProject['location']);
    if ($project_addr_parsed) {
        $project_addr_line = [
            'house_no' => $project_addr_parsed['house_no'] ?? '',
            'soi' => $project_addr_parsed['soi'] ?? '',
            'road' => $project_addr_parsed['road'] ?? '',
        ];
        $project_thai_addr_init_json = json_encode([
            'province' => $project_addr_parsed['province'],
            'amphoe'   => $project_addr_parsed['amphoe'],
            'tambon'   => $project_addr_parsed['tambon'],
            'zip'      => $project_addr_parsed['zip'],
        ], JSON_UNESCAPED_UNICODE);
    }
}
if (is_array($formFlash)) {
    $project_addr_line = [
        'house_no' => trim((string)($formFlash['addr_house_no'] ?? $project_addr_line['house_no'] ?? '')),
        'soi' => trim((string)($formFlash['addr_soi'] ?? $project_addr_line['soi'] ?? '')),
        'road' => trim((string)($formFlash['addr_road'] ?? $project_addr_line['road'] ?? '')),
    ];
    $flashProvince = trim((string)($formFlash['addr_province'] ?? ''));
    $flashAmphoe = trim((string)($formFlash['addr_amphoe'] ?? ''));
    $flashTambon = trim((string)($formFlash['addr_tambon'] ?? ''));
    $flashZip = trim((string)($formFlash['addr_zip'] ?? ''));
    $project_addr_full_hidden = trim((string)($formFlash['addr_full_hidden'] ?? ''));
    if ($flashProvince !== '') {
        $tambonLabel = $flashTambon;
        if ($tambonLabel !== '' && strpos($tambonLabel, "\x1E") !== false) {
            $parts = explode("\x1E", $tambonLabel, 2);
            if ($flashZip === '' && ($parts[0] ?? '') !== '') {
                $flashZip = $parts[0];
            }
            $tambonLabel = $parts[1] ?? $tambonLabel;
        }
        $project_thai_addr_init_json = json_encode([
            'province' => $flashProvince,
            'amphoe' => $flashAmphoe,
            'tambon' => $tambonLabel,
            'zip' => $flashZip,
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (drawdream_foundation_add_project_is_post_request()) {
    drawdream_csrf_require_valid('foundation_add_project.php');
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

    if (!in_array($category, $categories, true)) {
        drawdream_foundation_add_project_fail('กรุณาเลือกประเภทโครงการ', $editingId);
    }

    if (!in_array($targetGroup, $targetGroupOptions, true)) {
        drawdream_foundation_add_project_fail('กรุณาเลือกกลุ่มเป้าหมายที่ได้รับประโยชน์จากโครงการ', $editingId);
    }

    if ($name === '' || $desc === '' || $quote === '' || $goal <= 0 || $enddate === '') {
        drawdream_foundation_add_project_fail('กรุณากรอกข้อมูลโครงการให้ครบ', $editingId);
    }

    $dEndDt = DateTimeImmutable::createFromFormat('Y-m-d', $enddate, $tzBangkok);
    if (!$dEndDt || $dEndDt->format('Y-m-d') !== $enddate) {
        drawdream_foundation_add_project_fail('รูปแบบวันที่ไม่ถูกต้อง', $editingId);
    }
    if ($dEndDt->format('Y-m-d') < $todayProposalMin) {
        drawdream_foundation_add_project_fail('วันสิ้นสุดรับบริจาคต้องเป็นวันนี้หรือหลังจากวันนี้เท่านั้น', $editingId);
    }

    if ($needInfo === '') {
        drawdream_foundation_add_project_fail('กรุณากรอกแผนการดำเนินงาน', $editingId);
    }

    $addrProvince = trim((string)($_POST['addr_province'] ?? ''));
    $addrAmphoe = trim((string)($_POST['addr_amphoe'] ?? ''));
    $addrTambon = trim((string)($_POST['addr_tambon'] ?? ''));
    $addrZip = trim((string)($_POST['addr_zip'] ?? ''));
    $addrHidden = trim((string)($_POST['addr_full_hidden'] ?? ''));
    $projectLocation = drawdream_merge_foundation_address_from_post($_POST);
    if ($projectLocation === '' || ($addrProvince === '' && $addrHidden === '') || ($addrAmphoe === '' && $addrHidden === '')) {
        drawdream_foundation_add_project_fail('กรุณาเลือกจังหวัด อำเภอ ตำบล และรหัสไปรษณีย์ให้ครบ', $editingId);
    }
    if ($addrTambon === '' && $addrZip === '' && $addrHidden === '') {
        drawdream_foundation_add_project_fail('กรุณาเลือกตำบลและรหัสไปรษณีย์ให้ครบ', $editingId);
    }

    $currentProjectImage = '';
    $projectStatus = '';
    if ($isEditSubmit) {
        $stmtCurrent = $conn->prepare("SELECT project_image, start_date, project_status FROM foundation_project WHERE project_id = ? AND foundation_name = ? LIMIT 1");
        $stmtCurrent->bind_param("is", $editingId, $foundationName);
        $stmtCurrent->execute();
        $currentProjectRow = $stmtCurrent->get_result()->fetch_assoc();

        if (!$currentProjectRow) {
            header('Location: project.php?view=foundation&msg=' . rawurlencode('ไม่พบโครงการที่ต้องการแก้ไข'));
            exit();
        }
        $currentProjectImage = (string)($currentProjectRow['project_image'] ?? '');
        $projectStatus = strtolower(trim((string)($currentProjectRow['project_status'] ?? '')));
    }

    $enddateNorm = $dEndDt->format('Y-m-d');

    $newName = $currentProjectImage;
    if (isset($_FILES['project_image']) && (int)($_FILES['project_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $imageName = (string)($_FILES['project_image']['name'] ?? '');
        $tmpName = (string)($_FILES['project_image']['tmp_name'] ?? '');
        $fileSize = (int)($_FILES['project_image']['size'] ?? 0);

        if (!drawdream_upload_is_image_tmp($tmpName, $imageName)) {
            drawdream_foundation_add_project_fail('อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp เท่านั้น', $editingId);
        }

        $uploadDir = __DIR__ . '/uploads/project/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $forceJpeg = drawdream_upload_needs_jpeg_output($tmpName, $imageName, $fileSize, $projectMaxUploadBytes);
        $outExt = $forceJpeg ? 'jpg' : drawdream_upload_resolve_image_ext($tmpName, $imageName);
        $newName = 'project/project_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $outExt;
        $targetPath = __DIR__ . '/uploads/' . $newName;
        if (!drawdream_store_compressed_upload($tmpName, $targetPath, $projectMaxUploadBytes, $forceJpeg)) {
            drawdream_foundation_add_project_fail('บีบอัด/อัปโหลดรูปไม่สำเร็จ — ลองบันทึกเป็น JPG แล้วอัปโหลดใหม่', $editingId);
        }
    } elseif (!$isEditSubmit) {
        $uploadErr = (int)($_FILES['project_image']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $uploadMsg = drawdream_foundation_add_project_upload_error_message($uploadErr);
            drawdream_foundation_add_project_fail($uploadMsg['msg'], $editingId);
        }
        drawdream_foundation_add_project_fail('กรุณาอัปโหลดรูปภาพโครงการ', $editingId);
    }

    if ($isEditSubmit && in_array($projectStatus, ['completed', 'done'], true)) {
        drawdream_foundation_add_project_fail('ไม่สามารถแก้ไขโครงการที่สำเร็จแล้ว', $editingId);
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
                    'admin_approve_projects.php?id=' . $projectId,
                    'adm_pending_project:' . $projectId
                );
            }
            drawdream_record_foundation_submitted_project($conn, $uid, $projectId, $name);
        }

        header('Location: project.php?view=foundation&msg=' . rawurlencode($successMessage) . '&msg_icon=success');
        exit();
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        drawdream_foundation_add_project_fail('บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage(), $editingId);
    }
}

$missingContact = [];
if (empty($fp['phone'])) $missingContact[] = 'เบอร์โทร';
if (empty($fp['address'])) $missingContact[] = 'ที่อยู่';
if (empty($fp['email'])) $missingContact[] = 'อีเมล';
if (empty($fp['website']) && empty($fp['facebook_url'])) {
    $missingContact[] = 'ช่องทางติดต่อออนไลน์ (เว็บไซต์/Facebook)';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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

<?php
$pageFlashMsg = trim((string)($_GET['msg'] ?? ''));
if ($pageFlashMsg === '' && $formFlashError !== '') {
    $pageFlashMsg = $formFlashError;
}
$pageFlashIcon = 'error';
if (isset($_GET['msg_icon'])) {
    $pageFlashIcon = match ((string)$_GET['msg_icon']) {
        'warning' => 'warning',
        'success' => 'success',
        default => 'error',
    };
}
if ($pageFlashMsg !== '' && !isset($_GET['msg_icon']) && $formFlashError !== '') {
    $pageFlashIcon = 'error';
}
?>

<form method="POST" enctype="multipart/form-data" id="projectForm" class="form-container">
        <input type="hidden" name="foundation_project_save" value="1">
        <?= drawdream_csrf_field() ?>
        <?php if ($isEditMode): ?>
            <input type="hidden" name="edit_project_id" value="<?= (int)$editingProject['project_id'] ?>">
        <?php endif; ?>

    <!-- ── ซ้าย: preview รูป + ข้อมูลติดต่อ ── -->
    <div class="left-box">
        <h2><?= $isEditMode ? 'แก้ไขโครงการ' : 'เสนอโครงการ' ?></h2>
        <p class="left-foundation-name">มูลนิธิ <?= htmlspecialchars($foundationName) ?></p>

        <div class="upload-box" id="imagePreviewBox"<?php if (!empty($editingProject['project_image'])): ?> style="background-image:url('<?= htmlspecialchars(drawdream_project_image_url((string)$editingProject['project_image'], 'uploads/'), ENT_QUOTES, 'UTF-8') ?>');"<?php endif; ?>>
            <?= empty($editingProject['project_image']) ? 'ตัวอย่างรูปภาพโครงการ' : '' ?>
        </div>

        <div class="left-info-card">
            <h3>ภาพโครงการ<?= $isEditMode ? '' : ' *' ?></h3>
            <input type="file" name="project_image" id="projectImageInput" accept="image/*" <?= $isEditMode ? '' : 'required' ?>>
            <p class="form-hint" style="margin:8px 0 0;font-size:0.88em;color:#555;">รองรับ JPG, PNG, GIF, WEBP — รูปใหญ่ระบบบีบอัดอัตโนมัติก่อนส่ง (ไม่เกิน <?= htmlspecialchars($projectMaxUploadLabel, ENT_QUOTES, 'UTF-8') ?>)</p>
        </div>

        <div class="left-info-card">
            <h3>ข้อมูลติดต่อที่จะโชว์ในหน้าโครงการ</h3>
            <p>📞 เบอร์หลัก <?= htmlspecialchars($fp['phone'] ?? '-') ?></p>
            <p>✉️ อีเมล <?= htmlspecialchars($fp['email'] ?? '-') ?></p>
            <p>🌐 เว็บไซต์ <?= htmlspecialchars($fp['website'] ?? '-') ?></p>
            <p>📘 Facebook <?= htmlspecialchars($fp['facebook_url'] ?? '-') ?></p>
            <p>📍 ที่อยู่ <?= htmlspecialchars($fp['address'] ?? '-') ?></p>
        </div>
    </div>

    <!-- ── ขวา: ฟอร์มกรอกข้อมูล ── -->
    <div class="right-box">
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
                <input type="date" name="end_date" id="donationEndDate" min="<?= htmlspecialchars($todayProposalMin, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(substr((string)($editingProject['end_date'] ?? ''), 0, 10)) ?>" required>
            </div>

            <div class="form-group">
                <label>แผนการดำเนินงาน </label>
                <textarea name="need_info" rows="3" placeholder="สิ่งที่มูลนิธิวางแผนในการดำเนินงาน" required><?= htmlspecialchars($editingProject['need_info'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>พื้นที่ดำเนินโครงการ</label>
                <?php
                $thai_address_options = [
                    'require' => true,
                    'line' => $project_addr_line,
                    'full_hidden' => $project_addr_full_hidden,
                ];
                include __DIR__ . '/includes/thai_address_fields.php';
                ?>
                <?php if ($project_addr_parsed === null && $isEditMode && trim((string)($editingProject['location'] ?? '')) !== ''): ?>
                    <p class="form-hint" style="margin:8px 0 0;font-size:0.9em;color:#555;">ที่อยู่เดิม (ข้อความ): <?= htmlspecialchars($editingProject['location']) ?> — กรุณาเลือกจากรายการด้านบนให้ครบเพื่อเชื่อมกับการกรองตำแหน่งในหน้าโครงการ</p>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-submit" id="projectSubmitBtn">บันทึกข้อมูล</button>
    </div>
</form>

<?php if ($pageFlashMsg !== ''): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            icon: <?= json_encode($pageFlashIcon, JSON_UNESCAPED_UNICODE) ?>,
            title: <?= json_encode($pageFlashMsg, JSON_UNESCAPED_UNICODE) ?>,
            confirmButtonText: 'ตกลง'
        });
    });
    </script>
<?php endif; ?>

<script src="js/drawdream-swal.js?v=1"></script>
<?= drawdream_sweetalert2_js_tag('', true) ?>
<script src="js/drawdream-image-compress.js?v=3"></script>
<script>
const MAX_PROJECT_IMAGE_BYTES = <?= (int)$projectMaxUploadBytes ?>;
const MAX_PROJECT_SERVER_BYTES = <?= (int)$projectMaxUploadBytes ?>;
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

(function () {
    var form = document.getElementById('projectForm');
    var submitBtn = document.getElementById('projectSubmitBtn');
    var imageInput = document.getElementById('projectImageInput');
    if (!form || !submitBtn) return;
    var submitting = false;
    form.addEventListener('submit', function (e) {
        if (submitting) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        form.querySelectorAll('.thai-address-block select[disabled]').forEach(function (el) {
            el.removeAttribute('disabled');
        });
        var zipEl = document.getElementById('addr_zip');
        if (zipEl) {
            zipEl.dispatchEvent(new Event('change'));
        }

        (async function () {
            var file = imageInput && imageInput.files && imageInput.files[0];
            if (file && window.drawdreamImageCompress) {
                submitBtn.textContent = 'กำลังบีบอัดรูป…';
                try {
                    var processed = await drawdreamImageCompress.ensureImageWithinLimit(
                        file,
                        MAX_PROJECT_IMAGE_BYTES,
                        MAX_PROJECT_SERVER_BYTES
                    );
                    if (processed && processed !== file && imageInput) {
                        var dt = new DataTransfer();
                        dt.items.add(processed);
                        imageInput.files = dt.files;
                    }
                } catch (err) {
                    drawdreamAlert('รูปใหญ่เกินไป กรุณาบีบอัดแล้วลองใหม่');
                    return;
                }
            }
            submitting = true;
            submitBtn.textContent = 'กำลังบันทึก…';
            submitBtn.classList.add('is-busy');
            form.submit();
        })();
    });
})();

</script>
<script src="js/thai_address_select.js?v=2"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof ThaiAddressSelect !== 'undefined') {
        ThaiAddressSelect.mount({
            province: '#addr_province',
            amphoe: '#addr_amphoe',
            tambon: '#addr_tambon',
            zip: '#addr_zip',
            hiddenFull: '#addr_full_hidden',
            initial: <?= $project_thai_addr_init_json ?>
        });
    }
});
</script>


</body>
</html>
