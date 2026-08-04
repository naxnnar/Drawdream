<?php
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/drawdream_needlist_schema.php';
$funcs = [
    'foundation_needlist_item_filenames_from_row',
    'foundation_needlist_review_line_items_from_row',
    'foundation_needlist_approval_payload_from_row',
    'foundation_needlist_is_donation_ready',
];
foreach ($funcs as $f) {
    echo $f . ': ' . (function_exists($f) ? 'yes' : 'NO') . "\n";
}
