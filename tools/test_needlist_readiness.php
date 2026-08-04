<?php
declare(strict_types=1);
require __DIR__ . '/../includes/drawdream_needlist_schema.php';

$ready = [
    'need_items_json' => '[{"ลำดับ":1,"หมวดหมู่สิ่งของ":"อาหาร","ชื่อสิ่งของ":"นม","จำนวนสิ่งของ":10}]',
    'need_items_pricing_json' => '[{"ลำดับ":1,"ราคาต่อชิ้น":50,"ราคารวม":500}]',
    'item_name' => 'นม',
    'item_image' => 'a.jpg',
    'need_foundation_image' => 'b.jpg',
];
$broken = [
    'need_items_json' => '[]',
    'total_price' => 8200,
    'item_image' => '',
];

$ok = foundation_needlist_is_donation_ready($ready);
$bad = foundation_needlist_is_donation_ready($broken);
echo ($ok ? 'PASS' : 'FAIL') . " ready row\n";
echo ($bad ? 'FAIL' : 'PASS') . " broken row\n";
echo 'issues: ' . foundation_needlist_readiness_message_th($broken) . "\n";
exit(($ok && !$bad) ? 0 : 1);
