<?php
declare(strict_types=1);
$id = (int)($argv[1] ?? 0);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
$st = $conn->prepare('SELECT * FROM foundation_needlist WHERE item_id = ?');
$st->bind_param('i', $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) { echo "not found\n"; exit(1); }
echo "total_price={$row['total_price']} submitted_total={$row['submitted_total_price']}\n";
echo "need_items_pricing_json:\n{$row['need_items_pricing_json']}\n\n";
echo "submitted_need_items_pricing_json:\n{$row['submitted_need_items_pricing_json']}\n\n";
$admin = foundation_needlist_admin_line_items_from_row($row);
$sub = foundation_needlist_submitted_line_items_from_row($row);
echo 'admin_lines_total=' . array_sum(array_column($admin, 'line_total')) . "\n";
echo 'submitted_lines_total=' . array_sum(array_column($sub, 'line_total')) . "\n";
echo 'submitted_lines_count=' . count($sub) . " admin_lines_count=" . count($admin) . "\n";
$st2 = $conn->prepare('SELECT need_items_json FROM foundation_needlist WHERE item_id = ?');
$st2->bind_param('i', $id);
$st2->execute();
$itemsJson = (string)($st2->get_result()->fetch_assoc()['need_items_json'] ?? '');
echo "need_items_json:\n{$itemsJson}\n";
