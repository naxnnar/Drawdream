<?php
// includes/drawdream_needlist_payment_finalize.php — ปิดบริจาค needlist + จัดการ race ครบเป้า
declare(strict_types=1);

require_once __DIR__ . '/drawdream_project_payment_finalize.php';
require_once __DIR__ . '/drawdream_needlist_schema.php';
require_once __DIR__ . '/drawdream_needlist_catalog.php';
require_once __DIR__ . '/drawdream_needlist_catalog_funded.php';
require_once __DIR__ . '/needlist_donate_window.php';
require_once __DIR__ . '/donate_category_resolve.php';
require_once __DIR__ . '/donate_type.php';
require_once __DIR__ . '/payment_transaction_schema.php';
require_once __DIR__ . '/notification_audit.php';

const DRAWDREAM_NEED_FINALIZE_ITEM_EXCEED = 'item_exceed';

function drawdream_needlist_finalize_is_goal_race(string $code): bool
{
    return drawdream_project_finalize_is_goal_race($code)
        || $code === DRAWDREAM_NEED_FINALIZE_ITEM_EXCEED;
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
    string $omiseChargeId,
    array $picks = []
): int {
    drawdream_payment_transaction_ensure_schema($conn);
    $categoryId = drawdream_get_or_create_needitem_donate_category_id($conn);
    if ($categoryId <= 0) {
        return 0;
    }
    $pending = 'pending';
    $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
    $picksJson = '';
    if ($picks !== []) {
        $picksJson = drawdream_need_encode_picks_json($picks);
    }
    $hasPicksCol = drawdream_donation_has_need_item_picks_column($conn);
    if ($hasPicksCol && $picksJson !== '' && $picksJson !== '{}') {
        $ins = $conn->prepare(
            'INSERT INTO donation (
                category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                omise_charge_id, donate_type, need_item_picks_json
            ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)'
        );
        if (!$ins) {
            return 0;
        }
        $ins->bind_param('iiidssss', $categoryId, $foundationId, $donorUserId, $amountBaht, $pending, $omiseChargeId, $dtNeed, $picksJson);
    } else {
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
    }
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

    $itemUpdates = [];
    while ($item = $item_rows->fetch_assoc()) {
        $itemId = (int)($item['item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $ratio = (float)$item['total_price'] / $goal;
        $itemUpdates[$itemId] = round($amountBaht * $ratio, 2);
    }
    if ($itemUpdates !== []) {
        $caseSql = '';
        $bindTypes = '';
        $bindParams = [];
        foreach ($itemUpdates as $itemId => $itemAmount) {
            $caseSql .= ' WHEN ? THEN ?';
            $bindTypes .= 'id';
            $bindParams[] = $itemId;
            $bindParams[] = $itemAmount;
        }
        $itemIds = array_keys($itemUpdates);
        $inPh = implode(',', array_fill(0, count($itemIds), '?'));
        $bindTypes .= 'i' . str_repeat('i', count($itemIds));
        $bindParams[] = $foundationId;
        foreach ($itemIds as $id) {
            $bindParams[] = $id;
        }
        $batchUpd = $conn->prepare(
            'UPDATE foundation_needlist
             SET current_donate = current_donate + CASE item_id' . $caseSql . ' ELSE 0 END
             WHERE foundation_id = ? AND item_id IN (' . $inPh . ')'
        );
        if (!$batchUpd) {
            return 'update_failed';
        }
        $batchUpd->bind_param($bindTypes, ...$bindParams);
        $batchUpd->execute();
        foreach ($itemIds as $itemId) {
            drawdream_needlist_sync_service_charge_for_item($conn, $itemId);
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
                    $itemIdForLink = drawdream_needlist_first_unpaid_service_charge_item_id($conn, $foundationId);
                    $link = $itemIdForLink > 0
                        ? drawdream_needlist_service_charge_view_link($itemIdForLink)
                        : 'foundation.php#my-needlist-section';
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
 * ปิดบริจาค needlist สำเร็จ: lock → ตรวจ picks → บันทึก completed + picks → bump เงิน
 *
 * @param array<string,int> $picks
 * @return string DRAWDREAM_PROJECT_FINALIZE_* | DRAWDREAM_NEED_FINALIZE_ITEM_EXCEED | 'error'
 */
function drawdream_needlist_complete_successful_payment(
    mysqli $conn,
    int $foundationId,
    int $donorUserId,
    string $chargeId,
    float $amountBaht,
    array $picks,
    int $existingDonateId = 0
): string {
    drawdream_payment_transaction_ensure_schema($conn);

    if ($foundationId <= 0 || $donorUserId <= 0 || $chargeId === '' || $amountBaht < 20 || $picks === []) {
        return 'error';
    }

    $needOpen = drawdream_needlist_sql_open_for_donation();
    if (!$conn->begin_transaction()) {
        return 'error';
    }

    try {
        $lock = $conn->prepare("SELECT item_id FROM foundation_needlist WHERE foundation_id = ? AND $needOpen FOR UPDATE");
        if ($lock) {
            $lock->bind_param('i', $foundationId);
            $lock->execute();
            $lock->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $itemsSt = $conn->prepare("SELECT * FROM foundation_needlist WHERE foundation_id = ? AND $needOpen");
        if (!$itemsSt) {
            throw new RuntimeException('load items');
        }
        $itemsSt->bind_param('i', $foundationId);
        $itemsSt->execute();
        $itemRows = $itemsSt->get_result()->fetch_all(MYSQLI_ASSOC);
        $skeleton = drawdream_need_catalog_skeleton_from_needlist_rows($itemRows);
        $totals = drawdream_needlist_open_goal_totals($conn, $foundationId);
        $catalog = drawdream_need_catalog_with_remaining(
            $conn,
            $foundationId,
            $skeleton,
            $totals['remaining']
        );

        $pickErr = drawdream_need_validate_picks_against_catalog($catalog, $picks);
        if ($pickErr !== null) {
            throw new RuntimeException('needlist item:' . DRAWDREAM_NEED_FINALIZE_ITEM_EXCEED);
        }

        $pickTotal = drawdream_need_pick_total_baht_from_catalog($catalog, $picks);
        if (abs($pickTotal - $amountBaht) > 0.06) {
            throw new RuntimeException('amount mismatch');
        }

        $picksJson = drawdream_need_encode_picks_json($picks);
        $categoryId = drawdream_get_or_create_needitem_donate_category_id($conn);
        if ($categoryId <= 0) {
            throw new RuntimeException('category');
        }

        $completed = 'completed';
        $dtNeed = DRAWDREAM_DONATE_TYPE_NEED_ITEM;
        $donateId = $existingDonateId;

        if ($donateId > 0) {
            $pend = 'pending';
            $upt = $conn->prepare(
                'UPDATE donation
                 SET amount = ?, payment_status = ?, transfer_datetime = NOW(), donate_type = ?, need_item_picks_json = ?
                 WHERE donate_id = ? AND donor_id = ? AND payment_status = ? AND omise_charge_id = ?'
            );
            if (!$upt) {
                throw new RuntimeException('update donation');
            }
            $upt->bind_param('dsssiiss', $amountBaht, $completed, $dtNeed, $picksJson, $donateId, $donorUserId, $pend, $chargeId);
            $upt->execute();
            if ($upt->affected_rows < 1) {
                throw new RuntimeException('update donation');
            }
        } else {
            if (!drawdream_donation_has_need_item_picks_column($conn)) {
                throw new RuntimeException('schema');
            }
            $ins = $conn->prepare(
                'INSERT INTO donation (
                    category_id, target_id, donor_id, amount, payment_status, transfer_datetime,
                    omise_charge_id, donate_type, need_item_picks_json
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('insert donation');
            }
            $ins->bind_param('iiidssss', $categoryId, $foundationId, $donorUserId, $amountBaht, $completed, $chargeId, $dtNeed, $picksJson);
            $ins->execute();
            if ($ins->affected_rows < 1) {
                throw new RuntimeException('insert donation');
            }
            $donateId = (int)$conn->insert_id;
        }

        if ($donateId <= 0) {
            throw new RuntimeException('donate id');
        }

        $bump = drawdream_needlist_bump_open_items($conn, $foundationId, $amountBaht, $donateId, $chargeId);
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
        if (str_starts_with($msg, 'needlist item:')) {
            return substr($msg, strlen('needlist item:'));
        }

        return 'error';
    }
}

/**
 * @param array<string,int> $picks
 * @return string DRAWDREAM_PROJECT_FINALIZE_* หรือ 'error'
 */
function drawdream_finalize_needlist_donation(
    mysqli $conn,
    int $foundationId,
    int $donateIdParam,
    string $chargeId,
    float $amountBaht,
    int $donorUserId,
    array $picks = []
): string {
    if ($picks === []) {
        return 'error';
    }

    $pend = 'pending';
    $pt = $conn->prepare(
        'SELECT donate_id, donor_id, target_id
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
    if ($ptDonateId <= 0) {
        return 'error';
    }
    if ((int)($ptRow['donor_id'] ?? 0) !== $donorUserId || (int)($ptRow['target_id'] ?? 0) !== $foundationId) {
        return 'error';
    }

    return drawdream_needlist_complete_successful_payment(
        $conn,
        $foundationId,
        $donorUserId,
        $chargeId,
        $amountBaht,
        $picks,
        $ptDonateId
    );
}

/**
 * @return array<string,int>
 */
function drawdream_pending_needlist_picks_from_session(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $picks = $_SESSION['pending_need_item_picks'] ?? null;

    return is_array($picks) ? $picks : [];
}

/**
 * อ่าน picks จาก session หรือ need_item_picks_json ใน donation (กัน session หลุดตอน poll QR)
 *
 * @return array<string,int>
 */
function drawdream_pending_needlist_picks_resolve(mysqli $conn, string $chargeId): array
{
    $fromSession = drawdream_pending_needlist_picks_from_session();
    if ($fromSession !== []) {
        return $fromSession;
    }
    $chargeId = trim($chargeId);
    if ($chargeId === '') {
        return [];
    }
    drawdream_payment_transaction_ensure_schema($conn);
    if (!drawdream_donation_has_need_item_picks_column($conn)) {
        return [];
    }
    $st = $conn->prepare('SELECT need_item_picks_json FROM donation WHERE omise_charge_id = ? LIMIT 1');
    if (!$st) {
        return [];
    }
    $st->bind_param('s', $chargeId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return drawdream_need_decode_picks_json((string)($row['need_item_picks_json'] ?? ''));
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
