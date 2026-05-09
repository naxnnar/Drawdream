<?php
declare(strict_types=1);
// สรุปสั้น: ยกเลิกแผนอุปการะเด็กรายรอบ (subscription) และอัปเดตสถานะให้ตรงกับฐานข้อมูล

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
require_once dirname(__DIR__) . '/includes/child_subscription_history.php';

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
    "SELECT history_id, donate_id, recurring_schedule_id, recurring_plan_code
     FROM child_subscription_history
     WHERE child_id = ? AND donor_user_id = ? AND current_status = 'active'
     ORDER BY history_id DESC
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
$activeHistoryId = (int)($sub['history_id'] ?? 0);

// omise_schedule: เรียก revoke schedule เพื่อตัดรอบอนาคต
if ($scheduleId !== '' && str_starts_with($scheduleId, 'schd_')) {
    $res = drawdream_omise_post_form('/schedules/' . rawurlencode($scheduleId) . '/revoke', []);
    if (($res['object'] ?? '') === 'error' && !drawdream_omise_is_not_found_error($res)) {
        $m = drawdream_omise_error_message_for_user($res, 'ยกเลิกการอุปการะไม่สำเร็จ');
        child_subscription_cancel_redirect($m, false, $childId);
    }
}

$up = $conn->prepare(
    "UPDATE donation
     SET donate_type = 'child_subscription_charge'
     WHERE target_id = ? AND donor_id = ? AND donate_type = 'child_subscription'"
);
if (!$up) {
    child_subscription_cancel_redirect('บันทึกสถานะยกเลิกไม่สำเร็จ', false, $childId);
}
$up->bind_param('ii', $childId, $donorUid);
$up->execute();

// อัปเดต "แถวเดิม" ในประวัติให้เป็น cancelled และรีเซ็ตเวลา created_at เป็นเวลายกเลิกจริง
// (ไม่เพิ่มแถวใหม่ตามข้อกำหนด)
$cancelApplied = false;
$upHist = $conn->prepare(
    "UPDATE child_subscription_history
     SET event_type = 'subscription_cancelled',
         current_status = 'cancelled',
         created_at = NOW()
     WHERE child_id = ? AND donor_user_id = ? AND current_status = 'active'"
);
if ($upHist) {
    $upHist->bind_param('ii', $childId, $donorUid);
    $upHist->execute();
    $cancelApplied = $upHist->affected_rows > 0;
}
if (!$cancelApplied && $activeHistoryId > 0) {
    $upHistOne = $conn->prepare(
        "UPDATE child_subscription_history
         SET event_type = 'subscription_cancelled',
             current_status = 'cancelled',
             created_at = NOW()
         WHERE history_id = ? LIMIT 1"
    );
    if ($upHistOne) {
        $upHistOne->bind_param('i', $activeHistoryId);
        $upHistOne->execute();
        $cancelApplied = $upHistOne->affected_rows > 0;
    }
}

if ($cancelApplied) {
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
            $coverage = drawdream_child_donor_plan_coverage_window($conn, $childId, $donorUid);
            $nextOpenText = 'สามารถเปิดอุปการะรอบใหม่ได้ทันที';
            if (($coverage['end'] ?? null) instanceof DateTimeImmutable) {
                /** @var DateTimeImmutable $endAt */
                $endAt = $coverage['end'];
                $nextOpenText = 'เปิดอุปการะรอบถัดไปได้วันที่ ' . $endAt->format('d/m/Y H:i');
            }
            $title = 'มีการยกเลิกอุปการะเด็ก';
            $message = 'ผู้บริจาคได้ยกเลิกการอุปการะเด็ก'
                . ($childName !== '' ? ' "' . $childName . '"' : '')
                . ' แล้ว · ' . $nextOpenText;
            drawdream_send_notification(
                $conn,
                $foundationUserId,
                'child_subscription_cancelled',
                $title,
                $message,
                'children_donate.php?id=' . $childId,
                'child_subscription_cancelled:' . $childId
            );
        }
    }
}

drawdream_child_sync_sponsorship_status($conn, $childId);
child_subscription_cancel_redirect('ยกเลิกการอุปการะเรียบร้อยแล้ว', true, $childId);

