<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/navbar_notifications.php';

$uid = 3;
$bundle = drawdream_navbar_notifications_bundle($conn, $uid, 'foundation', '', true);
echo 'bundle_count=' . (int)$bundle['count'] . PHP_EOL;
echo 'html_len=' . strlen((string)$bundle['html']) . PHP_EOL;

$st = $conn->prepare('SELECT notif_id, is_read, link FROM notifications WHERE user_id = ? ORDER BY notif_id');
$st->bind_param('i', $uid);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) {
    echo (int)$row['notif_id'] . ' read=' . (int)$row['is_read'] . ' link=' . (string)$row['link'] . PHP_EOL;
}

$ai = $conn->query("SHOW TABLE STATUS LIKE 'notifications'")->fetch_assoc();
echo 'AUTO_INCREMENT=' . (string)($ai['Auto_increment'] ?? '?') . PHP_EOL;

// mark_read simulation (rollback)
$conn->begin_transaction();
$stMark = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notif_id = 1 AND user_id = ?');
$stMark->bind_param('i', $uid);
$stMark->execute();
echo 'mark_read_test_affected=' . $stMark->affected_rows . PHP_EOL;
$conn->rollback();
echo "mark_read_logic_ok\n";
