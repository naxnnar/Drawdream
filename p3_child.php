
<?php
session_start();
include 'db.php';

// ตรวจสอบการเชื่อมต่อกับฐานข้อมูล
if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

// ตรวจสอบว่าผู้ใช้เป็นแอดมินหรือไม่
if (!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: p1_home.php");
    exit();
}

// รับค่า needlist_id จากฟอร์มที่ส่งมาจากหน้า foundation.php
$needlist_id = (int)($_POST['needlist_id'] ?? 0);

// เช็คว่าได้ค่า needlist_id หรือไม่
if ($needlist_id <= 0) {
    echo "ไม่พบข้อมูลรายการ";
    exit;
}

// ดึงรายการที่รออนุมัติสำหรับ needlist_id ที่เลือก
$sql = "
  SELECT nl.*, fp.foundation_name
  FROM foundation_needlist nl
  JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
  WHERE nl.status='pending' AND nl.needlist_id = ? 
  ORDER BY nl.urgent DESC, nl.item_id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $needlist_id); // ใช้ needlist_id ที่รับมาจากฟอร์ม
$stmt->execute();
$result = $stmt->get_result();

// ตรวจสอบว่ามีผลลัพธ์หรือไม่
if (!$result) {
    die("Query failed: " . mysqli_error($conn));  // แสดงข้อผิดพลาดจาก query
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>อนุมัติรายการสิ่งของ | Admin</title>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/style.css?v=2">
</head>
<body>

<?php include 'navbar.php'; ?>

<?php if ($result && mysqli_num_rows($result) > 0): ?>
  <!-- แสดงผลข้อมูลที่รออนุมัติ -->
  <h2>รายการสิ่งของที่รออนุมัติ (pending)</h2>

  <table class="admin-table">
    <thead>
      <tr>
        <th>รูป</th>
        <th>มูลนิธิ</th>
        <th>หมวด</th>
        <th>รายการ</th>
        <th>จำนวน</th>
        <th>ราคา/หน่วย</th>
        <th>รวม</th>
        <th>เหตุผล (กรณีปฏิเสธ)</th>
        <th>จัดการ</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = mysqli_fetch_assoc($result)): ?>
        <?php
          $total = (float)$row['qty_needed'] * (float)$row['price_estimate'];
        ?>
        <tr>
          <td>
            <?php if (!empty($row['item_image'])): ?>
              <img class="admin-thumb" src="uploads/needs/<?= htmlspecialchars($row['item_image']) ?>" alt="">
            <?php else: ?>
              <div class="admin-noimg">ไม่มีรูป</div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($row['foundation_name']) ?></td>
          <td><?= htmlspecialchars($row['category']) ?></td>
          <td><?= htmlspecialchars($row['item_name']) ?></td>
          <td><?= (int)$row['qty_needed'] ?></td>
          <td><?= number_format((float)$row['price_estimate'], 2) ?></td>
          <td><b><?= number_format($total, 2) ?></b></td>
          <td>
            <textarea class="admin-note" name="note" form="f<?= $row['item_id'] ?>" placeholder="กรุณากรอกเหตุผลเมื่อปฏิเสธ"></textarea>
          </td>
          <td>
            <form id="f<?= $row['item_id'] ?>" method="post">
              <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>">

              <div class="admin-actions">
                <button class="admin-btn approve" name="action" value="approve" onclick="return confirm('ยืนยันอนุมัติรายการนี้?');">Approve</button>
                <button class="admin-btn reject" name="action" value="reject" onclick="return confirm('ยืนยันปฏิเสธรายการนี้? (ต้องมีเหตุผล)');">Reject</button>
              </div>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
<?php else: ?>
  <p>ตอนนี้ไม่มีรายการ pending ✅</p>
<?php endif; ?>

</body>
</html>