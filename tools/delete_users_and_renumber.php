<?php
/**
 * CLI: ลบ user ตาม id แล้ว renumber user_id ให้ต่อเนื่อง 1..N (+ อัปเดต FK ที่อ้างอิง)
 *
 * Usage:
 *   php tools/delete_users_and_renumber.php --ids=5,6 --dry-run
 *   php tools/delete_users_and_renumber.php --ids=5,6 --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;
$deleteIds = [];

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--ids=')) {
        foreach (explode(',', substr($arg, 6)) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $deleteIds[$id] = true;
            }
        }
    }
}

if ($deleteIds === []) {
    fwrite(STDERR, "Usage: php tools/delete_users_and_renumber.php --ids=5,6 [--dry-run|--apply]\n");
    exit(1);
}

$deleteIds = array_keys($deleteIds);
sort($deleteIds);

function drawdream_tool_table_exists(mysqli $conn, string $table): bool
{
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }
    $r = @$conn->query("SHOW TABLES LIKE '{$safe}'");

    return (bool)($r && $r->num_rows > 0);
}

function drawdream_tool_column_exists(mysqli $conn, string $table, string $column): bool
{
    $safeT = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeC = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($safeT === '' || $safeC === '') {
        return false;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `{$safeT}` LIKE '{$safeC}'");

    return (bool)($r && $r->num_rows > 0);
}

/** @return list<array<string,mixed>> */
function drawdream_tool_fetch_users(mysqli $conn, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT user_id, email, role FROM `user` WHERE user_id IN ({$ph}) ORDER BY user_id ASC");
    if (!$st) {
        return [];
    }
    $types = str_repeat('i', count($ids));
    $st->bind_param($types, ...$ids);
    $st->execute();
    $rows = [];
    $rs = $st->get_result();
    while ($rs && ($row = $rs->fetch_assoc())) {
        $rows[] = $row;
    }

    return $rows;
}

function drawdream_tool_count_for_user(mysqli $conn, int $userId, string $table, string $column): int
{
    if (!drawdream_tool_table_exists($conn, $table) || !drawdream_tool_column_exists($conn, $table, $column)) {
        return 0;
    }
    $safeT = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeC = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM `{$safeT}` WHERE `{$safeC}` = ?");
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $userId);
    $st->execute();

    return (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
}

function drawdream_tool_purge_user(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    if (drawdream_tool_table_exists($conn, 'child_subscription_history')) {
        $st = $conn->prepare('DELETE FROM child_subscription_history WHERE donor_user_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'donation')) {
        $st = $conn->prepare('DELETE FROM donation WHERE donor_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'notifications')) {
        $st = $conn->prepare('DELETE FROM notifications WHERE user_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'admin')) {
        $st = $conn->prepare('UPDATE `admin` SET admin_id = NULL WHERE admin_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
        $st2 = $conn->prepare('UPDATE `admin` SET notif_recipient_user_id = NULL WHERE notif_recipient_user_id = ?');
        if ($st2) {
            $st2->bind_param('i', $userId);
            $st2->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'user_presence')) {
        $st = $conn->prepare('DELETE FROM user_presence WHERE user_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'foundation_profile')) {
        $st = $conn->prepare('DELETE FROM foundation_profile WHERE user_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    if (drawdream_tool_table_exists($conn, 'donor')) {
        $st = $conn->prepare('DELETE FROM donor WHERE user_id = ?');
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
        }
    }

    $stUser = $conn->prepare('DELETE FROM `user` WHERE user_id = ?');
    if (!$stUser) {
        throw new RuntimeException($conn->error);
    }
    $stUser->bind_param('i', $userId);
    $stUser->execute();
    if ($stUser->affected_rows < 1) {
        throw new RuntimeException("user_id {$userId} not deleted");
    }
}

/** @param array<int,int> $mapping old => new */
function drawdream_tool_apply_user_id_mapping(mysqli $conn, array $mapping): void
{
    if ($mapping === []) {
        return;
    }

    $refUpdates = [
        ['donor', 'user_id'],
        ['foundation_profile', 'user_id'],
        ['donation', 'donor_id'],
        ['notifications', 'user_id'],
        ['admin', 'admin_id'],
        ['admin', 'notif_recipient_user_id'],
        ['child_subscription_history', 'donor_user_id'],
        ['user_presence', 'user_id'],
        ['foundation_needlist', 'reviewed_by_user_id'],
        ['foundation_needlist', 'price_reviewed_by_user_id'],
        ['foundation_needlist', 'created_by_user_id'],
        ['notification_read_state', 'user_id'],
        ['payment_transaction', 'donor_id'],
    ];

    foreach (array_keys($mapping) as $oldId) {
        $tmp = 1000000 + (int)$oldId;
        $st = $conn->prepare('UPDATE `user` SET user_id = ? WHERE user_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $tmp, $oldId);
        $st->execute();
    }

    foreach ($mapping as $oldId => $newId) {
        $tmp = 1000000 + (int)$oldId;

        $st = $conn->prepare('UPDATE `user` SET user_id = ? WHERE user_id = ?');
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ii', $newId, $tmp);
        $st->execute();

        foreach ($refUpdates as [$table, $column]) {
            if (!drawdream_tool_table_exists($conn, $table) || !drawdream_tool_column_exists($conn, $table, $column)) {
                continue;
            }
            $safeT = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $safeC = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $upd = $conn->prepare("UPDATE `{$safeT}` SET `{$safeC}` = ? WHERE `{$safeC}` = ?");
            if ($upd) {
                $upd->bind_param('ii', $newId, $oldId);
                $upd->execute();
            }
        }
    }
}

echo '=== Delete users: ' . implode(', ', $deleteIds) . " ===\n";
$found = drawdream_tool_fetch_users($conn, $deleteIds);
if (count($found) !== count($deleteIds)) {
    $foundIds = array_map(static fn($r) => (int)$r['user_id'], $found);
    $missing = array_diff($deleteIds, $foundIds);
    fwrite(STDERR, 'Missing user_id(s): ' . implode(', ', $missing) . "\n");
    exit(1);
}

foreach ($found as $row) {
    $uid = (int)$row['user_id'];
    echo sprintf(
        "  user_id=%d email=%s role=%s\n",
        $uid,
        (string)$row['email'],
        (string)$row['role']
    );
    foreach (
        [
            ['donation', 'donor_id'],
            ['donor', 'user_id'],
            ['notifications', 'user_id'],
            ['child_subscription_history', 'donor_user_id'],
            ['foundation_profile', 'user_id'],
        ] as [$tbl, $col]
    ) {
        $c = drawdream_tool_count_for_user($conn, $uid, $tbl, $col);
        if ($c > 0) {
            echo "    - {$tbl}.{$col}: {$c} row(s)\n";
        }
    }
}

$allRs = $conn->query('SELECT user_id FROM `user` ORDER BY user_id ASC');
$remainingBefore = [];
while ($allRs && ($r = $allRs->fetch_assoc())) {
    $remainingBefore[] = (int)$r['user_id'];
}

$remainingAfterDelete = array_values(array_filter(
    $remainingBefore,
    static fn(int $id): bool => !in_array($id, $deleteIds, true)
));

$mapping = [];
$newId = 1;
foreach ($remainingAfterDelete as $oldId) {
    if ($oldId !== $newId) {
        $mapping[$oldId] = $newId;
    }
    $newId++;
}
$nextAuto = count($remainingAfterDelete) + 1;

echo "\nAfter delete, renumber mapping:\n";
if ($mapping === []) {
    echo "  (already sequential)\n";
} else {
    foreach ($mapping as $old => $new) {
        echo "  {$old} -> {$new}\n";
    }
}
echo "Next AUTO_INCREMENT: {$nextAuto}\n";

if ($dryRun) {
    echo "\nDry-run only. Re-run with --apply to execute.\n";
    exit(0);
}

$conn->begin_transaction();
try {
    @$conn->query('SET FOREIGN_KEY_CHECKS=0');

    foreach ($deleteIds as $uid) {
        drawdream_tool_purge_user($conn, $uid);
    }

    drawdream_tool_apply_user_id_mapping($conn, $mapping);

    if (!$conn->query("ALTER TABLE `user` AUTO_INCREMENT = {$nextAuto}")) {
        throw new RuntimeException($conn->error);
    }

    @$conn->query('SET FOREIGN_KEY_CHECKS=1');
    $conn->commit();
    echo "\nApplied successfully.\n";
} catch (Throwable $e) {
    @$conn->query('SET FOREIGN_KEY_CHECKS=1');
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nRemaining users:\n";
$verify = $conn->query('SELECT user_id, email, role FROM `user` ORDER BY user_id ASC');
while ($verify && ($v = $verify->fetch_assoc())) {
    echo sprintf(
        "  id=%d email=%s role=%s\n",
        (int)$v['user_id'],
        (string)$v['email'],
        (string)$v['role']
    );
}
