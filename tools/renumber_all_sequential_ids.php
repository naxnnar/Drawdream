<?php
/**
 * CLI: รวม dedup admin + renumber admin / notifications / donation ให้ id ต่อเนื่อง
 *
 * Usage:
 *   php tools/renumber_all_sequential_ids.php --dry-run
 *   php tools/renumber_all_sequential_ids.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_audit_migrate.php';

$apply = in_array('--apply', $argv ?? [], true);
$flag = $apply ? '--apply' : '--dry-run';

echo "=== Step 1: deduplicate admin rows (target_id + target_entity) ===\n";
if ($apply) {
    drawdream_admin_deduplicate_entity_rows($conn);
    echo "Done.\n";
} else {
    $dup = @$conn->query(
        "SELECT target_id, target_entity, COUNT(*) AS c FROM `admin`
         WHERE target_id IS NOT NULL AND TRIM(COALESCE(target_entity,'')) != ''
         GROUP BY target_id, target_entity HAVING c > 1"
    );
    $n = $dup ? $dup->num_rows : 0;
    echo "Would deduplicate {$n} duplicate group(s) on --apply.\n";
}

$scripts = [
    'admin' => __DIR__ . '/renumber_admin.php',
    'notifications' => __DIR__ . '/renumber_notifications.php',
    'donation' => __DIR__ . '/renumber_donations.php',
];

foreach ($scripts as $label => $path) {
    echo "\n=== Step: renumber {$label} ===\n";
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$path}\n");
        exit(1);
    }
    passthru(PHP_BINARY . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($flag), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed: {$label} (exit {$code})\n");
        exit($code);
    }
}

echo "\nAll steps finished.\n";
