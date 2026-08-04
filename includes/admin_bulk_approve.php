<?php
// includes/admin_bulk_approve.php — อนุมัติคำขอหลายรายการพร้อมกัน (แอดมิน)

declare(strict_types=1);

/**
 * @return array{approved:int,skipped:int,messages:list<string>}
 */
function drawdream_admin_bulk_approve_foundations(mysqli $conn, int $adminUid, array $ids): array
{
    require_once __DIR__ . '/notification_audit.php';

    $approved = 0;
    $skipped = 0;
    $messages = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));

    foreach ($ids as $foundationId) {
        $stmt = $conn->prepare(
            'UPDATE foundation_profile
             SET account_verified = 1, verified_at = NOW()
             WHERE foundation_id = ? AND account_verified = 0'
        );
        if (!$stmt) {
            $skipped++;
            continue;
        }
        $stmt->bind_param('i', $foundationId);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $skipped++;
            continue;
        }

        $fu = drawdream_foundation_user_id_by_foundation_id($conn, $foundationId);
        drawdream_send_notification(
            $conn,
            $fu,
            'foundation_approved',
            'บัญชีมูลนิธิได้รับการอนุมัติ',
            'คุณสามารถเข้าใช้งานฟีเจอร์เต็มรูปแบบได้แล้ว',
            'project.php?view=foundation',
            'fdn_registration:' . $foundationId
        );
        drawdream_log_admin_action($conn, $adminUid, 'Approve_Foundation', $foundationId, 'bulk', $fu > 0 ? $fu : null, 'foundation_approved');
        $approved++;
    }

    return ['approved' => $approved, 'skipped' => $skipped, 'messages' => $messages];
}

/**
 * @return array{approved:int,skipped:int,messages:list<string>}
 */
function drawdream_admin_bulk_approve_children(mysqli $conn, int $adminUid, array $ids): array
{
    require_once __DIR__ . '/notification_audit.php';

    $approved = 0;
    $skipped = 0;
    $messages = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));

    $stUpd = $conn->prepare(
        "UPDATE foundation_children
         SET approve_profile = 'อนุมัติ', approve_at = NOW()
         WHERE child_id = ?
           AND COALESCE(approve_profile, 'รอดำเนินการ') IN ('รอดำเนินการ', 'กำลังดำเนินการ')"
    );
    if (!$stUpd) {
        return ['approved' => 0, 'skipped' => count($ids), 'messages' => ['อัปเดตโปรไฟล์เด็กไม่สำเร็จ']];
    }

    foreach ($ids as $childId) {
        $stUpd->bind_param('i', $childId);
        $stUpd->execute();
        if ($stUpd->affected_rows < 1) {
            $skipped++;
            continue;
        }

        drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_child:' . $childId);

        $stN = $conn->prepare(
            'SELECT fp.user_id, c.child_name FROM foundation_children c
             INNER JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
             WHERE c.child_id = ? LIMIT 1'
        );
        if ($stN) {
            $stN->bind_param('i', $childId);
            $stN->execute();
            $fr = $stN->get_result()->fetch_assoc();
            $fu = (int)($fr['user_id'] ?? 0);
            $cname = (string)($fr['child_name'] ?? '');
            $childPublicLink = 'children_donate.php?id=' . $childId;
            drawdream_send_notification(
                $conn,
                $fu,
                'child_approved',
                'อนุมัติโปรไฟล์เด็ก',
                'แอดมินอนุมัติโปรไฟล์เด็ก: ' . $cname,
                $childPublicLink,
                'fdn_child:' . $childId
            );
            drawdream_log_admin_action($conn, $adminUid, 'Approve_Child', $childId, 'bulk', $fu > 0 ? $fu : null, 'child_approved');
        }
        $approved++;
    }

    return ['approved' => $approved, 'skipped' => $skipped, 'messages' => $messages];
}

/**
 * @return array{approved:int,skipped:int,messages:list<string>}
 */
function drawdream_admin_bulk_approve_projects(mysqli $conn, int $adminUid, array $ids): array
{
    require_once __DIR__ . '/drawdream_project_status.php';
    require_once __DIR__ . '/notification_audit.php';

    $approved = 0;
    $skipped = 0;
    $messages = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));
    $pend = drawdream_sql_project_is_pending('project_status');
    $newStatus = 'approved';

    foreach ($ids as $projectId) {
        $stmt = $conn->prepare("UPDATE foundation_project SET project_status=? WHERE project_id=? AND {$pend}");
        if (!$stmt) {
            $skipped++;
            continue;
        }
        $stmt->bind_param('si', $newStatus, $projectId);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $skipped++;
            continue;
        }

        drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_project:' . $projectId);
        $stP = $conn->prepare('SELECT foundation_name, project_name FROM foundation_project WHERE project_id = ? LIMIT 1');
        if ($stP) {
            $stP->bind_param('i', $projectId);
            $stP->execute();
            $pr = $stP->get_result()->fetch_assoc();
            $fname = trim((string)($pr['foundation_name'] ?? ''));
            $pname = (string)($pr['project_name'] ?? '');
            $fu = drawdream_foundation_user_id_by_name($conn, $fname);
            $payLink = 'payment/payment_project.php?project_id=' . $projectId;
            drawdream_send_notification(
                $conn,
                $fu,
                'project_approved',
                'โครงการได้รับการอนุมัติ',
                'โครงการ "' . $pname . '" ผ่านการตรวจสอบแล้ว สามารถแชร์ลิงก์ให้ผู้บริจาคได้',
                $payLink
            );
            drawdream_log_admin_action($conn, $adminUid, 'Approve_Project', $projectId, 'bulk', $fu > 0 ? $fu : null, 'project_approved');
        }
        $approved++;
    }

    return ['approved' => $approved, 'skipped' => $skipped, 'messages' => $messages];
}

/**
 * อนุมัติรายการสิ่งของ — ใช้ราคาที่มูลนิธิเสนอ (ไม่ปรับราคาในโหมด bulk)
 *
 * @return array{approved:int,skipped:int,messages:list<string>}
 */
function drawdream_admin_bulk_approve_needs(mysqli $conn, int $adminUid, array $ids): array
{
    require_once __DIR__ . '/drawdream_needlist_schema.php';
    require_once __DIR__ . '/needlist_donate_window.php';
    require_once __DIR__ . '/notification_audit.php';

    $approved = 0;
    $skipped = 0;
    $messages = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));

    foreach ($ids as $itemId) {
        $stRow = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? AND approve_item = \'pending\' LIMIT 1');
        if (!$stRow) {
            $skipped++;
            continue;
        }
        $stRow->bind_param('i', $itemId);
        $stRow->execute();
        $row = $stRow->get_result()->fetch_assoc();
        if (!$row) {
            $skipped++;
            continue;
        }

        if (!foundation_needlist_is_donation_ready($row)) {
            $payload = foundation_needlist_approval_payload_from_row($row);
            if ($payload['total'] > 0) {
                if (is_string($payload['items_json']) && $payload['items_json'] !== '') {
                    $row['need_items_json'] = $payload['items_json'];
                }
                if (is_string($payload['pricing_json']) && $payload['pricing_json'] !== '') {
                    $row['need_items_pricing_json'] = $payload['pricing_json'];
                }
                $row['total_price'] = (float)$payload['total'];
            }
        }
        if (!foundation_needlist_is_donation_ready($row)) {
            $skipped++;
            $iname = trim((string)($row['item_name'] ?? ''));
            $messages[] = ($iname !== '' ? $iname : ('#' . $itemId)) . ': ข้อมูลยังไม่ครบ — ต้องตรวจสอบทีละรายการ';
            continue;
        }

        $submittedTotal = (float)($row['submitted_total_price'] ?? 0);
        if ($submittedTotal <= 0) {
            $submittedTotal = (float)($row['total_price'] ?? 0);
        }
        if ($submittedTotal <= 0) {
            $skipped++;
            $messages[] = '#' . $itemId . ': ไม่มีราคาที่อนุมัติได้';
            continue;
        }

        $needItemsPricingJson = null;
        $needItemsJson = null;
        $adminTotalPrice = $submittedTotal;
        $lineItems = foundation_needlist_review_line_items_from_row($row);
        if ($lineItems === []) {
            $payload = foundation_needlist_approval_payload_from_row($row, $submittedTotal > 0 ? $submittedTotal : null);
            if ($payload['total'] > 0) {
                $adminTotalPrice = (float)$payload['total'];
                $needItemsPricingJson = $payload['pricing_json'] ?? null;
                $needItemsJson = $payload['items_json'] ?? null;
            }
        } elseif ($lineItems !== []) {
            $rowsForEncode = [];
            foreach ($lineItems as $li) {
                $qty = (float)($li['qty'] ?? 0);
                $price = (float)($li['price'] ?? 0);
                if ($qty <= 0 || $price <= 0) {
                    continue;
                }
                $rowsForEncode[] = [
                    'slot' => (int)($li['slot'] ?? 0),
                    'category' => (string)($li['category'] ?? ''),
                    'item_name' => (string)($li['item_name'] ?? ''),
                    'qty' => $qty,
                    'price' => $price,
                ];
            }
            if ($rowsForEncode !== []) {
                $encoded = foundation_needlist_encode_line_items_json($rowsForEncode);
                $adminTotalPrice = (float)($encoded['total'] ?? $submittedTotal);
                $needItemsPricingJson = $encoded['pricing_json'] ?? null;
                $needItemsJson = $encoded['items_json'] ?? null;
            }
        }

        $reviewedAtRaw = trim((string)($row['created_at'] ?? ''));
        try {
            $from = ($reviewedAtRaw !== '' && !str_starts_with($reviewedAtRaw, '0000-00-00'))
                ? new DateTimeImmutable($reviewedAtRaw)
                : new DateTimeImmutable('now');
        } catch (Throwable $e) {
            $from = new DateTimeImmutable('now');
        }
        $donateEndSql = drawdream_needlist_compute_donate_window_end('', $from);
        if ($donateEndSql === null || $donateEndSql === '') {
            $skipped++;
            $messages[] = '#' . $itemId . ': คำนวณวันปิดรับบริจาคไม่ได้';
            continue;
        }

        $newStatus = 'approved';
        $hasJson = is_string($needItemsPricingJson) && $needItemsPricingJson !== '';
        if ($hasJson) {
            $itemsJsonBind = is_string($needItemsJson) && $needItemsJson !== '' ? $needItemsJson : '[]';
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    foundation_original_total_price = COALESCE(foundation_original_total_price, submitted_total_price, total_price),
                    foundation_original_need_items_json = COALESCE(foundation_original_need_items_json, submitted_need_items_json, need_items_json),
                    foundation_original_need_items_pricing_json = COALESCE(foundation_original_need_items_pricing_json, submitted_need_items_pricing_json, need_items_pricing_json),
                    submitted_total_price=?,
                    submitted_need_items_json=?,
                    submitted_need_items_pricing_json=?,
                    total_price=?,
                    price_reviewed_at=NOW(),
                    need_items_json = ?,
                    need_items_pricing_json = ?,
                    donate_window_end_at=?
                WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
            ");
            if (!$stmt) {
                $skipped++;
                continue;
            }
            $stmt->bind_param(
                'sdssdsssi',
                $newStatus,
                $adminTotalPrice,
                $itemsJsonBind,
                $needItemsPricingJson,
                $adminTotalPrice,
                $itemsJsonBind,
                $needItemsPricingJson,
                $donateEndSql,
                $itemId
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE foundation_needlist
                SET approve_item=?,
                    foundation_original_total_price = COALESCE(foundation_original_total_price, submitted_total_price, total_price),
                    foundation_original_need_items_json = COALESCE(foundation_original_need_items_json, submitted_need_items_json, need_items_json),
                    foundation_original_need_items_pricing_json = COALESCE(foundation_original_need_items_pricing_json, submitted_need_items_pricing_json, need_items_pricing_json),
                    submitted_total_price=?,
                    submitted_need_items_pricing_json=?,
                    total_price=?,
                    price_reviewed_at=NOW(),
                    need_items_pricing_json = COALESCE(?, need_items_pricing_json),
                    donate_window_end_at=?
                WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
            ");
            if (!$stmt) {
                $skipped++;
                continue;
            }
            $stmt->bind_param(
                'sdsdssi',
                $newStatus,
                $adminTotalPrice,
                $needItemsPricingJson,
                $adminTotalPrice,
                $needItemsPricingJson,
                $donateEndSql,
                $itemId
            );
        }
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $skipped++;
            continue;
        }

        drawdream_notifications_delete_by_entity_key($conn, 'adm_pending_need:' . $itemId);
        $stFu = $conn->prepare(
            "SELECT fp.user_id, nl.item_name FROM foundation_needlist nl
             INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
             WHERE nl.item_id = ? LIMIT 1"
        );
        if ($stFu) {
            $stFu->bind_param('i', $itemId);
            $stFu->execute();
            $nr = $stFu->get_result()->fetch_assoc();
            $fu = (int)($nr['user_id'] ?? 0);
            $iname = (string)($nr['item_name'] ?? '');
            $finalMsg = 'รายการ "' . $iname . '" ผ่านการตรวจสอบแล้ว (ระบบจะปิดรับบริจาคอัตโนมัติใน 1 เดือน)';
            $finalMsg .= ' ยอดเป้าหมายที่อนุมัติ: ' . number_format((float)$adminTotalPrice, 2) . ' บาท';
            drawdream_send_notification(
                $conn,
                $fu,
                'need_approved',
                'รายการสิ่งของได้รับการอนุมัติ',
                $finalMsg,
                'foundation.php',
                'fdn_need:' . $itemId
            );
            drawdream_log_admin_action($conn, $adminUid, 'Approve_Need', $itemId, 'bulk', $fu > 0 ? $fu : null, 'need_approved');
        }
        $approved++;
    }

    return ['approved' => $approved, 'skipped' => $skipped, 'messages' => $messages];
}

/**
 * @param array<string,list<int>> $groups
 * @return array{approved:int,skipped:int,messages:list<string>}
 */
function drawdream_admin_bulk_approve_all(mysqli $conn, int $adminUid, array $groups): array
{
    $totalApproved = 0;
    $totalSkipped = 0;
    $allMessages = [];

    $map = [
        'foundation' => 'drawdream_admin_bulk_approve_foundations',
        'child' => 'drawdream_admin_bulk_approve_children',
        'project' => 'drawdream_admin_bulk_approve_projects',
        'need' => 'drawdream_admin_bulk_approve_needs',
    ];

    foreach ($map as $key => $fn) {
        $ids = $groups[$key] ?? [];
        if ($ids === []) {
            continue;
        }
        $res = $fn($conn, $adminUid, $ids);
        $totalApproved += (int)($res['approved'] ?? 0);
        $totalSkipped += (int)($res['skipped'] ?? 0);
        foreach ($res['messages'] ?? [] as $msg) {
            $allMessages[] = $msg;
        }
    }

    return ['approved' => $totalApproved, 'skipped' => $totalSkipped, 'messages' => $allMessages];
}
