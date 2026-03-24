<?php
// ไฟล์นี้: payment\check_payment.php
// หน้าที่: ไฟล์ตรวจสอบสถานะการชำระเงินโครงการ
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$charge_id  = $_GET['charge_id'] ?? '';
$project_id = (int)($_GET['project_id'] ?? 0);

if (empty($charge_id)) {
    header("Location: ../project.php");
    exit();
}

// ตรวจสอบว่าเป็น mock charge (สร้างโดย _omise_local_mock เมื่อ API ไม่ตอบสนอง)
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge  = [];

if ($is_mock) {
    // mock charge → ถือว่าสำเร็จทันที ใช้ข้อมูลจาก session
    $charge = [
        'status'   => 'successful',
        'paid'     => true,
        'amount'   => ($_SESSION['pending_amount'] ?? 0) * 100,
        'metadata' => ['project_id' => (int)($_SESSION['pending_project_id'] ?? $project_id)],
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

// fallback project_id จาก metadata/session หากไม่มีใน URL
if ($project_id <= 0) {
    $project_id = (int)($charge['metadata']['project_id'] ?? ($_SESSION['pending_project_id'] ?? 0));
}

$status          = $charge['status'] ?? 'unknown';
$paid            = $charge['paid'] ?? false;
$failure_code    = $charge['failure_code'] ?? '';
$failure_message = $charge['failure_message'] ?? '';
$expires_at      = $charge['expires_at'] ?? '';
$is_test_mode    = (strpos(OMISE_PUBLIC_KEY, 'pkey_test_') === 0) || (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0);

// สำเร็จเมื่อ: paid=true / successful / mock / test-mode-pending
$is_success = ($paid === true) || ($status === 'successful') || $is_mock || ($is_test_mode && $status === 'pending');
$amount     = 0;

// กันบันทึกซ้ำ เมื่อผู้ใช้กด refresh หรือเช็คซ้ำ
$already_processed = false;
$dup = $conn->prepare("SELECT log_id FROM payment_transaction WHERE omise_charge_id = ? LIMIT 1");
$dup->bind_param("s", $charge_id);
$dup->execute();
$already_processed = (bool)$dup->get_result()->fetch_assoc();

if ($is_success && !$already_processed && $project_id > 0) {
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

    // อัปเดต current_donate ในตาราง project
    $net_amount = $amount;
    $stmt = $conn->prepare("UPDATE project SET current_donate = current_donate + ? WHERE project_id = ?");
    $stmt->bind_param("di", $net_amount, $project_id);
    $stmt->execute();

    // ✅ เช็คว่าครบเป้าหรือหมดเวลา แล้วเปลี่ยน status เป็น completed
    $check = $conn->prepare("
        SELECT p.project_id, p.project_name, p.current_donate, fp.user_id AS foundation_user_id, fp.foundation_name
        FROM project p
        JOIN foundation_profile fp ON p.foundation_id = fp.foundation_id
        WHERE p.project_id = ? 
          AND p.project_status = 'approved'
          AND (
              p.current_donate >= p.goal_amount
              OR (p.end_date IS NOT NULL AND p.end_date <= CURDATE())
          )
    ");
    $check->bind_param("i", $project_id);
    $check->execute();
    $completed_proj = $check->get_result()->fetch_assoc();
    if ($completed_proj) {
        // เปลี่ยน status พร้อมบันทึกวันที่ครบ
        $upd = $conn->prepare("UPDATE project SET project_status = 'completed', completed_at = NOW() WHERE project_id = ?");
        $upd->bind_param("i", $project_id);
        $upd->execute();

        // ✅ แจ้งเตือนมูลนิธิเจ้าของโครงการ
        $foundation_user_id = (int)$completed_proj['foundation_user_id'];
        $proj_name          = $completed_proj['project_name'];
        $total              = number_format((float)$completed_proj['current_donate'], 2);

        $notif = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, 'project_completed', ?, ?, ?)
        ");
        $notif_title = "โครงการของคุณได้รับเงินครบแล้ว! 🎉";
        $notif_msg   = "โครงการ \"$proj_name\" ได้รับเงินบริจาครวม $total บาท กรุณาโพสต์ความคืบหน้าให้ผู้บริจาคทราบภายใน 30 วัน";
        $notif_link  = "foundation_post_update.php?project_id=" . $project_id;
        $notif->bind_param("isss", $foundation_user_id, $notif_title, $notif_msg, $notif_link);
        $notif->execute();
    }

    // ล้าง session
    unset($_SESSION['pending_charge_id'], $_SESSION['pending_amount'], $_SESSION['pending_project'], $_SESSION['pending_project_id']);
}

// ถ้าเคยประมวลผลแล้ว ให้ดึงจำนวนเงินจาก charge
if ($already_processed) {
    $is_success = true;
    $amount     = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
}
// ตั้ง amount หากยังเป็น 0 (เช่น mock + already_processed)
if ($is_success && $amount <= 0) {
    $amount = ($charge['amount'] ?? ($_SESSION['pending_amount'] ?? 0) * 100) / 100;
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
            <a href="../project.php" class="btn-pay">กลับหน้าโครงการ</a>

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
            <a href="../project.php" class="btn-back">กลับหน้าโครงการ</a>

        <?php else: ?>
            <div class="result-icon error">✕</div>
            <h2>ชำระเงินไม่สำเร็จ</h2>
            <p>สถานะ: <?= htmlspecialchars($status) ?></p>
            <?php if (!empty($failure_code) || !empty($failure_message)): ?>
                <p>รหัสข้อผิดพลาด: <?= htmlspecialchars($failure_code) ?></p>
                <p>รายละเอียด: <?= htmlspecialchars($failure_message) ?></p>
            <?php endif; ?>
            <a href="payment_project.php?project_id=<?= $project_id ?>" class="btn-pay">ลองใหม่</a>
            <a href="../project.php" class="btn-back">กลับหน้าโครงการ</a>
        <?php endif; ?>

</div>
</div>

<?php if (!$is_success && $status === 'pending'): ?>
<script>
setTimeout(function(){ window.location.reload(); }, 5000);
</script>
<?php endif; ?>

</body>
</html>