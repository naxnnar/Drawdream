<?php
// เปิดโชว์ error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

// ตรวจสอบ login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ตรวจสอบว่าเป็นมูลนิธิหรือไม่
if (($_SESSION['role'] ?? '') !== 'foundation') {
    header("Location: p1_home.php");
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

$error = "";
$success = "";

if (isset($_POST['submit'])) {
    $category = $_POST['category'] ?? '';
    $item_name = trim($_POST['item_name'] ?? '');
    $item_desc = trim($_POST['item_desc'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $allow_other = isset($_POST['allow_other_brand']) ? 1 : 0;
    $urgent = isset($_POST['urgent']) ? 1 : 0;
    $note = trim($_POST['note'] ?? '');

    $qty = (int)($_POST['qty_needed'] ?? 0);
    $price = (float)($_POST['price_estimate'] ?? 0);

    // Validation
    if (!in_array($category, ['เด็กเล็ก','เด็กพิการ'], true)) {
        $error = "หมวดหมู่ไม่ถูกต้อง";
    } elseif ($item_name === '') {
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
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $imageName = $_FILES['item_image']['name'];
            $tmpName   = $_FILES['item_image']['tmp_name'];
            $fileSize  = $_FILES['item_image']['size'];

            if ($fileSize > 5 * 1024 * 1024) {
                $error = "ไฟล์ใหญ่เกิน 5MB";
            } else {
                $ext = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];

                if (!in_array($ext, $allowed, true)) {
                    $error = "อนุญาตเฉพาะไฟล์รูป jpg/jpeg/png/gif/webp";
                } else {
                    $safeName = time() . "_" . uniqid() . "." . $ext;
                    $targetPath = $uploadDir . $safeName;
                    
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $newImage = $safeName;
                        error_log("✅ อัปโหลดสำเร็จ: " . $newImage);
                    } else {
                        $error = "อัปโหลดรูปไม่สำเร็จ";
                    }
                }
            }
        }
    }

    // คำนวณราคารวม
    $total_price = $qty * $price;

    // บันทึกลงฐานข้อมูล
    if ($error === "") {
        $sql = "INSERT INTO foundation_needlist 
                (foundation_id, category, item_name, item_desc, brand, allow_other_brand, 
                 qty_needed, price_estimate, urgent, item_image, created_by_user_id, note, total_price, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            // แก้ไข: s (string) สำหรับ item_image
            $stmt->bind_param(
                "issssiidissdd",  // ← แก้ตรงนี้! i→s ตำแหน่งที่ 10
                $foundation_id, $category, $item_name, $item_desc, $brand,
                $allow_other, $qty, $price, $urgent, $newImage, $uid, $note, $total_price
            );

            error_log("📝 กำลังบันทึก item_image = " . $newImage);

            if ($stmt->execute()) {
                $success = "✅ เสนอรายการสำเร็จ! (รอแอดมินอนุมัติ)";
                if (!empty($newImage)) {
                    $success .= " | รูป: " . $newImage;
                }
                $_POST = array();
            } else {
                $error = "❌ บันทึกไม่สำเร็จ: " . $stmt->error;
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
    <link rel="stylesheet" href="css/style.css">
    <style>
        .add-need-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h2 { color: #333; margin-bottom: 25px; font-size: 28px; }
        
        .form-row { display: flex; gap: 30px; margin-bottom: 20px; }
        .form-col { flex: 1; }
        .form-group { margin-bottom: 20px; }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }
        
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        textarea { resize: vertical; min-height: 100px; }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .checkbox-group input { width: 18px; height: 18px; margin-right: 8px; }
        .checkbox-group label { margin: 0; font-weight: normal; }
        
        .image-upload-box {
            border: 2px dashed #ddd;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .image-upload-box:hover { border-color: #E57373; background: #fff; }
        .image-upload-box input[type="file"] { display: none; }
        .upload-label { display: block; cursor: pointer; }
        
        .total-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .btn-submit {
            background: #FFD54F;
            color: #333;
            padding: 15px 40px;
            border: none;
            border-radius: 25px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background: #FFC107;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
    </style>
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
                    <label>หมวดหมู่ *</label>
                    <select name="category" required>
                        <option value="">-- เลือกหมวดหมู่ --</option>
                        <option value="เด็กเล็ก">เด็กเล็ก</option>
                        <option value="เด็กพิการ">เด็กพิการ</option>
                    </select>
                </div>

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
                        <label class="upload-label">
                            <div style="font-size: 48px; margin-bottom: 10px;">📷</div>
                            <div>คลิกเพื่ออัปโหลดรูปภาพ</div>
                            <div style="font-size: 12px; color: #999; margin-top: 5px;">รองรับ JPG, PNG, GIF, WEBP (สูงสุด 5MB)</div>
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
                    <input type="number" name="qty_needed" id="qty" min="1" required>
                    <small style="color: #999;">หน่วย: ชิ้น/อัน/ตัว</small>
                </div>

                <div class="form-group">
                    <label>ราคาต่อหน่วย (บาท) *</label>
                    <input type="number" name="price_estimate" id="price" min="0.01" step="0.01" required>
                </div>

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="3" placeholder="เช่น: กรุณาระบุยี่ห้อที่ต้องการถ้ายี่ห้อนี้ไม่ได้"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <button type="submit" name="submit" class="btn-submit">
            ✓ ยืนยันเสนอรายการ
        </button>
    </form>
</div>

<script>
    const qty = document.getElementById('qty');
    const price = document.getElementById('price');
    const totalBox = document.getElementById('totalBox');

    function updateTotal() {
        const q = parseFloat(qty.value || 0);
        const p = parseFloat(price.value || 0);
        totalBox.textContent = "ยอดรวมรายการนี้: " + (q*p).toLocaleString('th-TH', {
            minimumFractionDigits: 2
        }) + " บาท";
    }

    qty.addEventListener('input', updateTotal);
    price.addEventListener('input', updateTotal);

    document.getElementById('fileInput').addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            const fileName = e.target.files[0].name;
            const uploadBox = document.querySelector('.image-upload-box label');
            uploadBox.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 10px;">✓</div>
                <div style="color: #4CAF50; font-weight: bold;">เลือกไฟล์แล้ว</div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">${fileName}</div>
            `;
        }
    });
</script>

</body>
</html>