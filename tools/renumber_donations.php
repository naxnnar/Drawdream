<?php
/**
 * CLI: รีเซ็ต donate_id ให้เรียง 1..N ตามลำดับ donate_id เดิม (หรือ transfer_datetime)
 * อัปเดต child_subscription_history + ลิงก์ใบเสร็จใน notifications
 *
 * Usage:
 *   php tools/renumber_donations.php --dry-run
 *   php tools/renumber_donations.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;

$rs = $conn->query(
    "SELECT donate_id, payment_status, amount, transfer_datetime, omise_charge_id
     FROM donation
     ORDER BY COALESCE(transfer_datetime, '1970-01-01') ASC, donate_id ASC"
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
    echo "donation table is empty — set AUTO_INCREMENT=1\n";
    if ($apply) {
        $conn->query('ALTER TABLE donation AUTO_INCREMENT = 1');
    }
    exit(0);
}

echo 'Current rows: ' . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "  id=%d status=%s amount=%s %s\n",
        (int)$row['donate_id'],
        (string)($row['payment_status'] ?? ''),
        (string)($row['amount'] ?? ''),
        (string)($row['transfer_datetime'] ?? '-')
    );
}

$mapping = [];
$newId = 1;
foreach ($rows as $row) {
    $oldId = (int)$row['donate_id'];
    if ($oldId !== $newId) {
        $mapping[$oldId] = $newId;
    }
    $newId++;
}
$nextAuto = count($rows) + 1;

if ($mapping === []) {
    echo "Already sequential 1.." . count($rows) . " — AUTO_INCREMENT should be {$nextAuto}\n";
    if ($apply) {
        $conn->query("ALTER TABLE donation AUTO_INCREMENT = {$nextAuto}");
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
        $st = $conn->prepare('UPDATE donation SET donate_id = ? WHERE donate_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $tmp, $oldId);
        $st->execute();
    }

    foreach ($mapping as $oldId => $newId) {
        $tmp = 1000000 + (int)$oldId;

        $st = $conn->prepare('UPDATE donation SET donate_id = ? WHERE donate_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $newId, $tmp);
        $st->execute();

        if ($conn->query("SHOW TABLES LIKE 'child_subscription_history'")?->num_rows) {
            $stHist = $conn->prepare('UPDATE child_subscription_history SET donate_id = ? WHERE donate_id = ?');
            if ($stHist) {
                $stHist->bind_param('ii', $newId, $oldId);
                $stHist->execute();
            }
        }

        $oldLink = 'donation_receipt.php?donate_id=' . $oldId;
        $newLink = 'donation_receipt.php?donate_id=' . $newId;
        $stNotif = $conn->prepare('UPDATE notifications SET link = ? WHERE link = ?');
        if ($stNotif) {
            $stNotif->bind_param('ss', $newLink, $oldLink);
            $stNotif->execute();
        }
        $likeOld = '%donate_id=' . $oldId . '%';
        $conn->query(
            "UPDATE notifications SET link = REPLACE(link, 'donate_id={$oldId}', 'donate_id={$newId}') WHERE link LIKE '" . $conn->real_escape_string($likeOld) . "'"
        );
    }

    if (!$conn->query("ALTER TABLE donation AUTO_INCREMENT = {$nextAuto}")) {
        throw new RuntimeException($conn->error);
    }

    $maxRs = $conn->query('SELECT COALESCE(MAX(donate_id), 0) AS m FROM donation');
    $maxId = $maxRs ? (int)($maxRs->fetch_assoc()['m'] ?? 0) : 0;
    $forcedNext = max($nextAuto, $maxId + 1);
    $conn->query("ALTER TABLE donation AUTO_INCREMENT = {$forcedNext}");

    $conn->commit();
    echo "\nApplied successfully.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$verify = $conn->query('SELECT donate_id, payment_status, amount FROM donation ORDER BY donate_id ASC');
echo "\nAfter:\n";
while ($verify && ($v = $verify->fetch_assoc())) {
    echo sprintf(
        "  id=%d status=%s amount=%s\n",
        (int)$v['donate_id'],
        (string)($v['payment_status'] ?? ''),
        (string)($v['amount'] ?? '')
    );
}
