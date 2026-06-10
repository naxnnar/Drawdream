<?php
// includes/drawdream_needlist_payment_finalize.php — ปิดบริจาค needlist + จัดการ race ครบเป้า
declare(strict_types=1);

require_once __DIR__ . '/drawdream_project_payment_finalize.php';
require_once __DIR__ . '/drawdream_needlist_schema.php';
require_once __DIR__ . '/needlist_donate_window.php';
require_once __DIR__ . '/escrow_funds_schema.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';
require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/notification_audit.php';

function drawdream_needlist_finalize_is_goal_race(string $code): bool
{
    return drawdream_project_finalize_is_goal_race($code);
}

/** @return array{goal: float, current: float, remaining: float} */
function drawdream_needlist_open_goal_totals(mysqli $conn, int $foundationId): array
{
    $out = ['goal' => 0.0, 'current' => 0.0, 'remaining' => 0.0];
    if ($foundationId <= 0) {
        return $out;
    }
    $needOpen = drawdream_needlist_sql_open_for_donation();
    $st = $conn->prepare(
        "SELECT COALESCE(SUM(total_price), 0) AS goal, COALESCE(SUM(current_donate), 0) AS current
         FROM foundation_needlist WHERE foundation_id = ? AND $needOpen"
    );
    if (!$st) {
        return $out;
    }
    $st->bind_param('i', $foundationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $goal = (float)($row['goal'] ?? 0);
    $current = (float)($row['current'] ?? 0);
    $out['goal'] = $goal;
    $out['current'] = $current;
    $out['remaining'] = ($goal > 0) ? max(0.0, $goal - $current) : 0.0;

    return $out;
}

function drawdream_needlist_remaining_goal_baht(mysqli $conn, int $foundationId): float
{
    return drawdream_needlist_open_goal_totals($conn, $foundationId)['remaining'];
}

function drawdream_needlist_can_accept_donation_amount(mysqli $conn, int $foundationId, float $amountBaht): bool
{
    if ($foundationId <= 0 || $amountBaht < 20) {
        return false;
    }
    $remaining = drawdream_needlist_remaining_goal_baht($conn, $foundationId);
    if ($remaining <= 0 || $remaining < 20) {
        return false;
    }

    return $amountBaht <= $remaining + 1e-6;
}

function drawdream_insert_pending_needlist_donation(
    mysqli $conn,
    int $foundationId,
    int $donorUserId,
    float $amountBaht,
    string $omiseChargeId
): int {
    drawdream_payment_transaction_ensure_schema($conn);
    $categoryId = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($categoryId <= 0) {
        return 0;
    }
    $pending = 'pending';
    $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $ins = $conn->prepare(
        'INSERT INTO donation (
            category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
            omise_charge_id, donate_type
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
    );
    if (!$ins) {
        return 0;
    }
    $ins->bind_param('iiidsss', $categoryId, $foundationId, $donorUserId, $amountBaht, $pending, $omiseChargeId, $dtNeed);
    if (!$ins->execute()) {
        return 0;
    }

    return (int)$conn->insert_id;
}

/**
 * @return string DRAWDREAM_PROJECT_FINALIZE_*
 */
function drawdream_needlist_bump_open_items(
    mysqli $conn,
    int $foundationId,
    float $amountBaht,
    int $donateId,
    string $chargeId
): string {
    $needOpen = drawdream_needlist_sql_open_for_donation();

    $lock = $conn->prepare("SELECT item_id FROM foundation_needlist WHERE foundation_id = ? AND $needOpen FOR UPDATE");
    if ($lock) {
        $lock->bind_param('i', $foundationId);
        $lock->execute();
        $lock->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $totals = drawdream_needlist_open_goal_totals($conn, $foundationId);
    $goal = $totals['goal'];
    $current = $totals['current'];
    if ($goal <= 0) {
        return 'not_configured';
    }
    if ($current >= $goal - 1e-9) {
        return DRAWDREAM_PROJECT_FINALIZE_GOAL_MET;
    }
    if ($current + $amountBaht > $goal + 1e-6) {
        return DRAWDREAM_PROJECT_FINALIZE_GOAL_EXCEED;
    }

    $items = $conn->prepare("SELECT item_id, total_price FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
    if (!$items) {
        return 'update_failed';
    }
    $items->bind_param('i', $foundationId);
    $items->execute();
    $item_rows = $items->get_result();
    $old_c = $current;
    $old_g = $goal;

    while ($item = $item_rows->fetch_assoc()) {
        $itemId = (int)($item['item_id'] ?? 0);
        $ratio = (float)$item['total_price'] / $goal;
        $item_amount = round($amountBaht * $ratio, 2);
        $upd = $conn->prepare('UPDATE foundation_needlist SET current_donate = current_donate + ? WHERE item_id = ?');
        if (!$upd) {
            return 'update_failed';
        }
        $upd->bind_param('di', $item_amount, $itemId);
        $upd->execute();
        if ($itemId > 0) {
            drawdream_needlist_sync_service_charge_for_item($conn, $itemId);
        }
        if ($itemId > 0 && $item_amount > 0) {
            if (!drawdream_escrow_funds_try_insert_holding_for_target($conn, 'need_item', $itemId, $donateId, $chargeId, $item_amount)) {
                return 'escrow_failed';
            }
        }
    }

    $newTotals = drawdream_needlist_open_goal_totals($conn, $foundationId);
    $new_c = $newTotals['current'];
    $new_g = $newTotals['goal'];
    $eps = 1e-6;
    if ($old_g > $eps && $old_c < $old_g - $eps && $new_c >= $new_g - $eps) {
        $fu = $conn->prepare('SELECT user_id, foundation_name FROM foundation_profile WHERE foundation_id = ? LIMIT 1');
        if ($fu) {
            $fu->bind_param('i', $foundationId);
            $fu->execute();
            $frow = $fu->get_result()->fetch_assoc();
            if ($frow) {
                $foundation_uid = (int)($frow['user_id'] ?? 0);
                $foundation_nm = trim((string)($frow['foundation_name'] ?? ''));
                if ($foundation_uid > 0) {
                    $totalFmt = number_format($new_c, 2, '.', ',');
                    $dispName = $foundation_nm !== '' ? '"' . $foundation_nm . '"' : 'มูลนิธิของคุณ';
                    $title = 'รายการสิ่งของได้รับเงินครบเป้าหมายแล้ว! 🎉';
                    $msg = 'รายการสิ่งของของ ' . $dispName . ' ได้รับเงินบริจาครวม ' . $totalFmt . ' บาท '
                        . 'กรุณาชำระค่าบริการระบบตามรายการที่ครบเป้าหมาย '
                        . 'เพื่อให้แอดมินดำเนินการจัดส่งสิ่งของ';
                    $link = 'foundation.php#my-needlist-section';
                    $sigStmt = $conn->prepare("SELECT GROUP_CONCAT(item_id ORDER BY item_id) AS sig FROM foundation_needlist WHERE foundation_id = ? AND ($needOpen)");
                    $sig = '';
                    if ($sigStmt) {
                        $sigStmt->bind_param('i', $foundationId);
                        $sigStmt->execute();
                        $sigRow = $sigStmt->get_result()->fetch_assoc();
                        $sig = trim((string)($sigRow['sig'] ?? ''));
                    }
                    $entityKey = 'needlist_goal_met:' . $foundationId . ':' . ($sig !== '' ? $sig : '0');
                    drawdream_send_notification($conn, $foundation_uid, 'needlist_funded', $title, $msg, $link, $entityKey);
                }
            }
        }
    }

    return DRAWDREAM_PROJECT_FINALIZE_OK;
}

/**
 * @return string DRAWDREAM_PROJECT_FINALIZE_* หรือ 'error'
 */
function drawdream_finalize_needlist_donation(
    mysqli $conn,
    int $foundationId,
    int $donateIdParam,
    string $chargeId,
    float $amountBaht,
    int $donorUserId
): string {
    drawdream_payment_transaction_ensure_schema($conn);

    $pend = 'pending';
    $pt = $conn->prepare(
        'SELECT donate_id, category_id, target_id, donor_id
         FROM donation WHERE omise_charge_id = ? AND payment_status = ? LIMIT 1'
    );
    $pt->bind_param('ss', $chargeId, $pend);
    $pt->execute();
    $ptRow = $pt->get_result()->fetch_assoc();
    if (!$ptRow) {
        return 'error';
    }

    $ptDonateId = (int)($ptRow['donate_id'] ?? 0);
    if ($donateIdParam > 0 && $ptDonateId > 0 && $donateIdParam !== $ptDonateId) {
        return 'error';
    }

    $pDonor = (int)($ptRow['donor_id'] ?? 0);
    $pTarget = (int)($ptRow['target_id'] ?? 0);
    if ($pDonor !== $donorUserId || $pTarget !== $foundationId || $ptDonateId <= 0) {
        return 'error';
    }

    if (!$conn->begin_transaction()) {
        return 'error';
    }
    try {
        $completed = 'completed';
        $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
        $upt = $conn->prepare(
            'UPDATE donation
             SET amount = ?, payment_status = ?, transfer_datetime = NOW(), donate_type = ?
             WHERE donate_id = ? AND payment_status = ?'
        );
        $upt->bind_param('dssis', $amountBaht, $completed, $dtNeed, $ptDonateId, $pend);
        $upt->execute();
        if ($upt->affected_rows < 1) {
            throw new RuntimeException('update donation');
        }

        $bump = drawdream_needlist_bump_open_items($conn, $foundationId, $amountBaht, $ptDonateId, $chargeId);
        if ($bump !== DRAWDREAM_PROJECT_FINALIZE_OK) {
            throw new RuntimeException('needlist bump:' . $bump);
        }

        $conn->commit();

        return DRAWDREAM_PROJECT_FINALIZE_OK;
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'needlist bump:')) {
            return substr($msg, strlen('needlist bump:'));
        }

        return 'error';
    }
}

/**
 * @return array{status: string, refunded: bool, donate_id: int}
 */
function drawdream_handle_needlist_late_payment(
    mysqli $conn,
    int $donateId,
    float $amountBaht,
    int $foundationId,
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
    $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $pend = 'pending';
    $upd->bind_param('dssiis', $amountBaht, $newStatus, $dtNeed, $donateId, $donorUserId, $pend);
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
                'บริจาคเงินเพื่อสมทบทุนจัดซื้อสิ่งของไม่สามารถนับยอดได้',
                'ผู้บริจาคชำระเงินแล้ว แต่มูลนิธิ #' . $foundationId . ' ครบเป้าก่อนหน้า (เหตุ: ' . $reason . ') — ต้องคืนเงินด้วยตนเอง Charge: ' . $chargeId,
                'admin_escrow.php'
            );
        }
    }

    return $out;
}
