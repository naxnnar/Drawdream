<?php
// ไฟล์นี้: payment\foundation_donate.php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit(); }
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) { header("Location: ../foundation.php"); exit(); }

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) { header("Location: ../foundation.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1");
$stmt->bind_param("i", $fid);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) { header("Location: ../foundation.php"); exit(); }

$stmt2 = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'approved'");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$goal = (float)($stmt2->get_result()->fetch_assoc()['goal'] ?? 0);

$stmt3 = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'approved'");
$stmt3->bind_param("i", $fid);
$stmt3->execute();
$current = (float)($stmt3->get_result()->fetch_assoc()['current'] ?? 0);

$percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;

$items_stmt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND approve_item = 'approved' ORDER BY urgent DESC, item_id DESC");
$items_stmt->bind_param("i", $fid);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$error = ""; $qr_image = ""; $charge_id = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        $amount_satang = $amount * 100;
        $source_response = omise_request('POST', '/sources', ['type' => 'promptpay', 'amount' => $amount_satang, 'currency' => 'THB']);
        if (isset($source_response['error'])) {
            $error = "เกิดข้อผิดพลาด: " . $source_response['message'];
        } elseif (isset($source_response['object']) && $source_response['object'] === 'source') {
            $charge_response = omise_request('POST', '/charges', [
                'amount' => $amount_satang, 'currency' => 'THB',
                'source' => $source_response['id'],
                'description' => 'บริจาครายการสิ่งของ: ' . $foundation['foundation_name'],
                'metadata' => ['foundation_id' => $fid, 'donor_id' => $_SESSION['user_id'], 'type' => 'needlist'],
            ]);
            if (isset($charge_response['error'])) {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . $charge_response['message'];
            } elseif (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';
                $_SESSION['pending_charge_id']    = $charge_id;
                $_SESSION['pending_amount']        = $amount;
                $_SESSION['pending_foundation']    = $foundation['foundation_name'];
                $_SESSION['pending_foundation_id'] = $fid;
            } else { $error = "เกิดข้อผิดพลาดที่ไม่คาดคิด"; }
        } else { $error = "ไม่สามารถสร้าง PromptPay Source ได้: " . ($source_response['message'] ?? 'unknown error'); }
    }
}

function omise_request($method, $path, $data = []) {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    $response = curl_exec($ch); $curl_error = curl_error($ch); curl_close($ch);
    if ($response === false || $response === '') {
        if (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) return _omise_local_mock($path, $data);
        return ['error' => 'curl_error', 'message' => $curl_error];
    }
    $decoded = json_decode($response, true);
    return $decoded ?? ['error' => 'json_error', 'message' => 'Invalid JSON'];
}

function _omise_local_mock(string $path, array $data): array {
    if (strpos($path, '/sources') !== false) return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    if (strpos($path, '/charges') !== false) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#fff"/><rect x="10" y="10" width="56" height="56" fill="#000"/><rect x="17" y="17" width="42" height="42" fill="#fff"/><rect x="24" y="24" width="28" height="28" fill="#000"/><rect x="134" y="10" width="56" height="56" fill="#000"/><rect x="141" y="17" width="42" height="42" fill="#fff"/><rect x="148" y="24" width="28" height="28" fill="#000"/><rect x="10" y="134" width="56" height="56" fill="#000"/><rect x="17" y="141" width="42" height="42" fill="#fff"/><rect x="24" y="148" width="28" height="28" fill="#000"/><text x="100" y="108" font-size="11" text-anchor="middle" font-family="Arial" fill="#555">TEST MODE</text><text x="100" y="122" font-size="9" text-anchor="middle" font-family="Arial" fill="#999">Mock PromptPay QR</text></svg>';
        return ['object'=>'charge','id'=>'chrg_mock_'.bin2hex(random_bytes(8)),'status'=>'pending','paid'=>false,'amount'=>$data['amount']??0,'currency'=>'THB','source'=>['type'=>'promptpay','scannable_code'=>['image'=>['download_uri'=>'data:image/svg+xml;base64,'.base64_encode($svg)]]]];
    }
    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
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
    <link rel="stylesheet" href="../css/foundation.css">
</head>
<body class="foundation-donate-page">

<?php include '../navbar.php'; ?>

<div class="fd-wrapper">
    <div class="fd-layout">

        <!-- ==================== ฝั่งซ้าย ==================== -->
        <div class="fd-left">
            <?php if (!empty($foundation['foundation_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($foundation['foundation_image']) ?>"
                     class="fd-cover" alt="">
            <?php endif; ?>

            <h2 class="fd-name"><?= htmlspecialchars($foundation['foundation_name']) ?></h2>

            <div class="fd-progress">
                <div class="fd-bar">
                    <div style="width:<?= (int)$percent ?>%;min-width:<?= $percent > 0 ? '6px' : '0' ?>;"></div>
                </div>
                <div class="fd-progress-text">
                    ยอดบริจาค <strong><?= number_format($current, 0) ?></strong> / <?= number_format($goal, 0) ?> บาท
                </div>
            </div>

            <?php if (!empty($items)): ?>
            <div class="fd-items-wrap">
                <h3 class="fd-items-title">รายการสิ่งของที่ต้องการ</h3>
                <?php foreach ($items as $item):
                    if (!is_array($item)) continue;
                    $imgs = array_values(array_filter(explode('|', (string)($item['photo_item'] ?? ''))));
                    $img0 = $imgs[0] ?? '';
                    $qty   = (int)($item['quantity_required'] ?? 0);
                    $price = number_format((float)($item['item_price'] ?? 0), 0);
                    $total = number_format((float)($item['total_price'] ?? 0), 0);
                    $urgent = !empty($item['urgent']);
                ?>
                <div class="fd-item-row<?= $urgent ? ' fd-item-urgent' : '' ?>">
                    <?php if ($img0): ?>
                        <img src="../uploads/needs/<?= htmlspecialchars($img0) ?>" class="fd-item-thumb" alt="">
                    <?php else: ?>
                        <div class="fd-item-noimg">📦</div>
                    <?php endif; ?>
                    <div class="fd-item-detail">
                        <div class="fd-item-name">
                            <?= htmlspecialchars($item['item_name'] ?? '') ?>
                            <?php if ($urgent): ?><span class="fd-urgent-badge">ด่วน</span><?php endif; ?>
                        </div>
                        <div class="fd-item-meta">
                            ต้องการ <?= $qty ?> ชิ้น &nbsp;·&nbsp; ราคา/หน่วย <?= $price ?> บาท &nbsp;·&nbsp; รวม <?= $total ?> บาท
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div><!-- /.fd-left -->

        <!-- ==================== ฝั่งขวา ==================== -->
        <div class="fd-right">
            <?php if ($error): ?>
                <div class="fd-alert fd-alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($qr_image)): ?>
                <div class="fd-qr-section">
                    <h3>สแกน QR Code เพื่อชำระเงิน</h3>
                    <p class="fd-qr-amount">จำนวน <strong><?= number_format($_SESSION['pending_amount'], 0) ?> บาท</strong></p>
                    <img src="<?= htmlspecialchars($qr_image) ?>" class="fd-qr-img" alt="QR Code PromptPay">
                    <p class="fd-qr-hint">QR Code มีอายุ 10 นาที</p>
                    <p class="fd-qr-charge">Charge ID: <?= htmlspecialchars($charge_id) ?></p>
                    <a href="check_needlist_payment.php?charge_id=<?= urlencode($charge_id) ?>&fid=<?= urlencode($fid) ?>" class="fd-btn-paid">
                        ✓ ชำระเงินแล้ว
                    </a>
                    <a href="foundation_donate.php?fid=<?= $fid ?>" class="fd-btn-cancel">ยกเลิก</a>
                </div>

            <?php else: ?>
                <h3 class="fd-form-title">เลือกจำนวนเงินที่ต้องการบริจาค</h3>
                <p class="fd-form-sub">เงินจะรวมเป็นกองทุนเพื่อซื้อสิ่งของให้มูลนิธิ</p>

                <div class="fd-presets">
                    <button type="button" class="fd-preset-btn" onclick="setAmount(50,this)"><span class="fd-preset-num">50</span><span class="fd-preset-unit">บาท</span></button>
                    <button type="button" class="fd-preset-btn" onclick="setAmount(100,this)"><span class="fd-preset-num">100</span><span class="fd-preset-unit">บาท</span></button>
                    <button type="button" class="fd-preset-btn" onclick="setAmount(500,this)"><span class="fd-preset-num">500</span><span class="fd-preset-unit">บาท</span></button>
                    <button type="button" class="fd-preset-btn" onclick="setAmount(1000,this)"><span class="fd-preset-num">1,000</span><span class="fd-preset-unit">บาท</span></button>
                </div>

                <form method="POST" class="fd-form">
                    <div class="fd-form-group">
                        <label class="fd-label">จำนวนเงิน (บาท) *</label>
                        <input type="number" name="amount" id="amountInput" min="20"
                               placeholder="ขั้นต่ำ 20 บาท" required class="fd-input">
                    </div>
                    <div class="fd-method-card">
                        <img src="../img/qr-code.png" alt="PromptPay" class="fd-method-icon">
                        <span>PromptPay QR</span>
                    </div>
                    <button type="submit" name="pay" class="fd-btn-pay">❤ บริจาค</button>
                </form>

                <a href="../foundation.php" class="fd-btn-back">← ย้อนกลับ</a>
            <?php endif; ?>
        </div><!-- /.fd-right -->

    </div><!-- /.fd-layout -->
</div><!-- /.fd-wrapper -->

<script>
function setAmount(val, btn) {
    document.getElementById('amountInput').value = val;
    document.querySelectorAll('.fd-preset-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
</script>
</body>
</html>