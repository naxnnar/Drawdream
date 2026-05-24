<?php
// payment/project_service_charge_qr.php — QR ชำระค่าบริการระบบโครงการ
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/omise_helpers.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    header('Location: ../project.php?view=foundation');
    exit();
}

$projectId = (int)($_GET['project_id'] ?? ($_SESSION['pending_psc_project_id'] ?? 0));
$charge_id = trim((string)($_GET['charge_id'] ?? ($_SESSION['pending_psc_charge_id'] ?? '')));
$amount = (int)($_SESSION['pending_psc_amount'] ?? 0);

if ($projectId <= 0 || $charge_id === '' || $amount < 20) {
    header('Location: ../project.php?view=foundation');
    exit();
}
if ((int)($_SESSION['pending_psc_project_id'] ?? 0) !== $projectId
    || (string)($_SESSION['pending_psc_charge_id'] ?? '') !== $charge_id) {
    header('Location: ../foundation_project_view.php?id=' . $projectId);
    exit();
}

$qr_image = trim((string)($_SESSION['pending_psc_qr_image'] ?? ''));
$is_mock_charge = (strpos($charge_id, 'chrg_mock_') === 0);
if ($qr_image === '' && !$is_mock_charge) {
    $fetched = drawdream_omise_fetch_charge($charge_id);
    if ($fetched) {
        $qr_image = drawdream_omise_promptpay_qr_uri_from_charge($fetched);
        if ($qr_image !== '') {
            $_SESSION['pending_psc_qr_image'] = $qr_image;
        }
    }
}

/** @return string */
function project_sc_qr_static_image(): string
{
    $imgDir = dirname(__DIR__) . '/img';
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
        if (is_file($imgDir . '/qr-code' . $ext)) {
            return '../img/qr-code' . $ext;
        }
    }

    return '../img/qr-code.png';
}

$qrSrc = $qr_image !== '' ? $qr_image : project_sc_qr_static_image();
$amountLabel = number_format($amount, 2) . ' บาท';
$backHref = '../foundation_project_view.php?id=' . $projectId;
$checkUrl = 'check_project_service_charge_payment.php?format=json&project_id=' . $projectId
    . '&charge_id=' . rawurlencode($charge_id);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<?php require_once __DIR__ . '/../includes/favicon_meta.php'; ?>
  <meta charset="utf-8">
  <title>ชำระค่าบริการระบบโครงการ | DrawDream</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/payment_qr.css?v=4">
</head>
<body class="payment-qr-page">

  <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="payment-qr-top-back" aria-label="กลับรายละเอียดโครงการ"><span aria-hidden="true">←</span></a>

  <main class="container py-3">
    <div class="payment-card">

      <p style="margin:0 0 20px;font-size:1.05rem;font-weight:600;">ชำระค่าบริการระบบโครงการ (5%)</p>

      <div class="qr-wrapper">
        <img src="<?= htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code สำหรับชำระค่าบริการ" width="260" height="260" decoding="async">
      </div>

      <div class="payment-info">
        <div class="info-row">
          <span>ชื่อบัญชี</span>
          <span>มูลนิธิ DrawDream</span>
        </div>
        <hr class="info-divider">
        <div class="info-row">
          <span>จำนวนเงิน</span>
          <span class="amount-text"><?= htmlspecialchars($amountLabel) ?></span>
        </div>
        <hr class="info-divider">
        <div class="info-row">
          <span>รายการ</span>
          <span>ค่าบริการจัดการระบบโครงการ</span>
        </div>
      </div>

      <p class="thank-you-text" id="sc-status-msg">
        สแกน QR เพื่อชำระค่าบริการตามยอดด้านบน<br>
        ระบบจะอัปเดตสถานะอัตโนมัติเมื่อชำระสำเร็จ
      </p>
    </div>
  </main>

  <script>
  (function () {
    var checkUrl = <?= json_encode($checkUrl, JSON_UNESCAPED_UNICODE) ?>;
    var returnUrl = <?= json_encode($backHref . '&sc_paid=1', JSON_UNESCAPED_UNICODE) ?>;
    var msg = document.getElementById('sc-status-msg');
    function poll() {
      fetch(checkUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.ok) {
            if (msg) { msg.textContent = 'ชำระค่าบริการสำเร็จ กำลังกลับไปหน้าโครงการ…'; }
            window.location.href = returnUrl;
            return;
          }
          if (data && data.error === 'failed') {
            if (msg) { msg.textContent = 'การชำระไม่สำเร็จ กรุณาลองใหม่จากหน้ารายละเอียดโครงการ'; }
            return;
          }
          setTimeout(poll, 5000);
        })
        .catch(function () { setTimeout(poll, 8000); });
    }
    setTimeout(poll, 4000);
  })();
  </script>
</body>
</html>
