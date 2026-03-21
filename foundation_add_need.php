<?php
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

if (isset($_POST['submit'])) {
    $item_name   = trim($_POST['item_name'] ?? '');
    $item_desc   = trim($_POST['item_desc'] ?? '');
    $brand       = trim($_POST['brand'] ?? '');
    $allow_other = isset($_POST['allow_other_brand']) ? 1 : 0;
    $urgent      = isset($_POST['urgent']) ? 1 : 0;
    $note        = trim($_POST['note'] ?? '');
    $qty         = (int)($_POST['qty_needed'] ?? 0);
    $price       = (float)($_POST['price_estimate'] ?? 0);

    // Validation
    if ($item_name === '') {
        $error = "กรุณากรอกชื่อรายการ";
    } elseif ($qty <= 0) {
        $error = "จำนวนต้องมากกว่า 0";
    } elseif ($price <= 0) {
        $error = "ราคาต่อหน่วยต้องมากกว่า 0";
    }

    // อัปโหลดรูป
    $newImage = '';
    if ($error === "" && isset($_FILES['item_image']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['item_image']['error'] !== 0) {
            $error = "ข้อผิดพลาดการอัปโหลด (Error code: " . $_FILES['item_image']['error'] . ")";
        } else {
            $uploadDir = "uploads/needs/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $imageName = $_FILES['item_image']['name'];
            $tmpName   = $_FILES['item_image']['tmp_name'];
            $fileSize  = $_FILES['item_image']['size'];

            if ($fileSize > 5 * 1024 * 1024) {
                $error = "ไฟล์ใหญ่เกิน 5MB";
            } else {
                $ext     = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (!in_array($ext, $allowed, true)) {
                    $error = "อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp";
                } else {
                    $safeName   = time() . "_" . uniqid() . "." . $ext;
                    $targetPath = $uploadDir . $safeName;
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $newImage = $safeName;
                    } else {
                        $error = "อัปโหลดรูปไม่สำเร็จ";
                    }
                }
            }
        }
    }

    // บันทึก
    if ($error === "") {
        $total_price = $qty * $price;

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
    <link rel="stylesheet" href="css/foundation_add_need.css">
</head>
<body>

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
                    <label>ชื่อรายการ *</label>
                    <input type="text" name="item_name" value="<?= htmlspecialchars($_POST['item_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>รายละเอียด/สเปก</label>
                    <textarea name="item_desc" rows="4"><?= htmlspecialchars($_POST['item_desc'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>ยี่ห้อ (ไม่บังคับ)</label>
                    <input type="text" name="brand" value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="allow_other_brand" id="allow_other" checked>
                    <label for="allow_other">รับยี่ห้ออื่นได้</label>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="urgent" id="urgent">
                    <label for="urgent">ต้องการด่วน</label>
                </div>

                <div class="form-group">
                    <label>รูปสินค้า (ไม่บังคับ)</label>
                    <div class="image-upload-box" onclick="document.getElementById('fileInput').click();">
                        <label class="upload-label" id="uploadLabel">
                            <div class="upload-icon">📷</div>
                            <div>คลิกเพื่ออัปโหลดรูปภาพ</div>
                            <div class="upload-hint">รองรับ JPG, PNG, GIF, WEBP (สูงสุด 5MB)</div>
                        </label>
                        <input type="file" name="item_image" id="fileInput" accept="image/*">
                    </div>
                </div>

            </div>

            <div class="form-col">

                <div class="total-box" id="totalBox">
                    ยอดรวมรายการนี้: 0 บาท
                </div>

                <div class="form-group">
                    <label>จำนวนที่ต้องการ *</label>
                    <input type="number" name="qty_needed" id="qty" min="1" value="<?= htmlspecialchars($_POST['qty_needed'] ?? '') ?>" required>
                    <small style="color:#999;">หน่วย: ชิ้น/อัน/ตัว</small>
                </div>

                <div class="form-group">
                    <label>ราคาต่อหน่วย (บาท) *</label>
                    <input type="number" name="price_estimate" id="price" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['price_estimate'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: กรุณาระบุยี่ห้อที่ต้องการถ้ายี่ห้อนี้ไม่ได้"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

            </div>
        </div>

        <button type="submit" name="submit" class="btn-submit">ยืนยันเสนอรายการ</button>
    </form>
</div>

<script>
const qty      = document.getElementById('qty');
const price    = document.getElementById('price');
const totalBox = document.getElementById('totalBox');

function updateTotal() {
    const q = parseFloat(qty.value || 0);
    const p = parseFloat(price.value || 0);
    totalBox.textContent = "ยอดรวมรายการนี้: " + (q * p).toLocaleString('th-TH', { minimumFractionDigits: 2 }) + " บาท";
}

qty.addEventListener('input', updateTotal);
price.addEventListener('input', updateTotal);

document.getElementById('fileInput').addEventListener('change', function(e) {
    if (e.target.files.length > 0) {
        document.getElementById('uploadLabel').innerHTML = `
            <div class="upload-icon">✓</div>
            <div style="color:#4CAF50; font-weight:bold;">เลือกไฟล์แล้ว</div>
            <div class="upload-hint">${e.target.files[0].name}</div>
        `;
    }
});
</script>

</body>
</html>