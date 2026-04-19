<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/omise_api_client.php';
require_once dirname(__DIR__) . '/includes/omise_user_messages.php';
require_once dirname(__DIR__) . '/includes/child_omise_subscription.php';
require_once dirname(__DIR__) . '/includes/child_sponsorship.php';
require_once dirname(__DIR__) . '/includes/notification_audit.php';

function child_subscription_cancel_redirect(string $msg, bool $ok, int $childId): void
{
    $q = http_build_query([
        'id' => max(0, $childId),
        'sub_ok' => $ok ? '1' : '0',
        'sub_msg' => $msg,
    ]);
    header('Location: ../children_donate.php?' . $q);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'donor' || empty($_SESSION['user_id'])) {
    child_subscription_cancel_redirect('กรุณาเข้าสู่ระบบผู้บริจาคก่อน', false, 0);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    child_subscription_cancel_redirect('วิธีเรียกใช้งานไม่ถูกต้อง', false, 0);
}

$donorUid = (int)($_SESSION['user_id'] ?? 0);
$childId = (int)($_POST['child_id'] ?? 0);
if ($donorUid <= 0 || $childId <= 0) {
    child_subscription_cancel_redirect('ไม่พบข้อมูลรายการที่ต้องการยกเลิก', false, $childId);
}

drawdream_child_omise_subscription_ensure_schema($conn);

$st = $conn->prepare(
    "SELECT donate_id, recurring_schedule_id, recurring_plan_code
     FROM donation
     WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription' AND recurring_status = 'active'
     ORDER BY donate_id DESC
     LIMIT 1"
);
if (!$st) {
    child_subscription_cancel_redirect('ระบบไม่พร้อมใช้งาน กรุณาลองใหม่', false, $childId);
}
$st->bind_param('ii', $childId, $donorUid);
$st->execute();
$sub = $st->get_result()->fetch_assoc();
if (!$sub) {
    child_subscription_cancel_redirect('ไม่พบการอุปการะที่ยังใช้งานอยู่', false, $childId);
}

$scheduleId = trim((string)($sub['recurring_schedule_id'] ?? ''));
$planCode = strtolower(trim((string)($sub['recurring_plan_code'] ?? '')));

// omise_schedule: เรียก revoke schedule เพื่อตัดรอบอนาคต
if ($scheduleId !== '' && str_starts_with($scheduleId, 'schd_')) {
    $res = drawdream_omise_post_form('/schedules/' . rawurlencode($scheduleId) . '/revoke', []);
    if (($res['object'] ?? '') === 'error' && !drawdream_omise_is_not_found_error($res)) {
        $m = drawdream_omise_error_message_for_user($res, 'ยกเลิกการอุปการะไม่สำเร็จ');
        child_subscription_cancel_redirect($m, false, $childId);
    }
}

$cancelled = 'cancelled';
$up = $conn->prepare(
    "UPDATE donation
     SET recurring_status = ?, recurring_next_charge_at = NULL
     WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription' AND recurring_status = 'active'"
);
if (!$up) {
    child_subscription_cancel_redirect('บันทึกสถานะยกเลิกไม่สำเร็จ', false, $childId);
}
$up->bind_param('sii', $cancelled, $childId, $donorUid);
$up->execute();

if ($up->affected_rows > 0) {
    $stChild = $conn->prepare(
        'SELECT child_name, foundation_id FROM foundation_children WHERE child_id = ? LIMIT 1'
    );
    if ($stChild) {
        $stChild->bind_param('i', $childId);
        $stChild->execute();
        $childRow = $stChild->get_result()->fetch_assoc() ?: [];
        $childName = trim((string)($childRow['child_name'] ?? ''));
        $foundationId = (int)($childRow['foundation_id'] ?? 0);
        $foundationUserId = drawdream_foundation_user_id_by_foundation_id($conn, $foundationId);
        if ($foundationUserId > 0) {
            $title = 'มีการยกเลิกอุปการะเด็ก';
            $message = 'ผู้บริจาคได้ยกเลิกการอุปการะเด็ก'
                . ($childName !== '' ? ' "' . $childName . '"' : '')
                . ' แล้ว';
            drawdream_send_notification(
                $conn,
                $foundationUserId,
                'child_subscription_cancelled',
                $title,
                $message,
                'children_donate.php?id=' . $childId
            );
        }
    }
}

drawdream_child_sync_sponsorship_status($conn, $childId);
child_subscription_cancel_redirect('ยกเลิกการอุปการะเรียบร้อยแล้ว', true, $childId);

