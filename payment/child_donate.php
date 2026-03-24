<?php
// ไฟล์นี้: payment\child_donate.php
// หน้าที่: หน้าบริจาคให้เด็กรายบุคคลผ่าน Omise PromptPay
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

// ต้อง login ก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// เฉพาะ donor เท่านั้นที่บริจาคได้
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) {
    header("Location: ../children_.php");
    exit();
}

$child_id = (int)($_POST['child_id'] ?? $_GET['child_id'] ?? 0);
if ($child_id <= 0) {
    header("Location: ../children_.php");
    exit();
}

// ดึงข้อมูลเด็ก
$stmt = $conn->prepare("
    SELECT c.*, COALESCE(NULLIF(c.foundation_name, ''), fp.foundation_name) AS display_foundation_name
    FROM Children c
    LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
    WHERE c.child_id = ? AND c.approve_profile = 'อนุมัติ'
    LIMIT 1
");
$stmt->bind_param("i", $child_id);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc();

if (!$child) {
    header("Location: ../children_.php");
    exit();
}

// ======== helper functions ========
function omise_request($method, $path, $data = []) {
    $ch = curl_init(OMISE_API_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, OMISE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response   = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        if (strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) {
            return _omise_local_mock($path, $data);
        }
        return ['error' => 'curl_error', 'message' => $curl_error];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['error' => 'json_error', 'message' => 'Invalid JSON response'];
    }
    return $decoded;
}

// Mock QR สำหรับ local dev ที่ติดต่อ Omise ไม่ได้
function _omise_local_mock(string $path, array $data): array {
    if (strpos($path, '/sources') !== false) {
        return ['object' => 'source', 'id' => 'src_mock_' . bin2hex(random_bytes(6)), 'type' => 'promptpay'];
    }
    if (strpos($path, '/charges') !== false) {
        $cid = 'chrg_mock_' . bin2hex(random_bytes(8));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
            . '<rect width="200" height="200" fill="#fff"/>'
            . '<rect x="10" y="10" width="56" height="56" fill="#000"/>'
            . '<rect x="17" y="17" width="42" height="42" fill="#fff"/>'
            . '<rect x="24" y="24" width="28" height="28" fill="#000"/>'
            . '<rect x="134" y="10" width="56" height="56" fill="#000"/>'
            . '<rect x="141" y="17" width="42" height="42" fill="#fff"/>'
            . '<rect x="148" y="24" width="28" height="28" fill="#000"/>'
            . '<rect x="10" y="134" width="56" height="56" fill="#000"/>'
            . '<rect x="17" y="141" width="42" height="42" fill="#fff"/>'
            . '<rect x="24" y="148" width="28" height="28" fill="#000"/>'
            . '<text x="100" y="108" font-size="11" text-anchor="middle" font-family="Arial" fill="#555">TEST MODE</text>'
            . '<text x="100" y="122" font-size="9" text-anchor="middle" font-family="Arial" fill="#999">Mock PromptPay QR</text>'
            . '</svg>';
        return [
            'object'   => 'charge',
            'id'       => $cid,
            'status'   => 'pending',
            'paid'     => false,
            'amount'   => $data['amount'] ?? 0,
            'currency' => 'THB',
            'source'   => ['scannable_code' => ['image' => ['download_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg)]]],
        ];
    }
    return ['error' => 'mock_unknown', 'message' => 'Mock: unknown API path'];
}

// ดึงค่าเริ่มต้นจาก POST (ส่งมาจาก children_donate.php) หรือ GET fallback
$preset_amount = (int)($_POST['amount'] ?? $_GET['amount'] ?? 0);

$error     = "";
$qr_image  = "";
$charge_id = "";

// ======== ประมวลผลการชำระเงิน ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $amount = (int)($_POST['amount'] ?? 0);

    if ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        $amount_satang = $amount * 100; // Omise ใช้สตางค์

        // สร้าง PromptPay Source
        $source_response = omise_request('POST', '/sources', [
            'type'     => 'promptpay',
            'amount'   => $amount_satang,
            'currency' => 'THB',
        ]);

        if (isset($source_response['error'])) {
            $error = "เกิดข้อผิดพลาด: " . $source_response['message'];
        } elseif (isset($source_response['object']) && $source_response['object'] === 'source') {
            $source_id = $source_response['id'];

            // สร้าง Charge พร้อม metadata เพื่อใช้ใน check_child_payment.php
            $charge_response = omise_request('POST', '/charges', [
                'amount'      => $amount_satang,
                'currency'    => 'THB',
                'source'      => $source_id,
                'description' => 'บริจาคให้เด็ก: ' . $child['child_name'],
                'metadata'    => [
                    'child_id' => $child_id,
                    'donor_id' => $_SESSION['user_id'],
                    'type'     => 'child',
                ],
            ]);

            if (isset($charge_response['error'])) {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . $charge_response['message'];
            } elseif (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

                // เก็บ session เพื่อใช้ใน check_child_payment.php
                $_SESSION['pending_charge_id'] = $charge_id;
                $_SESSION['pending_amount']    = $amount;
                $_SESSION['pending_child_id']  = $child_id;
                $_SESSION['pending_child_name'] = $child['child_name'];
            } else {
                $error = "เกิดข้อผิดพลาดที่ไม่คาดคิด";
            }
        } else {
            $error = "ไม่สามารถสร้าง PromptPay Source ได้: " . ($source_response['message'] ?? 'unknown error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บริจาคให้เด็ก: <?php echo htmlspecialchars($child['child_name']); ?> | DrawDream</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
    <style>
        /* Layout หลัก */
        .child-donate-wrap {
            display: flex;
            gap: 32px;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 16px;
            align-items: flex-start;
        }

        /* แผงซ้าย: ข้อมูลเด็ก */
        .child-info-panel {
            flex: 0 0 300px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .child-info-panel img.child-cover {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .child-info-body {
            padding: 20px;
        }
        .child-info-body h2 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #1e3a6e;
        }
        .child-meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: #555;
            margin-bottom: 7px;
        }
        .child-meta-row .bi {
            color: #CE573F;
            font-size: 1rem;
        }

        /* แผงขวา: ชำระเงิน */
        .payment-box {
            flex: 1;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            padding: 32px 28px;
        }
        .payment-box h3 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1e3a6e;
        }

        /* Preset Amount Buttons */
        .amount-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .preset-btn {
            padding: 8px 18px;
            border: 2px solid #CE573F;
            border-radius: 30px;
            background: #fff;
            color: #CE573F;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s;
            font-size: 0.92rem;
        }
        .preset-btn:hover,
        .preset-btn.active {
            background: #CE573F;
            color: #fff;
        }

        /* Input amount */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #444;
            font-size: 0.93rem;
        }
        .form-group input[type="number"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 10px;
            font-size: 1rem;
            outline: none;
            transition: border .15s;
        }
        .form-group input[type="number"]:focus {
            border-color: #CE573F;
        }

        /* Blue QR Card (แสดงหลังสร้าง QR สำเร็จ) */
        .qr-pay-card {
            background: #3C5099;
            border-radius: 36px;
            padding: 40px 40px 44px;
            max-width: 500px;
            margin: 50px auto;
            text-align: center;
            color: #fff;
        }
        .qr-pay-wrapper {
            background: #fff;
            border-radius: 24px;
            padding: 24px;
            display: inline-block;
            margin-bottom: 32px;
        }
        .qr-pay-wrapper img {
            width: 240px;
            height: 240px;
            display: block;
            object-fit: contain;
        }
        .qr-pay-info { width: 100%; margin-bottom: 30px; }
        .qr-pay-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 10px;
            font-size: 1.05rem;
            color: #fff;
        }
        .qr-pay-row span:first-child { color: #c8d0ff; font-weight: 400; }
        .qr-pay-row span:last-child { font-weight: 600; }
        .qr-pay-amount { font-size: 1.6rem !important; font-weight: 900 !important; color: #fff !important; }
        .qr-pay-divider { border: none; border-top: 1px solid rgba(255,255,255,0.15); margin: 4px 10px; }
        .btn-confirm-pay {
            display: block;
            background: #F8CE32;
            color: #222;
            font-size: 1.15rem;
            font-weight: 800;
            border: none;
            border-radius: 18px;
            padding: 18px 30px;
            width: 100%;
            margin-bottom: 14px;
            text-decoration: none;
            box-shadow: 0 6px 0 #c9a20a;
            transition: transform .1s, box-shadow .1s;
        }
        .btn-confirm-pay:hover { background: #f0c200; color: #222; }
        .btn-confirm-pay:active { transform: translateY(4px); box-shadow: 0 2px 0 #c9a20a; }
        .qr-thank-text {
            font-size: 0.92rem;
            color: #c8d0ff;
            line-height: 1.9;
            margin: 16px 0 0;
        }

        /* Buttons */
        .btn-pay {
            display: block;
            width: 100%;
            padding: 12px;
            background: #F1CF54;
            color: #222;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
            transition: background .15s;
        }
        .btn-pay:hover { background: #e5bb2e; color: #222; }
        .btn-back-link {
            display: block;
            text-align: center;
            color: #888;
            font-size: 0.9rem;
            text-decoration: none;
            margin-top: 6px;
        }
        .btn-back-link:hover { color: #555; }
        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 0.92rem;
        }
        .payment-method {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .method-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: 2px solid #CE573F;
            border-radius: 10px;
            background: #fff5f3;
            font-size: 0.9rem;
            font-weight: 600;
            color: #CE573F;
        }
        .method-icon { width: 30px; height: 30px; object-fit: contain; }

        @media (max-width: 700px) {
            .child-donate-wrap { flex-direction: column; }
            .child-info-panel { flex: unset; width: 100%; }
        }
    </style>
</head>
<body>

<?php include '../navbar.php'; ?>

<?php if (!empty($qr_image)): ?>
<!-- โหมด QR: แสดง Blue Card ตามสไตล์มาตรฐาน -->
<main class="container py-3">
    <div class="qr-pay-card">
        <div class="qr-pay-wrapper">
            <img src="<?php echo htmlspecialchars($qr_image); ?>" alt="QR Code PromptPay">
        </div>
        <div class="qr-pay-info">
            <div class="qr-pay-row">
                <span>ชื่อบัญชี</span>
                <span><?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></span>
            </div>
            <hr class="qr-pay-divider">
            <div class="qr-pay-row">
                <span>จำนวนเงิน</span>
                <span class="qr-pay-amount"><?php echo number_format($_SESSION['pending_amount'], 0); ?> บาท</span>
            </div>
        </div>
        <a href="check_child_payment.php?charge_id=<?php echo urlencode($charge_id); ?>&child_id=<?php echo $child_id; ?>"
           class="btn-confirm-pay">ชำระเงินแล้ว</a>
        <a href="child_donate.php?child_id=<?php echo $child_id; ?>" class="btn btn-outline-light w-100" style="border-radius:14px;font-weight:700;">ยกเลิก / สร้างใหม่</a>
        <p class="qr-thank-text">
            ขอขอบคุณเป็นอย่างยิ่งสำหรับการสนับสนุนของท่าน<br>
            ความเมตตานี้ได้เติมพลังให้ความฝันของน้อง ๆ ก้าวไปอีกขั้น<br>
            และสร้างอนาคตที่งดงามยิ่งขึ้น
        </p>
    </div>
</main>

<?php else: ?>
<!-- โหมดฟอร์ม: two-column layout -->
<div class="child-donate-wrap">

    <!-- แผงซ้าย: ข้อมูลเด็ก -->
    <div class="child-info-panel">
        <img class="child-cover"
             src="../uploads/Children/<?php echo htmlspecialchars($child['photo_child']); ?>"
             alt="รูปเด็ก">
        <div class="child-info-body">
            <h2><?php echo htmlspecialchars($child['child_name']); ?></h2>
            <div class="child-meta-row">
                <i class="bi bi-cake2-fill"></i>
                <span><?php echo (int)$child['age']; ?> ปี</span>
            </div>
            <div class="child-meta-row">
                <i class="bi bi-stars"></i>
                <span><?php echo htmlspecialchars($child['dream']); ?></span>
            </div>
            <div class="child-meta-row">
                <i class="bi bi-house-heart-fill"></i>
                <span><?php echo htmlspecialchars($child['display_foundation_name'] ?? '-'); ?></span>
            </div>
            <?php if (!empty($child['wish'])): ?>
            <div class="child-meta-row" style="margin-top:10px; align-items:flex-start;">
                <i class="bi bi-heart-fill" style="margin-top:3px;"></i>
                <span><?php echo htmlspecialchars($child['wish']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- แผงขวา: ชำระเงิน -->
    <div class="payment-box">

        <?php if ($error): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <h3>เลือกจำนวนเงินที่ต้องการบริจาค</h3>
        <p style="color:#666; font-size:13px; margin-bottom:16px;">เงินบริจาคจะส่งตรงถึงมูลนิธิผู้ดูแลเด็ก</p>

        <div class="amount-presets">
            <button type="button" class="preset-btn <?php echo $preset_amount===200?'active':''; ?>" onclick="setAmount(200, this)">200 บาท</button>
            <button type="button" class="preset-btn <?php echo $preset_amount===500?'active':''; ?>" onclick="setAmount(500, this)">500 บาท</button>
            <button type="button" class="preset-btn <?php echo $preset_amount===1000?'active':''; ?>" onclick="setAmount(1000, this)">1,000 บาท</button>
            <button type="button" class="preset-btn" onclick="setAmount(2000, this)">2,000 บาท</button>
        </div>

        <form method="POST">
            <div class="form-group">
                <label>จำนวนเงิน (บาท) <span style="color:#CE573F;">*</span></label>
                <input type="number" name="amount" id="amountInput"
                       min="20" placeholder="ขั้นต่ำ 20 บาท"
                       value="<?php echo $preset_amount > 0 ? $preset_amount : ''; ?>" required>
            </div>
            <div class="payment-method">
                <div class="method-card">
                    <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                    <span>PromptPay QR</span>
                </div>
            </div>
            <button type="submit" name="pay" class="btn-pay">บริจาค</button>
        </form>

        <a href="../children_donate.php?id=<?php echo $child_id; ?>" class="btn-back-link">← ย้อนกลับ</a>

    </div>
</div>
<?php endif; ?>

<script>
function setAmount(val, btn) {
    document.getElementById('amountInput').value = val;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
</script>

</body>
</html>
