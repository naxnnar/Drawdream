<?php
declare(strict_types=1);

/**
 * แดชบอร์ดผู้ใช้ — ออนไลน์จาก `user.last_seen_at` (คอลัมน์เดิม/เสริมสำหรับ presence)
 * กลับมาใช้ซ้ำ = ธุรกรรมจริงจากตารางเดิม ≥ 2 วัน (ไม่สร้างตาราง/คอลัมน์สำหรับ metric นี้)
 */
function drawdream_user_activity_online_window_minutes(): int
{
    return 5;
}

function drawdream_table_exists(mysqli $conn, string $table): bool
{
    $t = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}

function drawdream_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $r = @$conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
    );
    return $r && $r->num_rows > 0;
}

/** คอลัมน์ presence บน `user` (เฉพาะ “ใช้งานอยู่ตอนนี้”) */
function drawdream_ensure_user_activity_columns(mysqli $conn): void
{
    if (!function_exists('drawdream_schema_migrations_allowed')) {
        require_once __DIR__ . '/drawdream_schema_once.php';
    }
    if (!drawdream_schema_migrations_allowed()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }

    $needCols = [
        'last_seen_at' => 'ALTER TABLE `user` ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL',
    ];
    foreach ($needCols as $col => $ddl) {
        $chk = @$conn->query(
            "SHOW COLUMNS FROM `user` LIKE '" . $conn->real_escape_string($col) . "'"
        );
        if ($chk && $chk->num_rows === 0) {
            @$conn->query($ddl);
        }
    }

    $idx = @$conn->query("SHOW INDEX FROM `user` WHERE Key_name = 'idx_user_last_seen'");
    if ($idx && $idx->num_rows === 0) {
        @$conn->query('ALTER TABLE `user` ADD KEY idx_user_last_seen (last_seen_at)');
    }

    drawdream_migrate_legacy_user_presence($conn);

    $done = true;
}

function drawdream_migrate_legacy_user_presence(mysqli $conn): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;

    $marker = dirname(__DIR__) . '/config/user_presence_legacy_migrated.txt';
    if (is_file($marker)) {
        return;
    }

    $tPres = @$conn->query("SHOW TABLES LIKE 'user_presence'");
    if ($tPres && $tPres->num_rows > 0) {
        @$conn->query(
            'UPDATE `user` u
             INNER JOIN user_presence p ON p.user_id = u.user_id
             SET u.last_seen_at = p.last_seen_at
             WHERE u.last_seen_at IS NULL OR p.last_seen_at > u.last_seen_at'
        );
    }

    @file_put_contents($marker, date('c'));
}

/** อัปเดต last_seen_at โดยไม่รัน migration — ใช้หลังสมัคร/ล็อกอินบนหน้า DB_LIGHT */
function drawdream_touch_user_presence_fast(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    static $hasCol = null;
    if ($hasCol === null) {
        $hasCol = drawdream_table_has_column($conn, 'user', 'last_seen_at');
    }
    if (!$hasCol) {
        return;
    }
    $st = @$conn->prepare('UPDATE `user` SET last_seen_at = NOW() WHERE user_id = ?');
    if (!$st) {
        return;
    }
    $st->bind_param('i', $userId);
    @$st->execute();
}

/** @deprecated */
function drawdream_ensure_user_activity_tables(mysqli $conn): void
{
    drawdream_ensure_user_activity_columns($conn);
}

function drawdream_touch_user_presence_only(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    drawdream_ensure_user_activity_columns($conn);

    $st = $conn->prepare('UPDATE `user` SET last_seen_at = NOW() WHERE user_id = ?');
    if (!$st) {
        return;
    }
    $st->bind_param('i', $userId);
    $st->execute();
}

function drawdream_record_user_presence(mysqli $conn, ?string $_path = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $today = date('Y-m-d');
    if ((string)($_SESSION['drawdream_presence_day'] ?? '') !== $today) {
        $_SESSION['drawdream_presence_day'] = $today;
        $_SESSION['drawdream_presence_ping_at'] = 0;
    }

    $now = time();
    $lastPing = (int)($_SESSION['drawdream_presence_ping_at'] ?? 0);
    if ($lastPing > 0 && ($now - $lastPing) < 45) {
        return;
    }
    $_SESSION['drawdream_presence_ping_at'] = $now;

    drawdream_touch_user_presence_only($conn, $uid);
}

function drawdream_log_user_login(mysqli $conn, int $userId, string $_via = 'password'): void
{
    if ($userId <= 0) {
        return;
    }
    if (defined('DRAWDREAM_DB_LIGHT') && DRAWDREAM_DB_LIGHT) {
        drawdream_touch_user_presence_fast($conn, $userId);
        return;
    }
    drawdream_touch_user_presence_only($conn, $userId);
}

/**
 * ผู้ใช้ที่มี “ธุรกรรมจริง” อย่างน้อย 2 วันปฏิทิน (รวมจากตารางเดิม)
 *
 * - ผู้บริจาค: donation สำเร็จ (payment_status = completed)
 * - มูลนิธิ: ส่งโครงการ / เสนอสิ่งของ / เพิ่มโปรไฟล์เด็ก (foundation_children.created_at)
 * - แอดมิน: อนุมัติสิ่งของ (admin + target_entity need + สถานะอนุมัติ)
 */
function drawdream_count_returning_transaction_users(mysqli $conn): int
{
    $parts = [];

    if (drawdream_table_exists($conn, 'donation')
        && drawdream_table_has_column($conn, 'donation', 'donor_id')
        && drawdream_table_has_column($conn, 'donation', 'payment_status')
    ) {
        $dayExpr = drawdream_table_has_column($conn, 'donation', 'transfer_datetime')
            ? 'DATE(d.transfer_datetime)'
            : (drawdream_table_has_column($conn, 'donation', 'created_at')
                ? 'DATE(d.created_at)'
                : null);
        if ($dayExpr !== null) {
            $parts[] = "SELECT d.donor_id AS user_id, {$dayExpr} AS activity_day
                FROM donation d
                WHERE d.donor_id IS NOT NULL AND d.donor_id > 0
                  AND LOWER(TRIM(COALESCE(d.payment_status, ''))) = 'completed'
                  AND {$dayExpr} IS NOT NULL";
        }
    }

    if (drawdream_table_exists($conn, 'foundation_needlist')
        && drawdream_table_exists($conn, 'foundation_profile')
        && drawdream_table_has_column($conn, 'foundation_needlist', 'created_at')
        && drawdream_table_has_column($conn, 'foundation_profile', 'user_id')
    ) {
        $parts[] = "SELECT fp.user_id AS user_id, DATE(nl.created_at) AS activity_day
            FROM foundation_needlist nl
            INNER JOIN foundation_profile fp ON fp.foundation_id = nl.foundation_id
            WHERE fp.user_id IS NOT NULL AND fp.user_id > 0
              AND nl.created_at IS NOT NULL";
    }

    if (drawdream_table_exists($conn, 'foundation_project')
        && drawdream_table_exists($conn, 'foundation_profile')
        && drawdream_table_has_column($conn, 'foundation_profile', 'user_id')
    ) {
        $projDay = null;
        if (drawdream_table_has_column($conn, 'foundation_project', 'created_at')) {
            $projDay = 'DATE(p.created_at)';
        } elseif (drawdream_table_has_column($conn, 'foundation_project', 'start_date')) {
            $projDay = 'DATE(p.start_date)';
        }
        if ($projDay !== null) {
            $parts[] = "SELECT fp.user_id AS user_id, {$projDay} AS activity_day
                FROM foundation_project p
                INNER JOIN foundation_profile fp ON fp.foundation_id = p.foundation_id
                WHERE fp.user_id IS NOT NULL AND fp.user_id > 0
                  AND {$projDay} IS NOT NULL";
        }
    }

    if (drawdream_table_exists($conn, 'foundation_children')
        && drawdream_table_exists($conn, 'foundation_profile')
        && drawdream_table_has_column($conn, 'foundation_profile', 'user_id')
        && drawdream_table_has_column($conn, 'foundation_profile', 'foundation_id')
    ) {
        $childDay = null;
        if (drawdream_table_has_column($conn, 'foundation_children', 'created_at')) {
            $childDay = 'DATE(c.created_at)';
        } elseif (drawdream_table_has_column($conn, 'foundation_children', 'approve_at')) {
            $childDay = 'DATE(c.approve_at)';
        }
        if ($childDay !== null) {
            $parts[] = "SELECT fp.user_id AS user_id, {$childDay} AS activity_day
                FROM foundation_children c
                INNER JOIN foundation_profile fp ON fp.foundation_id = c.foundation_id
                WHERE fp.user_id IS NOT NULL AND fp.user_id > 0
                  AND {$childDay} IS NOT NULL";
        }
    }

    if (drawdream_table_exists($conn, 'admin')
        && drawdream_table_has_column($conn, 'admin', 'admin_id')
    ) {
        $dayCol = drawdream_table_has_column($conn, 'admin', 'action_at') ? 'action_at' : null;
        if ($dayCol !== null) {
            $entityFilter = drawdream_table_has_column($conn, 'admin', 'target_entity')
                ? " AND LOWER(TRIM(COALESCE(a.target_entity, ''))) = 'need'"
                : '';
            $approveFilter = drawdream_table_has_column($conn, 'admin', 'notif_type')
                ? " AND (
                    TRIM(COALESCE(a.notif_type, '')) = 'อนุมัติ'
                    OR LOWER(TRIM(COALESCE(a.notif_type, ''))) LIKE '%approv%'
                    OR LOWER(TRIM(COALESCE(a.notif_type, ''))) LIKE '%need_approved%'
                  )"
                : '';
            $parts[] = "SELECT a.admin_id AS user_id, DATE(a.{$dayCol}) AS activity_day
                FROM `admin` a
                WHERE a.admin_id IS NOT NULL AND a.admin_id > 0
                  AND a.{$dayCol} IS NOT NULL{$entityFilter}{$approveFilter}";
        }
    }

    if ($parts === []) {
        return 0;
    }

    $union = implode(' UNION ALL ', $parts);
    $sql = "SELECT COUNT(*) AS cnt FROM (
        SELECT user_id
        FROM ({$union}) acts
        WHERE user_id > 0 AND activity_day IS NOT NULL
        GROUP BY user_id
        HAVING COUNT(DISTINCT activity_day) >= 2
    ) t";

    $r = @$conn->query($sql);
    if (!$r) {
        return 0;
    }
    return (int)($r->fetch_assoc()['cnt'] ?? 0);
}

/**
 * @return array{
 *   returning_count:int,
 *   active_today:int,
 *   active_today_users:list<array{user_id:int,email:string,role:string,last_seen_at:string}>,
 *   refreshed_at:string
 * }
 */
function drawdream_admin_user_activity_stats(mysqli $conn): array
{
    drawdream_ensure_user_activity_columns($conn);

    $activeTodayUsers = [];
    $stToday = $conn->query(
        'SELECT user_id, email, role, last_seen_at
         FROM `user`
         WHERE last_seen_at IS NOT NULL AND DATE(last_seen_at) = CURDATE()
         ORDER BY last_seen_at DESC
         LIMIT 50'
    );
    if ($stToday) {
        while ($row = $stToday->fetch_assoc()) {
            $activeTodayUsers[] = [
                'user_id' => (int)($row['user_id'] ?? 0),
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
            ];
        }
    }

    $activeToday = count($activeTodayUsers);
    $rToday = $conn->query(
        'SELECT COUNT(*) AS cnt FROM `user` WHERE last_seen_at IS NOT NULL AND DATE(last_seen_at) = CURDATE()'
    );
    if ($rToday) {
        $activeToday = (int)($rToday->fetch_assoc()['cnt'] ?? 0);
    }

    return [
        'returning_count' => drawdream_count_returning_transaction_users($conn),
        'active_today' => $activeToday,
        'active_today_users' => $activeTodayUsers,
        'refreshed_at' => date('Y-m-d H:i:s'),
    ];
}

function drawdream_role_label_th(string $role): string
{
    return match ($role) {
        'admin' => 'แอดมิน',
        'foundation' => 'มูลนิธิ',
        'donor' => 'ผู้บริจาค',
        default => $role !== '' ? $role : '—',
    };
}
