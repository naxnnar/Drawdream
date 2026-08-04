<?php
/**
 * ตรวจรายการสิ่งของที่ยังไม่พร้อมเปิดรับบริจาค
 * Usage: php tools/audit_needlist_readiness.php [--approved-only]
 */
declare(strict_types=1);

include __DIR__ . '/../db.php';

$approvedOnly = in_array('--approved-only', $argv ?? [], true);

echo "=== Audit needlist donation readiness ===\n\n";

$where = $approvedOnly
    ? "WHERE LOWER(TRIM(COALESCE(approve_item,''))) IN ('approved','purchasing','done')"
    : '';
$sql = "SELECT nl.*, fp.foundation_name
        FROM foundation_needlist nl
        INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
        {$where}
        ORDER BY nl.foundation_id, nl.item_id";
$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
}

$notReady = [];
$ready = 0;
while ($row = $res->fetch_assoc()) {
    if (foundation_needlist_is_donation_ready($row)) {
        $ready++;
        continue;
    }
    $notReady[] = $row;
}

echo 'Ready: ' . $ready . "\n";
echo 'NOT ready: ' . count($notReady) . "\n\n";

if ($notReady === []) {
    echo "All scanned items are donation-ready.\n";
    exit(0);
}

foreach ($notReady as $row) {
    $iid = (int)($row['item_id'] ?? 0);
    $fid = (int)($row['foundation_id'] ?? 0);
    $fn = (string)($row['foundation_name'] ?? '');
    $ap = (string)($row['approve_item'] ?? '');
    $issues = foundation_needlist_readiness_issues($row);
    echo "--- item_id={$iid} foundation_id={$fid} ({$fn}) status={$ap} ---\n";
    echo '  item_name: ' . (string)($row['item_name'] ?? '') . "\n";
    echo '  total_price: ' . (string)($row['total_price'] ?? '0') . "\n";
    foreach ($issues as $issue) {
        echo '  - ' . $issue . "\n";
    }
    echo "  fix: foundation_add_need.php?item_id={$iid}\n\n";
}

exit(count($notReady) > 0 ? 2 : 0);
