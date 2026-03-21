<?php
// ไฟล์นี้: admin_approve_projects.php
// หน้าที่: หน้าแอดมินสำหรับอนุมัติโครงการ
session_start();
include 'db.php';

if (!isset($_SESSION['email'])) {
  header("Location: login.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: project.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = "";

// อนุมัติ/ปฏิเสธ (ใช้ POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $remark = trim($_POST['remark'] ?? '');

    $newStatus = null;
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject')  $newStatus = 'rejected';

    if ($project_id > 0 && in_array($newStatus, ['approved','rejected'], true)) {
        $stmt = $conn->prepare("UPDATE project SET project_status=? WHERE project_id=?");
        $stmt->bind_param("si", $newStatus, $project_id);
        $stmt->execute();

        // ✅ บันทึก log ลงตาราง admin
        $action_type = ($newStatus === 'approved') ? 'Approve_Project' : 'Reject_Project';
        $log_stmt = $conn->prepare("INSERT INTO admin (admin_id, action_type, target_id, remark) VALUES (?, ?, ?, ?)");
        $log_stmt->bind_param("isis", $uid, $action_type, $project_id, $remark);
        $log_stmt->execute();

        $msg = ($newStatus === 'approved') ? "อนุมัติโครงการแล้ว" : "ปฏิเสธโครงการแล้ว";
    }
}

// ดึงรายการที่รออนุมัติ
$result = mysqli_query($conn, "SELECT * FROM project WHERE project_status='pending' ORDER BY project_id DESC");
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>อนุมัติโครงการ | Admin</title>
  <link rel="stylesheet" href="css/navbar.css">
  <link rel="stylesheet" href="css/project.css">
</head>
<body class="admin-approve-projects-page">

<?php include 'navbar.php'; ?>

<div class="wrap">
  <h2>รายการโครงการที่รออนุมัติ (pending)</h2>

  <?php if (!empty($msg)): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if ($result && mysqli_num_rows($result) > 0): ?>
    <table>
      <thead>
        <tr>
          <th>รูป</th>
          <th>ชื่อโครงการ</th>
          <th>รายละเอียด</th>
          <th>เป้าหมาย</th>
          <th>วันปิดรับบริจาค</th>
          <th>เหตุผล (กรณีปฏิเสธ)</th>
          <th>จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <?php while($row = mysqli_fetch_assoc($result)): ?>
          <tr>
            <td>
              <img class="thumb" src="uploads/<?= htmlspecialchars($row['project_image']) ?>" alt="">
            </td>
            <td><?= htmlspecialchars($row['project_name']) ?></td>
            <td><?= nl2br(htmlspecialchars($row['project_desc'])) ?></td>
            <td><?= htmlspecialchars($row['goal_amount']) ?></td>
            <td><?= htmlspecialchars($row['end_date']) ?></td>
            <td>
              <form id="pf<?= (int)$row['project_id'] ?>" method="post">
                <input type="hidden" name="project_id" value="<?= (int)$row['project_id'] ?>">
                <textarea name="remark" placeholder="กรอกเหตุผลเมื่อปฏิเสธ"></textarea>
              </form>
            </td>
            <td>
              <button class="btn approve" name="action" value="approve"
                      form="pf<?= (int)$row['project_id'] ?>"
                      onclick="return confirm('ยืนยันอนุมัติโครงการนี้ไหม?');">Approve</button>
              <button class="btn reject" name="action" value="reject"
                      form="pf<?= (int)$row['project_id'] ?>"
                      onclick="return confirm('ยืนยันปฏิเสธโครงการนี้ไหม?');">Reject</button>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>ยังไม่มีโครงการที่รออนุมัติ ✅</p>
  <?php endif; ?>

</div>

</body>
</html>