<?php
// ------------------------------
// Backend: เตรียมข้อมูลและบันทึกการบริจาคมูลนิธิ
// ------------------------------
session_start();
include 'db.php';

$fid = (int)($_GET['fid'] ?? 1);

/* ต้องล็อกอินก่อนบริจาค */
if (!isset($_SESSION['email'])) {
  header("Location: login.php");
  exit();
}

$role = $_SESSION['role'] ?? 'donor';
if (!in_array($role, ['donor','admin'], true)) {
  header("Location: foundation.php?fid=".$fid);
  exit();
}

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $amount = (float)($_POST['amount'] ?? 0);
  if ($amount <= 0) {
    $error = "กรุณากรอกจำนวนเงินให้ถูกต้อง";
  } else {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("INSERT INTO foundation_donations (foundation_id, donor_user_id, amount, status)
                            VALUES (?,?,?,'verified')");
    $stmt->bind_param("iid", $fid, $uid, $amount);
    $stmt->execute();
    header("Location: foundation.php?fid=".$fid);
    exit();
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>บริจาค | DrawDream</title>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container" style="max-width:520px;">
  <h2>บริจาคเงินให้มูลนิธิ</h2>
  <?php if ($error): ?><p style="color:red;"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <form method="post">
    <label>จำนวนเงิน (บาท)</label>
    <input type="number" name="amount" min="1" required>
    <button type="submit" class="btn-main">ยืนยันบริจาค</button>
  </form>

  <a href="foundation.php?fid=<?= $fid ?>">← กลับ</a>
</div>

</body>
</html>