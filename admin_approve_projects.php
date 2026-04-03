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
    if ($action === 'approve') {
        $newStatus = 'approved';
    }
    if ($action === 'reject') {
        $newStatus = 'rejected';
    }

    if ($project_id > 0 && in_array($newStatus, ['approved', 'rejected'], true)) {
        require_once __DIR__ . '/includes/drawdream_project_status.php';
        $pend = drawdream_sql_project_is_pending('project_status');
        $stmt = $conn->prepare("UPDATE foundation_project SET project_status=? WHERE project_id=? AND {$pend} AND deleted_at IS NULL");
        $stmt->bind_param("si", $newStatus, $project_id);
        $stmt->execute();
        if ($stmt->affected_rows >= 1) {
            require_once __DIR__ . '/includes/notification_audit.php';
            drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_project:' . $project_id);
            $stP = $conn->prepare("SELECT foundation_name, project_name FROM foundation_project WHERE project_id = ? LIMIT 1");
            $stP->bind_param("i", $project_id);
            $stP->execute();
            $pr = $stP->get_result()->fetch_assoc();
            $fname = trim((string)($pr['foundation_name'] ?? ''));
            $pname = (string)($pr['project_name'] ?? '');
            $fu = drawdream_foundation_user_id_by_name($conn, $fname);
            $payLink = 'payment/payment_project.php?project_id=' . $project_id;
            if ($newStatus === 'approved') {
                drawdream_send_notification(
                    $conn,
                    $fu,
                    'project_approved',
                    'โครงการได้รับการอนุมัติ',
                    'โครงการ "' . $pname . '" ผ่านการตรวจสอบแล้ว สามารถแชร์ลิงก์ให้ผู้บริจาคได้',
                    $payLink
                );
                drawdream_log_admin_action($conn, $uid, 'Approve_Project', $project_id, $remark, $fu > 0 ? $fu : null, 'project_approved');
            } else {
                $rejBody = 'โครงการ "' . $pname . '" ไม่ผ่านการอนุมัติ';
                if ($remark !== '') {
                    $rejBody .= ' เหตุผล: ' . $remark;
                }
                drawdream_send_notification(
                    $conn,
                    $fu,
                    'project_rejected',
                    'โครงการไม่ผ่านการอนุมัติ',
                    $rejBody,
                    'project.php?view=foundation',
                    'fdn_project:' . $project_id
                );
                drawdream_log_admin_action($conn, $uid, 'Reject_Project', $project_id, $remark, $fu > 0 ? $fu : null, 'project_rejected');
            }
            $msg = ($newStatus === 'approved') ? 'อนุมัติโครงการแล้ว' : 'ปฏิเสธโครงการแล้ว';
        } else {
            $msg = 'ไม่พบโครงการสถานะรอดำเนินการ หรืออัปเดตไม่สำเร็จ';
        }
    }
}

// ดึงรายการที่รออนุมัติ
require_once __DIR__ . '/includes/drawdream_project_status.php';
$pendList = drawdream_sql_project_is_pending('project_status');
$result = mysqli_query($conn, "SELECT * FROM foundation_project WHERE {$pendList} AND deleted_at IS NULL ORDER BY project_id DESC");
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