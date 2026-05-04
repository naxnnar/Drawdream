<?php
declare(strict_types=1);

/**
 * donation_receipt.php
 * หน้าแสดงใบเสร็จอิเล็กทรอนิกส์ "1 รายการ"
 * เปิดได้เฉพาะ:
 * - เจ้าของรายการบริจาค
 * - admin
 *
 * รองรับใบเสร็จ 2 แบบ:
 * - บุคคลธรรมดา
 * - นิติบุคคล
 */

// สรุปสั้น: ไฟล์นี้รับผิดชอบการทำงานส่วน donation receipt

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$viewerId = (int)($_SESSION['user_id'] ?? 0);
$viewerRole = (string)($_SESSION['role'] ?? '');
$donateId = (int)($_GET['donate_id'] ?? 0);

if ($donateId <= 0) {
    http_response_code(400);
    echo 'ไม่พบเลขที่ใบเสร็จ';
    exit;
}

// ดึงเฉพาะรายการที่จ่ายสำเร็จแล้วเท่านั้น
$st = $conn->prepare(
    "SELECT d.donate_id, d.category_id, d.target_id, d.donor_id, d.amount, d.transfer_datetime, d.omise_charge_id, dn.tax_id,
            dc.project_donate, dc.needitem_donate, dc.child_donate
     FROM donation d
     LEFT JOIN donate_category dc ON dc.category_id = d.category_id
     LEFT JOIN donor dn ON dn.user_id = d.donor_id
     WHERE d.donate_id = ? AND d.payment_status = 'completed'
     LIMIT 1"
);
if (!$st) {
    http_response_code(500);
    echo 'ระบบไม่พร้อมใช้งาน';
    exit;
}
$st->bind_param('i', $donateId);
$st->execute();
$receipt = $st->get_result()->fetch_assoc();
if (!$receipt) {
    http_response_code(404);
    echo 'ไม่พบข้อมูลใบเสร็จ';
    exit;
}

$ownerDonorId = (int)($receipt['donor_id'] ?? 0);
// ความปลอดภัย: donor เห็นได้แค่ของตัวเอง, admin เห็นได้ทุกใบ
if ($viewerRole !== 'admin' && $viewerId !== $ownerDonorId) {
    http_response_code(403);
    echo 'คุณไม่มีสิทธิ์เข้าถึงใบเสร็จนี้';
    exit;
}

$donorName = '-';
$donorTaxId = trim((string)($receipt['tax_id'] ?? ''));
$donorAddress = '';
$donorReceiptType = 'บุคคลธรรมดา';
$requestedReceiptMode = strtolower(trim((string)($_GET['receipt_mode'] ?? '')));
if (!in_array($requestedReceiptMode, ['individual', 'juristic'], true)) {
    $requestedReceiptMode = '';
}
$receiptMode = 'individual';
$receiptModeNotice = '';
$hasReceiptType = false;
$hasReceiptCompanyName = false;
$hasReceiptCompanyTaxId = false;
$hasReceiptCompanyAddress = false;
// ตรวจคอลัมน์ donor แบบ runtime (กันบางเครื่อง migrate schema ยังไม่ครบ)
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_type'");
if ($colChk && $colChk->num_rows > 0) {
    $hasReceiptType = true;
}
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_company_name'");
if ($colChk && $colChk->num_rows > 0) {
    $hasReceiptCompanyName = true;
}
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_company_tax_id'");
if ($colChk && $colChk->num_rows > 0) {
    $hasReceiptCompanyTaxId = true;
}
$colChk = $conn->query("SHOW COLUMNS FROM donor LIKE 'receipt_company_address'");
if ($colChk && $colChk->num_rows > 0) {
    $hasReceiptCompanyAddress = true;
}

$donorSelectCols = ['first_name', 'last_name', 'tax_id'];
$donorSelectCols[] = $hasReceiptType ? 'receipt_type' : "'individual' AS receipt_type";
$donorSelectCols[] = $hasReceiptCompanyName ? 'receipt_company_name' : "'' AS receipt_company_name";
$donorSelectCols[] = $hasReceiptCompanyTaxId ? 'receipt_company_tax_id' : "'' AS receipt_company_tax_id";
$donorSelectCols[] = $hasReceiptCompanyAddress ? 'receipt_company_address' : "'' AS receipt_company_address";

$stDonor = $conn->prepare(
    'SELECT ' . implode(', ', $donorSelectCols) . ' FROM donor WHERE user_id = ? LIMIT 1'
);
if ($stDonor) {
    $stDonor->bind_param('i', $ownerDonorId);
    $stDonor->execute();
    $dRow = $stDonor->get_result()->fetch_assoc() ?: [];
    $personName = trim((string)($dRow['first_name'] ?? '') . ' ' . (string)($dRow['last_name'] ?? ''));
    if ($personName !== '') {
        $donorName = $personName;
    }
    $profileReceiptType = strtolower(trim((string)($dRow['receipt_type'] ?? 'individual')));
    if (!in_array($profileReceiptType, ['individual', 'juristic'], true)) {
        $profileReceiptType = 'individual';
    }
    $companyName = trim((string)($dRow['receipt_company_name'] ?? ''));
    $companyTaxId = trim((string)($dRow['receipt_company_tax_id'] ?? ''));
    $companyAddress = trim((string)($dRow['receipt_company_address'] ?? ''));
    $canUseJuristic = ($companyName !== '');
    $receiptMode = $requestedReceiptMode !== '' ? $requestedReceiptMode : $profileReceiptType;
    if ($receiptMode === 'juristic' && !$canUseJuristic) {
        $receiptMode = 'individual';
        $receiptModeNotice = 'ยังไม่มีข้อมูลนิติบุคคลครบถ้วน ระบบจึงใช้ข้อมูลบุคคลธรรมดาแทน';
    }
    if ($receiptMode === 'juristic') {
        $donorReceiptType = 'นิติบุคคล';
        $donorName = $companyName;
        if ($companyTaxId !== '') {
            $donorTaxId = $companyTaxId;
        }
        $donorAddress = $companyAddress;
    } else {
        $donorReceiptType = 'บุคคลธรรมดา';
        $fromProfileTax = trim((string)($dRow['tax_id'] ?? ''));
        if ($donorTaxId === '' && $fromProfileTax !== '') {
            $donorTaxId = $fromProfileTax;
        }
    }
}

$categoryLabel = 'บริจาคทั่วไป';
$targetLabel = '-';
$projectLabel = trim((string)($receipt['project_donate'] ?? ''));
$needLabel = trim((string)($receipt['needitem_donate'] ?? ''));
$childLabel = trim((string)($receipt['child_donate'] ?? ''));
$targetId = (int)($receipt['target_id'] ?? 0);
if ($projectLabel !== '' && $projectLabel !== '-') {
    $categoryLabel = 'บริจาคโครงการ';
    $stTarget = $conn->prepare('SELECT project_name FROM foundation_project WHERE project_id = ? LIMIT 1');
    if ($stTarget) {
        $stTarget->bind_param('i', $targetId);
        $stTarget->execute();
        $t = $stTarget->get_result()->fetch_assoc();
        $targetLabel = trim((string)($t['project_name'] ?? ''));
    }
} elseif ($needLabel !== '' && $needLabel !== '-') {
    $categoryLabel = 'บริจาคสิ่งของ';
    $stTarget = $conn->prepare('SELECT foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
    if ($stTarget) {
        $stTarget->bind_param('i', $targetId);
        $stTarget->execute();
        $t = $stTarget->get_result()->fetch_assoc();
        $targetLabel = trim((string)($t['foundation_name'] ?? ''));
    }
} elseif ($childLabel !== '' && $childLabel !== '-') {
    $categoryLabel = 'บริจาคเด็ก';
    $stTarget = $conn->prepare('SELECT child_name FROM foundation_children WHERE child_id = ? LIMIT 1');
    if ($stTarget) {
        $stTarget->bind_param('i', $targetId);
        $stTarget->execute();
        $t = $stTarget->get_result()->fetch_assoc();
        $targetLabel = trim((string)($t['child_name'] ?? ''));
    }
}
if ($targetLabel === '') {
    $targetLabel = '-';
}

$ts = strtotime((string)($receipt['transfer_datetime'] ?? ''));
$dateText = $ts !== false ? date('d/m/Y', $ts) : '-';
$timeText = $ts !== false ? date('H:i', $ts) . ' น.' : '-';
$receiptRefDate = $ts !== false ? date('Ymd', $ts) : date('Ymd');
$receiptRef = 'DD-' . $receiptRefDate . '-' . str_pad((string)$donateId, 7, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/includes/favicon_meta.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ใบเสร็จบริจาค | DrawDream</title>
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        body { margin: 0; background: #f2f4f8; font-family: 'Prompt', sans-serif; color: #24304a; }
        .receipt-wrap { max-width: 920px; margin: 26px auto 40px; padding: 0 14px; }
        .receipt-card {
            background: #fff;
            border-radius: 18px;
            border: 1px solid #dfe5f2;
            box-shadow: 0 14px 38px rgba(36, 48, 74, 0.08);
            padding: 26px 28px 30px;
        }
        .head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid #e7ebf4; padding-bottom: 16px; }
        .brand h1 { margin: 0 0 6px; font-size: 1.75rem; color: #243a7a; }
        .brand p { margin: 0; font-size: 0.95rem; color: #506087; }
        .ref { text-align: right; }
        .ref .k { color: #66789f; font-size: 0.9rem; }
        .ref .v { font-weight: 700; font-size: 1.03rem; }
        .receipt-mode-switch {
            margin-top: 14px;
            display: inline-flex;
            border: 1px solid #d8dfef;
            border-radius: 999px;
            overflow: hidden;
            background: #f8faff;
        }
        .receipt-mode-switch__btn {
            padding: 8px 14px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 700;
            color: #304369;
            background: transparent;
            border: 0;
        }
        .receipt-mode-switch__btn.is-active {
            background: #597d57;
            color: #fff;
        }
        .receipt-mode-print-only {
            display: none;
            margin-top: 10px;
            font-size: 0.92rem;
            color: #304369;
        }
        .receipt-mode-print-only .tag {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid #d8dfef;
            background: #f8faff;
            font-weight: 700;
        }
        .receipt-mode-notice {
            margin-top: 10px;
            background: #fff6e7;
            border: 1px solid #f0c87a;
            border-radius: 10px;
            color: #6b4b11;
            font-size: 0.88rem;
            padding: 8px 10px;
        }
        .grid { margin-top: 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .box { border: 1px solid #e5eaf5; border-radius: 12px; padding: 12px 14px; background: #fcfdff; }
        .box .k { font-size: 0.88rem; color: #607196; }
        .box .v { margin-top: 4px; font-size: 1.02rem; font-weight: 600; }
        .amt {
            margin-top: 16px;
            background: #eff4ff;
            border: 1px solid #cad9ff;
            border-radius: 14px;
            padding: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .amt .sum { font-size: 1.5rem; font-weight: 800; color: #1c3978; }
        .foot { margin-top: 16px; color: #5d6d90; font-size: 0.9rem; line-height: 1.65; }
        .actions { margin-top: 18px; display: flex; gap: 10px; justify-content: flex-end; }
        .btn { border: 0; border-radius: 10px; padding: 10px 16px; text-decoration: none; font-weight: 700; cursor: pointer; }
        .btn-print { background: #3c5099; color: #fff; }
        .btn-back { background: #e6ebf8; color: #25365f; }
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm;
            }
            .navbar, .actions { display: none !important; }
            body { background: #fff; }
            .receipt-wrap { margin: 0; max-width: none; padding: 0; }
            .receipt-card {
                box-shadow: none;
                border: 0;
                border-radius: 0;
                padding: 10px 12px;
                font-size: 12px;
            }
            .head {
                padding-bottom: 8px;
                margin-bottom: 6px;
                gap: 6px;
            }
            .brand h1 { font-size: 27px; margin: 0 0 4px; }
            .brand p { font-size: 16px; }
            .ref .k { font-size: 13px; }
            .ref .v { font-size: 16px; }
            .receipt-mode-switch {
                margin-top: 6px;
            }
            .receipt-mode-switch__btn {
                display: none !important;
            }
            .receipt-mode-switch__btn.is-active {
                display: inline-block !important;
                font-size: 13px;
                padding: 5px 12px;
                border-radius: 999px;
            }
            .receipt-mode-print-only {
                display: block;
            }
            .receipt-mode-notice {
                margin-top: 6px;
                padding: 6px 8px;
                font-size: 12px;
            }
            .grid {
                margin-top: 8px;
                gap: 7px;
            }
            .box {
                padding: 8px 10px;
                border-radius: 10px;
            }
            .box .k { font-size: 13px; }
            .box .v {
                margin-top: 2px;
                font-size: 16px;
                line-height: 1.25;
            }
            .amt {
                margin-top: 10px;
                border-radius: 10px;
                padding: 9px 10px;
            }
            .amt .sum {
                font-size: 34px;
                line-height: 1.1;
            }
            .foot {
                margin-top: 9px;
                font-size: 11px;
                line-height: 1.35;
            }
            .receipt-card, .grid, .box, .amt {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
        @media (max-width: 760px) {
            .grid { grid-template-columns: 1fr; }
            .ref { text-align: left; }
        }
        @media (max-width: 480px) {
            .receipt-wrap { padding: 0 8px; margin: 12px auto 28px; }
            .receipt-card { padding: 16px 14px 18px; }
            .brand h1 { font-size: 1.4rem; }
            .head { flex-direction: column; gap: 4px; }
            .actions { flex-direction: column-reverse; gap: 8px; }
            .btn { width: 100%; text-align: center; display: block; }
            .amt { flex-direction: column; gap: 6px; }
            .amt .sum { font-size: 1.3rem; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="receipt-wrap">
    <div class="receipt-card">
        <div class="head">
            <div class="brand">
                <h1>ใบเสร็จรับเงินบริจาคอิเล็กทรอนิกส์</h1>
                <p>DrawDream Platform (e-Donation)</p>
            </div>
            <div class="ref">
                <div class="k">เลขที่ใบเสร็จ</div>
                <div class="v"><?php echo htmlspecialchars($receiptRef); ?></div>
                <div class="k">วันที่ <?php echo htmlspecialchars($dateText); ?> เวลา <?php echo htmlspecialchars($timeText); ?></div>
            </div>
        </div>
        <div class="receipt-mode-switch" role="tablist" aria-label="เลือกประเภทออกใบเสร็จ">
            <a href="?donate_id=<?php echo (int)$donateId; ?>&receipt_mode=individual"
               class="receipt-mode-switch__btn<?php echo $receiptMode === 'individual' ? ' is-active' : ''; ?>"
               role="tab" aria-selected="<?php echo $receiptMode === 'individual' ? 'true' : 'false'; ?>">บุคคลธรรมดา</a>
            <a href="?donate_id=<?php echo (int)$donateId; ?>&receipt_mode=juristic"
               class="receipt-mode-switch__btn<?php echo $receiptMode === 'juristic' ? ' is-active' : ''; ?>"
               role="tab" aria-selected="<?php echo $receiptMode === 'juristic' ? 'true' : 'false'; ?>">นิติบุคคล</a>
        </div>
        <div class="receipt-mode-print-only">ประเภทเอกสาร: <span class="tag"><?php echo htmlspecialchars($donorReceiptType); ?></span></div>
        <?php if ($receiptModeNotice !== ''): ?>
        <div class="receipt-mode-notice"><?php echo htmlspecialchars($receiptModeNotice); ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="box">
                <div class="k">ผู้บริจาค (<?php echo htmlspecialchars($donorReceiptType); ?>)</div>
                <div class="v"><?php echo htmlspecialchars($donorName); ?></div>
            </div>
            <div class="box">
                <div class="k">เลขประจำตัวผู้เสียภาษี</div>
                <div class="v"><?php echo htmlspecialchars($donorTaxId !== '' ? $donorTaxId : '-'); ?></div>
            </div>
            <div class="box">
                <div class="k">ประเภทการบริจาค</div>
                <div class="v"><?php echo htmlspecialchars($categoryLabel); ?></div>
            </div>
            <div class="box">
                <div class="k">รายการอ้างอิง</div>
                <div class="v"><?php echo htmlspecialchars($targetLabel); ?></div>
            </div>
            <div class="box">
                <div class="k">Charge อ้างอิง</div>
                <div class="v"><?php echo htmlspecialchars((string)($receipt['omise_charge_id'] ?? '-') ?: '-'); ?></div>
            </div>
            <div class="box">
                <div class="k">เลขรายการบริจาคในระบบ</div>
                <div class="v"><?php echo (int)$receipt['donate_id']; ?></div>
            </div>
            <?php if ($donorAddress !== ''): ?>
            <div class="box" style="grid-column:1/-1;">
                <div class="k">ที่อยู่ในนามออกใบเสร็จ</div>
                <div class="v"><?php echo nl2br(htmlspecialchars($donorAddress)); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="amt">
            <div>
                <div class="k">จำนวนเงินรับบริจาค</div>
                <div class="sum"><?php echo number_format((float)$receipt['amount'], 2); ?> บาท</div>
            </div>
            <div class="k">ชำระสำเร็จแล้ว</div>
        </div>

        <div class="foot">
            เอกสารนี้ออกโดยระบบอัตโนมัติของ DrawDream เพื่อประกอบการลดหย่อนภาษีตามข้อมูลที่ผู้บริจาคบันทึกไว้ในโปรไฟล์ผู้บริจาค
            กรุณาตรวจสอบชื่อและเลขประจำตัวผู้เสียภาษีให้ถูกต้องก่อนใช้งาน
        </div>

        <div class="actions">
            <a href="profile.php" class="btn btn-back">กลับไปโปรไฟล์</a>
            <button type="button" class="btn btn-print" onclick="window.print()">พิมพ์ / บันทึก PDF</button>
        </div>
    </div>
</div>
</body>
</html>

