<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$maxRs = $conn->query('SELECT COALESCE(MAX(notif_id), 0) AS m FROM notifications');
$maxId = $maxRs ? (int)($maxRs->fetch_assoc()['m'] ?? 0) : 0;
$next = $maxId + 1;
$conn->query("ALTER TABLE notifications AUTO_INCREMENT = {$next}");
$ai = $conn->query("SHOW TABLE STATUS LIKE 'notifications'")->fetch_assoc();
echo "max_notif_id={$maxId}\n";
echo 'AUTO_INCREMENT=' . (string)($ai['Auto_increment'] ?? '?') . "\n";
