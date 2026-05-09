<?php
// admin_approve_children.php — แอดมินอนุมัติ/ปฏิเสธโปรไฟล์เด็ก

// สรุปสั้น: ไฟล์นี้จัดการหน้าแอดมินส่วน approve children

session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

// หมายเหตุ: การไม่อนุมัติเป็นการ UPDATE สถานะเท่านั้น ไม่มีการลบแถวจาก foundation_children
// ไม่มีระบบแก้ไขรออนุมัติแล้ว จึงไม่ใช้ pending_edit_json อีกต่อไป
// เหตุผลไม่อนุมัติไม่เก็บใน foundation_children — ส่งในแจ้งเตือน + audit แอดมิน แล้วดึงมาแสดงใน UI
$cDropReject = $conn->query("SHOW COLUMNS FROM foundation_children LIKE 'reject_reason'");
if ($cDropReject && $cDropReject->num_rows > 0) {
    @$conn->query('ALTER TABLE foundation_children DROP COLUMN reject_reason');
}

$needCols = [
    'approve_at' => "ALTER TABLE foundation_children ADD COLUMN approve_at DATETIME NULL",
];
foreach ($needCols as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}

$child_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'approve';
$rejectReason = trim($_POST['reject_reason'] ?? '');
$returnUrl = $_POST['return'] ?? $_GET['return'] ?? 'admin_notifications.php#admin-pending-children';
if (!is_string($returnUrl) || $returnUrl === '') {
    $returnUrl = 'admin_notifications.php#admin-pending-children';
} elseif (preg_match('/[<>"\']/', $returnUrl)) {
    $returnUrl = 'admin_notifications.php#admin-pending-children';
} elseif (
    strpos($returnUrl, 'admin_notifications.php') !== 0
    && strpos($returnUrl, 'children_donate.php') !== 0
    && strpos($returnUrl, 'children_.php') !== 0
) {
    $returnUrl = 'admin_notifications.php#admin-pending-children';
}

if ($child_id <= 0) {
    echo "<script>alert('ไม่พบรหัสโปรไฟล์เด็ก'); history.back();</script>";
    exit();
}

$adminUid = (int)($_SESSION['user_id'] ?? 0);

if ($action === 'reject' && $rejectReason === '') {
    echo "<script>alert('กรุณากรอกเหตุผลเมื่อไม่อนุมัติ'); history.back();</script>";
    exit();
}

$stFetch = $conn->prepare('SELECT * FROM foundation_children WHERE child_id = ? LIMIT 1');
$stFetch->bind_param('i', $child_id);
$stFetch->execute();
$rowFull = $stFetch->get_result()->fetch_assoc();
if (!$rowFull) {
    echo "<script>alert('ไม่พบข้อมูลเด็ก'); history.back();</script>";
    exit();
}

if (!empty($rowFull['deleted_at'])) {
    echo "<script>alert('โปรไฟล์นี้ถูกลบโดยมูลนิธิแล้ว (ข้อมูลยังอยู่ในระบบ)'); history.back();</script>";
    exit();
}

if ($action === 'approve') {
    $new_status = 'อนุมัติ';
    $sql = "UPDATE foundation_children SET approve_profile = ?, approve_at = NOW() WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $new_status, $child_id);
    $ok = $stmt->execute();
    $alert_msg = $ok ? 'อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'เกิดข้อผิดพลาด';
} else {
    $new_status = 'ไม่อนุมัติ';
    $sql = "UPDATE foundation_children SET approve_profile = ?, approve_at = NOW() WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $new_status, $child_id);
    $ok = $stmt->execute();
    $alert_msg = $ok ? 'ไม่อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'เกิดข้อผิดพลาด';
}

if ($ok) {
    require_once __DIR__ . '/includes/notification_audit.php';
    drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_child:' . $child_id);
    $stN = $conn->prepare(
        'SELECT fp.user_id, c.child_name FROM foundation_children c
         INNER JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
         WHERE c.child_id = ? LIMIT 1'
    );
    $stN->bind_param('i', $child_id);
    $stN->execute();
    $fr = $stN->get_result()->fetch_assoc();
    $fu = (int)($fr['user_id'] ?? 0);
    $cname = (string)($fr['child_name'] ?? '');
    $childPublicLink = 'children_donate.php?id=' . $child_id;
    if ($action === 'approve') {
        drawdream_send_notification(
            $conn,
            $fu,
            'child_approved',
            'อนุมัติโปรไฟล์เด็ก',
            'แอดมินอนุมัติโปรไฟล์เด็ก: ' . $cname,
            $childPublicLink,
            'fdn_child:' . $child_id
        );
        drawdream_log_admin_action($conn, $adminUid, 'Approve_Child', $child_id, '', $fu > 0 ? $fu : null, 'child_approved');
    } else {
        $rejPart = $rejectReason !== '' ? $rejectReason : 'ไม่ผ่านการพิจารณา';
        drawdream_send_notification(
            $conn,
            $fu,
            'child_rejected',
            'ไม่อนุมัติโปรไฟล์เด็ก',
            'โปรไฟล์ ' . $cname . ': ' . $rejPart,
            $childPublicLink,
            'fdn_child:' . $child_id
        );
        drawdream_log_admin_action($conn, $adminUid, 'Reject_Child', $child_id, $rejectReason, $fu > 0 ? $fu : null, 'child_rejected');
    }
    $msgJs = json_encode($alert_msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $urlJs = json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<script>alert({$msgJs}); window.location={$urlJs};</script>";
} else {
    echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); history.back();</script>";
}
?>
