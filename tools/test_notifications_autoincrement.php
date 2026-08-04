<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$maxRs = $conn->query('SELECT COALESCE(MAX(notif_id), 0) AS m FROM notifications');
$maxId = $maxRs ? (int)($maxRs->fetch_assoc()['m'] ?? 0) : 0;
$next = $maxId + 1;
$conn->query("ALTER TABLE notifications AUTO_INCREMENT = {$next}");

$conn->begin_transaction();
$st = $conn->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (3, '__autoinc_test__', 'x', '')");
$st->execute();
$newId = (int)$conn->insert_id;
$conn->rollback();

$ai = $conn->query("SHOW TABLE STATUS LIKE 'notifications'")->fetch_assoc();
echo "max_notif_id={$maxId}\n";
echo "expected_next={$next}\n";
echo "test_insert_id={$newId}\n";
echo 'AUTO_INCREMENT_status=' . (string)($ai['Auto_increment'] ?? '?') . "\n";
echo ($newId === $next ? "next_id_ok\n" : "next_id_MISMATCH\n");
