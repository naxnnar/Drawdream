<?php
// admin_approve_needlist.php — แอดมินอนุมัติรายการสิ่งของมูลนิธิ

session_start();
include 'db.php';

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: welcome.php");
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
        require_once __DIR__ . '/includes/needlist_donate_window.php';

        $donateEndSql = null;
        if ($newStatus === 'approved') {
            $sn = $conn->prepare("SELECT note FROM foundation_needlist WHERE item_id = ? AND approve_item = 'pending' LIMIT 1");
            if (!$sn) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $sn->bind_param("i", $item_id);
                $sn->execute();
                $nrow = $sn->get_result()->fetch_assoc();
                $periodLabel = drawdream_needlist_period_label_from_note((string)($nrow['note'] ?? ''));
                $donateEndSql = drawdream_needlist_compute_donate_window_end($periodLabel, new DateTimeImmutable('now'));
            }
        }

        if ($error !== '') {
            // ข้าม execute
        } elseif ($newStatus === 'approved' && $donateEndSql === null) {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    reviewed_by_user_id=?,
                    reviewed_at=NOW(),
                    review_note=?,
                    donate_window_end_at=NULL
                WHERE item_id=? AND approve_item='pending'
            ");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("sisi", $newStatus, $uid, $note, $item_id);
            }
        } elseif ($newStatus === 'approved' && $donateEndSql !== null) {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    reviewed_by_user_id=?,
                    reviewed_at=NOW(),
                    review_note=?,
                    donate_window_end_at=?
                WHERE item_id=? AND approve_item='pending'
            ");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("sissi", $newStatus, $uid, $note, $donateEndSql, $item_id);
            }
        } else {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    reviewed_by_user_id=?,
                    reviewed_at=NOW(),
                    review_note=?,
                    donate_window_end_at=NULL
                WHERE item_id=? AND approve_item='pending'
            ");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("sisi", $newStatus, $uid, $note, $item_id);
            }
        }

        if ($error === '' && isset($stmt) && $stmt instanceof mysqli_stmt) {
            if ($stmt->execute()) {
                require_once __DIR__ . '/includes/notification_audit.php';
                drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_need:' . $item_id);
                $action_type = ($newStatus === 'approved') ? 'Approve_Need' : 'Reject_Need';
                $stFu = $conn->prepare(
                    "SELECT fp.user_id, nl.item_name FROM foundation_needlist nl
                     INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
                     WHERE nl.item_id = ? LIMIT 1"
                );
                $stFu->bind_param('i', $item_id);
                $stFu->execute();
                $nr = $stFu->get_result()->fetch_assoc();
                $fu = (int)($nr['user_id'] ?? 0);
                $iname = (string)($nr['item_name'] ?? '');
                $notifKind = $newStatus === 'approved' ? 'need_approved' : 'need_rejected';
                if ($newStatus === 'approved') {
                    drawdream_send_notification(
                        $conn,
                        $fu,
                        'need_approved',
                        'รายการสิ่งของได้รับการอนุมัติ',
                        'รายการ "' . $iname . '" ผ่านการตรวจสอบแล้ว',
                        'foundation.php',
                        'fdn_need:' . $item_id
                    );
                } else {
                    $nb = 'รายการ "' . $iname . '" ไม่ผ่านการอนุมัติ';
                    if ($note !== '') {
                        $nb .= ' เหตุผล: ' . $note;
                    }
                    drawdream_send_notification(
                        $conn,
                        $fu,
                        'need_rejected',
                        'รายการสิ่งของไม่ผ่านการอนุมัติ',
                        $nb,
                        'foundation.php',
                        'fdn_need:' . $item_id
                    );
                }
                drawdream_log_admin_action($conn, $uid, $action_type, $item_id, $note, $fu > 0 ? $fu : null, $notifKind);
                $msg = ($newStatus === 'approved') ? "อนุมัติรายการแล้ว" : "ปฏิเสธรายการแล้ว";
                header("Location: admin_approve_needlist.php?msg=" . urlencode($msg));
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
  WHERE nl.approve_item='pending'
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
    <link rel="stylesheet" href="css/admin.css">
</head>
<body class="admin-approve-needlist-page">

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
                <?php
                    $itemImages = foundation_needlist_item_filenames_from_row($row);
                    $mainItemImage = $itemImages[0] ?? '';
                    $fdnNeedAdm = trim((string)($row['need_foundation_image'] ?? ''));
                ?>
                <tr>
                    <td class="admin-need-img-cell">
                        <?php if ($mainItemImage !== ''): ?>
                            <img class="admin-thumb" src="uploads/needs/<?= htmlspecialchars($mainItemImage) ?>" alt="">
                        <?php else: ?>
                            <div class="admin-noimg">ไม่มีรูปสิ่งของ</div>
                        <?php endif; ?>
                        <?php if ($fdnNeedAdm !== ''): ?>
                            <img class="admin-thumb admin-thumb-fdn" src="uploads/needs/<?= htmlspecialchars($fdnNeedAdm) ?>" alt="มูลนิธิ" title="รูปมูลนิธิ">
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