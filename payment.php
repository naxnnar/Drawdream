<?php
session_start();
include 'db.php';

$amount = isset($_GET['amount']) ? htmlspecialchars($_GET['amount']) : '0';
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

$child_name = "น้องๆ";
$foundation_name = "มูลนิธิ Drawdream";

if ($child_id > 0) {
    $sql = "SELECT child_name, foundation_name FROM Children WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $child_name = $row['child_name'];
        $foundation_name = $row['foundation_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <title>ชำระเงิน - DrawDream</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/style.css">
  <style>
    body {
      background-color: #eeeeee;
    }

    /* ===== การ์ดหลักสีน้ำเงิน ===== */
    .payment-card {
      background-color: #3B4CC0;
      border-radius: 36px;
      padding: 40px 40px 44px 40px;
      max-width: 520px;
      margin: 50px auto;
      text-align: center;
      color: #fff;
    }

    /* ===== กล่อง QR ===== */
    .qr-wrapper {
      background-color: #fff;
      border-radius: 24px;
      padding: 24px;
      display: inline-block;
      margin-bottom: 32px;
    }

    .qr-wrapper img {
      width: 260px;
      height: 260px;
      display: block;
      object-fit: contain;
    }

    /* ===== ตารางข้อมูล ===== */
    .payment-info {
      width: 100%;
      margin-bottom: 30px;
    }

    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      padding: 8px 10px;
      font-size: 1.05rem;
      color: #fff;
    }

    .info-row span:first-child {
      color: #c8d0ff;
      font-weight: 400;
    }

    .info-row span:last-child {
      font-weight: 600;
      text-align: right;
    }

    .amount-text {
      font-size: 1.6rem !important;
      font-weight: 900 !important;
      color: #fff !important;
    }

    /* ===== ปุ่มแนบสลิป (สีเหลือง) ===== */
    .btn-attach-slip {
      display: block;
      background-color: #F8CE32;
      color: #222;
      font-size: 1.2rem;
      font-weight: 800;
      border: none;
      border-radius: 18px;
      padding: 18px 30px;
      width: 100%;
      margin-bottom: 28px;
      text-decoration: none;
      box-shadow: 0 6px 0 #c9a20a;
      transition: transform 0.1s, box-shadow 0.1s;
    }

    .btn-attach-slip:hover {
      background-color: #f0c200;
      color: #222;
    }

    .btn-attach-slip:active {
      transform: translateY(4px);
      box-shadow: 0 2px 0 #c9a20a;
    }

    /* ===== ข้อความขอบคุณ ===== */
    .thank-you-text {
      font-size: 0.95rem;
      color: #c8d0ff;
      line-height: 1.9;
      margin: 0;
      font-weight: 400;
    }

    /* ===== Divider เส้นบาง ===== */
    .info-divider {
      border: none;
      border-top: 1px solid rgba(255,255,255,0.15);
      margin: 4px 10px;
    }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<main class="container py-3">
  <div class="payment-card">

    <!-- QR Code -->
    <div class="qr-wrapper">
      <img src="img/qr-code.png" alt="QR Code สำหรับชำระเงิน">
    </div>

    <!-- ข้อมูลการชำระเงิน -->
    <div class="payment-info">
      <div class="info-row">
        <span>ชื่อบัญชี</span>
        <span><?php echo htmlspecialchars($foundation_name); ?></span>
      </div>
      <hr class="info-divider">
      <div class="info-row">
        <span>จำนวนเงิน</span>
        <span class="amount-text"><?php echo number_format($amount); ?> บาท</span>
      </div>
    </div>

    <!-- ปุ่มแนบสลิป -->
    <a href="upload_slip.php?child_id=<?php echo $child_id; ?>&amount=<?php echo $amount; ?>" class="btn-attach-slip">
      แนบสลิป
    </a>

    <!-- ข้อความขอบคุณ -->
    <p class="thank-you-text">
      ขอขอบคุณเป็นอย่างยิ่งสำหรับการสนับสนุนของท่าน<br>
      ความเมตตานี้ได้เติมพลังให้ความฝันของน้อง ๆ ก้าวไปอีกขั้น<br>
      และสร้างอนาคตที่งดงามยิ่งขึ้น
    </p>

  </div>
</main>

<footer style="background-color: #3f4f9a; padding: 20px 0; color: white;" class="mt-5">
  <div class="container text-center">
    <p class="mb-0">&copy; All right reserved 2025 DrawDream Foundation</p>
  </div>
</footer>

</body>
</html>