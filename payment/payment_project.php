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
$stmt = $conn->prepare("
    SELECT p.*,
           fp.phone, u.email AS email, fp.address
    FROM project p
    LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
    LEFT JOIN users u ON u.user_id = fp.user_id
    WHERE p.project_id = ? AND p.project_status IN ('approved', 'completed', 'done')
    LIMIT 1
");
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

        // ตรวจสอบ error จาก curl หรือ API
        if (isset($source_response['error'])) {
            $error = "เกิดข้อผิดพลาด: " . $source_response['message'];
        } elseif (isset($source_response['object']) && $source_response['object'] === 'source') {
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

            // ตรวจสอบ error จาก charge response
            if (isset($charge_response['error'])) {
                $error = "เกิดข้อผิดพลาดในการสร้าง QR Code: " . $charge_response['message'];
            } elseif (isset($charge_response['id'])) {
                $charge_id = $charge_response['id'];
                $qr_image  = $charge_response['source']['scannable_code']['image']['download_uri'] ?? '';

                // เก็บ charge_id, qr_image, amount, project info ใน session
                $_SESSION['pending_charge_id']  = $charge_id;
                $_SESSION['pending_amount']     = $amount;
                $_SESSION['pending_project']    = $project['project_name'];
                $_SESSION['pending_project_id'] = $project_id;
                $_SESSION['qr_image']           = $qr_image;

                // redirect ไปหน้า scan_qr.php
                header('Location: scan_qr.php?charge_id=' . urlencode($charge_id));
                exit();

            } else {
                $error = "เกิดข้อผิดพลาดที่ไม่คาดคิด";
            }
        } else {
            $error = "ไม่สามารถสร้าง PromptPay Source ได้: " . ($source_response['message'] ?? 'unknown error');
        }
    }
}

// ======== ฟังก์ชันเรียก Omise API ========
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

    // API ไม่สามารถเข้าถึงได้ (เช่น ไม่มีเน็ตใน local) → fallback mock สำหรับ test key
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

// ฟังก์ชัน mock response สำหรับ local dev ที่ติดต่อ Omise ไม่ได้
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
    <!-- ซ้าย: ภาพ ชื่อโครงการ โปรไฟล์ เป้าหมาย -->
    <div class="project-info">
        <div style="position:relative;">
            <a href="javascript:history.back()" style="position:absolute;top:18px;left:18px;z-index:2;background:#fff;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px 0 rgba(0,0,0,0.07);border:none;text-decoration:none;">
                <span style="font-size:1.5em;color:#ff8800;">←</span>
            </a>
            <?php if (!empty($project['project_image'])): ?>
                <img src="../uploads/<?= htmlspecialchars($project['project_image']) ?>" class="project-img" alt="" style="margin-top:0;padding-top:0;display:block;border-top-left-radius:24px;border-top-right-radius:0;">
            <?php endif; ?>
        </div>
        <div class="project-info-inner">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                <h2 style="margin:0;display:flex;align-items:center;gap:10px;">
                    บริจาคให้โครงการ
                    <?php if (!empty($project['category'])): ?>
                        <span style="background:#ffe0b2;color:#e67e22;padding:3px 14px 3px 10px;border-radius:16px;font-size:0.95em;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                            <span style="font-size:1.2em;">🏷️</span> <?= htmlspecialchars($project['category']) ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <button onclick="navigator.share ? navigator.share({title:document.title,url:window.location.href}) : window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(window.location.href),'_blank')" title="แชร์โครงการ" style="border:none;background:transparent;cursor:pointer;font-size:1.5em;line-height:1;">
                    <span title="แชร์">🔗</span>
                </button>
            </div>
            <h3 style="margin-top:0;"><?= htmlspecialchars($project['project_name']) ?></h3>
            <!-- โปรไฟล์มูลนิธิ -->
            <div class="contact-card">
                <?php
                $foundationImg = '';
                $foundationId = 0;
                $stmtF = $conn->prepare("SELECT foundation_id, foundation_image FROM foundation_profile WHERE foundation_name = ? LIMIT 1");
                $stmtF->bind_param("s", $project['foundation_name']);
                $stmtF->execute();
                $fpRow = $stmtF->get_result()->fetch_assoc();
                if ($fpRow) {
                    $foundationImg = $fpRow['foundation_image'] ?? '';
                    $foundationId = (int)($fpRow['foundation_id'] ?? 0);
                }
                ?>
                <div style="display:flex;align-items:center;gap:12px;">
                    <?php if ($foundationImg): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($foundationImg) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1.5px solid #eee;">
                    <?php else: ?>
                        <div style="width:48px;height:48px;background:#f3f3f3;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:22px;">?</div>
                    <?php endif; ?>
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:1.1em;line-height:1.2;">
                            <?= htmlspecialchars($project['foundation_name']) ?>
                        </div>
                        <?php if ($foundationId): ?>
                            <a href="../foundation.php#f<?= $foundationId ?>" target="_blank" style="color:#1a73e8;font-size:0.97em;">ดูโปรไฟล์มูลนิธิ</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="goal-info">🎯 เป้าหมาย <?= number_format($project['goal_amount'], 0) ?> บาท</div>
        </div>
    </div>
    <!-- ขวา: รายละเอียด ฟอร์ม ปุ่ม -->
    <div class="payment-box">
        <div class="project-detail-summary" style="font-size:1.18em;background:none;border:none;box-shadow:none;padding:0;margin-bottom:28px;">
            <div class="detail-row" style="font-size:1.13em;font-weight:400;margin-bottom:18px;">
                <?= nl2br(htmlspecialchars($project['project_desc'])) ?>
            </div>
            <?php if (!empty($project['project_quote'])): ?>
                <div class="detail-row"><strong>คำโปรย</strong> <?= htmlspecialchars($project['project_quote']) ?></div>
            <?php endif; ?>
            <div class="detail-row"><strong>ระยะเวลาระดมทุน</strong> <?= htmlspecialchars($fundraisingPeriod) ?></div>
            <div class="detail-row"><strong>พื้นที่ดำเนินโครงการ</strong> <?= htmlspecialchars($projectArea) ?></div>
            <div class="detail-row"><strong>เป้าหมาย SDGs</strong> <?= htmlspecialchars($sdgGoal) ?></div>
            <div class="detail-row"><strong>กลุ่มเป้าหมาย</strong> <?= htmlspecialchars($beneficiaryGroup) ?></div>
            <?php if (!empty($project['need_info'])): ?>
                <div class="detail-row"><strong>กิจกรรมมูลนิธิ</strong> <?= htmlspecialchars($project['need_info']) ?></div>
            <?php endif; ?>
            <?php if (!empty($project['update_info'])): ?>
                <div class="detail-row"><strong>อัปเดต</strong> <?= htmlspecialchars($project['update_info']) ?></div>
            <?php endif; ?>
        </div>
        <h3 style="margin-top:18px;">เลือกจำนวนเงินที่ต้องการบริจาค</h3>
        <form method="POST">
            <div class="amount-presets-grid">
                <button type="button" class="preset-btn" onclick="selectPreset(2000)">2,000 บาท</button>
                <button type="button" class="preset-btn" onclick="selectPreset(1000)">1,000 บาท</button>
                <button type="button" class="preset-btn" onclick="selectPreset(500)">500 บาท</button>
                <div class="preset-btn preset-input-btn">
                    <label for="amountInput" style="display:block;font-size:1em;font-weight:700;color:#222;margin-bottom:2px;cursor:pointer;">ระบุจำนวน</label>
                    <input type="number" name="amount" id="amountInput" min="20" placeholder="ขั้นต่ำ 20 บาท" required style="font-size:1.2em;text-align:center;width:90%;border:none;border-bottom:2px solid #aaa;background:transparent;outline:none;margin:0 auto;display:block;" oninput="clearPresetBtns()">
                </div>
            </div>
            <div class="payment-method">
                <div class="method-card active">
                    <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                    <span>PromptPay QR</span>
                </div>
            </div>
            <button type="submit" name="pay" class="btn-pay" id="donateBtn">บริจาค</button>
        </form>
        <script>
        function selectPreset(val) {
            document.getElementById('amountInput').value = val;
            document.getElementById('amountInput').focus();
            clearPresetBtns();
            event.target.classList.add('active');
        }
        function clearPresetBtns() {
            document.querySelectorAll('.amount-presets-grid .preset-btn').forEach(b => b.classList.remove('active'));
        }
        document.getElementById('donateForm').addEventListener('submit', function(e) {
            var amount = document.getElementById('amountInput').value;
            if (!amount || amount < 20) {
                alert('กรุณากรอกจำนวนเงินขั้นต่ำ 20 บาท');
                e.preventDefault();
            }
        });
        </script>
    </div>
</div>

<?php if ($qr_image): ?>
            <div class="qr-section" style="text-align:center;margin:32px 0 24px 0;">
                <h3 style="font-size:1.3em;font-weight:700;">สแกน QR เพื่อชำระเงิน</h3>
                <img src="<?= htmlspecialchars($qr_image) ?>" alt="PromptPay QR" style="max-width:260px;width:100%;background:#fff;padding:16px;border-radius:16px;box-shadow:0 2px 12px 0 rgba(0,0,0,0.08);">
                <div style="margin-top:16px;font-size:1.15em;color:#222;">จำนวนเงิน <?= number_format((int)$_SESSION['pending_amount'] ?? 0) ?> บาท</div>
                <div style="margin-top:10px;color:#888;">โปรดสแกนด้วยแอปธนาคาร</div>
                <a href="?project_id=<?= $project_id ?>" style="display:inline-block;margin-top:22px;color:#ff8800;font-weight:600;text-decoration:underline;">กลับไปเลือกจำนวนเงินใหม่</a>
            </div>
        <?php endif; ?>
</body>
</html>