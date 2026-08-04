<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

echo "=== Users + profiles ===\n";
$r = $conn->query('SELECT u.user_id, u.email, u.role, d.user_id AS donor_uid, fp.user_id AS fp_uid, fp.foundation_id
    FROM `user` u
    LEFT JOIN donor d ON d.user_id = u.user_id
    LEFT JOIN foundation_profile fp ON fp.user_id = u.user_id
    ORDER BY u.user_id');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Orphan donor ===\n";
$r2 = $conn->query('SELECT d.user_id FROM donor d LEFT JOIN `user` u ON u.user_id = d.user_id WHERE u.user_id IS NULL');
echo 'count=' . ($r2 ? $r2->num_rows : 0) . "\n";

echo "\n=== Orphan foundation_profile ===\n";
$r3 = $conn->query('SELECT fp.foundation_id, fp.user_id FROM foundation_profile fp LEFT JOIN `user` u ON u.user_id = fp.user_id WHERE u.user_id IS NULL');
echo 'count=' . ($r3 ? $r3->num_rows : 0) . "\n";

echo "\n=== Stale refs to old user ids 7-10 ===\n";
$tables = [
    'donor' => ['user_id'],
    'foundation_profile' => ['user_id'],
    'donation' => ['donor_id'],
    'notifications' => ['user_id'],
    'child_subscription_history' => ['donor_user_id'],
    'user_presence' => ['user_id'],
    'admin' => ['admin_id', 'notif_recipient_user_id'],
    'foundation_needlist' => ['reviewed_by_user_id', 'price_reviewed_by_user_id', 'created_by_user_id'],
    'notification_read_state' => ['user_id'],
    'payment_transaction' => ['donor_id'],
];
foreach ($tables as $table => $cols) {
    $chk = @$conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$chk || $chk->num_rows === 0) {
        continue;
    }
    foreach ($cols as $col) {
        $cchk = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (!$cchk || $cchk->num_rows === 0) {
            continue;
        }
        $q = $conn->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$col}` IN (7,8,9,10)");
        $c = (int)($q->fetch_assoc()['c'] ?? 0);
        if ($c > 0) {
            echo "{$table}.{$col}: {$c}\n";
            $sample = $conn->query("SELECT * FROM `{$table}` WHERE `{$col}` IN (7,8,9,10) LIMIT 5");
            while ($sample && ($srow = $sample->fetch_assoc())) {
                echo '  ' . json_encode($srow, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
}

echo "\n=== All donor rows ===\n";
$rAllDonor = $conn->query('SELECT * FROM donor ORDER BY user_id ASC');
while ($rAllDonor && ($row = $rAllDonor->fetch_assoc())) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Donors missing profile row ===\n";
$r4 = $conn->query("SELECT u.user_id, u.email FROM `user` u LEFT JOIN donor d ON d.user_id = u.user_id WHERE u.role = 'donor' AND d.user_id IS NULL");
while ($r4 && ($row = $r4->fetch_assoc())) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Foundations missing profile row ===\n";
$r5 = $conn->query("SELECT u.user_id, u.email FROM `user` u LEFT JOIN foundation_profile fp ON fp.user_id = u.user_id WHERE u.role = 'foundation' AND fp.user_id IS NULL");
while ($r5 && ($row = $r5->fetch_assoc())) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Admin table ===\n";
$r6 = @$conn->query('SELECT id, admin_id, target_entity, target_id, notif_recipient_user_id FROM `admin` ORDER BY id DESC LIMIT 10');
if ($r6) {
    while ($row = $r6->fetch_assoc()) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
