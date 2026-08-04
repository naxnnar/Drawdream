<?php
/**
 * CLI: รีเซ็ตเลข notif_id ให้เริ่มจาก 1 ตามลำดับ created_at/notif_id
 * Usage:
 *   php tools/renumber_notifications.php --dry-run
 *   php tools/renumber_notifications.php --apply
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;

$rs = $conn->query('SELECT notif_id, user_id, title, is_read, created_at FROM notifications ORDER BY notif_id ASC');
if (!$rs) {
    fwrite(STDERR, "query failed: {$conn->error}\n");
    exit(1);
}

$rows = [];
while ($row = $rs->fetch_assoc()) {
    $rows[] = $row;
}

if ($rows === []) {
    echo "notifications table is empty — set AUTO_INCREMENT=1\n";
    if ($apply) {
        $conn->query('ALTER TABLE notifications AUTO_INCREMENT = 1');
    }
    exit(0);
}

echo 'Current rows: ' . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "  id=%d user=%d read=%d %s | %s\n",
        (int)$row['notif_id'],
        (int)$row['user_id'],
        (int)$row['is_read'],
        (string)$row['created_at'],
        mb_substr((string)$row['title'], 0, 40)
    );
}

$mapping = [];
$newId = 1;
foreach ($rows as $row) {
    $oldId = (int)$row['notif_id'];
    if ($oldId !== $newId) {
        $mapping[$oldId] = $newId;
    }
    $newId++;
}
$nextAuto = count($rows) + 1;

if ($mapping === []) {
    echo "Already sequential 1.." . count($rows) . " — AUTO_INCREMENT should be {$nextAuto}\n";
    if ($apply) {
        $conn->query("ALTER TABLE notifications AUTO_INCREMENT = {$nextAuto}");
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
    // ช่วงชั่วคราวหลีกเลี่ยง PK ชนกัน
    foreach (array_keys($mapping) as $oldId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE notifications SET notif_id = ? WHERE notif_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $tmp, $oldId);
        $st->execute();
    }

    foreach ($mapping as $oldId => $newId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE notifications SET notif_id = ? WHERE notif_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $newId, $tmp);
        $st->execute();
    }

    if (!$conn->query("ALTER TABLE notifications AUTO_INCREMENT = {$nextAuto}")) {
        throw new RuntimeException($conn->error);
    }

    // บาง MySQL/Aiven อาจไม่ตั้งค่า AUTO_INCREMENT ตามที่สั่ง — บังคับอีกครั้งจาก MAX(id)+1
    $maxRs = $conn->query('SELECT COALESCE(MAX(notif_id), 0) AS m FROM notifications');
    $maxId = $maxRs ? (int)($maxRs->fetch_assoc()['m'] ?? 0) : 0;
    $forcedNext = max($nextAuto, $maxId + 1);
    $conn->query("ALTER TABLE notifications AUTO_INCREMENT = {$forcedNext}");

    $conn->commit();
    echo "\nApplied successfully.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$verify = $conn->query('SELECT notif_id, user_id, title FROM notifications ORDER BY notif_id ASC');
echo "\nAfter:\n";
while ($verify && ($v = $verify->fetch_assoc())) {
    echo sprintf("  id=%d user=%d | %s\n", (int)$v['notif_id'], (int)$v['user_id'], (string)$v['title']);
}
