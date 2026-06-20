<?php
// includes/drawdream_project_payment_finalize.php — ปิดบริจาคโครงการ + จัดการ race ครบเป้า
declare(strict_types=1);

require_once __DIR__ . '/drawdream_project_service_charge.php';
require_once __DIR__ . '/donate_type.php';
require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/notification_audit.php';
require_once __DIR__ . '/omise_api_client.php';

const DRAWDREAM_DONATION_STATUS_PAID_UNALLOCATED = 'paid_unallocated';
const DRAWDREAM_DONATION_STATUS_REFUNDED = 'refunded';

const DRAWDREAM_PROJECT_FINALIZE_OK = 'ok';
const DRAWDREAM_PROJECT_FINALIZE_GOAL_MET = 'goal_met';
const DRAWDREAM_PROJECT_FINALIZE_GOAL_EXCEED = 'goal_exceed';

function drawdream_project_finalize_is_goal_race(string $code): bool
{
    return in_array($code, [DRAWDREAM_PROJECT_FINALIZE_GOAL_MET, DRAWDREAM_PROJECT_FINALIZE_GOAL_EXCEED], true);
}

/**
 * @return string DRAWDREAM_PROJECT_FINALIZE_* 
 */
function drawdream_project_bump_and_maybe_complete(mysqli $conn, int $project_id, float $amountBaht): string
{
    $sel = $conn->prepare('SELECT goal_amount, current_donate FROM foundation_project WHERE project_id = ? FOR UPDATE');
    $sel->bind_param('i', $project_id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    if (!$row) {
        return 'not_found';
    }
    $g = (float)($row['goal_amount'] ?? 0);
    $r = (float)($row['current_donate'] ?? 0);
    if ($g > 0) {
        if ($r >= $g - 1e-9) {
            return DRAWDREAM_PROJECT_FINALIZE_GOAL_MET;
        }
        if ($r + $amountBaht > $g + 1e-6) {
            return DRAWDREAM_PROJECT_FINALIZE_GOAL_EXCEED;
        }
    }

    $stmt = $conn->prepare('UPDATE foundation_project SET current_donate = current_donate + ? WHERE project_id = ?');
    $stmt->bind_param('di', $amountBaht, $project_id);
    if (!$stmt->execute() || $stmt->affected_rows < 1) {
        return 'update_failed';
    }

    drawdream_project_sync_service_charge_for_project($conn, $project_id);

    $check = $conn->prepare("
        SELECT p.project_id, p.project_name, p.current_donate, fp.user_id AS foundation_user_id, fp.foundation_name
        FROM foundation_project p
        JOIN foundation_profile fp ON p.foundation_name = fp.foundation_name
        WHERE p.project_id = ?
          AND p.project_status = 'approved'
         
          AND (
              p.current_donate >= p.goal_amount
              OR (p.end_date IS NOT NULL AND p.end_date <= CURDATE())
          )
    ");
    $check->bind_param('i', $project_id);
    $check->execute();
    $completed_proj = $check->get_result()->fetch_assoc();
    if ($completed_proj) {
        $upd = $conn->prepare("UPDATE foundation_project SET project_status = 'completed', completed_at = NOW() WHERE project_id = ?");
        $upd->bind_param('i', $project_id);
        $upd->execute();

        $foundation_user_id = (int)$completed_proj['foundation_user_id'];
        $proj_name = $completed_proj['project_name'];
        $total = number_format((float)$completed_proj['current_donate'], 2);

        $scRow = $conn->prepare(
            'SELECT service_charge FROM foundation_project WHERE project_id = ? LIMIT 1'
        );
        $scAmt = 0.0;
        if ($scRow) {
            $scRow->bind_param('i', $project_id);
            $scRow->execute();
            $scAmt = (float)($scRow->get_result()->fetch_assoc()['service_charge'] ?? 0);
        }
        $scLabel = $scAmt > 0 ? number_format($scAmt, 2) : '0.00';
        $notif_title = 'โครงการของคุณได้รับเงินครบแล้ว! 🎉';
        $notif_msg = "โครงการ \"$proj_name\" ได้รับเงินบริจาครวม $total บาท "
            . "กรุณาชำระค่าบริการระบบ {$scLabel} บาท (5%) จากหน้ารายละเอียดโครงการ "
            . 'ก่อนแอดมินยืนยันโอนเงิน escrow';
        $notif_link = 'foundation_post_update.php?project_id=' . $project_id;
        drawdream_send_notification($conn, $foundation_user_id, '', $notif_title, $notif_msg, $notif_link);
    }

    return DRAWDREAM_PROJECT_FINALIZE_OK;
}

/**
 * @return string DRAWDREAM_PROJECT_FINALIZE_* หรือ 'error'
 */
function drawdream_finalize_project_donation(
    mysqli $conn,
    int $project_id,
    int $donate_id_param,
    string $charge_id,
    float $amountBaht,
    int $donor_user_id
): string {
    drawdream_payment_transaction_ensure_schema($conn);

    $pend = 'pending';
    $pt = $conn->prepare(
        'SELECT donate_id, category_id, target_id, donor_id
         FROM donation WHERE omise_charge_id = ? AND payment_status = ? LIMIT 1'
    );
    $pt->bind_param('ss', $charge_id, $pend);
    $pt->execute();
    $ptRow = $pt->get_result()->fetch_assoc();
    if (!$ptRow) {
        return 'error';
    }

    $ptDonateId = isset($ptRow['donate_id']) && $ptRow['donate_id'] !== null ? (int)$ptRow['donate_id'] : 0;

    if ($donate_id_param > 0 && $ptDonateId > 0 && $donate_id_param !== $ptDonateId) {
        return 'error';
    }

    $pDonor = (int)($ptRow['donor_id'] ?? 0);
    $pTarget = (int)($ptRow['target_id'] ?? 0);
    $pCat = (int)($ptRow['category_id'] ?? 0);
    if ($pDonor !== $donor_user_id || $pTarget !== $project_id || $pCat <= 0 || $ptDonateId <= 0) {
        return 'error';
    }

    if (!$conn->begin_transaction()) {
        return 'error';
    }
    try {
        $completed = 'completed';
        $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
        $upt = $conn->prepare(
            'UPDATE donation
             SET amount = ?, payment_status = ?, transfer_datetime = NOW(), donate_type = ?
             WHERE donate_id = ? AND payment_status = ?'
        );
        $upt->bind_param('dssis', $amountBaht, $completed, $dtProj, $ptDonateId, $pend);
        $upt->execute();
        if ($upt->affected_rows < 1) {
            throw new RuntimeException('update donation');
        }

        $bump = drawdream_project_bump_and_maybe_complete($conn, $project_id, $amountBaht);
        if ($bump !== DRAWDREAM_PROJECT_FINALIZE_OK) {
            throw new RuntimeException('project bump:' . $bump);
        }

        $conn->commit();

        return DRAWDREAM_PROJECT_FINALIZE_OK;
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'project bump:')) {
            return substr($msg, strlen('project bump:'));
        }

        return 'error';
    }
}

/** ยอดที่ยังรับบริจาคได้ (บาท) — 0 = ครบเป้าแล้ว */
function drawdream_project_remaining_goal_baht(mysqli $conn, int $projectId): float
{
    if ($projectId <= 0) {
        return 0.0;
    }
    $st = $conn->prepare(
        'SELECT goal_amount, current_donate, project_status
         FROM foundation_project WHERE project_id = ? LIMIT 1'
    );
    if (!$st) {
        return 0.0;
    }
    $st->bind_param('i', $projectId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return 0.0;
    }
    $goal = (float)($row['goal_amount'] ?? 0);
    $raised = (float)($row['current_donate'] ?? 0);
    if ($goal <= 0) {
        return PHP_FLOAT_MAX;
    }

    return max(0.0, $goal - $raised);
}

/** โครงการยังรับบริจาคจำนวนนี้ได้หรือไม่ */
function drawdream_project_can_accept_donation_amount(mysqli $conn, int $projectId, float $amountBaht): bool
{
    if ($projectId <= 0 || $amountBaht < 20) {
        return false;
    }
    $remaining = drawdream_project_remaining_goal_baht($conn, $projectId);
    if ($remaining <= 0) {
        return false;
    }
    if ($remaining < 20) {
        return false;
    }

    return $amountBaht <= $remaining + 1e-6;
}

function drawdream_omise_refund_charge(string $chargeId, ?int $amountSatang = null): array
{
    $chargeId = trim($chargeId);
    if ($chargeId === '') {
        return ['object' => 'error', 'message' => 'missing charge id'];
    }
    if (strpos($chargeId, 'chrg_mock_') === 0) {
        return [
            'object' => 'refund',
            'id' => 'rfnd_mock_' . bin2hex(random_bytes(6)),
            'status' => 'closed',
            'amount' => $amountSatang ?? 0,
        ];
    }
    $fields = [];
    if ($amountSatang !== null && $amountSatang > 0) {
        $fields['amount'] = (string)$amountSatang;
    }

    return drawdream_omise_post_form('/charges/' . rawurlencode($chargeId) . '/refunds', $fields);
}

/**
 * จ่ายแล้วแต่ไม่ทันครบเป้า — พยายามคืนเงินอัตโนมัติผ่าน Omise
 *
 * @return array{status: string, refunded: bool, donate_id: int}
 */
function drawdream_handle_project_late_payment(
    mysqli $conn,
    int $donateId,
    float $amountBaht,
    int $projectId,
    string $chargeId,
    int $donorUserId,
    string $reason
): array {
    $out = ['status' => DRAWDREAM_DONATION_STATUS_PAID_UNALLOCATED, 'refunded' => false, 'donate_id' => $donateId];

    if ($donateId <= 0 || $chargeId === '' || $donorUserId <= 0) {
        return $out;
    }

    $amountSatang = (int)round($amountBaht * 100);
    $refundRes = drawdream_omise_refund_charge($chargeId, $amountSatang > 0 ? $amountSatang : null);
    $refunded = (($refundRes['object'] ?? '') === 'refund')
        && in_array((string)($refundRes['status'] ?? ''), ['closed', 'pending'], true);

    $newStatus = $refunded ? DRAWDREAM_DONATION_STATUS_REFUNDED : DRAWDREAM_DONATION_STATUS_PAID_UNALLOCATED;
    $upd = $conn->prepare(
        'UPDATE donation
         SET amount = ?, payment_status = ?, transfer_datetime = NOW(), donate_type = ?
         WHERE donate_id = ? AND donor_id = ? AND payment_status = ?'
    );
    if (!$upd) {
        return $out;
    }
    $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
    $pend = 'pending';
    $upd->bind_param('dssiis', $amountBaht, $newStatus, $dtProj, $donateId, $donorUserId, $pend);
    $upd->execute();

    if ($upd->affected_rows < 1) {
        $chk = $conn->prepare('SELECT payment_status FROM donation WHERE donate_id = ? AND donor_id = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('ii', $donateId, $donorUserId);
            $chk->execute();
            $existing = (string)($chk->get_result()->fetch_assoc()['payment_status'] ?? '');
            if (in_array($existing, [DRAWDREAM_DONATION_STATUS_REFUNDED, DRAWDREAM_DONATION_STATUS_PAID_UNALLOCATED], true)) {
                $out['status'] = $existing;
                $out['refunded'] = ($existing === DRAWDREAM_DONATION_STATUS_REFUNDED);
                return $out;
            }
        }
        return $out;
    }

    $out['status'] = $newStatus;
    $out['refunded'] = $refunded;

    if (!$refunded) {
        foreach (drawdream_admin_user_ids($conn) as $adminUid) {
            drawdream_send_notification(
                $conn,
                $adminUid,
                '',
                'บริจาคโครงการไม่สามารถนับยอดได้',
                'ผู้บริจาคชำระเงินแล้ว แต่โครงการ #' . $projectId . ' ครบเป้าก่อนหน้า (เหตุ: ' . $reason . ') — ต้องคืนเงินด้วยตนเอง Charge: ' . $chargeId,
                'admin_escrow.php'
            );
        }
    }

    return $out;
}

function drawdream_record_project_paid_unallocated(
    mysqli $conn,
    int $donateId,
    float $amountBaht,
    int $projectId,
    string $chargeId,
    int $donorUserId,
    string $reason
): bool {
    $result = drawdream_handle_project_late_payment($conn, $donateId, $amountBaht, $projectId, $chargeId, $donorUserId, $reason);

    return $result['donate_id'] > 0;
}

function drawdream_insert_project_paid_unallocated(
    mysqli $conn,
    int $projectId,
    int $donorUserId,
    float $amountBaht,
    string $chargeId,
    string $reason
): int {
    require_once __DIR__ . '/donate_category_resolve.php';

    $categoryId = drawdream_get_or_create_project_donate_category_id($conn);
    if ($categoryId <= 0) {
        return 0;
    }
    $dtProj = DRAWDREAM_DONATE_TYPE_PROJECT;
    $pend = 'pending';
    $ins = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, donate_type
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
    );
    if (!$ins) {
        return 0;
    }
    $ins->bind_param('iiidsss', $categoryId, $projectId, $donorUserId, $amountBaht, $pend, $chargeId, $dtProj);
    if (!$ins->execute()) {
        return 0;
    }
    $donateId = (int)$conn->insert_id;
    if ($donateId <= 0) {
        return 0;
    }

    $result = drawdream_handle_project_late_payment($conn, $donateId, $amountBaht, $projectId, $chargeId, $donorUserId, $reason);

    return $result['donate_id'] > 0 ? $donateId : 0;
}
