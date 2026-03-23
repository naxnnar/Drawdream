<?php
// donate_qr.php — แสดง QR Code และเลขบัญชี DrawDream
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>ชำระเงินบริจาค | DrawDream</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#f7ecde;">
  <div class="container py-5 text-center">
    <h2 class="mb-4">สแกนเพื่อบริจาคกับ <span style="color:#e06a4a;">DrawDream</span></h2>
    <div class="mb-4">
      <img src="../img/drawdream_qr.png" alt="QR Code" style="max-width:260px; width:100%; border-radius:16px; box-shadow:0 2px 12px #0001;">
    </div>
    <div class="mb-3">
      <h4>เลขที่บัญชี <span style="color:#f4c948; font-weight:bold;">011-1-11111-1</span></h4>
      <div>ธนาคารไทยพาณิชย์</div>
      <div>ชื่อบัญชี: มูลนิธิ DrawDream</div>
    </div>
    <a href="../homepage.php" class="btn btn-secondary mt-4">กลับหน้าหลัก</a>
  </div>
</body>
</html>
