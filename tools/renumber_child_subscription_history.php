<?php
/**
 * CLI: รีเซ็ต history_id ให้เรียง 1..N ตามลำดับ history_id เดิม
 *
 * Usage:
 *   php tools/renumber_child_subscription_history.php --dry-run
 *   php tools/renumber_child_subscription_history.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/child_subscription_history.php';

drawdream_child_subscription_history_ensure_schema($conn);

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;

$rs = $conn->query(
    'SELECT history_id, child_id, donor_user_id, current_status, event_type, created_at
     FROM child_subscription_history
     ORDER BY history_id ASC'
);
if (!$rs) {
    fwrite(STDERR, "query failed: {$conn->error}\n");
    exit(1);
}

$rows = [];
while ($row = $rs->fetch_assoc()) {
    $rows[] = $row;
}

if ($rows === []) {
    echo "child_subscription_history is empty — set AUTO_INCREMENT=1\n";
    if ($apply) {
        $conn->query('ALTER TABLE child_subscription_history AUTO_INCREMENT = 1');
    }
    exit(0);
}

echo 'Current rows: ' . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "  history_id=%d child=%d donor=%d status=%s event=%s %s\n",
        (int)$row['history_id'],
        (int)$row['child_id'],
        (int)$row['donor_user_id'],
        (string)($row['current_status'] ?? ''),
        (string)($row['event_type'] ?? ''),
        (string)($row['created_at'] ?? '')
    );
}

$mapping = [];
$newId = 1;
foreach ($rows as $row) {
    $oldId = (int)$row['history_id'];
    if ($oldId !== $newId) {
        $mapping[$oldId] = $newId;
    }
    $newId++;
}
$nextAuto = count($rows) + 1;

if ($mapping === []) {
    echo 'Already sequential 1..' . count($rows) . " — AUTO_INCREMENT should be {$nextAuto}\n";
    if ($apply) {
        $conn->query("ALTER TABLE child_subscription_history AUTO_INCREMENT = {$nextAuto}");
        echo "Set AUTO_INCREMENT={$nextAuto}\n";
    }
    exit(0);
}

echo "\nPlanned mapping:\n";
foreach ($mapping as $old => $new) {
    echo "  {$old} -> {$new}\n";
}
echo "Next AUTO_INCREMENT: {$nextAuto}\n";

if ($dryRun) {
    echo "\nDry-run only. Re-run with --apply to execute.\n";
    exit(0);
}

$conn->begin_transaction();
try {
    foreach (array_keys($mapping) as $oldId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE child_subscription_history SET history_id = ? WHERE history_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $tmp, $oldId);
        $st->execute();
    }

    foreach ($mapping as $oldId => $newId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE child_subscription_history SET history_id = ? WHERE history_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $newId, $tmp);
        $st->execute();
    }

    if (!$conn->query("ALTER TABLE child_subscription_history AUTO_INCREMENT = {$nextAuto}")) {
        throw new RuntimeException($conn->error);
    }

    $conn->commit();
    echo "\nRenumbered successfully.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
