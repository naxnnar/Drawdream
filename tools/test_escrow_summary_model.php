<?php
/**
 * ทดสอบโมเดล escrow แบบ 1 แถวต่อ target (หลังชำระค่าบริการ)
 * Usage: C:\xampp\php\php.exe tools/test_escrow_summary_model.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');
require_once $root . '/includes/escrow_funds_schema.php';

$dbLocalFile = $root . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $c */
    $c = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD') ?: getenv('AIVEN_PASSWORD');
    $c = [
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'localhost'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 3306)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'root'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'defaultdb'),
    ];
}

if (!function_exists('mysqli_init')) {
    fwrite(STDERR, "mysqli extension required\n");
    exit(1);
}
$connInit = mysqli_init();
if (!$connInit) {
    fwrite(STDERR, "Cannot init MySQL\n");
    exit(1);
}
$host = (string)($c['host'] ?? 'localhost');
$port = (int)($c['port'] ?? 3306);
$sslCa = trim((string)(getenv('DB_SSL_CA') !== false ? getenv('DB_SSL_CA') : ''));
$sslMode = strtolower(trim((string)(getenv('DB_SSL_MODE') !== false ? getenv('DB_SSL_MODE') : 'require')));
$useSsl = ($sslMode !== 'disable') || str_contains($host, 'aivencloud.com');
$sslFlags = 0;
if ($useSsl) {
    if ($sslCa !== '' && is_file($sslCa)) {
        mysqli_ssl_set($connInit, null, null, $sslCa, null, null);
    } elseif (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $sslFlags = MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
}
if (!@mysqli_real_connect(
    $connInit,
    $host,
    (string)($c['user'] ?? 'root'),
    (string)($c['password'] ?? ''),
    (string)($c['database'] ?? 'defaultdb'),
    $port,
    null,
    $sslFlags
)) {
    fwrite(STDERR, 'DB connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$conn = $connInit;
mysqli_set_charset($conn, 'utf8mb4');

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $label\n";
        $pass++;
    } else {
        echo "[FAIL] $label\n";
        $fail++;
    }
}

echo "=== Escrow summary model test ===\n";
echo "DB: {$c['database']} @ {$c['host']}\n\n";

// 1) Schema + migration
drawdream_escrow_funds_ensure_schema($conn);
ok(drawdream_escrow_has_index($conn, 'escrow_funds', 'uq_escrow_target'), 'unique index uq_escrow_target exists');
ok(!drawdream_escrow_has_index($conn, 'escrow_funds', 'uq_escrow_target_donate'), 'old index uq_escrow_target_donate removed');

// 2) No per-donation rows
$nPerDon = (int)($conn->query('SELECT COUNT(*) FROM escrow_funds WHERE donate_id > 0')->fetch_row()[0] ?? -1);
ok($nPerDon === 0, "no per-donation rows (donate_id > 0), count=$nPerDon");

// 3) Functions exist
ok(function_exists('drawdream_escrow_sync_summary_holding_for_project'), 'sync project function exists');
ok(function_exists('drawdream_escrow_sync_summary_holding_for_need_foundation'), 'sync need_foundation function exists');
ok(!function_exists('drawdream_escrow_funds_try_insert_holding'), 'old per-donation insert removed');

// 4) Payment finalize files must not call old insert
foreach ([
    'includes/drawdream_project_payment_finalize.php',
    'includes/drawdream_needlist_payment_finalize.php',
    'payment/check_project_payment.php',
] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    ok(
        strpos($src, 'drawdream_escrow_funds_try_insert_holding') === false,
        "$rel has no per-donation escrow insert"
    );
}

// 5) Service charge files must call sync
foreach ([
    'payment/check_project_service_charge_payment.php' => 'drawdream_escrow_sync_summary_holding_for_project',
    'payment/check_needlist_service_charge_payment.php' => 'drawdream_escrow_sync_summary_holding_for_need_item',
] as $rel => $fn) {
    $src = (string)file_get_contents($root . '/' . $rel);
    ok(strpos($src, $fn) !== false, "$rel calls $fn");
}

// 6) DB state vs business rules
echo "\n--- Current DB state ---\n";
$escrowRows = $conn->query('SELECT escrow_id, target_type, target_id, donate_id, amount, status FROM escrow_funds ORDER BY escrow_id');
$escrowCount = 0;
while ($row = $escrowRows->fetch_assoc()) {
    $escrowCount++;
    echo '  escrow: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    ok((int)$row['donate_id'] === 0, "escrow_id={$row['escrow_id']} uses summary donate_id=0");
}
if ($escrowCount === 0) {
    echo "  (no escrow rows — OK if no service charge paid yet)\n";
}

$proj = $conn->query(
    "SELECT project_id, project_status, current_donate,
            CASE WHEN service_charge_paid_at IS NULL THEN 0 ELSE 1 END AS sc_paid
     FROM foundation_project ORDER BY project_id"
);
while ($p = $proj->fetch_assoc()) {
    $pid = (int)$p['project_id'];
    $scPaid = (int)$p['sc_paid'] === 1;
    $st = (string)$p['project_status'];
    $ef = $conn->query(
        "SELECT status, amount FROM escrow_funds WHERE target_type='project' AND target_id=$pid LIMIT 1"
    )->fetch_assoc();
    $hasEscrow = is_array($ef);
    $line = "project $pid status=$st sc_paid=" . ($scPaid ? 'Y' : 'N') . ' escrow=' . ($hasEscrow ? $ef['status'] : 'none');
    echo "  $line\n";
    if ($scPaid && $st === 'completed') {
        ok($hasEscrow && $ef['status'] === 'holding', "project $pid: SC paid + completed => escrow holding");
    } elseif (!$scPaid) {
        ok(!$hasEscrow || $ef['status'] !== 'holding', "project $pid: no SC => no holding escrow");
    }
}

$need = $conn->query(
    "SELECT item_id, approve_item, current_donate, total_price,
            CASE WHEN service_charge_paid_at IS NULL THEN 0 ELSE 1 END AS sc_paid
     FROM foundation_needlist ORDER BY item_id"
);
while ($n = $need->fetch_assoc()) {
    $iid = (int)$n['item_id'];
    $scPaid = (int)$n['sc_paid'] === 1;
    $ef = $conn->query(
        "SELECT status FROM escrow_funds WHERE target_type='need_foundation' AND target_id=(SELECT foundation_id FROM foundation_needlist WHERE item_id=$iid LIMIT 1) LIMIT 1"
    )->fetch_assoc();
    $hasEscrow = is_array($ef);
    echo '  need_item ' . $iid . ' approve=' . $n['approve_item'] . ' sc_paid=' . ($scPaid ? 'Y' : 'N')
        . ' foundation_escrow=' . ($hasEscrow ? $ef['status'] : 'none') . "\n";
    if (!$scPaid) {
        ok(!$hasEscrow || $ef['status'] !== 'holding', "need_item $iid: no SC => no foundation holding escrow");
    }
}

// 7) Sync dry-run on project 1 (should no-op without SC)
$before = (int)($conn->query("SELECT COUNT(*) FROM escrow_funds WHERE target_type='project' AND target_id=1")->fetch_row()[0] ?? 0);
drawdream_escrow_sync_summary_holding_for_project($conn, 1);
$after = (int)($conn->query("SELECT COUNT(*) FROM escrow_funds WHERE target_type='project' AND target_id=1")->fetch_row()[0] ?? 0);
ok($before === $after, 'sync project without SC paid does not create escrow');

echo "\n=== Result: PASS=$pass FAIL=$fail ===\n";
exit($fail > 0 ? 1 : 0);
