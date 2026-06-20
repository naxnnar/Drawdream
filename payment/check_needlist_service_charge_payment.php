<?php
// payment/check_needlist_service_charge_payment.php — ยืนยันชำระค่าบริการระบบรายการสิ่งของ
declare(strict_types=1);

include __DIR__ . '/../db.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/../includes/escrow_funds_schema.php';
require_once __DIR__ . '/../includes/donate_category_resolve.php';
require_once __DIR__ . '/../includes/donate_type.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/notification_audit.php';
require_once __DIR__ . '/omise_helpers.php';
drawdream_ensure_needlist_schema($conn);

$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';

/**
 * @param array<string, mixed> $payload
 */
function needlist_sc_respond(array $payload, bool $json, int $itemId = 0): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit();
    }
    if (!empty($payload['ok']) && $itemId > 0) {
        header('Location: ../foundation_need_view.php?id=' . $itemId . '&sc_paid=1');
        exit();
    }
    header('Location: ../foundation.php');
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    needlist_sc_respond(['ok' => false, 'error' => 'auth'], $wantJson);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stFn = $conn->prepare('SELECT foundation_id FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFn) {
    needlist_sc_respond(['ok' => false, 'error' => 'foundation'], $wantJson);
}
$stFn->bind_param('i', $uid);
$stFn->execute();
$foundationId = (int)($stFn->get_result()->fetch_assoc()['foundation_id'] ?? 0);
if ($foundationId <= 0) {
    needlist_sc_respond(['ok' => false, 'error' => 'foundation'], $wantJson);
}

$itemId = (int)($_GET['item_id'] ?? ($_SESSION['pending_sc_item_id'] ?? 0));
$charge_id = trim((string)($_GET['charge_id'] ?? ($_SESSION['pending_sc_charge_id'] ?? '')));
if ($itemId <= 0 || $charge_id === '') {
    needlist_sc_respond(['ok' => false, 'error' => 'params'], $wantJson, $itemId);
}

$st = $conn->prepare(
    'SELECT item_id, foundation_id, service_charge, service_charge_paid_at, item_name
     FROM foundation_needlist
     WHERE item_id = ? AND foundation_id = ?
     LIMIT 1'
);
if (!$st) {
    needlist_sc_respond(['ok' => false, 'error' => 'db'], $wantJson, $itemId);
}
$st->bind_param('ii', $itemId, $foundationId);
$st->execute();
$item = $st->get_result()->fetch_assoc();
if (!$item) {
    needlist_sc_respond(['ok' => false, 'error' => 'not_found'], $wantJson, $itemId);
}

if (!empty($item['service_charge_paid_at'])) {
    drawdream_escrow_sync_summary_holding_for_need_item($conn, $itemId);
    drawdream_clear_pending_service_charge_session();
    needlist_sc_respond(['ok' => true, 'already' => true], $wantJson, $itemId);
}

$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge = [];
if ($is_mock) {
    $charge = [
        'status' => 'successful',
        'paid' => true,
        'amount' => ((int)($_SESSION['pending_sc_amount'] ?? 0)) * 100,
        'metadata' => [
            'type' => 'need_service_charge',
            'item_id' => $itemId,
            'foundation_id' => $foundationId,
        ],
    ];
} else {
    $fetched = drawdream_omise_fetch_charge($charge_id, false, true);
    $charge = is_array($fetched) ? $fetched : [];
}

$metaType = (string)($charge['metadata']['type'] ?? '');
$metaItem = (int)($charge['metadata']['item_id'] ?? 0);
$metaFid = (int)($charge['metadata']['foundation_id'] ?? 0);
if ($metaType !== 'need_service_charge' || $metaItem !== $itemId || $metaFid !== $foundationId) {
    needlist_sc_respond(['ok' => false, 'error' => 'metadata'], $wantJson, $itemId);
}

$status = (string)($charge['status'] ?? 'unknown');
$paid = $charge['paid'] ?? false;
$is_success = ($paid === true) || ($status === 'successful') || $is_mock;

if (!$is_success) {
    if (in_array($status, ['failed', 'expired'], true)) {
        needlist_sc_respond(['ok' => false, 'error' => 'failed'], $wantJson, $itemId);
    }
    needlist_sc_respond(['ok' => false, 'pending' => true], $wantJson, $itemId);
}

$paidAmount = ($charge['amount'] ?? 0) / 100;
$expected = (float)($item['service_charge'] ?? 0);
$expectedInt = (int)round($expected);
if (abs($paidAmount - $expectedInt) > 0.01 && abs($paidAmount - $expected) > 0.02) {
    needlist_sc_respond(['ok' => false, 'error' => 'amount_mismatch'], $wantJson, $itemId);
}

$dup = $conn->prepare(
    "SELECT donate_id FROM donation WHERE omise_charge_id = ? AND payment_status = 'completed' LIMIT 1"
);
$dup->bind_param('s', $charge_id);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
    $updPaid = $conn->prepare(
        'UPDATE foundation_needlist SET service_charge_paid_at = COALESCE(service_charge_paid_at, NOW()) WHERE item_id = ?'
    );
    if ($updPaid) {
        $updPaid->bind_param('i', $itemId);
        $updPaid->execute();
    }
    drawdream_escrow_sync_summary_holding_for_need_item($conn, $itemId);
    drawdream_clear_pending_service_charge_session();
    needlist_sc_respond(['ok' => true, 'duplicate' => true], $wantJson, $itemId);
}

$category_id = drawdream_get_or_create_service_charge_donate_category_id($conn);
if ($category_id <= 0) {
    needlist_sc_respond(['ok' => false, 'error' => 'category'], $wantJson, $itemId);
}

$ok = false;
if ($conn->begin_transaction()) {
    try {
        $completed = 'completed';
        $dtSc = DRAWDREAM_DONATE_TYPE_NEED_SERVICE_CHARGE;
        $ins = $conn->prepare(
            'INSERT INTO donation (
                category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                omise_charge_id, donate_type
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare_donation');
        }
        $ins->bind_param('iiidsss', $category_id, $itemId, $uid, $paidAmount, $completed, $charge_id, $dtSc);
        $ins->execute();
        if ((int)$conn->insert_id <= 0) {
            throw new RuntimeException('insert_donation');
        }

        $upd = $conn->prepare(
            'UPDATE foundation_needlist SET service_charge_paid_at = NOW() WHERE item_id = ? AND foundation_id = ?'
        );
        if (!$upd) {
            throw new RuntimeException('prepare_paid_at');
        }
        $upd->bind_param('ii', $itemId, $foundationId);
        $upd->execute();
        if ($upd->affected_rows < 1) {
            throw new RuntimeException('update_paid_at');
        }

        if (!drawdream_escrow_sync_summary_holding_for_need_item($conn, $itemId)) {
            throw new RuntimeException('escrow_sync');
        }

        $conn->commit();
        $ok = true;
    } catch (Throwable $e) {
        $conn->rollback();
        $ok = false;
    }
}

if ($ok) {
    $fn = $conn->prepare(
        'SELECT nl.item_name, fp.foundation_name
         FROM foundation_needlist nl
         JOIN foundation_profile fp ON nl.foundation_id = fp.foundation_id
         WHERE nl.item_id = ?
         LIMIT 1'
    );
    if ($fn) {
        $fn->bind_param('i', $itemId);
        $fn->execute();
        $fnRow = $fn->get_result()->fetch_assoc();
        if ($fnRow) {
            drawdream_notify_admins_needlist_service_charge_paid(
                $conn,
                $itemId,
                (string)($fnRow['item_name'] ?? ''),
                (string)($fnRow['foundation_name'] ?? '')
            );
        }
    }
    drawdream_clear_pending_service_charge_session();
    needlist_sc_respond(['ok' => true], $wantJson, $itemId);
}

needlist_sc_respond(['ok' => false, 'error' => 'save'], $wantJson, $itemId);
