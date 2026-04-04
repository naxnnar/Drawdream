<?php
// admin_approve_children.php — แอดมินอนุมัติ/ปฏิเสธโปรไฟล์เด็ก

session_start();
include 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

// หมายเหตุ: การไม่อนุมัติเป็นการ UPDATE สถานะเท่านั้น ไม่มีการลบแถวจาก foundation_children
$needCols = [
    'reject_reason' => "ALTER TABLE foundation_children ADD COLUMN reject_reason TEXT NULL AFTER approve_profile",
    'reviewed_at' => "ALTER TABLE foundation_children ADD COLUMN reviewed_at DATETIME NULL AFTER reject_reason",
    'pending_edit_json' => "ALTER TABLE foundation_children ADD COLUMN pending_edit_json LONGTEXT NULL AFTER approve_profile",
    'first_approved_at' => "ALTER TABLE foundation_children ADD COLUMN first_approved_at DATETIME NULL AFTER reviewed_at",
];
foreach ($needCols as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM foundation_children LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}
$conn->query("
    UPDATE foundation_children
    SET first_approved_at = reviewed_at
    WHERE first_approved_at IS NULL
      AND reviewed_at IS NOT NULL
      AND COALESCE(approve_profile, '') IN ('อนุมัติ', 'กำลังดำเนินการ')
");

$child_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'approve';
$rejectReason = trim($_POST['reject_reason'] ?? '');
$returnUrl = $_POST['return'] ?? $_GET['return'] ?? 'children_.php';
if (!preg_match('/^[a-zA-Z0-9_\.\-]+(\?[a-zA-Z0-9_=&\-]*)?$/', $returnUrl)) {
    $returnUrl = 'children_.php';
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

$pendingRaw = trim((string)($rowFull['pending_edit_json'] ?? ''));

if ($action === 'approve') {
    if ($pendingRaw !== '') {
        $p = json_decode($pendingRaw, true);
        if (!is_array($p)) {
            $p = [];
        }
        $c = $rowFull;
        $cn = (string)($p['child_name'] ?? $c['child_name'] ?? '');
        $bd = (string)($p['birth_date'] ?? $c['birth_date'] ?? '');
        $ag = (int)($p['age'] ?? $c['age'] ?? 0);
        $ed = (string)($p['education'] ?? $c['education'] ?? '');
        $dr = (string)($p['dream'] ?? $c['dream'] ?? '');
        $lk = (string)($p['likes'] ?? $c['likes'] ?? '');
        $wi = (string)($p['wish'] ?? $c['wish'] ?? '');
        $wc = (string)($p['wish_cat'] ?? $c['wish_cat'] ?? '');
        $bn = (string)($p['bank_name'] ?? $c['bank_name'] ?? '');
        $cb = (string)($p['child_bank'] ?? $c['child_bank'] ?? '');
        $qr = (string)($p['qr_account_image'] ?? $c['qr_account_image'] ?? '');
        $ph = (string)($p['photo_child'] ?? $c['photo_child'] ?? '');

        $sql = "UPDATE foundation_children SET child_name=?, birth_date=?, age=?, education=?, dream=?, likes=?, wish=?, wish_cat=?, bank_name=?, child_bank=?, qr_account_image=?, photo_child=?, pending_edit_json=NULL, approve_profile='อนุมัติ', reject_reason=NULL, reviewed_at=NOW() WHERE child_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'ssisssssssssi',
            $cn,
            $bd,
            $ag,
            $ed,
            $dr,
            $lk,
            $wi,
            $wc,
            $bn,
            $cb,
            $qr,
            $ph,
            $child_id
        );
        $ok = $stmt->execute();
        $alert_msg = $ok ? 'อนุมัติการแก้ไขโปรไฟล์เรียบร้อยแล้ว' : 'เกิดข้อผิดพลาด';
    } else {
        $new_status = 'อนุมัติ';
        $reasonToSave = null;
        $sql = "UPDATE foundation_children SET approve_profile = ?, reject_reason = ?, reviewed_at = NOW(), first_approved_at = COALESCE(first_approved_at, NOW()) WHERE child_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $new_status, $reasonToSave, $child_id);
        $ok = $stmt->execute();
        $alert_msg = $ok ? 'อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'เกิดข้อผิดพลาด';
    }
} else {
    if ($pendingRaw !== '') {
        $sql = "UPDATE foundation_children SET pending_edit_json=NULL, approve_profile='อนุมัติ', reject_reason=?, reviewed_at=NOW() WHERE child_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $rejectReason, $child_id);
        $ok = $stmt->execute();
        $alert_msg = $ok ? 'ไม่อนุมัติการแก้ไข — ข้อมูลที่แสดงต่อสาธารณะยังเป็นชุดเดิม' : 'เกิดข้อผิดพลาด';
    } else {
        $new_status = 'ไม่อนุมัติ';
        $sql = "UPDATE foundation_children SET approve_profile = ?, reject_reason = ?, reviewed_at = NOW() WHERE child_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $new_status, $rejectReason, $child_id);
        $ok = $stmt->execute();
        $alert_msg = $ok ? 'ไม่อนุมัติโปรไฟล์เรียบร้อยแล้ว' : 'เกิดข้อผิดพลาด';
    }
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
        if ($pendingRaw !== '') {
            drawdream_send_notification(
                $conn,
                $fu,
                'child_edit_approved',
                'อนุมัติการแก้ไขโปรไฟล์เด็ก',
                'แอดมินอนุมัติการแก้ไขโปรไฟล์: ' . $cname,
                $childPublicLink,
                'fdn_child:' . $child_id
            );
            drawdream_log_admin_action($conn, $adminUid, 'Approve_Child', $child_id, '', $fu > 0 ? $fu : null, 'child_edit_approved');
        } else {
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
        }
    } else {
        $rejPart = $rejectReason !== '' ? $rejectReason : 'ไม่ผ่านการพิจารณา';
        if ($pendingRaw !== '') {
            drawdream_send_notification(
                $conn,
                $fu,
                'child_edit_rejected',
                'ไม่อนุมัติการแก้ไขโปรไฟล์เด็ก',
                'โปรไฟล์ ' . $cname . ': ' . $rejPart,
                $childPublicLink,
                'fdn_child:' . $child_id
            );
            drawdream_log_admin_action($conn, $adminUid, 'Reject_Child', $child_id, $rejectReason, $fu > 0 ? $fu : null, 'child_edit_rejected');
        } else {
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
    }
    $msgJs = json_encode($alert_msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $urlJs = json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<script>alert({$msgJs}); window.location={$urlJs};</script>";
} else {
    echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต'); history.back();</script>";
}
?>
