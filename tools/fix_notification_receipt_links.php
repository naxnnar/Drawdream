<?php
declare(strict_types=1);
/**
 * ไล่แก้ notifications ที่ลิงก์ donation_receipt ชี้ donate_id ไม่มีใน DB
 * Usage: C:\xampp\php\php.exe tools/fix_notification_receipt_links.php [--apply]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
drawdream_load_env_file($root . '/.env');

$dbLocalFile = $root . '/config/db.local.php';
if (is_file($dbLocalFile)) {
    /** @var array{host:string,port?:int,user:string,password:string,database:string} $dbConfig */
    $dbConfig = require $dbLocalFile;
} else {
    $envPass = getenv('DB_PASSWORD') ?: getenv('AIVEN_PASSWORD');
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: (getenv('AIVEN_HOST') ?: 'localhost'),
        'port' => (int)(getenv('DB_PORT') ?: (getenv('AIVEN_PORT') ?: 3306)),
        'user' => getenv('DB_USER') ?: (getenv('AIVEN_USER') ?: 'root'),
        'password' => $envPass !== false && $envPass !== '' ? (string)$envPass : '',
        'database' => getenv('DB_NAME') ?: (getenv('AIVEN_DB') ?: 'defaultdb'),
    ];
}

$host = (string)($dbConfig['host'] ?? 'localhost');
$port = (int)($dbConfig['port'] ?? 3306);
$user = (string)($dbConfig['user'] ?? 'root');
$password = (string)($dbConfig['password'] ?? '');
$database = (string)($dbConfig['database'] ?? 'drawdream_db');

if (!function_exists('mysqli_init')) {
    fwrite(STDERR, "mysqli extension is required\n");
    exit(1);
}

$connInit = mysqli_init();
if (!$connInit) {
    fwrite(STDERR, "Cannot initialize MySQL client\n");
    exit(1);
}

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

$connOk = @mysqli_real_connect($connInit, $host, $user, $password, $database, $port, null, $sslFlags);
if (!$connOk) {
    fwrite(STDERR, 'DB connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$conn = $connInit;
mysqli_set_charset($conn, 'utf8mb4');

function parse_donate_id_from_link(string $link): int
{
    if (preg_match('/[?&]donate_id=(\d+)/', $link, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function parse_amount_from_message(string $message): ?float
{
    if (preg_match('/([\d,]+\.\d{2})\s*บาท/u', $message, $m)) {
        return (float)str_replace(',', '', $m[1]);
    }
    if (preg_match('/([\d,]+)\s*บาท/u', $message, $m)) {
        return (float)str_replace(',', '', $m[1]);
    }
    return null;
}

echo "=== Scan notifications with donation_receipt links ===\n\n";

$sql = "
    SELECT n.notif_id, n.user_id, n.title, n.message, n.link, n.created_at,
           CASE WHEN d.donate_id IS NULL THEN 0 ELSE 1 END AS donate_exists
    FROM notifications n
    LEFT JOIN donation d ON d.donate_id = CAST(
        NULLIF(REGEXP_SUBSTR(n.link, 'donate_id=([0-9]+)'), '') AS UNSIGNED
    )
    WHERE n.link LIKE '%donation_receipt.php%'
    ORDER BY n.notif_id
";

// REGEXP_SUBSTR may not exist on older MySQL — fallback query
$r = @$conn->query($sql);
if (!$r) {
    $r = $conn->query("
        SELECT notif_id, user_id, title, message, link, created_at
        FROM notifications
        WHERE link LIKE '%donation_receipt.php%'
        ORDER BY notif_id
    ");
}

/** @var list<array<string,mixed>> $broken */
$broken = [];
/** @var list<array<string,mixed>> $ok */
$ok = [];

while ($row = $r->fetch_assoc()) {
    $linkId = parse_donate_id_from_link((string)($row['link'] ?? ''));
    $exists = false;
    if ($linkId > 0) {
        $chk = $conn->prepare('SELECT donate_id FROM donation WHERE donate_id = ? LIMIT 1');
        $chk->bind_param('i', $linkId);
        $chk->execute();
        $exists = (bool)$chk->get_result()->fetch_assoc();
    }
    $row['link_donate_id'] = $linkId;
    $row['donate_exists'] = $exists ? 1 : 0;
    if (!$exists) {
        $broken[] = $row;
    } else {
        $ok[] = $row;
    }
}

echo 'Receipt notifications OK: ' . count($ok) . "\n";
echo 'Receipt notifications BROKEN: ' . count($broken) . "\n\n";

if ($broken === []) {
    echo "Nothing to fix.\n";
    exit(0);
}

/** @var list<string> $updateSql */
$updateSql = [];
$fixed = 0;
$unresolved = 0;

foreach ($broken as $n) {
    $notifId = (int)$n['notif_id'];
    $userId = (int)$n['user_id'];
    $linkId = (int)$n['link_donate_id'];
    $msg = (string)($n['message'] ?? '');
    $createdAt = (string)($n['created_at'] ?? '');
    $amount = parse_amount_from_message($msg);

    echo "--- notif_id={$notifId} user_id={$userId} link_donate_id={$linkId} ---\n";
    echo "  message: {$msg}\n";
    echo "  created_at: {$createdAt}\n";

    // หา donation ที่น่าจะเป็นคู่: ผู้บริจาคคนเดียวกัน + completed + ยอดตรง (ถ้ามี) + ใกล้เวลา
    $candidates = [];
    $st = $conn->prepare(
        "SELECT donate_id, donor_id, amount, payment_status, donate_type, target_id, transfer_datetime, created_at
         FROM donation
         WHERE donor_id = ? AND LOWER(TRIM(COALESCE(payment_status,''))) = 'completed'
         ORDER BY donate_id"
    );
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    while ($d = $res->fetch_assoc()) {
        $candidates[] = $d;
    }

    if ($candidates === []) {
        echo "  => NO completed donation for this user — cannot auto-fix\n\n";
        $unresolved++;
        continue;
    }

    $bestId = 0;
    $bestScore = -1;
    foreach ($candidates as $d) {
        $score = 0;
        $dAmount = (float)($d['amount'] ?? 0);
        if ($amount !== null && abs($dAmount - $amount) < 0.01) {
            $score += 100;
        }
        // ใกล้เวลาแจ้งเตือน
        $dTime = (string)($d['transfer_datetime'] ?? $d['created_at'] ?? '');
        if ($dTime !== '' && $createdAt !== '') {
            $diff = abs(strtotime($createdAt) - strtotime($dTime));
            if ($diff <= 3600) {
                $score += 50;
            } elseif ($diff <= 86400) {
                $score += 20;
            }
        }
        // link ผิดแต่ใกล้เคียง (เช่น 6 vs 4)
        if ($linkId > 0 && abs((int)$d['donate_id'] - $linkId) <= 2) {
            $score += 5;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (int)$d['donate_id'];
        }
    }

    if ($bestScore < 50) {
        // fallback: ถ้ามียอดเดียวที่ตรง
        $byAmount = array_filter($candidates, static function ($d) use ($amount) {
            if ($amount === null) {
                return false;
            }
            return abs((float)$d['amount'] - $amount) < 0.01;
        });
        if (count($byAmount) === 1) {
            $bestId = (int)array_values($byAmount)[0]['donate_id'];
            $bestScore = 80;
        }
    }

    if ($bestId <= 0 || $bestScore < 50) {
        echo "  Candidates:\n";
        foreach ($candidates as $d) {
            echo '    ' . json_encode($d, JSON_UNESCAPED_UNICODE) . "\n";
        }
        echo "  => AMBIGUOUS — review manually\n\n";
        $unresolved++;
        continue;
    }

    $newLink = 'donation_receipt.php?donate_id=' . $bestId;
    echo "  => FIX: {$linkId} -> donate_id={$bestId} (score={$bestScore})\n";
    echo "  SQL: UPDATE notifications SET link = '{$newLink}' WHERE notif_id = {$notifId};\n\n";

    $updateSql[] = "UPDATE notifications SET link = '{$newLink}' WHERE notif_id = {$notifId};";

    if ($apply) {
        $up = $conn->prepare('UPDATE notifications SET link = ? WHERE notif_id = ?');
        $up->bind_param('si', $newLink, $notifId);
        if ($up->execute()) {
            $fixed++;
        }
    }
}

echo str_repeat('=', 60) . "\n";
if (!$apply) {
    echo "DRY RUN — ไม่ได้แก้ DB\n";
    echo "รันอีกครั้งด้วย --apply เพื่อ apply:\n";
    echo "  C:\\xampp\\php\\php.exe tools/fix_notification_receipt_links.php --apply\n\n";
    if ($updateSql !== []) {
        echo "-- SQL to run in DBeaver:\n";
        foreach ($updateSql as $s) {
            echo $s . "\n";
        }
    }
} else {
    echo "Applied {$fixed} fix(es). Unresolved: {$unresolved}\n";
}

exit($unresolved > 0 ? 1 : 0);
