<?php
// payment/scan_qr.php
// แสดง QR Code สำหรับสแกนจ่าย + ข้อมูลใบเสร็จรับเงิน
session_start();
include '../db.php';
include 'config.php';

$charge_id = $_GET['charge_id'] ?? ($_SESSION['pending_charge_id'] ?? '');
$amount = $_SESSION['pending_amount'] ?? 0;
$project_name = $_SESSION['pending_project'] ?? '';
$project_id = $_SESSION['pending_project_id'] ?? 0;

if (!$charge_id || !$amount || !$project_name) {
    header('Location: ../project.php');
    exit();
}

// mock: ดึง QR image จาก session (จริงควร query จาก DB หรือ Omise)

$qr_image = '';
if (isset($_SESSION['pending_charge_id']) && $_SESSION['pending_charge_id'] === $charge_id) {
    $qr_image = $_SESSION['qr_image'] ?? '';
}
// fallback: Omise test QR PNG หากไม่มี qr_image จริง
if (!$qr_image) {
    $qr_image = 'https://cdn.omise.co/assets/dashboard/img/test-qr-code.png';
}

// mock: หมายเลขรายการ
$receipt_no = strtoupper(substr($charge_id, -10));

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR Code เพื่อบริจาค</title>
    <link rel="stylesheet" href="../css/payment.css">
    <style>
        body { background: #f7ecde; }
        .qr-main { max-width: 520px; margin: 36px auto; background: #fff; border-radius: 18px; box-shadow: 0 2px 16px 0 rgba(0,0,0,0.07); padding: 32px 28px 28px 28px; }
        .qr-title { font-size: 2em; font-weight: 700; text-align: center; margin-bottom: 18px; }
        .qr-amount-bar { background: #fff7ea; border-radius: 10px; padding: 16px 0 12px 0; font-size: 1.5em; color: #ff8800; font-weight: 700; text-align: center; margin-bottom: 18px; }
        .qr-project { display: flex; align-items: center; gap: 16px; margin-bottom: 12px; }
        .qr-project-img { width: 64px; height: 64px; object-fit: cover; border-radius: 10px; background: #eee; }
        .qr-project-info { font-size: 1.1em; }
        .qr-section { text-align: center; margin: 24px 0 18px 0; }
        .qr-section img { max-width: 260px; width: 100%; background: #fff; padding: 16px; border-radius: 16px; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.08); }
        .qr-receipt { background: #f7f7f7; border-radius: 12px; padding: 18px 18px 10px 18px; margin-top: 18px; font-size: 1.08em; }
        .qr-receipt-row { margin-bottom: 8px; }
        .qr-download-btn { margin: 18px auto 0 auto; display: block; background: #ff8800; color: #fff; font-size: 1.15em; font-weight: 700; border: none; border-radius: 10px; padding: 12px 0; width: 100%; cursor: pointer; transition: background 0.15s; }
        .qr-download-btn:hover { background: #e67e22; }
        .qr-note { color: #888; font-size: 0.98em; margin-top: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="qr-main">
        <div class="qr-title">Scan QR Code เพื่อบริจาค</div>
        <div class="qr-amount-bar" style="color:#222;">ยอดบริจาค <?= number_format($amount) ?> บาท</div>
        <div class="qr-project">
            <span class="qr-project-info" style="font-size:1.1em;font-weight:600;display:inline-block;">บริจาคให้กับโครงการ <?= htmlspecialchars($project_name) ?></span>
        </div>
        <div class="qr-section">
            <img src="<?= htmlspecialchars($qr_image) ?>" alt="PromptPay QR">
        </div>
        <div class="qr-receipt">
            <div class="qr-receipt-row"><b>จำนวนเงิน</b> <?= number_format($amount,2) ?> บาท</div>
            <div class="qr-receipt-row"><b>เลขที่รายการบริจาค</b> <?= htmlspecialchars($receipt_no) ?></div>
            <div class="qr-receipt-row"><b>ชื่อโครงการ</b> <?= htmlspecialchars($project_name) ?></div>
            <div class="qr-receipt-row"><b>วันที่</b> <?= date('d/m/Y H:i') ?></div>
        </div>
                <a class="qr-download-btn" 
                   href="check_project_payment.php?charge_id=<?= urlencode($charge_id) ?>&project_id=<?= urlencode($project_id) ?>"
                   style="background:#F1CF54;color:#222;display:flex;align-items:center;justify-content:center;gap:10px;font-size:1.18em;text-decoration:none;">
                    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" style="vertical-align:middle;"><circle cx="11" cy="11" r="11" fill="#597D57"/><path d="M6.5 11.5L10 15L16 8.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>ชำระเงินแล้ว</span>
                </a>
                <div class="qr-note">* ในกรณีรับเงินเพื่อขอลดหย่อนภาษีแบบ e-Donation จะเป็นชื่อ-นามสกุล เจ้าของบัญชีธนาคารที่ใช้ชำระเงิน</div>
                <div id="paid-modal" style="display:none;position:fixed;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.25);z-index:9999;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:16px;max-width:340px;margin:auto;padding:32px 24px;text-align:center;box-shadow:0 2px 16px 0 rgba(0,0,0,0.13);">
                                <div style="font-size:2.2em;margin-bottom:12px;">
                                    <svg width="38" height="38" viewBox="0 0 38 38" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                                        <circle cx="19" cy="19" r="19" fill="#597D57"/>
                                        <path d="M11 20.5L17 26L27 14" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div style="font-size:1.25em;font-weight:600;margin-bottom:10px;">ขอบคุณสำหรับการสนับสนุนโครงการ</div>
                                <div style="color:#666;font-size:1em;">ระบบจะตรวจสอบและอัปเดตสถานะการบริจาคของคุณโดยอัตโนมัติ</div>
                                <button onclick="closePaidModal()" style="margin-top:22px;background:#F1CF54;color:#222;font-size:1.1em;font-weight:700;border:none;border-radius:8px;padding:10px 28px;cursor:pointer;">ปิด</button>
                        </div>
                </div>
    </div>
    <script>
    function showPaidModal() {
        document.getElementById('paid-modal').style.display = 'flex';
    }
    function closePaidModal() {
        window.location.href = '../project.php';
    }
    </script>
</body>
</html>
