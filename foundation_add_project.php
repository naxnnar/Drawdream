<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header("Location: project.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stmtFp = $conn->prepare("SELECT fp.*, u.email FROM foundation_profile fp JOIN users u ON u.user_id = fp.user_id WHERE fp.user_id = ? LIMIT 1");
$stmtFp->bind_param("i", $uid);
$stmtFp->execute();
$fp = $stmtFp->get_result()->fetch_assoc();

$foundationName = trim((string)($fp['foundation_name'] ?? ''));
if ($foundationName === '') {
    echo "<script>alert('ไม่พบข้อมูลมูลนิธิ กรุณาอัปเดตโปรไฟล์ก่อน'); window.location='update_profile.php';</script>";
    exit();
}

$categories = ['การศึกษา', 'สุขภาพและอนามัย', 'อาหารและโภชนาการ', 'สิ่งอำนวยความสะดวก'];

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
    'donation_option_1' => '',
    'donation_option_2' => '',
    'donation_option_3' => '',
    'urgent_info' => '',
    'need_info' => '',
    'update_info' => '',
];

if ($editProjectId > 0) {
    $stmtEdit = $conn->prepare(
        "SELECT p.*, pd.category, pd.target_group, pd.project_quote,
                pd.donation_option_1, pd.donation_option_2, pd.donation_option_3,
                pd.urgent_info, pd.need_info, pd.update_info
         FROM project p
         LEFT JOIN project_detail pd ON pd.project_id = p.project_id
         WHERE p.project_id = ? AND p.foundation_name = ?
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
    $enddate = $_POST['end_date'] ?? '';

    // ตัวเลือกยอดบริจาค
    $opt1 = (int)($_POST['donation_option_1'] ?? 0);
    $opt2 = (int)($_POST['donation_option_2'] ?? 0);
    $opt3 = (int)($_POST['donation_option_3'] ?? 0);

    // ข้อมูลกล่องรายละเอียดหน้าโครงการ
    $urgentInfo = trim($_POST['urgent_info'] ?? '');
    $needInfo = trim($_POST['need_info'] ?? '');
    $updateInfo = trim($_POST['update_info'] ?? '');

    if (!in_array($category, $categories, true)) {
        echo "<script>alert('กรุณาเลือกประเภทโครงการ'); history.back();</script>";
        exit();
    }

    if ($name === '' || $desc === '' || $targetGroup === '' || $quote === '' || $goal <= 0 || $enddate === '') {
        echo "<script>alert('กรุณากรอกข้อมูลโครงการให้ครบ'); history.back();</script>";
        exit();
    }

    if ($opt1 <= 0 || $opt2 <= 0 || $opt3 <= 0) {
        echo "<script>alert('กรุณากรอกตัวเลือกยอดบริจาคให้ครบ (มากกว่า 0)'); history.back();</script>";
        exit();
    }

    if ($urgentInfo === '' || $needInfo === '') {
        echo "<script>alert('กรุณากรอกข้อมูลข้อความขาดแคลนทางข้อมูล และกิจกรรมทางมูลนิธิ'); history.back();</script>";
        exit();
    }

    $currentProjectImage = '';
    if ($isEditSubmit) {
        $stmtCurrent = $conn->prepare("SELECT project_image FROM project WHERE project_id = ? AND foundation_name = ? LIMIT 1");
        $stmtCurrent->bind_param("is", $editingId, $foundationName);
        $stmtCurrent->execute();
        $currentProjectRow = $stmtCurrent->get_result()->fetch_assoc();

        if (!$currentProjectRow) {
            echo "<script>alert('ไม่พบโครงการที่ต้องการแก้ไข'); window.location='project.php?view=foundation';</script>";
            exit();
        }
        $currentProjectImage = (string)($currentProjectRow['project_image'] ?? '');
    }

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

    mysqli_begin_transaction($conn);
    try {
        $goalDec = (float)$goal;
        if ($isEditSubmit) {
            $stmtProject = $conn->prepare(
                "UPDATE project
                 SET project_name = ?, project_desc = ?, project_image = ?, goal_amount = ?, end_date = ?
                 WHERE project_id = ? AND foundation_name = ?"
            );
            $stmtProject->bind_param("sssdsis", $name, $desc, $newName, $goalDec, $enddate, $editingId, $foundationName);
            if (!$stmtProject->execute()) {
                throw new Exception($stmtProject->error ?: 'แก้ไขโครงการไม่สำเร็จ');
            }

            $projectId = $editingId;

            $stmtCheckDetail = $conn->prepare("SELECT project_id FROM project_detail WHERE project_id = ? LIMIT 1");
            $stmtCheckDetail->bind_param("i", $projectId);
            $stmtCheckDetail->execute();
            $detailExists = (bool)$stmtCheckDetail->get_result()->fetch_assoc();

            if ($detailExists) {
                $stmtDetail = $conn->prepare(
                    "UPDATE project_detail
                     SET category = ?, target_group = ?, project_quote = ?,
                         donation_option_1 = ?, donation_option_2 = ?, donation_option_3 = ?,
                         urgent_info = ?, need_info = ?, update_info = ?
                     WHERE project_id = ?"
                );
                $stmtDetail->bind_param(
                    "sssiiisssi",
                    $category,
                    $targetGroup,
                    $quote,
                    $opt1,
                    $opt2,
                    $opt3,
                    $urgentInfo,
                    $needInfo,
                    $updateInfo,
                    $projectId
                );
            } else {
                $stmtDetail = $conn->prepare(
                    "INSERT INTO project_detail
                        (project_id, category, target_group, project_quote, donation_option_1, donation_option_2, donation_option_3, urgent_info, need_info, update_info)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmtDetail->bind_param(
                    "isssiiisss",
                    $projectId,
                    $category,
                    $targetGroup,
                    $quote,
                    $opt1,
                    $opt2,
                    $opt3,
                    $urgentInfo,
                    $needInfo,
                    $updateInfo
                );
            }
        } else {
            $stmtProject = $conn->prepare(
                "INSERT INTO project (project_name, project_desc, project_image, goal_amount, end_date, project_status, current_donate, start_date, foundation_name, approve_project)
                 VALUES (?, ?, ?, ?, ?, 'pending', 0, CURDATE(), ?, NULL)"
            );
            $stmtProject->bind_param("sssdss", $name, $desc, $newName, $goalDec, $enddate, $foundationName);
            if (!$stmtProject->execute()) {
                throw new Exception($stmtProject->error ?: 'บันทึกโครงการไม่สำเร็จ');
            }

            $projectId = (int)$conn->insert_id;
            $stmtDetail = $conn->prepare(
                "INSERT INTO project_detail
                    (project_id, category, target_group, project_quote, donation_option_1, donation_option_2, donation_option_3, urgent_info, need_info, update_info)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtDetail->bind_param(
                "isssiiisss",
                $projectId,
                $category,
                $targetGroup,
                $quote,
                $opt1,
                $opt2,
                $opt3,
                $urgentInfo,
                $needInfo,
                $updateInfo
            );
        }

        if (!$stmtDetail->execute()) {
            throw new Exception($stmtDetail->error ?: 'บันทึกรายละเอียดโครงการไม่สำเร็จ');
        }

        mysqli_commit($conn);
        $successMessage = $isEditSubmit ? 'แก้ไขโครงการสำเร็จ' : 'เสนอโครงการสำเร็จ (รอแอดมินอนุมัติ)';
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
    <link rel="stylesheet" href="css/addproject.css">
</head>
<body>

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
                <label>กลุ่มเป้าหมาย </label>
                <input type="text" name="target_group" placeholder="เช่น เด็ก, เกี่ยวกับการศึกษา, ผู้พิการ (คั่นด้วยจุลภาค)" value="<?= htmlspecialchars($editingProject['target_group'] ?? '') ?>" required>
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
                <textarea name="project_desc" rows="5" placeholder="อธิบายรายละเอียดโครงการ วัตถุประสงค์ แผนงาน" required><?= htmlspecialchars($editingProject['project_desc'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>เป้าหมาย (บาท) </label>
                <input type="number" name="goal_amount" min="1" placeholder="จำนวนเงินที่ต้องการระดมทุน" value="<?= htmlspecialchars((string)($editingProject['goal_amount'] ?? '')) ?>" required>
            </div>

            <div class="form-group">
                <label>ตัวเลือกปุ่มบริจาค (บาท) *</label>
                <div class="donation-opts-grid">
                    <input type="number" name="donation_option_1" min="20" placeholder="ตัวเลือก 1" value="<?= htmlspecialchars((string)($editingProject['donation_option_1'] ?? '')) ?>" required>
                    <input type="number" name="donation_option_2" min="20" placeholder="ตัวเลือก 2" value="<?= htmlspecialchars((string)($editingProject['donation_option_2'] ?? '')) ?>" required>
                    <input type="number" name="donation_option_3" min="20" placeholder="ตัวเลือก 3" value="<?= htmlspecialchars((string)($editingProject['donation_option_3'] ?? '')) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>วันที่ปิดรับบริจาค </label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($editingProject['end_date'] ?? '') ?>" required>
            </div>

            <!-- <div class="form-group">
                <label>ข้อความขาดแคลน / ความจำเป็นเร่งด่วน </label>
                <textarea name="urgent_info" rows="3" placeholder="อธิบายสถานการณ์ที่ขาดแคลนและทำไมต้องการความช่วยเหลือ" required><?= htmlspecialchars($editingProject['urgent_info'] ?? '') ?></textarea>
            </div> -->

            <div class="form-group">
                <label>กิจกรรมและการดำเนินงานของมูลนิธิ </label>
                <textarea name="need_info" rows="3" placeholder="สิ่งที่มูลนิธิได้ดำเนินการและแผนในอนาคต" required><?= htmlspecialchars($editingProject['need_info'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>อัปเดตเพิ่มเติม</label>
                <textarea name="update_info" rows="3" placeholder="ความคืบหน้า หรือข้อมูลอื่นๆ ที่ต้องการแจ้ง (ไม่บังคับ)"><?= htmlspecialchars($editingProject['update_info'] ?? '') ?></textarea>
            </div>

            <button type="submit" name="submit" class="btn-submit"><?= $isEditMode ? 'บันทึกการแก้ไขโครงการ' : 'เสนอโครงการ' ?></button>
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

</body>
</html>
