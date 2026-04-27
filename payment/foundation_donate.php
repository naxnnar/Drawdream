<?php
// payment/foundation_donate.php — บริจาคมูลนิธิ (need list) + Omise
// สรุปสั้น: หน้าเริ่มบริจาคมูลนิธิ สร้าง charge และเตรียมรายการ pending ก่อนแสดง QR
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';
include 'config.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';

if (!isset($_SESSION['user_id'])) {
    $msg = rawurlencode('กรุณาเข้าสู่ระบบก่อนจึงจะบริจาคได้');
    header("Location: ../login.php?page=login&error={$msg}");
    exit();
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'])) { header("Location: ../foundation.php"); exit(); }

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) { header("Location: ../foundation.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM foundation_profile WHERE foundation_id = ? LIMIT 1");
$stmt->bind_param("i", $fid);
$stmt->execute();
$foundation = $stmt->get_result()->fetch_assoc();
if (!$foundation) { header("Location: ../foundation.php"); exit(); }

$needOpen = drawdream_needlist_sql_open_for_donation();
$stmt2 = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt2->bind_param("i", $fid);
$stmt2->execute();
$goal = (float)($stmt2->get_result()->fetch_assoc()['goal'] ?? 0);

$stmt3 = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
$stmt3->bind_param("i", $fid);
$stmt3->execute();
$current = (float)($stmt3->get_result()->fetch_assoc()['current'] ?? 0);

$percent = ($goal > 0) ? min(100, ($current / $goal) * 100) : 0;
$percentRounded = (int)round($percent);
$remainingNeed = ($goal > 0) ? max(0.0, $goal - $current) : 0.0;
$maxDonatePerChargeBaht = ($goal > 0) ? (int)max(0, (int)floor($remainingNeed + 1e-9)) : 0;
$error = "";
$qr_image = "";
$charge_id = "";

if ($goal > 0 && $current >= $goal) {
    header('Location: ../needlist_result.php?fid=' . $fid);
    exit();
}
if ($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20) {
    $error = "ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้";
}

$items_stmt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND $needOpen ORDER BY urgent DESC, item_id DESC");
$items_stmt->bind_param("i", $fid);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$fdCoverNeedImage = '';
foreach ($items as $itCov) {
    $nfCov = trim((string)($itCov['need_foundation_image'] ?? ''));
    if ($nfCov !== '') {
        $fdCoverNeedImage = $nfCov;
        break;
    }
}

$donateDisabled = ($goal <= 0 || count($items) === 0 || ($goal > 0 && $remainingNeed > 0 && $remainingNeed < 20));

/**
 * @return array<int,array{item_name:string,qty_needed:float,price_estimate:float,line_total:float}>
 */
function drawdream_need_item_lines_from_row(array $item): array
{
    $raw = trim((string)($item['need_items_json'] ?? ''));
    $lines = [];
    $nameTokens = [];
    $rawNames = trim((string)($item['item_name'] ?? ''));
    if ($rawNames !== '') {
        $parts = preg_split('/\s*(?:,|\||\R)\s*/u', $rawNames);
        if (is_array($parts)) {
            foreach ($parts as $p) {
                $t = trim((string)$p);
                if ($t !== '') {
                    $nameTokens[] = $t;
                }
            }
        }
    }
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $qty = (float)($row['qty_needed'] ?? ($row['qty'] ?? 0));
                $price = (float)($row['price_estimate'] ?? ($row['price'] ?? 0));
                $lineTotal = (float)($row['line_total'] ?? 0);
                if ($lineTotal <= 0 && $qty > 0 && $price > 0) {
                    $lineTotal = $qty * $price;
                }
                if ($qty <= 0 || $price <= 0) {
                    continue;
                }
                $name = trim((string)($row['item_name'] ?? ''));
                if ($name === '') {
                    $name = $nameTokens[$idx] ?? ('รายการที่ ' . ((int)$idx + 1));
                }
                $lines[] = [
                    'item_name' => $name,
                    'qty_needed' => $qty,
                    'price_estimate' => $price,
                    'line_total' => $lineTotal,
                ];
            }
        }
    }
    if ($lines !== []) {
        return $lines;
    }
    $fallbackQty = (float)($item['qty_needed'] ?? 0);
    $fallbackTotal = (float)($item['total_price'] ?? 0);
    $fallbackPrice = $fallbackQty > 0 ? ($fallbackTotal / $fallbackQty) : 0.0;
    if ($fallbackQty > 0 && $fallbackPrice > 0) {
        return [[
            'item_name' => trim((string)($item['item_name'] ?? '')) !== '' ? (string)$item['item_name'] : 'รายการสิ่งของ',
            'qty_needed' => $fallbackQty,
            'price_estimate' => $fallbackPrice,
            'line_total' => $fallbackTotal,
        ]];
    }
    return [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $rawAmt = (string)($_POST['amount'] ?? '');
    $rawAmt = str_replace([',', ' ', "\xC2\xA0"], '', $rawAmt);
    $amount = (int)max(0, round((float)$rawAmt));
    if ($goal <= 0 || count($items) === 0) {
        $error = "ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือปิดรับแล้ว)";
    } elseif ($amount < 20) {
        $error = "จำนวนเงินขั้นต่ำ 20 บาท";
    } else {
        $stFreshGoal = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS goal FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
        $stFreshCur = $conn->prepare("SELECT COALESCE(SUM(current_donate), 0) AS current FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
        $gFresh = 0.0;
        $rFresh = 0.0;
        if ($stFreshGoal && $stFreshCur) {
            $stFreshGoal->bind_param("i", $fid);
            $stFreshGoal->execute();
            $gFresh = (float)($stFreshGoal->get_result()->fetch_assoc()['goal'] ?? 0);
            $stFreshCur->bind_param("i", $fid);
            $stFreshCur->execute();
            $rFresh = (float)($stFreshCur->get_result()->fetch_assoc()['current'] ?? 0);
        }
        $remFresh = ($gFresh > 0) ? max(0.0, $gFresh - $rFresh) : 0.0;
        if ($gFresh > 0) {
            if ($remFresh <= 0) {
                $error = 'รายการสิ่งของนี้ระดมครบตามเป้าหมายแล้ว ไม่สามารถบริจาคเพิ่มได้';
            } elseif ($amount > $remFresh + 1e-6) {
                $error = 'จำนวนบริจาคต้องไม่เกินยอดที่เหลือจะครบเป้าหมาย (' . number_format($remFresh, 0, '.', ',') . ' บาท)';
            } elseif ($remFresh < 20) {
                $error = 'ยอดที่เหลือจะครบเป้าหมายไม่ถึงขั้นต่ำการบริจาค 20 บาท — ไม่สามารถบริจาคเพิ่มได้';
            }
        }
    }

    if ($error === '') {
        drawdream_clear_pending_payment_session();
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
                $_SESSION['qr_image']              = $qr_image;
                header('Location: scan_qr.php?type=foundation&charge_id=' . rawurlencode($charge_id) . '&fid=' . $fid);
                exit();
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
    $response = curl_exec($ch); $curl_error = curl_error($ch);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="420" viewBox="0 0 320 420">'
            . '<rect width="320" height="420" fill="#ffffff"/>'
            . '<rect x="40" y="22" width="240" height="60" rx="6" fill="#1f4f7c"/>'
            . '<text x="160" y="56" font-size="20" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#fff" font-weight="700">THAI QR</text>'
            . '<text x="160" y="76" font-size="14" text-anchor="middle" font-family="Prompt,Arial,sans-serif" fill="#d8e8f7">PROMPTPAY</text>'
            . '<rect x="36" y="96" width="248" height="248" rx="8" fill="#fff" stroke="#d7dce4"/>'
            . '<rect x="52" y="112" width="56" height="56" fill="#000"/><rect x="59" y="119" width="42" height="42" fill="#fff"/><rect x="66" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="212" y="112" width="56" height="56" fill="#000"/><rect x="219" y="119" width="42" height="42" fill="#fff"/><rect x="226" y="126" width="28" height="28" fill="#000"/>'
            . '<rect x="52" y="272" width="56" height="56" fill="#000"/><rect x="59" y="279" width="42" height="42" fill="#fff"/><rect x="66" y="286" width="28" height="28" fill="#000"/>'
            . '<g fill="#000">'
            . '<rect x="132" y="118" width="8" height="8"/><rect x="148" y="118" width="8" height="8"/><rect x="164" y="118" width="8" height="8"/><rect x="180" y="118" width="8" height="8"/>'
            . '<rect x="124" y="134" width="8" height="8"/><rect x="140" y="134" width="8" height="8"/><rect x="156" y="134" width="8" height="8"/><rect x="172" y="134" width="8" height="8"/><rect x="188" y="134" width="8" height="8"/>'
            . '<rect x="124" y="150" width="8" height="8"/><rect x="140" y="150" width="8" height="8"/><rect x="164" y="150" width="8" height="8"/><rect x="188" y="150" width="8" height="8"/>'
            . '<rect x="116" y="166" width="8" height="8"/><rect x="132" y="166" width="8" height="8"/><rect x="148" y="166" width="8" height="8"/><rect x="164" y="166" width="8" height="8"/><rect x="180" y="166" width="8" height="8"/><rect x="196" y="166" width="8" height="8"/>'
            . '<rect x="116" y="182" width="8" height="8"/><rect x="132" y="182" width="8" height="8"/><rect x="156" y="182" width="8" height="8"/><rect x="172" y="182" width="8" height="8"/><rect x="196" y="182" width="8" height="8"/>'
            . '<rect x="116" y="198" width="8" height="8"/><rect x="140" y="198" width="8" height="8"/><rect x="156" y="198" width="8" height="8"/><rect x="180" y="198" width="8" height="8"/><rect x="196" y="198" width="8" height="8"/>'
            . '<rect x="116" y="214" width="8" height="8"/><rect x="132" y="214" width="8" height="8"/><rect x="148" y="214" width="8" height="8"/><rect x="164" y="214" width="8" height="8"/><rect x="180" y="214" width="8" height="8"/><rect x="196" y="214" width="8" height="8"/>'
            . '<rect x="124" y="230" width="8" height="8"/><rect x="140" y="230" width="8" height="8"/><rect x="156" y="230" width="8" height="8"/><rect x="172" y="230" width="8" height="8"/><rect x="188" y="230" width="8" height="8"/>'
            . '<rect x="124" y="246" width="8" height="8"/><rect x="140" y="246" width="8" height="8"/><rect x="164" y="246" width="8" height="8"/><rect x="180" y="246" width="8" height="8"/>'
            . '<rect x="132" y="262" width="8" height="8"/><rect x="148" y="262" width="8" height="8"/><rect x="164" y="262" width="8" height="8"/><rect x="180" y="262" width="8" height="8"/>'
            . '<rect x="124" y="278" width="8" height="8"/><rect x="140" y="278" width="8" height="8"/><rect x="156" y="278" width="8" height="8"/><rect x="172" y="278" width="8" height="8"/><rect x="188" y="278" width="8" height="8"/>'
            . '<rect x="124" y="294" width="8" height="8"/><rect x="148" y="294" width="8" height="8"/><rect x="172" y="294" width="8" height="8"/><rect x="188" y="294" width="8" height="8"/>'
            . '<rect x="124" y="310" width="8" height="8"/><rect x="140" y="310" width="8" height="8"/><rect x="156" y="310" width="8" height="8"/><rect x="172" y="310" width="8" height="8"/><rect x="188" y="310" width="8" height="8"/>'
            . '</g>'
            . '</svg>';
        return ['object'=>'charge','id'=>'chrg_mock_'.bin2hex(random_bytes(8)),'status'=>'pending','paid'=>false,'amount'=>$data['amount']??0,'currency'=>'THB','source'=>['type'=>'promptpay','scannable_code'=>['image'=>['download_uri'=>'data:image/svg+xml;base64,'.base64_encode($svg)]]]];
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
    <title>บริจาครายการสิ่งของ | DrawDream</title>
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/payment.css">
    <link rel="stylesheet" href="../css/foundation.css?v=21">
</head>
<body class="foundation-donate-page">

<?php include '../navbar.php'; ?>

<div class="fd-wrapper">
    <div class="fd-layout">

        <!-- ==================== ฝั่งซ้าย ==================== -->
        <div class="fd-left">
            <?php if ($fdCoverNeedImage !== ''): ?>
                <img src="../uploads/needs/<?= htmlspecialchars($fdCoverNeedImage) ?>"
                     class="fd-cover" alt="ภาพประกอบรายการสิ่งของ">
            <?php elseif (!empty($foundation['foundation_image'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($foundation['foundation_image']) ?>"
                     class="fd-cover" alt="">
            <?php endif; ?>

            <h2 class="fd-name"><?= htmlspecialchars($foundation['foundation_name']) ?></h2>
            <?php if ($goal <= 0 || empty($items)): ?>
                <div class="fd-alert fd-alert-error" role="status">ขณะนี้ไม่มีรายการสิ่งของที่เปิดรับบริจาค (ครบระยะเวลาหรือยังไม่มีรายการที่อนุมัติ)</div>
            <?php endif; ?>
            <?php if (!empty($foundation['foundation_desc'])): ?>
                <p class="fd-foundation-desc"><?= nl2br(htmlspecialchars($foundation['foundation_desc'])) ?></p>
            <?php endif; ?>

            <div class="fd-progress">
                <div class="fd-progress-remaining">
                    เหลืออีก <strong><?= number_format($remainingNeed, 0) ?> บาท</strong> จะครบเป้าหมาย
                </div>
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
                    $rawNeedImgs = foundation_needlist_item_filenames_from_row($item);
                    $imgsThree = array_pad(array_slice($rawNeedImgs, 0, 3), 3, '');
                    $total = number_format((float)($item['total_price'] ?? 0), 0);
                    $urgent = !empty($item['urgent']);
                ?>
                <div class="fd-item-row<?= $urgent ? ' fd-item-urgent' : '' ?>">
                    <div class="fd-item-thumbs">
                    <?php foreach ($imgsThree as $imn): ?>
                        <?php if ($imn !== ''): ?>
                        <img src="../uploads/needs/<?= htmlspecialchars($imn) ?>" class="fd-item-thumb" alt="">
                        <?php else: ?>
                        <div class="fd-item-noimg fd-item-noimg--small" title="ไม่มีรูป">—</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                    <div class="fd-item-detail">
                        <div class="fd-item-name">
                            <?= htmlspecialchars($item['item_name'] ?? '') ?>
                            <?php if ($urgent): ?><span class="fd-urgent-badge">ด่วน</span><?php endif; ?>
                        </div>
                        <div class="fd-item-meta">
                            รวม <?= $total ?> บาท
                        </div>
                        <?php
                            $itemLines = drawdream_need_item_lines_from_row($item);
                        ?>
                        <?php if ($itemLines !== []): ?>
                        <div class="fd-item-lines">
                            <?php foreach ($itemLines as $line): ?>
                                <div class="fd-item-line">
                                    <span class="fd-item-line-name"><?= htmlspecialchars((string)$line['item_name']) ?></span>
                                    <span class="fd-item-line-meta">
                                        <?= number_format((float)$line['qty_needed'], 0) ?> ชิ้น × <?= number_format((float)$line['price_estimate'], 2) ?> บาท
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
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

                <h3 class="fd-donate-section-title">เลือกจำนวนเงินที่ต้องการบริจาค</h3>
                <p class="fd-form-sub">เงินจะรวมเป็นกองทุนเพื่อซื้อสิ่งของให้มูลนิธิ</p>
                <?php if ($goal > 0 && $maxDonatePerChargeBaht > 0): ?>
                <p class="project-donate-cap-hint" style="margin:0 0 12px;font-size:0.95rem;color:#334155;font-weight:600;font-family:'Sarabun',sans-serif;">
                    บริจาคได้สูงสุดครั้งละไม่เกิน <?= number_format($maxDonatePerChargeBaht, 0, '.', ',') ?> บาท (ยอดที่เหลือจะครบเป้าหมาย)
                </p>
                <input type="hidden" id="maxDonateBaht" value="<?= (int)$maxDonatePerChargeBaht ?>">
                <?php endif; ?>

                <form method="POST" id="foundationDonateForm"<?= $donateDisabled ? ' class="fd-form-disabled"' : '' ?>>
                    <div class="amount-presets-grid fd-amount-presets-grid">
                        <button type="button" class="preset-btn" data-amt="2000" onclick="fdSelectPreset(2000)">2,000 บาท</button>
                        <button type="button" class="preset-btn" data-amt="1000" onclick="fdSelectPreset(1000)">1,000 บาท</button>
                        <button type="button" class="preset-btn" data-amt="500" onclick="fdSelectPreset(500)">500 บาท</button>
                        <div class="preset-btn preset-input-btn fd-preset-custom-cell">
                            <label for="amountInput" class="fd-preset-custom-label">ระบุจำนวน</label>
                            <input type="number" name="amount" id="amountInput" min="20" step="1"
                                   placeholder="ขั้นต่ำ 20 บาท" inputmode="numeric"
                                   oninput="fdClearPresetHighlight()">
                        </div>
                    </div>
                    <div class="payment-method">
                        <div class="method-card active">
                            <img src="../img/qr-code.png" alt="PromptPay" class="method-icon">
                            <span>PromptPay QR</span>
                        </div>
                    </div>
                    <button type="submit" name="pay" class="btn-pay"<?= $donateDisabled ? ' disabled' : '' ?>>บริจาค</button>
                </form>
        </div><!-- /.fd-right -->

    </div><!-- /.fd-layout -->
</div><!-- /.fd-wrapper -->

<script>
function fdParseBahtAmount(raw) {
    if (raw == null) return NaN;
    var s = String(raw).replace(/,/g, '').replace(/\u00a0/g, '').replace(/\s/g, '').trim();
    if (s === '') return NaN;
    var x = parseFloat(s);
    return isNaN(x) ? NaN : Math.round(x);
}
function fdGetMaxDonateBaht() {
    var el = document.getElementById('maxDonateBaht');
    if (!el || el.value === '') return null;
    var m = parseInt(el.value, 10);
    return (isNaN(m) || m <= 0) ? null : m;
}
function fdClearPresetHighlight() {
    document.querySelectorAll('#foundationDonateForm .amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
        b.classList.remove('preset-selected');
    });
}
function fdSelectPreset(amt) {
    var maxB = fdGetMaxDonateBaht();
    var useAmt = amt;
    if (maxB !== null && amt > maxB) {
        useAmt = maxB;
    }
    var inp = document.getElementById('amountInput');
    if (inp) {
        inp.value = String(useAmt);
    }
    document.querySelectorAll('#foundationDonateForm .amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
        var v = parseInt(b.getAttribute('data-amt'), 10);
        b.classList.toggle('preset-selected', v === useAmt);
    });
}
document.addEventListener('DOMContentLoaded', function () {
    var maxB = fdGetMaxDonateBaht();
    if (maxB === null) return;
    document.querySelectorAll('#foundationDonateForm .amount-presets-grid .preset-btn[data-amt]').forEach(function (b) {
        var v = parseInt(b.getAttribute('data-amt'), 10);
        if (v > maxB) {
            b.disabled = true;
            b.style.opacity = '0.45';
            b.title = 'เกินยอดที่เหลือจะครบเป้าหมาย';
        }
    });
});
document.getElementById('foundationDonateForm').addEventListener('submit', function (e) {
    var inp = document.getElementById('amountInput');
    var n = inp ? fdParseBahtAmount(inp.value) : NaN;
    var maxB = fdGetMaxDonateBaht();
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
    if (!n || isNaN(n) || n < 20) {
        e.preventDefault();
        alert('กรุณาเลือกหรือระบุจำนวนเงินอย่างน้อย 20 บาท');
        if (inp) inp.focus();
    }
});
</script>
</body>
</html>