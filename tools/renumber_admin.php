<?php
/**
 * CLI: รีเซ็ต id ในตาราง admin ให้เรียง 1..N ตาม action_at/id เดิม
 *
 * Usage:
 *   php tools/renumber_admin.php --dry-run
 *   php tools/renumber_admin.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;

$tbl = @$conn->query("SHOW TABLES LIKE 'admin'");
if (!$tbl || $tbl->num_rows === 0) {
    echo "admin table not found — skip\n";
    exit(0);
}

$rs = $conn->query(
    "SELECT id, admin_id, target_id, target_entity, notif_type, action_at
     FROM `admin`
     ORDER BY COALESCE(action_at, '1970-01-01') ASC, id ASC"
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
    echo "admin table is empty — set AUTO_INCREMENT=1\n";
    if ($apply) {
        $conn->query('ALTER TABLE `admin` AUTO_INCREMENT = 1');
    }
    exit(0);
}

echo 'Current rows: ' . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "  id=%d admin_id=%s target=%s:%s type=%s %s\n",
        (int)$row['id'],
        $row['admin_id'] !== null ? (string)(int)$row['admin_id'] : 'NULL',
        (string)($row['target_entity'] ?? ''),
        (string)($row['target_id'] ?? ''),
        (string)($row['notif_type'] ?? ''),
        (string)($row['action_at'] ?? '')
    );
}

$mapping = [];
$newId = 1;
foreach ($rows as $row) {
    $oldId = (int)$row['id'];
    if ($oldId !== $newId) {
        $mapping[$oldId] = $newId;
    }
    $newId++;
}
$nextAuto = count($rows) + 1;

if ($mapping === []) {
    echo "Already sequential 1.." . count($rows) . " — AUTO_INCREMENT should be {$nextAuto}\n";
    if ($apply) {
        $conn->query("ALTER TABLE `admin` AUTO_INCREMENT = {$nextAuto}");
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
        $st = $conn->prepare('UPDATE `admin` SET id = ? WHERE id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $tmp, $oldId);
        $st->execute();
    }

    foreach ($mapping as $oldId => $newId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE `admin` SET id = ? WHERE id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $newId, $tmp);
        $st->execute();
    }

    if (!$conn->query("ALTER TABLE `admin` AUTO_INCREMENT = {$nextAuto}")) {
        throw new RuntimeException($conn->error);
    }

    $maxRs = $conn->query('SELECT COALESCE(MAX(id), 0) AS m FROM `admin`');
    $maxId = $maxRs ? (int)($maxRs->fetch_assoc()['m'] ?? 0) : 0;
    $forcedNext = max($nextAuto, $maxId + 1);
    $conn->query("ALTER TABLE `admin` AUTO_INCREMENT = {$forcedNext}");

    $conn->commit();
    echo "\nApplied successfully.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$verify = $conn->query('SELECT id, target_entity, target_id, notif_type FROM `admin` ORDER BY id ASC');
echo "\nAfter:\n";
while ($verify && ($v = $verify->fetch_assoc())) {
    echo sprintf(
        "  id=%d target=%s:%s type=%s\n",
        (int)$v['id'],
        (string)($v['target_entity'] ?? ''),
        (string)($v['target_id'] ?? ''),
        (string)($v['notif_type'] ?? '')
    );
}
