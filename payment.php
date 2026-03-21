<?php
// ไฟล์นี้: payment.php
// หน้าที่: หน้าชำระเงินด้วย QR สำหรับบริจาคเด็ก
// ------------------------------
// Backend: เตรียมข้อมูลหน้า QR สำหรับบริจาคเด็ก
// ------------------------------
session_start();
include 'db.php';

$amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
$amount = max(0, $amount);
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_paid'])) {
  $postedChildId = (int)($_POST['child_id'] ?? 0);
  $postedAmount = (float)($_POST['amount'] ?? 0);
  $postedAmount = max(0, $postedAmount);

  if ($postedChildId > 0 && $postedAmount > 0) {
    // Ensure table exists before insert
    $conn->query("CREATE TABLE IF NOT EXISTS child_donations (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      child_id INT NOT NULL,
      donor_user_id INT NULL,
      amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $donorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($donorUserId > 0) {
      $stmtIns = $conn->prepare("INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)");
      $stmtIns->bind_param("iid", $postedChildId, $donorUserId, $postedAmount);
    } else {
      $stmtIns = $conn->prepare("INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, NULL, ?)");
      $stmtIns->bind_param("id", $postedChildId, $postedAmount);
    }
    $stmtIns->execute();

    header('Location: children_donate.php?id=' . $postedChildId . '&msg=' . urlencode('บันทึกยอดบริจาคเรียบร้อยแล้ว'));
    exit();
  }
}

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
  <link rel="stylesheet" href="css/payment_qr.css">
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- ------------------------------
  Frontend: แสดง QR และสรุปยอดชำระ
  ------------------------------ -->
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

    <form method="post" class="mb-3">
      <input type="hidden" name="child_id" value="<?php echo (int)$child_id; ?>">
      <input type="hidden" name="amount" value="<?php echo htmlspecialchars((string)$amount); ?>">
      <button type="submit" name="confirm_paid" class="btn-attach-slip">ฉันชำระเงินแล้ว</button>
    </form>

    <a href="children_donate.php?id=<?php echo (int)$child_id; ?>" class="btn btn-outline-light w-100 btn-back-child">กลับหน้าโปรไฟล์เด็ก</a>

    <!-- ข้อความขอบคุณ -->
    <p class="thank-you-text">
      ขอขอบคุณเป็นอย่างยิ่งสำหรับการสนับสนุนของท่าน<br>
      ความเมตตานี้ได้เติมพลังให้ความฝันของน้อง ๆ ก้าวไปอีกขั้น<br>
      และสร้างอนาคตที่งดงามยิ่งขึ้น
    </p>

  </div>
</main>

<footer class="payment-footer mt-5">
  <div class="container text-center">
    <p class="mb-0">&copy; All right reserved 2025 DrawDream Foundation</p>
  </div>
</footer>

</body>
</html>