<?php
/**
 * Simulate admin approve for one pending needlist (server-side only)
 * Usage: php tools/simulate_needlist_approve.php <item_id>
 */
declare(strict_types=1);

$itemId = isset($argv[1]) ? (int)$argv[1] : 0;
$commit = in_array('--commit', $argv ?? [], true);
if ($itemId <= 0) {
    fwrite(STDERR, "Usage: php tools/simulate_needlist_approve.php <item_id>\n");
    exit(1);
}

require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';

$st = $conn->prepare("SELECT * FROM foundation_needlist WHERE item_id = ? AND LOWER(TRIM(approve_item))='pending' LIMIT 1");
$st->bind_param('i', $itemId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) {
    echo "No pending row for item_id={$itemId}\n";
    exit(2);
}

$submittedTotal = (float)($row['submitted_total_price'] ?? 0);
if ($submittedTotal <= 0) {
    $submittedTotal = (float)($row['total_price'] ?? 0);
}

$lineItems = foundation_needlist_review_line_items_from_row($row);
$adminTotalPrice = $submittedTotal;
$needItemsJson = null;
$needItemsPricingJson = null;

if ($lineItems !== []) {
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
        $needItemsJson = $encoded['items_json'] ?? null;
        $needItemsPricingJson = $encoded['pricing_json'] ?? null;
    }
} else {
    $payload = foundation_needlist_approval_payload_from_row($row, $submittedTotal > 0 ? $submittedTotal : null);
    $adminTotalPrice = (float)($payload['total'] ?? 0);
    $needItemsJson = $payload['items_json'] ?? null;
    $needItemsPricingJson = $payload['pricing_json'] ?? null;
}

$readyRow = $row;
if (is_string($needItemsPricingJson) && $needItemsPricingJson !== '') {
    $readyRow['need_items_pricing_json'] = $needItemsPricingJson;
}
if (is_string($needItemsJson) && $needItemsJson !== '') {
    $readyRow['need_items_json'] = $needItemsJson;
}
$readyRow['total_price'] = $adminTotalPrice;

if (!foundation_needlist_is_donation_ready($readyRow)) {
    echo "NOT READY: " . foundation_needlist_readiness_message_th($readyRow) . "\n";
    exit(3);
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
$newStatus = 'approved';

$conn->begin_transaction();

$hasJson = is_string($needItemsPricingJson) && $needItemsPricingJson !== '';
if ($hasJson) {
    $itemsJsonBind = is_string($needItemsJson) && $needItemsJson !== '' ? $needItemsJson : '[]';
    $stmt = $conn->prepare("
        UPDATE foundation_needlist
        SET approve_item=?,
            submitted_total_price = COALESCE(submitted_total_price, total_price),
            submitted_need_items_pricing_json = COALESCE(submitted_need_items_pricing_json, need_items_pricing_json),
            total_price=?,
            price_reviewed_at=NOW(),
            need_items_json = ?,
            need_items_pricing_json = ?,
            donate_window_end_at=?
        WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
    ");
    if (!$stmt) {
        echo "Prepare failed: {$conn->error}\n";
        exit(4);
    }
    $stmt->bind_param('sdsssi', $newStatus, $adminTotalPrice, $itemsJsonBind, $needItemsPricingJson, $donateEndSql, $itemId);
} else {
    $stmt = $conn->prepare("
        UPDATE foundation_needlist
        SET approve_item=?,
            submitted_total_price = COALESCE(submitted_total_price, total_price),
            submitted_need_items_pricing_json = COALESCE(submitted_need_items_pricing_json, need_items_pricing_json),
            total_price=?,
            price_reviewed_at=NOW(),
            donate_window_end_at=?
        WHERE item_id=? AND LOWER(TRIM(approve_item))='pending'
    ");
    if (!$stmt) {
        echo "Prepare failed: {$conn->error}\n";
        exit(4);
    }
    $stmt->bind_param('sdsi', $newStatus, $adminTotalPrice, $donateEndSql, $itemId);
}

if (!$stmt->execute()) {
    echo "Execute failed: {$stmt->error}\n";
    exit(5);
}
echo "dry_run=" . ($commit ? 'no' : 'yes') . " affected_rows={$stmt->affected_rows}\n";
if ($stmt->affected_rows < 1) {
    echo "UPDATE matched no rows\n";
    exit(6);
}
if (!$commit) {
    $conn->rollback();
    echo "OK (rolled back) — use --commit to persist\n";
    exit(0);
}
$conn->commit();
echo "APPROVED item_id={$itemId} total={$adminTotalPrice} donate_end={$donateEndSql}\n";
