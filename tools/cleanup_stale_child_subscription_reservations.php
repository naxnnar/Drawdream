<?php
// tools/cleanup_stale_child_subscription_reservations.php — ลบแถว reserving ค้าง/ซ้ำ
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/child_subscription_history.php';

$childId = isset($argv[1]) ? (int)$argv[1] : 0;
$scoped = $childId > 0 ? ('child_id=' . $childId) : 'all children';
$deleted = drawdream_child_subscription_history_cleanup_stale_reservations(
    $conn,
    $childId > 0 ? $childId : null
);

echo "Removed {$deleted} stale reserving row(s) ({$scoped}).\n";
