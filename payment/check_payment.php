<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$charge_id  = $_GET['charge_id'] ?? '';
$project_id = (int)($_GET['project_id'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../p2_project.php");
    exit();
}

// เช็คสถานะ charge จาก Omise
$ch = curl_init(OMISE_API_URL . '/charges/' . $charge_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
$response = curl_exec($ch);
curl_close($ch);
$charge = json_decode($response, true);

$status = $charge['status'] ?? 'unknown';
$paid   = $charge['paid'] ?? false;
$is_success = ($paid === true) || ($status === 'successful');
$failure_code = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at = $charge['expires_at'] ?? '';
$is_test_mode = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);
$amount = 0;

if ($is_success) {
    $amount      = ($charge['amount'] ?? 0) / 100;
    $service_fee = 0;
    // ดึง tax_id ของ donor
    $tax_id = '';
    $stmt = $conn->prepare("SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $donor  = $stmt->get_result()->fetch_assoc();
    $tax_id = $donor['tax_id'] ?? '';

    // หา category_id สำหรับโครงการ
    $stmt = $conn->prepare("SELECT category_id FROM donate_category WHERE project_donate IS NOT NULL LIMIT 1");
    $stmt->execute();
    $cat = $stmt->get_result()->fetch_assoc();

    if (!$cat) {
        $conn->query("INSERT INTO donate_category (project_donate) VALUES ('โครงการ')");
        $category_id = $conn->insert_id;
    } else {
        $category_id = $cat['category_id'];
    }

    // บันทึกลง donation
    $stmt = $conn->prepare("
        INSERT INTO donation (category_id, amount, service_fee, payment_status, transfer_datetime)
        VALUES (?, ?, ?, 'completed', NOW())
    ");
    $stmt->bind_param("idd", $category_id, $amount, $service_fee);
    $stmt->execute();
    $donate_id = $conn->insert_id;

    // บันทึกลง payment_transaction
    $stmt = $conn->prepare("
        INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status)
        VALUES (?, ?, ?, 'completed')
    ");
    $stmt->bind_param("iss", $donate_id, $tax_id, $charge_id);
    $stmt->execute();

    // อัปเดต current_amount ในตาราง project
    $net_amount = $amount;
    $stmt = $conn->prepare("UPDATE project SET current_amount = current_amount + ? WHERE project_id = ?");
    $stmt->bind_param("di", $net_amount, $project_id);
    $stmt->execute();

    // ล้าง session
    unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_project']);
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
            <p>ขอบคุณที่ร่วมบริจาคให้โครงการ</p>
            <p>จำนวน <strong><?= number_format($amount, 2) ?> บาท</strong></p>
            <p class="charge-ref">อ้างอิง: <?= htmlspecialchars($charge_id) ?></p>
            <a href="../p2_project.php" class="btn-pay">กลับหน้าโครงการ</a>

        <?php elseif ($status === 'pending'): ?>
            <div class="result-icon pending">⏳</div>
            <h2>รอการชำระเงิน</h2>
            <p>ยังไม่พบการชำระเงิน กรุณาสแกน QR Code แล้วลองใหม่</p>
            <?php if ($is_test_mode): ?>
                <p style="color:#a16207;">ระบบกำลังใช้ Omise Test Key (โหมดทดสอบ) การสแกนจ่ายจริงอาจไม่เปลี่ยนสถานะเป็นสำเร็จ</p>
            <?php endif; ?>
            <?php if (!empty($expires_at)): ?>
                <p>QR หมดอายุ: <?= htmlspecialchars($expires_at) ?></p>
            <?php endif; ?>
            <p class="charge-ref">Charge: <?= htmlspecialchars($charge_id) ?> | Status: <?= htmlspecialchars($status) ?> | Paid: <?= $paid ? 'true' : 'false' ?></p>
            <a href="check_payment.php?charge_id=<?= urlencode($charge_id) ?>&project_id=<?= $project_id ?>" 
               class="btn-pay">เช็คอีกครั้ง</a>
            <a href="../p2_project.php" class="btn-back">กลับหน้าโครงการ</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?= htmlspecialchars($status) ?></p>
            <?php if (!empty($failure_code) || !empty($failure_message)): ?>
                <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <p>รายละเอียด: <?= htmlspecialchars($failure_message) ?></p>
            <?php endif; ?>
            <a href="payment_project.php?project_id=<?= $project_id ?>" class="btn-pay">ลองใหม่</a>
            <a href="../p2_project.php" class="btn-back">กลับหน้าโครงการ</a>
        <?php endif; ?>

    </div>
</div>

</body>
</html>