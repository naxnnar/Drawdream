<?php
// ไฟล์นี้: payment\payment_project.php
// หน้าที่: หน้าชำระเงินสำหรับโครงการ
// ------------------------------
// Backend: สร้างรายการชำระเงินโครงการผ่าน Omise
// ------------------------------
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';

// ต้อง login ก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$project_id = (int)($_GET['project_id'] ?? 0);
if ($project_id <= 0) {
    header("Location: ../project.php");
    exit();
}

// ดึงข้อมูลโครงการ
$stmt = $conn->prepare("\n    SELECT p.*,\n           COALESCE(pd.category, p.category) AS category,\n           COALESCE(pd.target_group, p.target_group) AS target_group,\n           pd.project_quote,\n           pd.donation_option_1, pd.donation_option_2, pd.donation_option_3,\n           pd.urgent_info, pd.need_info, pd.update_info,\n           fp.contact_person, fp.phone, fp.phone_secondary, u.email AS email, fp.website, fp.facebook_url, fp.line_id, fp.address\n    FROM project p\n    LEFT JOIN project_detail pd ON pd.project_id = p.project_id\n    LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name\n    LEFT JOIN users u ON u.user_id = fp.user_id\n    WHERE p.project_id = ? AND p.project_status = 'approved'\n    LIMIT 1\n");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: ../project.php");
    exit();
}

function formatThaiDate($dateStr) {
    if (empty($dateStr)) return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return '-';
    $thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $day = (int)date('j', $ts);
    $monthIdx = (int)date('n', $ts) - 1;
    $year = (int)date('Y', $ts) + 543;
    return $day . ' ' . $thaiMonths[$monthIdx] . ' ' . $year;
}

function mapCategoryToSdgs($category) {
    $map = [
        'การศึกษา' => 'SDG 4: การศึกษาที่มีคุณภาพ',
        'สุขภาพและอนามัย' => 'SDG 3: สุขภาพและความเป็นอยู่ที่ดี',
        'อาหารและโภชนาการ' => 'SDG 2: ขจัดความหิวโหย',
        'สิ่งอำนวยความสะดวก' => 'SDG 10: ลดความเหลื่อมล้ำ',
    ];
    return $map[$category] ?? 'SDG 1: ขจัดความยากจน';
}

$fundraisingPeriod = formatThaiDate($project['start_date'] ?? null) . ' - ' . formatThaiDate($project['end_date'] ?? null);
$projectArea = trim((string)($project['address'] ?? '')) !== '' ? (string)$project['address'] : '-';
$sdgGoal = mapCategoryToSdgs((string)($project['category'] ?? ''));
$beneficiaryGroup = trim((string)($project['target_group'] ?? '')) !== '' ? (string)$project['target_group'] : '-';

$donationOptions = [];
foreach (['donation_option_1', 'donation_option_2', 'donation_option_3'] as $optKey) {
    $optVal = (int)($project[$optKey] ?? 0);
    if ($optVal > 0 && !in_array($optVal, $donationOptions, true)) {
        $donationOptions[] = $optVal;
    }
}
if (empty($donationOptions)) {
    $donationOptions = [50, 100, 500, 1000];
} elseif (count($donationOptions) < 4) {
    $fallbacks = [50, 100, 500, 1000, 2000];
    foreach ($fallbacks as $fb) {
        if (!in_array($fb, $donationOptions, true)) {
            $donationOptions[] = $fb;
        }
        if (count($donationOptions) >= 4) break;
    }
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
<div class="payment-card">

    <div class="project-info">
        <h2>บริจาคให้โครงการ</h2>
        <h3><?= htmlspecialchars($project['project_name']) ?></h3>
        <?php if (!empty($project['project_image'])): ?>
            <img src="../uploads/<?= htmlspecialchars($project['project_image']) ?>" class="project-img" alt="">
        <?php endif; ?>
        <p class="project-desc"><?= htmlspecialchars($project['project_desc']) ?></p>
        <div class="goal-info">
            เป้าหมาย <?= number_format($project['goal_amount'], 0) ?> บาท
        </div>

        <div class="contact-card">
            <h4>ข้อมูลติดต่อมูลนิธิ</h4>
            <p>👤 ผู้ติดต่อ: <?= htmlspecialchars($project['contact_person'] ?? '-') ?></p>
            <p>📞 เบอร์หลัก: <?= htmlspecialchars($project['phone'] ?? '-') ?></p>
            <p>📱 เบอร์รอง: <?= htmlspecialchars($project['phone_secondary'] ?? '-') ?></p>
            <p>✉️ อีเมล: <?= htmlspecialchars($project['email'] ?? '-') ?></p>
            <p>🌐 เว็บไซต์: <?= htmlspecialchars($project['website'] ?? '-') ?></p>
            <p>📘 Facebook: <?= htmlspecialchars($project['facebook_url'] ?? '-') ?></p>
            <p>💬 Line: <?= htmlspecialchars($project['line_id'] ?? '-') ?></p>
            <p>📍 ที่อยู่: <?= htmlspecialchars($project['address'] ?? '-') ?></p>
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
            <div class="project-detail-summary">
                <h4>รายละเอียดโครงการ</h4>
                <div class="detail-row"><strong>ระยะเวลาระดมทุน:</strong> <?= htmlspecialchars($fundraisingPeriod) ?></div>
                <div class="detail-row"><strong>พื้นที่ดำเนินโครงการ:</strong> <?= htmlspecialchars($projectArea) ?></div>
                <div class="detail-row"><strong>เป้าหมาย SDGs:</strong> <?= htmlspecialchars($sdgGoal) ?></div>
                <div class="detail-row"><strong>กลุ่มเป้าหมายที่ได้รับประโยชน์จากโครงการ:</strong> <?= htmlspecialchars($beneficiaryGroup) ?></div>
                <?php if (!empty($project['project_quote'])): ?>
                    <div class="detail-row"><strong>คำโปรย:</strong> <?= htmlspecialchars($project['project_quote']) ?></div>
                <?php endif; ?>
                <?php if (!empty($project['urgent_info'])): ?>
                    <div class="detail-row"><strong>ความจำเป็น:</strong> <?= htmlspecialchars($project['urgent_info']) ?></div>
                <?php endif; ?>
                <?php if (!empty($project['need_info'])): ?>
                    <div class="detail-row"><strong>กิจกรรมมูลนิธิ:</strong> <?= htmlspecialchars($project['need_info']) ?></div>
                <?php endif; ?>
                <?php if (!empty($project['update_info'])): ?>
                    <div class="detail-row"><strong>อัปเดต:</strong> <?= htmlspecialchars($project['update_info']) ?></div>
                <?php endif; ?>
            </div>

            <h3>เลือกจำนวนเงินที่ต้องการบริจาค</h3>

            <div class="amount-presets">
                <?php foreach ($donationOptions as $optAmount): ?>
                    <button type="button" class="preset-btn" onclick="setAmount(this, <?= (int)$optAmount ?>)"><?= number_format((int)$optAmount) ?> บาท</button>
                <?php endforeach; ?>
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

            <a href="../project.php" class="btn-back">ย้อนกลับ</a>
        <?php endif; ?>

    </div>
</div>
</div>

<script>
function setAmount(el, val) {
    document.getElementById('amountInput').value = val;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
}
</script>

</body>
</html>