<?php
// ไฟล์นี้: foundation_add_need.php
// หน้าที่: หน้ามูลนิธิสำหรับเพิ่มรายการสิ่งของที่ต้องการ
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

// ดึง foundation_id
$stmt = $conn->prepare("SELECT foundation_id FROM foundation_profile WHERE user_id=? LIMIT 1");
if (!$stmt) die("Prepare failed: " . $conn->error);
$stmt->bind_param("i", $uid);
$stmt->execute();
$fp = $stmt->get_result()->fetch_assoc();
if (!$fp) die("ยังไม่มีโปรไฟล์มูลนิธิ กรุณาสร้างก่อน");
$foundation_id = (int)$fp['foundation_id'];

$error   = "";
$success = "";

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

    // บันทึก
    if ($error === "") {
        $total_price = $goal;
        $newImage = implode('|', $uploadedImages);

        $sql  = "INSERT INTO foundation_needlist 
                 (foundation_id, item_name, item_desc, brand, allow_other_brand,
                  qty_needed, price_estimate, urgent, item_image, created_by_user_id, note, total_price, approve_item)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param(
                "isssiidisisd",
                $foundation_id, $item_name, $item_desc, $brand,
                $allow_other, $qty, $price, $urgent, $newImage, $uid, $note, $total_price
            );

            if ($stmt->execute()) {
                $success = "เสนอรายการสำเร็จ รอแอดมินอนุมัติ";
                $_POST   = [];
            } else {
                $error = "บันทึกไม่สำเร็จ: " . $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เสนอรายการสิ่งของ | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/foundation.css?v=10">
</head>
<body class="foundation-add-need-page">

<?php include 'navbar.php'; ?>

<div class="add-need-container">
    <h2>เสนอรายการสิ่งของ</h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
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
                    <label>ยอดเป้าหมายเงินบริจาค (บาท) label>
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

                <div class="form-group">
                    <label>รายละเอียด</label>
                    <textarea name="item_desc" rows="4" placeholder="ระบุเงื่อนไขที่จำเป็น"><?= htmlspecialchars($_POST['item_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: กรุณาระบุยี่ห้อที่ต้องการถ้ายี่ห้อนี้ไม่ได้"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="urgent" id="urgent">
                    <label for="urgent">ต้องการด่วน</label>
                </div>

                <div class="form-group">
                    <label>รูปสินค้า (ไม่บังคับ, สูงสุด 3 รูป)</label>
                    <div class="image-upload-box" onclick="document.getElementById('fileInput').click();">
                        <label class="upload-label" id="uploadLabel">
                            <div class="upload-icon">📷</div>
                            <div>คลิกเพื่อเลือกได้สูงสุด 3 รูป</div>
                            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP (ไฟล์ละไม่เกิน 5MB)</div>
                        </label>
                        <input type="file" name="item_image[]" id="fileInput" accept="image/*" multiple>
                    </div>
                    <div id="imagePreviewList" class="upload-preview-list"></div>
                </div>

            </div>
        </div>

        <button type="submit" name="submit" class="btn-submit">ยืนยันเสนอรายการ</button>
    </form>
</div>

<script>
const goalAmount  = document.getElementById('goalAmount');
const periodSelect = document.getElementById('period');
const totalBox = document.getElementById('totalBox');
const urgentCheckbox = document.getElementById('urgent');
const fileInput = document.getElementById('fileInput');
const previewList = document.getElementById('imagePreviewList');
const categoryChecks = Array.from(document.querySelectorAll('.category-check'));
const itemChecks = Array.from(document.querySelectorAll('.item-option-check'));
const itemGroups = Array.from(document.querySelectorAll('.item-check-group'));
const selectedItemCount = document.getElementById('selectedItemCount');
let selectedFiles = [];

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
        item.appendChild(badge);
        item.appendChild(cap);
        previewList.appendChild(item);
    });
}

fileInput.addEventListener('change', function(e) {
    const files = Array.from(e.target.files || []);
    if (files.length > 3) {
        alert('อัปโหลดได้สูงสุด 3 รูป');
        e.target.value = '';
        selectedFiles = [];
        document.getElementById('uploadLabel').innerHTML = `
            <div class="upload-icon">📷</div>
            <div>คลิกเพื่อเลือกได้สูงสุด 3 รูป</div>
            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP (ไฟล์ละไม่เกิน 5MB)</div>
        `;
        renderPreviews();
        return;
    }

    selectedFiles = files;
    if (files.length > 0) {
        document.getElementById('uploadLabel').innerHTML = `
            <div class="upload-icon">✓</div>
            <div style="color:#4CAF50; font-weight:bold;">เลือกไฟล์แล้ว ${files.length} รูป</div>
            <div class="upload-hint">${files.map(f => f.name).join(', ')}</div>
        `;
    }
    renderPreviews();
});

urgentCheckbox.addEventListener('change', renderPreviews);

updateVisibleItemGroups();
updateSelectedItemCounter();
updateTotal();
</script>

</body>
</html>