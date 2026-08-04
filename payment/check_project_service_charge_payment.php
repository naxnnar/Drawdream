<?php
// payment/check_project_service_charge_payment.php — ยืนยันชำระค่าบริการระบบโครงการ
declare(strict_types=1);

include __DIR__ . '/../includes/payment_bootstrap.php';
include __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/drawdream_project_service_charge.php';
require_once __DIR__ . '/../includes/escrow_funds_schema.php';
require_once __DIR__ . '/../includes/qr_payment_abandon.php';
require_once __DIR__ . '/../includes/notification_audit.php';
require_once __DIR__ . '/../includes/e_receipt.php';
require_once __DIR__ . '/omise_helpers.php';

$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';

/**
 * @param array<string, mixed> $payload
 */
function project_sc_respond(array $payload, bool $json, int $projectId = 0): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit();
    }
    if (!empty($payload['ok']) && $projectId > 0) {
        drawdream_payment_flush_redirect('../foundation_project_view.php?id=' . $projectId . '&sc_paid=1');
        exit();
    }
    header('Location: ../project.php?view=foundation');
    exit();
}

/** @param array<string, mixed> $charge */
function project_sc_charge_is_success(array $charge, bool $isMock): bool
{
    $status = (string)($charge['status'] ?? 'unknown');
    $paid = $charge['paid'] ?? false;

    return ($paid === true) || ($status === 'successful') || $isMock;
}

/**
 * @return array<string, mixed>
 */
function project_sc_resolve_charge(
    string $chargeId,
    int $projectId,
    int $foundationId,
    int $sessionAmount
): array {
    $isMock = (strpos($chargeId, 'chrg_mock_') === 0);
    if ($isMock) {
        return [
            'status' => 'successful',
            'paid' => true,
            'amount' => max(0, $sessionAmount) * 100,
            'metadata' => [
                'type' => 'project_service_charge',
                'project_id' => $projectId,
                'foundation_id' => $foundationId,
            ],
        ];
    }

    $sessionMatches = $chargeId !== ''
        && $chargeId === trim((string)($_SESSION['pending_psc_charge_id'] ?? ''))
        && (int)($_SESSION['pending_psc_project_id'] ?? 0) === $projectId;

    if ($sessionMatches && drawdream_omise_is_test_mode() && $sessionAmount > 0) {
        return [
            'status' => 'successful',
            'paid' => true,
            'amount' => $sessionAmount * 100,
            'metadata' => [
                'type' => 'project_service_charge',
                'project_id' => $projectId,
                'foundation_id' => $foundationId,
            ],
        ];
    }

    if ($sessionMatches && drawdream_omise_is_test_mode()) {
        $marked = drawdream_omise_mark_charge_as_paid_for_test($chargeId, true);
        if (is_array($marked) && project_sc_charge_is_success($marked, false)) {
            return $marked;
        }
    }

    $fetched = drawdream_omise_fetch_charge($chargeId, false, false);

    return is_array($fetched) ? $fetched : [];
}

function project_sc_notify_admins_deferred(
    mysqli $conn,
    int $projectId,
    string $projectName,
    string $foundationName
): void {
    register_shutdown_function(static function () use ($conn, $projectId, $projectName, $foundationName): void {
        try {
            drawdream_notify_admins_project_service_charge_paid($conn, $projectId, $projectName, $foundationName);
        } catch (Throwable $e) {
            error_log('[drawdream_project_sc_deferred] ' . $e->getMessage());
        }
    });
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'foundation') {
    project_sc_respond(['ok' => false, 'error' => 'auth'], $wantJson);
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$stFn = $conn->prepare('SELECT foundation_id, foundation_name FROM foundation_profile WHERE user_id = ? LIMIT 1');
if (!$stFn) {
    project_sc_respond(['ok' => false, 'error' => 'foundation'], $wantJson);
}
$stFn->bind_param('i', $uid);
$stFn->execute();
$fpRow = $stFn->get_result()->fetch_assoc();
$foundationId = (int)($fpRow['foundation_id'] ?? 0);
$foundationName = trim((string)($fpRow['foundation_name'] ?? ''));
if ($foundationId <= 0 || $foundationName === '') {
    project_sc_respond(['ok' => false, 'error' => 'foundation'], $wantJson);
}

$projectId = (int)($_GET['project_id'] ?? ($_SESSION['pending_psc_project_id'] ?? 0));
$charge_id = trim((string)($_GET['charge_id'] ?? ($_SESSION['pending_psc_charge_id'] ?? '')));
if ($projectId <= 0 || $charge_id === '') {
    project_sc_respond(['ok' => false, 'error' => 'params'], $wantJson, $projectId);
}

$st = $conn->prepare(
    'SELECT project_id, foundation_id, service_charge, service_charge_paid_at, project_name
     FROM foundation_project
     WHERE project_id = ? AND foundation_name = ?
     LIMIT 1'
);
if (!$st) {
    project_sc_respond(['ok' => false, 'error' => 'db'], $wantJson, $projectId);
}
$st->bind_param('is', $projectId, $foundationName);
$st->execute();
$proj = $st->get_result()->fetch_assoc();
if (!$proj) {
    project_sc_respond(['ok' => false, 'error' => 'not_found'], $wantJson, $projectId);
}

if (!empty($proj['service_charge_paid_at'])) {
    drawdream_escrow_sync_summary_holding_for_project($conn, $projectId);
    drawdream_clear_pending_project_service_charge_session();
    project_sc_respond(['ok' => true, 'already' => true], $wantJson, $projectId);
}

$sessionAmount = (int)($_SESSION['pending_psc_amount'] ?? 0);
$is_mock = (strpos($charge_id, 'chrg_mock_') === 0);
$charge = project_sc_resolve_charge($charge_id, $projectId, $foundationId, $sessionAmount);

$metaType = (string)($charge['metadata']['type'] ?? '');
$metaProj = (int)($charge['metadata']['project_id'] ?? 0);
$metaFid = (int)($charge['metadata']['foundation_id'] ?? 0);
if ($metaType !== 'project_service_charge' || $metaProj !== $projectId || $metaFid !== $foundationId) {
    project_sc_respond(['ok' => false, 'error' => 'metadata'], $wantJson, $projectId);
}

$status = (string)($charge['status'] ?? 'unknown');
$is_success = project_sc_charge_is_success($charge, $is_mock);

if (!$is_success) {
    if (in_array($status, ['failed', 'expired'], true)) {
        project_sc_respond(['ok' => false, 'error' => 'failed'], $wantJson, $projectId);
    }
    project_sc_respond(['ok' => false, 'pending' => true], $wantJson, $projectId);
}

$paidAmount = ($charge['amount'] ?? 0) / 100;
$expected = (float)($proj['service_charge'] ?? 0);
$expectedInt = (int)round($expected);
if (abs($paidAmount - $expectedInt) > 0.01 && abs($paidAmount - $expected) > 0.02) {
    project_sc_respond(['ok' => false, 'error' => 'amount_mismatch'], $wantJson, $projectId);
}

$dup = $conn->prepare(
    "SELECT donate_id FROM donation WHERE omise_charge_id = ? AND payment_status = 'completed' LIMIT 1"
);
$dup->bind_param('s', $charge_id);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
    $updPaid = $conn->prepare(
        'UPDATE foundation_project SET service_charge_paid_at = COALESCE(service_charge_paid_at, NOW()) WHERE project_id = ?'
    );
    if ($updPaid) {
        $updPaid->bind_param('i', $projectId);
        $updPaid->execute();
    }
    drawdream_escrow_sync_summary_holding_for_project($conn, $projectId);
    drawdream_clear_pending_project_service_charge_session();
    project_sc_respond(['ok' => true, 'duplicate' => true], $wantJson, $projectId);
}

$category_id = drawdream_get_or_create_service_charge_donate_category_id($conn);
if ($category_id <= 0) {
    project_sc_respond(['ok' => false, 'error' => 'category'], $wantJson, $projectId);
}

$ok = false;
if ($conn->begin_transaction()) {
    try {
        $completed = 'completed';
        $dtSc = DRAWDREAM_DONATE_TYPE_PROJECT_SERVICE_CHARGE;
        $ins = $conn->prepare(
            'INSERT INTO donation (
                category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                omise_charge_id, donate_type
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare_donation');
        }
        $ins->bind_param('iiidsss', $category_id, $projectId, $uid, $paidAmount, $completed, $charge_id, $dtSc);
        $ins->execute();
        if ((int)$conn->insert_id <= 0) {
            throw new RuntimeException('insert_donation');
        }

        $upd = $conn->prepare(
            'UPDATE foundation_project SET service_charge_paid_at = NOW() WHERE project_id = ? AND foundation_name = ?'
        );
        if (!$upd) {
            throw new RuntimeException('prepare_paid_at');
        }
        $upd->bind_param('is', $projectId, $foundationName);
        $upd->execute();
        if ($upd->affected_rows < 1) {
            throw new RuntimeException('update_paid_at');
        }

        if (!drawdream_escrow_sync_summary_holding_for_project($conn, $projectId)) {
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
    project_sc_notify_admins_deferred(
        $conn,
        $projectId,
        (string)($proj['project_name'] ?? ''),
        $foundationName
    );
    drawdream_clear_pending_project_service_charge_session();
    project_sc_respond(['ok' => true], $wantJson, $projectId);
}

project_sc_respond(['ok' => false, 'error' => 'save'], $wantJson, $projectId);
