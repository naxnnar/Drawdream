<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

// ต้อง login ก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$project_id = (int)($_GET['project_id'] ?? 0);
if ($project_id <= 0) {
    header("Location: ../p2_project.php");
    exit();
}

// ดึงข้อมูลโครงการ
$stmt = $conn->prepare("SELECT * FROM project WHERE project_id = ? AND status = 'approved' LIMIT 1");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: ../p2_project.php");
    exit();
}

// ดึงข้อมูล donor
$donor = null;
if ($_SESSION['role'] === 'donor') {
    $stmt2 = $conn->prepare("SELECT * FROM donor WHERE user_id = ? LIMIT 1");
    $stmt2->bind_param("i", $_SESSION['user_id']);
    $stmt2->execute();
    $donor = $stmt2->get_result()->fetch_assoc();
}

$error   = "";
$success = "";
$qr_image = "";
$charge_id = "";

// ======== ประมวลผลการชำระเงิน ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $amount = (int)($_POST['amount'] ?? 0);

    if ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        // เรียก Omise API สร้าง PromptPay Source
        $amount_satang = $amount * 100; // Omise ใช้สตางค์

        $source_response = omise_request('POST', '/sources', [
            'type'     => 'promptpay',
            'amount'   => $amount_satang,
            'currency' => 'THB',
        ]);

        if (isset($source_response['object']) && $source_response['object'] === 'source') {
            $source_id = $source_response['id'];

            // สร้าง Charge
            $charge_response = omise_request('POST', '/charges', [
                'amount'   => $amount_satang,
                'currency' => 'THB',
                'source'   => $source_id,
                'description' => 'บริจาคโครงการ: ' . $project['project_name'],
                'metadata' => [
                    'project_id' => $project_id,
                    'donor_id'   => $_SESSION['user_id'],
                ],
            ]);

            if (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

                // บันทึกลง database (status = pending รอยืนยัน

                // เก็บ charge_id ไว้ใน session เพื่อเช็คสถานะ
                $_SESSION['pending_charge_id'] = $charge_id;
                $_SESSION['pending_amount']    = $amount;
                $_SESSION['pending_project']   = $project['project_name'];

            } else {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . ($charge_response['message'] ?? 'unknown error');
            }
        } else {
            $error = "เกิดข้อผิดพลาด: " . ($source_response['message'] ?? 'unknown error');
        }
    }
}

// ======== ฟังก์ชันเรียก Omise API ========
function omise_request($method, $path, $data = []) {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ชำระเงิน | DrawDream</title>
    <link rel="stylesheet" href="../css/payment.css">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">

    <div class="project-info">
        <h2>บริจาคให้โครงการ</h2>
        <h3><?= htmlspecialchars($project['project_name']) ?></h3>
        <?php if (!empty($project['project_image'])): ?>
            <img src="../uploads/<?= htmlspecialchars($project['project_image']) ?>" class="project-img" alt="">
        <?php endif; ?>
        <p class="project-desc"><?= htmlspecialchars($project['project_desc']) ?></p>
        <div class="goal-info">
            เป้าหมาย <?= number_format($project['project_goal'], 0) ?> บาท
        </div>
    </div>

    <div class="payment-box">

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($qr_image)): ?>
            <!-- แสดง QR Code -->
            <div class="qr-section">
                <h3>สแกน QR Code เพื่อชำระเงิน</h3>
                <p class="qr-amount">จำนวน <strong><?= number_format($_SESSION['pending_amount'], 0) ?> บาท</strong></p>
                <img src="<?= htmlspecialchars($qr_image) ?>" class="qr-image" alt="QR Code PromptPay">
                <p class="qr-hint">QR Code มีอายุ 10 นาที</p>
                <p class="qr-charge">Charge ID: <?= htmlspecialchars($charge_id) ?></p>

                <a href="check_payment.php?charge_id=<?= urlencode($charge_id) ?>&project_id=<?= $project_id ?>" 
                   class="btn-check">ฉันชำระเงินแล้ว</a>
                <a href="payment_project.php?project_id=<?= $project_id ?>" class="btn-cancel">ยกเลิก</a>
            </div>

        <?php else: ?>
            <!-- ฟอร์มกรอกจำนวนเงิน -->
            <h3>เลือกจำนวนเงินที่ต้องการบริจาค</h3>

            <div class="amount-presets">
                <button type="button" class="preset-btn" onclick="setAmount(50)">50 บาท</button>
                <button type="button" class="preset-btn" onclick="setAmount(100)">100 บาท</button>
                <button type="button" class="preset-btn" onclick="setAmount(500)">500 บาท</button>
                <button type="button" class="preset-btn" onclick="setAmount(1000)">1,000 บาท</button>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label>จำนวนเงิน (บาท) *</label>
                    <input type="number" name="amount" id="amountInput" min="20" placeholder="ขั้นต่ำ 20 บาท" required>
                </div>
                <div class="payment-method">
                    <div class="method-card active">
                        <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                        <span>PromptPay QR</span>
                    </div>
                </div>
                <button type="submit" name="pay" class="btn-pay">สร้าง QR Code</button>
            </form>

            <a href="../p2_project.php" class="btn-back">ย้อนกลับ</a>
        <?php endif; ?>

    </div>
</div>

<script>
function setAmount(val) {
    document.getElementById('amountInput').value = val;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
}
</script>

</body>
</html>