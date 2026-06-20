<?php
/**
 * ตรวจความสมบูรณ์ข้อมูล DrawDream (read-only)
 * Usage: php tools/db_integrity_check.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');

$dbLocalFile = $root . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD');
    if ($envPass === false || $envPass === '') {
        $envPass = getenv('AIVEN_PASSWORD');
    }
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'localhost'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 3306)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'root'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'drawdream_db'),
    ];
}

$host = (string)($dbConfig['host'] ?? 'localhost');
$port = (int)($dbConfig['port'] ?? 3306);
$user = (string)($dbConfig['user'] ?? 'root');
$password = (string)($dbConfig['password'] ?? '');
$database = (string)($dbConfig['database'] ?? 'drawdream_db');

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
$conn = @mysqli_connect($host, $user, $password, $database, $port);
if (!$conn) {
    fwrite(STDERR, 'DB connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

/** @var list<array{level:string,check:string,detail:string,count:int}> */
$issues = [];
/** @var list<array{check:string,detail:string}> */
$ok = [];

function table_exists(mysqli $conn, string $table): bool
{
    $t = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}

function col_exists(mysqli $conn, string $table, string $col): bool
{
    $r = @$conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($col) . "'"
    );
    return $r && $r->num_rows > 0;
}

function add_issue(string $level, string $check, string $detail, int $count = 0): void
{
    global $issues;
    $issues[] = ['level' => $level, 'check' => $check, 'detail' => $detail, 'count' => $count];
}

function add_ok(string $check, string $detail = ''): void
{
    global $ok;
    $ok[] = ['check' => $check, 'detail' => $detail];
}

function run_count(mysqli $conn, string $sql): int
{
    $r = @$conn->query($sql);
    if (!$r) {
        return -1;
    }
    $row = $r->fetch_row();
    return (int)($row[0] ?? 0);
}

function run_sample(mysqli $conn, string $sql, int $limit = 5): string
{
    $r = @$conn->query($sql . ' LIMIT ' . $limit);
    if (!$r || $r->num_rows === 0) {
        return '(none)';
    }
    $lines = [];
    while ($row = $r->fetch_assoc()) {
        $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
    }
    return implode("\n    ", $lines);
}

echo "DrawDream DB integrity check\n";
echo "Host: {$host}:{$port}  DB: {$database}\n";
echo str_repeat('=', 60) . "\n";

// --- Table row counts ---
$tables = ['user', 'donor', 'foundation_profile', 'foundation_children', 'foundation_project', 'foundation_needlist', 'donation', 'notifications', 'admin'];
echo "\n[Row counts]\n";
foreach ($tables as $t) {
    if (!table_exists($conn, $t)) {
        echo "  {$t}: (table missing)\n";
        continue;
    }
    $n = run_count($conn, "SELECT COUNT(*) FROM `{$t}`");
    echo "  {$t}: {$n}\n";
}

// --- User / profile orphans ---
if (table_exists($conn, 'donor') && table_exists($conn, 'user')) {
    $n = run_count($conn, 'SELECT COUNT(*) FROM donor d LEFT JOIN `user` u ON u.user_id = d.user_id WHERE u.user_id IS NULL');
    if ($n > 0) {
        add_issue('ERROR', 'donor.user_id ชี้ user ที่ไม่มี', run_sample($conn, 'SELECT d.user_id FROM donor d LEFT JOIN `user` u ON u.user_id = d.user_id WHERE u.user_id IS NULL'), $n);
    } else {
        add_ok('donor.user_id ครบทุกแถว');
    }
}

if (table_exists($conn, 'foundation_profile') && table_exists($conn, 'user')) {
    $n = run_count($conn, 'SELECT COUNT(*) FROM foundation_profile fp LEFT JOIN `user` u ON u.user_id = fp.user_id WHERE u.user_id IS NULL');
    if ($n > 0) {
        add_issue('ERROR', 'foundation_profile.user_id ชี้ user ที่ไม่มี', run_sample($conn, 'SELECT fp.foundation_id, fp.user_id FROM foundation_profile fp LEFT JOIN `user` u ON u.user_id = fp.user_id WHERE u.user_id IS NULL'), $n);
    } else {
        add_ok('foundation_profile.user_id ครบทุกแถว');
    }

    $n = run_count($conn, "SELECT COUNT(*) FROM foundation_profile WHERE account_verified NOT IN (0,1,2) OR account_verified IS NULL");
    if ($n > 0) {
        add_issue('WARN', 'account_verified ค่าแปลก', run_sample($conn, 'SELECT foundation_id, account_verified FROM foundation_profile WHERE account_verified NOT IN (0,1,2) OR account_verified IS NULL'), $n);
    } else {
        add_ok('account_verified เป็น 0/1/2 เท่านั้น');
    }
}

if (table_exists($conn, 'user')) {
    $n = run_count($conn, "SELECT COUNT(*) FROM (SELECT email, COUNT(*) c FROM `user` GROUP BY email HAVING c > 1) t");
    if ($n > 0) {
        add_issue('ERROR', 'อีเมลซ้ำใน user', run_sample($conn, "SELECT email, COUNT(*) c FROM `user` GROUP BY email HAVING c > 1"), $n);
    } else {
        add_ok('ไม่มีอีเมลซ้ำใน user');
    }
}

// --- Children / project / needlist foundation_id ---
foreach ([
    ['children', 'child_id', 'foundation_id'],
    ['foundation_project', 'project_id', 'foundation_id'],
    ['foundation_needlist', 'item_id', 'foundation_id'],
] as [$tbl, $pk, $fk]) {
    if (!table_exists($conn, $tbl) || !table_exists($conn, 'foundation_profile')) {
        continue;
    }
    $n = run_count($conn, "SELECT COUNT(*) FROM `{$tbl}` t LEFT JOIN foundation_profile fp ON fp.foundation_id = t.{$fk} WHERE fp.foundation_id IS NULL");
    if ($n > 0) {
        add_issue('ERROR', "{$tbl}.{$fk} ชี้มูลนิธิที่ไม่มี", run_sample($conn, "SELECT t.{$pk}, t.{$fk} FROM `{$tbl}` t LEFT JOIN foundation_profile fp ON fp.foundation_id = t.{$fk} WHERE fp.foundation_id IS NULL"), $n);
    } else {
        add_ok("{$tbl}.{$fk} อ้างอิงมูลนิธิได้ครบ");
    }
}

// --- Donation integrity ---
if (table_exists($conn, 'donation')) {
    if (col_exists($conn, 'donation', 'donor_id')) {
        $n = run_count($conn, 'SELECT COUNT(*) FROM donation d LEFT JOIN `user` u ON u.user_id = d.donor_id WHERE d.donor_id IS NOT NULL AND d.donor_id > 0 AND u.user_id IS NULL');
        if ($n > 0) {
            add_issue('ERROR', 'donation.donor_id ชี้ user ที่ไม่มี', run_sample($conn, 'SELECT donate_id, donor_id FROM donation d LEFT JOIN `user` u ON u.user_id = d.donor_id WHERE d.donor_id IS NOT NULL AND d.donor_id > 0 AND u.user_id IS NULL'), $n);
        } else {
            add_ok('donation.donor_id ชี้ user ได้ครบ');
        }
    }

    if (col_exists($conn, 'donation', 'payment_status')) {
        $n = run_count($conn, "SELECT COUNT(*) FROM donation WHERE payment_status = 'completed' AND (amount IS NULL OR amount <= 0)");
        if ($n > 0) {
            add_issue('WARN', 'บริจาค completed แต่ amount <= 0', run_sample($conn, "SELECT donate_id, amount, payment_status FROM donation WHERE payment_status = 'completed' AND (amount IS NULL OR amount <= 0)"), $n);
        } else {
            add_ok('donation completed มี amount > 0');
        }

        $n = run_count($conn, "SELECT COUNT(*) FROM donation WHERE payment_status NOT IN ('pending','completed','failed','cancelled','expired') AND payment_status IS NOT NULL AND TRIM(payment_status) <> ''");
        if ($n > 0) {
            add_issue('WARN', 'payment_status ค่าแปลก', run_sample($conn, "SELECT donate_id, payment_status FROM donation WHERE payment_status NOT IN ('pending','completed','failed','cancelled','expired') AND payment_status IS NOT NULL AND TRIM(payment_status) <> ''"), $n);
        }
    }

    if (col_exists($conn, 'donation', 'omise_charge_id')) {
        $n = run_count($conn, "SELECT COUNT(*) FROM (SELECT omise_charge_id, COUNT(*) c FROM donation WHERE omise_charge_id IS NOT NULL AND TRIM(omise_charge_id) <> '' GROUP BY omise_charge_id HAVING c > 1) t");
        if ($n > 0) {
            add_issue('ERROR', 'omise_charge_id ซ้ำ', run_sample($conn, "SELECT omise_charge_id, COUNT(*) c FROM donation WHERE omise_charge_id IS NOT NULL AND TRIM(omise_charge_id) <> '' GROUP BY omise_charge_id HAVING c > 1"), $n);
        } else {
            add_ok('ไม่มี omise_charge_id ซ้ำ');
        }
    }
}

// --- Needlist current_donate vs donation sum (target_id = item_id) ---
if (table_exists($conn, 'foundation_needlist') && table_exists($conn, 'donation')
    && col_exists($conn, 'foundation_needlist', 'current_donate')
    && col_exists($conn, 'donation', 'target_id')
    && col_exists($conn, 'donation', 'donate_type')
    && col_exists($conn, 'donation', 'payment_status')) {
    $sql = "
        SELECT nl.item_id, nl.foundation_id, nl.current_donate AS stored,
               COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(d.payment_status,''))) = 'completed'
                   AND LOWER(TRIM(COALESCE(d.donate_type,''))) = 'need_item'
                   THEN d.amount ELSE 0 END), 0) AS summed
        FROM foundation_needlist nl
        LEFT JOIN donation d ON d.target_id = nl.item_id
        GROUP BY nl.item_id, nl.foundation_id, nl.current_donate
        HAVING ABS(stored - summed) > 0.01
    ";
    $n = run_count($conn, "SELECT COUNT(*) FROM ({$sql}) t");
    if ($n > 0) {
        add_issue('WARN', 'needlist current_donate ไม่ตรงยอด donation (need_item)', run_sample($conn, $sql), $n);
    } else {
        add_ok('needlist current_donate ตรงยอด donation (need_item)');
    }
}

// --- Project donation totals (target_id = project_id) ---
if (table_exists($conn, 'foundation_project') && table_exists($conn, 'donation')
    && col_exists($conn, 'foundation_project', 'current_donate')
    && col_exists($conn, 'donation', 'target_id')
    && col_exists($conn, 'donation', 'donate_type')) {
    $sql = "
        SELECT p.project_id, p.current_donate AS stored,
               COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(d.payment_status,''))) = 'completed'
                   AND LOWER(TRIM(COALESCE(d.donate_type,''))) = 'project'
                   THEN d.amount ELSE 0 END), 0) AS summed
        FROM foundation_project p
        LEFT JOIN donation d ON d.target_id = p.project_id
        GROUP BY p.project_id, p.current_donate
        HAVING ABS(stored - summed) > 0.01
    ";
    $n = run_count($conn, "SELECT COUNT(*) FROM ({$sql}) t");
    if ($n > 0) {
        add_issue('WARN', 'project current_donate ไม่ตรงยอด donation รวม', run_sample($conn, $sql), $n);
    } else {
        add_ok('project current_donate ตรงยอด donation');
    }
}

// --- Broken notification links (donation_receipt) ---
if (table_exists($conn, 'notifications') && table_exists($conn, 'donation')) {
    $sql = "
        SELECT n.notif_id, n.link
        FROM notifications n
        WHERE n.link LIKE '%donation_receipt.php?donate_id=%'
          AND NOT EXISTS (
            SELECT 1 FROM donation d
            WHERE d.donate_id = CAST(SUBSTRING_INDEX(n.link, 'donate_id=', -1) AS UNSIGNED)
          )
    ";
    $n = run_count($conn, "SELECT COUNT(*) FROM ({$sql}) t");
    if ($n > 0) {
        add_issue('WARN', 'แจ้งเตือนลิงก์ใบเสร็จชี้ donate_id ที่ไม่มี', run_sample($conn, $sql), $n);
    } else {
        add_ok('ลิงก์ใบเสร็จใน notifications ชี้ donate_id ได้');
    }
}

// --- Donation ID gaps (informational) ---
if (table_exists($conn, 'donation') && col_exists($conn, 'donation', 'donate_id')) {
    $row = @$conn->query('SELECT MIN(donate_id) mn, MAX(donate_id) mx, COUNT(*) cnt FROM donation')?->fetch_assoc();
    if ($row) {
        $mn = (int)($row['mn'] ?? 0);
        $mx = (int)($row['mx'] ?? 0);
        $cnt = (int)($row['cnt'] ?? 0);
        $expected = $mx - $mn + 1;
        if ($cnt > 0 && $expected > $cnt) {
            add_issue('INFO', 'donate_id มีช่องว่าง (อาจลบ/แก้ manual)', "min={$mn} max={$mx} rows={$cnt} expected_if_continuous={$expected} gaps=" . ($expected - $cnt), $expected - $cnt);
        } else {
            add_ok('donate_id ต่อเนื่องหรือไม่มีช่องว่างที่น่าสงสัย', "min={$mn} max={$mx} count={$cnt}");
        }
    }
}

// --- Orphan columns user may have dropped ---
foreach (['foundation_profile' => ['phone_secondary', 'needlist_result_text', 'reviewed_at', 'verified_by']] as $tbl => $cols) {
    if (!table_exists($conn, $tbl)) {
        continue;
    }
    foreach ($cols as $col) {
        if (col_exists($conn, $tbl, $col)) {
            add_issue('INFO', "คอลัมน์ {$tbl}.{$col} ยังอยู่ใน DB", 'ลบใน DBeaver ได้ถ้า deploy โค้ดใหม่แล้ว');
        }
    }
}

// --- Report ---
echo "\n[OK checks: " . count($ok) . "]\n";
foreach ($ok as $item) {
    $extra = $item['detail'] !== '' ? " — {$item['detail']}" : '';
    echo "  ✓ {$item['check']}{$extra}\n";
}

$errors = array_filter($issues, static fn($i) => $i['level'] === 'ERROR');
$warns = array_filter($issues, static fn($i) => $i['level'] === 'WARN');
$infos = array_filter($issues, static fn($i) => $i['level'] === 'INFO');

echo "\n[Issues: ERROR=" . count($errors) . " WARN=" . count($warns) . " INFO=" . count($infos) . "]\n";
foreach ($issues as $item) {
    $tag = $item['level'];
    $cnt = $item['count'] > 0 ? " ({$item['count']} rows)" : '';
    echo "\n[{$tag}] {$item['check']}{$cnt}\n";
    if ($item['detail'] !== '') {
        echo "    {$item['detail']}\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
if (count($errors) > 0) {
    echo "RESULT: พบปัญหาร้ายแรง — ควรแก้\n";
    exit(2);
}
if (count($warns) > 0) {
    echo "RESULT: มีจุดควรตรวจเพิ่ม แต่ระบบหลักน่าจะใช้ได้\n";
    exit(1);
}
echo "RESULT: ไม่พบความเพี้ยนร้ายแรง\n";
exit(0);
