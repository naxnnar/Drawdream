<?php
declare(strict_types=1);
$id = (int)($argv[1] ?? 1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
$t0 = microtime(true);
$st = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ? LIMIT 1');
$st->bind_param('i', $id);
$st->execute();
$n = $st->get_result()->fetch_assoc();
echo 'fetch ' . round(microtime(true) - $t0, 3) . "s\n";
if (!$n) { echo "not found\n"; exit(1); }
echo 'json_lens items=' . strlen((string)($n['need_items_json'] ?? '')) . ' pricing=' . strlen((string)($n['need_items_pricing_json'] ?? '')) . ' sub_pricing=' . strlen((string)($n['submitted_need_items_pricing_json'] ?? '')) . ' sub_items=' . strlen((string)($n['submitted_need_items_json'] ?? '')) . "\n";
$t1 = microtime(true);
$admin = foundation_needlist_admin_line_items_from_row($n);
echo 'admin lines=' . count($admin) . ' ' . round(microtime(true) - $t1, 3) . "s\n";
$t2 = microtime(true);
$sub = foundation_needlist_submitted_line_items_from_row($n);
echo 'sub lines=' . count($sub) . ' ' . round(microtime(true) - $t2, 3) . "s\n";
