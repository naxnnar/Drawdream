<?php
session_start();
include 'db.php';

if (!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: p1_home.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);

$msg = "";
$error = "";

// อนุมัติ/ปฏิเสธ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $note    = trim($_POST['note'] ?? '');

    $newStatus = null;
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject')  $newStatus = 'rejected';

    if ($item_id <= 0 || !in_array($newStatus, ['approved','rejected'], true)) {
        $error = "ข้อมูลไม่ถูกต้อง";
    } elseif ($newStatus === 'rejected' && $note === '') {
        $error = "กรุณากรอกเหตุผลเมื่อปฏิเสธ";
    } else {
        $stmt = $conn->prepare("
            UPDATE foundation_needlist
            SET status=?,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_note=?
            WHERE item_id=? AND status='pending'
        ");
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param("sisi", $newStatus, $uid, $note, $item_id);
            if ($stmt->execute()) {
                
                // ✅ บันทึก log ลงตาราง admin
                $action_type = ($newStatus === 'approved') ? 'Approve_Need' : 'Reject_Need';
                $log_stmt = $conn->prepare("INSERT INTO admin (admin_id, action_type, target_id, remark) VALUES (?, ?, ?, ?)");
                $log_stmt->bind_param("isis", $uid, $action_type, $item_id, $note);
                $log_stmt->execute();
                
                $msg = ($newStatus === 'approved') ? "อนุมัติรายการแล้ว" : "ปฏิเสธรายการแล้ว";
                header("Location: admin_needlist.php?msg=" . urlencode($msg));
                exit();
            } else {
                $error = "อัปเดตไม่สำเร็จ: " . $stmt->error;
            }
        }
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ดึงรายการ pending + ชื่อมูลนิธิ
$sql = "
  SELECT nl.*, fp.foundation_name
  FROM foundation_needlist nl
  JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
  WHERE nl.status='pending'
  ORDER BY nl.urgent DESC, nl.item_id DESC
";
$result = mysqli_query($conn, $sql);
if (!$result) die("Query failed: " . mysqli_error($conn));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <link rel="stylesheet" href="css/navbar.css">
    <meta charset="UTF-8">
    <title>อนุมัติรายการสิ่งของ | Admin</title>
    <link rel="stylesheet" href="css/style.css?v=2">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="wrap">
    <h2>รายการสิ่งของที่รออนุมัติ (pending)</h2>

    <?php if ($error): ?>
        <div class="msg err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="msg ok"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($result && mysqli_num_rows($result) > 0): ?>
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
                <?php $total = (float)$row['qty_needed'] * (float)$row['price_estimate']; ?>
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
                    <td>
                        <?= htmlspecialchars($row['item_name']) ?>
                        <?php if ((int)$row['urgent'] === 1): ?>
                            <div class="urgent-tag">ต้องการด่วน</div>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$row['qty_needed'] ?></td>
                    <td><?= number_format((float)$row['price_estimate'], 2) ?></td>
                    <td><b><?= number_format($total, 2) ?></b></td>
                    <td>
                        <form id="f<?= (int)$row['item_id'] ?>" method="post">
                            <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>">
                            <textarea class="admin-note" name="note" placeholder="กรอกเหตุผลเมื่อปฏิเสธ"></textarea>
                        </form>
                    </td>
                    <td>
                        <div class="admin-actions">
                            <button class="admin-btn approve" name="action" value="approve"
                                    form="f<?= (int)$row['item_id'] ?>"
                                    onclick="return confirm('ยืนยันอนุมัติรายการนี้?');">Approve</button>

                            <button class="admin-btn reject" name="action" value="reject"
                                    form="f<?= (int)$row['item_id'] ?>"
                                    onclick="return confirm('ยืนยันปฏิเสธรายการนี้? (ต้องมีเหตุผล)');">Reject</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>ตอนนี้ไม่มีรายการ pending ✅</p>
    <?php endif; ?>
</div>

</body>
</html>