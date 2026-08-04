<?php
/**
 * Trace admin needlist approve POST validation (CLI)
 * Usage: php tools/debug_needlist_approve_post.php <item_id>
 */
declare(strict_types=1);

$itemId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($itemId <= 0) {
    fwrite(STDERR, "Usage: php tools/debug_needlist_approve_post.php <item_id>\n");
    exit(1);
}

require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
require_once __DIR__ . '/../includes/needlist_donate_window.php';
drawdream_ensure_needlist_schema($conn);

function debug_admin_needlist_pricing_from_post(array $lineItems, array $post, array $namePool): array
{
    $rowsForEncode = [];
    foreach ($lineItems as $idx => $li) {
        $slot = (int)($li['slot'] ?? 0);
        $qty = (float)($li['qty'] ?? 0);
        if ($slot <= 0 || $qty <= 0) {
            continue;
        }
        $rawPrice = str_replace([',', ' '], '', trim((string)($post['item_price_' . $slot] ?? '')));
        if ($rawPrice === '') {
            $rawPrice = str_replace([',', ' '], '', trim((string)($post['item_price_idx_' . $idx] ?? '')));
        }
        if ($rawPrice === '') {
            $rawPrice = (string)($li['price'] ?? '0');
        }
        $newPrice = drawdream_needlist_round_money((float)$rawPrice);
        if ($newPrice <= 0) {
            return ['error' => "ราคารายการที่ {$slot} ต้องมากกว่า 0", 'total' => 0.0, 'items_json' => null, 'pricing_json' => null];
        }
        $itemLabel = trim((string)($li['item_name'] ?? ''));
        if ($itemLabel === '') {
            $itemLabel = trim((string)($namePool[$idx] ?? ''));
        }
        $rowsForEncode[] = [
            'slot' => $slot,
            'category' => (string)($li['category'] ?? ''),
            'item_name' => $itemLabel,
            'qty' => $qty,
            'price' => $newPrice,
        ];
    }
    if ($rowsForEncode === []) {
        return ['error' => 'ไม่พบรายการสิ่งของย่อยสำหรับปรับราคา', 'total' => 0.0, 'items_json' => null, 'pricing_json' => null];
    }
    $encoded = foundation_needlist_encode_line_items_json($rowsForEncode);
    return [
        'error' => '',
        'total' => $encoded['total'],
        'items_json' => $encoded['items_json'],
        'pricing_json' => $encoded['pricing_json'],
    ];
}

$st = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? LIMIT 1');
$st->bind_param('i', $itemId);
$st->execute();
$oldRow = $st->get_result()->fetch_assoc();
if (!$oldRow) {
    echo "not found\n";
    exit(2);
}

$lineItemsApprove = foundation_needlist_review_line_items_from_row($oldRow);
$post = ['item_id' => (string)$itemId, 'action' => 'approve'];
foreach ($lineItemsApprove as $idx => $li) {
    $slot = (int)($li['slot'] ?? 0);
    $price = number_format((float)($li['price'] ?? 0), 2, '.', '');
    if ($slot > 0) {
        $post['item_price_' . $slot] = $price;
    }
    $post['item_price_idx_' . $idx] = $price;
}

$error = '';
$action = strtolower(trim((string)($post['action'] ?? '')));
$newStatus = ($action === 'approve') ? 'approved' : null;
$submittedTotal = (float)($oldRow['submitted_total_price'] ?? 0);
if ($submittedTotal <= 0) {
    $submittedTotal = (float)($oldRow['total_price'] ?? 0);
}

$adminTotalPrice = null;
$needItemsPricingJson = null;
$needItemsJson = null;
$namePool = array_values(array_filter(array_map('trim', explode(',', (string)($oldRow['item_name'] ?? '')))));
$built = debug_admin_needlist_pricing_from_post($lineItemsApprove, $post, $namePool);
echo 'built_error=' . ($built['error'] !== '' ? $built['error'] : '(none)') . "\n";
if ($built['error'] === '') {
    $adminTotalPrice = $built['total'];
    $needItemsPricingJson = $built['pricing_json'];
    $needItemsJson = $built['items_json'];
}
if ($needItemsJson === null || $needItemsPricingJson === null || $adminTotalPrice === null || (float)$adminTotalPrice <= 0) {
    $payload = foundation_needlist_approval_payload_from_row($oldRow, $submittedTotal > 0 ? $submittedTotal : null);
    if ($payload['total'] > 0) {
        $adminTotalPrice = (float)$payload['total'];
        $needItemsJson = $payload['items_json'];
        $needItemsPricingJson = $payload['pricing_json'];
    }
}
echo 'admin_total=' . (string)$adminTotalPrice . "\n";
echo 'has_json=' . (is_string($needItemsPricingJson) && $needItemsPricingJson !== '' ? 'yes' : 'no') . "\n";

$reviewedAtRaw = trim((string)($oldRow['created_at'] ?? ''));
try {
    $from = ($reviewedAtRaw !== '' && !str_starts_with($reviewedAtRaw, '0000-00-00'))
        ? new DateTimeImmutable($reviewedAtRaw)
        : new DateTimeImmutable('now');
} catch (Throwable $e) {
    $from = new DateTimeImmutable('now');
}
$donateEndSql = drawdream_needlist_compute_donate_window_end('', $from);
echo 'donate_end=' . (string)($donateEndSql ?? 'NULL') . "\n";

$conn->begin_transaction();
$hasJson = is_string($needItemsPricingJson) && $needItemsPricingJson !== '';
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
$newStatus = 'approved';
$stmt->bind_param('sdsssi', $newStatus, $adminTotalPrice, $itemsJsonBind, $needItemsPricingJson, $donateEndSql, $itemId);
$stmt->execute();
echo 'affected_rows=' . $stmt->affected_rows . ' err=' . $stmt->error . "\n";
$conn->rollback();

echo 'approve_item_now=' . (string)($oldRow['approve_item'] ?? '') . "\n";
