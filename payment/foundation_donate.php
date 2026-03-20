<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) {
    header("Location: ../foundation.php");
    exit();
}

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) {
    header("Location: ../foundation.php");
    exit();
}

// ดึงข้อมูลมูลนิธิ
$stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1");
$stmt->bind_param("i", $fid);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();

if (!$foundation) {
    header("Location: ../foundation.php");
    exit();
}

// ดึงยอดรวมราคาสิ่งของทั้งหมดที่อนุมัติแล้ว
$stmt2 = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND status = 'approved'");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$goal_row = $stmt2->get_result()->fetch_assoc();
$goal = (float)($goal_row['goal'] ?? 0);

// ดึงยอดบริจาคปัจจุบัน
$stmt3 = $conn->prepare("
    SELECT COALESCE(SUM(d.amount), 0) AS current
    FROM donation d
    JOIN donate_category dc ON d.category_id = dc.category_id
    WHERE dc.needitem_donate IS NOT NULL
    AND d.payment_status = 'completed'
");
$stmt3->execute();
$current_row = $stmt3->get_result()->fetch_assoc();
$current = (float)($current_row['current'] ?? 0);

$percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;

// ดึงรายการสิ่งของ
$items_stmt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND status = 'approved' ORDER BY urgent DESC, item_id DESC");
$items_stmt->bind_param("i", $fid);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$error     = "";
$qr_image  = "";
$charge_id = "";

// ======== ประมวลผลการชำระเงิน ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $amount = (int)($_POST['amount'] ?? 0);

    if ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        $amount_satang = $amount * 100;

        $source_response = omise_request('POST', '/sources', [
            'type'     => 'promptpay',
            'amount'   => $amount_satang,
            'currency' => 'THB',
        ]);

        if (isset($source_response['object']) && $source_response['object'] === 'source') {
            $source_id = $source_response['id'];

            $charge_response = omise_request('POST', '/charges', [
                'amount'      => $amount_satang,
                'currency'    => 'THB',
                'source'      => $source_id,
                'description' => 'บริจาครายการสิ่งของ: ' . $foundation['foundation_name'],
                'metadata'    => [
                    'foundation_id' => $fid,
                    'donor_id'      => $_SESSION['user_id'],
                    'type'          => 'needlist',
                ],
            ]);

            if (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

                $_SESSION['pending_charge_id']     = $charge_id;
                $_SESSION['pending_amount']         = $amount;
                $_SESSION['pending_foundation']     = $foundation['foundation_name'];
                $_SESSION['pending_foundation_id']  = $fid;
            } else {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . ($charge_response['message'] ?? 'unknown error');
            }
        } else {
            $error = "เกิดข้อผิดพลาด: " . ($source_response['message'] ?? 'unknown error');
        }
    }
}

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
    <title>บริจาครายการสิ่งของ | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
    <link rel="stylesheet" href="../css/foundation_donate.css">
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="donate-container">

    <!-- ซ้าย: ข้อมูลมูลนิธิ + รายการสิ่งของ -->
    <div class="foundation-panel">
        <div class="foundation-header">
            <?php if (!empty($foundation['foundation_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($foundation['foundation_image']) ?>" class="foundation-cover" alt="">
            <?php endif; ?>
            <h2><?= htmlspecialchars($foundation['foundation_name']) ?></h2>
        </div>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="bar"><div style="width:<?= (int)$percent ?>%"></div></div>
            <div class="progress-text">
                ยอดบริจาค <?= number_format($current, 0) ?> / <?= number_format($goal, 0) ?> บาท
            </div>
        </div>

        <!-- รายการสิ่งของ -->
        <?php if (!empty($items)): ?>
        <div class="items-list">
            <h3>รายการสิ่งของที่ต้องการ</h3>
            <?php foreach ($items as $item): ?>
                <div class="item-row <?= $item['urgent'] ? 'urgent' : '' ?>">
                    <?php if (!empty($item['item_image'])): ?>
                        <img src="../uploads/needs/<?= htmlspecialchars($item['item_image']) ?>" class="item-thumb" alt="">
                    <?php endif; ?>
                    <div class="item-detail">
                        <div class="item-name">
                            <?= htmlspecialchars($item['item_name']) ?>
                            <?php if ($item['urgent']): ?>
                                <span class="urgent-tag">ด่วน</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-meta">
                            ต้องการ <?= (int)$item['qty_needed'] ?> ชิ้น |
                            ราคา/หน่วย <?= number_format((float)$item['price_estimate'], 0) ?> บาท |
                            รวม <?= number_format((float)$item['total_price'], 0) ?> บาท
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ขวา: ชำระเงิน -->
    <div class="payment-box">

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($qr_image)): ?>
            <div class="qr-section">
                <h3>สแกน QR Code เพื่อชำระเงิน</h3>
                <p class="qr-amount">จำนวน <strong><?= number_format($_SESSION['pending_amount'], 0) ?> บาท</strong></p>
                <img src="<?= htmlspecialchars($qr_image) ?>" class="qr-image" alt="QR Code PromptPay">
                <p class="qr-hint">QR Code มีอายุ 10 นาที</p>
                <p class="qr-charge">Charge ID: <?= htmlspecialchars($charge_id) ?></p>
                <a href="check_needlist_payment.php?charge_id=<?= urlencode($charge_id) ?>&fid=<?= $fid ?>"
                   class="btn-check">ฉันชำระเงินแล้ว</a>
                <a href="foundation_donate.php?fid=<?= $fid ?>" class="btn-cancel">ยกเลิก</a>
            </div>

        <?php else: ?>
            <h3>เลือกจำนวนเงินที่ต้องการบริจาค</h3>
            <p style="color:#666; font-size:13px; margin-bottom:15px;">เงินจะรวมเป็นกองทุนเพื่อซื้อสิ่งของให้มูลนิธิ</p>

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

            <a href="../foundation.php" class="btn-back">ย้อนกลับ</a>
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