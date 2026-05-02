<?php
// payment/payment_project.php — หน้าชำระเงินโครงการ + Omise PromptPay
// สรุปสั้น: หน้าเริ่มบริจาคโครงการ สร้าง charge และบันทึกรายการ pending ก่อนพาไป QR
/**
 * ชำระเงินโครงการ: Omise PromptPay (source + charge) แล้วไป scan_qr.php
 * mock ใช้ได้เฉพาะเมื่อ OMISE_ALLOW_LOCAL_MOCK=true (ค่าเริ่มต้น false → QR จาก Omise test จริง)
 *
 * @see README.md
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once __DIR__ . '/omise_helpers.php';
require_once __DIR__ . '/../includes/project_donation_dates.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/payment_transaction_schema.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/donate_type.php';

// ต้อง login ก่อน
if (!isset($_SESSION['user_id'])) {
    $msg = rawurlencode('กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้');
    header("Location: ../login.php?page=login&error={$msg}");
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
    FROM foundation_project p
    LEFT JOIN foundation_profile fp ON fp.foundation_name = p.foundation_name
    LEFT JOIN `user` u ON u.user_id = fp.user_id
    WHERE p.project_id = ? AND p.project_status IN ('approved', 'completed', 'done') AND p.deleted_at IS NULL
    LIMIT 1
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: ../project.php");
    exit();
}

$todayBangkok = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$donationStartEff = drawdream_project_effective_donation_start($project);
if (!empty($project['end_date']) && $todayBangkok > substr((string)$project['end_date'], 0, 10)) {
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

/** @return array{num:int,title:string} */
function mapCategoryToSdgDetails(?string $category): array
{
    $map = [
        'การศึกษา' => ['num' => 4, 'title' => 'SDG 4: การศึกษาที่มีคุณภาพ'],
        'สุขภาพและอนามัย' => ['num' => 3, 'title' => 'SDG 3: สุขภาพและความเป็นอยู่ที่ดี'],
        'อาหารและโภชนาการ' => ['num' => 2, 'title' => 'SDG 2: ขจัดความหิวโหย'],
        'สิ่งอำนวยความสะดวก' => ['num' => 10, 'title' => 'SDG 10: ลดความเหลื่อมล้ำ'],
    ];
    $key = (string)($category ?? '');
    return $map[$key] ?? ['num' => 1, 'title' => 'SDG 1: ขจัดความยากจน'];
}

/**
 * path แบบ URL สำหรับแท็ก img (เรียกจากไฟล์ใน payment/) → img/sdg/sdg1–sdg16 นามสกุลใดที่มีในโฟลเดอร์
 */
function drawdream_sdg_icon_web_path(int $sdgNum): string
{
    $n = max(1, min(16, $sdgNum));
    $dir = realpath(__DIR__ . '/../img/sdg');
    if ($dir === false) {
        return '../img/rainbow.png';
    }
    $base = $dir . DIRECTORY_SEPARATOR . 'sdg' . $n;
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
        if (is_file($base . $ext)) {
            return '../img/sdg/sdg' . $n . $ext;
        }
    }
    return '../img/rainbow.png';
}

/**
 * บันทึก donation สถานะ pending ตาม charge_id
 *
 * @return int donate_id หรือ 0 ถ้าล้มเหลว
 */
function drawdream_insert_pending_project_donation(
    mysqli $conn,
    int $categoryId,
    int $targetProjectId,
    int $donorUserId,
    float $amountBaht,
    string $omiseChargeId
): int {
    drawdream_payment_transaction_ensure_schema($conn);

    $pending = 'pending';
    $insP = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, transaction_status, donate_type, recurring_plan_code
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
    );
    if (!$insP) {
        return 0;
    }
    $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
    $planOnce = DRAWDREAM_DONATION_RECURRING_PLAN_ONE_TIME;
    $insP->bind_param(
        'iiidsssss',
        $categoryId,
        $targetProjectId,
        $donorUserId,
        $amountBaht,
        $pending,
        $omiseChargeId,
        $pending,
        $dtProj,
        $planOnce
    );
    if (!$insP->execute()) {
        return 0;
    }

    return (int)$conn->insert_id;
}

$fundraisingPeriod = formatThaiDate($donationStartEff ?? '') . ' - ' . formatThaiDate($project['end_date'] ?? null);
$locText = trim((string)($project['location'] ?? ''));
$addrFallback = trim((string)($project['address'] ?? ''));
$projectArea = $locText !== '' ? $locText : ($addrFallback !== '' ? $addrFallback : '-');
$sdgDetails = mapCategoryToSdgDetails((string)($project['category'] ?? ''));
$sdgGoal = $sdgDetails['title'];
$beneficiaryGroup = trim((string)($project['target_group'] ?? '')) !== '' ? (string)$project['target_group'] : '-';

$goalProj = !empty($project['goal_amount']) ? (float)$project['goal_amount'] : 0.0;
$raisedProj = (float)($project['current_donate'] ?? 0);
$progressProj = ($goalProj > 0) ? min(100.0, ($raisedProj / $goalProj) * 100.0) : 0.0;
$remainingProj = ($goalProj > 0) ? max(0.0, $goalProj - $raisedProj) : 0.0;
/** ยอดบริจาคสูงสุดต่อครั้ง (บาท) เมื่อมีเป้าหมาย — 0 = ไม่จำกัด (ไม่ได้ตั้งเป้าเป็นยอดเงิน) */
$maxDonatePerChargeBaht = ($goalProj > 0) ? (int)max(0, (int)floor($remainingProj + 1e-9)) : 0;

if ($goalProj > 0 && $remainingProj <= 0) {
    header('Location: ../project.php?msg=' . rawurlencode('โครงการนี้ระดมทุนครบตามเป้าหมายแล้ว ไม่สามารถบริจาคเพิ่มได้'));
    exit();
}
if ($goalProj > 0 && $remainingProj > 0 && $remainingProj < 20) {
    header('Location: ../project.php?msg=' . rawurlencode('ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้'));
    exit();
}

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
    $rawAmt = (string)($_POST['amount'] ?? '');
    $rawAmt = str_replace([',', ' ', "\xC2\xA0"], '', $rawAmt);
    $amount = (int) max(0, round((float) $rawAmt));

    if (!empty($project['end_date']) && $todayBangkok > substr((string)$project['end_date'], 0, 10)) {
        $error = "ปิดรับบริจาคแล้ว";
    } elseif ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        $stFresh = $conn->prepare('SELECT goal_amount, current_donate FROM foundation_project WHERE project_id = ? AND deleted_at IS NULL LIMIT 1');
        $stFresh->bind_param('i', $project_id);
        $stFresh->execute();
        $rowFresh = $stFresh->get_result()->fetch_assoc();
        $gFresh = (float)($rowFresh['goal_amount'] ?? 0);
        $rFresh = (float)($rowFresh['current_donate'] ?? 0);
        $remFresh = ($gFresh > 0) ? max(0.0, $gFresh - $rFresh) : 0.0;
        if ($gFresh > 0) {
            if ($remFresh <= 0) {
                $error = 'โครงการนี้ระดมทุนครบตามเป้าหมายแล้ว ไม่สามารถบริจาคเพิ่มได้';
            } elseif ($amount > $remFresh + 1e-6) {
                $error = 'จำนวนบริจาคต้องไม่เกินยอดที่เหลือจะครบเป้าหมาย (' . number_format($remFresh, 0, '.', ',') . ' บาท)';
            } elseif ($remFresh < 20) {
                $error = 'ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้';
            }
        }
    }

    if ($error === '') {
        // ปิดรายการ QR เก่าที่ไม่สำเร็จค้างในระบบ แล้วเริ่มรายการสแกนใหม่
        drawdream_abandon_all_pending_qr_for_donor($conn, (int)$_SESSION['user_id']);
        drawdream_clear_pending_payment_session();

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
                $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($charge_response);
                if ($qr_image === '' && strpos((string) $charge_id, 'chrg_mock_') !== 0) {
                    $again = drawdream_omise_fetch_charge((string) $charge_id);
                    if ($again) {
                        $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($again);
                    }
                }

                $categoryIdResolved = drawdream_get_or_create_project_donate_category_id($conn);
                $pendingDonateId = drawdream_insert_pending_project_donation(
                    $conn,
                    $categoryIdResolved,
                    $project_id,
                    (int)$_SESSION['user_id'],
                    (float)$amount,
                    $charge_id
                );
                if ($pendingDonateId <= 0) {
                    $error = 'ไม่สามารถบันทึกรายการบริจาคชั่วคราวได้ กรุณาลองใหม่';
                } else {
                    // เก็บ charge_id, qr_image, amount, project info ใน session
                    $_SESSION['pending_charge_id']  = $charge_id;
                    $_SESSION['pending_amount']     = $amount;
                    $_SESSION['pending_project']    = $project['project_name'];
                    $_SESSION['pending_project_id'] = $project_id;
                    $_SESSION['qr_image']           = $qr_image;
                    $_SESSION['pending_donate_id']  = $pendingDonateId;

                    // redirect ไปหน้าสแกน QR ร่วม (โครงการ)
                    header('Location: scan_qr.php?type=project&charge_id=' . urlencode($charge_id));
                    exit();
                }

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

    // API ไม่สามารถเข้าถึงได้ → mock เฉพาะเมื่อเปิด OMISE_ALLOW_LOCAL_MOCK และใช้ test key
    if ($response === false || $response === '') {
        if (defined('OMISE_ALLOW_LOCAL_MOCK') && OMISE_ALLOW_LOCAL_MOCK && strpos(OMISE_SECRET_KEY, 'skey_test_') === 0) {
            return _omise_local_mock($path, $data);
        }
        $msg = ($curl_error !== '') ? $curl_error : 'ไม่ได้รับตอบกลับจาก Omise (ตรวจสอบอินเทอร์เน็ต / PHP cURL / SSL)';
        return ['error' => 'curl_error', 'message' => $msg];
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
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ชำระเงิน | DrawDream</title>
        <link rel="stylesheet" href="../css/payment.css?v=5">
        <style>
        .project-sdgs-benefit-wrap {
            display: flex;
            gap: 18px;
            margin: 12px 0 0 0;
            flex-wrap: wrap;
        }
        .sdgs-block, .benefit-block {
            background: #f7f7f7;
            border-radius: 12px;
            padding: 14px 18px 12px 18px;
            min-width: 180px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            box-shadow: 0 2px 8px #0001;
            margin-bottom: 0;
        }
        .sdgs-label, .benefit-label {
            font-size: 1.08em;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 7px;
            font-family: 'Prompt', sans-serif;
            display: flex;
            align-items: center;
        }
        .sdgs-list, .benefit-list {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .sdg-item, .benefit-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.08em;
            font-family: 'Prompt', sans-serif;
            font-weight: 600;
        }
        .sdg-emoji {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #fff;
            font-size: 1.15em;
            font-weight: 800;
            margin-right: 4px;
            box-shadow: 0 1px 4px #0001;
        }
        .benefit-emoji {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #222;
            font-size: 1.25em;
            font-weight: 800;
            margin-right: 4px;
            background: #f9c2d1;
            box-shadow: 0 1px 4px #0001;
        }
        .sdg-text, .benefit-text {
            font-size: 1.08em;
            font-family: 'Prompt', sans-serif;
            font-weight: 600;
            color: #222;
        }
        </style>
</head>
<body>

<?php include '../navbar.php'; ?>

<div class="payment-container">

<div class="payment-card">
    <!-- ซ้าย: ภาพ ชื่อโครงการ โปรไฟล์ เป้าหมาย -->
    <div class="project-info">
        <div style="position:relative;">
            <a href="javascript:history.back()" style="position:absolute;top:18px;left:18px;z-index:2;background:#fff;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px 0 rgba(0,0,0,0.07);border:none;text-decoration:none;">
                <span style="font-size:1.5em;color:#3C5099;">←</span>
            </a>
            <?php if (!empty($project['project_image'])): ?>
                <img src="<?= htmlspecialchars(drawdream_project_image_url((string)($project['project_image'] ?? ''), '../uploads/'), ENT_QUOTES, 'UTF-8') ?>" class="project-img" alt="" style="margin-top:0;padding-top:0;display:block;border-top-left-radius:24px;border-top-right-radius:0;">
            <?php endif; ?>
        </div>
        <div class="project-info-inner">
            <div class="project-payment-heading-row" style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                <h2 style="margin:0;display:flex;align-items:center;gap:10px;">
                    บริจาคให้โครงการ
                    <?php if (!empty($project['category'])): ?>
                        <span class="project-category-pill" style="background:rgba(60,80,153,0.14);color:#3C5099;padding:3px 14px 3px 10px;border-radius:16px;font-size:0.95em;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
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
                $fdnName = htmlspecialchars($project['foundation_name']);
                $profileAria = 'ดูโปรไฟล์มูลนิธิ ' . $project['foundation_name'];
                ?>
                <?php if ($foundationId): ?>
                <a class="contact-card-link" href="../foundation_public_profile.php?id=<?= (int)$foundationId ?>" aria-label="<?= htmlspecialchars($profileAria, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <div class="contact-card-inner">
                    <?php if ($foundationImg): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($foundationImg) ?>" alt="" class="contact-card-avatar">
                    <?php else: ?>
                        <div class="contact-card-avatar contact-card-avatar--empty">?</div>
                    <?php endif; ?>
                    <div class="contact-card-text">
                        <div class="contact-card-name"><?= $fdnName ?></div>
                    </div>
                </div>
                <?php if ($foundationId): ?>
                </a>
                <?php endif; ?>
            </div>
                        <div class="project-donate-progress" aria-label="ความคืบหน้าการระดมทุน">
                            <?php if ($goalProj > 0): ?>
                                <div class="project-donate-progress-meta">
                                    <span>ได้รับ <?= number_format($raisedProj, 0) ?> บาท</span>
                                    <span>เป้าหมาย <?= number_format($goalProj, 0) ?> บาท (<?= (int)round($progressProj) ?>%)</span>
                                </div>
                                <?php if ($remainingProj > 0): ?>
                                    <p class="project-donate-progress-remaining">เหลืออีก <?= number_format($remainingProj, 0) ?> บาทจะครบเป้าหมาย</p>
                                <?php else: ?>
                                    <p class="project-donate-progress-remaining project-donate-progress-remaining--done">ครบเป้าหมายตามยอดที่ตั้งไว้แล้ว</p>
                                <?php endif; ?>
                                <div class="project-donate-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($progressProj) ?>">
                                    <div class="project-donate-progress-fill" style="width: <?= (float)$progressProj ?>%"></div>
                                </div>
                            <?php else: ?>
                                <div class="project-donate-progress-meta project-donate-progress-meta--single">
                                    <span>ได้รับ <?= number_format($raisedProj, 0) ?> บาท</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- SDG Image Row -->
                        <?php
                        $sdgNum = (int)$sdgDetails['num'];
                        $sdgTitle = (string)$sdgDetails['title'];
                        $sdgImgPath = drawdream_sdg_icon_web_path($sdgNum);
                        ?>
                        <div class="sdg-row">
                            <img src="<?= htmlspecialchars($sdgImgPath) ?>" alt="SDG <?= (int)$sdgNum ?>" class="sdg-img" title="<?= htmlspecialchars($sdgTitle) ?>" id="sdg-img-clickable" style="cursor:zoom-in;">
                            <span class="sdg-title">เป้าหมาย SDGs<br>SDG <?= (int)$sdgNum ?> <?= htmlspecialchars(preg_replace('/^SDG ?\d+:? ?/', '', $sdgTitle)) ?></span>
                        </div>
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
            <!-- <div class="detail-row"><strong>เป้าหมาย SDGs</strong> <?= htmlspecialchars($sdgGoal) ?></div> -->
            <!-- <div class="detail-row"><strong>กลุ่มเป้าหมาย</strong> <?= htmlspecialchars($beneficiaryGroup) ?></div> -->
            <?php if (!empty($project['need_info'])): ?>
                <div class="detail-row"><strong>กิจกรรมมูลนิธิ</strong> <?= htmlspecialchars($project['need_info']) ?></div>
            <?php endif; ?>
            <?php if (!empty($project['update_text'])): ?>
                <div class="detail-row"><strong>สรุปผลลัพธ์ล่าสุดจากมูลนิธิ</strong> <?= nl2br(htmlspecialchars($project['update_text'])) ?></div>
            <?php endif; ?>
        </div>
        <h3 style="margin-top:18px;">เลือกจำนวนเงินที่ต้องการบริจาค</h3>
        <?php if ($goalProj > 0 && $maxDonatePerChargeBaht > 0): ?>
        <p class="project-donate-cap-hint" style="margin:0 0 12px;font-size:0.95rem;color:#334155;font-weight:600;font-family:'Sarabun',sans-serif;">
            บริจาคได้สูงสุดครั้งละไม่เกิน <?= number_format($maxDonatePerChargeBaht, 0, '.', ',') ?> บาท (ยอดที่เหลือจะครบเป้าหมาย)
        </p>
        <input type="hidden" id="maxDonateBaht" value="<?= (int)$maxDonatePerChargeBaht ?>">
        <?php endif; ?>
        <form method="POST" id="projectDonateForm">
            <div class="amount-presets-grid">
                <button type="button" class="preset-btn" data-amt="2000" onclick="selectPreset(2000)">2,000 บาท</button>
                <button type="button" class="preset-btn" data-amt="1000" onclick="selectPreset(1000)">1,000 บาท</button>
                <button type="button" class="preset-btn" data-amt="500" onclick="selectPreset(500)">500 บาท</button>
                <div class="preset-btn preset-input-btn project-preset-custom-cell">
                    <label for="amountInput" class="project-preset-custom-label">ระบุจำนวน</label>
                    <input type="text" name="amount" id="amountInput" placeholder="ขั้นต่ำ 20 บาท" inputmode="decimal" autocomplete="off" enterkeyhint="done" oninput="clearPresetBtns()" aria-label="จำนวนเงินบริจาค (บาท) ขั้นต่ำ 20 บาท">
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
        <style>
        .sdg-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 4px 0 0 0;
            max-width: 340px;
        }
        .sdg-row .sdg-img {
            width: 36px !important;
            height: 36px !important;
            max-width: 36px !important;
            max-height: 36px !important;
            object-fit: contain !important;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 4px #0001;
            padding: 1px;
            display: block;
        }
        .sdg-title {
            font-size: 1.13em;
            font-weight: 700;
            color: #222;
            font-family: 'Prompt', sans-serif;
            line-height: 1.2;
            word-break: break-word;
        }
        .amount-presets-grid .preset-btn.preset-selected {
            outline: 2px solid #3C5099;
            background: #f0f2fa;
        }
        </style>
        <script>
        function clearPresetBtns() {
            document.querySelectorAll('.amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
                b.classList.remove('preset-selected');
            });
        }
        function parseBahtAmount(raw) {
            if (raw == null) return NaN;
            var s = String(raw).replace(/,/g, '').replace(/\u00a0/g, '').replace(/\s/g, '').trim();
            if (s === '') return NaN;
            var x = parseFloat(s);
            return isNaN(x) ? NaN : Math.round(x);
        }
        function getMaxDonateBaht() {
            var el = document.getElementById('maxDonateBaht');
            if (!el || el.value === '') return null;
            var m = parseInt(el.value, 10);
            return (isNaN(m) || m <= 0) ? null : m;
        }
        function selectPreset(amt) {
            var maxB = getMaxDonateBaht();
            var useAmt = amt;
            if (maxB !== null && amt > maxB) {
                useAmt = maxB;
            }
            var inp = document.getElementById('amountInput');
            if (inp) {
                inp.value = String(useAmt);
            }
            document.querySelectorAll('.amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
                var v = parseInt(b.getAttribute('data-amt'), 10);
                b.classList.toggle('preset-selected', v === useAmt);
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            var maxB = getMaxDonateBaht();
            if (maxB === null) return;
            document.querySelectorAll('.amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
                var v = parseInt(b.getAttribute('data-amt'), 10);
                if (v > maxB) {
                    b.disabled = true;
                    b.style.opacity = '0.45';
                    b.title = 'เกินยอดที่เหลือจะครบเป้าหมาย';
                }
            });
        });
        document.getElementById('projectDonateForm').addEventListener('submit', function (e) {
            var inp = document.getElementById('amountInput');
            var n = inp ? parseBahtAmount(inp.value) : NaN;
            var maxB = getMaxDonateBaht();
            if (inp && !isNaN(n) && n >= 20) {
                if (maxB !== null && n > maxB) {
                    e.preventDefault();
                    alert('จำนวนบริจาคต้องไม่เกินยอดที่เหลือจะครบเป้าหมาย (' + maxB.toLocaleString('th-TH') + ' บาท)');
                    inp.focus();
                    return;
                }
                inp.value = String(n);
                return;
            }
            e.preventDefault();
            alert('กรุณาเลือกหรือระบุจำนวนเงินอย่างน้อย 20 บาท');
            if (inp) inp.focus();
        });
        </script>

        <!-- SDG Image Modal -->
        <div id="sdgModal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);justify-content:center;align-items:center;">
          <img id="sdgModalImg" src="" alt="SDG" style="max-width:80vw;max-height:80vh;border-radius:16px;box-shadow:0 4px 32px #0008;">
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          const sdgImg = document.getElementById('sdg-img-clickable') || document.querySelector('.sdg-img');
          const modal = document.getElementById('sdgModal');
          const modalImg = document.getElementById('sdgModalImg');
          if(sdgImg && modal && modalImg) {
            sdgImg.addEventListener('click', function() {
              modal.style.display = 'flex';
              modalImg.src = sdgImg.src;
            });
            modal.addEventListener('click', function(e) {
              if(e.target === modal) {
                modal.style.display = 'none';
                modalImg.src = '';
              }
            });
          }
        });
        </script>