<?php
// ไฟล์นี้: payment.php
// หน้าที่: หน้าชำระเงินด้วย QR ธนาคารสำหรับบริจาคเด็ก (การ์ดสีน้ำเงิน)
// ------------------------------
session_start();
include 'db.php';
require_once __DIR__ . '/includes/child_sponsorship.php';
drawdream_child_sponsorship_ensure_columns($conn);

$child_id = (int)($_POST['child_id'] ?? $_GET['child_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? $_GET['amount'] ?? 0);
$amount = max(0, $amount);

/** @return string path รูป img/qr-code.{ext} */
function payment_page_qr_src(): string
{
    $dir = __DIR__ . '/img';
    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
        if (is_file($dir . '/qr-code' . $ext)) {
            return 'img/qr-code' . $ext;
        }
    }
    return 'img/qr-code.png';
}

if ($child_id <= 0) {
    header('Location: children_.php');
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!in_array($_SESSION['role'] ?? '', ['donor', 'admin'], true)) {
    header('Location: children_.php');
    exit();
}

$stmt = $conn->prepare(
    'SELECT c.*, COALESCE(NULLIF(c.foundation_name, \'\'), fp.foundation_name) AS display_foundation_name
     FROM foundation_children c
     LEFT JOIN foundation_profile fp ON c.foundation_id = fp.foundation_id
     WHERE c.child_id = ? AND c.deleted_at IS NULL LIMIT 1'
);
$stmt->bind_param('i', $child_id);
$stmt->execute();
$childRow = $stmt->get_result()->fetch_assoc();
if (!$childRow) {
    header('Location: children_.php');
    exit();
}
if (!drawdream_child_can_receive_donation($conn, $child_id, $childRow)) {
    header('Location: children_donate.php?id=' . $child_id . '&msg=' . rawurlencode('ไม่สามารถบริจาคให้เด็กคนนี้ได้ในขณะนี้'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_paid'])) {
    $postedChildId = (int)($_POST['child_id'] ?? 0);
    $postedAmount = (float)($_POST['amount'] ?? 0);
    $postedAmount = max(0, $postedAmount);

    if ($postedChildId > 0 && $postedAmount > 0) {
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
            $stmtIns = $conn->prepare('INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)');
            $stmtIns->bind_param('iid', $postedChildId, $donorUserId, $postedAmount);
        } else {
            $stmtIns = $conn->prepare('INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, NULL, ?)');
            $stmtIns->bind_param('id', $postedChildId, $postedAmount);
        }
        $stmtIns->execute();

        header('Location: children_donate.php?id=' . $postedChildId . '&msg=' . urlencode('บันทึกยอดบริจาคเรียบร้อยแล้ว'));
        exit();
    }
}

$qrSrc = payment_page_qr_src();
$accountDisplay = 'มูลนิธิ DrawDream';
$amountDisplay = ($amount >= 20)
    ? number_format($amount, 0) . ' บาท'
    : 'ตามจำนวนที่โอน';
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
  <link rel="stylesheet" href="css/payment_qr.css?v=4">
</head>
<body class="payment-qr-page">

<a href="children_donate.php?id=<?php echo (int)$child_id; ?>" class="payment-qr-top-back" aria-label="กลับ"><span aria-hidden="true">←</span></a>

<?php include 'navbar.php'; ?>

<main class="container py-3">
  <div class="payment-card">

    <div class="qr-wrapper">
      <img src="<?php echo htmlspecialchars($qrSrc); ?>" alt="QR Code สำหรับชำระเงิน" width="260" height="260" decoding="async">
    </div>

    <div class="payment-info">
      <div class="info-row">
        <span>ชื่อบัญชี</span>
        <span><?php echo htmlspecialchars($accountDisplay); ?></span>
      </div>
      <hr class="info-divider">
      <div class="info-row">
        <span>จำนวนเงิน</span>
        <span class="amount-text"><?php echo htmlspecialchars($amountDisplay); ?></span>
      </div>
    </div>

    <form method="post" class="mb-3">
      <input type="hidden" name="child_id" value="<?php echo (int)$child_id; ?>">
      <input type="hidden" name="amount" value="<?php echo htmlspecialchars((string)$amount); ?>">
      <button type="submit" name="confirm_paid" class="btn-attach-slip">ยืนยันการบริจาค</button>
    </form>

    <a href="children_donate.php?id=<?php echo (int)$child_id; ?>" class="btn btn-outline-light w-100 btn-back-child">กลับหน้าโปรไฟล์เด็ก</a>

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
