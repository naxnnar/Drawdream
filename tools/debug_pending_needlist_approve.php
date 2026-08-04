<?php
/**
 * Debug pending needlist approval blockers
 * Usage: php tools/debug_pending_needlist_approve.php [item_id]
 */
declare(strict_types=1);

require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';

$focusId = isset($argv[1]) ? (int)$argv[1] : 0;

$sql = "SELECT * FROM foundation_needlist WHERE LOWER(TRIM(COALESCE(approve_item,''))) = 'pending'";
if ($focusId > 0) {
    $sql .= ' AND item_id = ' . $focusId;
}
$sql .= ' ORDER BY item_id DESC LIMIT 10';

$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: {$conn->error}\n");
    exit(1);
}

if ($res->num_rows === 0) {
    echo "No pending needlist rows.\n";
    exit(0);
}

while ($row = $res->fetch_assoc()) {
    $id = (int)($row['item_id'] ?? 0);
    echo "=== item_id={$id} ===\n";
    echo 'item_name: ' . (string)($row['item_name'] ?? '') . "\n";
    echo 'approve_item: ' . (string)($row['approve_item'] ?? '') . "\n";
    echo 'total_price: ' . (string)($row['total_price'] ?? '') . "\n";
    echo 'submitted_total_price: ' . (string)($row['submitted_total_price'] ?? '') . "\n";
    echo 'qty_needed: ' . (string)($row['qty_needed'] ?? '') . "\n";
    echo 'item_image: ' . substr((string)($row['item_image'] ?? ''), 0, 80) . "\n";
    echo 'need_foundation_image: ' . (string)($row['need_foundation_image'] ?? '') . "\n";
    echo 'need_items_json len: ' . strlen((string)($row['need_items_json'] ?? '')) . "\n";

    $review = foundation_needlist_review_line_items_from_row($row);
    $picker = foundation_needlist_donor_picker_lines_from_row($row);
    $payload = foundation_needlist_approval_payload_from_row($row);
    $ready = foundation_needlist_is_donation_ready($row);
    $issues = foundation_needlist_readiness_message_th($row);

    echo 'review_lines: ' . count($review) . "\n";
    echo 'picker_lines: ' . count($picker) . "\n";
    echo 'payload_total: ' . (float)($payload['total'] ?? 0) . "\n";
    echo 'ready_now: ' . ($ready ? 'yes' : 'no') . "\n";
    if ($issues !== '') {
        echo 'issues: ' . $issues . "\n";
    }

    if ($payload['total'] > 0) {
        $sim = $row;
        if (is_string($payload['items_json']) && $payload['items_json'] !== '') {
            $sim['need_items_json'] = $payload['items_json'];
        }
        if (is_string($payload['pricing_json']) && $payload['pricing_json'] !== '') {
            $sim['need_items_pricing_json'] = $payload['pricing_json'];
        }
        $sim['total_price'] = (float)$payload['total'];
        $readyAfter = foundation_needlist_is_donation_ready($sim);
        echo 'ready_after_payload: ' . ($readyAfter ? 'yes' : 'no') . "\n";
        if (!$readyAfter) {
            echo 'issues_after_payload: ' . foundation_needlist_readiness_message_th($sim) . "\n";
        }
    }

    if ($review !== []) {
        echo "review sample:\n";
        echo json_encode($review[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    echo "\n";
}
