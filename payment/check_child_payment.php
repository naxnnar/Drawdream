<?php
// ไฟล์นี้: payment\check_child_payment.php
// หน้าที่: ตรวจสอบสถานะการชำระเงินบริจาคเด็กรายบุคคล
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$charge_id = $_GET['charge_id'] ?? '';
$child_id  = (int)($_GET['child_id'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../children_.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ local dev)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['child_id' => (int)($_SESSION['pending_child_id'] ?? $child_id)],
    ];
} else {
    // เช็คสถานะ charge จาก Omise API
    $ch = curl_init(OMISE_API_URL . '/charges/' . $charge_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);
    $charge = json_decode($response, true) ?? [];
}

// fallback child_id จาก metadata/session หากไม่มีใน URL
if ($child_id <= 0) {
    $child_id = (int)($charge['metadata']['child_id'] ?? ($_SESSION['pending_child_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

$is_success = ($paid === true) || ($status === 'successful') || $is_mock;
$amount     = 0;

// กันบันทึกซ้ำเมื่อ refresh หรือเช็คซ้ำ
$already_processed = false;
$dup = $conn->prepare("SELECT log_id FROM payment_transaction WHERE omise_charge_id = ? LIMIT 1");
$dup->bind_param("s", $charge_id);
$dup->execute();
$already_processed = (bool)$dup->get_result()->fetch_assoc();

if ($is_success && !$already_processed && $child_id > 0) {
    $amount = ($charge['amount'] ?? 0) / 100;

    // ดึง tax_id ของ donor
    $tax_id = '';
    $stmt = $conn->prepare("SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $donor_row = $stmt->get_result()->fetch_assoc();
    $tax_id = $donor_row['tax_id'] ?? '';

    // หา category_id สำหรับเด็กรายบุคคล — สร้างถ้ายังไม่มี
    $stmt = $conn->prepare("SELECT category_id FROM donate_category WHERE child_donate IS NOT NULL LIMIT 1");
    $stmt->execute();
    $cat = $stmt->get_result()->fetch_assoc();

    if (!$cat) {
        // ตรวจสอบว่ามีคอลัมน์ child_donate ในตาราง donate_category หรือยัง
        $colCheck = $conn->query("SHOW COLUMNS FROM donate_category LIKE 'child_donate'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE donate_category ADD COLUMN child_donate VARCHAR(100) NULL");
        }
        $conn->query("INSERT INTO donate_category (child_donate) VALUES ('เด็กรายบุคคล')");
        $category_id = $conn->insert_id;
    } else {
        $category_id = $cat['category_id'];
    }

    // Transaction: บันทึก donation + payment_transaction + child_donations
    $conn->begin_transaction();
    try {
        // บันทึกลง donation
        $stmt = $conn->prepare("
            INSERT INTO donation (category_id, amount, service_fee, payment_status, transfer_datetime)
            VALUES (?, ?, 0, 'completed', NOW())
        ");
        $stmt->bind_param("id", $category_id, $amount);
        $stmt->execute();
        $donate_id = $conn->insert_id;

        // บันทึกลง payment_transaction
        $stmt = $conn->prepare("
            INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status)
            VALUES (?, ?, ?, 'completed')
        ");
        $stmt->bind_param("iss", $donate_id, $tax_id, $charge_id);
        $stmt->execute();

        // บันทึกลง child_donations (ตารางเดิมที่ payment.php เคยใช้)
        $conn->query("
            CREATE TABLE IF NOT EXISTS child_donations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                donor_user_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(child_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $conn->prepare("
            INSERT INTO child_donations (child_id, donor_user_id, amount)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iid", $child_id, $_SESSION['user_id'], $amount);
        $stmt->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        // ล้มเหลวในการบันทึก → แสดง error แทน success
        $is_success = false;
        $failure_message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาติดต่อผู้ดูแลระบบ";
    }

    // ล้าง session
    unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_child_id'], $_SESSION['pending_child_name']);
}

// ถ้าเคยประมวลผลแล้ว ให้ดึงจำนวนเงินจาก charge
if ($already_processed) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}

// ดึงชื่อเด็กเพื่อแสดงผล
$child_name = $_SESSION['pending_child_name'] ?? '';
if (empty($child_name) && $child_id > 0) {
    $stmtN = $conn->prepare("SELECT child_name FROM Children WHERE child_id = ? LIMIT 1");
    $stmtN->bind_param("i", $child_id);
    $stmtN->execute();
    $childRow = $stmtN->get_result()->fetch_assoc();
    $child_name = $childRow['child_name'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลการชำระเงิน | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">
    <div class="result-box">

        <?php if ($is_success): ?>
            <div class="result-icon success">✓</div>
            <h2>ชำระเงินสำเร็จ!</h2>
            <?php if (!empty($child_name)): ?>
                <p>ขอบคุณที่ร่วมบริจาคให้ <strong><?php echo htmlspecialchars($child_name); ?></strong></p>
            <?php else: ?>
                <p>ขอบคุณที่ร่วมบริจาคให้เด็กรายบุคคล</p>
            <?php endif; ?>
            <p>จำนวน <strong><?php echo number_format($amount, 2); ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?php echo htmlspecialchars($charge_id); ?></p>

            <!-- ปุ่มเดียว: กลับหน้าโปรไฟล์เด็ก (สี #CC583F) -->
            <a href="../children_.php" class="btn-pay" style="background:#CC583F; border:none; width:100%; max-width:400px; margin:32px auto 0 auto; display:block; font-size:1.3rem;">กลับหน้าโปรไฟล์เด็ก</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>รอการชำระเงิน</h2>
            <p>ยังไม่พบการชำระเงิน กรุณาสแกน QR Code แล้วลองใหม่</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ) การสแกนจ่ายจริงอาจไม่เปลี่ยนสถานะเป็นสำเร็จ</p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?php echo htmlspecialchars($expires_at); ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?php echo htmlspecialchars($charge_id); ?> | Status: <?php echo htmlspecialchars($status); ?> | Paid: <?php echo $paid ? 'true' : 'false'; ?></p>
            <a href="check_child_payment.php?charge_id=<?php echo urlencode($charge_id); ?>&child_id=<?php echo $child_id; ?>"
               class="btn-pay">เช็คอีกครั้ง</a>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?php echo htmlspecialchars($status); ?></p>
            <?php if (!empty($failure_code)): ?>
                <p>รหัสข้อผิดพลาด: <?php echo htmlspecialchars($failure_code); ?></p>
                <p>รายละเอียด: <?php echo htmlspecialchars($failure_message); ?></p>
            <?php elseif (!empty($failure_message)): ?>
                <p><?php echo htmlspecialchars($failure_message); ?></p>
            <?php endif; ?>
            <button type="button" class="btn-pay" onclick="window.location.reload()">ลองใหม่</button>
            <a href="../children_.php" class="btn-back">กลับหน้ารายชื่อเด็ก</a>
        <?php endif; ?>

    </div>
</div>

<?php if (!$is_success && $status === 'pending'): ?>
<!-- รีเฟรชอัตโนมัติทุก 5 วินาทีเมื่อยังรอการชำระเงิน -->
<script>
setTimeout(function () { window.location.reload(); }, 5000);
</script>
<?php endif; ?>

</body>
</html>
